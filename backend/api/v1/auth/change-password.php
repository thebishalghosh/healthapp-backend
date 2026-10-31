<?php

declare(strict_types=1);

if (!function_exists('response_error')) {
	require_once dirname(__DIR__, 3) . '/bootstrap.php';
}
require_once dirname(__DIR__, 3) . '/core/auth.php';
require_once dirname(__DIR__, 3) . '/services/ChangePasswordService.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
	response_error('METHOD_NOT_ALLOWED', 'Method not allowed.', 405);
}

try {
	$user = authenticated_user();
	[$currentPassword, $newPassword] = ChangePasswordService::validate(auth_json_body());
	ChangePasswordService::change(database_connection(), (int) $user['id'], $currentPassword, $newPassword);
} catch (ChangePasswordValidationException $exception) {
	response_validation_error($exception->fields);
} catch (ChangePasswordInvalidCurrentPasswordException) {
	response_error('INVALID_CURRENT_PASSWORD', 'Current password is incorrect.', 400);
} catch (ChangePasswordSamePasswordException) {
	response_validation_error(['new_password' => 'New password must differ from the current password.']);
} catch (ChangePasswordUnauthenticatedException) {
	response_error('UNAUTHENTICATED', 'Authentication is required.', 401);
} catch (PDOException) {
	response_error('DATABASE_ERROR', 'Password could not be changed. Please try again.', 500);
}

response_success([
	'sessions_invalidated' => true,
	'reauthentication_required' => true,
], 'Password changed successfully. Existing sessions have been invalidated; please log in again.');
