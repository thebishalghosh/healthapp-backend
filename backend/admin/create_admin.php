<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

require_once __DIR__ . '/includes/auth.php';

$email = trim((string) ($argv[1] ?? ''));
$name = trim((string) ($argv[2] ?? 'Administrator'));
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '') {
	fwrite(STDERR, "Usage: php admin/create_admin.php admin@example.com [name]\n");
	exit(1);
}

fwrite(STDOUT, 'Password: ');
$password = trim((string) fgets(STDIN));
if (strlen($password) < 12) {
	fwrite(STDERR, "Password must be at least 12 characters.\n");
	exit(1);
}

$statement = database_connection()->prepare(
	'INSERT INTO admin_users (email, password_hash, name) VALUES (:email, :password_hash, :name) ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), name = VALUES(name), updated_at = CURRENT_TIMESTAMP'
);
$statement->execute([
	'email' => strtolower($email),
	'password_hash' => password_hash($password, PASSWORD_DEFAULT),
	'name' => $name,
]);
fwrite(STDOUT, "Admin account created or updated.\n");