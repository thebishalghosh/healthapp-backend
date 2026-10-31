<?php

declare(strict_types=1);

if (!function_exists('response_error')) {
	require_once dirname(__DIR__, 2) . '/bootstrap.php';
}
require_once dirname(__DIR__, 2) . '/core/auth.php';
require_once dirname(__DIR__, 2) . '/services/AccountDeletionService.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'DELETE') {
	response_error('METHOD_NOT_ALLOWED', 'Method not allowed.', 405);
}

$user = authenticated_user();
if ($_GET !== []) {
	response_validation_error(['query' => 'Query parameters are not supported.']);
}

try {
	$body = auth_json_body();
	if ($body === [] || array_is_list($body)) {
		throw new AccountDeactivationValidationException(['_request' => 'A JSON object is required.']);
	}
	$currentPassword = AccountDeactivationService::validate($body);
	AccountDeactivationService::deactivate(database_connection(), (int) $user['id'], $currentPassword);
} catch (AccountDeactivationValidationException $exception) {
	response_validation_error($exception->fields);
} catch (AccountDeactivationInvalidPasswordException) {
	response_error('INVALID_CURRENT_PASSWORD', 'Current password is incorrect.', 400);
} catch (AccountDeactivationFailureException $exception) {
	error_log(sprintf('[%s] Account deactivation stopped: %s', request_id(), $exception->getMessage()));
	response_error('ACCOUNT_NOT_ACTIVE', 'This account is no longer active.', 409);
} catch (PDOException $exception) {
	$driverCode = $exception->errorInfo[1] ?? null;
	error_log(sprintf(
		'[%s] Account deactivation database failure: SQLSTATE=%s%s',
		request_id(),
		(string) $exception->getCode(),
		is_int($driverCode) || is_string($driverCode) ? ' driver_code=' . $driverCode : ''
	));
	response_error('DATABASE_ERROR', 'The account could not be deactivated. Please try again.', 500);
}

response_success([
	'account_deactivated' => true,
	'sessions_invalidated' => true,
	'subscription_billing_cancelled' => false,
	'subscription_billing_may_continue' => true,
], 'Your account has been deactivated and your sessions signed out. Recurring subscriptions are unchanged and may continue billing; cancel them separately with the payment provider.');
