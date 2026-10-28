<?php

declare(strict_types=1);

/**
 * Give the default roles (Manager, Staff) the default permissions that were added after they were created.
 *   php cli/sync-roles.php            show what is missing
 *   php cli/sync-roles.php --apply    grant it
 * Only adds permissions; never removes one. Safe to run again. Admin always holds everything (php cli/seed.php).
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\App;
use App\Database\Seeder;
use App\Services\AuditService;

App::boot();
$seeder = new Seeder();
$apply = in_array('--apply', $argv, true);
$todo = $apply ? $seeder->syncDefaultRoles() : $seeder->missingDefaults();

if ($todo === []) {
    echo "Every default role already holds its default permissions.\n";
    exit(0);
}
foreach ($todo as $role => $keys) {
    echo ($apply ? 'Granted to ' : 'Missing for ') . $role . ': ' . implode(', ', $keys) . PHP_EOL;
}
if ($apply) {
    AuditService::record('role_permissions_changed', 'role', null, null, ['granted' => $todo]);
    echo "Done. Review them under Roles & Permissions.\n";
} else {
    echo "\nRun again with --apply to grant them.\n";
}
