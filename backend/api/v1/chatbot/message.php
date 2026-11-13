<?php

declare(strict_types=1);

if (!function_exists('response_error')) {
	require_once dirname(__DIR__, 3) . '/bootstrap.php';
}
require_once dirname(__DIR__, 3) . '/core/auth.php';
require_once dirname(__DIR__, 3) . '/services/ChatbotService.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
	response_error('METHOD_NOT_ALLOWED', 'Method not allowed.', 405);
}

$user = authenticated_user();
$body = auth_json_body();
$fields = [];
$sessionId = filter_var($body['session_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$message = $body['message'] ?? null;
foreach ($body as $field => $value) {
	if (!in_array($field, ['session_id', 'message'], true)) {
		$fields[$field] = 'This field is not supported.';
	}
}
if ($sessionId === false) {
	$fields['session_id'] = 'A valid chat session is required.';
}
if (!is_string($message) || trim($message) === '' || strlen($message) > 10000) {
	$fields['message'] = 'Message must contain 1 to 10000 bytes.';
}
if ($fields !== []) {
	response_validation_error($fields);
}

$idempotencyKey = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null;
if ($idempotencyKey !== null && (!is_string($idempotencyKey) || trim($idempotencyKey) === '' || strlen($idempotencyKey) > 191)) {
	response_validation_error(['Idempotency-Key' => 'Idempotency-Key must contain 1 to 191 bytes.']);
}

try {
	$result = ChatbotService::sendMessage(
		database_connection(),
		(int) $user['id'],
		(int) $sessionId,
		trim($message),
		$idempotencyKey === null ? null : trim($idempotencyKey)
	);
} catch (ChatDailyLimitReachedException $exception) {
	respond([
		'success' => false,
		'error' => 'DAILY_CHAT_LIMIT_REACHED',
		'message' => 'You have reached your daily AI chat limit.',
		'usage' => $exception->usage,
	], 429);
} catch (ChatIdempotencyConflictException $exception) {
	response_error('IDEMPOTENCY_KEY_REUSED', 'This Idempotency-Key was already used for another chat message.', 409);
} catch (OutOfBoundsException $exception) {
	response_error('CHAT_SESSION_NOT_FOUND', 'Chat session was not found.', 404);
} catch (DomainException $exception) {
	response_error('CHAT_SESSION_ARCHIVED', 'Archived chat sessions cannot receive new messages.', 409);
} catch (ChatQuotaLockException $exception) {
	response_error('CHAT_QUOTA_UNAVAILABLE', 'Chat quota could not be checked. Please retry.', 503);
} catch (ChatGenerationException $exception) {
	error_log(sprintf('[%s] Gemini chat request failed: %s', request_id(), (string) $exception->getPrevious()));
	response_error('GEMINI_UNAVAILABLE', 'AI chat is temporarily unavailable. Please try again later.', 503);
} catch (Throwable $exception) {
	error_log(sprintf('[%s] Chat message processing failed: %s', request_id(), (string) $exception));
	response_error('INTERNAL_ERROR', 'The chat message could not be processed.', 500);
}

response_success($result, 'Chat response generated.');