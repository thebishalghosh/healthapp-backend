<?php

declare(strict_types=1);

if (!function_exists('response_error')) {
	require_once dirname(__DIR__, 3) . '/bootstrap.php';
}
require_once dirname(__DIR__, 3) . '/core/auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
	response_error('METHOD_NOT_ALLOWED', 'Method not allowed.', 405);
}

$user = authenticated_user();
$body = auth_json_body();
foreach ($body as $field => $value) {
	if ($field !== 'title') {
		response_validation_error([$field => 'This field is not supported.']);
	}
}
$title = $body['title'] ?? null;
if ($title !== null && (!is_string($title) || strlen(trim($title)) > 191)) {
	response_validation_error(['title' => 'Title must be a string of at most 191 bytes.']);
}

try {
	$database = database_connection();
	$statement = $database->prepare('INSERT INTO chat_sessions (user_id, title) VALUES (:user_id, :title)');
	$statement->execute(['user_id' => (int) $user['id'], 'title' => $title === null ? null : trim($title)]);
	response_success(['session_id' => (int) $database->lastInsertId()], 'Chat session started.', 201);
} catch (Throwable $exception) {
	error_log(sprintf('[%s] Chat session creation failed: %s', request_id(), (string) $exception));
	response_error('INTERNAL_ERROR', 'Chat session could not be started.', 500);
}