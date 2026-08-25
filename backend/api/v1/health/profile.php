<?php

declare(strict_types=1);

if (!function_exists('response_error')) {
	require_once dirname(__DIR__, 3) . '/bootstrap.php';
}
require_once dirname(__DIR__, 3) . '/core/auth.php';

$method = $_SERVER['REQUEST_METHOD'] ?? '';

if (!in_array($method, ['GET', 'PUT'], true)) {
	response_error('METHOD_NOT_ALLOWED', 'Method not allowed.', 405);
}

$user = authenticated_user();
$userId = (int) $user['id'];
$database = database_connection();
$profileFields = [
	'first_name',
	'last_name',
	'date_of_birth',
	'gender',
	'height_cm',
	'weight_kg',
	'activity_level',
	'fitness_goal',
	'dietary_preference',
];

if ($method === 'PUT') {
	$body = auth_json_body();
	$fields = [];

	foreach ($body as $field => $value) {
		if (!in_array($field, $profileFields, true)) {
			$fields[$field] = 'This field is not supported.';
		}
	}

	if ($body === []) {
		$fields['_request'] = 'At least one profile field is required.';
	}

	if (array_key_exists('first_name', $body) && (!is_string($body['first_name']) || trim($body['first_name']) === '' || strlen(trim($body['first_name'])) > 100)) {
		$fields['first_name'] = 'First name must be 1 to 100 characters.';
	}

	if (array_key_exists('last_name', $body) && (!is_string($body['last_name']) || trim($body['last_name']) === '' || strlen(trim($body['last_name'])) > 100)) {
		$fields['last_name'] = 'Last name must be 1 to 100 characters.';
	}

	if (array_key_exists('date_of_birth', $body)) {
		$date = is_string($body['date_of_birth']) ? DateTimeImmutable::createFromFormat('!Y-m-d', $body['date_of_birth']) : false;
		if (!$date || $date->format('Y-m-d') !== $body['date_of_birth'] || $date > new DateTimeImmutable('today')) {
			$fields['date_of_birth'] = 'Date of birth must be a valid date that is not in the future.';
		}
	}

	if (array_key_exists('gender', $body) && (!is_string($body['gender']) || strlen(trim($body['gender'])) > 50)) {
		$fields['gender'] = 'Gender must be at most 50 characters.';
	}

	if (array_key_exists('height_cm', $body) && (!is_numeric($body['height_cm']) || (float) $body['height_cm'] < 50 || (float) $body['height_cm'] > 300)) {
		$fields['height_cm'] = 'Height must be between 50 and 300 cm.';
	}

	if (array_key_exists('weight_kg', $body) && (!is_numeric($body['weight_kg']) || (float) $body['weight_kg'] <= 0 || (float) $body['weight_kg'] > 500)) {
		$fields['weight_kg'] = 'Weight must be greater than 0 and at most 500 kg.';
	}

	$activityLevels = ['sedentary', 'light', 'moderate', 'active', 'very_active'];
	if (array_key_exists('activity_level', $body) && !in_array($body['activity_level'], $activityLevels, true)) {
		$fields['activity_level'] = 'Activity level is invalid.';
	}

	$fitnessGoals = ['weight_loss', 'weight_gain', 'muscle_gain', 'maintenance', 'general_wellness'];
	if (array_key_exists('fitness_goal', $body) && !in_array($body['fitness_goal'], $fitnessGoals, true)) {
		$fields['fitness_goal'] = 'Fitness goal is invalid.';
	}

	if (array_key_exists('dietary_preference', $body) && (!is_string($body['dietary_preference']) || strlen(trim($body['dietary_preference'])) > 100)) {
		$fields['dietary_preference'] = 'Dietary preference must be at most 100 characters.';
	}

	if ($fields !== []) {
		response_validation_error($fields);
	}

	$existingStatement = $database->prepare('SELECT user_id FROM user_profiles WHERE user_id = :user_id LIMIT 1');
	$existingStatement->execute(['user_id' => $userId]);
	$profileExists = (bool) $existingStatement->fetch();
	$values = [];

	foreach ($profileFields as $field) {
		if (array_key_exists($field, $body)) {
			$values[$field] = is_string($body[$field]) ? trim($body[$field]) : $body[$field];
		}
	}

	if ($profileExists) {
		$assignments = implode(', ', array_map(static fn (string $field): string => $field . ' = :' . $field, array_keys($values)));
		$values['user_id'] = $userId;
		$statement = $database->prepare('UPDATE user_profiles SET ' . $assignments . ' WHERE user_id = :user_id');
	} else {
		$columns = implode(', ', array_merge(['user_id'], array_keys($values)));
		$placeholders = implode(', ', array_merge([':user_id'], array_map(static fn (string $field): string => ':' . $field, array_keys($values))));
		$values['user_id'] = $userId;
		$statement = $database->prepare('INSERT INTO user_profiles (' . $columns . ') VALUES (' . $placeholders . ')');
	}

	$statement->execute($values);
}

$statement = $database->prepare(
	'SELECT first_name, last_name, date_of_birth, gender, height_cm, weight_kg, activity_level, fitness_goal, dietary_preference
	 FROM user_profiles WHERE user_id = :user_id LIMIT 1'
);
$statement->execute(['user_id' => $userId]);
$profile = $statement->fetch() ?: array_fill_keys($profileFields, null);

response_success(['profile' => $profile], $method === 'GET' ? 'Health profile retrieved.' : 'Health profile updated.');