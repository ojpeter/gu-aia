<?php

/**
 * Console sign-in. requirements.md Section 14; CLAUDE.md Rule 5.
 *
 * One message for every failure. Unknown email, wrong password, deactivated
 * account, temporary lockout and a missing second factor all render identically,
 * because staff emails at a university are guessable by construction and a
 * helpful error message turns this form into an account enumerator.
 *
 * The second-factor field is always shown, never revealed conditionally after a
 * password is accepted. Revealing it would tell an attacker which accounts hold
 * the authoriser role, which is precisely the account worth attacking.
 */

declare(strict_types=1);

$console = require __DIR__ . '/_bootstrap.php';

use GuAia\Admin\Authenticator;
use GuAia\Admin\SecretBox;
use GuAia\Logging\AuditLog;

if ($console->isSignedIn()) {
    header('Location: index.php');
    exit;
}

$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!$console->csrf->verify((string) ($_POST['csrf_token'] ?? ''))) {
        $error = 'Your session expired. Please try again.';
    } else {
        $result = (new Authenticator($console->pdo, secrets: $console->secrets))->attempt(
            (string) ($_POST['email'] ?? ''),
            (string) ($_POST['password'] ?? ''),
            ($_POST['totp'] ?? '') === '' ? null : (string) $_POST['totp'],
        );

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        if ($result->successful && $result->user !== null) {
            // Regenerate on privilege change: a session id fixed before sign-in
            // must not survive into an authenticated one.
            session_regenerate_id(true);

            $_SESSION['console_user'] = [
                'id' => $result->user->id,
                'name' => $result->user->name,
                'email' => $result->user->email,
                'role' => $result->user->role->value,
                'office_id' => $result->user->officeId,
                'two_factor' => $result->user->twoFactorSatisfied,
            ];

            $console->audit->record(action: AuditLog::LOGIN, user: $result->user, ip: $ip);

            header('Location: index.php');
            exit;
        }

        // Recorded without naming an account. The operator reason, where there
        // is one, goes to the error log for whoever has to fix it.
        $console->audit->recordFailedLogin($ip);
        if ($result->operatorReason !== null) {
            error_log('[gu-aia console] sign-in refused: ' . $result->operatorReason);
        }

        $error = $result->message();
    }
}

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
<title>Sign in — GU-AIA console</title>
<link rel="stylesheet" href="../assets/console.css">
</head>
<body class="console-login">
<main>
  <h1>GU-AIA console</h1>
  <p class="lede">For Communications and the Registry.</p>

<?php if ($error !== null): ?>
  <p class="error" role="alert"><?= $console->esc($error) ?></p>
<?php endif; ?>

  <form method="post" action="login.php">
    <?= $console->csrf->field() ?>
    <label for="email">Work email</label>
    <input type="email" id="email" name="email" required autocomplete="username"
           value="<?= $console->esc((string) ($_POST['email'] ?? '')) ?>">

    <label for="password">Password</label>
    <input type="password" id="password" name="password" required autocomplete="current-password">

    <label for="totp">Authenticator code</label>
    <input type="text" id="totp" name="totp" inputmode="numeric" autocomplete="one-time-code"
           pattern="[0-9]*" maxlength="6">
    <p class="hint">Required only for accounts that can mark a source authoritative. Leave blank otherwise.</p>

    <button type="submit">Sign in</button>
  </form>
</main>
</body>
</html>
