<?php

/**
 * Ingests the configured corpus sources. requirements.md Section 5.
 *
 *   php bin/ingest.php              ingest every configured source
 *   php bin/ingest.php --dry-run    report what would happen, write nothing
 *
 * PHASE 0 GATES THIS, AND THE GATE IS THE EMPTY ALLOW-LIST.
 *
 * config/corpus.php ships with no allowed paths and no document sources, so this
 * command currently ingests nothing and says so. That is not an oversight to be
 * fixed by adding paths: Section 15 says "No indexing before this completes",
 * and what Phase 0 produces is exactly what each source needs before it can be
 * listed here - an owning office, a review date and a review interval.
 *
 * Adding a URL without those three does not work either. The ingester refuses it
 * (INV-11) before spending a request.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use GuAia\Ingestion\HashingEmbedder;
use GuAia\Ingestion\HttpFetcher;
use GuAia\Ingestion\Ingester;
use GuAia\Ingestion\IngestOutcome;

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

$corpus = require $root . '/config/corpus.php';

$sources = [];
foreach ($corpus['documents']['sources'] ?? [] as $source) {
    $sources[] = $source;
}

if ($sources === []) {
    echo "No corpus sources are configured, so nothing was ingested.\n\n";
    echo "This is the Phase 0 gate, not a misconfiguration. requirements.md Section 15:\n";
    echo "\"Content audit with Communications and the Registry. One authoritative source\n";
    echo "per fact; owners and review dates assigned. NO INDEXING BEFORE THIS COMPLETES.\"\n\n";
    echo "Each source added to config/corpus.php needs an owning office, a review date\n";
    echo "and a review interval. Without all three the ingester refuses it (INV-11).\n";
    exit(0);
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
        $env['DB_INGEST_USER'] ?? '',
        $env['DB_INGEST_PASS'] ?? '',
        $options
    );
} catch (PDOException) {
    fwrite(STDERR, "Cannot connect to the database. Check .env.\n");
    exit(1);
}

$fetcher = new HttpFetcher(
    domain: (string) ($corpus['crawl']['domain'] ?? 'gu.ac.ug'),
    allowedPaths: $corpus['crawl']['allowed_paths'] ?? [],
    excludedPaths: $corpus['crawl']['excluded_paths'] ?? [],
);

$ingester = new Ingester($pdo, $fetcher, new HashingEmbedder());

$run = $pdo->prepare(
    "INSERT INTO ingest_runs (scope, started_at, status) VALUES ('full', NOW(), 'running')"
);
$run->execute();
$runId = (int) $pdo->lastInsertId();

$seen = 0;
$ingested = 0;
$rejected = 0;
$chunks = 0;
$flagged = [];

if ($dryRun) {
    $pdo->beginTransaction();
}

foreach ($sources as $source) {
    $seen++;
    $outcome = $ingester->ingest((string) $source['url'], $source);

    printf("%-60s %s\n", mb_substr((string) $source['url'], 0, 60), $outcome->status);

    if ($outcome->status === IngestOutcome::INGESTED) {
        $ingested++;
        $chunks += $outcome->chunks;
        foreach ($outcome->flagged as $flag) {
            $flagged[] = $flag;
        }
    } elseif ($outcome->status === IngestOutcome::REJECTED) {
        $rejected++;
        printf("    %s\n", (string) $outcome->message);
    }
}

if ($dryRun && $pdo->inTransaction()) {
    $pdo->rollBack();
} else {
    $finish = $pdo->prepare(
        "UPDATE ingest_runs
            SET finished_at = NOW(), status = 'succeeded', documents_seen = ?,
                documents_ingested = ?, documents_rejected = ?, chunks_written = ?
          WHERE id = ?"
    );
    $finish->execute([$seen, $ingested, $rejected, $chunks, $runId]);
}

printf(
    "\n%s: %d seen, %d ingested (%d chunks), %d rejected.\n",
    $dryRun ? 'DRY RUN' : 'Done',
    $seen,
    $ingested,
    $chunks,
    $rejected
);

if ($flagged !== []) {
    echo "\nInstruction-shaped text was found and KEPT (INV-6 defends by delimiting,\n";
    echo "not by editing University pages). Someone should look at these:\n";
    foreach (array_unique($flagged) as $flag) {
        printf("  - %s\n", $flag);
    }
}

if ($rejected > 0) {
    echo "\nRejected documents are recorded with a reason on the document row and appear\n";
    echo "in the console, so the owning office can act on them (Section 5.2).\n";
}

echo "\nRe-run bin/evaluate.php now: Section 12 requires the harness after every re-index,\n";
echo "because content changes break retrieval as surely as code does.\n";

exit(0);
