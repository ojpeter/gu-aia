<?php

declare(strict_types=1);

namespace GuAia\Admin;

/**
 * Console roles and what each may do. requirements.md Section 14; CLAUDE.md Rule 5.
 *
 * "The console is for Communications and the Registry, not for engineers."
 *
 * Three roles, and the ladder between them is deliberately steep at the top.
 * `markAuthoritative` is the highest-consequence action in the whole system: it
 * decides which source wins when two conflict (Section 5.2), which means it
 * decides which fees figure a member of the public is shown. It is the only
 * permission that requires 2FA.
 *
 * Permissions are checked PER ACTION, not once at login. A session that was
 * authorised to read the unanswered-question report is not thereby authorised to
 * re-index the corpus, and the check that matters is the one at the point of the
 * action.
 *
 * NOTE WHAT IS ABSENT. There is no permission to edit corpus content, because
 * Section 14 forbids it: "No content editing capability beyond curated entries.
 * The website remains the source of truth; the console must never become a
 * second place where facts live." Adding one here would be the mechanism by
 * which that happens.
 */
enum Role: string
{
    /** Sees the corpus browser and the reports. Changes nothing. */
    case Reader = 'reader';

    /** Additionally authors curated Q&A entries and triggers a re-index. */
    case Editor = 'editor';

    /** Additionally marks a document authoritative for a category. Requires 2FA. */
    case Authoriser = 'authoriser';

    public const VIEW_REPORTS = 'view_reports';
    public const VIEW_CORPUS = 'view_corpus';
    public const EDIT_CURATED = 'edit_curated';
    public const TRIGGER_REINDEX = 'trigger_reindex';
    public const RESOLVE_CONFLICT = 'resolve_conflict';
    public const MARK_AUTHORITATIVE = 'mark_authoritative';

    /** @return list<string> */
    public function permissions(): array
    {
        return match ($this) {
            self::Reader => [
                self::VIEW_REPORTS,
                self::VIEW_CORPUS,
            ],
            self::Editor => [
                self::VIEW_REPORTS,
                self::VIEW_CORPUS,
                self::EDIT_CURATED,
                self::TRIGGER_REINDEX,
                self::RESOLVE_CONFLICT,
            ],
            self::Authoriser => [
                self::VIEW_REPORTS,
                self::VIEW_CORPUS,
                self::EDIT_CURATED,
                self::TRIGGER_REINDEX,
                self::RESOLVE_CONFLICT,
                self::MARK_AUTHORITATIVE,
            ],
        };
    }

    public function may(string $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    /**
     * Permissions that cannot be exercised without a second factor.
     *
     * CLAUDE.md Rule 5: "2FA for any role that can mark a document
     * authoritative." A compromised console account can change what the
     * University appears to say in public, and this is the action that does it
     * most directly.
     *
     * @return list<string>
     */
    public static function permissionsRequiringTwoFactor(): array
    {
        return [self::MARK_AUTHORITATIVE];
    }

    public static function requiresTwoFactor(string $permission): bool
    {
        return in_array($permission, self::permissionsRequiringTwoFactor(), true);
    }
}
