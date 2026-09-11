<?php

declare(strict_types=1);

if (!function_exists('response_error')) {
	require_once dirname(__DIR__, 3) . '/bootstrap.php';
}
require_once dirname(__DIR__, 3) . '/core/auth.php';
require_once dirname(__DIR__, 3) . '/services/HealthTrackingService.php';

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'POST', 'PUT', 'DELETE'], true)) {
	response_error('METHOD_NOT_ALLOWED', 'Method not allowed.', 405);
}

$user = authenticated_user();
$userId = (int) $user['id'];
$database = database_connection();

if ($method === 'DELETE') {
	$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
	if ($id === false) {
		response_validation_error(['id' => 'ID must be a positive integer.']);
	}

	$statement = $database->prepare('DELETE FROM workout_logs WHERE id = :id AND user_id = :user_id');
	$statement->execute(['id' => $id, 'user_id' => $userId]);
	if ($statement->rowCount() === 0) {
		response_error('WORKOUT_LOG_NOT_FOUND', 'Workout entry was not found.', 404);
	}

	response_success(['workout' => ['id' => (int) $id]], 'Workout deleted.');
}

if ($method === 'POST') {
	$body = auth_json_body();
	$fields = [];
	$workoutType = is_string($body['workout_type'] ?? null) ? trim($body['workout_type']) : '';
	if ($workoutType === '' || strlen($workoutType) > 100) {
		$fields['workout_type'] = 'Workout type must be 1 to 100 characters.';
	}
	if (!HealthTrackingService::validNumber($body['duration_minutes'] ?? null, 1, 1440) || (float) $body['duration_minutes'] != (int) $body['duration_minutes']) {
		$fields['duration_minutes'] = 'Duration must be a positive whole number of minutes up to 1440.';
	}
	if (!HealthTrackingService::validNumber($body['calories_burned'] ?? null, 0, 100000)) {
		$fields['calories_burned'] = 'Calories burned must be a non-negative number.';
	}
	if ($fields !== []) {
		response_validation_error($fields);
	}

	$statement = $database->prepare('INSERT INTO workout_logs (user_id, workout_type, duration_minutes, calories_burned, workout_date) VALUES (:user_id, :workout_type, :duration_minutes, :calories_burned, :workout_date)');
	$statement->execute([
		'user_id' => $userId,
		'workout_type' => $workoutType,
		'duration_minutes' => (int) $body['duration_minutes'],
		'calories_burned' => (float) $body['calories_burned'],
		'workout_date' => HealthTrackingService::now()->format('Y-m-d H:i:s'),
	]);

	response_success(['workout' => ['id' => (int) $database->lastInsertId(), 'workout_type' => $workoutType, 'duration_minutes' => (int) $body['duration_minutes'], 'calories_burned' => (float) $body['calories_burned']]], 'Workout logged.', 201);
}

if ($method === 'PUT') {
	$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
	if ($id === false) {
		response_validation_error(['id' => 'ID must be a positive integer.']);
	}

	$body = auth_json_body();
	$fields = [];
	$workoutName = null;
	$workoutType = is_string($body['workout_type'] ?? null) ? trim($body['workout_type']) : '';
	$notes = null;
	$source = $body['source'] ?? 'manual';
	if (array_key_exists('workout_name', $body)) {
		if ($body['workout_name'] !== null && (!is_string($body['workout_name']) || strlen(trim($body['workout_name'])) > 191)) {
			$fields['workout_name'] = 'Workout name must be null or at most 191 characters.';
		} else {
			$workoutName = $body['workout_name'] === null ? null : trim($body['workout_name']);
		}
	}
	if ($workoutType === '' || strlen($workoutType) > 100) {
		$fields['workout_type'] = 'Workout type must be 1 to 100 characters.';
	}
	if (!HealthTrackingService::validNumber($body['duration_minutes'] ?? null, 1, 1440) || (float) $body['duration_minutes'] != (int) $body['duration_minutes']) {
		$fields['duration_minutes'] = 'Duration must be a positive whole number of minutes up to 1440.';
	}
	if (!HealthTrackingService::validNumber($body['calories_burned'] ?? null, 0, 100000)) {
		$fields['calories_burned'] = 'Calories burned must be a non-negative number.';
	}
	if (array_key_exists('notes', $body)) {
		if ($body['notes'] !== null && !is_string($body['notes'])) {
			$fields['notes'] = 'Notes must be null or a string.';
		} else {
			$notes = $body['notes'];
		}
	}
	if (!in_array($source, ['manual', 'planned', 'reminder'], true)) {
		$fields['source'] = 'Source must be manual, planned, or reminder.';
	}
	if ($fields !== []) {
		response_validation_error($fields);
	}

	$statement = $database->prepare(
		'UPDATE workout_logs
		 SET workout_name = :workout_name, workout_type = :workout_type, duration_minutes = :duration_minutes, calories_burned = :calories_burned, notes = :notes, source = :source
		 WHERE id = :id AND user_id = :user_id'
	);
	$statement->execute([
		'id' => $id,
		'user_id' => $userId,
		'workout_name' => $workoutName,
		'workout_type' => $workoutType,
		'duration_minutes' => (int) $body['duration_minutes'],
		'calories_burned' => (float) $body['calories_burned'],
		'notes' => $notes,
		'source' => $source,
	]);

	$updatedStatement = $database->prepare('SELECT id, workout_name, workout_type, duration_minutes, calories_burned, workout_date, notes, source, created_at FROM workout_logs WHERE id = :id AND user_id = :user_id');
	$updatedStatement->execute(['id' => $id, 'user_id' => $userId]);
	$workout = $updatedStatement->fetch();
	if (!$workout) {
		response_error('WORKOUT_LOG_NOT_FOUND', 'Workout entry was not found.', 404);
	}
	$workout['id'] = (int) $workout['id'];
	$workout['duration_minutes'] = (int) $workout['duration_minutes'];
	$workout['calories_burned'] = (float) $workout['calories_burned'];

	response_success(['workout' => $workout], 'Workout updated.');
}

[$today, $tomorrow] = HealthTrackingService::todayBounds();
$statement = $database->prepare(
	'SELECT id, workout_type, duration_minutes, calories_burned, workout_date
	 FROM workout_logs WHERE user_id = :user_id AND workout_date >= :start AND workout_date < :end ORDER BY workout_date ASC, id ASC'
);
$statement->execute(['user_id' => $userId, 'start' => $today->format('Y-m-d H:i:s'), 'end' => $tomorrow->format('Y-m-d H:i:s')]);
$workouts = [];
$totalMinutes = 0;
$totalCalories = 0.0;
while ($workout = $statement->fetch()) {
	$workout['id'] = (int) $workout['id'];
	$workout['duration_minutes'] = (int) ($workout['duration_minutes'] ?? 0);
	$workout['calories_burned'] = (float) ($workout['calories_burned'] ?? 0);
	$totalMinutes += $workout['duration_minutes'];
	$totalCalories += $workout['calories_burned'];
	$workouts[] = $workout;
}

response_success(['workout' => ['duration_minutes' => $totalMinutes, 'calories_burned' => round($totalCalories, 2), 'workouts' => $workouts]], 'Workout summary retrieved.');
