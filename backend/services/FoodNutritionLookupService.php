<?php

declare(strict_types=1);

final class FoodNutritionLookupService
{
	private const ALIASES = [
		'banana' => 'Banana (Raw)',
		'roti' => 'Roti/Chapati (Plain, Commercially Prepared)',
		'chapati' => 'Roti/Chapati (Plain, Commercially Prepared)',
		'cucumber' => 'Cucumber with Peel (Raw)',
		'cucumber slices' => 'Cucumber with Peel (Raw)',
		'sliced cucumber' => 'Cucumber with Peel (Raw)',
		'tomato' => 'Tomato (Raw)',
		'tomato slices' => 'Tomato (Raw)',
		'sliced tomato' => 'Tomato (Raw)',
		'carrot' => 'Carrot (Raw)',
		'white rice' => 'White Rice, Cooked (Salted, No Added Fat)',
		'steamed white rice' => 'White Rice, Cooked (Salted, No Added Fat)',
		'cooked white rice' => 'White Rice, Cooked (Salted, No Added Fat)',
		'white rice cooked with salt' => 'White Rice, Cooked (Salted, No Added Fat)',
		'brown rice' => 'Brown Rice, Cooked (Salted, No Added Fat)',
		'steamed brown rice' => 'Brown Rice, Cooked (Salted, No Added Fat)',
		'cooked brown rice' => 'Brown Rice, Cooked (Salted, No Added Fat)',
		'brown rice cooked with salt' => 'Brown Rice, Cooked (Salted, No Added Fat)',
		'mango' => 'Mango (Raw)',
		'mangos' => 'Mango (Raw)',
		'watermelon' => 'Watermelon (Raw)',
		'water melon' => 'Watermelon (Raw)',
		'papaya' => 'Papaya (Raw)',
		'papayas' => 'Papaya (Raw)',
		'guava' => 'Guava (Raw)',
		'guavas' => 'Guava (Raw)',
	];

	private const NUTRIENTS = [
		'calories',
		'protein_g',
		'carbohydrates_g',
		'fat_g',
		'fiber_g',
		'sugar_g',
		'sodium_mg',
	];

	private const RICE_CANDIDATE_NAMES = [
		'White Rice, Cooked (Salted, No Added Fat)',
		'Brown Rice, Cooked (Salted, No Added Fat)',
	];

	public static function normalizeName(string $name): string
	{
		if (class_exists('Normalizer')) {
			$unicodeNormalized = Normalizer::normalize($name, Normalizer::FORM_KC);
			if (is_string($unicodeNormalized)) {
				$name = $unicodeNormalized;
			}
		}

		$name = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
		$name = preg_replace('/[\p{P}\p{S}]+/u', ' ', $name);
		if (!is_string($name)) {
			return '';
		}

		$name = preg_replace('/\s+/u', ' ', $name);

		return is_string($name) ? trim($name) : '';
	}

	public static function findCandidates(PDO $database, mixed $detectedFoodName): array
	{
		if (!is_string($detectedFoodName)) {
			return [];
		}

		$normalizedName = self::normalizeName(trim($detectedFoodName));
		if ($normalizedName === '') {
			return [];
		}

		$catalogStatement = $database->query(
			'SELECT id, name, serving_unit
			 FROM foods
			 WHERE is_active = 1
			 ORDER BY id ASC'
		);
		$catalogByName = [];
		foreach ($catalogStatement->fetchAll(PDO::FETCH_ASSOC) as $food) {
			$catalogKey = self::normalizeName((string) $food['name']);
			if ($catalogKey !== '') {
				$catalogByName[$catalogKey][] = $food;
			}
		}

		$lookupName = $normalizedName;
		if (isset(self::ALIASES[$normalizedName])) {
			$lookupName = self::normalizeName(self::ALIASES[$normalizedName]);
		}
		$matchedRows = $catalogByName[$lookupName] ?? null;

		if ($matchedRows === null && $normalizedName === 'rice') {
			$matchedRows = [];
			foreach (self::RICE_CANDIDATE_NAMES as $candidateName) {
				$rows = $catalogByName[self::normalizeName($candidateName)] ?? [];
				foreach ($rows as $row) {
					$matchedRows[] = $row;
				}
			}
		}

		if ($matchedRows === null) {
			return [];
		}

		return array_map(
			static fn (array $food): array => [
				'food_id' => (int) $food['id'],
				'name' => (string) $food['name'],
				'serving_unit' => (string) $food['serving_unit'],
			],
			$matchedRows
		);
	}

