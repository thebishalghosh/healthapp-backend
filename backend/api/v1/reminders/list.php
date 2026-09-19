<?php

declare(strict_types=1);

if (!function_exists('response_error')) {
	require_once dirname(__DIR__, 3) . '/bootstrap.php';
}
require_once dirname(__DIR__, 3) . '/core/auth.php';
require_once dirname(__DIR__, 3) . '/models/Reminder.php';
require_once dirname(__DIR__, 3) . '/services/EntitlementService.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
	response_error('METHOD_NOT_ALLOWED', 'Method not allowed.', 405);
}

$user = authenticated_user();
$database = database_connection();
EntitlementService::requireFeature($database, (int) $user['id'], 'reminders');
try {
	response_success(['reminders' => Reminder::list($database, (int) $user['id'])], 'Reminders retrieved.');
} catch (Throwable $exception) {
	error_log(sprintf('[%s] Reminder list failed: %s', request_id(), $exception->getMessage()));
	response_error('INTERNAL_ERROR', 'Reminders could not be retrieved.', 500);
}
