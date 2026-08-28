<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/core/auth.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$backendPath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');

if ($backendPath !== '' && str_starts_with($path, $backendPath)) {
	$path = substr($path, strlen($backendPath));
}

if ($method === 'GET' && rtrim($path, '/') === '/api/v1/health') {
	require __DIR__ . '/api/v1/health.php';
	exit;
}

$authRoutes = [
	'POST /api/v1/auth/register' => __DIR__ . '/api/v1/auth/register.php',
	'POST /api/v1/auth/login' => __DIR__ . '/api/v1/auth/login.php',
	'POST /api/v1/auth/logout' => __DIR__ . '/api/v1/auth/logout.php',
	'GET /api/v1/auth/me' => __DIR__ . '/api/v1/auth/me.php',
	'GET /api/v1/health/profile' => __DIR__ . '/api/v1/health/profile.php',
	'PUT /api/v1/health/profile' => __DIR__ . '/api/v1/health/profile.php',
	'GET /api/v1/health/nutrition' => __DIR__ . '/api/v1/health/nutrition.php',
	'POST /api/v1/health/nutrition/calculate' => __DIR__ . '/api/v1/health/nutrition/calculate.php',
	'POST /api/v1/health/water' => __DIR__ . '/api/v1/health/water.php',
	'GET /api/v1/health/water' => __DIR__ . '/api/v1/health/water.php',
	'POST /api/v1/health/food' => __DIR__ . '/api/v1/health/food.php',
	'GET /api/v1/health/food' => __DIR__ . '/api/v1/health/food.php',
	'POST /api/v1/health/workouts' => __DIR__ . '/api/v1/health/workouts.php',
	'GET /api/v1/health/workouts' => __DIR__ . '/api/v1/health/workouts.php',
	'POST /api/v1/health/sleep' => __DIR__ . '/api/v1/health/sleep.php',
	'GET /api/v1/health/sleep' => __DIR__ . '/api/v1/health/sleep.php',
	'GET /api/v1/health/today' => __DIR__ . '/api/v1/health/today.php',
];

$route = $method . ' ' . rtrim($path, '/');

if (isset($authRoutes[$route])) {
	require $authRoutes[$route];
	exit;
}

response_error('NOT_FOUND', 'The requested endpoint was not found.', 404);
