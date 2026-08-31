<?php

/**
 * The assistant endpoint. requirements.md Sections 10, 11, 13.
 *
 * GET  renders the widget shell (disclosure, form, starter questions).
 * POST answers a question, and returns either the whole page or just the answer
 *      fragment depending on the Accept header.
 *
 * BOTH PATHS ARE THE SAME CODE. Section 10 requires "a plain form posting to the
 * SAME ENDPOINT, returning a cited answer as HTML" (INV-9), and the surest way to
 * keep a no-JavaScript fallback working is to make it the primary path rather
 * than a second implementation that nobody exercises.
 *
 * THIS FILE OWNS THE TRANSACTION. InteractionLogger writes inside the caller's
 * transaction by design (INV-7), so somebody has to be the caller. It is here:
 * begin, answer, log, commit, then render. A response is never sent before its
 * log entry is committed.
 *
 * Errors show nothing internal (CLAUDE.md Rule 5). A stack trace, a DB message
 * or a prompt fragment reaching a visitor would be a disclosure bug in a system
 * whose whole job is controlling what it discloses.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use GuAia\Answering\AnsweringPipeline;
use GuAia\Answering\BudgetGuard;
use GuAia\Answering\CategoryRouter;
use GuAia\Answering\CitationBinder;
use GuAia\Answering\FakeGenerator;
use GuAia\Answering\PromptBuilder;
use GuAia\Http\WidgetRenderer;
use GuAia\Ingestion\HashingEmbedder;
use GuAia\Logging\IdentifierHasher;
use GuAia\Logging\InteractionLogger;
use GuAia\Retrieval\CandidateGenerator;
use GuAia\Retrieval\QueryNormaliser;
use GuAia\Retrieval\Reranker;
use GuAia\Retrieval\Retriever;
use GuAia\Safety\Csrf;
use GuAia\Safety\RateLimiter;
use GuAia\Safety\RefusalRenderer;

$root = dirname(__DIR__);

/** @var array<string, string> $env */
$env = (static function (string $path): array {
    if (!is_readable($path)) {
        return [];
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

$isDev = ($env['APP_ENV'] ?? 'development') === 'development';

// Session: secure cookie flags, a name that does not advertise the stack, and a
// 30-minute idle expiry (Section 10: "conversation state is limited to the
// current session, held server-side, expiring in 30 minutes").
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => (($_SERVER['HTTPS'] ?? '') !== '') || (($_SERVER['SERVER_PORT'] ?? '') === '443'),
]);
session_name($env['SESSION_COOKIE_NAME'] ?? 'gu_aia_session');
session_start();

if (isset($_SESSION['last_seen']) && (time() - (int) $_SESSION['last_seen']) > 1800) {
    $_SESSION = [];
    session_regenerate_id(true);
}
$_SESSION['last_seen'] = time();

$csrf = new Csrf($_SESSION);
$renderer = new WidgetRenderer();

$question = trim((string) ($_POST['question'] ?? $_GET['question'] ?? ''));
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$wantsFragment = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/html+fragment');

$notice = null;
$answer = null;

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

    $hasher = new IdentifierHasher($env['LOG_HASH_KEY'] ?? '');

    if ($isPost && $question !== '') {
        // CSRF before anything that writes, spends or counts.
        if (!$csrf->verify((string) ($_POST['csrf_token'] ?? ''))) {
            http_response_code(400);
            $notice = 'Your session expired. Please ask the question again.';
        } else {
            $budgetConfig = require $root . '/config/budget.php';
            $limits = $budgetConfig['rate_limit'];

            $limiter = new RateLimiter($pdo);
            $ipHash = $hasher->hash((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
            $sessionHash = $hasher->hash(session_id() ?: 'no-session');

            $perIp = $limiter->hit('ip', $ipHash, (int) $limits['per_ip_per_hour'], 'ask');
            $perSession = $limiter->hit('session', $sessionHash, (int) $limits['per_session_per_hour'], 'ask');

            if (!$perIp->allowed || !$perSession->allowed) {
                http_response_code(429);
                header('Retry-After: ' . max($perIp->retryAfterSeconds, $perSession->retryAfterSeconds));
                $notice = ($perSession->allowed ? $perIp : $perSession)->message();
            } else {
                $retrievalConfig = require $root . '/config/retrieval.php';
                $categoryConfig = require $root . '/config/categories.php';
                $refusalConfig = require $root . '/config/refusals.php';

                $embedder = new HashingEmbedder();
                $threshold = $retrievalConfig['score_threshold'];

                $pipeline = new AnsweringPipeline(
                    router: new CategoryRouter(),
                    retriever: new Retriever(
                        candidates: new CandidateGenerator($pdo, (int) $retrievalConfig['candidate_limit']),
                        reranker: new Reranker(exactCodeBoost: (float) $retrievalConfig['exact_match_boost']),
                        embedder: $embedder,
                        normaliser: new QueryNormaliser($retrievalConfig['abbreviations']),
                        scoreThreshold: $threshold === null ? null : (float) $threshold,
                        topK: (int) $retrievalConfig['top_k'],
                    ),
                    prompts: new PromptBuilder($root . '/config/prompts'),
                    // The fake stays wired until Section 18 open question 1 is
                    // decided. It is not a placeholder that pretends: it returns
                    // an uncited answer, which the citation binder discards, so
                    // the visible behaviour is an honest refusal rather than a
                    // fabricated answer.
                    generator: new FakeGenerator(),
                    binder: new CitationBinder(),
                    refusals: new RefusalRenderer($refusalConfig),
                    categories: $categoryConfig['categories'],
                    budget: new BudgetGuard(
                        monthlyCeiling: $budgetConfig['monthly_ceiling'] === null
                            ? null
                            : (float) $budgetConfig['monthly_ceiling'],
                    ),
                );

                $logger = new InteractionLogger($pdo, $hasher);

                // The transaction the logger's contract depends on.
                $pdo->beginTransaction();
                try {
                    $startedAt = hrtime(true);
                    $answer = $pipeline->answer($question);
                    $latencyMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);

                    $logger->log(
                        correlationId: sprintf(
                            '%s-%s-4%s-%s-%s',
                            bin2hex(random_bytes(4)),
                            bin2hex(random_bytes(2)),
                            substr(bin2hex(random_bytes(2)), 1),
                            bin2hex(random_bytes(2)),
                            bin2hex(random_bytes(6))
                        ),
                        question: $question,
                        result: $answer,
                        latencyMs: $latencyMs,
                        context: [
                            'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                            'session_id' => session_id() ?: null,
                        ],
                    );

                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $e;
                }
            }
        }
    }
} catch (Throwable $e) {
    error_log('[gu-aia] ' . $e->getMessage());
    http_response_code(503);
    $answer = null;
    // Nothing internal reaches the visitor.
    $notice = 'The assistant is temporarily unavailable. Please try again shortly.';
}

// A fragment response returns only the answer, for the enhanced path.
if ($isPost && $wantsFragment) {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo $answer !== null
        ? $renderer->answer($answer)
        : '<div class="gu-aia-answer" id="gu-aia-answer" role="region" aria-live="polite" tabindex="-1">'
            . '<p class="gu-aia-caution" role="note">' . $renderer->esc((string) $notice) . '</p></div>';
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Ask Gulu University</title>
<link rel="icon" type="image/png" sizes="32x32" href="assets/images/logo-32.png">
<?= $renderer->assets(__DIR__) ?>
</head>
<body>
<?= $renderer->shell(
    csrfField: $csrf->field(),
    question: $question,
    answer: $answer,
    notice: $notice,
) ?>
</body>
</html>
