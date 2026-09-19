<?php

declare(strict_types=1);

if (!function_exists('response_error')) {
	require_once dirname(__DIR__, 3) . '/bootstrap.php';
}
require_once dirname(__DIR__, 3) . '/core/auth.php';
require_once dirname(__DIR__, 3) . '/models/UserSettings.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
	response_error('METHOD_NOT_ALLOWED', 'Method not allowed.', 405);
}

$user = authenticated_user();
try {
	response_success(['settings' => UserSettings::get(database_connection(), (int) $user['id'])], 'Settings retrieved.');
} catch (Throwable $exception) {
	error_log(sprintf('[%s] Settings retrieval failed: %s', request_id(), $exception->getMessage()));
	response_error('INTERNAL_ERROR', 'Settings could not be retrieved.', 500);
}
