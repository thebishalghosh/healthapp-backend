<?php

declare(strict_types=1);

if (!function_exists('response_error')) {
	require_once dirname(__DIR__, 4) . '/bootstrap.php';
}
require_once dirname(__DIR__, 4) . '/core/auth.php';
require_once dirname(__DIR__, 4) . '/services/FoodScanService.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
	response_error('METHOD_NOT_ALLOWED', 'Method not allowed.', 405);
}

authenticated_user();

try {
	$scanResult = FoodScanService::scanUploadedFile(database_connection(), $_FILES['image'] ?? null);
} catch (GeminiResponseException $exception) {
	error_log(sprintf('[%s] Gemini food scan response rejected: %s', request_id(), $exception->getMessage()));
	response_error('AI_RESPONSE_INVALID', 'Food image analysis returned an invalid structured response.', 502);
} catch (PDOException $exception) {
	error_log(sprintf('[%s] Food catalog lookup failed.', request_id()));
	response_error('FOOD_CATALOG_UNAVAILABLE', 'Food nutrition data is temporarily unavailable.', 503);
} catch (Throwable $exception) {
	error_log(sprintf('[%s] Gemini food scan failed: %s', request_id(), $exception->getMessage()));
	response_error('GEMINI_UNAVAILABLE', 'Food image analysis is temporarily unavailable.', 503);
}

response_success($scanResult, 'Food scan completed.');