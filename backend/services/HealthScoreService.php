<?php

declare(strict_types=1);

final class HealthScoreService
{
	private const COMPONENT_WEIGHTS = [
		'nutrition' => 0.30,
		'hydration' => 0.20,
		'workout' => 0.20,
		'sleep' => 0.20,
		'consistency' => 0.10,
	];

	private const SLEEP_FULL_SCORE_MINUTES = 420;
	private const SLEEP_FULL_SCORE_MAX_MINUTES = 540;
	private const WORKOUT_TARGET_MINUTES = 30;

	public static function dailySummary(
		PDO $database,
		int $userId,
		DateTimeImmutable $localDate,
		DateTimeZone $timezone,
		?array $nutritionTargets
	): array {
		if ($userId < 1 || $localDate->format('H:i:s') !== '00:00:00') {
			throw new InvalidArgumentException('A valid user ID and local calendar date are required.');
		}

		$date = $localDate->format('Y-m-d');
		[$start, $dayEnd] = HealthTrackingService::utcDateBounds($date, $timezone->getName());
		$utcNow = HealthTrackingService::now()->format('Y-m-d H:i:s');
		$end = min($dayEnd, $utcNow);
		if ($end < $start) {
			$end = $start;
		}

		$foodStatement = $database->prepare(
			'SELECT COUNT(*) AS meal_count,
				COUNT(calories) AS calories_count,
				COUNT(protein_g) AS protein_g_count,
				COUNT(carbohydrates_g) AS carbohydrates_g_count,
				COUNT(fat_g) AS fat_g_count,
				COUNT(fiber_g) AS fiber_g_count,
				COALESCE(SUM(calories), 0) AS calories,
				COALESCE(SUM(protein_g), 0) AS protein_g,
				COALESCE(SUM(carbohydrates_g), 0) AS carbohydrates_g,
				COALESCE(SUM(fat_g), 0) AS fat_g,
				COALESCE(SUM(fiber_g), 0) AS fiber_g
			 FROM meal_logs
			 WHERE user_id = :user_id AND consumed_at >= :start AND consumed_at < :end'
		);
		$foodStatement->execute(['user_id' => $userId, 'start' => $start, 'end' => $end]);
		$food = $foodStatement->fetch(PDO::FETCH_ASSOC);

		$waterStatement = $database->prepare(
			'SELECT COUNT(*) AS entry_count, COALESCE(SUM(amount_ml), 0) AS consumed_ml
			 FROM water_logs
			 WHERE user_id = :user_id AND consumed_at >= :start AND consumed_at < :end'
		);
		$waterStatement->execute(['user_id' => $userId, 'start' => $start, 'end' => $end]);
		$water = $waterStatement->fetch(PDO::FETCH_ASSOC);

		$workoutStatement = $database->prepare(
			'SELECT COUNT(*) AS workout_count,
				COALESCE(SUM(CASE WHEN duration_minutes > 0 THEN duration_minutes ELSE 0 END), 0) AS duration_minutes,
				COALESCE(SUM(calories_burned), 0) AS calories_burned
			 FROM workout_logs
			 WHERE user_id = :user_id AND workout_date >= :start AND workout_date < :end'
		);
		$workoutStatement->execute(['user_id' => $userId, 'start' => $start, 'end' => $end]);
		$workout = $workoutStatement->fetch(PDO::FETCH_ASSOC);

		$sleepStatement = $database->prepare(
			'SELECT id, sleep_start, sleep_end, duration_minutes
			 FROM sleep_logs
			 WHERE user_id = :user_id AND sleep_start >= :start AND sleep_start < :end
			   AND sleep_end <= :cutoff AND duration_minutes > 0
			 ORDER BY sleep_start DESC, id DESC
			 LIMIT 1'
		);
		$sleepStatement->execute(['user_id' => $userId, 'start' => $start, 'end' => $end, 'cutoff' => $end]);
		$sleepRow = $sleepStatement->fetch(PDO::FETCH_ASSOC);
		$sleep = null;
		if ($sleepRow) {
			$utc = new DateTimeZone(HealthTrackingService::TIMEZONE);
			$sleepStart = (new DateTimeImmutable($sleepRow['sleep_start'], $utc))->setTimezone($timezone);
			$sleepEnd = (new DateTimeImmutable($sleepRow['sleep_end'], $utc))->setTimezone($timezone);
			$sleep = [
				'id' => (int) $sleepRow['id'],
				'sleep_date' => $sleepStart->format('Y-m-d'),
				'bedtime' => $sleepStart->format('H:i:s'),
				'wake_time' => $sleepEnd->format('H:i:s'),
				'duration_minutes' => (int) $sleepRow['duration_minutes'],
			];
		}

		$food = [
			'meal_count' => (int) $food['meal_count'],
			'nutrient_counts' => [
				'calories' => (int) $food['calories_count'],
				'protein_g' => (int) $food['protein_g_count'],
				'carbohydrates_g' => (int) $food['carbohydrates_g_count'],
				'fat_g' => (int) $food['fat_g_count'],
				'fiber_g' => (int) $food['fiber_g_count'],
			],
			'calories' => (float) $food['calories'],
			'protein_g' => (float) $food['protein_g'],
			'carbohydrates_g' => (float) $food['carbohydrates_g'],
			'fat_g' => (float) $food['fat_g'],
			'fiber_g' => (float) $food['fiber_g'],
		];
		$water = [
			'entry_count' => (int) $water['entry_count'],
			'consumed_ml' => (float) $water['consumed_ml'],
		];
		$workout = [
			'workout_count' => (int) $workout['workout_count'],
			'duration_minutes' => (int) $workout['duration_minutes'],
			'calories_burned' => (float) $workout['calories_burned'],
		];

		return [
			'date' => $date,
			'food' => $food,
			'water' => $water,
			'workout' => $workout,
			'sleep' => $sleep,
			'health_score' => self::calculate($nutritionTargets, $food, $water, $workout, $sleep),
		];
	}

