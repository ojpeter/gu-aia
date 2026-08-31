<?php

/**
 * Triggers a re-index. requirements.md Section 14.
 *
 * "Trigger a re-index of a single document or the whole corpus."
 *
 * The console does NOT run the crawl in the request. A full re-index fetches
 * every source, extracts, chunks and embeds - minutes of work, and a browser
 * that times out halfway through would leave the corpus half-superseded with
 * nobody able to tell. Instead this records a QUEUED ingest run, which the
 * worker picks up (bin/ingest.php), and the console shows its progress.
 *
 * That also keeps the boundary honest: ingestion writes the corpus under the
 * ingestion account, not under the console's session.
 */

declare(strict_types=1);

$console = require __DIR__ . '/_bootstrap.php';

use GuAia\Admin\Role;
use GuAia\Logging\AuditLog;

$user = $console->requirePermission(Role::TRIGGER_REINDEX);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !$console->csrf->verify((string) ($_POST['csrf_token'] ?? ''))) {
    header('Location: index.php');
    exit;
}

$documentId = (int) ($_POST['document_id'] ?? 0);
$scope = $documentId > 0 ? 'document' : 'full';

try {
    $console->pdo->beginTransaction();

    $statement = $console->pdo->prepare(
        "INSERT INTO ingest_runs (scope, document_id, triggered_by, started_at, status)
         VALUES (:scope, :document_id, :triggered_by, NOW(), 'running')"
    );
    $statement->execute([
        'scope' => $scope,
        'document_id' => $documentId > 0 ? $documentId : null,
        'triggered_by' => $user->id,
    ]);
    $runId = (int) $console->pdo->lastInsertId();

    $console->audit->record(
        action: AuditLog::REINDEX_TRIGGERED,
        user: $user,
        entityType: 'ingest_run',
        entityId: (string) $runId,
        detail: $scope === 'full'
            ? 'Queued a full corpus re-index'
            : sprintf('Queued a re-index of document %d', $documentId),
        ip: $console->clientIp,
    );

    $console->pdo->commit();
} catch (Throwable $e) {
    if ($console->pdo->inTransaction()) {
        $console->pdo->rollBack();
    }
    error_log('[gu-aia console] reindex trigger failed: ' . $e->getMessage());
}

header('Location: index.php?queued=1');
exit;
