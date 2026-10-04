<?php

declare(strict_types=1);

if (!function_exists('response_error')) {
	require_once dirname(__DIR__, 4) . '/bootstrap.php';
}
require_once dirname(__DIR__, 4) . '/core/auth.php';
require_once dirname(__DIR__, 4) . '/services/FoodScanConfirmationService.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
	response_error('METHOD_NOT_ALLOWED', 'Method not allowed.', 405);
}

$user = authenticated_user();
$database = database_connection();
$request = auth_json_body();

try {
	$confirmedMeal = FoodScanConfirmationService::confirm($database, (int) $user['id'], $request);
} catch (FoodScanConfirmationValidationException $exception) {
	response_validation_error($exception->fields);
} catch (PDOException $exception) {
	error_log(sprintf('[%s] Food scan confirmation database operation failed.', request_id()));
	response_error('MEAL_CONFIRMATION_FAILED', 'Confirmed food could not be saved.', 500);
} catch (Throwable $exception) {
	error_log(sprintf('[%s] Food scan confirmation failed: %s', request_id(), $exception->getMessage()));
	response_error('MEAL_CONFIRMATION_FAILED', 'Confirmed food could not be saved.', 500);
}

response_success($confirmedMeal, 'Food scan meal confirmed.', 201);