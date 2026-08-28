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
	$mealType = $body['meal_type'] ?? null;
	$foodName = is_string($body['food_name'] ?? null) ? trim($body['food_name']) : '';
	if (!in_array($mealType, HealthTrackingService::MEAL_TYPES, true)) {
		$fields['meal_type'] = 'Meal type must be breakfast, lunch, dinner, or snack.';
	}
	if ($foodName === '' || strlen($foodName) > 191) {
		$fields['food_name'] = 'Food name must be 1 to 191 characters.';
	}
	foreach (['calories', 'protein_g', 'carbohydrates_g', 'fat_g'] as $field) {
		if (!HealthTrackingService::validNumber($body[$field] ?? null, 0, 100000)) {
			$fields[$field] = 'Value must be a non-negative number.';
		}
	}
	if ($fields !== []) {
		response_validation_error($fields);
	}

	$statement = $database->prepare(
		'INSERT INTO meal_logs (user_id, meal_type, food_name, calories, protein_g, carbohydrates_g, fat_g, consumed_at)
		 VALUES (:user_id, :meal_type, :food_name, :calories, :protein_g, :carbohydrates_g, :fat_g, :consumed_at)'
	);
	$statement->execute([
		'user_id' => $userId,
		'meal_type' => $mealType,
		'food_name' => $foodName,
		'calories' => (float) $body['calories'],
		'protein_g' => (float) $body['protein_g'],
		'carbohydrates_g' => (float) $body['carbohydrates_g'],
		'fat_g' => (float) $body['fat_g'],
		'consumed_at' => HealthTrackingService::now()->format('Y-m-d H:i:s'),
	]);

	response_success(['food' => ['id' => (int) $database->lastInsertId(), 'meal_type' => $mealType, 'food_name' => $foodName]], 'Food logged.', 201);
}

$nutrition = HealthTrackingService::nutritionOrError($database, $userId);
[$today, $tomorrow] = HealthTrackingService::todayBounds();
$start = $today->format('Y-m-d H:i:s');
$end = $tomorrow->format('Y-m-d H:i:s');
$statement = $database->prepare(
	'SELECT id, meal_type, food_name, calories, protein_g, carbohydrates_g, fat_g, consumed_at
	 FROM meal_logs WHERE user_id = :user_id AND consumed_at >= :start AND consumed_at < :end ORDER BY consumed_at ASC, id ASC'
);
$statement->execute(['user_id' => $userId, 'start' => $start, 'end' => $end]);
$meals = [];
while ($meal = $statement->fetch()) {
	foreach (['calories', 'protein_g', 'carbohydrates_g', 'fat_g'] as $field) {
		$meal[$field] = (float) ($meal[$field] ?? 0);
	}
	$meal['id'] = (int) $meal['id'];
	$meals[] = $meal;
}
$totalsStatement = $database->prepare('SELECT COALESCE(SUM(calories), 0) AS calories, COALESCE(SUM(protein_g), 0) AS protein_g, COALESCE(SUM(carbohydrates_g), 0) AS carbohydrates_g, COALESCE(SUM(fat_g), 0) AS fat_g FROM meal_logs WHERE user_id = :user_id AND consumed_at >= :start AND consumed_at < :end');
$totalsStatement->execute(['user_id' => $userId, 'start' => $start, 'end' => $end]);
$totals = $totalsStatement->fetch();

response_success(['food' => [
	'calories_target' => (float) $nutrition['calories_target'],
	'consumed_calories' => round((float) $totals['calories'], 2),
	'protein_g' => round((float) $totals['protein_g'], 2),
	'carbohydrates_g' => round((float) $totals['carbohydrates_g'], 2),
	'fat_g' => round((float) $totals['fat_g'], 2),
	'meals' => $meals,
]], 'Food summary retrieved.');
