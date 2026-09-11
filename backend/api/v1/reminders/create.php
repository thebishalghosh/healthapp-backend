<?php

declare(strict_types=1);

if (!function_exists('response_error')) {
	require_once dirname(__DIR__, 3) . '/bootstrap.php';
}
require_once dirname(__DIR__, 3) . '/core/auth.php';
require_once dirname(__DIR__, 3) . '/models/Reminder.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
	response_error('METHOD_NOT_ALLOWED', 'Method not allowed.', 405);
}

$user = authenticated_user();
$body = auth_json_body();
$fields = Reminder::validate($body);
if ($fields !== []) {
	response_validation_error($fields);
}

try {
	$reminder = Reminder::create(database_connection(), (int) $user['id'], $body);
	response_success(['reminder' => $reminder], 'Reminder created.', 201);
} catch (Throwable $exception) {
	error_log(sprintf('[%s] Reminder creation failed: %s', request_id(), $exception->getMessage()));
	response_error('INTERNAL_ERROR', 'Reminder could not be created.', 500);
}
