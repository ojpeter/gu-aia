<?php

declare(strict_types=1);

namespace GuAia\Answering;

/**
 * What a generation call returned, including what it cost.
 *
 * Token counts and cost are not optional extras: INV-8 caps spend, and the
 * budget check before the next call is only as good as what the last one
 * reported. Section 13 requires both on every logged interaction.
 */
final readonly class GenerationResult
{
    public function __construct(
        public string $text,
        public int $promptTokens = 0,
        public int $completionTokens = 0,
        public float $cost = 0.0,
        public int $latencyMs = 0,
    ) {
    }
}
