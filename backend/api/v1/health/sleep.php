<?php

declare(strict_types=1);

if (!function_exists('response_error')) {
        require_once dirname(__DIR__, 3) . '/bootstrap.php';
}
require_once dirname(__DIR__, 3) . '/core/auth.php';
require_once dirname(__DIR__, 3) . '/services/HealthTrackingService.php';
require_once dirname(__DIR__, 3) . '/models/SleepLog.php';

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'POST', 'PUT', 'DELETE'], true)) {
        response_error('METHOD_NOT_ALLOWED', 'Method not allowed.', 405);
}

$user = authenticated_user();
$userId = (int) $user['id'];
$database = database_connection();
$timezone = SleepLog::timezone($database, $userId);

if ($method === 'POST' || $method === 'PUT') {
        $body = auth_json_body();
        $fields = SleepLog::validate($body, $timezone);
        if ($fields !== []) {
                response_validation_error($fields);
        }
        try {
                if ($method === 'POST') {
                        response_success(['sleep' => SleepLog::create($database, $userId, $body, $timezone)], 'Sleep logged.', 201);
                }
                $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($id === false) {
                        response_validation_error(['id' => 'ID must be a positive integer.']);
                }
                $sleep = SleepLog::update($database, $userId, (int) $id, $body, $timezone);
                if ($sleep === null) {
                        response_error('SLEEP_NOT_FOUND', 'Sleep log was not found.', 404);
                }
                response_success(['sleep' => $sleep], 'Sleep updated.');
        } catch (InvalidArgumentException $exception) {
                response_validation_error(['sleep_interval' => $exception->getMessage()]);
        } catch (Throwable $exception) {
                error_log(sprintf('[%s] Sleep write failed: %s', request_id(), $exception->getMessage()));
                response_error('INTERNAL_ERROR', 'Sleep could not be saved.', 500);
        }
}

if ($method === 'DELETE') {
        $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
                response_validation_error(['id' => 'ID must be a positive integer.']);
        }
        try {
                if (!SleepLog::delete($database, $userId, (int) $id)) {
                        response_error('SLEEP_NOT_FOUND', 'Sleep log was not found.', 404);
                }
                response_success(['sleep' => ['id' => (int) $id]], 'Sleep deleted.');
        } catch (Throwable $exception) {
                error_log(sprintf('[%s] Sleep deletion failed: %s', request_id(), $exception->getMessage()));
                response_error('INTERNAL_ERROR', 'Sleep could not be deleted.', 500);
        }
}

try {
        response_success(['sleep' => SleepLog::today($database, $userId, $timezone)], 'Sleep summary retrieved.');
} catch (Throwable $exception) {
        error_log(sprintf('[%s] Sleep retrieval failed: %s', request_id(), $exception->getMessage()));
        response_error('INTERNAL_ERROR', 'Sleep could not be retrieved.', 500);
}
