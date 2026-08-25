<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/core/response.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/config/database.php';

configure_cors();
$requestId = request_id();

set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(static function (Throwable $exception) use ($requestId): never {
    error_log(sprintf('[%s] %s', $requestId, (string) $exception));
    response_error('INTERNAL_ERROR', 'An unexpected error occurred.', 500);
});

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}