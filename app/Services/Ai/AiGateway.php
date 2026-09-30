<?php

namespace App\Services\Ai;

use App\Enums\AiFeature;
use App\Models\Cabinet;
use App\Models\LandingSetting;
use App\Models\User;
use App\Services\Sync\MobileSyncSettings;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * The single door every AI feature goes through.
 *
 * - `direct`: this server holds the provider key (the hosted control plane).
 *   The user's cabinet wallet is charged here.
 * - `relay`: a local desktop with no key. The request is forwarded to the
 *   hosted service with the Sanctum token the installation already holds for
 *   appointment sync; the hosted service charges the wallet and answers.
 */
final class AiGateway
{
    public function __construct(
        private readonly AiProviderClient $provider,
        private readonly AiCreditLedger $ledger,
        private readonly MobileSyncSettings $syncSettings,
        private readonly HttpFactory $http,
    ) {}

    /**
     * With $user given, a relay is offered only to a user of the cabinet the
     * desktop was linked for: the hosted service charges the token owner's
     * wallet.
     */
    public function mode(?User $user = null): ?string
    {
        if ($this->provider->isConfigured()) {
            return 'direct';
        }

        return $this->syncSettings->endpoint() !== null
            && $this->syncSettings->token() !== null
            && ($user === null || $this->syncSettings->servesCabinet($user->cabinet_id))
            ? 'relay'
            : null;
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     */
    public function complete(User $user, AiFeature $feature, array $messages, bool $vision = false, bool $json = true): AiCompletion
    {
        return match ($this->mode($user)) {
            'direct' => $this->ledger->spend(
                $this->cabinetOf($user),
                $user,
                $feature,
                fn (): AiCompletion => $this->provider->complete($messages, $vision, $json, $feature->model($vision)),
            ),
            'relay' => $this->relayComplete($feature, $messages, $vision, $json),
            default => throw new AiException(
                'L’assistant IA n’est pas encore connecté sur ce poste. Reliez ce poste au service en ligne (Configuration › Service en ligne) puis réessayez.',
                AiException::UNAVAILABLE,
            ),
        };
    }

    /**
     * What the doctor's screens show next to each AI button.
     *
     * @return array{available: bool, enabled: bool, balance: int|null, costs: array<string, int>, support: array{phone: string|null, email: string|null}, message: string|null}
     */
    public function status(User $user): array
    {
        $base = [
            'available' => false,
            'enabled' => true,
            'balance' => null,
            'costs' => AiFeature::costs(),
            'support' => $this->support(),
            'message' => null,
        ];

        try {
            $mode = $this->mode($user);

            if ($mode === 'direct') {
                $cabinet = $this->cabinetOf($user);

                return [
                    ...$base,
                    'available' => true,
                    'enabled' => (bool) $cabinet->ai_enabled,
                    'balance' => $this->ledger->balance($cabinet),
                ];
            }

            if ($mode === 'relay') {
                $remote = $this->send(fn (PendingRequest $request) => $request->get('/api/v1/ai/status'));

                return [
                    ...$base,
                    'available' => (bool) ($remote['available'] ?? false),
                    'enabled' => (bool) ($remote['enabled'] ?? true),
                    'balance' => isset($remote['balance']) ? (int) $remote['balance'] : null,
                    'costs' => is_array($remote['costs'] ?? null) ? array_map('intval', $remote['costs']) : $base['costs'],
                    'support' => is_array($remote['support'] ?? null) ? [
                        'phone' => $remote['support']['phone'] ?? null,
                        'email' => $remote['support']['email'] ?? null,
                    ] : $base['support'],
                ];
            }
        } catch (AiException $exception) {
            return [...$base, 'message' => $exception->getMessage()];
        }

        return [...$base, 'message' => 'L’assistant IA n’est pas encore connecté sur ce poste.'];
    }

    /**
     * @return array{phone: string|null, email: string|null}
     */
    public function support(): array
    {
        $read = static function (string $key): ?string {
            try {
                $value = LandingSetting::query()->where('key', $key)->value('value');
            } catch (\Throwable) {
                return null;
            }

            return is_string($value) && trim($value) !== '' ? trim($value) : null;
        };

        return [
            'phone' => $read('contact_phone'),
            'email' => $read('contact_email'),
        ];
    }

    public function cabinetOf(User $user): Cabinet
    {
        $cabinet = $user->cabinet_id !== null ? Cabinet::query()->find($user->cabinet_id) : null;

        if (! $cabinet instanceof Cabinet) {
            throw new AiException('Ce compte n’est rattaché à aucun cabinet : les crédits IA sont attribués par cabinet.', AiException::UNAVAILABLE);
        }

        return $cabinet;
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     */
    private function relayComplete(AiFeature $feature, array $messages, bool $vision, bool $json): AiCompletion
    {
        $body = $this->send(fn (PendingRequest $request) => $request
            ->timeout((int) config('ai.timeout', 60) + 15)
            ->post('/api/v1/ai/complete', [
                'feature' => $feature->value,
                'messages' => $messages,
                'vision' => $vision,
                'json' => $json,
            ]), charges: true);

        $content = $body['content'] ?? null;

        if (! is_string($content)) {
            throw new AiException('Le service en ligne a renvoyé une réponse illisible.', AiException::PROVIDER);
        }

        return new AiCompletion(
            content: $content,
            model: (string) ($body['model'] ?? ''),
            balance: isset($body['balance']) ? (int) $body['balance'] : null,
        );
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     * @param  bool  $charges  whether the hosted service charges the wallet for this call
     * @return array<string, mixed>
     */
    private function send(callable $call, bool $charges = false): array
    {
        $request = $this->http
            ->baseUrl((string) $this->syncSettings->endpoint())
            ->withToken((string) $this->syncSettings->token())
            ->acceptJson()
            ->asJson()
            ->connectTimeout(8)
            ->timeout(20);

        try {
            $response = $call($request);
        } catch (ConnectionException $exception) {
            if ($charges && $this->reachedService($exception)) {
                // The hosted service charges before it calls the provider and
                // keeps going when this side gives up, so a retry could pay twice.
                throw new AiException(
                    'La réponse de l’assistant IA n’est pas arrivée à temps. La demande a peut-être déjà été traitée et facturée : vérifiez le solde de crédits avant de réessayer.',
                    AiException::UNAVAILABLE,
                );
            }

            throw new AiException('L’assistant IA a besoin d’Internet. Vérifiez la connexion puis réessayez.', AiException::UNAVAILABLE);
        }

        $body = $response->json();
        $body = is_array($body) ? $body : [];

        if ($response->unauthorized()) {
            // A revoked token never works again; forgetting it lets the
            // settings page offer to link this desktop again.
            $this->syncSettings->forget();

            throw new AiException('La connexion de ce poste au service en ligne a expiré. Reliez-le à nouveau dans Configuration › Service en ligne.', AiException::UNAVAILABLE);
        }

        if ($response->failed()) {
            $reason = is_string($body['reason'] ?? null) ? $body['reason'] : AiException::PROVIDER;
            $message = is_string($body['message'] ?? null) ? $body['message'] : 'Le service IA n’a pas pu répondre.';

            throw new AiException($message, $reason, isset($body['balance']) ? (int) $body['balance'] : null);
        }

        return $body;
    }

    /**
     * Whether the connection was up when the call failed, so the request may
     * have reached the hosted service. cURL reports a zero pre-transfer time
     * when the connection (DNS, TCP, TLS) never completed.
     */
    private function reachedService(ConnectionException $exception): bool
    {
        $previous = $exception->getPrevious();

        if (! $previous instanceof ConnectException) {
            return false;
        }

        $context = $previous->getHandlerContext();

        return (float) ($context['pretransfer_time'] ?? 0) > 0
            || (float) ($context['size_upload'] ?? 0) > 0;
    }
}
