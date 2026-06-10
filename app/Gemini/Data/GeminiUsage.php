<?php

namespace App\Gemini\Data;

readonly class GeminiUsage
{
    public function __construct(
        public string $modelId,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public string $pricingMode = 'standard',
        public string $inputModality = 'text',
        public ?int $contextTokens = null,
        public int $cachedReadTokens = 0,
        public float $cachedStorageTokenHours = 0.0,
    ) {}

    /**
     * @param  array<string, mixed>  $usage
     */
    public static function fromTokenPayload(
        string $modelId,
        array $usage,
        string $pricingMode = 'standard',
        string $inputModality = 'text',
        ?int $contextTokens = null,
    ): self {
        return new self(
            modelId: $modelId,
            inputTokens: (int) ($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? 0),
            outputTokens: (int) ($usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0),
            pricingMode: $pricingMode,
            inputModality: $inputModality,
            contextTokens: $contextTokens,
            cachedReadTokens: (int) ($usage['cached_read_tokens'] ?? 0),
            cachedStorageTokenHours: (float) ($usage['cached_storage_token_hours'] ?? 0),
        );
    }

    public function effectiveContextTokens(): int
    {
        return $this->contextTokens ?? ($this->inputTokens + $this->outputTokens);
    }
}
