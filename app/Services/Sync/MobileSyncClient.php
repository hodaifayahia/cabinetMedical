<?php

namespace App\Services\Sync;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * HTTP transport for appointment sync.
 *
 * This class does nothing but talk to the remote: no local writes, no decisions
 * about conflicts. That keeps the interesting logic — matching, versioning,
 * echo suppression — in classes that can be tested without a network.
 */
final class MobileSyncClient
{
    /**
     * Deliberately short. Sync is a foreground action a clinician triggered; if
     * the network is poor, failing quickly and saying so beats a frozen button.
     */
    private const CONNECT_TIMEOUT_SECONDS = 5;

    private const REQUEST_TIMEOUT_SECONDS = 20;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly MobileSyncSettings $settings,
    ) {}

    /**
     * Fetch one page of remote events after `$cursor`.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     *
     * @throws SyncTransportException
     */
    public function pull(int $cursor, int $limit): array
    {
        $response = $this->send(
            fn (PendingRequest $request) => $request->get('/api/v1/sync/appointments', [
                'cursor' => $cursor,
                'limit' => $limit,
            ]),
            'pull',
        );

        $body = $response;

        return [
            'data' => is_array($body['data'] ?? null) ? array_values($body['data']) : [],
            'meta' => is_array($body['meta'] ?? null) ? $body['meta'] : [],
        ];
    }

    /**
     * Tell the remote every event through `$cursor` has been consumed, so its
     * pending queue does not grow without bound.
     *
     * @throws SyncTransportException
     */
    public function acknowledge(int $cursor): void
    {
        $this->send(
            fn (PendingRequest $request) => $request->post('/api/v1/sync/appointments/ack', [
                'cursor' => $cursor,
            ]),
            'acknowledge',
        );
    }

    /**
     * Deliver local events to the remote, which applies them with the same
     * importer this installation uses for the pull direction.
     *
     * @param  list<array<string, mixed>>  $events
     * @return array<string, mixed>
     *
     * @throws SyncTransportException
     */
    public function push(array $events): array
    {
        return $this->send(
            fn (PendingRequest $request) => $request->post('/api/v1/sync/appointments/push', [
                'events' => $events,
            ]),
            'push',
        );
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     * @return array<string, mixed>
     *
     * @throws SyncTransportException
     */
    private function send(callable $call, string $operation): array
    {
        $endpoint = $this->settings->endpoint();
        $token = $this->settings->token();

        if ($endpoint === null || $token === null) {
            throw new SyncTransportException(
                "La synchronisation n'est pas configurée sur ce poste.",
            );
        }

        $request = $this->http
            ->baseUrl($endpoint)
            ->withToken($token)
            ->acceptJson()
            ->asJson()
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->timeout(self::REQUEST_TIMEOUT_SECONDS);

        try {
            $response = $call($request);
        } catch (ConnectionException) {
            // The expected case for a local-first installation: no internet.
            // It is not an error state, just "not now".
            throw new SyncTransportException(
                'Le service en ligne est injoignable. Réessayez lorsque vous aurez une connexion Internet.',
                offline: true,
            );
        }

        if ($response->unauthorized() || $response->forbidden()) {
            throw new SyncTransportException(
                "L'autorisation de synchronisation a expiré. Reconnectez ce poste au service en ligne.",
            );
        }

        if ($response->failed()) {
            // The remote body may echo clinical detail, so only the status is
            // reported; the operation name says which call failed.
            throw new SyncTransportException(sprintf(
                'Le service en ligne a refusé la synchronisation (%s, code %d).',
                $operation,
                $response->status(),
            ));
        }

        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw new SyncTransportException(
                'Le service en ligne a renvoyé une réponse illisible.',
            );
        }

        return $decoded;
    }
}
