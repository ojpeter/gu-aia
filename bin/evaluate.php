<?php

/**
 * The evaluation harness. requirements.md Section 12.
 *
 * "This is not optional and is not a nice-to-have."
 * "The harness runs in CI and blocks a merge on regression. Retrieval quality is
 *  a test, not a feeling."
 *
 * WHAT IT CAN MEASURE TODAY, AND WHAT IT CANNOT
 *
 * Section 12 asks for four metrics: retrieval hit rate at k, refusal precision
 * and recall, citation validity, and mean latency.
 *
 * Two of those need a corpus and a retrieval pipeline, neither of which exists —
 * Phase 0 gates indexing. This harness therefore measures what the routing and
 * refusal layer can actually be held to today, and reports the rest as
 * NOT MEASURABLE rather than as zero, one, or a silent pass.
 *
 * That distinction is the whole point of the file. A harness that prints
 * "citation validity: 100%" when nothing was cited is worse than one that prints
 * nothing, because somebody will quote the number.
 *
 *   php bin/evaluate.php              run, record, print, exit non-zero on failure
 *   php bin/evaluate.php --no-record  run and print without writing an eval_run
 *
 * Exit 0 if every measurable threshold held, 1 otherwise. CI gates on this.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use GuAia\Answering\AnswerMode;
use GuAia\Answering\CategoryRouter;
use GuAia\Answering\FakeGenerator;

$root = dirname(__DIR__);
$record = !in_array('--no-record', $argv, true);

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

$config = require $root . '/config/eval.php';
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
} catch (PDOException) {
    fwrite(STDERR, "Cannot connect to the database. Check .env.\n");
    exit(1);
}

$questionsStmt = $pdo->query(
    'SELECT id, question, language, expected_mode, must_not_refuse, expected_category_key,
            expected_document_id, suite, source
       FROM eval_questions
      WHERE is_active = 1
      ORDER BY suite, id'
);
if ($questionsStmt === false) {
    fwrite(STDERR, "Could not read eval_questions. Run bin/seed_eval_questions.php first.\n");
    exit(1);
}

/** @var list<array<string, mixed>> $questions */
$questions = $questionsStmt->fetchAll();

if ($questions === []) {
    fwrite(STDERR, "The golden set is empty. Run bin/seed_eval_questions.php.\n");
    exit(1);
}

$router = new CategoryRouter();

/**
 * A suite whose expectation depends on a pipeline stage that does not exist yet
 * is PENDING, not failed. See config/eval.php 'suite_requires' for why this
 * distinction is load-bearing.
 *
 * @var array<string, list<string>> $missingStages suite => stages not yet built
 */
$missingStages = [];
foreach ($config['suite_requires'] as $suiteName => $requires) {
    $missing = array_values(array_diff($requires, $config['stages_built']));
    if ($missing !== []) {
        $missingStages[(string) $suiteName] = $missing;
    }
}

/** @var list<array<string, mixed>> $results */
$results = [];
$corpusEmpty = (static function (PDO $pdo): bool {
    $stmt = $pdo->query('SELECT COUNT(*) FROM chunks WHERE status = \'active\'');
    return $stmt === false || (int) $stmt->fetchColumn() === 0;
})($pdo);

foreach ($questions as $q) {
    $started = hrtime(true);

    // The generator is watched, not used: INV-2 requires that a high-stakes
    // question never reaches it, and the only way to assert that is to count.
    $generator = new FakeGenerator();
    $routing = $router->route((string) $q['question']);

    if ($routing->mode->callsGenerator() && !$corpusEmpty) {
        // Grounded answering would happen here once retrieval exists. With an
        // empty corpus there is nothing to ground against, so nothing is called.
        $generator->generate('system', 'user');
    }

    $latencyMs = (int) ((hrtime(true) - $started) / 1_000_000);

    $expectedMode = $q['expected_mode'] === null ? null : (string) $q['expected_mode'];
    $mustNotRefuse = (int) $q['must_not_refuse'] === 1;
    $actualMode = $routing->mode->value;

    if ($mustNotRefuse) {
        $passed = $routing->mode !== AnswerMode::Refuse;
        $detail = $passed ? null : 'over-refusal: an ordinary question was refused';
    } else {
        $passed = $actualMode === $expectedMode;
        $detail = $passed ? null : sprintf('expected %s, got %s', (string) $expectedMode, $actualMode);
    }

    // INV-3 is not satisfied by refusing late. An individual-outcome question
    // that reached retrieval first is a failure even if the final answer was a
    // refusal.
    if ((string) $q['suite'] === 'individual_outcome' && $passed && !$routing->refusedBeforeRetrieval) {
        $passed = false;
        $detail = 'refused, but only after retrieval — INV-3 requires refusal before retrieval';
    }

    if ($routing->mode === AnswerMode::Quoted && $generator->wasCalled()) {
        $passed = false;
        $detail = 'INV-2 breach: the generator was invoked for a quoted-mode question';
    }

    $suite = (string) $q['suite'];

    // The expectation is right; the stage that would satisfy it is not built.
    // Recording that as a failure would make an unbuilt system look broken and
    // train whoever reads this output to ignore red.
    $pending = isset($missingStages[$suite]);
    if ($pending) {
        $detail = 'pending: needs ' . implode(' + ', $missingStages[$suite]);
    }

    $results[] = [
        'question_id' => (int) $q['id'],
        'suite' => $suite,
        'question' => (string) $q['question'],
        'expected_mode' => $expectedMode,
        'must_not_refuse' => $mustNotRefuse,
        'actual_mode' => $actualMode,
        'pending' => $pending,
        'passed' => $pending ? null : $passed,
        'detail' => $detail,
        'latency_ms' => $latencyMs,
    ];
}

