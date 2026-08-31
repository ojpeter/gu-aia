<?php

declare(strict_types=1);

namespace GuAia\Answering;

/**
 * The fake generator. requirements.md Section 3; CLAUDE.md Rule 10.
 *
 * "External API behind a Generator interface, WITH A FAKE FOR TESTS and a hard
 *  budget guard." The whole system must be testable and demonstrable with no API
 *  key and no spend, which is also why GENERATOR_DRIVER=fake is the default in
 *  .env.example.
 *
 * It records every call, so a test can assert not only what came back but
 * whether the generator was called at all — which is exactly how INV-2 is
 * tested: a fees question must produce an answer WITHOUT the generator ever
 * being invoked. "No generated figure, ever" is only demonstrable if something
 * counts the invocations.
 */
final class FakeGenerator implements Generator
{
    /** @var list<array{system: string, user: string}> */
    private array $calls = [];

    /** @var list<string> */
    private array $queuedResponses = [];

    private bool $shouldTimeOut = false;

    public function __construct(
        private readonly string $defaultResponse = 'This is a fake answer. [1]',
        private readonly string $model = 'fake-generator',
    ) {
    }

    /** Queue an exact response for the next call. */
    public function willReturn(string $text): self
    {
        $this->queuedResponses[] = $text;

        return $this;
    }

    /** Make the next call time out, to exercise the degraded path (INV-8). */
    public function willTimeOut(): self
    {
        $this->shouldTimeOut = true;

        return $this;
    }

    public function generate(string $systemPrompt, string $userContent): GenerationResult
    {
        $this->calls[] = ['system' => $systemPrompt, 'user' => $userContent];

        if ($this->shouldTimeOut) {
            $this->shouldTimeOut = false;
            throw new GenerationTimedOut('Fake generator timed out.');
        }

        $text = array_shift($this->queuedResponses) ?? $this->defaultResponse;

        return new GenerationResult(
            text: $text,
            promptTokens: str_word_count($userContent),
            completionTokens: str_word_count($text),
            cost: 0.0,
            latencyMs: 0,
        );
    }

    public function modelName(): string
    {
        return $this->model;
    }

    public function callCount(): int
    {
        return count($this->calls);
    }

    public function wasCalled(): bool
    {
        return $this->calls !== [];
    }

    /** @return list<array{system: string, user: string}> */
    public function calls(): array
    {
        return $this->calls;
    }
}
