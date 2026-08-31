<?php

/**
 * Retention sweep. INV-12, DPPA 2019, docs/data-protection.md.
 *
 * Redacts interaction text, unanswered-question text and feedback comments past
 * the configured retention period. Nothing is deleted: the rows survive with
 * their identifying content blanked, so a past answer stays reconstructible for
 * a complaint.
 *
 * Intended to run daily from the scheduler.
 *
 *   php bin/redact_expired.php            redact
 *   php bin/redact_expired.php --dry-run  report what would be redacted
 *
 * EXITS NON-ZERO IF LOG_RETENTION_DAYS IS UNSET, and that is the point. The log
 * writer exists now, so the day the widget is exposed to the public is the day
 * personal data starts accumulating. A scheduled job that fails loudly every
 * night until somebody sets a period is the correct alarm; a job that silently
 * did nothing would let the gap sit unnoticed for a year.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use GuAia\Logging\RetentionSweeper;

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

$retentionDays = ($env['LOG_RETENTION_DAYS'] ?? '') === '' ? null : (int) $env['LOG_RETENTION_DAYS'];
$technicalDays = ($env['TECHNICAL_LOG_RETENTION_DAYS'] ?? '') === ''
    ? null
    : (int) $env['TECHNICAL_LOG_RETENTION_DAYS'];

if ($retentionDays === null) {
    fwrite(STDERR, "LOG_RETENTION_DAYS is not set.\n\n");
    fwrite(STDERR, "Nothing was redacted, and nothing will be until a period is agreed with the\n");
    fwrite(STDERR, "University's data protection function (requirements.md Section 18, open\n");
    fwrite(STDERR, "question 5) and published in the privacy notice.\n\n");
    fwrite(STDERR, "This is a deliberate failure, not a crash. Guessing a period would be worse:\n");
    fwrite(STDERR, "too short destroys the record a complaint needs, too long is a retention\n");
    fwrite(STDERR, "nobody authorised.\n");
    exit(1);
}

try {
    $options = require $root . '/config/pdo_options.php';
    $pdo = new PDO(
        sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            $env['DB_HOST'] ?? 'localhost',
            $env['DB_NAME'] ?? 'gu_aia',
            $env['DB_CHARSET'] ?? 'utf8mb4'
        ),
        $env['DB_APP_USER'] ?? '',
        $env['DB_APP_PASS'] ?? '',
        $options
    );
} catch (PDOException) {
    fwrite(STDERR, "Cannot connect to the database. Check .env.\n");
    exit(1);
}

$sweeper = new RetentionSweeper($pdo, $retentionDays, $technicalDays);

if ($dryRun) {
    $pdo->beginTransaction();
}

try {
    $counts = $sweeper->sweep();
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

if ($dryRun && $pdo->inTransaction()) {
    $pdo->rollBack();
}

printf(
    "%s retention sweep (%d days, technical %s days):\n",
    $dryRun ? 'DRY RUN' : 'Completed',
    $retentionDays,
    $technicalDays === null ? 'not set' : (string) $technicalDays
);
printf("  interactions redacted: %d\n", $counts['interactions']);
printf("  unanswered questions redacted: %d\n", $counts['unanswered']);
printf("  feedback comments redacted: %d\n", $counts['feedback']);
printf("  technical identifiers cleared: %d\n", $counts['technical']);
echo "\nNothing was deleted. Rows survive with identifying content blanked (INV-12).\n";

exit(0);
