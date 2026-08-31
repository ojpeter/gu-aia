<?php

declare(strict_types=1);

namespace GuAia\Logging;

use GuAia\Admin\AuthenticatedUser;
use PDO;

/**
 * The append-only admin audit log. CLAUDE.md Rule 5.
 *
 * "Every admin publish/edit/delete/permission-change action is written to an
 *  append-only audit log — who, what, when. This is what makes 'a wrong fact got
 *  published' or 'who deleted this page' answerable after the fact."
 *
 * APPEND-ONLY IS ENFORCED BY GRANT, NOT BY THIS CLASS.
 *
 * The application account holds SELECT and INSERT on `admin_audit_log` and
 * neither UPDATE nor DELETE (db/accounts.sql, proven by bin/verify_grants.php).
 * So this class has no update method not because it politely declines to offer
 * one, but because the server would refuse it. That distinction matters: a
 * convention can be worked around by the next person in a hurry; a missing
 * privilege cannot.
 *
 * The most important thing recorded here is `mark_authoritative`. That action
 * decides which source wins when two conflict, which means it decides which fees
 * figure a member of the public is shown. When somebody asks in a year's time
 * why the assistant quoted the wrong number, this table is the answer.
 */
final class AuditLog
{
    public const LOGIN = 'login';
    public const LOGIN_FAILED = 'login_failed';
    public const LOGOUT = 'logout';
    public const MARK_AUTHORITATIVE = 'mark_authoritative';
    public const CURATED_ENTRY_SAVED = 'curated_entry_saved';
    public const REINDEX_TRIGGERED = 'reindex_triggered';
    public const CONFLICT_RESOLVED = 'conflict_resolved';
    public const PERMISSION_DENIED = 'permission_denied';

    public function __construct(
        private readonly PDO $pdo,
        private readonly IdentifierHasher $hasher,
    ) {
    }

    public function record(
        string $action,
        ?AuthenticatedUser $user = null,
        ?string $entityType = null,
        ?string $entityId = null,
        ?string $detail = null,
        ?string $ip = null,
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO admin_audit_log
                (admin_user_id, action, entity_type, entity_id, detail, ip_hash)
             VALUES (:admin_user_id, :action, :entity_type, :entity_id, :detail, :ip_hash)'
        );

        $statement->execute([
            // Null for system and worker actions, which is why the column is
            // nullable: an ingestion run has no user, and pretending it does
            // would attribute machine actions to a person.
            'admin_user_id' => $user?->id,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'detail' => $detail === null ? null : mb_substr($detail, 0, 2000),
            'ip_hash' => $this->hasher->hashOrNull($ip),
        ]);
    }

    /**
     * A failed sign-in, recorded WITHOUT a user id even when the email matched
     * a real account.
     *
     * Attributing a failure to an account would put "somebody tried to sign in
     * as this named member of staff and got it wrong" into a table that other
     * staff can read, on the strength of an attempt anyone could make with a
     * guessed email. The attempt is still recorded, because a spike in these
     * is exactly what an operator needs to see; it is the attribution that is
     * withheld.
     */
    public function recordFailedLogin(?string $ip = null): void
    {
        $this->record(action: self::LOGIN_FAILED, ip: $ip);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 50): array
    {
        $limit = max(1, min(500, $limit));

        $statement = $this->pdo->query(
            "SELECT a.occurred_at, a.action, a.entity_type, a.entity_id, a.detail, u.name AS actor
               FROM admin_audit_log a
               LEFT JOIN admin_users u ON u.id = a.admin_user_id
              ORDER BY a.id DESC
              LIMIT {$limit}"
        );

        if ($statement === false) {
            return [];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll();

        return $rows;
    }
}
