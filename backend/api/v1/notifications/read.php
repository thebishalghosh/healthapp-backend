<?php

declare(strict_types=1);

if (!function_exists('response_error')) {
        require_once dirname(__DIR__, 3) . '/bootstrap.php';
}
require_once dirname(__DIR__, 3) . '/core/auth.php';
require_once dirname(__DIR__, 3) . '/services/NotificationService.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'PATCH') {
        response_error('METHOD_NOT_ALLOWED', 'Method not allowed.', 405);
}

$user = authenticated_user();
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false) {
        response_validation_error(['id' => 'ID must be a positive integer.']);
}

try {
        if (!NotificationService::markRead(database_connection(), (int) $user['id'], (int) $id)) {
                response_error('NOTIFICATION_NOT_FOUND', 'Notification was not found.', 404);
        }
        response_success(['notification' => ['id' => (int) $id, 'is_read' => true]], 'Notification marked as read.');
} catch (Throwable $exception) {
        error_log(sprintf('[%s] Notification read failed: %s', request_id(), $exception->getMessage()));
        response_error('INTERNAL_ERROR', 'Notification could not be updated.', 500);
}