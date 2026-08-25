<?php

declare(strict_types=1);

if (!function_exists('response_error')) {
	require_once dirname(__DIR__, 3) . '/bootstrap.php';
}
require_once dirname(__DIR__, 3) . '/core/auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
	response_error('METHOD_NOT_ALLOWED', 'Method not allowed.', 405);
}

$token = auth_bearer_token();
$statement = database_connection()->prepare('DELETE FROM user_sessions WHERE token_hash = :token_hash');
$statement->execute(['token_hash' => hash('sha256', $token)]);

response_success([], 'Logout successful.');
