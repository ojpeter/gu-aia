<?php

declare(strict_types=1);

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

$applied = $pdo->query('SELECT filename FROM schema_migrations ORDER BY filename')
    ->fetchAll(PDO::FETCH_COLUMN);

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

    printf("Applying %s ... ", $filename);

    try {
        $pdo->beginTransaction();
        $pdo->exec($sql);
        $stmt = $pdo->prepare('INSERT INTO schema_migrations (filename, applied_at) VALUES (?, NOW())');
        $stmt->execute([$filename]);
        $pdo->commit();
        echo "ok\n";
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo "FAILED\n";
        fwrite(STDERR, $e->getMessage() . "\n");
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

    return new PDO(
        $dsn,
        $env['DB_MIGRATION_USER'] ?? '',
        $env['DB_MIGRATION_PASS'] ?? '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
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
