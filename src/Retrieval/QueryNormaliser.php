<?php

declare(strict_types=1);

namespace GuAia\Retrieval;

/**
 * Query normalisation and BOOLEAN MODE safety. requirements.md Section 6.
 *
 *   normalised := normalise(query)   # casefold, strip, expand known abbreviations
 *
 * THE PART THAT MATTERS MOST
 *
 * CLAUDE.md Rule 3 calls the `FULLTEXT ... AGAINST` clause "the single
 * highest-risk query in the system. Bind it, and sanitise BOOLEAN MODE operators
 * separately."
 *
 * The second half is the half people skip. Binding the parameter stops SQL
 * injection completely — the string can never become SQL. It does NOT stop the
 * string from being interpreted, because MySQL parses the bound value itself as
 * a boolean search expression. A user typing
 *
 *     -fees tuition
 *
 * has bound a perfectly safe parameter that instructs the engine to EXCLUDE
 * every document containing "fees" — from a question that is obviously about
 * fees. `*` can turn a one-word query into a full-table scan; `"` opens a phrase
 * that swallows the rest; `<`, `>` and `~` silently reweight; unbalanced `(` is a
 * syntax error that surfaces as a 500.
 *
 * None of that is injection. All of it is a stranger steering retrieval. So every
 * operator character is stripped, and the terms are re-joined by the system, not
 * by the user.
 *
 * Abbreviation expansion is configuration seeded with the University's own
 * vocabulary (Section 6) and is deliberately empty here until Communications and
 * the Registry supply it: inventing what "FoS" or "UCER" stands for would put a
 * guess into the retrieval path where it is hardest to notice.
 */
final class QueryNormaliser
{
    /**
     * Every character MySQL/MariaDB treats as a BOOLEAN MODE operator.
     * Removed, never escaped: there is no legitimate reason for a member of the
     * public to be writing boolean search syntax into a question box.
     */
    private const BOOLEAN_OPERATORS = '/[+\-><()~*"@]+/u';

    /** @param array<string, list<string>> $abbreviations from config/retrieval.php */
    public function __construct(
        private readonly array $abbreviations = [],
        private readonly string $codePattern = '/\b[A-Z]{2,4}\s?\d{3,4}\b/',
    ) {
    }

    /**
     * The safe form for MATCH ... AGAINST (... IN BOOLEAN MODE), still bound as a
     * parameter by the caller.
     */
    public function forFullText(string $query): string
    {
        $terms = $this->terms($query);
        if ($terms === []) {
            return '';
        }

        // Joined with spaces, which BOOLEAN MODE treats as optional terms. No
        // '+' is added: requiring every term would make a long question return
        // nothing, and Section 6 wants a candidate pool of ~200 to rerank, not a
        // precise match.
        return implode(' ', $terms);
    }

    /**
     * Casefolded, operator-free terms with abbreviations expanded.
     *
     * @return list<string>
     */
    public function terms(string $query): array
    {
        $text = mb_strtolower(trim($query), 'UTF-8');

        // Strip boolean operators BEFORE splitting, so "-fees" becomes "fees"
        // rather than an empty term.
        $text = (string) preg_replace(self::BOOLEAN_OPERATORS, ' ', $text);

        // Anything that is not a letter or digit is a separator. This also
        // removes the apostrophes and quotes that would otherwise survive.
        $text = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($text === '') {
            return [];
        }

        $terms = explode(' ', $text);

        // Expand known abbreviations, keeping the original term as well — the
        // user may have typed the form that actually appears in the document.
        $expanded = [];
        foreach ($terms as $term) {
            $expanded[$term] = true;
            foreach ($this->abbreviations[$term] ?? [] as $alternative) {
                foreach (explode(' ', mb_strtolower($alternative, 'UTF-8')) as $word) {
                    if ($word !== '') {
                        $expanded[$word] = true;
                    }
                }
            }
        }

        // MySQL's default minimum token size is 3; shorter terms are ignored by
        // the index anyway, and passing them along only lengthens the string.
        $result = array_values(array_filter(
            array_keys($expanded),
            static fn (string $t): bool => mb_strlen($t, 'UTF-8') >= 3
        ));

        /** @var list<string> $result */
        return $result;
    }

    /**
     * Programme and course codes, matched exactly and boosted: "a user typing a
     * code knows what they want" (Section 6).
     *
     * Read from the ORIGINAL query, before casefolding, because the pattern is
     * case-sensitive by design — "BSc" is a code, "bsc" in the middle of a
     * sentence usually is not.
     *
     * @return list<string>
     */
    public function codes(string $query): array
    {
        if (preg_match_all($this->codePattern, $query, $matches) === 0) {
            return [];
        }

        $codes = [];
        foreach ($matches[0] as $code) {
            // Normalise internal spacing so "CSC 101" and "CSC101" are one code.
            $codes[(string) preg_replace('/\s+/', '', strtoupper($code))] = true;
        }

        /** @var list<string> $list */
        $list = array_keys($codes);

        return $list;
    }
}
