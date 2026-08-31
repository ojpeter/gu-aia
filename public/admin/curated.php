<?php

/**
 * Curated question-and-answer entries. requirements.md Sections 5.1, 14.
 *
 * "Authored in the admin console for facts that live on no page."
 *
 * This is the ONLY content the console may author. Section 14: "No content
 * editing capability beyond curated entries. The website remains the source of
 * truth; the console must never become a second place where facts live."
 *
 * The form demands an owning office, a review date and a review interval before
 * it will save anything, which is not bureaucracy — it is INV-11, and it is also
 * exactly what the Phase 0 content audit produces for each fact. Authoring here
 * satisfies that gate per entry rather than working around it.
 *
 * Editing supersedes rather than overwrites (INV-12), so a past answer stays
 * reconstructible. The page says so where the person editing can see it, because
 * a rule nobody is told about is a rule that surprises somebody later.
 */

declare(strict_types=1);

$console = require __DIR__ . '/_bootstrap.php';

use GuAia\Admin\CuratedEntryInput;
use GuAia\Admin\CuratedEntryWriter;
use GuAia\Admin\Role;
use GuAia\Ingestion\HashingEmbedder;
use GuAia\Logging\AuditLog;

$user = $console->requirePermission(Role::EDIT_CURATED);

$errors = [];
$notice = null;
$submitted = $_POST;
$editing = (int) ($_GET['supersedes'] ?? $_POST['supersedes'] ?? 0);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!$console->csrf->verify((string) ($_POST['csrf_token'] ?? ''))) {
        $errors['form'] = 'Your session expired. Please submit again.';
    } else {
        [$input, $errors] = CuratedEntryInput::fromRequest($console->pdo, $_POST);

        if ($input !== null) {
            $writer = new CuratedEntryWriter($console->pdo, new HashingEmbedder());

            $console->pdo->beginTransaction();
            try {
                $entryId = $writer->save($user, $input, $editing > 0 ? $editing : null);

                $console->audit->record(
                    action: AuditLog::CURATED_ENTRY_SAVED,
                    user: $user,
                    entityType: 'curated_entry',
                    entityId: (string) $entryId,
                    detail: $editing > 0
                        ? sprintf('Superseded entry %d with %d: %s', $editing, $entryId, $input->question)
                        : sprintf('Created entry %d: %s', $entryId, $input->question),
                    ip: $console->clientIp,
                );

                $console->pdo->commit();

                $notice = $editing > 0
                    ? 'Saved. The previous version was superseded, not deleted, so any answer already given from it stays reconstructible.'
                    : 'Saved and indexed.';
                $submitted = [];
                $editing = 0;
            } catch (Throwable $e) {
                if ($console->pdo->inTransaction()) {
                    $console->pdo->rollBack();
                }
                error_log('[gu-aia console] curated save failed: ' . $e->getMessage());
                $errors['form'] = 'Could not save that entry. Nothing was changed.';
            }
        }
    }
}

$offices = $console->pdo->query('SELECT id, name FROM offices WHERE is_active = 1 ORDER BY name');
$offices = $offices === false ? [] : $offices->fetchAll();

$categories = $console->pdo->query('SELECT category_key, label FROM categories ORDER BY sort_order');
$categories = $categories === false ? [] : $categories->fetchAll();

$entriesStatement = $console->pdo->query(
    "SELECT e.id, e.question, e.answer, e.category_key, d.reviewed_at,
            d.review_interval_days, o.name AS office,
            DATE_ADD(d.reviewed_at, INTERVAL d.review_interval_days DAY) < CURDATE() AS overdue
       FROM curated_entries e
       INNER JOIN documents d ON d.id = e.document_id
       INNER JOIN offices o ON o.id = d.owning_office_id
      WHERE e.status = 'active'
      ORDER BY overdue DESC, e.updated_at DESC
      LIMIT 50"
);
$entries = $entriesStatement === false ? [] : $entriesStatement->fetchAll();

$value = static fn (string $field, string $fallback = ''): string
    => (string) ($submitted[$field] ?? $fallback);

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
<title>Curated answers — GU-AIA console</title>
<link rel="stylesheet" href="../assets/console.css">
</head>
<body>
<header class="bar">
  <strong>GU-AIA console</strong>
  <a href="index.php">Dashboard</a>
  <span><?= $console->esc($user->name) ?> &middot; <?= $console->esc($user->role->value) ?></span>
  <form method="post" action="logout.php"><?= $console->csrf->field() ?><button type="submit">Sign out</button></form>
</header>

<main>

<?php if ($notice !== null): ?>
  <p class="empty" role="status"><?= $console->esc($notice) ?></p>
<?php endif; ?>
<?php if (isset($errors['form'])): ?>
  <p class="error" role="alert"><?= $console->esc($errors['form']) ?></p>
