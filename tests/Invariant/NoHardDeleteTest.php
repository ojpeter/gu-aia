<?php

declare(strict_types=1);

namespace GuAia\Tests\Invariant;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * INV-12 — Nothing is deleted.
 *
 * "Superseded chunks are marked superseded, not removed, so that a past answer
 *  can be reconstructed for a complaint. No DELETE in application code; CI greps
 *  for it."
 *
 * This is the CI grep, as a test so it runs with everything else.
 *
 * It is the third of three layers, and the weakest of them, which is worth
 * knowing when reading it:
 *
 *   1. No database account is granted DELETE or DROP (db/accounts.sql), proven
 *      by bin/verify_grants.php. The server refuses.
 *   2. bin/migrate.php refuses any migration containing DELETE or TRUNCATE.
 *   3. This grep, which catches the intent early and gives a clear reason.
 *
 * A grep can be fooled by string concatenation. It is here to stop the honest
 * mistake, not the determined one; layer 1 stops that.
 */
#[Group('invariant')]
final class NoHardDeleteTest extends TestCase
{
    /** Directories containing application code that talks to the database. */
    private const SCANNED = ['src', 'bin', 'public'];

    /**
     * bin/migrate.php and bin/verify_grants.php mention DELETE inside the
     * guards and probes that ENFORCE this invariant. Excluding them by name,
     * rather than loosening the pattern, keeps the check strict everywhere else.
     *
     * @var list<string>
     */
    private const ALLOWED = [
        'bin/migrate.php',        // refuses migrations containing DELETE/TRUNCATE
        'bin/verify_grants.php',  // probes that DELETE is refused by the server
    ];

    public function testNoApplicationCodePerformsAHardDelete(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];

        foreach (self::SCANNED as $dir) {
            $path = $root . '/' . $dir;
            if (!is_dir($path)) {
                continue;
            }

            /** @var iterable<SplFileInfo> $files */
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));

            foreach ($files as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                if (in_array($relative, self::ALLOWED, true)) {
                    continue;
                }

                $contents = (string) file_get_contents($file->getPathname());

                if (preg_match('/\bDELETE\s+FROM\b/i', $contents) === 1) {
                    $offenders[] = $relative . ' (DELETE FROM)';
                }
                if (preg_match('/\bTRUNCATE\b/i', $contents) === 1) {
                    $offenders[] = $relative . ' (TRUNCATE)';
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "INV-12 breach: hard deletion found in application code.\n"
            . "Supersede or redact instead — a past answer must stay reconstructible "
            . "for a complaint.\n" . implode("\n", $offenders)
        );
    }

    public function testNoMigrationDeletesData(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];

        foreach (glob($root . '/db/migrations/*.sql') ?: [] as $file) {
            $contents = (string) file_get_contents($file);
            if (preg_match('/\bDELETE\s+FROM\b|\bTRUNCATE\b/i', $contents) === 1) {
                $offenders[] = basename($file);
            }
        }

        self::assertSame([], $offenders, 'INV-12 breach: a migration removes rows.');
    }

    public function testNoAccountIsGrantedDeleteOrDrop(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (['db/accounts.sql', 'db/accounts_bootstrap.sql'] as $relative) {
            $contents = (string) file_get_contents($root . '/' . $relative);

            // Look only at the GRANT statements; the surrounding commentary
            // discusses DELETE precisely because it is withheld.
            foreach (explode("\n", $contents) as $line) {
                $line = trim($line);
                if (!str_starts_with(strtoupper($line), 'GRANT ')) {
                    continue;
                }

                self::assertDoesNotMatchRegularExpression(
                    '/\bDELETE\b/i',
                    $line,
                    "INV-12 breach: {$relative} grants DELETE.\n{$line}"
                );
                self::assertDoesNotMatchRegularExpression(
                    '/\bDROP\b/i',
                    $line,
                    "Forward-only breach: {$relative} grants DROP.\n{$line}"
                );
                self::assertDoesNotMatchRegularExpression(
                    '/\bALL\s+PRIVILEGES\b/i',
                    $line,
                    "Least privilege breach: {$relative} grants ALL PRIVILEGES, which "
                    . "would include DELETE and DROP.\n{$line}"
                );
            }
        }
    }
}