	public static function resolveCatalogCandidate(
		PDO $database,
		mixed $detectedFoodName,
		mixed $quantity,
		mixed $quantityUnit,
		mixed $confidence,
		mixed $selectedFoodId,
		array $candidates
	): array {
		$displayName = is_string($detectedFoodName) ? trim($detectedFoodName) : '';
		$displayQuantity = is_numeric($quantity) && is_finite((float) $quantity) ? round((float) $quantity, 2) : null;
		$unit = is_string($quantityUnit) ? strtolower(trim($quantityUnit)) : 'unknown';
		if ($unit === '') {
			$unit = 'unknown';
		}

		$normalizedConfidence = null;
		if ($confidence !== null) {
			if (!is_numeric($confidence) || !is_finite((float) $confidence) || (float) $confidence < 0 || (float) $confidence > 1) {
				return self::unresolved('invalid_confidence', $displayName, $displayQuantity, $unit);
			}
			$normalizedConfidence = (float) $confidence;
		}

		if ($selectedFoodId === null) {
			return self::unresolved('ambiguous_catalog_selection', $displayName, $displayQuantity, $unit, $normalizedConfidence);
		}
		if ($displayName === '') {
			return self::unresolved('missing_food_name', $displayName, $displayQuantity, $unit, $normalizedConfidence);
		}
		if (!is_numeric($quantity) || !is_finite((float) $quantity) || (float) $quantity <= 0) {
			return self::unresolved('invalid_quantity', $displayName, $displayQuantity, $unit, $normalizedConfidence);
		}
		if ($unit === 'ml') {
			return self::unresolved('volume_unit_not_supported', $displayName, $displayQuantity, $unit, $normalizedConfidence);
		}
		if ($unit === 'unknown') {
			return self::unresolved('unknown_quantity_unit', $displayName, $displayQuantity, $unit, $normalizedConfidence);
		}
		if ($unit !== 'g') {
			return self::unresolved('unsupported_quantity_unit', $displayName, $displayQuantity, $unit, $normalizedConfidence);
		}

		if (!is_int($selectedFoodId) || $selectedFoodId < 1) {
			return self::unresolved('invalid_catalog_selection', $displayName, $displayQuantity, $unit, $normalizedConfidence);
		}

		$candidateIds = [];
		foreach ($candidates as $candidate) {
			if (is_array($candidate) && is_int($candidate['food_id'] ?? null)) {
				$candidateIds[$candidate['food_id']] = true;
			}
		}
		if (!isset($candidateIds[$selectedFoodId])) {
			return self::unresolved('invalid_catalog_selection', $displayName, $displayQuantity, $unit, $normalizedConfidence);
		}

		$statement = $database->prepare(
			'SELECT id, name, serving_size, serving_unit, calories, protein_g, carbohydrates_g, fat_g, fiber_g, sugar_g, sodium_mg
			 FROM foods
			 WHERE id = :id AND is_active = 1
			 LIMIT 1'
		);
		$statement->execute(['id' => $selectedFoodId]);
		$food = $statement->fetch(PDO::FETCH_ASSOC);
		if (!$food) {
			return self::unresolved('inactive_catalog_food', $displayName, $displayQuantity, $unit, $normalizedConfidence);
		}

		try {
			$nutrition = self::scaleCatalogNutrition($food, $quantity, $unit);
		} catch (DomainException $exception) {
			$reason = $exception->getMessage() === 'incomplete_catalog_nutrition'
				? 'incomplete_catalog_nutrition'
				: 'unsupported_catalog_basis';

			return self::unresolved($reason, $displayName, $displayQuantity, $unit, $normalizedConfidence);
		}

		return [
			'status' => 'matched',
			'food' => ['id' => (int) $food['id'], 'name' => (string) $food['name']],
			'quantity' => ['value' => $displayQuantity, 'unit' => $unit],
			'nutrition' => $nutrition,
		] + ($normalizedConfidence === null ? [] : ['confidence' => round($normalizedConfidence, 4)]);
	}

	public static function unresolvedDetection(
		string $reason,
		string $detectedFood,
		mixed $quantity,
		mixed $quantityUnit,
		mixed $confidence = null
	): array {
		$displayQuantity = is_numeric($quantity) && is_finite((float) $quantity) ? round((float) $quantity, 2) : null;
		$unit = is_string($quantityUnit) ? strtolower(trim($quantityUnit)) : 'unknown';
		if ($unit === '') {
			$unit = 'unknown';
		}
		$normalizedConfidence = is_numeric($confidence)
			&& is_finite((float) $confidence)
			&& (float) $confidence >= 0
			&& (float) $confidence <= 1
			? (float) $confidence
			: null;

		return self::unresolved($reason, trim($detectedFood), $displayQuantity, $unit, $normalizedConfidence);
	}

