<?php

declare(strict_types=1);

if (!function_exists('response_error')) {
        require_once dirname(__DIR__, 3) . '/bootstrap.php';
}
require_once dirname(__DIR__, 3) . '/core/auth.php';
require_once dirname(__DIR__, 3) . '/services/HealthTrackingService.php';
require_once dirname(__DIR__, 3) . '/models/SleepLog.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        response_error('METHOD_NOT_ALLOWED', 'Method not allowed.', 405);
}

$user = authenticated_user();
$database = database_connection();
$timezone = SleepLog::timezone($database, (int) $user['id']);
$date = $_GET['date'] ?? null;
if ($date !== null && !HealthTrackingService::validDate($date)) {
        response_validation_error(['date' => 'Date must be a valid YYYY-MM-DD date.']);
}

try {
        response_success(['sleep' => ['entries' => SleepLog::history($database, (int) $user['id'], $timezone, $date)]], 'Sleep history retrieved.');
} catch (Throwable $exception) {
        error_log(sprintf('[%s] Sleep history failed: %s', request_id(), $exception->getMessage()));
        response_error('INTERNAL_ERROR', 'Sleep history could not be retrieved.', 500);
}
