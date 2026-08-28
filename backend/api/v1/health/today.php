<?php

declare(strict_types=1);

if (!function_exists('response_error')) {
	require_once dirname(__DIR__, 3) . '/bootstrap.php';
}
require_once dirname(__DIR__, 3) . '/core/auth.php';
require_once dirname(__DIR__, 3) . '/services/HealthTrackingService.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
	response_error('METHOD_NOT_ALLOWED', 'Method not allowed.', 405);
}

$user = authenticated_user();
$userId = (int) $user['id'];
$database = database_connection();
$nutrition = HealthTrackingService::nutritionSummary($database, $userId);
[$today, $tomorrow] = HealthTrackingService::todayBounds();
$start = $today->format('Y-m-d H:i:s');
$end = $tomorrow->format('Y-m-d H:i:s');

$foodStatement = $database->prepare('SELECT COALESCE(SUM(calories), 0) AS calories, COALESCE(SUM(protein_g), 0) AS protein_g, COALESCE(SUM(carbohydrates_g), 0) AS carbohydrates_g, COALESCE(SUM(fat_g), 0) AS fat_g FROM meal_logs WHERE user_id = :user_id AND consumed_at >= :start AND consumed_at < :end');
$foodStatement->execute(['user_id' => $userId, 'start' => $start, 'end' => $end]);
$food = $foodStatement->fetch();

$waterStatement = $database->prepare('SELECT COALESCE(SUM(amount_ml), 0) AS consumed_ml FROM water_logs WHERE user_id = :user_id AND consumed_at >= :start AND consumed_at < :end');
$waterStatement->execute(['user_id' => $userId, 'start' => $start, 'end' => $end]);
$waterConsumed = (float) $waterStatement->fetch()['consumed_ml'];
$waterTarget = $nutrition === null ? null : (float) $nutrition['water_ml'];

$workoutStatement = $database->prepare('SELECT COALESCE(SUM(duration_minutes), 0) AS duration_minutes, COALESCE(SUM(calories_burned), 0) AS calories_burned FROM workout_logs WHERE user_id = :user_id AND workout_date >= :start AND workout_date < :end');
$workoutStatement->execute(['user_id' => $userId, 'start' => $start, 'end' => $end]);
$workout = $workoutStatement->fetch();

$sleepStatement = $database->prepare('SELECT sleep_start, sleep_end, duration_minutes FROM sleep_logs WHERE user_id = :user_id AND sleep_start >= :start AND sleep_start < :end ORDER BY sleep_start DESC, id DESC LIMIT 1');
$sleepStatement->execute(['user_id' => $userId, 'start' => $start, 'end' => $end]);
$sleepRow = $sleepStatement->fetch();
$sleep = ['duration_minutes' => 0, 'bedtime' => null, 'wake_time' => null];
if ($sleepRow) {
	$timezone = new DateTimeZone(HealthTrackingService::TIMEZONE);
	$sleepStart = new DateTimeImmutable($sleepRow['sleep_start'], $timezone);
	$sleepEnd = new DateTimeImmutable($sleepRow['sleep_end'], $timezone);
	$sleep = [
		'duration_minutes' => (int) $sleepRow['duration_minutes'],
		'bedtime' => $sleepStart->format('H:i:s'),
		'wake_time' => $sleepEnd->format('H:i:s'),
	];
}

response_success([
	'date' => $today->format('Y-m-d'),
	'nutrition' => [
		'calories_target' => $nutrition === null ? null : (float) $nutrition['calories_target'],
		'calories_consumed' => round((float) $food['calories'], 2),
		'protein_g' => round((float) $food['protein_g'], 2),
		'carbohydrates_g' => round((float) $food['carbohydrates_g'], 2),
		'fat_g' => round((float) $food['fat_g'], 2),
	],
	'water' => [
		'target_ml' => $waterTarget === null ? null : round($waterTarget, 2),
		'consumed_ml' => round($waterConsumed, 2),
		'remaining_ml' => $waterTarget === null ? null : round(max(0, $waterTarget - $waterConsumed), 2),
		'percentage' => $waterTarget === null ? null : round($waterTarget > 0 ? min(100, ($waterConsumed / $waterTarget) * 100) : 0, 2),
	],
	'workout' => [
		'duration_minutes' => (int) $workout['duration_minutes'],
		'calories_burned' => round((float) $workout['calories_burned'], 2),
	],
	'sleep' => $sleep,
], 'Daily health summary retrieved.');
