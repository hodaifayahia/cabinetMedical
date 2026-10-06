<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
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
     * The recorded audio of a dictation segment, as text. An empty string
     * means the segment held no speech.
     */
    public function transcribe(string $path, string $mime, ?string $model = null): AiCompletion
    {
        if (! $this->isConfigured()) {
            throw new AiException('Le service IA n’est pas configuré sur ce serveur.', AiException::UNAVAILABLE);
        }

        $audio = @file_get_contents($path);

        if (! is_string($audio) || $audio === '') {
            throw new AiException('L’enregistrement audio est vide ou illisible.', AiException::UNSUPPORTED);
        }

        $model ??= (string) config('ai.transcription_model');
        $driver = (string) config('ai.transcription_driver', 'chat_input_audio');
        $request = $this->http
            ->withToken((string) (config('ai.transcription_api_key') ?: config('ai.api_key')))
            ->acceptJson()
            ->connectTimeout(10)
            ->timeout((int) config('ai.timeout', 60));
        $baseUrl = (string) (config('ai.transcription_base_url') ?: config('ai.base_url'));

        try {
            $response = $driver === 'openai_transcriptions'
                ? $this->openAiTranscription($request, $baseUrl, $audio, $mime, $model)
                : $this->chatInputAudio($request, $baseUrl, $audio, $mime, $model);
        } catch (ConnectionException) {
            throw new AiException('Le service de transcription est injoignable. Réessayez dans un instant.', AiException::UNAVAILABLE);
        }

        if ($response->failed()) {
            // Status only: the body may echo what the patient said.
            Log::warning('AI transcription request failed', ['status' => $response->status(), 'model' => $model, 'driver' => $driver]);

            throw new AiException('La transcription de la dictée a échoué. Réessayez ou tapez vos notes.', AiException::PROVIDER);
        }

        $text = $driver === 'openai_transcriptions'
            ? $response->json('text')
            : $response->json('choices.0.message.content');

        if (is_array($text)) {
            // Some servers answer with content parts.
            $text = implode(' ', array_filter(array_map(
                static fn (mixed $part): ?string => is_array($part) && is_string($part['text'] ?? null) ? $part['text'] : null,
                $text,
            )));
        }

        if (! is_string($text)) {
            throw new AiException('Le service de transcription a renvoyé une réponse illisible.', AiException::PROVIDER);
        }

        return new AiCompletion(
            content: trim($text),
            model: (string) ($response->json('model') ?? $model),
            promptTokens: (int) $response->json('usage.prompt_tokens', $response->json('usage.input_tokens', 0)),
            completionTokens: (int) $response->json('usage.completion_tokens', $response->json('usage.output_tokens', 0)),
        );
    }

    /**
     * Alibaba Model Studio (compatible mode) serves Qwen3-ASR as a chat
     * completion whose user turn is one `input_audio` part.
     */
    private function chatInputAudio(PendingRequest $request, string $baseUrl, string $audio, string $mime, string $model): Response
    {
        return $request->asJson()->post($baseUrl.'/chat/completions', [
            'model' => $model,
            'messages' => [[
                'role' => 'user',
                'content' => [[
                    'type' => 'input_audio',
                    'input_audio' => ['data' => 'data:'.$mime.';base64,'.base64_encode($audio)],
                ]],
            ]],
            'stream' => false,
            'asr_options' => [
                'language' => (string) config('ai.transcription_language', 'fr'),
                // "trente-huit cinq" becomes "38,5".
                'enable_itn' => true,
            ],
        ]);
    }

    /**
     * OpenAI-style speech to text (Whisper and compatible servers).
     */
    private function openAiTranscription(PendingRequest $request, string $baseUrl, string $audio, string $mime, string $model): Response
    {
        return $request
            ->attach('file', $audio, 'dictee.'.self::audioExtension($mime), ['Content-Type' => $mime])
            ->post($baseUrl.'/audio/transcriptions', [
                'model' => $model,
                'language' => (string) config('ai.transcription_language', 'fr'),
                'response_format' => 'json',
            ]);
    }

    public static function audioExtension(string $mime): string
    {
        return match (true) {
            str_contains($mime, 'ogg'), str_contains($mime, 'opus') => 'ogg',
            str_contains($mime, 'mp4'), str_contains($mime, 'm4a') => 'm4a',
            str_contains($mime, 'mpeg') => 'mp3',
            str_contains($mime, 'wav') => 'wav',
            default => 'webm',
        };
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
