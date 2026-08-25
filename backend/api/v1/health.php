<?php

declare(strict_types=1);

if (!function_exists('response_error')) {
    require_once dirname(__DIR__, 2) . '/bootstrap.php';
}

try {
    database_connection()->query('SELECT 1');
} catch (Throwable $exception) {
    error_log(sprintf('[%s] Health database check failed: %s', $_SERVER['HTTP_X_REQUEST_ID'] ?? 'unknown', $exception->getMessage()));
    response_error('SERVICE_UNAVAILABLE', 'The database is unavailable.', 503);
}

response_success([
    'status' => 'ok',
    'api_version' => API_VERSION,
    'database' => 'connected',
], 'API is healthy');