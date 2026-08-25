<?php

declare(strict_types=1);

if (!function_exists('response_error')) {
	require_once dirname(__DIR__, 3) . '/bootstrap.php';
}
require_once dirname(__DIR__, 3) . '/core/auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
	response_error('METHOD_NOT_ALLOWED', 'Method not allowed.', 405);
}

[$email, $password, $firstName, $lastName] = auth_validate_registration(auth_json_body());
$database = database_connection();
$existing = $database->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
$existing->execute(['email' => $email]);

if ($existing->fetch()) {
	response_error('EMAIL_ALREADY_EXISTS', 'An account with this email already exists.', 409, ['email' => 'Email is already registered.']);
}

try {
	$database->beginTransaction();
	$statement = $database->prepare(
		'INSERT INTO users (uuid, email, password_hash) VALUES (:uuid, :email, :password_hash)'
	);
	$statement->execute([
		'uuid' => auth_uuid(),
		'email' => $email,
		'password_hash' => password_hash($password, PASSWORD_DEFAULT),
	]);
	$userId = (int) $database->lastInsertId();
	$profile = $database->prepare(
		'INSERT INTO user_profiles (user_id, first_name, last_name) VALUES (:user_id, :first_name, :last_name)'
	);
	$profile->execute([
		'user_id' => $userId,
		'first_name' => $firstName,
		'last_name' => $lastName,
	]);
	$database->commit();

	response_success(['user' => [
		'id' => $userId,
		'email' => $email,
		'first_name' => $firstName,
		'last_name' => $lastName,
	]], 'Account created.', 201);
} catch (Throwable $exception) {
	if ($database->inTransaction()) {
		$database->rollBack();
	}
	if ($exception instanceof PDOException && $exception->getCode() === '23000') {
		response_error('EMAIL_ALREADY_EXISTS', 'An account with this email already exists.', 409, ['email' => 'Email is already registered.']);
	}

	throw $exception;
}
