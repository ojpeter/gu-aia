<?php

/**
 * Marking a source authoritative for a category. requirements.md Sections 5.2, 14.
 *
 * The highest-consequence screen in the console. It does not change what any
 * document says; it changes which one the assistant quotes when two disagree —
 * which, for fees, decides the figure a member of the public is shown.
 *
 * So the screen is built to make the consequence visible BEFORE the click:
 * every category shows what is currently authoritative, and the confirmation
 * names what is about to be displaced. A change of this weight should never be
 * something somebody discovers they made.
 *
 * Requires the authoriser role AND a second factor satisfied this session. The
 * page guard checks it, and AuthoritativeMarker checks it again — a privileged
 * action that trusts its caller is one refactor away from being unguarded.
 */

declare(strict_types=1);

$console = require __DIR__ . '/_bootstrap.php';

use GuAia\Admin\AuthoritativeMarker;
use GuAia\Admin\Role;
use GuAia\Logging\AuditLog;

$user = $console->requirePermission(Role::MARK_AUTHORITATIVE);

$marker = new AuthoritativeMarker($console->pdo);
$notice = null;
$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!$console->csrf->verify((string) ($_POST['csrf_token'] ?? ''))) {
        $error = 'Your session expired. Please try again.';
    } else {
        $documentId = (int) ($_POST['document_id'] ?? 0);
        $categoryKey = trim((string) ($_POST['category_key'] ?? ''));

        try {
            $console->pdo->beginTransaction();

            $displaced = $marker->mark($user, $documentId, $categoryKey);

            $console->audit->record(
                action: AuditLog::MARK_AUTHORITATIVE,
                user: $user,
                entityType: 'document',
                entityId: (string) $documentId,
                // Both sides named: a year from now, "why did the assistant quote
                // the wrong figure" is answered by this line.
                detail: sprintf(
                    'Marked document %d authoritative for %s%s',
                    $documentId,
                    $categoryKey,
                    $displaced['previousDocumentId'] === null
                        ? ' (nothing displaced)'
                        : sprintf(
                            ', displacing document %d (%s)',
                            $displaced['previousDocumentId'],
                            (string) $displaced['previousTitle']
                        )
                ),
                ip: $console->clientIp,
            );

            $console->pdo->commit();

            $notice = $displaced['previousDocumentId'] === null
                ? 'Marked authoritative. Nothing was displaced.'
                : sprintf(
                    'Marked authoritative. "%s" is no longer the authoritative source for this category.',
                    (string) $displaced['previousTitle']
                );
        } catch (Throwable $e) {
            if ($console->pdo->inTransaction()) {
                $console->pdo->rollBack();
            }
            error_log('[gu-aia console] mark authoritative failed: ' . $e->getMessage());
            $error = $e instanceof RuntimeException
                ? $e->getMessage()
                : 'Could not change the authoritative source. Nothing was changed.';
        }
    }
}

$categoriesStatement = $console->pdo->query(
    'SELECT category_key, label FROM categories ORDER BY sort_order'
);
$categories = $categoriesStatement === false ? [] : $categoriesStatement->fetchAll();

$documentsStatement = $console->pdo->query(
    "SELECT id, title, source_ref, category_key, reviewed_at, is_authoritative,
            DATE_ADD(reviewed_at, INTERVAL review_interval_days DAY) < CURDATE() AS overdue
       FROM documents
      WHERE status = 'active' AND category_key IS NOT NULL
      ORDER BY category_key, is_authoritative DESC, title"
);
$documents = $documentsStatement === false ? [] : $documentsStatement->fetchAll();

/** @var array<string, list<array<string, mixed>>> $byCategory */
$byCategory = [];
foreach ($documents as $document) {
    $byCategory[(string) $document['category_key']][] = $document;
}

