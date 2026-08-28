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
	'SELECT n.id, n.calculated_date, n.calories_target, n.protein_g, n.carbohydrates_g, n.fat_g, n.fiber_g, n.water_ml,
		n.calculation_method, n.calculation_version, n.source, n.metadata, n.created_at,
		up.updated_at AS profile_updated_at
	 FROM nutrition_requirements n
	 LEFT JOIN user_profiles up ON up.user_id = n.user_id
	 WHERE n.user_id = :user_id
	 ORDER BY n.created_at DESC, n.id DESC
	 LIMIT 1'
);
$statement->execute(['user_id' => (int) $user['id']]);
$nutrition = $statement->fetch();

if (!$nutrition) {
	response_error('NUTRITION_NOT_CALCULATED', 'Nutrition requirements have not been calculated yet.', 404);
}

$createdAt = $nutrition['created_at'];
$profileUpdatedAt = $nutrition['profile_updated_at'];
unset($nutrition['created_at'], $nutrition['profile_updated_at']);

if ($profileUpdatedAt !== null && $profileUpdatedAt > $createdAt) {
	response_error('NUTRITION_STALE', 'Nutrition requirements are out of date. Recalculate them after updating your health profile.', 409);
}

if ($nutrition['metadata'] !== null) {
	$nutrition['metadata'] = json_decode($nutrition['metadata'], true);
}

foreach (['calories_target', 'protein_g', 'carbohydrates_g', 'fat_g', 'fiber_g', 'water_ml'] as $field) {
	$nutrition[$field] = (float) $nutrition[$field];
}

$nutrition['id'] = (int) $nutrition['id'];

response_success(['nutrition' => $nutrition], 'Nutrition requirements retrieved.');