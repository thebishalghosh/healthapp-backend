<?php

declare(strict_types=1);

if (!function_exists('response_error')) {
	require_once dirname(__DIR__, 3) . '/bootstrap.php';
}
require_once dirname(__DIR__, 3) . '/core/auth.php';
require_once dirname(__DIR__, 3) . '/models/Reminder.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'PUT') {
	response_error('METHOD_NOT_ALLOWED', 'Method not allowed.', 405);
}

$user = authenticated_user();
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false) {
	response_validation_error(['id' => 'ID must be a positive integer.']);
}
$body = auth_json_body();
$fields = Reminder::validate($body);
if ($fields !== []) {
	response_validation_error($fields);
}

try {
	$reminder = Reminder::update(database_connection(), (int) $user['id'], (int) $id, $body);
	if ($reminder === null) {
		response_error('REMINDER_NOT_FOUND', 'Reminder was not found.', 404);
	}
	response_success(['reminder' => $reminder], 'Reminder updated.');
} catch (Throwable $exception) {
	error_log(sprintf('[%s] Reminder update failed: %s', request_id(), $exception->getMessage()));
	response_error('INTERNAL_ERROR', 'Reminder could not be updated.', 500);
}