$conflictsStatement = $console->pdo->query(
    "SELECT c.id, c.category_key, c.detail, c.detected_at,
            a.title AS title_a, b.title AS title_b
       FROM source_conflicts c
       INNER JOIN documents a ON a.id = c.document_a_id
       INNER JOIN documents b ON b.id = c.document_b_id
      WHERE c.resolved_at IS NULL
      ORDER BY c.detected_at DESC
      LIMIT 25"
);
$conflicts = $conflictsStatement === false ? [] : $conflictsStatement->fetchAll();

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
<title>Authoritative sources — GU-AIA console</title>
<link rel="stylesheet" href="../assets/console.css">
</head>
<body>
<header class="bar">
  <strong>GU-AIA console</strong>
  <a href="index.php">Dashboard</a>
  <a href="curated.php">Curated answers</a>
  <span><?= $console->esc($user->name) ?> &middot; <?= $console->esc($user->role->value) ?></span>
  <form method="post" action="logout.php"><?= $console->csrf->field() ?><button type="submit">Sign out</button></form>
</header>

<main>

<?php if ($notice !== null): ?>
  <p class="empty" role="status"><?= $console->esc($notice) ?></p>
<?php endif; ?>
<?php if ($error !== null): ?>
  <p class="error" role="alert"><?= $console->esc($error) ?></p>
<?php endif; ?>

  <section>
    <h2>Authoritative sources</h2>
    <p class="lede">When two published sources disagree, the one marked authoritative for that category is the one the assistant quotes. For fees, that decides the figure a member of the public is shown.</p>
    <p class="lede"><strong>Exactly one source per category.</strong> Marking a new one displaces the current one automatically &mdash; two would make the outcome depend on retrieval order, which is the ambiguity this flag exists to remove.</p>
  </section>

<?php if ($conflicts !== []): ?>
  <section>
    <h2>Conflicts detected</h2>
    <p class="lede">Section&nbsp;5.2: a conflict is a <em>content defect to be fixed</em>, not a retrieval problem to be tuned around. Marking one source authoritative decides which the assistant quotes; it does not make the other one correct.</p>
    <table>
      <thead><tr><th scope="col">Category</th><th scope="col">Sources</th><th scope="col">Detail</th><th scope="col">Detected</th></tr></thead>
      <tbody>
<?php foreach ($conflicts as $conflict): ?>
        <tr class="overdue">
          <td><?= $console->esc((string) $conflict['category_key']) ?></td>
          <td><?= $console->esc((string) $conflict['title_a']) ?> vs <?= $console->esc((string) $conflict['title_b']) ?></td>
          <td><?= $console->esc((string) $conflict['detail']) ?></td>
          <td><?= $console->esc((string) $conflict['detected_at']) ?></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </section>
<?php endif; ?>

<?php foreach ($categories as $category): ?>
<?php $key = (string) $category['category_key']; $inCategory = $byCategory[$key] ?? []; ?>
  <section>
    <h2><?= $console->esc((string) $category['label']) ?></h2>
<?php if ($inCategory === []): ?>
    <p class="empty">No documents filed under this category yet.</p>
<?php else: ?>
    <table>
      <thead><tr><th scope="col">Document</th><th scope="col">Reviewed</th><th scope="col">Authoritative</th><th scope="col"></th></tr></thead>
      <tbody>
<?php foreach ($inCategory as $document): ?>
        <tr<?= (int) $document['overdue'] === 1 ? ' class="overdue"' : '' ?>>
          <td><a href="<?= $console->esc((string) $document['source_ref']) ?>" rel="noopener"><?= $console->esc((string) $document['title']) ?></a></td>
          <td><?= $console->esc((string) $document['reviewed_at']) ?><?= (int) $document['overdue'] === 1 ? ' <strong>overdue</strong>' : '' ?></td>
          <td><?= (int) $document['is_authoritative'] === 1 ? '<strong>yes</strong>' : '&mdash;' ?></td>
          <td>
<?php if ((int) $document['is_authoritative'] !== 1): ?>
            <form method="post" action="authoritative.php">
              <?= $console->csrf->field() ?>
              <input type="hidden" name="document_id" value="<?= (int) $document['id'] ?>">
              <input type="hidden" name="category_key" value="<?= $console->esc($key) ?>">
              <button type="submit">Make authoritative</button>
            </form>
<?php endif; ?>
          </td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
<?php endif; ?>
  </section>
<?php endforeach; ?>

</main>
</body>
</html>
