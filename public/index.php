<?php

declare(strict_types=1);

/**
 * GU-AIA — project status page.
 *
 * This is NOT the assistant. There is no assistant yet: the repository is
 * foundations only (see progress.md). This page exists so that
 * http://localhost/gu-aia/ resolves to something honest and useful instead of a
 * directory listing, and so that the environment can be checked in a real
 * browser rather than inferred.
 *
 * Deliberately not built: anything resembling a chat interface. A widget shell
 * with no retrieval behind it would be a system that appears to answer and
 * cannot — the exact failure mode requirements.md Section 0 is written against.
 * The real widget arrives with the answering layer, carrying its server-rendered
 * AI disclosure (INV-4), its 60 KB budget and its no-JS fallback (INV-9).
 *
 * Detail is gated by APP_ENV: development shows the full preflight, anything
 * else shows the minimum, because a preflight table is a reconnaissance aid
 * (CLAUDE.md Rule 5 — never expose paths, versions or DB state to a visitor).
 */

$root = dirname(__DIR__);

/**
 * Minimal .env reader. Mirrors bin/migrate.php rather than sharing it, because
 * src/ has no bootstrap yet and this page must not invent one.
 *
 * @return array<string, string>
 */
$loadEnv = static function (string $path): array {
    if (!is_readable($path)) {
        return [];
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
$appEnv = $env['APP_ENV'] ?? 'development';
$isDev = $appEnv === 'development';

$esc = static fn (?string $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

/** @var list<array{label: string, ok: bool, detail: string}> $checks */
$checks = [];

if ($isDev) {
    $phpOk = PHP_VERSION_ID >= 80200;
    $checks[] = [
        'label' => 'PHP 8.2+',
        'ok' => $phpOk,
        'detail' => $phpOk ? 'running ' . PHP_VERSION : 'running ' . PHP_VERSION . ' — requirements.md Section 3 requires 8.2+',
    ];

    foreach (['pdo_mysql', 'mbstring', 'fileinfo', 'json'] as $ext) {
        $checks[] = [
            'label' => 'ext-' . $ext,
            'ok' => extension_loaded($ext),
            'detail' => extension_loaded($ext) ? 'loaded' : 'not loaded',
        ];
    }

    $envExists = is_readable($root . '/.env');
    $checks[] = [
        'label' => '.env present',
        'ok' => $envExists,
        'detail' => $envExists ? 'loaded' : 'not found — copy .env.example to .env',
    ];

    // Database. Absent is the expected state right now: no schema has been
    // written yet, so this reports rather than complains.
    $dbDetail = 'skipped — no .env';
    $dbOk = false;
    if ($envExists) {
        try {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                $env['DB_HOST'] ?? 'localhost',
                $env['DB_NAME'] ?? 'gu_aia',
                $env['DB_CHARSET'] ?? 'utf8mb4'
            );
            new PDO($dsn, $env['DB_MIGRATION_USER'] ?? '', $env['DB_MIGRATION_PASS'] ?? '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 2,
            ]);
            $dbOk = true;
            $dbDetail = 'schema ' . ($env['DB_NAME'] ?? 'gu_aia') . ' reachable';
        } catch (PDOException) {
            // Message deliberately not shown: a connection error leaks host,
            // user and schema names.
            $dbDetail = 'not reachable — expected, no schema has been created yet';
        }
    }
    $checks[] = ['label' => 'Database', 'ok' => $dbOk, 'detail' => $dbDetail];

    $migrations = glob($root . '/db/migrations/*.sql') ?: [];
    $checks[] = [
        'label' => 'Migrations',
        'ok' => false,
        'detail' => count($migrations) . ' written — the corpus schema is the next piece of work',
    ];

    $checks[] = [
        'label' => 'Generator',
        'ok' => ($env['GENERATOR_DRIVER'] ?? 'fake') === 'fake',
        'detail' => 'driver: ' . ($env['GENERATOR_DRIVER'] ?? 'fake') . ' — no API key, no spend',
    ];
}

/** @var list<array{id: string, text: string}> $invariants */
$invariants = [
    ['id' => 'INV-1', 'text' => 'No answer without a source.'],
    ['id' => 'INV-2', 'text' => 'High-stakes facts are quoted, never paraphrased.'],
    ['id' => 'INV-3', 'text' => 'No individual outcome.'],
    ['id' => 'INV-4', 'text' => 'Disclosure, before the first answer.'],
    ['id' => 'INV-5', 'text' => 'Closed retrieval scope.'],
    ['id' => 'INV-6', 'text' => 'Retrieved content is data, never instruction.'],
    ['id' => 'INV-7', 'text' => 'Everything is logged.'],
    ['id' => 'INV-8', 'text' => 'Spend is capped.'],
    ['id' => 'INV-9', 'text' => 'It works on a bad connection.'],
    ['id' => 'INV-10', 'text' => 'No personal data in Phase 1.'],
    ['id' => 'INV-11', 'text' => 'Stale content is visible.'],
    ['id' => 'INV-12', 'text' => 'Nothing is deleted.'],
];

http_response_code(200);
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>GU-AIA — Gulu University AI Assistant</title>
<style>
:root {
  --green: #00bf63;
  --green-deep: #027a41;
  --yellow: #fff24d;
  --red: #ff3131;
  --ink: #10201a;
  --body: #40514a;
  --muted: #6b7b74;
  --surface: #ffffff;
  --surface-alt: #f4f8f6;
  --border: #e2eae6;
  --radius: 10px;
}
@media (prefers-color-scheme: dark) {
  :root {
    --ink: #eef5f0;
    --body: #c2cfc9;
    --muted: #8c9c95;
    --surface: #131a17;
    --surface-alt: #1a2320;
    --border: #2a3733;
    --green-deep: #35d98a;
  }
}
* { box-sizing: border-box; }
body {
  margin: 0;
  padding: 0;
  font: 16px/1.6 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
  color: var(--body);
  background: var(--surface);
}
.skip {
  position: absolute; left: -9999px; top: 0;
  background: var(--green-deep); color: #fff; padding: .6rem 1rem; z-index: 10;
}
.skip:focus { left: 0; }
/* Page content spans 80% of the viewport width, centred. */
.wrap { width: 80%; margin: 0 auto; padding: 0; }
/* On a phone, 80% of a 390px screen leaves ~312px of text with no side room at
   all. Widen the band on small screens so the line length stays readable and
   the table still has somewhere to scroll — the 80% band is the desktop
   intent, not a rule worth breaking mobile for. */
@media (max-width: 48rem) { .wrap { width: 92%; } }
header.masthead {
  background: linear-gradient(135deg, #0e1912 0%, #027a41 60%, #00bf63 100%);
  color: #fff;
  padding: 2.5rem 0 2.25rem;
}
header.masthead p { color: rgba(255,255,255,.9); }
h1 { font-size: clamp(1.5rem, 4vw, 2.1rem); margin: 0 0 .4rem; line-height: 1.25; }
h2 { font-size: 1.15rem; color: var(--ink); margin: 2.25rem 0 .75rem; }
h3 { font-size: .95rem; color: var(--ink); margin: 1.25rem 0 .5rem; }
.tagline { margin: 0; font-size: 1rem; }
/* Specificity must beat `header.masthead p` above (0,1,2), which would otherwise
   win on source order and paint this white — white on #fff24d is ~1.1:1 and
   fails WCAG outright. The yellow accent pairs with dark text ONLY. */
header.masthead p.badge {
  display: inline-block; margin-bottom: .9rem; padding: .25rem .7rem;
  background: var(--yellow); color: #10201a; border-radius: 999px;
  font-size: .75rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase;
}
main { padding: 0 0 3rem; }
.callout {
  border-left: 4px solid var(--green); background: var(--surface-alt);
  padding: 1rem 1.15rem; border-radius: 0 var(--radius) var(--radius) 0; margin: 1.75rem 0;
}
.callout p:first-child { margin-top: 0; }
.callout p:last-child { margin-bottom: 0; }
blockquote {
  margin: 1.5rem 0; padding: 0 0 0 1.15rem;
  border-left: 3px solid var(--border); color: var(--muted); font-style: italic;
}
table { width: 100%; border-collapse: collapse; margin: .5rem 0 1rem; font-size: .9rem; }
caption { text-align: left; color: var(--muted); font-size: .85rem; padding-bottom: .5rem; }
th, td { text-align: left; padding: .55rem .6rem; border-bottom: 1px solid var(--border); vertical-align: top; }
th { color: var(--ink); font-weight: 600; }
.scroll { overflow-x: auto; }
.state { font-weight: 700; white-space: nowrap; }
.state.ok { color: var(--green-deep); }
.state.no { color: var(--muted); }
ul.inv { list-style: none; margin: 0; padding: 0; display: grid; gap: .4rem; grid-template-columns: repeat(auto-fill, minmax(17rem, 1fr)); }
ul.inv li { background: var(--surface-alt); border: 1px solid var(--border); border-radius: var(--radius); padding: .55rem .75rem; font-size: .875rem; }
ul.inv code { color: var(--green-deep); font-weight: 700; font-size: .8rem; }
.docs { list-style: none; margin: 0; padding: 0; display: grid; gap: .6rem; }
.docs li { border: 1px solid var(--border); border-radius: var(--radius); padding: .75rem .9rem; }
.docs strong { color: var(--ink); display: block; font-size: .95rem; }
.docs span { font-size: .875rem; }
code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; background: var(--surface-alt); padding: .1rem .3rem; border-radius: 4px; font-size: .85em; }
footer { border-top: 1px solid var(--border); padding: 1.5rem 0 2.5rem; color: var(--muted); font-size: .85rem; }
a { color: var(--green-deep); }
a:focus-visible, .skip:focus-visible { outline: 3px solid var(--green); outline-offset: 2px; }
@media (prefers-reduced-motion: reduce) { * { transition: none !important; animation: none !important; } }
</style>
</head>
<body>
<a class="skip" href="#main">Skip to content</a>

<header class="masthead">
  <div class="wrap">
    <p class="badge">Foundations only — nothing runs yet</p>
    <h1>GU-AIA — Gulu University AI Assistant</h1>
    <p class="tagline">A retrieval assistant for gu.ac.ug. Directorate of ICT Services.</p>
  </div>
</header>

<main id="main">
  <div class="wrap">

    <div class="callout">
      <p><strong>This is not the assistant.</strong> There is no assistant yet. This repository currently holds the specification, the engineering rules, the standards register and the scaffolding — no ingestion, no retrieval, no answering, no widget, no schema.</p>
      <p>A chat box with nothing behind it would be a system that <em>appears</em> to answer and cannot, which is precisely the failure this project is built against. The widget arrives with the answering layer, carrying its disclosure, its payload budget and its no-JavaScript fallback.</p>
    </div>

    <blockquote>
      The failure mode that matters is not &ldquo;the assistant could not answer.&rdquo; It is &ldquo;the assistant answered confidently and was wrong.&rdquo; A refusal costs a user thirty seconds. A fabricated fees figure costs someone a term.
      <br><small>&mdash; requirements.md, Section 0</small>
    </blockquote>

<?php if ($isDev && $checks !== []): ?>
    <h2>Environment preflight</h2>
    <div class="scroll">
      <table>
        <caption>Shown in development only. A failing database row is expected at this stage &mdash; no schema has been written.</caption>
        <thead>
          <tr><th scope="col">Check</th><th scope="col">State</th><th scope="col">Detail</th></tr>
        </thead>
        <tbody>
<?php foreach ($checks as $c): ?>
          <tr>
            <th scope="row"><?= $esc($c['label']) ?></th>
            <td class="state <?= $c['ok'] ? 'ok' : 'no' ?>"><?= $c['ok'] ? 'OK' : 'Pending' ?></td>
            <td><?= $esc($c['detail']) ?></td>
          </tr>
<?php endforeach; ?>
        </tbody>
      </table>
    </div>
<?php endif; ?>

    <h2>The twelve invariants</h2>
    <p>Each requires a named test in <code>tests/Invariant/</code> before release. <strong>All twelve are currently specified and none is implemented</strong> &mdash; an invariant without a passing test is an invariant that does not exist, whatever the code appears to do.</p>
    <ul class="inv">
<?php foreach ($invariants as $inv): ?>
      <li><code><?= $esc($inv['id']) ?></code> <?= $esc($inv['text']) ?></li>
<?php endforeach; ?>
    </ul>

    <h2>Where the work stands</h2>
    <p>Read these in order. They live in the repository, not on this page &mdash; <code>docs/</code> is deliberately not web-reachable.</p>
    <ul class="docs">
      <li><strong>requirements.md</strong><span>The engineering contract. 19 sections. Read Section 2 before writing any code.</span></li>
      <li><strong>CLAUDE.md</strong><span>13 project rules, including the AI-governance, data-access and LLM-security standards.</span></li>
      <li><strong>progress.md</strong><span>What actually exists, what is next, and every open question. Read before starting, update before stopping.</span></li>
      <li><strong>docs/standards.md</strong><span>Every control mapped to its external standard and its real state: Specified, Implemented, or Verified.</span></li>
      <li><strong>docs/ai-risk-register.md</strong><span>NIST AI RMF MAP and MEASURE artefact. Thirteen risks identified.</span></li>
      <li><strong>docs/data-protection.md</strong><span>DPPA 2019 data-flow table, lawful basis and retention per flow.</span></li>
    </ul>

    <h2>Next</h2>
    <p>The corpus schema migration &mdash; documents, chunks with their embedding blob and full-text index, the interaction log, feedback. Then the invariant tests and the evaluation harness, which Section 12 requires in the first sprint rather than at the end.</p>
    <p><strong>Phase 0 gates indexing, not schema.</strong> Nothing may be indexed until Communications and the Registry have assigned an authoritative source, an owner and a review date to each fact.</p>

  </div>
</main>

<footer>
  <div class="wrap">
    <p>Governed by <code>DICTS/POL/AI/001</code> &mdash; University Policy on the Use of Artificial Intelligence. Owned by the Directorate of ICT Services; business-owned by the Directorate of Communications with the Academic Registrar for admissions content.</p>
<?php if ($isDev): ?>
    <p>Environment: <code><?= $esc($appEnv) ?></code>. This status page is not indexed and is not intended for the public.</p>
<?php endif; ?>
  </div>
</footer>
</body>
</html>
