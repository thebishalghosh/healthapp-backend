<?php

declare(strict_types=1);

final class FoodLocationValidationException extends InvalidArgumentException
{
	public function __construct(public readonly array $fields)
	{
		parent::__construct('Food location validation failed.');
	}
}

final class FoodLocationService
{
	public static function validate(array $body): array
	{
		$fields = [];
		foreach ($body as $field => $_) {
			if (!in_array($field, ['country', 'country_code', 'state', 'city'], true)) {
				$fields[$field] = 'This field is not supported.';
			}
		}

		$country = self::normalize($body['country'] ?? null);
		if (!self::validText($country, 2, 100) || preg_match('/\pL/u', $country) !== 1) {
			$fields['country'] = 'Country is required and must be 2 to 100 characters.';
		}
		$countryCode = $body['country_code'] ?? null;
		if ($countryCode !== null && (!is_string($countryCode) || preg_match('/^[A-Za-z]{2}$/', $countryCode) !== 1)) {
			$fields['country_code'] = 'Country code must be a two-letter ISO 3166-1 alpha-2 code.';
		}

		$location = ['country' => $country, 'country_code' => $countryCode === null ? null : strtoupper($countryCode)];
		foreach (['state' => 100, 'city' => 100] as $field => $maximum) {
			$value = self::normalize($body[$field] ?? null);
			if ($value !== null && !self::validText($value, 1, $maximum)) {
				$fields[$field] = ucfirst($field) . ' must be at most ' . $maximum . ' characters and contain no control characters.';
			}
			$location[$field] = $value;
		}
		if ($fields !== []) {
			throw new FoodLocationValidationException($fields);
		}

		return $location;
	}

	public static function get(PDO $database, int $userId): ?array
	{
		$statement = $database->prepare(
			'SELECT country, country_code, state, city, created_at, updated_at
			 FROM user_food_locations WHERE user_id = :user_id LIMIT 1'
		);
		$statement->execute(['user_id' => $userId]);
		$location = $statement->fetch();

		return $location ?: null;
	}

	public static function save(PDO $database, int $userId, array $body): array
	{
		$location = self::validate($body);
		$statement = $database->prepare(
			'INSERT INTO user_food_locations (user_id, country, country_code, state, city)
			 VALUES (:user_id, :country, :country_code, :state, :city)
			 ON DUPLICATE KEY UPDATE country = VALUES(country), country_code = VALUES(country_code),
			 state = VALUES(state), city = VALUES(city), updated_at = CURRENT_TIMESTAMP'
		);
		$statement->execute([
			'user_id' => $userId,
			'country' => $location['country'],
			'country_code' => $location['country_code'],
			'state' => $location['state'],
			'city' => $location['city'],
		]);

		return self::get($database, $userId) ?? throw new RuntimeException('Saved food location could not be retrieved.');
	}

	public static function delete(PDO $database, int $userId): bool
	{
		$statement = $database->prepare('DELETE FROM user_food_locations WHERE user_id = :user_id');
		$statement->execute(['user_id' => $userId]);

		return $statement->rowCount() > 0;
	}

	private static function normalize(mixed $value): ?string
	{
		if ($value === null) {
			return null;
		}
		if (!is_string($value)) {
			return '';
		}
		$value = trim($value);

		return $value === '' ? null : $value;
	}

	private static function validText(?string $value, int $minimum, int $maximum): bool
	{
		if ($value === null || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
			return false;
		}
		$length = preg_match_all('/./us', $value, $characters);

		return $length !== false && $length >= $minimum && $length <= $maximum;
	}
}
