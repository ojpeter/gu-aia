<?php

declare(strict_types=1);

namespace GuAia\Logging;

use PDO;

/**
 * The weekly Unanswered Questions Report. requirements.md Section 13.
 *
 * "From this, the system produces a weekly Unanswered Questions Report, ranked by
 *  frequency, grouped by category, and distributed to the offices that own the
 *  relevant content.
 *
 *  TREAT THIS REPORT AS A PRIMARY DELIVERABLE. It is a ranked list of what the
 *  public comes to the University's website looking for and cannot find, and it
 *  is likely to be worth more to the institution than the assistant itself."
 *
 * That last sentence is why this class is not an afterthought bolted to the
 * admin console. The report is the product of the refusals, and refusals are the
 * outcome this system is designed to produce whenever the corpus does not
 * support an answer. A well-behaved assistant generates this report constantly;
 * a badly-behaved one generates confident answers instead and produces nothing
 * anyone can act on.
 *
 * Section 19 says the same thing from the other side: where the website is
 * stale or contradictory, the assistant "will surface that at scale, in public".
 * This report is how that surfacing reaches the people who can fix it, before
 * the public does the surfacing for them.
 */
final class UnansweredQuestionsReport
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Ranked by frequency, most-asked first.
     *
     * Redacted rows are excluded: once retention has expired and the question
     * text has been redacted (INV-12 keeps the row, blanks the content), it can
     * no longer be reported on, and reporting an empty string as a top question
     * would be worse than omitting it.
     *
     * @return list<array{question: string, occurrences: int, category_key: ?string, reasons: string, first_seen: string, last_seen: string}>
     */
    public function forWeek(string $weekStart, string $weekEnd, int $limit = 50): array
    {
        $limit = max(1, min(500, $limit));

        $statement = $this->pdo->prepare(
            "SELECT normalised_question           AS question,
                    COUNT(*)                      AS occurrences,
                    MIN(category_key)             AS category_key,
                    GROUP_CONCAT(DISTINCT refusal_reason ORDER BY refusal_reason SEPARATOR ', ') AS reasons,
                    MIN(created_at)               AS first_seen,
                    MAX(created_at)               AS last_seen
               FROM unanswered_questions
              WHERE created_at >= :week_start
                AND created_at < :week_end
                AND redacted_at IS NULL
                AND normalised_question <> ''
              GROUP BY normalised_question
              ORDER BY occurrences DESC, last_seen DESC
              LIMIT {$limit}"
        );

        $statement->execute(['week_start' => $weekStart, 'week_end' => $weekEnd]);

        /** @var list<array{question: string, occurrences: int, category_key: ?string, reasons: string, first_seen: string, last_seen: string}> $rows */
        $rows = $statement->fetchAll();

        return $rows;
    }

    /**
     * The same period grouped by category, so each office receives the part it
     * owns rather than the whole list.
     *
     * @return array<string, int> category key => count; the empty key holds uncategorised
     */
    public function byCategory(string $weekStart, string $weekEnd): array
    {
        $statement = $this->pdo->prepare(
            'SELECT COALESCE(category_key, \'\') AS category_key, COUNT(*) AS occurrences
               FROM unanswered_questions
              WHERE created_at >= :week_start
                AND created_at < :week_end
                AND redacted_at IS NULL
              GROUP BY COALESCE(category_key, \'\')
              ORDER BY occurrences DESC'
        );
        $statement->execute(['week_start' => $weekStart, 'week_end' => $weekEnd]);

        $counts = [];
        foreach ($statement->fetchAll() as $row) {
            $counts[(string) $row['category_key']] = (int) $row['occurrences'];
        }

        return $counts;
    }

    /**
     * Why the assistant refused, ranked.
     *
     * This is the operational half of the report and it is worth reading
     * separately: a week dominated by `below_threshold` says the corpus is thin
     * or the threshold is wrong, while a week dominated by `individual_outcome`
     * says the public is asking a question the assistant will never answer and
     * the Registry may want a clearer page about it. Those two need completely
     * different responses, and a single ranked list of questions hides the
     * difference.
     *
     * @return array<string, int>
     */
    public function byRefusalReason(string $weekStart, string $weekEnd): array
    {
        $statement = $this->pdo->prepare(
            'SELECT refusal_reason, COUNT(*) AS occurrences
               FROM unanswered_questions
              WHERE created_at >= :week_start
                AND created_at < :week_end
                AND redacted_at IS NULL
              GROUP BY refusal_reason
              ORDER BY occurrences DESC'
        );
        $statement->execute(['week_start' => $weekStart, 'week_end' => $weekEnd]);

        $counts = [];
        foreach ($statement->fetchAll() as $row) {
            $counts[(string) $row['refusal_reason']] = (int) $row['occurrences'];
        }

        return $counts;
    }
}
