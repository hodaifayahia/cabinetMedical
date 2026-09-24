<?php

namespace App\Services\Ai;

final class AiCompletion
{
    public function __construct(
        public readonly string $content,
        public readonly string $model,
        public readonly int $promptTokens = 0,
        public readonly int $completionTokens = 0,
        public readonly ?int $balance = null,
    ) {}

    public function withBalance(int $balance): self
    {
        return new self($this->content, $this->model, $this->promptTokens, $this->completionTokens, $balance);
    }

    /**
     * The model is asked for a JSON object; tolerate a fenced reply anyway.
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        $text = trim($this->content);

        if (str_starts_with($text, '```')) {
            $text = (string) preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $text);
        }

        $decoded = json_decode($text, true);

        if (! is_array($decoded)) {
            $start = strpos($text, '{');
            $end = strrpos($text, '}');
            $decoded = $start !== false && $end !== false
                ? json_decode(substr($text, $start, $end - $start + 1), true)
                : null;
        }

        return is_array($decoded) ? $decoded : [];
    }
}
