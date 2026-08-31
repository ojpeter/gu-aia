<?php

/**
 * Console dashboard. requirements.md Section 14.
 *
 * Section 14 lists seven things the console must offer. This page carries the
 * read-only ones:
 *
 *   - the corpus browser (what is indexed, from where, when reviewed, who owns it)
 *   - conflicts detected between sources
 *   - the unanswered-question report and the feedback stream
 *   - the last evaluation run
 *
 * The write actions live on their own screens, linked from the bar and only for
 * the roles that may use them: curated.php (editor and above) and
 * authoritative.php (authoriser, second factor required). Re-indexing is queued
 * from here for the ingestion worker rather than run inside the request.
 *
 * THE UNANSWERED-QUESTION REPORT IS FIRST ON THE PAGE, DELIBERATELY. Section 13:
 * "Treat this report as a primary deliverable... it is likely to be worth more
 * to the institution than the assistant itself." Putting the corpus browser
 * first would make this a content-management screen with a report attached,
 * which is the wrong way round.
 */

declare(strict_types=1);

$console = require __DIR__ . '/_bootstrap.php';

use GuAia\Admin\Role;
use GuAia\Logging\UnansweredQuestionsReport;

$user = $console->requirePermission(Role::VIEW_REPORTS);

$weekStart = (new DateTimeImmutable('-7 days'))->format('Y-m-d 00:00:00');
$weekEnd = (new DateTimeImmutable('+1 day'))->format('Y-m-d 00:00:00');

$report = new UnansweredQuestionsReport($console->pdo);
$unanswered = $report->forWeek($weekStart, $weekEnd, 20);
$byReason = $report->byRefusalReason($weekStart, $weekEnd);

/** @return array<string, mixed>|null */
$one = static function (PDO $pdo, string $sql): ?array {
    $statement = $pdo->query($sql);
    if ($statement === false) {
        return null;
    }
    $row = $statement->fetch();

    return is_array($row) ? $row : null;
};

