<?php

declare(strict_types=1);

final class NutritionService
{
	private const ACTIVITY_MULTIPLIERS = [
		'sedentary' => 1.2,
		'light' => 1.375,
		'moderate' => 1.55,
		'active' => 1.725,
		'very_active' => 1.9,
	];

	private const GOAL_ADJUSTMENTS = [
		'weight_loss' => -0.15,
		'weight_gain' => 0.10,
		'muscle_gain' => 0.10,
		'maintenance' => 0.0,
		'general_wellness' => 0.0,
	];

	public static function validate_profile(array $profile): array
	{
		$errors = [];
		$required = ['date_of_birth', 'gender', 'height_cm', 'weight_kg', 'activity_level', 'fitness_goal'];

		foreach ($required as $field) {
			if (!array_key_exists($field, $profile) || $profile[$field] === null || $profile[$field] === '') {
				$errors[$field] = 'This profile field is required for nutrition calculation.';
			}
		}

		$date = is_string($profile['date_of_birth'] ?? null)
			? DateTimeImmutable::createFromFormat('!Y-m-d', $profile['date_of_birth'])
			: false;
		$today = new DateTimeImmutable('today');

		if (!$date || $date->format('Y-m-d') !== ($profile['date_of_birth'] ?? null) || $date > $today) {
			$errors['date_of_birth'] = 'Date of birth must be a valid date that is not in the future.';
		} else {
			$age = $date->diff($today)->y;
			if ($age < 13 || $age > 120) {
				$errors['date_of_birth'] = 'Age must be between 13 and 120 years.';
			}
		}

		if (!in_array($profile['gender'] ?? null, ['male', 'female'], true)) {
			$errors['gender'] = 'Gender must be male or female for this calculation method.';
		}

		if (!is_numeric($profile['height_cm'] ?? null) || (float) $profile['height_cm'] <= 0 || (float) $profile['height_cm'] > 300) {
			$errors['height_cm'] = 'Height must be greater than 0 and at most 300 cm.';
		}

		if (!is_numeric($profile['weight_kg'] ?? null) || (float) $profile['weight_kg'] <= 0 || (float) $profile['weight_kg'] > 500) {
			$errors['weight_kg'] = 'Weight must be greater than 0 and at most 500 kg.';
		}

		if (!array_key_exists($profile['activity_level'] ?? '', self::ACTIVITY_MULTIPLIERS)) {
			$errors['activity_level'] = 'Activity level is invalid.';
		}

		if (!array_key_exists($profile['fitness_goal'] ?? '', self::GOAL_ADJUSTMENTS)) {
			$errors['fitness_goal'] = 'Fitness goal is invalid.';
		}

		return $errors;
	}

	public static function calculate(array $profile): array
	{
		$date = new DateTimeImmutable($profile['date_of_birth']);
		$age = $date->diff(new DateTimeImmutable('today'))->y;
		$weight = (float) $profile['weight_kg'];
		$height = (float) $profile['height_cm'];
		$bmr = 10 * $weight + 6.25 * $height - 5 * $age + ($profile['gender'] === 'male' ? 5 : -161);
		$activityMultiplier = self::ACTIVITY_MULTIPLIERS[$profile['activity_level']];
		$tdee = $bmr * $activityMultiplier;
		$goalAdjustment = self::GOAL_ADJUSTMENTS[$profile['fitness_goal']];
		$calories = $tdee * (1 + $goalAdjustment);
		$protein = 1.6 * $weight;
		$fat = ($calories * 0.25) / 9;
		$carbohydrates = max(0, ($calories - ($protein * 4) - ($fat * 9)) / 4);
		$fiber = ($calories / 1000) * 14;
		$water = 35 * $weight;

		return [
			'calculated_date' => gmdate('Y-m-d'),
			'calories_target' => round($calories, 2),
			'protein_g' => round($protein, 2),
			'carbohydrates_g' => round($carbohydrates, 2),
			'fat_g' => round($fat, 2),
			'fiber_g' => round($fiber, 2),
			'water_ml' => round($water, 2),
			'calculation_method' => 'mifflin_st_jeor',
			'calculation_version' => 'v1',
			'source' => 'system',
			'metadata' => [
				'age' => $age,
				'gender' => $profile['gender'],
				'height_cm' => round($height, 2),
				'weight_kg' => round($weight, 2),
				'activity_level' => $profile['activity_level'],
				'activity_multiplier' => $activityMultiplier,
				'fitness_goal' => $profile['fitness_goal'],
				'goal_adjustment' => $goalAdjustment,
				'bmr' => round($bmr, 2),
				'tdee' => round($tdee, 2),
			],
		];
	}
}
