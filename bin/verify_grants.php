<?php

declare(strict_types=1);

/**
 * Functional verification of the database grants. CLAUDE.md Rule 3.
 *
 * "Verify each grant functionally — attempt the write that should fail and
 *  record the result — rather than asserting it from the GRANT statement."
 *
 * A GRANT statement is a claim. This script is the evidence. It connects as each
 * account and attempts every operation that must be allowed and every operation
 * that must be refused, then reports what actually happened.
 *
 * Run it after any change to db/accounts.sql or db/accounts_bootstrap.sql, and
 * after any migration that adds a table — a new table with no grant is invisible
 * to the app, and a new table granted too widely is a quiet privilege creep.
 *
 *   php bin/verify_grants.php
 *
 * Exit code 0 if every expectation held, 1 otherwise.
 *
 * Every probe is written to be harmless. Nothing here inserts real rows: the
 * denied probes are refused before they touch data, and the allowed probes are
 * reads or no-op updates against ids that do not exist.
 */

$root = dirname(__DIR__);

/** @return array<string, string> */
$loadEnv = static function (string $path): array {
    if (!is_readable($path)) {
        fwrite(STDERR, "No .env found at {$path}.\n");
        exit(1);
    }
    $env = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $env[trim($k)] = trim(trim($v), "\"'");
    }
    return $env;
};

$env = $loadEnv($root . '/.env');
$dsn = sprintf(
    'mysql:host=%s;dbname=%s;charset=%s',
    $env['DB_HOST'] ?? 'localhost',
    $env['DB_NAME'] ?? 'gu_aia',
    $env['DB_CHARSET'] ?? 'utf8mb4'
);

$accounts = [
    'app'     => [$env['DB_APP_USER'] ?? '', $env['DB_APP_PASS'] ?? ''],
    'ingest'  => [$env['DB_INGEST_USER'] ?? '', $env['DB_INGEST_PASS'] ?? ''],
    'migrate' => [$env['DB_MIGRATION_USER'] ?? '', $env['DB_MIGRATION_PASS'] ?? ''],
];

$options = require $root . '/config/pdo_options.php';

/** @var array<string, PDO> $pdo */
$pdo = [];
foreach ($accounts as $name => [$user, $pass]) {
    try {
        $pdo[$name] = new PDO($dsn, $user, $pass, $options);
    } catch (PDOException $e) {
        fwrite(STDERR, "Cannot connect as '{$name}' ({$user}). Check .env and db/accounts*.sql.\n");
        exit(1);
    }
}

/**
 * Each probe: [account, expectation, what it proves, SQL].
 * 'allow' = must succeed. 'deny' = must be refused by the server.
 *
 * @var list<array{0: string, 1: string, 2: string, 3: string}> $probes
 */
