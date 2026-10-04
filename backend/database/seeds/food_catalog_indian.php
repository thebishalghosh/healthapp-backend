<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

$foods = [
	['name' => 'White Rice, Cooked (Salted, No Added Fat)', 'category' => 'Cereal Grains and Pasta', 'cuisine' => 'General', 'calories' => 129, 'protein_g' => 2.67, 'carbohydrates_g' => 28.0, 'fat_g' => 0.280, 'fiber_g' => 0.400, 'sugar_g' => 0.050, 'sodium_mg' => 245, 'source' => 'USDA FoodData Central FNDDS 2024-10-31, FDC ID 2708408'],
	['name' => 'Brown Rice, Cooked (Salted, No Added Fat)', 'category' => 'Cereal Grains and Pasta', 'cuisine' => 'General', 'calories' => 123, 'protein_g' => 2.43, 'carbohydrates_g' => 25.8, 'fat_g' => 1.11, 'fiber_g' => 1.00, 'sugar_g' => 0.220, 'sodium_mg' => 201, 'source' => 'USDA FoodData Central FNDDS 2024-10-31, FDC ID 2708414'],
	['name' => 'Upma', 'category' => 'Prepared Foods', 'cuisine' => 'Indian', 'calories' => 87, 'protein_g' => 1.98, 'carbohydrates_g' => 13.8, 'fat_g' => 2.64, 'fiber_g' => 1.30, 'sugar_g' => 1.61, 'sodium_mg' => 98, 'source' => 'USDA FoodData Central FNDDS 2024-10-31, FDC ID 2709128'],
	['name' => 'Dal', 'category' => 'Prepared Foods', 'cuisine' => 'Indian', 'calories' => 145, 'protein_g' => 8.60, 'carbohydrates_g' => 19.2, 'fat_g' => 4.31, 'fiber_g' => 7.50, 'sugar_g' => 1.71, 'sodium_mg' => 309, 'source' => 'USDA FoodData Central FNDDS 2024-10-31, FDC ID 2707427'],
	['name' => 'Paneer', 'category' => 'Dairy and Egg Products', 'cuisine' => 'Indian', 'calories' => 299, 'protein_g' => 15.9, 'carbohydrates_g' => 22.5, 'fat_g' => 15.5, 'fiber_g' => 0, 'sugar_g' => 23.3, 'sodium_mg' => 185, 'source' => 'USDA FoodData Central FNDDS 2024-10-31, FDC ID 2705740'],
	['name' => 'Whole-Wheat Paratha (Commercially Prepared, Frozen)', 'category' => 'Baked Products', 'cuisine' => 'Indian', 'calories' => 326, 'protein_g' => 6.36, 'carbohydrates_g' => 45.35, 'fat_g' => 13.2, 'fiber_g' => 9.6, 'sugar_g' => 4.15, 'sodium_mg' => 452, 'source' => 'USDA FoodData Central SR Legacy 2018, FDC ID 174076'],
	['name' => 'Mung Beans (Cooked, No Salt)', 'category' => 'Legumes and Legume Products', 'cuisine' => 'General', 'calories' => 105, 'protein_g' => 7.02, 'carbohydrates_g' => 19.15, 'fat_g' => 0.38, 'fiber_g' => 7.6, 'sugar_g' => 2, 'sodium_mg' => 2, 'source' => 'USDA FoodData Central SR Legacy 2018, FDC ID 174257'],
	['name' => 'Kidney Beans (Cooked, No Salt)', 'category' => 'Legumes and Legume Products', 'cuisine' => 'General', 'calories' => 127, 'protein_g' => 8.67, 'carbohydrates_g' => 22.8, 'fat_g' => 0.5, 'fiber_g' => 6.4, 'sugar_g' => 0.32, 'sodium_mg' => 1, 'source' => 'USDA FoodData Central SR Legacy 2018, FDC ID 173740'],
	['name' => 'Cauliflower (Boiled, No Salt)', 'category' => 'Vegetables and Vegetable Products', 'cuisine' => 'General', 'calories' => 23, 'protein_g' => 1.84, 'carbohydrates_g' => 4.11, 'fat_g' => 0.45, 'fiber_g' => 2.3, 'sugar_g' => 2.08, 'sodium_mg' => 15, 'source' => 'USDA FoodData Central SR Legacy 2018, FDC ID 170397'],
	['name' => 'Cabbage (Boiled, No Salt)', 'category' => 'Vegetables and Vegetable Products', 'cuisine' => 'General', 'calories' => 23, 'protein_g' => 1.27, 'carbohydrates_g' => 5.51, 'fat_g' => 0.06, 'fiber_g' => 1.9, 'sugar_g' => 2.79, 'sodium_mg' => 8, 'source' => 'USDA FoodData Central SR Legacy 2018, FDC ID 169976'],
	['name' => 'Green Beans (Boiled, No Salt)', 'category' => 'Vegetables and Vegetable Products', 'cuisine' => 'General', 'calories' => 35, 'protein_g' => 1.89, 'carbohydrates_g' => 7.88, 'fat_g' => 0.28, 'fiber_g' => 3.2, 'sugar_g' => 3.63, 'sodium_mg' => 1, 'source' => 'USDA FoodData Central SR Legacy 2018, FDC ID 169141'],
	['name' => 'Green Peas (Boiled, No Salt)', 'category' => 'Vegetables and Vegetable Products', 'cuisine' => 'General', 'calories' => 84, 'protein_g' => 5.36, 'carbohydrates_g' => 15.63, 'fat_g' => 0.22, 'fiber_g' => 5.5, 'sugar_g' => 5.93, 'sodium_mg' => 3, 'source' => 'USDA FoodData Central SR Legacy 2018, FDC ID 170420'],
	['name' => 'Onion (Raw)', 'category' => 'Vegetables and Vegetable Products', 'cuisine' => 'General', 'calories' => 40, 'protein_g' => 1.1, 'carbohydrates_g' => 9.34, 'fat_g' => 0.1, 'fiber_g' => 1.7, 'sugar_g' => 4.24, 'sodium_mg' => 4, 'source' => 'USDA FoodData Central SR Legacy 2018, FDC ID 170000'],
	['name' => 'Green Bell Pepper (Raw)', 'category' => 'Vegetables and Vegetable Products', 'cuisine' => 'General', 'calories' => 20, 'protein_g' => 0.86, 'carbohydrates_g' => 4.64, 'fat_g' => 0.17, 'fiber_g' => 1.7, 'sugar_g' => 2.4, 'sodium_mg' => 3, 'source' => 'USDA FoodData Central SR Legacy 2018, FDC ID 170427'],
	['name' => 'Eggplant (Boiled, No Salt)', 'category' => 'Vegetables and Vegetable Products', 'cuisine' => 'General', 'calories' => 35, 'protein_g' => 0.83, 'carbohydrates_g' => 8.73, 'fat_g' => 0.23, 'fiber_g' => 2.5, 'sugar_g' => 3.2, 'sodium_mg' => 1, 'source' => 'USDA FoodData Central SR Legacy 2018, FDC ID 169229'],
	['name' => 'Sweet Potato (Boiled, No Skin)', 'category' => 'Vegetables and Vegetable Products', 'cuisine' => 'General', 'calories' => 76, 'protein_g' => 1.37, 'carbohydrates_g' => 17.72, 'fat_g' => 0.14, 'fiber_g' => 2.5, 'sugar_g' => 5.74, 'sodium_mg' => 27, 'source' => 'USDA FoodData Central SR Legacy 2018, FDC ID 168484'],
	['name' => 'Mango (Raw)', 'category' => 'Fruits and Fruit Juices', 'cuisine' => 'General', 'calories' => 60, 'protein_g' => 0.82, 'carbohydrates_g' => 14.98, 'fat_g' => 0.38, 'fiber_g' => 1.6, 'sugar_g' => 13.66, 'sodium_mg' => 1, 'source' => 'USDA FoodData Central SR Legacy 2018, FDC ID 169910'],
	['name' => 'Watermelon (Raw)', 'category' => 'Fruits and Fruit Juices', 'cuisine' => 'General', 'calories' => 30, 'protein_g' => 0.61, 'carbohydrates_g' => 7.55, 'fat_g' => 0.15, 'fiber_g' => 0.4, 'sugar_g' => 6.2, 'sodium_mg' => 1, 'source' => 'USDA FoodData Central SR Legacy 2018, FDC ID 167765'],
	['name' => 'Papaya (Raw)', 'category' => 'Fruits and Fruit Juices', 'cuisine' => 'General', 'calories' => 43, 'protein_g' => 0.47, 'carbohydrates_g' => 10.82, 'fat_g' => 0.26, 'fiber_g' => 1.7, 'sugar_g' => 7.82, 'sodium_mg' => 8, 'source' => 'USDA FoodData Central SR Legacy 2018, FDC ID 169926'],
	['name' => 'Guava (Raw)', 'category' => 'Fruits and Fruit Juices', 'cuisine' => 'General', 'calories' => 68, 'protein_g' => 2.55, 'carbohydrates_g' => 14.32, 'fat_g' => 0.95, 'fiber_g' => 5.4, 'sugar_g' => 8.92, 'sodium_mg' => 2, 'source' => 'USDA FoodData Central SR Legacy 2018, FDC ID 173044'],
];

