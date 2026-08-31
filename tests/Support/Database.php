<?php

declare(strict_types=1);

namespace GuAia\Tests\Support;

use PDO;
use PDOException;

/**
 * Database connection for integration tests.
 *
 * Tests that touch the database run inside a transaction and roll back, which
 * leaves nothing behind. That matters more than usual here: the corpus must stay
 * EMPTY until Phase 0 completes, so a test that seeded a document and forgot to
 * clean up would put fabricated content into the corpus the whole project is
 * built to keep honest.
 *
 * Rollback also happens to be the only cleanup available — no account on this
 * schema holds DELETE (INV-12) — which is a pleasant confirmation that the
 * constraint and the testing strategy agree.
 *
 * Connects as the MIGRATION account, because it is the only one with INSERT on
 * both the corpus and the log tables. That is a deliberate widening for tests
 * only; nothing in src/ ever uses it.
 */
final class Database
{
    private static ?PDO $pdo = null;
    private static ?string $unavailableReason = null;

    public static function connect(): ?PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        if (self::$unavailableReason !== null) {
            return null;
        }

        $root = dirname(__DIR__, 2);
        $envPath = $root . '/.env';

        if (!is_readable($envPath)) {
            self::$unavailableReason = 'no .env';

            return null;
        }

        $env = [];
        foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $env[trim($k)] = trim(trim($v), "\"'");
        }

        try {
            $options = require $root . '/config/pdo_options.php';
            self::$pdo = new PDO(
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
            self::$unavailableReason = 'cannot connect';

            return null;
        }

        return self::$pdo;
    }

    public static function unavailableReason(): string
    {
        return self::$unavailableReason ?? 'unknown';
    }
}