$probes = [
    // --- The corpus is readable by the app, and writable only by ingestion ---
    ['app', 'allow', 'app reads chunks (retrieval)',
        'SELECT id FROM chunks LIMIT 1'],
    ['app', 'allow', 'app reads categories',
        'SELECT category_key FROM categories LIMIT 1'],
    ['app', 'deny', 'app CANNOT write the corpus (defacement risk)',
        "INSERT INTO documents (source_type, source_ref, source_ref_hash, title, owning_office_id, reviewed_at, review_interval_days) VALUES ('web_page','x','x','x',1,'2026-01-01',30)"],
    ['app', 'deny', 'app CANNOT write chunks',
        "UPDATE chunks SET body = 'tampered' WHERE id = 0"],

    // --- INV-12: nothing is deleted, enforced by the server ---
    ['app', 'deny', 'INV-12: app CANNOT delete interactions',
        'DELETE FROM interactions WHERE id = 0'],
    ['app', 'deny', 'INV-12: app CANNOT delete feedback',
        'DELETE FROM feedback WHERE id = 0'],
    ['ingest', 'deny', 'INV-12: ingest CANNOT delete documents',
        'DELETE FROM documents WHERE id = 0'],
    ['ingest', 'deny', 'INV-12: ingest CANNOT delete chunks',
        'DELETE FROM chunks WHERE id = 0'],
    ['migrate', 'deny', 'INV-12: migration account CANNOT delete',
        'DELETE FROM documents WHERE id = 0'],
    ['migrate', 'deny', 'forward-only: migration account CANNOT drop tables',
        'DROP TABLE IF EXISTS _grant_probe_nonexistent'],

    // --- Purpose limitation: ingestion has no business in the chat logs ---
    ['ingest', 'deny', 'purpose limitation: ingest CANNOT read interactions (personal data)',
        'SELECT id FROM interactions LIMIT 1'],
    ['ingest', 'deny', 'purpose limitation: ingest CANNOT read feedback',
        'SELECT id FROM feedback LIMIT 1'],
    ['ingest', 'deny', 'purpose limitation: ingest CANNOT read unanswered questions',
        'SELECT id FROM unanswered_questions LIMIT 1'],
    ['ingest', 'allow', 'ingest reads documents (its own job)',
        'SELECT id FROM documents LIMIT 1'],

    // --- The audit log is append-only, by grant not by convention ---
    ['app', 'allow', 'app reads the audit log',
        'SELECT id FROM admin_audit_log LIMIT 1'],
    ['app', 'deny', 'append-only: app CANNOT rewrite the audit log',
        "UPDATE admin_audit_log SET action = 'rewritten' WHERE id = 0"],
    ['app', 'deny', 'append-only: app CANNOT delete audit entries',
        'DELETE FROM admin_audit_log WHERE id = 0'],

    // --- Column-level: the app records a login, it does not grant a role ---
    ['app', 'allow', 'app may record a login timestamp',
        'UPDATE admin_users SET last_login_at = NOW() WHERE id = 0'],
    ['app', 'deny', 'app CANNOT change an admin role',
        "UPDATE admin_users SET role = 'authoriser' WHERE id = 0"],
    ['app', 'deny', 'app CANNOT change an admin password hash',
        "UPDATE admin_users SET password_hash = 'x' WHERE id = 0"],

    // --- INV-10: no cross-system reach, made true in the grant table ---
    ['app', 'deny', 'INV-10: app CANNOT read gu_hrms (gu-services database)',
        'SELECT 1 FROM gu_hrms.employees LIMIT 1'],
    ['app', 'deny', 'INV-10: app CANNOT read gu_website (sibling database)',
        'SELECT 1 FROM gu_website.news LIMIT 1'],
    ['ingest', 'deny', 'INV-10: ingest CANNOT read gu_hrms',
        'SELECT 1 FROM gu_hrms.employees LIMIT 1'],

    // --- The app can do the logging it must do (INV-7) ---
    ['app', 'allow', 'INV-7: app reads the interaction log',
        'SELECT id FROM interactions LIMIT 1'],
    ['app', 'allow', 'app reads budget periods (INV-8 pre-generation check)',
        'SELECT period FROM budget_periods LIMIT 1'],
    ['app', 'allow', 'app reads rate limits',
        'SELECT id FROM rate_limits LIMIT 1'],
];

/** MySQL/MariaDB access-denied SQLSTATEs: 42000 covers 1142/1143/1044. */
$isAccessDenied = static function (PDOException $e): bool {
    $msg = $e->getMessage();
    return str_contains($msg, 'command denied')
        || str_contains($msg, 'Access denied')
        || str_contains($msg, 'SELECT command denied')
        || str_contains($msg, 'denied to user');
};

$pass = 0;
$fail = 0;
$failures = [];

printf("%-8s  %-6s  %-8s  %s\n", 'ACCOUNT', 'EXPECT', 'RESULT', 'WHAT IT PROVES');
echo str_repeat('-', 100), "\n";

foreach ($probes as [$account, $expect, $what, $sql]) {
    $denied = false;
    $otherError = null;

    try {
        $pdo[$account]->query($sql);
    } catch (PDOException $e) {
        if ($isAccessDenied($e)) {
            $denied = true;
        } else {
            // A non-privilege error (missing table, FK violation) means the probe
            // did not test what it claims to test. Surface it rather than
            // counting it as a pass.
            $otherError = $e->getMessage();
        }
    }

    $actual = $denied ? 'denied' : ($otherError !== null ? 'error' : 'allowed');
    $ok = ($expect === 'deny' && $denied) || ($expect === 'allow' && $otherError === null && !$denied);

    if ($ok) {
        $pass++;
    } else {
        $fail++;
        $failures[] = [$account, $expect, $actual, $what, $otherError];
    }

    printf("%-8s  %-6s  %-8s  %s%s\n", $account, $expect, $actual, $ok ? '' : '** ', $what);
}

echo str_repeat('-', 100), "\n";
printf("%d passed, %d failed, %d probes total.\n", $pass, $fail, count($probes));

if ($fail > 0) {
    echo "\nFAILURES:\n";
    foreach ($failures as [$account, $expect, $actual, $what, $err]) {
        printf("  [%s] expected %s, got %s: %s\n", $account, $expect, $actual, $what);
        if ($err !== null) {
            printf("      %s\n", $err);
        }
    }
    echo "\nThe grant table does not match what db/accounts.sql claims. Fix it before proceeding.\n";
    exit(1);
}

echo "\nEvery grant behaves as db/accounts.sql claims.\n";
exit(0);
