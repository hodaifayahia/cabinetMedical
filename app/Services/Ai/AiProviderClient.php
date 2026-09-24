<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;

/**
 * Talks to the OpenAI-compatible chat completions endpoint. Only the hosted
 * control plane (or a developer machine) holds the key.
 */
final class AiProviderClient
{
    public function __construct(private readonly HttpFactory $http) {}

    public function isConfigured(): bool
    {
        return (string) config('ai.api_key') !== '';
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     */
    public function complete(array $messages, bool $vision = false, bool $json = true, ?string $model = null): AiCompletion
    {
        if (! $this->isConfigured()) {
            throw new AiException('Le service IA n’est pas configuré sur ce serveur.', AiException::UNAVAILABLE);
        }

        $model ??= (string) config($vision ? 'ai.vision_model' : 'ai.model');

        try {
            $response = $this->http
                ->withToken((string) config('ai.api_key'))
                ->acceptJson()
                ->asJson()
                ->connectTimeout(10)
                ->timeout((int) config('ai.timeout', 60))
                ->post(config('ai.base_url').'/chat/completions', array_filter([
                    'model' => $model,
                    'messages' => $messages,
                    'temperature' => 0.2,
                    // Qwen reasoning models think by default; a suggestion must
                    // come back in seconds, not after a long chain of thought.
                    'enable_thinking' => false,
                    // Chat replies are prose; everything else is parsed JSON.
                    'response_format' => $json ? ['type' => 'json_object'] : null,
                ], static fn ($value): bool => $value !== null));
        } catch (ConnectionException) {
            throw new AiException('Le service IA est injoignable. Réessayez dans un instant.', AiException::UNAVAILABLE);
        }

        if ($response->failed()) {
            // Status only: the provider body may echo the clinical prompt.
            Log::warning('AI provider request failed', ['status' => $response->status(), 'model' => $model]);

            throw new AiException('Le service IA n’a pas pu répondre. Aucun crédit n’a été décompté.', AiException::PROVIDER);
        }

        $content = $response->json('choices.0.message.content');

        if (! is_string($content) || trim($content) === '') {
            throw new AiException('Le service IA a renvoyé une réponse vide. Aucun crédit n’a été décompté.', AiException::PROVIDER);
        }

        return new AiCompletion(
            content: $content,
            model: (string) ($response->json('model') ?? $model),
            promptTokens: (int) $response->json('usage.prompt_tokens', 0),
            completionTokens: (int) $response->json('usage.completion_tokens', 0),
        );
    }
}
