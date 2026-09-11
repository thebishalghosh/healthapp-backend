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
$date = $_GET['date'] ?? HealthTrackingService::now()->format('Y-m-d');
if (!HealthTrackingService::validDate($date)) {
	response_validation_error(['date' => 'Date must be a valid YYYY-MM-DD date.']);
}
$timezone = $_GET['timezone'] ?? HealthTrackingService::TIMEZONE;
if (!HealthTrackingService::validTimezone($timezone)) {
	response_validation_error(['timezone' => 'Timezone must be a valid IANA timezone identifier.']);
}
[$start, $end] = HealthTrackingService::utcDateBounds($date, $timezone);

$statement = $database->prepare(
	'SELECT id, workout_name, workout_type, duration_minutes, calories_burned, workout_date, notes, source, created_at
	 FROM workout_logs
	 WHERE user_id = :user_id AND workout_date >= :start AND workout_date < :end
	 ORDER BY workout_date DESC, id DESC'
);
$statement->execute(['user_id' => $userId, 'start' => $start, 'end' => $end]);
$workouts = [];
while ($workout = $statement->fetch()) {
	$workout['id'] = (int) $workout['id'];
	$workout['duration_minutes'] = (int) ($workout['duration_minutes'] ?? 0);
	$workout['calories_burned'] = (float) ($workout['calories_burned'] ?? 0);
	$workouts[] = $workout;
}

$totalsStatement = $database->prepare('SELECT COALESCE(SUM(duration_minutes), 0) AS duration_minutes, COALESCE(SUM(calories_burned), 0) AS calories_burned FROM workout_logs WHERE user_id = :user_id AND workout_date >= :start AND workout_date < :end');
$totalsStatement->execute(['user_id' => $userId, 'start' => $start, 'end' => $end]);
$totals = $totalsStatement->fetch();

response_success(['workout' => [
	'date' => $date,
	'workouts' => $workouts,
	'duration_minutes' => (int) $totals['duration_minutes'],
	'calories_burned' => round((float) $totals['calories_burned'], 2),
]], 'Workout history retrieved.');