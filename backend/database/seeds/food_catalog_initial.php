<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

$foods = [
	['name' => 'Cucumber with Peel (Raw)', 'category' => 'Vegetables and Vegetable Products', 'cuisine' => 'General', 'calories' => 15, 'protein_g' => 0.65, 'carbohydrates_g' => 3.63, 'fat_g' => 0.11, 'fiber_g' => 0.5, 'sugar_g' => 1.67, 'sodium_mg' => 2, 'source' => 'USDA FoodData Central SR Legacy 2018, FDC ID 168409'],
	['name' => 'Spinach (Boiled, No Salt)', 'category' => 'Vegetables and Vegetable Products', 'cuisine' => 'General', 'calories' => 23, 'protein_g' => 2.97, 'carbohydrates_g' => 3.75, 'fat_g' => 0.26, 'fiber_g' => 2.4, 'sugar_g' => 0.43, 'sodium_mg' => 70, 'source' => 'USDA FoodData Central SR Legacy 2018, FDC ID 168463'],
	['name' => 'Orange (Raw)', 'category' => 'Fruits and Fruit Juices', 'cuisine' => 'General', 'calories' => 47, 'protein_g' => 0.94, 'carbohydrates_g' => 11.75, 'fat_g' => 0.12, 'fiber_g' => 2.4, 'sugar_g' => 9.35, 'sodium_mg' => 0, 'source' => 'USDA FoodData Central SR Legacy 2018, FDC ID 169097'],
	['name' => 'Broccoli (Boiled, No Salt)', 'category' => 'Vegetables and Vegetable Products', 'cuisine' => 'General', 'calories' => 35, 'protein_g' => 2.38, 'carbohydrates_g' => 7.18, 'fat_g' => 0.41, 'fiber_g' => 3.3, 'sugar_g' => 1.39, 'sodium_mg' => 41, 'source' => 'USDA FoodData Central SR Legacy 2018, FDC ID 169967'],
	['name' => 'Carrot (Raw)', 'category' => 'Vegetables and Vegetable Products', 'cuisine' => 'General', 'calories' => 41, 'protein_g' => 0.93, 'carbohydrates_g' => 9.58, 'fat_g' => 0.24, 'fiber_g' => 2.8, 'sugar_g' => 4.74, 'sodium_mg' => 69, 'source' => 'USDA FoodData Central SR Legacy 2018, FDC ID 170393'],
	['name' => 'Potato (Boiled, No Salt)', 'category' => 'Vegetables and Vegetable Products', 'cuisine' => 'General', 'calories' => 86, 'protein_g' => 1.71, 'carbohydrates_g' => 20.01, 'fat_g' => 0.1, 'fiber_g' => 1.8, 'sugar_g' => 0.89, 'sodium_mg' => 5, 'source' => 'USDA FoodData Central SR Legacy 2018, FDC ID 170440'],
	['name' => 'Tomato (Raw)', 'category' => 'Vegetables and Vegetable Products', 'cuisine' => 'General', 'calories' => 18, 'protein_g' => 0.88, 'carbohydrates_g' => 3.89, 'fat_g' => 0.2, 'fiber_g' => 1.2, 'sugar_g' => 2.63, 'sodium_mg' => 5, 'source' => 'USDA FoodData Central SR Legacy 2018, FDC ID 170457'],
	['name' => 'Mixed Vegetables (Boiled, No Salt)', 'category' => 'Vegetables and Vegetable Products', 'cuisine' => 'General', 'calories' => 65, 'protein_g' => 2.86, 'carbohydrates_g' => 13.09, 'fat_g' => 0.15, 'fiber_g' => 4.4, 'sugar_g' => 3.12, 'sodium_mg' => 35, 'source' => 'USDA FoodData Central SR Legacy 2018, FDC ID 170472'],
	['name' => 'Plain Whole-Milk Yogurt', 'category' => 'Dairy and Egg Products', 'cuisine' => 'General', 'calories' => 61, 'protein_g' => 3.47, 'carbohydrates_g' => 4.66, 'fat_g' => 3.25, 'fiber_g' => 0, 'sugar_g' => 4.66, 'sodium_mg' => 46, 'source' => 'USDA FoodData Central SR Legacy 2018, FDC ID 171284'],
	['name' => 'Cooked Chicken Breast (Roasted)', 'category' => 'Poultry Products', 'cuisine' => 'General', 'calories' => 165, 'protein_g' => 31.02, 'carbohydrates_g' => 0, 'fat_g' => 3.57, 'fiber_g' => 0, 'sugar_g' => 0, 'sodium_mg' => 74, 'source' => 'USDA FoodData Central SR Legacy 2018, FDC ID 171477'],
	['name' => 'Apple with Skin (Raw)', 'category' => 'Fruits and Fruit Juices', 'cuisine' => 'General', 'calories' => 52, 'protein_g' => 0.26, 'carbohydrates_g' => 13.81, 'fat_g' => 0.17, 'fiber_g' => 2.4, 'sugar_g' => 10.39, 'sodium_mg' => 1, 'source' => 'USDA FoodData Central SR Legacy 2018, FDC ID 171688'],
	['name' => 'Avocado (Raw)', 'category' => 'Fruits and Fruit Juices', 'cuisine' => 'General', 'calories' => 160, 'protein_g' => 2, 'carbohydrates_g' => 8.53, 'fat_g' => 14.66, 'fiber_g' => 6.7, 'sugar_g' => 0.66, 'sodium_mg' => 7, 'source' => 'USDA FoodData Central SR Legacy 2018, FDC ID 171705'],
	['name' => 'Roti/Chapati (Plain, Commercially Prepared)', 'category' => 'Baked Products', 'cuisine' => 'Indian', 'calories' => 297, 'protein_g' => 11.25, 'carbohydrates_g' => 46.36, 'fat_g' => 7.45, 'fiber_g' => 4.9, 'sugar_g' => 2.72, 'sodium_mg' => 409, 'source' => 'USDA FoodData Central SR Legacy 2018, FDC ID 171844'],
	['name' => 'Cooked Lentils (No Salt)', 'category' => 'Legumes and Legume Products', 'cuisine' => 'General', 'calories' => 116, 'protein_g' => 9.02, 'carbohydrates_g' => 20.13, 'fat_g' => 0.38, 'fiber_g' => 7.9, 'sugar_g' => 1.8, 'sodium_mg' => 2, 'source' => 'USDA FoodData Central SR Legacy 2018, FDC ID 172421'],
	['name' => 'Whole-Wheat Bread', 'category' => 'Baked Products', 'cuisine' => 'General', 'calories' => 252, 'protein_g' => 12.45, 'carbohydrates_g' => 42.71, 'fat_g' => 3.5, 'fiber_g' => 6, 'sugar_g' => 4.34, 'sodium_mg' => 455, 'source' => 'USDA FoodData Central SR Legacy 2018, FDC ID 172688'],
	['name' => 'Boiled Egg', 'category' => 'Dairy and Egg Products', 'cuisine' => 'General', 'calories' => 155, 'protein_g' => 12.58, 'carbohydrates_g' => 1.12, 'fat_g' => 10.61, 'fiber_g' => 0, 'sugar_g' => 1.12, 'sodium_mg' => 124, 'source' => 'USDA FoodData Central SR Legacy 2018, FDC ID 173424'],
	['name' => 'Cooked Chickpeas (No Salt)', 'category' => 'Legumes and Legume Products', 'cuisine' => 'General', 'calories' => 164, 'protein_g' => 8.86, 'carbohydrates_g' => 27.42, 'fat_g' => 2.59, 'fiber_g' => 7.6, 'sugar_g' => 4.8, 'sodium_mg' => 7, 'source' => 'USDA FoodData Central SR Legacy 2018, FDC ID 173757'],
	['name' => 'Oatmeal (Cooked with Water, No Salt)', 'category' => 'Breakfast Cereals', 'cuisine' => 'General', 'calories' => 71, 'protein_g' => 2.54, 'carbohydrates_g' => 12, 'fat_g' => 1.52, 'fiber_g' => 1.7, 'sugar_g' => 0.27, 'sodium_mg' => 4, 'source' => 'USDA FoodData Central SR Legacy 2018, FDC ID 173905'],
	['name' => 'Banana (Raw)', 'category' => 'Fruits and Fruit Juices', 'cuisine' => 'General', 'calories' => 89, 'protein_g' => 1.09, 'carbohydrates_g' => 22.84, 'fat_g' => 0.33, 'fiber_g' => 2.6, 'sugar_g' => 12.23, 'sodium_mg' => 1, 'source' => 'USDA FoodData Central SR Legacy 2018, FDC ID 173944'],
	['name' => 'Tilapia (Cooked, Dry Heat)', 'category' => 'Finfish and Shellfish Products', 'cuisine' => 'General', 'calories' => 128, 'protein_g' => 26.15, 'carbohydrates_g' => 0, 'fat_g' => 2.65, 'fiber_g' => 0, 'sugar_g' => 0, 'sodium_mg' => 56, 'source' => 'USDA FoodData Central SR Legacy 2018, FDC ID 175177'],
];

$database = database_connection();
$lockName = 'health_backend_food_catalog_initial_seed';
$lockStatement = $database->prepare('SELECT GET_LOCK(:lock_name, 10)');
$lockStatement->execute(['lock_name' => $lockName]);
if ((int) $lockStatement->fetchColumn() !== 1) {
	fwrite(STDERR, "Could not acquire the food catalog seed lock.\n");
	exit(1);
}

$inserted = 0;
$skipped = 0;
try {
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
	fwrite(STDERR, 'Food catalog seed failed: ' . $exception->getMessage() . "\n");
	exit(1);
} finally {
	$releaseStatement = $database->prepare('SELECT RELEASE_LOCK(:lock_name)');
	$releaseStatement->execute(['lock_name' => $lockName]);
}