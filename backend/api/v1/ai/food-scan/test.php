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
	$foodDetection = FoodScanService::detectFromUploadedFile($_FILES['image'] ?? null);
} catch (Throwable $exception) {
	error_log(sprintf('[%s] Gemini food scan test failed: %s', request_id(), $exception->getMessage()));
	response_error('GEMINI_UNAVAILABLE', 'Food image analysis is temporarily unavailable.', 503);
}

response_success($foodDetection, 'Food image analyzed.');