<?php

declare(strict_types=1);

/**
 * Create a user account. No default credentials ship with the app.
 *
 *   php cli/create-user.php --username=maria.lopez --name="Maria Lopez" [--email=maria@example.com] [--role=Admin|Manager|Staff] [--password=...] [--force-change]
 *
 * The role defaults to Staff (cli/create-admin.php is a shortcut for --role=Admin).
 * Without --password a strong random one is generated and printed once.
 * --force-change makes the user choose a new password at first sign-in.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\App;
use App\Core\Validator;
use App\Helpers\PasswordPolicy;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;
use App\Services\AuditService;

App::boot();

$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
        $options[$m[1]] = $m[2] ?? true;
    }
}

$generated = !isset($options['password']) || !is_string($options['password']);
$password = $generated ? PasswordPolicy::generate(20) : $options['password'];

$input = [
    'name' => is_string($options['name'] ?? null) ? $options['name'] : 'Administrator',
    'username' => is_string($options['username'] ?? null) ? strtolower(trim($options['username'])) : '',
    'email' => is_string($options['email'] ?? null) && trim($options['email']) !== '' ? strtolower(trim($options['email'])) : null,
    'password' => $password,
];

$validator = Validator::make($input, [
    'name' => 'required|max:120',
    'username' => 'required|username|unique:users,username',
    'email' => 'nullable|email|max:190|unique:users,email',
    'password' => 'required|password',
]);

if ($validator->fails()) {
    foreach ($validator->errors() as $messages) {
        fwrite(STDERR, '  - ' . $messages[0] . PHP_EOL);
    }
    fwrite(STDERR, "Usage: php cli/create-user.php --username=maria.lopez --name=\"Maria Lopez\" [--email=maria@example.com] [--role=Admin|Manager|Staff] [--password=...] [--force-change]\n");
    exit(1);
}

$roleName = is_string($options['role'] ?? null) ? $options['role'] : 'Staff';
$role = (new RoleRepository())->findByName($roleName);
if ($role === null) {
    fwrite(STDERR, "Role \"{$roleName}\" does not exist. Run: php cli/migrate.php && php cli/seed.php\n");
    exit(1);
}

$id = (new UserRepository())->create([
    'name' => $input['name'],
    'username' => $input['username'],
    'email' => $input['email'],
    'password_hash' => PasswordPolicy::hash($password),
    'role_id' => (int) $role['id'],
    'must_change_password' => isset($options['force-change']) ? 1 : 0,
]);

AuditService::record('user_created', 'user', $id, null, ['username' => $input['username'], 'email' => $input['email'], 'role' => $roleName, 'via' => 'cli']);

echo "{$roleName} created: {$input['username']}\n";
if ($generated) {
    echo "Generated password (shown once): {$password}\n";
}
if (isset($options['force-change'])) {
    echo "The user must change this password at first sign-in.\n";
}
