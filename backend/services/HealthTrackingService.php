<?php

declare(strict_types=1);

final class HealthTrackingService
{
	public const TIMEZONE = 'UTC';

	public const MEAL_TYPES = ['breakfast', 'lunch', 'dinner', 'snack'];

	public static function now(): DateTimeImmutable
	{
		return new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE));
	}

	public static function todayBounds(): array
	{
		$today = new DateTimeImmutable('today', new DateTimeZone(self::TIMEZONE));

		return [$today, $today->modify('+1 day')];
	}

	public static function validNumber(mixed $value, float $minimum, ?float $maximum = null): bool
	{
		if (!is_numeric($value)) {
			return false;
		}

		$number = (float) $value;

		return is_finite($number) && $number >= $minimum && ($maximum === null || $number <= $maximum);
	}

	public static function validDate(mixed $value): bool
	{
		if (!is_string($value)) {
			return false;
		}

		$date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone(self::TIMEZONE));

		return $date !== false && $date->format('Y-m-d') === $value;
	}

	public static function validTime(mixed $value): bool
	{
		return is_string($value) && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $value) === 1;
	}

	public static function normalizeTime(string $value): string
	{
		return strlen($value) === 5 ? $value . ':00' : $value;
	}

	public static function latestNutrition(PDO $database, int $userId): ?array
	{
		$statement = $database->prepare(
			'SELECT n.id, n.calories_target, n.water_ml, n.created_at, up.updated_at AS profile_updated_at
			 FROM nutrition_requirements n
			 LEFT JOIN user_profiles up ON up.user_id = n.user_id
			 WHERE n.user_id = :user_id
			 ORDER BY n.created_at DESC, n.id DESC
			 LIMIT 1'
		);
		$statement->execute(['user_id' => $userId]);
		$nutrition = $statement->fetch();

		if (!$nutrition) {
			return null;
		}

		$nutrition['stale'] = $nutrition['profile_updated_at'] !== null && $nutrition['profile_updated_at'] > $nutrition['created_at'];

		return $nutrition;
	}

	public static function nutritionOrError(PDO $database, int $userId): array
	{
		$nutrition = self::latestNutrition($database, $userId);

		if ($nutrition === null) {
			response_error('NUTRITION_NOT_CALCULATED', 'Nutrition requirements must be calculated before tracking health data.', 404);
		}

		if ($nutrition['stale']) {
			response_error('NUTRITION_STALE', 'Nutrition requirements are out of date. Recalculate them after updating your health profile.', 409);
		}

		return $nutrition;
	}

	public static function nutritionSummary(PDO $database, int $userId): ?array
	{
		$nutrition = self::latestNutrition($database, $userId);

		if ($nutrition === null) {
			return null;
		}

		if ($nutrition['stale']) {
			response_error('NUTRITION_STALE', 'Nutrition requirements are out of date. Recalculate them after updating your health profile.', 409);
		}

		return $nutrition;
	}
}
