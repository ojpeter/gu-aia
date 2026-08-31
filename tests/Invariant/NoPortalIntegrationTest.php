<?php

declare(strict_types=1);

namespace GuAia\Tests\Invariant;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * INV-10 — No personal data in Phase 1.
 *
 * "No login, no student records, no account lookup... Phase 1 has NO PORTAL
 *  INTEGRATION AT ALL; THE INTEGRATION SURFACE DOES NOT EXIST IN THE CODEBASE."
 *
 * This test asserts an absence, which is unusual and deliberate. Risk R-13 in
 * docs/ai-risk-register.md is "Phase 2 surface added early" — stubbing a
 * connection to the Portal "ready for later" is a natural engineering instinct
 * and it silently turns a no-personal-data system into a personal-data one
 * without any of the controls Phase 2 requires.
 *
 * requirements.md Section 15 is explicit that Phase 2 is "a separate
 * specification. Do not begin it inside this repository."
 *
 * If this test ever needs changing, that is the signal that a scope decision is
 * being made. Make it deliberately, not by editing an assertion.
 */
#[Group('invariant')]
final class NoPortalIntegrationTest extends TestCase
{
    private const SCANNED = ['src', 'bin', 'public', 'config'];

    /**
     * Schemas and systems belonging to the sibling projects. None of these may
     * be referenced from this codebase in Phase 1.
     *
     * @var array<string, string>
     */
    private const FORBIDDEN = [
        'gu_hrms' => 'the gu-services (eServices Portal) database, which holds HR and student records',
        'gu_website' => "the gu-website database",
        'cms_news' => 'a gu-services CMS table',
        'cms_staff_profiles' => 'a gu-services CMS table',
    ];

    public function testNoCodeReferencesASiblingProjectDatabase(): void
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

                // bin/verify_grants.php names the sibling schemas precisely to
                // PROVE they are unreachable. That is enforcement, not integration.
                if ($relative === 'bin/verify_grants.php') {
                    continue;
                }

                $contents = (string) file_get_contents($file->getPathname());

                foreach (self::FORBIDDEN as $needle => $why) {
                    if (str_contains($contents, $needle)) {
                        $offenders[] = sprintf('%s references "%s" (%s)', $relative, $needle, $why);
                    }
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "INV-10 breach: a Phase 2 integration surface has appeared in the codebase.\n"
            . "Phase 2 is a separate specification and must not begin in this repository "
            . "(requirements.md Section 15).\n" . implode("\n", $offenders)
        );
    }

    public function testNoStudentRecordConceptsAreModelledInTheSchema(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];

        // Tables that would only exist if this project had started holding
        // per-person academic or financial data.
        $forbiddenTables = [
            'students', 'student_records', 'fees_balances', 'fee_balance',
            'examination_results', 'exam_results', 'enrolments', 'enrollments',
            'transcripts',
        ];

        foreach (glob($root . '/db/migrations/*.sql') ?: [] as $file) {
            $contents = (string) file_get_contents($file);

            foreach ($forbiddenTables as $table) {
                if (preg_match('/CREATE\s+TABLE\s+`?' . preg_quote($table, '/') . '`?\b/i', $contents) === 1) {
                    $offenders[] = basename($file) . ' creates ' . $table;
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "INV-10 breach: the schema has grown a personal-record table.\n"
            . implode("\n", $offenders)
        );
    }

    public function testTheIndividualRecordCategoryRefusesRatherThanLooksUp(): void
    {
        $categories = require dirname(__DIR__, 2) . '/config/categories.php';

        self::assertSame(
            'refuse',
            $categories['categories']['individual_record']['mode'],
            'INV-10 breach: "what is my balance" must be refused in Phase 1, not answered.'
        );
    }
}
