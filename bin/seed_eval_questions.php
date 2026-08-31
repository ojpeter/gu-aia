<?php

/**
 * Seeds the golden question set from config/eval/golden_set.php.
 *
 * requirements.md Section 12. Idempotent, and deliberately conservative:
 *
 *   - a question already present is left alone, never overwritten;
 *   - a row whose `source` is 'office' is never touched at all, because the
 *     Registry and Communications own the set once they start editing it, and a
 *     seeder that silently reverts their edits would be worse than no seeder.
 *
 * Runs under the migration account, which is the only one with INSERT on the
 * eval tables (db/accounts.sql).
 *
 *   php bin/seed_eval_questions.php            insert anything missing
 *   php bin/seed_eval_questions.php --dry-run  report, change nothing
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$dryRun = in_array('--dry-run', $argv, true);

/** @var array<string, string> $env */
$env = (static function (string $path): array {
    if (!is_readable($path)) {
        fwrite(STDERR, "No .env found at {$path}.\n");
        exit(1);
    }
    $out = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $out[trim($k)] = trim(trim($v), "\"'");
    }
    return $out;
})($root . '/.env');

$options = require $root . '/config/pdo_options.php';

try {
    $pdo = new PDO(
        sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            $env['DB_HOST'] ?? 'localhost',
            $env['DB_NAME'] ?? 'gu_aia',
            $env['DB_CHARSET'] ?? 'utf8mb4'
        ),
        $env['DB_MIGRATION_USER'] ?? '',
        $env['DB_MIGRATION_PASS'] ?? '',
        $options
    );
} catch (PDOException $e) {
    fwrite(STDERR, "Cannot connect to the database. Check .env.\n");
    exit(1);
}

/** @var array<string, array{expected_mode: ?string, expected_category_key: ?string, questions: list<string>}> $set */
$set = require $root . '/config/eval/golden_set.php';

$existingStmt = $pdo->query('SELECT question, source FROM eval_questions');
if ($existingStmt === false) {
    fwrite(STDERR, "Could not read eval_questions.\n");
    exit(1);
}

/** @var array<string, string> $existing question => source */
$existing = [];
foreach ($existingStmt->fetchAll() as $row) {
    $existing[(string) $row['question']] = (string) $row['source'];
}

$insert = $pdo->prepare(
    'INSERT INTO eval_questions
        (question, language, expected_mode, must_not_refuse, expected_category_key, suite, source, notes)
     VALUES (:question, :language, :mode, :must_not_refuse, :category, :suite, \'seed\', :notes)'
);

$inserted = 0;
$skipped = 0;
$protected = 0;

foreach ($set as $suite => $block) {
    $mode = $block['expected_mode'];
    $category = $block['expected_category_key'];
    $mustNotRefuse = $mode === null;

    foreach ($block['questions'] as $question) {
        if (isset($existing[$question])) {
            if ($existing[$question] === 'office') {
                $protected++;
            } else {
                $skipped++;
            }
            continue;
        }

        if ($dryRun) {
            printf("would insert [%s] %s\n", $suite, $question);
            $inserted++;
            continue;
        }

        $insert->execute([
            'question' => $question,
            'language' => 'en',
            'mode' => $mode,
            'must_not_refuse' => $mustNotRefuse ? 1 : 0,
            'category' => $category,
            'suite' => $suite,
            'notes' => 'Seeded from config/eval/golden_set.php. Not yet reviewed by an office.',
        ]);
        $inserted++;
    }
}

printf(
    "%s %d question(s). %d already present, %d left alone because an office owns them.\n",
    $dryRun ? 'Would insert' : 'Inserted',
    $inserted,
    $skipped,
    $protected
);

if (!$dryRun) {
    $total = $pdo->query('SELECT COUNT(*) FROM eval_questions');
    $count = $total === false ? 0 : (int) $total->fetchColumn();
    printf("Golden set now holds %d questions.\n", $count);

    $config = require $root . '/config/eval.php';
    $minimum = (int) $config['required_composition']['total_minimum'];
    if ($count < $minimum) {
        printf(
            "\nWARNING: Section 12 requires at least %d questions. The set is %d short, and the\n"
            . "shortfall is the part only the Registry and Communications can write.\n",
            $minimum,
            $minimum - $count
        );
    }
}

exit(0);
