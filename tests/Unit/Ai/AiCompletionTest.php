<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AiCompletion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The model is asked for JSON but may answer with a fence, a preamble or
 * plain garbage. Parsing must never throw: every feature relies on an array.
 */
class AiCompletionTest extends TestCase
{
    public function test_it_keeps_the_provider_details(): void
    {
        $completion = new AiCompletion('{"a":1}', 'qwen', 10, 20);

        $this->assertSame('{"a":1}', $completion->content);
        $this->assertSame('qwen', $completion->model);
        $this->assertSame(10, $completion->promptTokens);
        $this->assertSame(20, $completion->completionTokens);
        $this->assertNull($completion->balance);
    }

    public function test_with_balance_returns_a_copy_and_leaves_the_original_untouched(): void
    {
        $completion = new AiCompletion('x', 'qwen', 3, 4);
        $charged = $completion->withBalance(42);

        $this->assertNotSame($completion, $charged);
        $this->assertNull($completion->balance);
        $this->assertSame(42, $charged->balance);
        $this->assertSame('x', $charged->content);
        $this->assertSame('qwen', $charged->model);
        $this->assertSame(3, $charged->promptTokens);
        $this->assertSame(4, $charged->completionTokens);
    }

    public function test_token_counts_default_to_zero(): void
    {
        $completion = new AiCompletion('x', 'm');

        $this->assertSame(0, $completion->promptTokens);
        $this->assertSame(0, $completion->completionTokens);
    }

    /**
     * @return array<string, array{0: string, 1: array<mixed>}>
     */
    public static function parsableReplies(): array
    {
        return [
            'plain object' => ['{"motif":"Toux"}', ['motif' => 'Toux']],
            'surrounding whitespace' => ["  \n{\"motif\":\"Toux\"}\n  ", ['motif' => 'Toux']],
            'json fence' => ["```json\n{\"motif\":\"Toux\"}\n```", ['motif' => 'Toux']],
            'bare fence' => ["```\n{\"motif\":\"Toux\"}\n```", ['motif' => 'Toux']],
            'uppercase fence language' => ["```JSON\n{\"motif\":\"Toux\"}\n```", ['motif' => 'Toux']],
            'preamble before the object' => ['Voici la réponse : {"motif":"Toux"}', ['motif' => 'Toux']],
            'text after the object' => ['{"motif":"Toux"} Bonne journée.', ['motif' => 'Toux']],
            'nested objects' => ['{"quality":{"rating":"bonne","issues":[]}}', ['quality' => ['rating' => 'bonne', 'issues' => []]]],
            'unicode content' => ['{"diagnostic":"Fièvre à 39 °C — bronchite"}', ['diagnostic' => 'Fièvre à 39 °C — bronchite']],
            'top-level list' => ['[1,2,3]', [1, 2, 3]],
            'empty object' => ['{}', []],
        ];
    }

    /**
     * @param  array<mixed>  $expected
     */
    #[DataProvider('parsableReplies')]
    public function test_it_parses_json_in_every_shape_the_model_uses(string $content, array $expected): void
    {
        $this->assertSame($expected, (new AiCompletion($content, 'm'))->json());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unparsableReplies(): array
    {
        return [
            'empty string' => [''],
            'whitespace' => ["   \n\t "],
            'prose' => ['Je ne peux pas répondre à cette question.'],
            'scalar json string' => ['"bonjour"'],
            'scalar json number' => ['42'],
            'json null' => ['null'],
            'json true' => ['true'],
            'truncated object' => ['{"motif": "Toux", "examens": '],
            'only an opening brace' => ['{'],
            'only a closing brace' => ['}'],
            'braces in the wrong order' => ['} texte {'],
            'broken object between braces' => ['Réponse : {motif: Toux} fin'],
            'empty fence' => ["```json\n```"],
        ];
    }

    #[DataProvider('unparsableReplies')]
    public function test_an_unreadable_reply_becomes_an_empty_array_instead_of_failing(string $content): void
    {
        $this->assertSame([], (new AiCompletion($content, 'm'))->json());
    }
}
