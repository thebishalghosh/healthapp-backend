<?php

declare(strict_types=1);

if (!function_exists('response_error')) {
	require_once dirname(__DIR__, 3) . '/bootstrap.php';
}
require_once dirname(__DIR__, 3) . '/core/auth.php';
require_once dirname(__DIR__, 3) . '/models/Reminder.php';
require_once dirname(__DIR__, 3) . '/services/EntitlementService.php';

if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['PUT', 'PATCH'], true)) {
	response_error('METHOD_NOT_ALLOWED', 'Method not allowed.', 405);
}

$user = authenticated_user();
$database = database_connection();
EntitlementService::requireFeature($database, (int) $user['id'], 'reminders');
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false) {
	response_validation_error(['id' => 'ID must be a positive integer.']);
}
$body = auth_json_body();
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'PATCH') {
	$existing = Reminder::find($database, (int) $user['id'], (int) $id);
	if ($existing === null) {
		response_error('REMINDER_NOT_FOUND', 'Reminder was not found.', 404);
	}
	$body = array_merge([
		'reminder_type' => $existing['reminder_type'],
		'title' => $existing['title'],
		'message' => $existing['message'],
		'reminder_time' => $existing['reminder_time'],
		'start_date' => $existing['start_date'],
		'end_date' => $existing['end_date'],
		'repeat_type' => $existing['repeat_type'],
		'repeat_days' => $existing['repeat_days'],
		'is_enabled' => $existing['is_enabled'],
	], $body);
}
$fields = Reminder::validate($body);
if ($fields !== []) {
	response_validation_error($fields);
}

try {
	$reminder = Reminder::update($database, (int) $user['id'], (int) $id, $body);
	if ($reminder === null) {
		response_error('REMINDER_NOT_FOUND', 'Reminder was not found.', 404);
	}
	response_success(['reminder' => $reminder], 'Reminder updated.');
} catch (Throwable $exception) {
	error_log(sprintf('[%s] Reminder update failed: %s', request_id(), $exception->getMessage()));
	response_error('INTERNAL_ERROR', 'Reminder could not be updated.', 500);
}