	public static function calculate(
		?array $nutritionTargets,
		array $food,
		array $water,
		array $workout,
		?array $sleep
	): array {
		$components = [
			'nutrition' => self::nutritionScore($nutritionTargets, $food),
			'hydration' => self::hydrationScore($nutritionTargets, $water),
			'workout' => self::workoutScore($workout),
			'sleep' => self::sleepScore($sleep),
			'consistency' => self::consistencyScore($food, $water, $workout, $sleep),
		];
		foreach ($components as $component => $score) {
			if ($score !== null) {
				$components[$component] = round(max(0.0, min(100.0, $score)), 2);
			}
		}

		$weightedTotal = 0.0;
		$availableWeight = 0.0;
		foreach (self::COMPONENT_WEIGHTS as $component => $weight) {
			if ($components[$component] === null) {
				continue;
			}
			$weightedTotal += $components[$component] * $weight;
			$availableWeight += $weight;
		}

		return [
			'score' => $availableWeight > 0 ? (int) round($weightedTotal / $availableWeight) : null,
			'components' => $components,
			'available_components' => array_values(array_filter(
				array_keys($components),
				static fn (string $component): bool => $components[$component] !== null
			)),
		];
	}

	private static function nutritionScore(?array $targets, array $food): ?float
	{
		if ($targets === null) {
			return null;
		}
		if (($food['meal_count'] ?? 0) < 1) {
			foreach (['calories_target', 'protein_g', 'carbohydrates_g', 'fat_g', 'fiber_g'] as $targetField) {
				if (self::isPositiveNumber($targets[$targetField] ?? null)) {
					return 0.0;
				}
			}

			return null;
		}

		$nutrients = [
			'calories_target' => 'calories',
			'protein_g' => 'protein_g',
			'carbohydrates_g' => 'carbohydrates_g',
			'fat_g' => 'fat_g',
			'fiber_g' => 'fiber_g',
		];
		$scores = [];
		foreach ($nutrients as $targetField => $intakeField) {
			$target = $targets[$targetField] ?? null;
			$intake = $food[$intakeField] ?? null;
			$nutrientCount = $food['nutrient_counts'][$intakeField] ?? null;
			if (isset($food['nutrient_counts'])
				&& (!is_int($nutrientCount) || $nutrientCount < (int) ($food['meal_count'] ?? 0))) {
				continue;
			}
			if (!self::isPositiveNumber($target) || !self::isNonNegativeNumber($intake)) {
				continue;
			}
			$deviation = abs(((float) $intake - (float) $target) / (float) $target);
			$scores[] = max(0.0, min(100.0, 100.0 - max(0.0, $deviation - 0.1) * 100.0));
		}

		return $scores === [] ? null : self::average($scores);
	}

	private static function hydrationScore(?array $targets, array $water): ?float
	{
		$target = $targets['water_ml'] ?? null;
		$consumed = $water['consumed_ml'] ?? null;
		if (!self::isPositiveNumber($target) || !self::isNonNegativeNumber($consumed)) {
			return null;
		}

		return min(100.0, (float) $consumed / (float) $target * 100.0);
	}

	private static function workoutScore(array $workout): float
	{
		$minutes = $workout['duration_minutes'] ?? 0;
		if (!self::isNonNegativeNumber($minutes)) {
			return 0.0;
		}

		return min(100.0, (float) $minutes / self::WORKOUT_TARGET_MINUTES * 100.0);
	}

	private static function sleepScore(?array $sleep): float
	{
		$minutes = $sleep['duration_minutes'] ?? null;
		if (!self::isNonNegativeNumber($minutes) || (float) $minutes === 0.0) {
			return 0.0;
		}
		if ((float) $minutes >= self::SLEEP_FULL_SCORE_MINUTES && (float) $minutes <= self::SLEEP_FULL_SCORE_MAX_MINUTES) {
			return 100.0;
		}

		$distanceOutsideRange = (float) $minutes < self::SLEEP_FULL_SCORE_MINUTES
			? self::SLEEP_FULL_SCORE_MINUTES - (float) $minutes
			: (float) $minutes - self::SLEEP_FULL_SCORE_MAX_MINUTES;

		return max(0.0, 100.0 - $distanceOutsideRange / 60.0 * 25.0);
	}

	private static function consistencyScore(array $food, array $water, array $workout, ?array $sleep): float
	{
		$engagedCategories = 0;
		$engagedCategories += ($food['meal_count'] ?? 0) > 0 ? 1 : 0;
		$engagedCategories += ($water['entry_count'] ?? 0) > 0 && ($water['consumed_ml'] ?? 0) > 0 ? 1 : 0;
		$engagedCategories += ($workout['duration_minutes'] ?? 0) > 0 ? 1 : 0;
		$engagedCategories += ($sleep['duration_minutes'] ?? 0) > 0 ? 1 : 0;

		return $engagedCategories / 4.0 * 100.0;
	}

	private static function average(array $values): float
	{
		return array_sum($values) / count($values);
	}

	private static function isPositiveNumber(mixed $value): bool
	{
		return self::isNonNegativeNumber($value) && (float) $value > 0;
	}

	private static function isNonNegativeNumber(mixed $value): bool
	{
		return is_numeric($value) && is_finite((float) $value) && (float) $value >= 0;
	}
}
