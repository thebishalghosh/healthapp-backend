<?php

declare(strict_types=1);

if (!function_exists('response_error')) {
	require_once dirname(__DIR__, 3) . '/bootstrap.php';
}
require_once dirname(__DIR__, 3) . '/core/auth.php';
require_once dirname(__DIR__, 3) . '/services/HealthTrackingService.php';
require_once dirname(__DIR__, 3) . '/services/HealthScoreService.php';
require_once dirname(__DIR__, 3) . '/models/SleepLog.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
	response_error('METHOD_NOT_ALLOWED', 'Method not allowed.', 405);
}

$user = authenticated_user();
$userId = (int) $user['id'];
$database = database_connection();
$sleepTimezone = SleepLog::timezone($database, $userId);
$nutrition = HealthTrackingService::nutritionSummary($database, $userId);
$today = new DateTimeImmutable('today', $sleepTimezone);
$summary = HealthScoreService::dailySummary($database, $userId, $today, $sleepTimezone, $nutrition);
$food = $summary['food'];
$waterConsumed = $summary['water']['consumed_ml'];
$waterTarget = $nutrition === null || !is_numeric($nutrition['water_ml'] ?? null)
	? null
	: (float) $nutrition['water_ml'];
$workout = $summary['workout'];
$sleep = $summary['sleep'] ?? ['duration_minutes' => 0, 'bedtime' => null, 'wake_time' => null];

response_success([
	'date' => $summary['date'],
	'nutrition' => [
		'calories_target' => $nutrition === null || !is_numeric($nutrition['calories_target'] ?? null) ? null : (float) $nutrition['calories_target'],
		'calories_consumed' => round($food['calories'], 2),
		'protein_g' => round($food['protein_g'], 2),
		'carbohydrates_g' => round($food['carbohydrates_g'], 2),
		'fat_g' => round($food['fat_g'], 2),
	],
	'water' => [
		'target_ml' => $waterTarget === null ? null : round($waterTarget, 2),
		'consumed_ml' => round($waterConsumed, 2),
		'remaining_ml' => $waterTarget === null ? null : round(max(0, $waterTarget - $waterConsumed), 2),
		'percentage' => $waterTarget === null ? null : round($waterTarget > 0 ? min(100, ($waterConsumed / $waterTarget) * 100) : 0, 2),
	],
	'workout' => [
		'duration_minutes' => $workout['duration_minutes'],
		'calories_burned' => round($workout['calories_burned'], 2),
	],
	'sleep' => $sleep,
	'health_score' => $summary['health_score'],
], 'Daily health summary retrieved.');
