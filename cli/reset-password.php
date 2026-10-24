<?php

declare(strict_types=1);

/**
 * Recover access when the only administrator has forgotten their password (or is locked out).
 *   php cli/reset-password.php --email=admin@example.com
 * Generates a temporary password (shown once), requires a new one at the next sign-in, unlocks the account and ends
 * that person's other sessions. Needs shell access to the server, which is the point: it is the break-glass path.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\App;
use App\Helpers\PasswordPolicy;
use App\Repositories\UserRepository;
use App\Services\AuditService;

App::boot();

$email = '';
foreach ($argv as $arg) {
    if (preg_match('/^--email=(.+)$/', $arg, $m)) {
        $email = strtolower(trim($m[1]));
    }
}
if ($email === '') {
    fwrite(STDERR, "Usage: php cli/reset-password.php --email=person@example.com\n");
    exit(1);
}

$users = new UserRepository();
$user = $users->findByEmail($email);
if ($user === null || $user['deleted_at'] !== null) {
    fwrite(STDERR, "No user with the email {$email}.\n");
    exit(1);
}

$password = PasswordPolicy::generate(16);
$users->updatePassword((int) $user['id'], PasswordPolicy::hash($password), true);
$users->clearLock((int) $user['id']);
if ((int) $user['is_active'] !== 1) {
    $users->setActive((int) $user['id'], true);
    echo "The account was deactivated; it has been reactivated.\n";
}
AuditService::record('password_reset', 'user', (int) $user['id'], null, ['email' => $email, 'via' => 'cli']);

echo "Temporary password for {$email} (shown once): {$password}\n";
echo "They must choose a new password at their next sign-in. Any other open sessions have ended.\n";
