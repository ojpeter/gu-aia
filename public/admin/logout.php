<?php

/**
 * Console sign-out.
 *
 * POST only, with a CSRF token. A GET logout link can be triggered by any page
 * that can make the browser fetch a URL - an <img> tag on another site is
 * enough - which is a nuisance rather than a breach, but it is a nuisance with
 * no upside.
 */

declare(strict_types=1);

$console = require __DIR__ . '/_bootstrap.php';

use GuAia\Logging\AuditLog;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !$console->csrf->verify((string) ($_POST['csrf_token'] ?? ''))) {
    header('Location: index.php');
    exit;
}

if ($console->isSignedIn()) {
    $console->audit->record(
        action: AuditLog::LOGOUT,
        user: $console->user,
        ip: (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
    );
}

$_SESSION = [];
session_regenerate_id(true);
session_destroy();

header('Location: login.php');
exit;
