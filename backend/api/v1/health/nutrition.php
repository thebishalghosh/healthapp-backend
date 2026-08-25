<?php

declare(strict_types=1);

if (!function_exists('response_error')) {
	require_once dirname(__DIR__, 3) . '/bootstrap.php';
}
require_once dirname(__DIR__, 3) . '/core/auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
	response_error('METHOD_NOT_ALLOWED', 'Method not allowed.', 405);
}

$user = authenticated_user();
$statement = database_connection()->prepare(
	'SELECT calculated_date, calories_target, protein_g, carbohydrates_g, fat_g, fiber_g, water_ml,
		calculation_method, calculation_version, source, metadata
	 FROM nutrition_requirements
	 WHERE user_id = :user_id
	 ORDER BY created_at DESC
	 LIMIT 1'
);
$statement->execute(['user_id' => (int) $user['id']]);
$nutrition = $statement->fetch();

if (!$nutrition) {
	response_error('NUTRITION_NOT_CALCULATED', 'Nutrition requirements have not been calculated yet.', 404);
}

if ($nutrition['metadata'] !== null) {
	$nutrition['metadata'] = json_decode($nutrition['metadata'], true);
}

response_success(['nutrition' => $nutrition], 'Nutrition requirements retrieved.');