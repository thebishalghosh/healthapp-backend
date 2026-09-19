<?php

declare(strict_types=1);

if (!function_exists('response_error')) {
	require_once dirname(__DIR__, 3) . '/bootstrap.php';
}
require_once dirname(__DIR__, 3) . '/core/auth.php';
require_once dirname(__DIR__, 3) . '/services/HealthTrackingService.php';
require_once dirname(__DIR__, 3) . '/services/GeminiService.php';
require_once dirname(__DIR__, 3) . '/services/AIUsageService.php';
require_once dirname(__DIR__, 3) . '/services/FoodRecommendationHistoryService.php';
require_once dirname(__DIR__, 3) . '/services/EntitlementService.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
	response_error('METHOD_NOT_ALLOWED', 'Method not allowed.', 405);
}

$user = authenticated_user();
$userId = (int) $user['id'];
$database = database_connection();
EntitlementService::requireFeature($database, $userId, 'ai_food_recommendations');
$body = trim((string) file_get_contents('php://input'));
$request = $body === '' ? [] : json_decode($body, true);

if (!is_array($request)) {
	response_error('VALIDATION_ERROR', 'Request body must contain a JSON object.', 422, ['_request' => 'A JSON object is required.']);
}

$fields = [];
foreach ($request as $field => $value) {
	if ($field !== 'meal_type') {
		$fields[$field] = 'This field is not supported.';
	}
}
$mealType = $request['meal_type'] ?? null;
if ($mealType !== null && !in_array($mealType, HealthTrackingService::MEAL_TYPES, true)) {
	$fields['meal_type'] = 'Meal type must be breakfast, lunch, dinner, or snack.';
}
if ($fields !== []) {
	response_validation_error($fields);
}

$profileStatement = $database->prepare(
	'SELECT first_name, date_of_birth, gender, height_cm, weight_kg, activity_level, fitness_goal
	 FROM user_profiles WHERE user_id = :user_id LIMIT 1'
);
$profileStatement->execute(['user_id' => $userId]);
$profile = $profileStatement->fetch();
$requiredProfileFields = ['date_of_birth', 'gender', 'height_cm', 'weight_kg', 'activity_level', 'fitness_goal'];
if (!$profile || count(array_filter($requiredProfileFields, static fn (string $field): bool => $profile[$field] === null || $profile[$field] === '')) > 0) {
	response_error('PROFILE_NOT_FOUND', 'Complete your health profile before requesting food recommendations.', 404);
}

$nutrition = HealthTrackingService::nutritionOrError($database, $userId);
foreach (['calories_target', 'protein_g', 'carbohydrates_g', 'fat_g', 'fiber_g', 'water_ml'] as $field) {
	if ($nutrition[$field] === null) {
		response_error('NUTRITION_NOT_CALCULATED', 'Nutrition requirements are incomplete. Recalculate them before requesting food recommendations.', 404);
	}
}

$date = new DateTimeImmutable($profile['date_of_birth'], new DateTimeZone(HealthTrackingService::TIMEZONE));
$today = new DateTimeImmutable('today', new DateTimeZone(HealthTrackingService::TIMEZONE));
[$todayStart, $tomorrow] = HealthTrackingService::todayBounds();
$sanitizeText = static function (mixed $value, int $maxLength): string {
	$value = is_string($value) ? trim($value) : '';
	$value = preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '';

	return substr($value, 0, $maxLength);
};
$mealStatement = $database->prepare(
	'SELECT meal_type, food_name, calories, protein_g, carbohydrates_g, fat_g, fiber_g
	 FROM meal_logs WHERE user_id = :user_id AND consumed_at >= :start AND consumed_at < :end
	 ORDER BY consumed_at ASC, id ASC'
);
$mealStatement->execute([
	'user_id' => $userId,
	'start' => $todayStart->format('Y-m-d H:i:s'),
	'end' => $tomorrow->format('Y-m-d H:i:s'),
]);
$meals = [];
$consumed = array_fill_keys(['calories', 'protein_g', 'carbohydrates_g', 'fat_g', 'fiber_g'], 0.0);
while ($meal = $mealStatement->fetch()) {
	$normalizedMeal = [
		'meal_type' => $sanitizeText($meal['meal_type'], 30),
		'food_name' => $sanitizeText($meal['food_name'], 191),
	];
	foreach (array_keys($consumed) as $field) {
		$value = round((float) ($meal[$field] ?? 0), 2);
		$normalizedMeal[$field] = $value;
		$consumed[$field] += $value;
	}
	$meals[] = $normalizedMeal;
}