$database = database_connection();
$lockName = 'health_backend_food_catalog_indian_seed';
$lockStatement = $database->prepare('SELECT GET_LOCK(:lock_name, 10)');
$lockStatement->execute(['lock_name' => $lockName]);
if ((int) $lockStatement->fetchColumn() !== 1) {
	fwrite(STDERR, "Could not acquire the Indian food catalog seed lock.\n");
	exit(1);
}

$inserted = 0;
$skipped = 0;
try {
	$seenNames = [];
	foreach ($foods as $food) {
		if (isset($seenNames[$food['name']])) {
			throw new RuntimeException('Duplicate canonical name in seed: ' . $food['name']);
		}
		$seenNames[$food['name']] = true;
		foreach (['calories', 'protein_g', 'carbohydrates_g', 'fat_g', 'fiber_g', 'sugar_g', 'sodium_mg'] as $nutrient) {
			if (!is_numeric($food[$nutrient]) || !is_finite((float) $food[$nutrient]) || (float) $food[$nutrient] < 0) {
				throw new RuntimeException('Invalid USDA value for ' . $food['name'] . ': ' . $nutrient);
			}
		}
		if ($food['source'] === '') {
			throw new RuntimeException('Missing USDA source for ' . $food['name']);
		}
	}

	$database->beginTransaction();
	$existingStatement = $database->prepare('SELECT id FROM foods WHERE name = :name LIMIT 1');
	$insertStatement = $database->prepare(
		'INSERT INTO foods
			(name, category, cuisine, serving_size, serving_unit, calories, protein_g, carbohydrates_g, fat_g, fiber_g, sugar_g, sodium_mg, source, is_active)
		 VALUES
			(:name, :category, :cuisine, 100, :serving_unit, :calories, :protein_g, :carbohydrates_g, :fat_g, :fiber_g, :sugar_g, :sodium_mg, :source, 1)'
	);

	foreach ($foods as $food) {
		$existingStatement->execute(['name' => $food['name']]);
		if ($existingStatement->fetchColumn() !== false) {
			$skipped++;
			continue;
		}

		$insertStatement->execute([
			'name' => $food['name'],
			'category' => $food['category'],
			'cuisine' => $food['cuisine'],
			'serving_unit' => 'g',
			'calories' => $food['calories'],
			'protein_g' => $food['protein_g'],
			'carbohydrates_g' => $food['carbohydrates_g'],
			'fat_g' => $food['fat_g'],
			'fiber_g' => $food['fiber_g'],
			'sugar_g' => $food['sugar_g'],
			'sodium_mg' => $food['sodium_mg'],
			'source' => $food['source'],
		]);
		$inserted++;
	}

	$database->commit();
	fprintf(STDOUT, "Inserted %d foods; skipped %d existing canonical names.\n", $inserted, $skipped);
} catch (Throwable $exception) {
	if ($database->inTransaction()) {
		$database->rollBack();
	}
	fwrite(STDERR, 'Indian food catalog seed failed: ' . $exception->getMessage() . "\n");
	exit(1);
} finally {
	$releaseStatement = $database->prepare('SELECT RELEASE_LOCK(:lock_name)');
	$releaseStatement->execute(['lock_name' => $lockName]);
}
