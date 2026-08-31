<?php

/**
 * GU-AIA migration runner.
 *
 * Forward-only. Applies every numbered .sql file in db/migrations/ that has not
 * yet been recorded in the schema_migrations ledger, in filename order, each in
 * its own transaction.
 *
 * There are no down-migrations by design (requirements.md Section 3), and an
 * already-applied migration is never edited — write a new one instead.
 *
 * Usage:
 *   php bin/migrate.php            apply pending migrations
 *   php bin/migrate.php --status   list applied and pending, apply nothing
 *
 * Runs under its own least-privilege database account (CLAUDE.md Rule 3): the
 * migration account is the only one with DDL rights, and is used here and
 * nowhere else.
 */

declare(strict_types=1);

const MIGRATIONS_DIR = __DIR__ . '/../db/migrations';

$env = load_env(__DIR__ . '/../.env');
$statusOnly = in_array('--status', $argv, true);

try {
    $pdo = connect($env);
} catch (PDOException $e) {
    fwrite(STDERR, "Cannot connect to the database. Check .env.\n");
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

ensure_ledger($pdo);

// PDO::query() is typed PDOStatement|false. With ERRMODE_EXCEPTION it throws
// instead of returning false, but the signature does not say so, and chaining
// straight off it would be an unchecked call. Kept explicit rather than
// suppressed.
$appliedStmt = $pdo->query('SELECT filename FROM schema_migrations ORDER BY filename');
if ($appliedStmt === false) {
    fwrite(STDERR, "Could not read the schema_migrations ledger.\n");
    exit(1);
}

/** @var list<string> $applied */
$applied = $appliedStmt->fetchAll(PDO::FETCH_COLUMN);

$all = glob(MIGRATIONS_DIR . '/*.sql') ?: [];
sort($all, SORT_STRING);

$pending = array_values(array_filter(
    $all,
    static fn (string $path): bool => !in_array(basename($path), $applied, true)
));

if ($statusOnly) {
    printf("Applied: %d\n", count($applied));
    foreach ($applied as $filename) {
        printf("  [x] %s\n", $filename);
    }
    printf("Pending: %d\n", count($pending));
    foreach ($pending as $path) {
        printf("  [ ] %s\n", basename($path));
    }
    exit(0);
}

if ($pending === []) {
    echo "Nothing to apply. Schema is up to date.\n";
    exit(0);
}

foreach ($pending as $path) {
    $filename = basename($path);
    $sql = file_get_contents($path);

    if ($sql === false || trim($sql) === '') {
        fwrite(STDERR, "Refusing to apply empty or unreadable migration: {$filename}\n");
        exit(1);
    }

    // INV-12: nothing is deleted. A migration that removes rows would make a past
    // answer unreconstructible, so the runner refuses it rather than trusting review.
    if (preg_match('/\bDELETE\s+FROM\b|\bTRUNCATE\b/i', $sql) === 1) {
        fwrite(STDERR, "Refusing {$filename}: contains DELETE/TRUNCATE, which INV-12 forbids.\n");
        exit(1);
    }

    // MySQL and MariaDB commit implicitly on every DDL statement, so a
    // migration that creates or alters a table CANNOT be wrapped in a
    // transaction — the first CREATE ends it, and the later commit() then
    // throws "There is no active transaction". Data-only migrations (seeds,
    // backfills) have no such restriction and do get real atomicity.
    //
    // The honest consequence, which is why it is stated here and in
    // db/migrations/README.md rather than glossed over: a DDL migration that
    // fails halfway leaves partial state behind, and the remedy is a new
    // forward migration, not a rollback.
    $isDdl = preg_match('/\b(CREATE|ALTER|DROP|RENAME)\b/i', $sql) === 1;

    printf("Applying %-34s %-6s ... ", $filename, $isDdl ? '[ddl]' : '[data]');

    try {
        if (!$isDdl) {
            $pdo->beginTransaction();
        }

        $pdo->exec($sql);

        $stmt = $pdo->prepare('INSERT INTO schema_migrations (filename, applied_at) VALUES (?, NOW())');
        $stmt->execute([$filename]);

        if ($pdo->inTransaction()) {
            $pdo->commit();
        }

        echo "ok\n";
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo "FAILED\n";
        fwrite(STDERR, $e->getMessage() . "\n");
        if ($isDdl) {
            fwrite(STDERR, "This migration contains DDL, which MySQL/MariaDB cannot roll back.\n");
            fwrite(STDERR, "Part of {$filename} may have been applied. Inspect the schema before retrying;\n");
            fwrite(STDERR, "the fix is a new forward migration, never an edit to this one.\n");
        }
        fwrite(STDERR, "Stopped. No further migrations applied.\n");
        exit(1);
    }
}

printf("Done. %d migration(s) applied.\n", count($pending));
exit(0);

/**
 * Minimal .env reader. No dependency, matching the no-Composer-dependencies
 * posture of the sibling projects.
 *
 * @return array<string, string>
 */
function load_env(string $path): array
{
    if (!is_readable($path)) {
        fwrite(STDERR, "No .env found at {$path}. Copy .env.example to .env and fill it in.\n");
        exit(1);
    }

    $env = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $env[trim($key)] = trim(trim($value), "\"'");
    }

    return $env;
}

/**
 * @param array<string, string> $env
 */
function connect(array $env): PDO
{
    $host = $env['DB_HOST'] ?? 'localhost';
    $name = $env['DB_NAME'] ?? 'gu_aia';
    $charset = $env['DB_CHARSET'] ?? 'utf8mb4';

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $host, $name, $charset);

    // Shared options, including the strict sql_mode that stops the server
    // silently substituting '0000-00-00' for a missing NOT NULL date — see
    // config/pdo_options.php and 0007_enforce_review_dates.sql.
    $options = require __DIR__ . '/../config/pdo_options.php';

    return new PDO(
        $dsn,
        $env['DB_MIGRATION_USER'] ?? '',
        $env['DB_MIGRATION_PASS'] ?? '',
        $options
    );
}

function ensure_ledger(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            filename VARCHAR(255) NOT NULL PRIMARY KEY,
            applied_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}
