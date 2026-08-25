<?php

declare(strict_types=1);

function configure_cors(): void
{
	$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
	$origins = array_filter(array_map('trim', explode(',', (string) app_config('CORS_ALLOWED_ORIGINS', ''))));

	if (in_array($requestOrigin, $origins, true)) {
		header('Access-Control-Allow-Origin: ' . $requestOrigin);
		header('Vary: Origin');
	}

	header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
	header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Request-ID');
	header('Access-Control-Max-Age: 86400');
}

function request_id(): string
{
	$requestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? '';

	if (!is_string($requestId) || !preg_match('/^[A-Za-z0-9._-]{1,100}$/', $requestId)) {
		$requestId = bin2hex(random_bytes(16));
	}

	header('X-Request-ID: ' . $requestId);

	return $requestId;
}
