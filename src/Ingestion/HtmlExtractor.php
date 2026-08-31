<?php

declare(strict_types=1);

namespace GuAia\Ingestion;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Turns a University web page into structural blocks. requirements.md Section 5.3.
 *
 * The chunker's rules are structural — split on headings, never split a fees
 * table — so extraction has to preserve structure rather than flatten the page
 * to text. A `<table>` becomes ONE atomic block with its `<caption>` attached,
 * which is what makes "never split a fees table" possible downstream.
 *
 * WHAT IS DROPPED, AND WHY IT MATTERS MORE THAN IT SOUNDS
 *
 * Navigation, headers, footers, sidebars and cookie banners are removed before
 * anything else. Not for tidiness: those elements repeat on every page of the
 * site, so leaving them in would give every chunk the same few hundred words in
 * common. Retrieval would then rank on boilerplate, the top result for most
 * queries would be whichever page had the most navigation, and the vector
 * rerank would be comparing menus. A corpus of a few thousand chunks that all
 * share a footer is a corpus with far less signal than its size suggests.
 */
final class HtmlExtractor
{
    /** Elements removed entirely, with their contents. */
    private const STRIP = [
        'script', 'style', 'noscript', 'nav', 'header', 'footer', 'aside',
        'form', 'button', 'iframe', 'svg', 'template',
    ];

    /**
     * Containers that are site furniture wherever they appear. Matched on role,
     * id and class, because University sites rarely use semantic elements
     * consistently.
     */
    private const FURNITURE_PATTERN = '/(^|[\s_-])(nav|navigation|menu|breadcrumb|sidebar|footer|header|banner|cookie|consent|skip|social|share|widget)([\s_-]|$)/i';

    /** @return list<Block> */
    public function extract(string $html): array
    {
        if (trim($html) === '') {
            throw ExtractionFailed::tooLittleText(0);
        }

        $document = new DOMDocument();

        // libxml on real-world HTML is noisy and almost always recoverable;
        // the errors are suppressed rather than ignored, then cleared so they
        // do not leak into an unrelated later parse.
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($document);

        $this->removeFurniture($xpath);

        $root = $document->getElementsByTagName('body')->item(0) ?? $document->documentElement;
        if (!$root instanceof DOMNode) {
            throw ExtractionFailed::tooLittleText(0);
        }

        $blocks = [];
        $this->walk($root, $blocks);

        $characters = 0;
        foreach ($blocks as $block) {
            $characters += mb_strlen($block->text);
        }

        // A page that yields almost nothing is a page that failed to extract,
        // not a page with a short answer on it. Indexing the fragments would put
        // a citable but meaningless chunk into the corpus.
        if ($characters < 120) {
            throw ExtractionFailed::tooLittleText($characters);
        }

        return $blocks;
    }

    private function removeFurniture(DOMXPath $xpath): void
    {
        foreach (self::STRIP as $tag) {
            $nodes = $xpath->query('//' . $tag);
            foreach ($nodes === false ? [] : iterator_to_array($nodes) as $node) {
                // DOMNodeList can yield DOMNameSpaceNode, which is not removable.
                if ($node instanceof DOMElement) {
                    $node->parentNode?->removeChild($node);
                }
            }
        }

        $candidates = $xpath->query('//*[@id or @class or @role]');
        foreach ($candidates === false ? [] : iterator_to_array($candidates) as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            $signature = $node->getAttribute('id') . ' '
                . $node->getAttribute('class') . ' '
                . $node->getAttribute('role');

            if (preg_match(self::FURNITURE_PATTERN, $signature) === 1) {
                $node->parentNode?->removeChild($node);
            }
        }
    }

    /** @param list<Block> $blocks */
    private function walk(DOMNode $node, array &$blocks): void
    {
        foreach ($node->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->nodeName);

            if (preg_match('/^h([1-6])$/', $tag, $m) === 1) {
                $text = $this->text($child);
                if ($text !== '') {
                    $blocks[] = new Block(Block::HEADING, $text, (int) $m[1]);
                }
                continue;
            }

            if ($tag === 'table') {
                // One atomic block, caption attached. This is the line that
                // makes "never split a fees table" achievable.
                $caption = '';
                $captions = $child->getElementsByTagName('caption');
                if ($captions->length > 0) {
                    $caption = $this->text($captions->item(0));
                }

                $text = $this->tableText($child);
                if ($text !== '') {
                    $blocks[] = new Block(
                        type: Block::TABLE,
                        text: $text,
                        caption: $caption === '' ? null : $caption,
                        atomic: true,
                    );
                }
                continue;
            }

            if ($tag === 'ul' || $tag === 'ol' || $tag === 'dl') {
                $text = $this->listText($child);
                if ($text !== '') {
                    // Entry-requirement lists must not be split either, and an
                    // extractor cannot tell which list is which. Treating every
                    // list as atomic errs toward keeping related items together,
                    // which is the safe direction: half a requirements list reads
                    // as a complete one.
                    $blocks[] = new Block(type: Block::LIST, text: $text, atomic: true);
                }
                continue;
            }

            if (in_array($tag, ['p', 'blockquote', 'pre'], true)) {
                $text = $this->text($child);
                if ($text !== '') {
                    $blocks[] = new Block(Block::PARAGRAPH, $text);
                }
                continue;
            }

            $this->walk($child, $blocks);
        }
    }

    private function tableText(DOMElement $table): string
    {
        $lines = [];

        foreach ($table->getElementsByTagName('tr') as $row) {
            $cells = [];
            foreach ($row->childNodes as $cell) {
                if ($cell instanceof DOMElement && in_array(strtolower($cell->nodeName), ['td', 'th'], true)) {
                    $cells[] = $this->text($cell);
                }
            }
            $line = trim(implode(' | ', $cells));
            if ($line !== '' && trim($line, '| ') !== '') {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }

    private function listText(DOMElement $list): string
    {
        $items = [];

        foreach ($list->getElementsByTagName('li') as $item) {
            $text = $this->text($item);
            if ($text !== '') {
                $items[] = '- ' . $text;
            }
        }

        foreach ($list->getElementsByTagName('dt') as $term) {
            $text = $this->text($term);
            if ($text !== '') {
                $items[] = '- ' . $text;
            }
        }

        return implode("\n", $items);
    }

    private function text(?DOMNode $node): string
    {
        if ($node === null) {
            return '';
        }

        $text = (string) preg_replace('/\s+/u', ' ', $node->textContent);

        return trim($text);
    }
}
