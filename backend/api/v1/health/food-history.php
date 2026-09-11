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
$nutrition = HealthTrackingService::nutritionOrError($database, $userId);

$statement = $database->prepare(
	'SELECT id, meal_type, food_name, calories, protein_g, carbohydrates_g, fat_g, consumed_at, created_at
	 FROM meal_logs
	 WHERE user_id = :user_id AND consumed_at >= :start AND consumed_at < :end
	 ORDER BY consumed_at DESC, id DESC'
);
$statement->execute(['user_id' => $userId, 'start' => $start, 'end' => $end]);
$meals = [];
while ($meal = $statement->fetch()) {
	$meal['id'] = (int) $meal['id'];
	foreach (['calories', 'protein_g', 'carbohydrates_g', 'fat_g'] as $field) {
		$meal[$field] = (float) ($meal[$field] ?? 0);
	}
	$meals[] = $meal;
}

$totalsStatement = $database->prepare('SELECT COALESCE(SUM(calories), 0) AS calories, COALESCE(SUM(protein_g), 0) AS protein_g, COALESCE(SUM(carbohydrates_g), 0) AS carbohydrates_g, COALESCE(SUM(fat_g), 0) AS fat_g FROM meal_logs WHERE user_id = :user_id AND consumed_at >= :start AND consumed_at < :end');
$totalsStatement->execute(['user_id' => $userId, 'start' => $start, 'end' => $end]);
$totals = $totalsStatement->fetch();

response_success(['food' => [
	'date' => $date,
	'meals' => $meals,
	'calories_target' => (float) $nutrition['calories_target'],
	'consumed_calories' => round((float) $totals['calories'], 2),
	'protein_g' => round((float) $totals['protein_g'], 2),
	'carbohydrates_g' => round((float) $totals['carbohydrates_g'], 2),
	'fat_g' => round((float) $totals['fat_g'], 2),
]], 'Meal history retrieved.');