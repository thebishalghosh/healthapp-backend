<?php

declare(strict_types=1);

if (!function_exists('response_error')) {
	require_once dirname(__DIR__, 3) . '/bootstrap.php';
}
require_once dirname(__DIR__, 3) . '/core/auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
	response_error('METHOD_NOT_ALLOWED', 'Method not allowed.', 405);
}

[$email, $password] = auth_validate_login(auth_json_body());
$statement = database_connection()->prepare(
	'SELECT u.id, u.uuid, u.email, u.password_hash, u.status, u.deleted_at, up.first_name, up.last_name
	 FROM users u LEFT JOIN user_profiles up ON up.user_id = u.id
	 WHERE u.email = :email LIMIT 1'
);
$statement->execute(['email' => $email]);
$user = $statement->fetch();

if (!$user || !password_verify($password, $user['password_hash']) || $user['status'] !== 'active' || $user['deleted_at'] !== null) {
	response_error('INVALID_CREDENTIALS', 'Invalid email or password.', 401);
}

$database = database_connection();
[$token, $expiresAt] = auth_create_session($database, (int) $user['id']);
$update = $database->prepare('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = :id');
$update->execute(['id' => $user['id']]);

response_success([
	'user' => auth_user_data($user, $user),
	'access_token' => $token,
	'expires_at' => $expiresAt,
], 'Login successful.');
