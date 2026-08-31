<?php

declare(strict_types=1);

namespace GuAia\Logging;

use PDO;
use RuntimeException;

/**
 * Retention expiry, by redaction. INV-12 and the DPPA 2019.
 *
 * "Logs are personal data. Retention period stated in configuration and
 *  published in the privacy notice; access restricted; NO HARD DELETION
 *  (INV-12), REDACTION ONLY."
 *
 * WHAT REDACTION MEANS HERE, AND WHY IT IS NOT DELETION IN DISGUISE
 *
 * The row survives. Its identifying content does not. `query_text`, the answer,
 * the normalised question and any free-text feedback are blanked; the
 * correlation ID, timings, mode, category, refusal reason, token counts and
 * cost remain, because those are what let a past interaction be reconstructed
 * well enough to answer a complaint — "on this date the assistant refused this
 * category of question and cited these sources" — without keeping the words a
 * particular person typed.
 *
 * That is a deliberate trade and it must be disclosed, not assumed: a data
 * subject asking for erasure gets redaction, and docs/data-protection.md
 * Section 4 says the privacy notice has to say so plainly. A design choice with
 * a stated reason is defensible; the same choice undisclosed is not.
 *
 * IT REFUSES TO RUN WITHOUT A CONFIGURED PERIOD
 *
 * `LOG_RETENTION_DAYS` is unset until the University's data protection function
 * sets it (Section 18, open question 5). Guessing a period would be worse than
 * failing: too short destroys the record a complaint needs, too long is an
 * unlawful retention nobody chose. So the sweeper throws, loudly, and a
 * scheduled run that starts failing is exactly the alarm this gap deserves now
 * that the log writer exists.
 */
final class RetentionSweeper
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ?int $retentionDays,
        private readonly ?int $technicalRetentionDays = null,
    ) {
    }

    /**
     * @return array{interactions: int, unanswered: int, feedback: int, technical: int}
     */
    public function sweep(?\DateTimeImmutable $now = null): array
    {
        if ($this->retentionDays === null || $this->retentionDays <= 0) {
            throw new RuntimeException(
                'LOG_RETENTION_DAYS is not set. Refusing to guess a retention period: too short '
                . 'destroys the record a complaint needs, too long is a retention nobody authorised. '
                . 'Set it with the University data protection function (requirements.md Section 18, '
                . 'open question 5).'
            );
        }

        $now ??= new \DateTimeImmutable();
        $cutoff = $now->modify('-' . $this->retentionDays . ' days')->format('Y-m-d H:i:s');

        return [
            'interactions' => $this->redactInteractions($cutoff),
            'unanswered' => $this->redactUnansweredQuestions($cutoff),
            'feedback' => $this->redactFeedback($cutoff),
            'technical' => $this->redactTechnicalIdentifiers($now),
        ];
    }

    /**
     * Blanks the text a person wrote and the text written back to them, and
     * stamps redacted_at so a later sweep skips the row and so the redaction is
     * itself auditable.
     */
    private function redactInteractions(string $cutoff): int
    {
        $statement = $this->pdo->prepare(
            "UPDATE interactions
                SET query_text = '[redacted]',
                    normalised_query = NULL,
                    answer = NULL,
                    redacted_at = NOW()
              WHERE created_at < :cutoff
                AND redacted_at IS NULL"
        );
        $statement->execute(['cutoff' => $cutoff]);

        return $statement->rowCount();
    }

    private function redactUnansweredQuestions(string $cutoff): int
    {
        $statement = $this->pdo->prepare(
            "UPDATE unanswered_questions
                SET normalised_question = '',
                    redacted_at = NOW()
              WHERE created_at < :cutoff
                AND redacted_at IS NULL"
        );
        $statement->execute(['cutoff' => $cutoff]);

        return $statement->rowCount();
    }

    private function redactFeedback(string $cutoff): int
    {
        // The rating survives; the free-text comment does not. An aggregate of
        // thumbs is not personal data, and losing it would destroy the only
        // long-run quality signal this system has.
        $statement = $this->pdo->prepare(
            'UPDATE feedback
                SET comment = NULL,
                    redacted_at = NOW()
              WHERE created_at < :cutoff
                AND redacted_at IS NULL'
        );
        $statement->execute(['cutoff' => $cutoff]);

        return $statement->rowCount();
    }

    /**
     * DF-2 has its own, shorter period: the hashed IP and session are kept only
     * as long as abuse investigation needs them, which is far less than the
     * interaction record. Clearing them early is data minimisation, and the
     * interaction stays reconstructible without them.
     */
    private function redactTechnicalIdentifiers(\DateTimeImmutable $now): int
    {
        if ($this->technicalRetentionDays === null || $this->technicalRetentionDays <= 0) {
            return 0;
        }

        $cutoff = $now->modify('-' . $this->technicalRetentionDays . ' days')->format('Y-m-d H:i:s');

        $statement = $this->pdo->prepare(
            'UPDATE interactions
                SET ip_hash = NULL, session_id = NULL
              WHERE created_at < :cutoff
                AND (ip_hash IS NOT NULL OR session_id IS NOT NULL)'
        );
        $statement->execute(['cutoff' => $cutoff]);

        return $statement->rowCount();
    }
}