$targets = [];
foreach (['calories_target' => 'calories', 'protein_g' => 'protein_g', 'carbohydrates_g' => 'carbohydrates_g', 'fat_g' => 'fat_g', 'fiber_g' => 'fiber_g', 'water_ml' => 'water_ml'] as $nutritionField => $contextField) {
	$targets[$contextField] = round((float) $nutrition[$nutritionField], 2);
}
$remaining = [];
foreach (array_keys($consumed) as $field) {
	$remaining[$field] = round($targets[$field] - $consumed[$field], 2);
}

$context = [
	'profile' => [
		'age' => $date->diff($today)->y,
		'gender' => $sanitizeText($profile['gender'], 50),
		'height_cm' => (float) $profile['height_cm'],
		'weight_kg' => (float) $profile['weight_kg'],
		'activity_level' => $sanitizeText($profile['activity_level'], 30),
		'fitness_goal' => $sanitizeText($profile['fitness_goal'], 30),
	],
	'nutrition_targets' => $targets,
	'today_consumed' => $consumed,
	'today_remaining' => $remaining,
	'logged_meals' => $meals,
];

try {
	$startedAt = hrtime(true);
	$recommendations = GeminiService::generateFoodRecommendations($context, $mealType);
} catch (Throwable $exception) {
	$responseTimeMs = isset($startedAt) ? (int) round((hrtime(true) - $startedAt) / 1_000_000) : 0;
	try {
		AIUsageService::record($database, $userId, 'gemini', (string) app_config('GEMINI_MODEL', 'unknown'), 'failed', 503, $responseTimeMs, $exception->getMessage());
	} catch (Throwable $usageException) {
		error_log(sprintf('[%s] AI usage failure logging failed: %s', request_id(), $usageException->getMessage()));
	}
	error_log(sprintf('[%s] Gemini food recommendation request failed: %s', request_id(), $exception->getMessage()));
	response_error('GEMINI_UNAVAILABLE', 'Food recommendations are temporarily unavailable.', 503);
}

$responseTimeMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);
$model = (string) app_config('GEMINI_MODEL', 'unknown');
$generationId = null;
try {
	$database->beginTransaction();
	$generationId = FoodRecommendationHistoryService::save($database, $userId, $mealType, $context, $recommendations, 'gemini', $model);
	AIUsageService::record($database, $userId, 'gemini', $model, 'success', 200, $responseTimeMs);
	$database->commit();
} catch (Throwable $exception) {
	if ($database->inTransaction()) {
		$database->rollBack();
	}
	try {
		AIUsageService::record($database, $userId, 'gemini', $model, 'failed', 500, $responseTimeMs, 'persistence_error');
	} catch (Throwable $usageException) {
		error_log(sprintf('[%s] AI persistence failure logging failed: %s', request_id(), $usageException->getMessage()));
	}
	error_log(sprintf('[%s] Food recommendation persistence failed: %s', request_id(), $exception->getMessage()));
	response_error('AI_PERSISTENCE_FAILED', 'Food recommendations could not be saved.', 500);
}

response_success([
	'recommendations' => $recommendations,
	'generation_id' => $generationId,
	'saved' => true,
	'daily_context' => [
		'calories_target' => $targets['calories'],
		'calories_consumed' => $consumed['calories'],
		'calories_remaining' => $remaining['calories'],
		'protein_target_g' => $targets['protein_g'],
		'protein_consumed_g' => $consumed['protein_g'],
		'protein_remaining_g' => $remaining['protein_g'],
	],
	'generated_by' => 'gemini',
], 'Food recommendations generated.');