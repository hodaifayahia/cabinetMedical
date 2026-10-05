<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\AiException;
use App\Services\Ai\AiProviderClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InteractsWithAi;
use Tests\TestCase;

/**
 * The OpenAI-compatible client. Its failures must always be safe to show the
 * doctor and must never echo the provider body.
 */
class AiProviderClientTest extends TestCase
{
    use InteractsWithAi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureDirectAi();
    }

    private function client(): AiProviderClient
    {
        return app(AiProviderClient::class);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function messages(): array
    {
        return [['role' => 'user', 'content' => 'Bonjour']];
    }

    private function catch(callable $call): AiException
    {
        try {
            $call();
        } catch (AiException $exception) {
            return $exception;
        }

        $this->fail('An AiException was expected.');
    }

    public function test_it_is_configured_only_with_a_key(): void
    {
        $this->assertTrue($this->client()->isConfigured());

        config(['ai.api_key' => '']);

        $this->assertFalse($this->client()->isConfigured());
    }

    public function test_without_a_key_nothing_is_sent(): void
    {
        config(['ai.api_key' => '']);
        Http::fake();

        $exception = $this->catch(fn () => $this->client()->complete($this->messages()));

        $this->assertSame(AiException::UNAVAILABLE, $exception->reason);
        Http::assertNothingSent();
    }

    public function test_a_json_request_is_sent_with_the_expected_body(): void
    {
        $this->fakeAiReply(['ok' => true]);

        $this->client()->complete($this->messages());

        Http::assertSent(fn (HttpRequest $request): bool => $request->url() === self::AI_PROVIDER
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer test-key')
            && $request['model'] === 'text-model'
            && $request['messages'] === [['role' => 'user', 'content' => 'Bonjour']]
            && $request['temperature'] === 0.2
            && $request['enable_thinking'] === false
            && $request['response_format'] === ['type' => 'json_object']);
    }

    public function test_a_prose_request_has_no_response_format(): void
    {
        $this->fakeAiReply('Réponse libre');

        $this->client()->complete($this->messages(), json: false);

        Http::assertSent(fn (HttpRequest $request): bool => ! array_key_exists('response_format', $request->data()));
    }

    public function test_a_vision_request_uses_the_vision_model(): void
    {
        $this->fakeAiReply(['ok' => true]);

        $this->client()->complete($this->messages(), vision: true);

        Http::assertSent(fn (HttpRequest $request): bool => $request['model'] === 'vision-model');
    }

    public function test_an_explicit_model_wins(): void
    {
        $this->fakeAiReply(['ok' => true]);

        $this->client()->complete($this->messages(), vision: true, model: 'ecg-model');

        Http::assertSent(fn (HttpRequest $request): bool => $request['model'] === 'ecg-model');
    }

    public function test_a_successful_reply_is_returned_with_its_usage(): void
    {
        $this->fakeAiReply(['motif' => 'Toux'], 'qwen-served');

        $completion = $this->client()->complete($this->messages());

        $this->assertSame(['motif' => 'Toux'], $completion->json());
        $this->assertSame('qwen-served', $completion->model);
        $this->assertSame(800, $completion->promptTokens);
        $this->assertSame(120, $completion->completionTokens);
        $this->assertNull($completion->balance);
    }

    public function test_a_reply_without_model_or_usage_falls_back_to_defaults(): void
    {
        Http::fake([self::AI_PROVIDER => Http::response(['choices' => [['message' => ['content' => '{"a":1}']]]])]);

        $completion = $this->client()->complete($this->messages());

        $this->assertSame('text-model', $completion->model);
        $this->assertSame(0, $completion->promptTokens);
        $this->assertSame(0, $completion->completionTokens);
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function failingStatuses(): array
    {
        return [
            'bad request' => [400],
            'bad key' => [401],
            'forbidden' => [403],
            'rate limited' => [429],
            'server error' => [500],
            'bad gateway' => [502],
            'unavailable' => [503],
        ];
    }

    #[DataProvider('failingStatuses')]
    public function test_a_failed_call_is_a_provider_error_that_hides_the_body(int $status): void
    {
        Http::fake([self::AI_PROVIDER => Http::response(['error' => ['message' => 'Patient Benali: prompt echoed']], $status)]);
        Log::spy();

        $exception = $this->catch(fn () => $this->client()->complete($this->messages()));

        $this->assertSame(AiException::PROVIDER, $exception->reason);
        $this->assertSame(502, $exception->httpStatus());
        $this->assertStringNotContainsString('Benali', $exception->getMessage());
        $this->assertStringContainsString('Aucun crédit', $exception->getMessage());
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $context === ['status' => $status, 'model' => 'text-model'])->once();
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function emptyReplies(): array
    {
        return [
            'no choices' => [[]],
            'empty choices' => [['choices' => []]],
            'null content' => [['choices' => [['message' => ['content' => null]]]]],
            'empty content' => [['choices' => [['message' => ['content' => '']]]]],
            'whitespace content' => [['choices' => [['message' => ['content' => "  \n "]]]]],
            'array content' => [['choices' => [['message' => ['content' => ['text' => 'x']]]]]],
            'number content' => [['choices' => [['message' => ['content' => 42]]]]],
        ];
    }

    #[DataProvider('emptyReplies')]
    public function test_an_empty_reply_is_a_provider_error(mixed $body): void
    {
        Http::fake([self::AI_PROVIDER => Http::response($body)]);

        $exception = $this->catch(fn () => $this->client()->complete($this->messages()));

        $this->assertSame(AiException::PROVIDER, $exception->reason);
        $this->assertStringContainsString('vide', $exception->getMessage());
    }

    public function test_a_non_json_body_is_a_provider_error(): void
    {
        Http::fake([self::AI_PROVIDER => Http::response('<html>Gateway</html>', 200, ['Content-Type' => 'text/html'])]);

        $this->assertSame(AiException::PROVIDER, $this->catch(fn () => $this->client()->complete($this->messages()))->reason);
    }

    public function test_an_unreachable_provider_is_unavailable(): void
    {
        Http::fake([self::AI_PROVIDER => fn () => throw new ConnectionException('cURL error 7: Failed to connect')]);

        $exception = $this->catch(fn () => $this->client()->complete($this->messages()));

        $this->assertSame(AiException::UNAVAILABLE, $exception->reason);
        $this->assertSame(503, $exception->httpStatus());
        $this->assertStringNotContainsString('cURL', $exception->getMessage());
    }

    public function test_the_base_url_is_configurable(): void
    {
        config(['ai.base_url' => 'https://other.test/compatible-mode/v1']);
        Http::fake(['https://other.test/compatible-mode/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => '{}']]]])]);

        $this->client()->complete($this->messages());

        Http::assertSentCount(1);
    }
}
