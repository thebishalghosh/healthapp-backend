<?php

declare(strict_types=1);

function auth_json_body(): array
{
	$rawBody = file_get_contents('php://input');
	$body = json_decode($rawBody ?: '', true);

	if (!is_array($body)) {
		response_error('INVALID_JSON', 'Request body must contain valid JSON.', 400);
	}

	return $body;
}

function auth_normalize_email(mixed $email): string
{
	return is_string($email) ? strtolower(trim($email)) : '';
}

function auth_validate_registration(array $body): array
{
	$email = auth_normalize_email($body['email'] ?? null);
	$password = $body['password'] ?? null;
	$firstName = is_string($body['first_name'] ?? null) ? trim($body['first_name']) : '';
	$lastName = is_string($body['last_name'] ?? null) ? trim($body['last_name']) : '';
	$fields = [];

	if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 191) {
		$fields['email'] = 'Enter a valid email address.';
	}

	if (!is_string($password) || strlen($password) < 8 || strlen($password) > 72 || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
		$fields['password'] = 'Password must be 8 to 72 characters and include uppercase, lowercase, and numeric characters.';
	}

	if ($firstName === '' || strlen($firstName) > 100 || preg_match('/[\x00-\x1F\x7F]/', $firstName)) {
		$fields['first_name'] = 'First name must be 1 to 100 characters.';
	}

	if ($lastName === '' || strlen($lastName) > 100 || preg_match('/[\x00-\x1F\x7F]/', $lastName)) {
		$fields['last_name'] = 'Last name must be 1 to 100 characters.';
	}

	if ($fields !== []) {
		response_validation_error($fields);
	}

	return [$email, $password, $firstName, $lastName];
}

function auth_validate_login(array $body): array
{
	$email = auth_normalize_email($body['email'] ?? null);
	$password = $body['password'] ?? null;

	if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !is_string($password) || $password === '') {
		response_error('INVALID_CREDENTIALS', 'Invalid email or password.', 401);
	}

	return [$email, $password];
}

function auth_user_data(array $user, ?array $profile = null): array
{
	return [
		'id' => (int) $user['id'],
		'email' => $user['email'],
		'first_name' => $profile['first_name'] ?? null,
		'last_name' => $profile['last_name'] ?? null,
	];
}

function auth_create_session(PDO $database, int $userId): array
{
	$token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
	$expiresAt = gmdate('Y-m-d H:i:s', time() + 60 * 60 * 24 * 30);
	$statement = $database->prepare(
		'INSERT INTO user_sessions (user_id, token_hash, ip_address, user_agent, expires_at) VALUES (:user_id, :token_hash, :ip_address, :user_agent, :expires_at)'
	);
	$statement->execute([
		'user_id' => $userId,
		'token_hash' => hash('sha256', $token),
		'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
		'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
		'expires_at' => $expiresAt,
	]);

	return [$token, $expiresAt];
}

function auth_bearer_token(): string
{
	$header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

	if (!is_string($header) || !preg_match('/^Bearer\s+([A-Za-z0-9_-]{20,200})$/', $header, $matches)) {
		response_error('UNAUTHENTICATED', 'Authentication is required.', 401);
	}

	return $matches[1];
}

function authenticated_user(): array
{
	$token = auth_bearer_token();
	$statement = database_connection()->prepare(
		'SELECT u.id, u.email, u.status, up.first_name, up.last_name
		 FROM user_sessions s
		 INNER JOIN users u ON u.id = s.user_id
		 LEFT JOIN user_profiles up ON up.user_id = u.id
		 WHERE s.token_hash = :token_hash AND s.expires_at > UTC_TIMESTAMP()
		   AND u.status = :status AND u.deleted_at IS NULL
		 LIMIT 1'
	);
	$statement->execute(['token_hash' => hash('sha256', $token), 'status' => 'active']);
	$user = $statement->fetch();

	if (!$user) {
		response_error('UNAUTHENTICATED', 'Authentication is required.', 401);
	}

	$update = database_connection()->prepare('UPDATE user_sessions SET last_used_at = UTC_TIMESTAMP() WHERE token_hash = :token_hash');
	$update->execute(['token_hash' => hash('sha256', $token)]);

	return $user;
}

function auth_uuid(): string
{
	$bytes = random_bytes(16);
	$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
	$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

	return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}
