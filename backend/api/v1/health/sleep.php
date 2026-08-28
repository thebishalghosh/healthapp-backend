<?php

declare(strict_types=1);

if (!function_exists('response_error')) {
	require_once dirname(__DIR__, 3) . '/bootstrap.php';
}
require_once dirname(__DIR__, 3) . '/core/auth.php';
require_once dirname(__DIR__, 3) . '/services/HealthTrackingService.php';

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'POST'], true)) {
	response_error('METHOD_NOT_ALLOWED', 'Method not allowed.', 405);
}

$user = authenticated_user();
$userId = (int) $user['id'];
$database = database_connection();

if ($method === 'POST') {
	$body = auth_json_body();
	$fields = [];
	$sleepDate = $body['sleep_date'] ?? null;
	$bedtime = $body['bedtime'] ?? null;
	$wakeTime = $body['wake_time'] ?? null;
	if (!HealthTrackingService::validDate($sleepDate)) {
		$fields['sleep_date'] = 'Sleep date must be a valid YYYY-MM-DD date.';
	} elseif ($sleepDate > HealthTrackingService::now()->format('Y-m-d')) {
		$fields['sleep_date'] = 'Sleep date cannot be in the future.';
	}
	if (!HealthTrackingService::validTime($bedtime)) {
		$fields['bedtime'] = 'Bedtime must be a valid HH:MM or HH:MM:SS time.';
	}
	if (!HealthTrackingService::validTime($wakeTime)) {
		$fields['wake_time'] = 'Wake time must be a valid HH:MM or HH:MM:SS time.';
	}
	$hasDuration = array_key_exists('duration_minutes', $body);
	if ($hasDuration && (!HealthTrackingService::validNumber($body['duration_minutes'], 1, 1440) || (float) $body['duration_minutes'] != (int) $body['duration_minutes'])) {
		$fields['duration_minutes'] = 'Duration must be a positive whole number of minutes up to 1440.';
	}
	if ($fields !== []) {
		response_validation_error($fields);
	}

	$timezone = new DateTimeZone(HealthTrackingService::TIMEZONE);
	$start = new DateTimeImmutable($sleepDate . ' ' . HealthTrackingService::normalizeTime($bedtime), $timezone);
	$end = new DateTimeImmutable($sleepDate . ' ' . HealthTrackingService::normalizeTime($wakeTime), $timezone);
	if ($end <= $start) {
		$end = $end->modify('+1 day');
	}
	$calculatedDuration = (int) (($end->getTimestamp() - $start->getTimestamp()) / 60);
	if ($calculatedDuration < 1 || $calculatedDuration > 1440) {
		response_validation_error(['sleep_interval' => 'Bedtime and wake time must describe a duration between 1 and 1440 minutes.']);
	}
	$duration = $hasDuration ? (int) $body['duration_minutes'] : $calculatedDuration;

	$statement = $database->prepare('INSERT INTO sleep_logs (user_id, sleep_start, sleep_end, duration_minutes) VALUES (:user_id, :sleep_start, :sleep_end, :duration_minutes)');
	$statement->execute([
		'user_id' => $userId,
		'sleep_start' => $start->format('Y-m-d H:i:s'),
		'sleep_end' => $end->format('Y-m-d H:i:s'),
		'duration_minutes' => $duration,
	]);

	response_success(['sleep' => ['id' => (int) $database->lastInsertId(), 'sleep_date' => $sleepDate, 'bedtime' => $bedtime, 'wake_time' => $wakeTime, 'duration_minutes' => $duration]], 'Sleep logged.', 201);
}

[$today, $tomorrow] = HealthTrackingService::todayBounds();
$statement = $database->prepare('SELECT id, sleep_start, sleep_end, duration_minutes FROM sleep_logs WHERE user_id = :user_id AND sleep_start >= :start AND sleep_start < :end ORDER BY sleep_start DESC, id DESC LIMIT 1');
$statement->execute(['user_id' => $userId, 'start' => $today->format('Y-m-d H:i:s'), 'end' => $tomorrow->format('Y-m-d H:i:s')]);
$sleep = $statement->fetch();

if ($sleep) {
	$start = new DateTimeImmutable($sleep['sleep_start'], new DateTimeZone(HealthTrackingService::TIMEZONE));
	$end = new DateTimeImmutable($sleep['sleep_end'], new DateTimeZone(HealthTrackingService::TIMEZONE));
	$sleep = [
		'id' => (int) $sleep['id'],
		'bedtime' => $start->format('H:i:s'),
		'wake_time' => $end->format('H:i:s'),
		'duration_minutes' => (int) $sleep['duration_minutes'],
	];
}

response_success(['sleep' => $sleep], 'Sleep summary retrieved.');
