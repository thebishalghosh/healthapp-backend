<?php

declare(strict_types=1);

if (!function_exists('response_error')) {
	require_once dirname(__DIR__, 3) . '/bootstrap.php';
}
require_once dirname(__DIR__, 3) . '/core/auth.php';
require_once dirname(__DIR__, 3) . '/services/FoodLocationService.php';

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'PUT', 'DELETE'], true)) {
	response_error('METHOD_NOT_ALLOWED', 'Method not allowed.', 405);
}

$user = authenticated_user();
$database = database_connection();
$userId = (int) $user['id'];

if ($method === 'GET') {
	response_success(['location' => FoodLocationService::get($database, $userId)], 'Food location retrieved.');
}

if ($method === 'DELETE') {
	$deleted = FoodLocationService::delete($database, $userId);
	response_success(['deleted' => $deleted], $deleted ? 'Food location deleted.' : 'No food location was configured.');
}

$body = auth_json_body();
try {
	$location = FoodLocationService::save($database, $userId, $body);
} catch (FoodLocationValidationException $exception) {
	response_validation_error($exception->fields);
}

response_success(['location' => $location], 'Food location saved.');
