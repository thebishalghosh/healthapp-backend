<?php

declare(strict_types=1);

if (!function_exists('response_error')) {
	require_once dirname(__DIR__, 3) . '/bootstrap.php';
}
require_once dirname(__DIR__, 3) . '/core/auth.php';
require_once dirname(__DIR__, 3) . '/services/HealthMedicalProfileService.php';

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'PUT', 'DELETE'], true)) {
	response_error('METHOD_NOT_ALLOWED', 'Method not allowed.', 405);
}

$user = authenticated_user();
$database = database_connection();
$userId = (int) $user['id'];

if ($method === 'GET') {
	response_success(HealthMedicalProfileService::get($database, $userId), 'Medical profile retrieved.');
}

if ($method === 'DELETE') {
	HealthMedicalProfileService::withdraw($database, $userId);
	response_success(['deleted' => true], 'Medical profile deleted and consent withdrawn.');
}

$body = auth_json_body();
try {
	HealthMedicalProfileService::save($database, $userId, $body);
} catch (HealthMedicalProfileValidationException $exception) {
	response_validation_error($exception->fields);
}

response_success(HealthMedicalProfileService::get($database, $userId), 'Medical profile saved.');
