<?php

namespace App\Services\Sync;

use App\Models\AuditLog;
use App\Models\Cabinet;
use App\Services\Cabinet\CabinetSeatService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Links a local desktop to the hosted Drclick service.
 *
 * The doctor signs in with their online account once; the service answers
 * with a Sanctum token that this installation keeps (encrypted) and uses for
 * everything that needs the Internet: the seats bought in the admin panel,
 * appointment sync with the mobile application, and the AI assistant. The
 * password is sent once and never stored.
 *
 * Before the link is kept, the online account must be proven to belong to
 * this desktop's cabinet, so one clinic's desktop can never start pulling
 * another clinic's seats or pushing its appointments into the wrong agenda.
 * The link then serves that local cabinet only.
 */
final class OnlineServiceLink
{
    private const CONNECT_TIMEOUT_SECONDS = 5;

    private const REQUEST_TIMEOUT_SECONDS = 20;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly MobileSyncSettings $settings,
        private readonly CabinetSeatService $seats,
    ) {}

    /**
     * Only an installed desktop links to the online service. The online
     * service itself — and a browser-served copy of it — has nothing to link
     * to; a developer machine may, to exercise the flow.
     */
    public function isAvailable(): bool
    {
        return (bool) config('medismart.online_service.linkable', false)
            || app()->environment('local');
    }

    public function isLinked(): bool
    {
        return $this->settings->endpoint() !== null && $this->settings->token() !== null;
    }

    /**
     * Whether a user of $cabinet may use the link. A desktop holding several
     * cabinets is linked for one of them only.
     */
    public function isLinkedFor(Cabinet $cabinet): bool
    {
        return $this->isLinked() && $this->settings->servesCabinet($cabinet->getKey());
    }

    /**
     * What Configuration › Service en ligne shows a user of $cabinet. A link
     * made for another cabinet of this computer is reported as such, without
     * that cabinet's account.
     *
     * @return array{linked: bool, linkedElsewhere: bool, endpoint: string|null, accountEmail: string|null, cabinetName: string|null, linkedAt: string|null}
     */
    public function status(?Cabinet $cabinet = null): array
    {
        $linked = $cabinet === null ? $this->isLinked() : $this->isLinkedFor($cabinet);

        return [
            'linked' => $linked,
            'linkedElsewhere' => ! $linked && $this->isLinked(),
            'endpoint' => $this->settings->endpoint() ?? $this->defaultEndpoint(),
            'accountEmail' => $linked ? $this->settings->accountEmail() : null,
            'cabinetName' => $linked ? $this->settings->cabinetName() : null,
            'linkedAt' => $linked ? $this->settings->linkedAt()?->toIso8601String() : null,
        ];
    }

    /**
     * Sign in to the online service and keep the link. Returns the seat
     * limit confirmed online.
     *
     * The link is kept only once the online service has confirmed that the
     * account belongs to this cabinet. Anything short of that — another
     * cabinet, an account without one, an error, a connection lost halfway —
     * revokes the token just issued and leaves the desktop unlinked.
     *
     * @throws ValidationException
     */
    public function link(
        Cabinet $cabinet,
        string $endpoint,
        string $email,
        #[SensitiveParameter] string $password,
    ): int {
        $endpoint = $this->normaliseEndpoint($endpoint);
        $body = $this->requestToken($cabinet, $endpoint, $email, $password);

        $token = $body['token'];
        $user = is_array($body['user'] ?? null) ? $body['user'] : [];
        $accountEmail = is_string($user['email'] ?? null) ? $user['email'] : $email;
        $cabinetName = is_string($user['cabinet']['name'] ?? null) ? $user['cabinet']['name'] : null;

        if (($user['is_platform_admin'] ?? false) === true) {
            // Such a token reads every tenant's appointment stream.
            $this->revokeRemoteToken($endpoint, $token);

            throw ValidationException::withMessages([
                'email' => 'Un compte d’administration de la plateforme ne peut pas relier un poste. '
                    .'Utilisez le compte en ligne de ce cabinet.',
            ]);
        }

        $this->settings->configure($endpoint, $token, $accountEmail, $cabinetName, (int) $cabinet->getKey());

        try {
            $seatLimit = $this->seats->refresh($cabinet)->seatLimit();
        } catch (SyncTransportException $exception) {
            $this->revokeRemoteToken($endpoint, $token);
            $this->settings->forget();

            throw ValidationException::withMessages([
                'email' => match (true) {
                    $exception->reason === SyncTransportException::REASON_CABINET_MISMATCH => 'Ce compte en ligne appartient à un autre cabinet que celui de ce poste. '
                        .'Utilisez le compte en ligne de ce cabinet, dont le titulaire a la même adresse e-mail que sur ce poste.',
                    $exception->offline => 'La connexion au service en ligne s’est interrompue avant qu’il confirme le cabinet de ce compte. '
                        .'Le poste n’a pas été relié : réessayez.',
                    default => 'Le poste n’a pas été relié : le service en ligne n’a pas confirmé que ce compte appartient à ce cabinet. '
                        .$exception->getMessage(),
                },
            ]);
        }

        AuditLog::record('online_service.linked', $cabinet, [
            'endpoint_host' => parse_url($endpoint, PHP_URL_HOST),
            'account_email' => $accountEmail,
            'seat_limit' => $seatLimit,
        ]);

        return $seatLimit;
    }

    /**
     * Forget the link. The token is revoked on the online service when it can
     * be reached; either way this desktop stops using it. The seats already
     * received are kept.
     */
    public function unlink(Cabinet $cabinet): void
    {
        $endpoint = $this->settings->endpoint();
        $token = $this->settings->token();

        if ($endpoint !== null && $token !== null) {
            $this->revokeRemoteToken($endpoint, $token);
        }

        $this->settings->forget();

        AuditLog::record('online_service.unlinked', $cabinet, [
            'endpoint_host' => $endpoint === null ? null : parse_url($endpoint, PHP_URL_HOST),
        ]);
    }

    /**
     * @return array{token: string, user?: mixed}
     *
     * @throws ValidationException
     */
    private function requestToken(
        Cabinet $cabinet,
        string $endpoint,
        string $email,
        #[SensitiveParameter] string $password,
    ): array {
        try {
            $response = $this->http
                ->baseUrl($endpoint)
                ->acceptJson()
                ->asJson()
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->post('/api/v1/auth/token', [
                    'email' => $email,
                    'password' => $password,
                    'device_name' => Str::limit('Poste Drclick — '.$cabinet->name, 250, ''),
                ]);
        } catch (ConnectionException) {
            throw ValidationException::withMessages([
                'endpoint' => 'Le service en ligne est injoignable. Vérifiez la connexion Internet et l’adresse, puis réessayez.',
            ]);
        }

        if ($response->status() === 422) {
            throw ValidationException::withMessages([
                'email' => 'Adresse e-mail ou mot de passe incorrect sur le service en ligne.',
            ]);
        }

        if ($response->status() === 403) {
            // The service explains why the account may not sign in (cabinet
            // pending, suspended, licence expired…) in French already.
            $message = $response->json('message');

            throw ValidationException::withMessages([
                'email' => is_string($message) && $message !== ''
                    ? Str::limit($message, 300)
                    : 'Ce compte ne peut pas se connecter au service en ligne pour le moment.',
            ]);
        }

        $body = $response->json();

        if ($response->failed() || ! is_array($body) || ! is_string($body['token'] ?? null) || $body['token'] === '') {
            throw ValidationException::withMessages([
                'endpoint' => 'Cette adresse ne répond pas comme le service en ligne Drclick. Vérifiez-la puis réessayez.',
            ]);
        }

        /** @var array{token: string, user?: mixed} $body */
        return $body;
    }

    /**
     * Best effort: a token that cannot be revoked now simply stays unused.
     */
    private function revokeRemoteToken(string $endpoint, #[SensitiveParameter] string $token): void
    {
        try {
            $this->http
                ->baseUrl($endpoint)
                ->withToken($token)
                ->acceptJson()
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->post('/api/v1/auth/logout');
        } catch (ConnectionException) {
            // Offline: nothing more to do.
        }
    }

    /**
     * An absolute HTTPS address with no credentials, query or fragment. Plain
     * HTTP is accepted only on a developer machine.
     *
     * @throws ValidationException
     */
    private function normaliseEndpoint(string $endpoint): string
    {
        $endpoint = rtrim(trim($endpoint), '/');
        $parts = parse_url($endpoint);
        $scheme = is_array($parts) ? Str::lower((string) ($parts['scheme'] ?? '')) : '';
        $allowedSchemes = app()->environment('local') ? ['https', 'http'] : ['https'];

        if (! is_array($parts)
            || ! in_array($scheme, $allowedSchemes, true)
            || ! isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw ValidationException::withMessages([
                'endpoint' => 'Saisissez l’adresse complète du service en ligne, commençant par https://.',
            ]);
        }

        return $endpoint;
    }

    private function defaultEndpoint(): ?string
    {
        $url = config('medismart.online_service.url');

        return is_string($url) && trim($url) !== '' ? rtrim(trim($url), '/') : null;
    }
}
