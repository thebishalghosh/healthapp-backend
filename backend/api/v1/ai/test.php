<?php

declare(strict_types=1);

if (!function_exists('response_error')) {
	require_once dirname(__DIR__, 3) . '/bootstrap.php';
}
require_once dirname(__DIR__, 3) . '/core/auth.php';
require_once dirname(__DIR__, 3) . '/services/GeminiService.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
	response_error('METHOD_NOT_ALLOWED', 'Method not allowed.', 405);
}

authenticated_user();

try {
	$response = GeminiService::testConnection();
} catch (Throwable $exception) {
	error_log(sprintf('[%s] Gemini test request failed: %s', request_id(), $exception->getMessage()));
	response_error('GEMINI_UNAVAILABLE', 'Gemini is temporarily unavailable.', 503);
}

response_success(['status' => 'ok', 'response' => $response], 'Gemini connection successful.');