	public static function resolve(
		PDO $database,
		mixed $detectedFoodName,
		mixed $quantity,
		mixed $quantityUnit,
		mixed $confidence = null
	): array {
		$displayName = is_string($detectedFoodName) ? trim($detectedFoodName) : '';
		$displayQuantity = is_numeric($quantity) && is_finite((float) $quantity) ? round((float) $quantity, 2) : null;
		$unit = is_string($quantityUnit) ? strtolower(trim($quantityUnit)) : 'unknown';
		if ($unit === '') {
			$unit = 'unknown';
		}

		$normalizedConfidence = null;
		if ($confidence !== null) {
			if (!is_numeric($confidence) || !is_finite((float) $confidence) || (float) $confidence < 0 || (float) $confidence > 1) {
				return self::unresolved('invalid_confidence', $displayName, $displayQuantity, $unit);
			}
			$normalizedConfidence = (float) $confidence;
		}

		if ($displayName === '') {
			return self::unresolved('missing_food_name', $displayName, $displayQuantity, $unit, $normalizedConfidence);
		}
		if (!is_numeric($quantity) || !is_finite((float) $quantity) || (float) $quantity <= 0) {
			return self::unresolved('invalid_quantity', $displayName, $displayQuantity, $unit, $normalizedConfidence);
		}
		if ($unit === 'ml') {
			return self::unresolved('volume_unit_not_supported', $displayName, $displayQuantity, $unit, $normalizedConfidence);
		}
		if ($unit === 'unknown') {
			return self::unresolved('unknown_quantity_unit', $displayName, $displayQuantity, $unit, $normalizedConfidence);
		}
		if ($unit !== 'g') {
			return self::unresolved('unsupported_quantity_unit', $displayName, $displayQuantity, $unit, $normalizedConfidence);
		}

		if (self::normalizeName($displayName) === '') {
			return self::unresolved('invalid_food_name', $displayName, $displayQuantity, $unit, $normalizedConfidence);
		}

		$candidates = self::findCandidates($database, $displayName);
		if ($candidates === []) {
			return self::unresolved('food_not_found', $displayName, $displayQuantity, $unit, $normalizedConfidence);
		}
		if (count($candidates) !== 1) {
			return self::unresolved('ambiguous_catalog_name', $displayName, $displayQuantity, $unit, $normalizedConfidence);
		}

		return self::resolveCatalogCandidate(
			$database,
			$displayName,
			$quantity,
			$unit,
			$normalizedConfidence,
			$candidates[0]['food_id'],
			$candidates
		);
	}

	public static function scaleCatalogNutrition(array $food, mixed $quantity, mixed $quantityUnit): array
	{
		if (!is_numeric($quantity) || !is_finite((float) $quantity) || (float) $quantity <= 0) {
			throw new DomainException('invalid_quantity');
		}

		$quantityUnit = is_string($quantityUnit) ? strtolower(trim($quantityUnit)) : '';
		$servingSize = $food['serving_size'] ?? null;
		$servingUnit = is_string($food['serving_unit'] ?? null) ? strtolower(trim($food['serving_unit'])) : '';
		if (!is_numeric($servingSize) || (float) $servingSize !== 100.0 || !in_array($servingUnit, ['g', 'ml'], true) || $quantityUnit !== $servingUnit) {
			throw new DomainException('unsupported_catalog_basis');
		}

		$catalogNutrition = [];
		foreach (self::NUTRIENTS as $nutrient) {
			$value = $food[$nutrient] ?? null;
			if (!is_numeric($value) || !is_finite((float) $value) || (float) $value < 0) {
				throw new DomainException('incomplete_catalog_nutrition');
			}
			$catalogNutrition[$nutrient] = (float) $value;
		}

		$scale = (float) $quantity / (float) $servingSize;
		$nutrition = [];
		foreach (self::NUTRIENTS as $nutrient) {
			$nutrition[$nutrient] = round($catalogNutrition[$nutrient] * $scale, 2);
		}

		return $nutrition;
	}

	private static function unresolved(
		string $reason,
		string $detectedFood,
		?float $quantity,
		string $unit,
		?float $confidence = null
	): array {
		$result = [
			'status' => 'unresolved',
			'reason' => $reason,
			'detected_food' => $detectedFood,
			'quantity' => ['value' => $quantity, 'unit' => $unit],
		];
		if ($confidence !== null) {
			$result['confidence'] = round($confidence, 4);
		}

		return $result;
	}
}