<?php

declare(strict_types=1);

namespace GuAia\Ingestion;

/**
 * Extracts text from a PDF. requirements.md Section 5.2.
 *
 * "Scanned PDFs without a text layer are rejected at ingestion with a report to
 *  the owning office, NOT SILENTLY OCR'd INTO NOISE."
 *
 * READ THIS BEFORE RELYING ON IT.
 *
 * This is a deliberately limited extractor, and its limits are the point. It
 * reads uncompressed and Flate-compressed content streams and pulls the strings
 * out of the text-showing operators. It handles the PDFs a university publishes
 * from a word processor. It does NOT handle every PDF: unusual encodings,
 * embedded subset fonts with custom encodings, and heavily-structured documents
 * can defeat it.
 *
 * The important design decision follows from that: WHEN IT CANNOT READ A
 * DOCUMENT CONFIDENTLY, IT REFUSES, and the document is reported to its owning
 * office rather than indexed. Section 5.2 states that rule for scanned PDFs;
 * this project applies it to every extraction failure, because the reasoning is
 * identical. A gap in the corpus produces a refusal, which is a correct outcome.
 * Approximate text in the corpus produces a confident answer built on nonsense,
 * which is the failure this whole project exists to prevent.
 *
 * The prospectus and the fees structure are exactly the documents most likely to
 * be complex and most costly to get wrong, so the conservative direction is the
 * right one. If real University PDFs turn out to defeat this regularly, the
 * answer is a reviewed parser dependency or a text-based publication process —
 * not loosening the threshold until things stop being rejected.
 */
final class PdfExtractor
{
    /** Below this, treat the document as having no usable text layer. */
    private const MINIMUM_CHARACTERS = 200;

    /** @return list<Block> */
    public function extract(string $pdf): array
    {
        if (!str_starts_with($pdf, '%PDF-')) {
            throw ExtractionFailed::unsupportedFormat('not a PDF');
        }

        $text = $this->readText($pdf);
        $text = trim((string) preg_replace('/[ \t]+/u', ' ', $text));
        $text = (string) preg_replace('/\n{3,}/u', "\n\n", $text);

        if (mb_strlen($text) < self::MINIMUM_CHARACTERS) {
            // The overwhelmingly common cause is a scan. Reported as such,
            // because "publish a text version" is an action the owning office
            // can take, whereas "extraction failed" is not.
            throw ExtractionFailed::noTextLayer();
        }

        return $this->toBlocks($text);
    }

    private function readText(string $pdf): string
    {
        $text = '';

        // Content lives in stream ... endstream pairs. Flate is by far the most
        // common filter; anything else is left alone and simply yields nothing,
        // which surfaces as a rejection rather than as garbage.
        if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $matches) === 0) {
            return '';
        }

        foreach ($matches[1] as $stream) {
            $decoded = @gzuncompress($stream);

            if ($decoded === false) {
                $decoded = @gzinflate($stream);
            }
            if ($decoded === false) {
                // Possibly an uncompressed stream. Only usable if it looks like
                // a content stream rather than an image or font blob.
                $decoded = str_contains($stream, 'BT') ? $stream : false;
            }
            if ($decoded === false) {
                continue;
            }

            $text .= $this->readShownText($decoded);
        }

        return $text;
    }

    /**
     * Pulls the strings out of the text-showing operators: Tj, TJ, ' and ".
     *
     * Deliberately ignores positioning, so column layouts and tables come out as
     * running text. That is a real limitation and it is why table-heavy PDFs are
     * a poor source: the structure the chunker relies on is not recoverable from
     * a page description. Fees tables belong on a web page, or in a curated
     * entry, where the structure survives.
     */
    private function readShownText(string $stream): string
    {
        $out = '';

        if (preg_match_all('/\((?:[^()\\\\]|\\\\.)*\)|\[[^\]]*\]/s', $stream, $tokens) === 0) {
            return '';
        }

        foreach ($tokens[0] as $token) {
            if ($token[0] === '[') {
                // TJ array: strings interleaved with kerning numbers.
                if (preg_match_all('/\((?:[^()\\\\]|\\\\.)*\)/s', $token, $parts) > 0) {
                    foreach ($parts[0] as $part) {
                        $out .= $this->decodeString($part);
                    }
                    $out .= ' ';
                }
                continue;
            }

            $out .= $this->decodeString($token) . ' ';
        }

        return $out . "\n";
    }

    private function decodeString(string $token): string
    {
        $value = substr($token, 1, -1);

        $value = str_replace(
            ['\\n', '\\r', '\\t', '\\(', '\\)', '\\\\'],
            ["\n", "\r", "\t", '(', ')', '\\'],
            $value
        );

        // Octal escapes.
        $value = (string) preg_replace_callback(
            '/\\\\([0-7]{1,3})/',
            static fn (array $m): string => chr((int) octdec($m[1])),
            $value
        );

        return $value;
    }

    /**
     * @return list<Block>
     *
     * A PDF carries no reliable heading structure once flattened to shown text,
     * so everything becomes paragraphs. Page numbers are not recovered either.
     * Both are stated rather than approximated: a heading path invented by
     * guessing at font sizes would put confident structure into the corpus that
     * the document does not actually have.
     */
    private function toBlocks(string $text): array
    {
        $blocks = [];

        foreach (preg_split('/\n{2,}/u', $text) ?: [] as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph !== '') {
                $blocks[] = new Block(Block::PARAGRAPH, $paragraph);
            }
        }

        return $blocks;
    }
}
