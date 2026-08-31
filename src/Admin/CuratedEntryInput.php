<?php

declare(strict_types=1);

namespace GuAia\Admin;

use PDO;

/**
 * A validated curated entry, ready to write. CLAUDE.md Rule 4.
 *
 * "Every form submission goes through one shared validator... No scattered
 *  manual `if` checks. Enforce validation SERVER-SIDE regardless of any
 *  client-side JS validation."
 *
 * A value of this class cannot be constructed from invalid input: `fromRequest`
 * either returns one or returns a list of errors. That is the point of making it
 * a type rather than passing an array around — the writer cannot be handed
 * something unvalidated, because there is no way to express it.
 *
 * ENUM-LIKE FIELDS ARE CHECKED AGAINST THE ACTUAL TABLES, not against a
 * hard-coded array (Rule 4). A category or office that exists in the form but
 * not in the database would fail at the foreign key with a 500; checking first
 * turns that into a field error a person can act on.
 */
final readonly class CuratedEntryInput
{
    private function __construct(
        public string $question,
        public string $answer,
        public ?string $categoryKey,
        public int $owningOfficeId,
        public string $reviewedAt,
        public int $reviewIntervalDays,
    ) {
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array{0: self|null, 1: array<string, string>} the input, or field errors
     */
    public static function fromRequest(PDO $pdo, array $request): array
    {
        $errors = [];

        $question = trim((string) ($request['question'] ?? ''));
        $answer = trim((string) ($request['answer'] ?? ''));
        $categoryKey = trim((string) ($request['category_key'] ?? ''));
        $officeId = (int) ($request['owning_office_id'] ?? 0);
        $reviewedAt = trim((string) ($request['reviewed_at'] ?? ''));
        $interval = (int) ($request['review_interval_days'] ?? 0);

        if ($question === '') {
            $errors['question'] = 'A question is required.';
        } elseif (mb_strlen($question) > 500) {
            $errors['question'] = 'Keep the question under 500 characters.';
        }

        if ($answer === '') {
            $errors['answer'] = 'An answer is required.';
        } elseif (mb_strlen($answer) > 5000) {
            $errors['answer'] = 'Keep the answer under 5000 characters.';
        }

        // INV-11. Not optional, and not defaulted: a defaulted review date is a
        // fabricated review date, and the schema refuses one anyway.
        if ($reviewedAt === '') {
            $errors['reviewed_at'] = 'A review date is required.';
        } elseif (!self::isRealDate($reviewedAt)) {
            $errors['reviewed_at'] = 'Use a real date, as YYYY-MM-DD.';
        } elseif ($reviewedAt > date('Y-m-d')) {
            // Rule 4: "valid format + logical range". A future review date would
            // keep the staleness sweep permanently satisfied.
            $errors['reviewed_at'] = 'A review date cannot be in the future.';
        } elseif ($reviewedAt < '2000-01-01') {
            $errors['reviewed_at'] = 'That date is too far in the past to be a real review.';
        }

        if ($interval < 1) {
            $errors['review_interval_days'] = 'A review interval of at least one day is required.';
        } elseif ($interval > 1825) {
            $errors['review_interval_days'] = 'Five years is the longest review interval allowed.';
        }

        if ($officeId < 1) {
            $errors['owning_office_id'] = 'Choose the office that owns this answer.';
        } elseif (!self::exists($pdo, 'SELECT 1 FROM offices WHERE id = ? AND is_active = 1', [$officeId])) {
            $errors['owning_office_id'] = 'That office does not exist.';
        }

        $categoryExists = $categoryKey === ''
            || self::exists($pdo, 'SELECT 1 FROM categories WHERE category_key = ?', [$categoryKey]);

        if (!$categoryExists) {
            $errors['category_key'] = 'That category does not exist.';
        }

        if ($errors !== []) {
            return [null, $errors];
        }

        return [
            new self(
                question: $question,
                answer: $answer,
                categoryKey: $categoryKey === '' ? null : $categoryKey,
                owningOfficeId: $officeId,
                reviewedAt: $reviewedAt,
                reviewIntervalDays: $interval,
            ),
            [],
        ];
    }

    private static function isRealDate(string $value): bool
    {
        // Not a regex: 2026-02-30 matches any sensible pattern and is not a date.
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }

    /** @param list<mixed> $parameters */
    private static function exists(PDO $pdo, string $sql, array $parameters): bool
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetchColumn() !== false;
    }
}