// ---------------------------------------------------------------------------
// Metrics
// ---------------------------------------------------------------------------

/** @param list<array<string, mixed>> $rows */
$rate = static function (array $rows): ?float {
    // Pending rows are excluded from the denominator: scoring a question the
    // system cannot yet be asked would make the percentage meaningless.
    $scored = array_values(array_filter($rows, static fn (array $r): bool => $r['pending'] !== true));
    if ($scored === []) {
        return null;
    }
    $passed = count(array_filter($scored, static fn (array $r): bool => $r['passed'] === true));

    return $passed / count($scored);
};

/** @param list<array<string, mixed>> $rows */
$inSuite = static function (array $rows, string $suite): array {
    return array_values(array_filter($rows, static fn (array $r): bool => $r['suite'] === $suite));
};

$outcomeRecall = $rate($inSuite($results, 'individual_outcome'));
$recordRecall = $rate($inSuite($results, 'individual_record'));
$quotedAccuracy = $rate($inSuite($results, 'quoted_high_stakes'));
$precisionRows = $inSuite($results, 'precision');
$refusalPrecision = $rate($precisionRows);

$latencies = array_map(static fn (array $r): int => (int) $r['latency_ms'], $results);
$meanLatency = $latencies === [] ? 0 : (int) round(array_sum($latencies) / count($latencies));

$totalPassed = count(array_filter($results, static fn (array $r): bool => $r['passed'] === true));
$totalPending = count(array_filter($results, static fn (array $r): bool => $r['pending'] === true));
$totalFailed = count(array_filter($results, static fn (array $r): bool => $r['passed'] === false));

// ---------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------

$thresholds = $config['thresholds'];
$failures = [];

$report = static function (string $label, ?float $actual, ?float $threshold) use (&$failures): void {
    if ($threshold === null) {
        printf("  %-28s %s\n", $label, 'NOT MEASURABLE — needs a corpus (Phase 0 gates indexing)');
        return;
    }
    if ($actual === null) {
        printf("  %-28s %s\n", $label, 'NO CASES in the set');
        return;
    }
    $ok = $actual >= $threshold;
    printf(
        "  %-28s %6.1f%%   threshold %5.1f%%   %s\n",
        $label,
        $actual * 100,
        $threshold * 100,
        $ok ? 'pass' : 'FAIL'
    );
    if (!$ok) {
        $failures[] = sprintf('%s: %.1f%% below threshold %.1f%%', $label, $actual * 100, $threshold * 100);
    }
};

echo "\nGU-AIA evaluation run\n";
echo str_repeat('=', 78), "\n";
printf(
    "Questions: %d active   Passed: %d   Failed: %d   Pending: %d\n",
    count($results),
    $totalPassed,
    $totalFailed,
    $totalPending
);

if ($totalPending > 0) {
    echo "\nPENDING means the expectation is correct but the stage that would satisfy it\n";
    echo "does not exist yet. Not failures, and not counted toward the gate:\n";
    foreach ($missingStages as $suiteName => $missing) {
        $n = count(array_filter($results, static fn (array $r): bool => $r['suite'] === $suiteName));
        if ($n > 0) {
            printf("  %-22s %3d question(s)  waiting on: %s\n", $suiteName, $n, implode(', ', $missing));
        }
    }
}
echo "\n";

echo "Measurable now (routing and refusal layer):\n";
$report('individual outcome recall', $outcomeRecall, $thresholds['individual_outcome_recall']);
$report('individual record recall', $recordRecall, $thresholds['individual_record_recall']);
$report('quoted routing accuracy', $quotedAccuracy, $thresholds['quoted_routing_accuracy']);
$report('refusal precision', $refusalPrecision, $thresholds['refusal_precision']);

echo "\nNot measurable until a corpus exists:\n";
$report('retrieval hit rate at k', null, $thresholds['retrieval_hit_rate_at_k']);
$report('citation validity', null, $thresholds['citation_validity']);
printf("  %-28s %d ms (routing only; no retrieval or generation)\n", 'mean latency', $meanLatency);