<?php endif; ?>

  <section>
    <h2><?= $editing > 0 ? 'Replace an answer' : 'Add a curated answer' ?></h2>
    <p class="lede">For facts that live on no page. This is the only content the console may author &mdash; the website stays the source of truth for everything else.</p>
<?php if ($editing > 0): ?>
    <p class="empty">Saving will <strong>supersede</strong> the existing version rather than overwrite it, so any answer already given from it can still be reconstructed if somebody asks.</p>
<?php endif; ?>

    <form method="post" action="curated.php">
      <?= $console->csrf->field() ?>
      <input type="hidden" name="supersedes" value="<?= (int) $editing ?>">

      <label for="question">Question</label>
      <input type="text" id="question" name="question" maxlength="500" required
             value="<?= $console->esc($value('question')) ?>">
<?php if (isset($errors['question'])): ?><p class="error"><?= $console->esc($errors['question']) ?></p><?php endif; ?>

      <label for="answer">Answer</label>
      <textarea id="answer" name="answer" rows="5" maxlength="5000" required><?= $console->esc($value('answer')) ?></textarea>
      <p class="hint">Write what the University would publish. The assistant returns this text and cites it; it does not rewrite it.</p>
<?php if (isset($errors['answer'])): ?><p class="error"><?= $console->esc($errors['answer']) ?></p><?php endif; ?>

      <label for="owning_office_id">Owning office</label>
      <select id="owning_office_id" name="owning_office_id" required>
        <option value="">Choose an office</option>
<?php foreach ($offices as $office): ?>
        <option value="<?= (int) $office['id'] ?>"<?= $value('owning_office_id') === (string) $office['id'] ? ' selected' : '' ?>>
          <?= $console->esc((string) $office['name']) ?>
        </option>
<?php endforeach; ?>
      </select>
      <p class="hint">Who is accountable for this being right, and who is told when it goes stale.</p>
<?php if (isset($errors['owning_office_id'])): ?><p class="error"><?= $console->esc($errors['owning_office_id']) ?></p><?php endif; ?>

      <label for="category_key">Category</label>
      <select id="category_key" name="category_key">
        <option value="">None</option>
<?php foreach ($categories as $category): ?>
        <option value="<?= $console->esc((string) $category['category_key']) ?>"<?= $value('category_key') === (string) $category['category_key'] ? ' selected' : '' ?>>
          <?= $console->esc((string) $category['label']) ?>
        </option>
<?php endforeach; ?>
      </select>
      <p class="hint">Retrieval filters on this, which is what stops a fees question being answered from something else.</p>
<?php if (isset($errors['category_key'])): ?><p class="error"><?= $console->esc($errors['category_key']) ?></p><?php endif; ?>

      <label for="reviewed_at">Last reviewed</label>
      <input type="date" id="reviewed_at" name="reviewed_at" required
             max="<?= date('Y-m-d') ?>" value="<?= $console->esc($value('reviewed_at', date('Y-m-d'))) ?>">
      <p class="hint">Shown to the public beside the answer. Every answer carries the date its source was last checked.</p>
<?php if (isset($errors['reviewed_at'])): ?><p class="error"><?= $console->esc($errors['reviewed_at']) ?></p><?php endif; ?>

      <label for="review_interval_days">Review every (days)</label>
      <input type="number" id="review_interval_days" name="review_interval_days" min="1" max="1825" required
             value="<?= $console->esc($value('review_interval_days', '365')) ?>">
      <p class="hint">After this, answers drawn from it carry a visible caution and your office is told.</p>
<?php if (isset($errors['review_interval_days'])): ?><p class="error"><?= $console->esc($errors['review_interval_days']) ?></p><?php endif; ?>

      <button type="submit"><?= $editing > 0 ? 'Replace' : 'Save' ?></button>
    </form>
  </section>

  <section>
    <h2>Current curated answers</h2>
<?php if ($entries === []): ?>
    <p class="empty">None yet.</p>
<?php else: ?>
    <table>
      <thead><tr><th scope="col">Question</th><th scope="col">Owner</th><th scope="col">Reviewed</th><th scope="col"></th></tr></thead>
      <tbody>
<?php foreach ($entries as $entry): ?>
        <tr<?= (int) $entry['overdue'] === 1 ? ' class="overdue"' : '' ?>>
          <td><?= $console->esc((string) $entry['question']) ?></td>
          <td><?= $console->esc((string) $entry['office']) ?></td>
          <td><?= $console->esc((string) $entry['reviewed_at']) ?><?= (int) $entry['overdue'] === 1 ? ' <strong>overdue</strong>' : '' ?></td>
          <td><a href="curated.php?supersedes=<?= (int) $entry['id'] ?>">Replace</a></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
<?php endif; ?>
  </section>

</main>
</body>
</html>
