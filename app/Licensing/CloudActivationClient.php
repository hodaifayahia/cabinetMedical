<?php

namespace App\Licensing;

use App\Services\MachineFingerprintService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use SensitiveParameter;

/**
 * Desktop half of the one-time online activation.
 *
 * Sends the activation code (or the owner's online account) to the online
 * service's POST /api/v1/desktop/activate, and accepts the answer only once
 * the entitlement it carries verifies against the public key this
 * installation ships. Every failure becomes a French message under the form
 * field the doctor used, so the activation screen can say exactly what went
 * wrong: no Internet, wrong code, code already used on another poste…
 */
final class CloudActivationClient
{
    private const CONNECT_TIMEOUT_SECONDS = 5;

    private const REQUEST_TIMEOUT_SECONDS = 20;

    public const OFFLINE_MESSAGE = 'Le service en ligne Drclick est injoignable. Une connexion Internet est nécessaire une seule fois '
        .'pour activer ce poste : vérifiez la connexion puis réessayez. Une fois activé, il fonctionne sans Internet. '
        .'Sans connexion possible, demandez à Drclick un fichier de licence.';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly CabinetEntitlementVerifier $verifier,
        private readonly MachineFingerprintService $fingerprint,
    ) {}

    /**
     * Only an installed desktop activates against the online service; the
     * online service itself redeems its codes locally.
     */
    public function isAvailable(): bool
    {
        $linkable = (bool) config('medismart.online_service.linkable', false)
            || (bool) config('medismart.runtime.desktop_supervised', false);

        return $linkable && $this->endpoint() !== null;
    }

    /**
     * The online service address this build was made for: absolute HTTPS
     * with no credentials, query or fragment (plain HTTP on a developer
     * machine only).
     */
    public function endpoint(): ?string
    {
        $url = config('medismart.online_service.url');

        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $url = rtrim(trim($url), '/');
        $parts = parse_url($url);
        $scheme = is_array($parts) ? Str::lower((string) ($parts['scheme'] ?? '')) : '';
        $allowed = app()->environment('local') ? ['https', 'http'] : ['https'];

        if (! is_array($parts)
            || ! in_array($scheme, $allowed, true)
            || ! isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            return null;
        }

        return $url;
    }

    /**
     * @throws ValidationException under `$errorKey`
     */
    public function activateWithCode(
        #[SensitiveParameter] string $code,
        string $ownerEmail,
        string $errorKey = 'license_code',
    ): CloudActivation {
        return $this->request([
            'license_code' => trim($code),
            'owner_email' => Str::lower(trim($ownerEmail)),
        ], $errorKey);
    }

    /**
     * @throws ValidationException under `$errorKey`
     */
    public function activateWithAccount(
        string $email,
        #[SensitiveParameter] string $password,
        bool $link,
        string $deviceName,
        string $errorKey = 'online_email',
    ): CloudActivation {
        return $this->request([
            'email' => trim($email),
            'password' => $password,
            'link' => $link,
            'device_name' => Str::limit($deviceName, 250, ''),
        ], $errorKey);
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws ValidationException
     */
    private function request(#[SensitiveParameter] array $payload, string $errorKey): CloudActivation
    {
        $endpoint = $this->endpoint();

        if ($endpoint === null || ! $this->isAvailable()) {
            $this->fail($errorKey, 'Ce poste ne connaît pas l’adresse du service en ligne Drclick. Mettez Drclick à jour ou activez-le avec un fichier de licence.');
        }

        // Checked before anything is sent: a code redeemed online whose
        // answer this poste cannot verify would be spent for nothing.
        if (! $this->verifier->isConfigured()) {
            $this->fail($errorKey, 'Ce poste ne peut pas vérifier les licences Drclick : la clé de vérification est absente de cette installation. '
                .'Mettez Drclick à jour ou contactez le support.');
        }

        try {
            $installationId = $this->fingerprint->installationId();
        } catch (RuntimeException) {
            $this->fail($errorKey, 'L’identité de ce poste est introuvable. Redémarrez Drclick puis réessayez.');
        }

        try {
            $response = $this->http
                ->baseUrl($endpoint)
                ->acceptJson()
                ->asJson()
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->withoutRedirecting()
                ->post('/api/v1/desktop/activate', [
                    ...$payload,
                    'installation_id' => $installationId,
                    'app_version' => (string) config('medismart.version', 'unknown'),
                ]);
        } catch (ConnectionException) {
            $this->fail($errorKey, self::OFFLINE_MESSAGE, offline: true);
        }

        if (! $response->successful()) {
            $this->fail($errorKey, $this->refusalMessage($response));
        }

        $envelope = $response->json('entitlement');

        if (! is_string($envelope) || $envelope === '') {
            $this->fail($errorKey, 'Le service en ligne a renvoyé une réponse illisible. Réessayez plus tard.');
        }

        try {
            $entitlement = $this->verifier->verify($envelope);
        } catch (RuntimeException) {
            $this->fail($errorKey, 'La licence reçue du service en ligne n’a pas pu être vérifiée (signature refusée). '
                .'Ce poste n’a pas été activé. Contactez le support Drclick.');
        }

        if (! $entitlement->boundToHub($installationId)) {
            $this->fail($errorKey, 'La licence reçue a été émise pour un autre poste. Ce poste n’a pas été activé.');
        }

        $cabinet = $response->json('cabinet');
        $account = $response->json('account');
        $token = $response->json('token');

        return new CloudActivation(
            endpoint: $endpoint,
            envelope: $envelope,
            entitlement: $entitlement,
            cabinet: is_array($cabinet) ? $cabinet : [],
            token: is_string($token) && $token !== '' ? $token : null,
            accountEmail: is_array($account) && is_string($account['email'] ?? null) ? $account['email'] : null,
            accountCabinetName: is_array($account) && is_string($account['cabinet_name'] ?? null) ? $account['cabinet_name'] : null,
        );
    }

    private function refusalMessage(Response $response): string
    {
        $reason = $response->json('reason');
        $message = $response->json('message');
        $serverMessage = is_string($message) && trim($message) !== '' ? Str::limit(trim($message), 300) : null;

        return match (true) {
            $reason === DesktopActivationRefused::CODE_ALREADY_USED => 'Ce code de licence a déjà été utilisé sur un autre poste. '
                .'Chaque code ne s’utilise qu’une fois : demandez un nouveau code à Drclick, ou activez ce poste avec le compte en ligne du cabinet.',
            $reason === DesktopActivationRefused::INVALID_CODE => 'Ce code de licence est invalide ou n’est plus disponible. Vérifiez sa saisie.',
            $reason === DesktopActivationRefused::INVALID_CREDENTIALS => 'Adresse e-mail ou mot de passe incorrect sur le service en ligne.',
            $response->status() === 429 => 'Trop de tentatives d’activation. Patientez une minute puis réessayez.',
            $response->status() === 422 && $serverMessage === null => 'Les informations saisies ont été refusées par le service en ligne.',
            $serverMessage !== null && in_array($response->status(), [403, 409, 422, 503], true) => $serverMessage,
            default => sprintf(
                'Le service en ligne Drclick n’a pas pu traiter l’activation (code %d). Réessayez plus tard.',
                $response->status(),
            ),
        };
    }

    /**
     * @throws ValidationException
     */
    private function fail(string $errorKey, string $message, bool $offline = false): never
    {
        $exception = ValidationException::withMessages([$errorKey => $message]);

        if ($offline) {
            // Lets the activation screen show its "no Internet" state.
            session()->flash('activation_offline', true);
        }

        throw $exception;
    }
}