$corpus = $one($console->pdo, "SELECT
        (SELECT COUNT(*) FROM documents WHERE status = 'active') AS documents,
        (SELECT COUNT(*) FROM chunks WHERE status = 'active') AS chunks,
        (SELECT COUNT(*) FROM documents WHERE status = 'active'
            AND DATE_ADD(reviewed_at, INTERVAL review_interval_days DAY) < CURDATE()) AS overdue,
        (SELECT COUNT(*) FROM source_conflicts WHERE resolved_at IS NULL) AS conflicts,
        (SELECT COUNT(*) FROM documents WHERE ingest_status = 'rejected') AS rejected");

$lastEval = $one($console->pdo, 'SELECT started_at, questions_total, questions_passed, passed, notes
                          FROM eval_runs ORDER BY id DESC LIMIT 1');

$feedbackStatement = $console->pdo->query(
    "SELECT rating, COUNT(*) AS n FROM feedback WHERE redacted_at IS NULL GROUP BY rating"
);
$feedback = ['up' => 0, 'down' => 0];
foreach ($feedbackStatement === false ? [] : $feedbackStatement->fetchAll() as $row) {
    $feedback[(string) $row['rating']] = (int) $row['n'];
}

$documentsStatement = $console->pdo->query(
    "SELECT d.title, d.source_ref, d.reviewed_at, d.review_interval_days, d.is_authoritative,
            d.category_key, d.ingest_status, o.name AS office,
            DATE_ADD(d.reviewed_at, INTERVAL d.review_interval_days DAY) < CURDATE() AS overdue
       FROM documents d
       INNER JOIN offices o ON o.id = d.owning_office_id
      WHERE d.status = 'active'
      ORDER BY overdue DESC, d.reviewed_at ASC
      LIMIT 25"
);
$documents = $documentsStatement === false ? [] : $documentsStatement->fetchAll();

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>GU-AIA console</title>
<link rel="stylesheet" href="../assets/console.css">
</head>
<body>
<header class="bar">
  <strong>GU-AIA console</strong>
<?php if ($user->may(Role::EDIT_CURATED)): ?>
  <a href="curated.php">Curated answers</a>
<?php endif; ?>
<?php if ($user->may(Role::MARK_AUTHORITATIVE)): ?>
  <a href="authoritative.php">Authoritative sources</a>
<?php endif; ?>
  <span><?= $console->esc($user->name) ?> &middot; <?= $console->esc($user->role->value) ?></span>
  <form method="post" action="logout.php"><?= $console->csrf->field() ?><button type="submit">Sign out</button></form>
</header>

<main>

<?php if (isset($_GET['queued'])): ?>
  <p class="empty" role="status">Re-index queued. The ingestion worker will pick it up; nothing has changed yet.</p>
<?php endif; ?>

  <section>
    <h2>Unanswered questions, last 7 days</h2>
    <p class="lede">What the public came looking for and could not find. Section&nbsp;13 calls this a primary deliverable.</p>
<?php if ($unanswered === []): ?>
    <p class="empty">Nothing yet. The assistant has not been asked anything it could not answer &mdash; or has not been asked anything at all.</p>
<?php else: ?>
    <table>
      <thead><tr><th scope="col">Question</th><th scope="col">Times</th><th scope="col">Category</th><th scope="col">Why refused</th></tr></thead>
      <tbody>
<?php foreach ($unanswered as $row): ?>
        <tr>
          <td><?= $console->esc((string) $row['question']) ?></td>
          <td class="num"><?= (int) $row['occurrences'] ?></td>
          <td><?= $console->esc($row['category_key'] === null ? '&mdash;' : (string) $row['category_key']) ?></td>
          <td><?= $console->esc((string) $row['reasons']) ?></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
<?php endif; ?>

<?php if ($byReason !== []): ?>
    <h3>Why the assistant refused</h3>
    <p class="lede">Read this separately from the list above. A week dominated by <code>below_threshold</code> means the corpus is thin or the threshold is wrong; a week dominated by <code>individual_outcome</code> means the public is asking something the assistant will never answer, and the Registry may want a clearer page about it.</p>
    <ul class="tally">
<?php foreach ($byReason as $reason => $count): ?>
      <li><code><?= $console->esc($reason) ?></code> <span><?= (int) $count ?></span></li>
<?php endforeach; ?>
    </ul>
<?php endif; ?>
  </section>

  <section>
    <h2>Corpus</h2>
<?php if ($corpus !== null && (int) $corpus['documents'] === 0): ?>
    <p class="empty"><strong>The corpus is empty, and that is correct.</strong> Phase&nbsp;0 gates indexing: nothing may be indexed until Communications and the Registry have assigned an authoritative source, an owner and a review date to each fact. Until then the assistant refuses every question, which is the honest behaviour of a system with nothing to answer from.</p>
<?php else: ?>
    <ul class="tally">
      <li>Documents <span><?= (int) ($corpus['documents'] ?? 0) ?></span></li>
      <li>Chunks <span><?= (int) ($corpus['chunks'] ?? 0) ?></span></li>
      <li>Overdue for review <span><?= (int) ($corpus['overdue'] ?? 0) ?></span></li>
      <li>Unresolved conflicts <span><?= (int) ($corpus['conflicts'] ?? 0) ?></span></li>
      <li>Rejected at ingestion <span><?= (int) ($corpus['rejected'] ?? 0) ?></span></li>
    </ul>
<?php endif; ?>

<?php if ($documents !== []): ?>
    <table>
      <thead><tr><th scope="col">Document</th><th scope="col">Owner</th><th scope="col">Reviewed</th><th scope="col">Authoritative</th></tr></thead>
      <tbody>
<?php foreach ($documents as $doc): ?>
        <tr<?= (int) $doc['overdue'] === 1 ? ' class="overdue"' : '' ?>>
          <td><a href="<?= $console->esc((string) $doc['source_ref']) ?>" rel="noopener"><?= $console->esc((string) $doc['title']) ?></a></td>
          <td><?= $console->esc((string) $doc['office']) ?></td>
          <td><?= $console->esc((string) $doc['reviewed_at']) ?><?= (int) $doc['overdue'] === 1 ? ' <strong>overdue</strong>' : '' ?></td>
          <td><?= (int) $doc['is_authoritative'] === 1 ? 'yes' : '&mdash;' ?></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
<?php endif; ?>
  </section>

  <section>
    <h2>Last evaluation run</h2>
<?php if ($lastEval === null): ?>
    <p class="empty">Never run. <code>php bin/evaluate.php</code></p>
<?php else: ?>
    <ul class="tally">
      <li>Run at <span><?= $console->esc((string) $lastEval['started_at']) ?></span></li>
      <li>Questions evaluated and passed <span><?= (int) $lastEval['questions_passed'] ?> of <?= (int) $lastEval['questions_total'] ?></span></li>
      <li>Met its thresholds <span><?= (int) $lastEval['passed'] === 1 ? 'yes' : 'no' ?></span></li>
    </ul>
    <p class="lede"><?= $console->esc((string) $lastEval['notes']) ?></p>
<?php endif; ?>
  </section>

  <section>
    <h2>Feedback</h2>
    <ul class="tally">
      <li>Helpful <span><?= $feedback['up'] ?></span></li>
      <li>Not helpful <span><?= $feedback['down'] ?></span></li>
    </ul>
  </section>

<?php if ($user->may(Role::TRIGGER_REINDEX)): ?>
  <section>
    <h2>Re-index</h2>
    <p class="lede">Queues a run for the ingestion worker rather than crawling inside this request. A full re-index is minutes of work, and a browser timing out halfway would leave the corpus half-superseded with nobody able to tell.</p>
    <p class="lede"><strong>Section&nbsp;12 requires the evaluation harness to be re-run and recorded after every re-index</strong> &mdash; content changes break retrieval as surely as code does.</p>
    <form method="post" action="reindex.php">
      <?= $console->csrf->field() ?>
      <button type="submit">Queue a full re-index</button>
    </form>
  </section>
<?php endif; ?>

</main>
</body>
</html>
