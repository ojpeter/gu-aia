<?php

declare(strict_types=1);

namespace GuAia\Safety;

/**
 * A refusal, with whatever route to a human it was able to name.
 *
 * `handoffMissing` is deliberately part of the value rather than something the
 * caller re-derives: Section 9 makes naming a contact part of what a refusal IS,
 * so a refusal that could not name one is a defect that must travel with the
 * result into the log and the weekly report.
 */
final readonly class RenderedRefusal
{
    /** @param array<string, string> $contactDetails email/telephone/url, whichever exist */
    public function __construct(
        public string $text,
        public ?string $office,
        public array $contactDetails = [],
        public bool $handoffMissing = false,
    ) {
    }
}
