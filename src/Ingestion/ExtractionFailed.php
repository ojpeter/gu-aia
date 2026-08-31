<?php

declare(strict_types=1);

namespace GuAia\Ingestion;

use RuntimeException;

/**
 * A document could not be extracted, and must NOT be indexed.
 *
 * requirements.md Section 5.2: "Scanned PDFs without a text layer are rejected
 * at ingestion with a report to the owning office, NOT SILENTLY OCR'd INTO
 * NOISE."
 *
 * The principle generalises past scanned PDFs, and this project applies it that
 * way: anything the extractor cannot read confidently is rejected with a reason
 * the owning office can act on, rather than ingested as approximate text.
 * Garbage in the corpus is worse than a gap in it — a gap produces a refusal,
 * which is a correct outcome, while garbage produces a confident answer built on
 * nonsense, which is the failure this whole project is written against.
 */
final class ExtractionFailed extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function noTextLayer(): self
    {
        return new self(
            'no_text_layer',
            'This PDF has no extractable text layer. It is probably a scan. '
            . 'Publish a text-based version, or add the facts as curated entries.'
        );
    }

    public static function tooLittleText(int $characters): self
    {
        return new self(
            'too_little_text',
            sprintf(
                'Only %d characters of text could be extracted, which is too little to be '
                . 'the real content. Rejected rather than indexed as fragments.',
                $characters
            )
        );
    }

    public static function unsupportedFormat(string $contentType): self
    {
        return new self('unsupported_format', sprintf('Cannot extract text from "%s".', $contentType));
    }
}
