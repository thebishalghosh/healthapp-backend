<?php

declare(strict_types=1);

require_once __DIR__ . '/HealthTrackingService.php';
require_once __DIR__ . '/FoodNutritionLookupService.php';

final class FoodScanConfirmationValidationException extends InvalidArgumentException
{
	public function __construct(public readonly array $fields)
	{
		parent::__construct('Food scan confirmation request is invalid.');
	}
}

final class FoodScanConfirmationService
{
	private const MAX_DECIMAL_10_2 = 99_999_999.99;
	private const RETURNED_NUTRIENTS = [
		'calories',
		'protein_g',
		'carbohydrates_g',
		'fat_g',
		'fiber_g',
		'sugar_g',
		'sodium_mg',
	];

	public static function confirm(PDO $database, int $userId, mixed $request): array
	{
		[$mealType, $items] = self::validateRequest($request);
		$database->beginTransaction();

		try {
			$foodStatement = $database->prepare(
				' SELECT id, name, serving_size, serving_unit, calories, protein_g, carbohydrates_g, fat_g, fiber_g, sugar_g, sodium_mg, vitamins, minerals, source, is_active
				  FROM foods
				  WHERE id = :food_id AND is_active = 1
				  FOR UPDATE'
			);
			$preparedItems = [];
			foreach ($items as $index => $item) {
				$foodStatement->execute(['food_id' => $item['food_id']]);
				$food = $foodStatement->fetch(PDO::FETCH_ASSOC);
				if (!$food) {
					throw new FoodScanConfirmationValidationException([
						'items.' . $index . '.food_id' => 'Food does not exist or is inactive.',
					]);
				}

				try {
					$nutrition = FoodNutritionLookupService::scaleCatalogNutrition($food, $item['quantity'], $item['unit']);
				} catch (DomainException $exception) {
					$field = $exception->getMessage() === 'unsupported_catalog_basis'
						? 'items.' . $index . '.unit'
						: 'items.' . $index . '.food_id';
					$message = $exception->getMessage() === 'unsupported_catalog_basis'
						? 'Unit must match the food catalog serving basis.'
						: 'Food catalog nutrition is incomplete.';
					throw new FoodScanConfirmationValidationException([$field => $message]);
				}

				foreach ($nutrition as $value) {
					if (!is_finite((float) $value) || (float) $value > self::MAX_DECIMAL_10_2) {
						throw new FoodScanConfirmationValidationException([
							'items.' . $index . '.quantity' => 'Calculated nutrition exceeds the meal log storage range.',
						]);
					}
				}

				$preparedItems[] = [
					'food' => $food,
					'quantity' => $item['quantity'],
					'unit' => $item['unit'],
					'nutrition' => $nutrition,
				];
			}

			$insertStatement = $database->prepare(
				'INSERT INTO meal_logs
					(user_id, food_id, meal_type, food_name, quantity, unit, calories, protein_g, carbohydrates_g, fat_g, fiber_g, consumed_at, source)
				 VALUES
					(:user_id, :food_id, :meal_type, :food_name, :quantity, :unit, :calories, :protein_g, :carbohydrates_g, :fat_g, :fiber_g, :consumed_at, :source)'
			);
			$confirmedItems = [];
			foreach ($preparedItems as $item) {
				$food = $item['food'];
				$nutrition = $item['nutrition'];
				$insertStatement->execute([
					'user_id' => $userId,
					'food_id' => (int) $food['id'],
					'meal_type' => $mealType,
					'food_name' => $food['name'],
					'quantity' => $item['quantity'],
					'unit' => $item['unit'],
					'calories' => $nutrition['calories'],
					'protein_g' => $nutrition['protein_g'],
					'carbohydrates_g' => $nutrition['carbohydrates_g'],
					'fat_g' => $nutrition['fat_g'],
					'fiber_g' => $nutrition['fiber_g'],
					'consumed_at' => HealthTrackingService::now()->format('Y-m-d H:i:s'),
					'source' => 'food_scan',
				]);

				$confirmedItem = [
					'meal_log_id' => (int) $database->lastInsertId(),
					'food_id' => (int) $food['id'],
					'food_name' => (string) $food['name'],
					'quantity' => $item['quantity'],
					'unit' => $item['unit'],
				];
				foreach (self::RETURNED_NUTRIENTS as $nutrient) {
					$confirmedItem[$nutrient] = $nutrition[$nutrient];
				}
				$confirmedItems[] = $confirmedItem;
			}

			$database->commit();

			return ['meal_type' => $mealType, 'items' => $confirmedItems];
		} catch (Throwable $exception) {
			if ($database->inTransaction()) {
				$database->rollBack();
			}
			throw $exception;
		}
	}

	private static function validateRequest(mixed $request): array
	{
		if (!is_array($request) || array_is_list($request)) {
			throw new FoodScanConfirmationValidationException(['_request' => 'A JSON object is required.']);
		}

		$fields = [];
		foreach ($request as $field => $_value) {
			if (!in_array($field, ['meal_type', 'items'], true)) {
				$fields[(string) $field] = 'This field is not supported.';
			}
		}

		$mealType = $request['meal_type'] ?? null;
		if (!is_string($mealType) || !in_array($mealType, HealthTrackingService::MEAL_TYPES, true)) {
			$fields['meal_type'] = 'Meal type must be breakfast, lunch, dinner, or snack.';
		}

		$items = $request['items'] ?? null;
		if (!is_array($items) || !array_is_list($items) || $items === []) {
			$fields['items'] = 'At least one food item is required.';
			$items = [];
		}

		$validatedItems = [];
		foreach ($items as $index => $item) {
			$prefix = 'items.' . $index;
			if (!is_array($item) || array_is_list($item)) {
				$fields[$prefix] = 'Each item must be a JSON object.';
				continue;
			}

			foreach ($item as $field => $_value) {
				if (!in_array($field, ['food_id', 'quantity', 'unit'], true)) {
					$fields[$prefix . '.' . $field] = 'This field is not supported.';
				}
			}

			$foodId = $item['food_id'] ?? null;
			if (!is_int($foodId) || $foodId < 1) {
				$fields[$prefix . '.food_id'] = 'Food ID must be a positive integer.';
			}

			$quantity = $item['quantity'] ?? null;
			if (!HealthTrackingService::validNumber($quantity, 0, self::MAX_DECIMAL_10_2) || (float) $quantity <= 0) {
				$fields[$prefix . '.quantity'] = 'Quantity must be greater than zero and within the supported storage range.';
			} else {
				$quantity = round((float) $quantity, 2);
				if ($quantity <= 0) {
					$fields[$prefix . '.quantity'] = 'Quantity must be at least 0.01.';
				}
			}

			$unit = $item['unit'] ?? null;
			if (!is_string($unit) || !in_array(strtolower(trim($unit)), ['g', 'ml'], true)) {
				$fields[$prefix . '.unit'] = 'Unit must be g or ml and compatible with the catalog serving basis.';
			} else {
				$unit = strtolower(trim($unit));
			}

			if (is_int($foodId) && $foodId > 0 && is_numeric($quantity) && (float) $quantity > 0 && is_string($unit) && in_array($unit, ['g', 'ml'], true)) {
				$validatedItems[] = ['food_id' => $foodId, 'quantity' => $quantity, 'unit' => $unit];
			}
		}

		if ($fields !== []) {
			throw new FoodScanConfirmationValidationException($fields);
		}

		return [$mealType, $validatedItems];
	}
}