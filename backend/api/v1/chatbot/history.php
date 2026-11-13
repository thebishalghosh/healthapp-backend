<?php

declare(strict_types=1);

if (!function_exists('response_error')) {
	require_once dirname(__DIR__, 3) . '/bootstrap.php';
}
require_once dirname(__DIR__, 3) . '/core/auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
	response_error('METHOD_NOT_ALLOWED', 'Method not allowed.', 405);
}

$user = authenticated_user();
$sessionId = filter_var($_GET['session_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($sessionId === false) {
	response_validation_error(['session_id' => 'A valid chat session is required.']);
}

try {
	$database = database_connection();
	$sessionStatement = $database->prepare('SELECT id FROM chat_sessions WHERE id = :session_id AND user_id = :user_id LIMIT 1');
	$sessionStatement->execute(['session_id' => $sessionId, 'user_id' => (int) $user['id']]);
	if (!$sessionStatement->fetchColumn()) {
		response_error('CHAT_SESSION_NOT_FOUND', 'Chat session was not found.', 404);
	}

	$statement = $database->prepare(
		'SELECT id, role, message, ai_model, created_at
		 FROM chat_messages WHERE session_id = :session_id AND user_id = :user_id
		 ORDER BY id ASC LIMIT 500'
	);
	$statement->execute(['session_id' => $sessionId, 'user_id' => (int) $user['id']]);
	response_success([
		'session_id' => (int) $sessionId,
		'messages' => $statement->fetchAll(),
	], 'Chat history retrieved.');
} catch (Throwable $exception) {
	error_log(sprintf('[%s] Chat history retrieval failed: %s', request_id(), (string) $exception));
	response_error('INTERNAL_ERROR', 'Chat history could not be retrieved.', 500);
}