// Composition. A set that has quietly shrunk must not look complete.
echo "\nComposition against Section 12:\n";
$composition = $config['required_composition'];
foreach ($composition as $suite => $required) {
    if ($suite === 'total_minimum') {
        continue;
    }
    $have = count($inSuite($results, (string) $suite));
    printf(
        "  %-28s %3d / %3d %s\n",
        $suite,
        $have,
        $required,
        $have >= $required ? '' : '  SHORT'
    );
}
printf(
    "  %-28s %3d / %3d %s\n",
    'total',
    count($results),
    $composition['total_minimum'],
    count($results) >= $composition['total_minimum'] ? '' : '  SHORT'
);

// Languages. Section 18 open question 3.
$languages = [];
foreach ($questions as $q) {
    $languages[(string) $q['language']] = true;
}
$missingLanguages = array_values(array_diff($config['required_languages'], array_keys($languages)));

echo "\nLanguages present: " . implode(', ', array_keys($languages)) . "\n";
if ($missingLanguages !== []) {
    echo "  MISSING: " . implode(', ', $missingLanguages) . "\n";
    echo "  Section 18 open question 3: support for these languages is UNMEASURED and\n";
    echo "  must not be advertised. Adding questions requires a competent speaker;\n";
    echo "  inventing them would put wrong-language strings into the one artefact\n";
    echo "  meant to tell the truth about language quality.\n";
}

// Provenance. Section 12 requires the set to be authored with the offices.
$fromOffice = 0;
foreach ($questions as $q) {
    if ((string) $q['source'] === 'office') {
        $fromOffice++;
    }
}
printf(
    "\nProvenance: %d of %d questions authored by an office; %d are repository seed.\n",
    $fromOffice,
    count($questions),
    count($questions) - $fromOffice
);
if ($fromOffice === 0) {
    echo "  Section 12 requires the set to be authored WITH the Registry and\n";
    echo "  Communications. None of it has been yet.\n";
}

// Failures, in detail.
$failed = array_values(array_filter($results, static fn (array $r): bool => $r['passed'] === false));
if ($failed !== []) {
    echo "\nFailed cases:\n";
    foreach ($failed as $f) {
        printf("  [%s] %s\n      %s\n", $f['suite'], $f['question'], (string) $f['detail']);
    }
}

// ---------------------------------------------------------------------------
// Record the run (Section 12: re-run and record after every corpus re-index)
// ---------------------------------------------------------------------------

$passedOverall = $failures === [];

if ($record) {
    $runStmt = $pdo->prepare(
        'INSERT INTO eval_runs
            (started_at, finished_at, prompt_version, model, questions_total, questions_passed,
             refusal_precision, mean_latency_ms, passed, notes)
         VALUES (NOW(), NOW(), :prompt_version, :model, :total, :passed_count,
                 :precision, :latency, :passed, :notes)'
    );
    $runStmt->execute([
        'prompt_version' => null,
        'model' => 'none (routing layer only)',
        'total' => count($results),
        'passed_count' => $totalPassed,
        'precision' => $refusalPrecision,
        'latency' => $meanLatency,
        'passed' => $passedOverall ? 1 : 0,
        'notes' => 'Routing and refusal layer only. Retrieval hit rate and citation validity '
            . 'not measurable: no corpus (Phase 0 gates indexing).',
    ]);
    $runId = (int) $pdo->lastInsertId();

    $resultStmt = $pdo->prepare(
        'INSERT INTO eval_results
            (eval_run_id, eval_question_id, actual_mode, expected_mode, mode_matched,
             latency_ms, passed, failure_detail)
         VALUES (:run, :question, :actual, :expected, :matched, :latency, :passed, :detail)'
    );
    foreach ($results as $r) {
        $resultStmt->execute([
            'run' => $runId,
            'question' => $r['question_id'],
            'actual' => $r['actual_mode'],
            // eval_results.expected_mode is NOT NULL; a precision case expects
            // "anything but refuse", recorded as its actual mode when it passed.
            'expected' => $r['expected_mode'] ?? ($r['passed'] === true ? $r['actual_mode'] : 'grounded'),
            'matched' => $r['passed'] === true ? 1 : 0,
            'latency' => $r['latency_ms'],
            'passed' => $r['passed'] === true ? 1 : 0,
            'detail' => $r['detail'],
        ]);
    }

    printf("\nRecorded as eval run #%d.\n", $runId);
}

echo str_repeat('=', 78), "\n";

if (!$passedOverall) {
    echo "EVALUATION FAILED\n";
    foreach ($failures as $f) {
        echo '  ' . $f . "\n";
    }
    exit(1);
}

echo "All measurable thresholds met.\n";
printf(
    "%d of %d questions could actually be evaluated. This is NOT a statement that\n"
    . "retrieval works, or that the assistant is safe end to end. It cannot be, yet.\n",
    count($results) - $totalPending,
    count($results)
);
exit(0);
