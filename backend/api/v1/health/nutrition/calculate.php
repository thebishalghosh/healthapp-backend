<?php

declare(strict_types=1);

if (!function_exists('response_error')) {
	require_once dirname(__DIR__, 4) . '/bootstrap.php';
}
require_once dirname(__DIR__, 4) . '/core/auth.php';
require_once dirname(__DIR__, 4) . '/services/NutritionService.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
	response_error('METHOD_NOT_ALLOWED', 'Method not allowed.', 405);
}

$user = authenticated_user();
$userId = (int) $user['id'];
$profileStatement = database_connection()->prepare(
	'SELECT date_of_birth, gender, height_cm, weight_kg, activity_level, fitness_goal
	 FROM user_profiles WHERE user_id = :user_id LIMIT 1'
);
$profileStatement->execute(['user_id' => $userId]);
$profile = $profileStatement->fetch() ?: [];
$errors = NutritionService::validate_profile($profile);

if ($errors !== []) {
	response_validation_error($errors, 'Complete your health profile before calculating nutrition requirements.');
}

$result = NutritionService::calculate($profile);
$statement = database_connection()->prepare(
	'INSERT INTO nutrition_requirements
	 (user_id, calculated_date, calories_target, protein_g, carbohydrates_g, fat_g, fiber_g, water_ml, calculation_method, calculation_version, source, metadata)
	 VALUES (:user_id, :calculated_date, :calories_target, :protein_g, :carbohydrates_g, :fat_g, :fiber_g, :water_ml, :calculation_method, :calculation_version, :source, :metadata)'
);
$statement->execute([
	'user_id' => $userId,
	'calculated_date' => $result['calculated_date'],
	'calories_target' => $result['calories_target'],
	'protein_g' => $result['protein_g'],
	'carbohydrates_g' => $result['carbohydrates_g'],
	'fat_g' => $result['fat_g'],
	'fiber_g' => $result['fiber_g'],
	'water_ml' => $result['water_ml'],
	'calculation_method' => $result['calculation_method'],
	'calculation_version' => $result['calculation_version'],
	'source' => $result['source'],
	'metadata' => json_encode($result['metadata'], JSON_THROW_ON_ERROR),
]);

$result['id'] = (int) database_connection()->lastInsertId();
response_success(['nutrition' => $result], 'Nutrition requirements calculated.', 201);