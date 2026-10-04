<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
	fwrite(STDERR, "Run this test from the command line.\n");
	exit(1);
}

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/services/FoodNutritionLookupService.php';

$database = database_connection();
$bananaStatement = $database->prepare(
	'SELECT id, calories, protein_g, carbohydrates_g, fat_g, fiber_g, sugar_g, sodium_mg
	 FROM foods
	 WHERE name = :name AND is_active = 1
	 LIMIT 1'
);
$bananaStatement->execute(['name' => 'Banana (Raw)']);
$banana = $bananaStatement->fetch(PDO::FETCH_ASSOC);
if (!$banana) {
	fwrite(STDERR, "Required live catalog row is missing: Banana (Raw).\n");
	exit(1);
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
	$assertions++;
};
$nutrients = ['calories', 'protein_g', 'carbohydrates_g', 'fat_g', 'fiber_g', 'sugar_g', 'sodium_mg'];
$run = static fn (mixed $name, mixed $quantity, mixed $unit, mixed $confidence = null): array => FoodNutritionLookupService::resolve(
	$database,
	$name,
	$quantity,
	$unit,
	$confidence
);

try {
	$exact = $run('Banana (Raw)', 100, 'g');
	$assert($exact['status'] === 'matched', 'Exact 100 g banana should match.');
	$assert($exact['food']['id'] === (int) $banana['id'], 'Matched food ID should come from the database.');
	foreach ($nutrients as $nutrient) {
		$assert(
			abs($exact['nutrition'][$nutrient] - (float) $banana[$nutrient]) < 0.0001,
			'100 g nutrition should equal the live catalog value for ' . $nutrient . '.'
		);
	}
	fwrite(STDOUT, "PASS exact catalog match and 100 g nutrition\n");

	$double = $run('Banana (Raw)', 200, 'g');
	$assert($double['status'] === 'matched', '200 g banana should match.');
	foreach ($nutrients as $nutrient) {
		$expected = round((float) $banana[$nutrient] * 2, 2);
		$assert(abs($double['nutrition'][$nutrient] - $expected) < 0.0001, '200 g scaling should be correct for ' . $nutrient . '.');
	}
	fwrite(STDOUT, "PASS 200 g scaling for all nutrients\n");

	$alias = $run(' BANANA ', 100, 'g', 0.87);
	$assert($alias['status'] === 'matched' && $alias['food']['name'] === 'Banana (Raw)', 'Curated banana alias should resolve to Banana (Raw).');
	$assert($alias['confidence'] === 0.87, 'A valid confidence value should be preserved.');
	fwrite(STDOUT, "PASS curated alias and confidence passthrough\n");

	$assert(FoodNutritionLookupService::normalizeName("  BANANA—(Raw)  ") === 'banana raw', 'Normalization should fold case, punctuation, and whitespace.');
	fwrite(STDOUT, "PASS normalization preserves food words\n");

	$unknownFood = $run('Chicken Curry', 180, 'g');
	$assert($unknownFood['status'] === 'unresolved' && $unknownFood['reason'] === 'food_not_found', 'Chicken Curry must not map to roasted chicken breast.');
	$assert(!array_key_exists('nutrition', $unknownFood), 'Unresolved food must not contain fabricated nutrition.');
	fwrite(STDOUT, "PASS unknown food remains unresolved\n");

	$millilitres = $run('Banana (Raw)', 100, 'ml');
	$assert($millilitres['status'] === 'unresolved' && $millilitres['reason'] === 'volume_unit_not_supported', 'Millilitres must remain unresolved.');
	$unknownUnit = $run('Banana (Raw)', 100, 'unknown');
	$assert($unknownUnit['status'] === 'unresolved' && $unknownUnit['reason'] === 'unknown_quantity_unit', 'Unknown unit must remain unresolved.');
	fwrite(STDOUT, "PASS ml and unknown units remain unresolved\n");

	foreach ([0, -1] as $invalidQuantity) {
		$invalid = $run('Banana (Raw)', $invalidQuantity, 'g');
		$assert($invalid['status'] === 'unresolved' && $invalid['reason'] === 'invalid_quantity', 'Zero/negative quantity must be rejected.');
	}
	fwrite(STDOUT, "PASS zero and negative quantities rejected\n");

	foreach ([null, '   '] as $missingName) {
		$missing = $run($missingName, 100, 'g');
		$assert($missing['status'] === 'unresolved' && $missing['reason'] === 'missing_food_name', 'Missing food name must be rejected.');
	}
	fwrite(STDOUT, "PASS missing food name rejected\n");

	$invalidConfidence = $run('Banana (Raw)', 100, 'g', 1.01);
	$assert($invalidConfidence['status'] === 'unresolved' && $invalidConfidence['reason'] === 'invalid_confidence', 'Out-of-range confidence must be rejected.');
	fwrite(STDOUT, "PASS invalid confidence rejected\n");

	$newCatalogFoods = [
		'White Rice, Cooked (Salted, No Added Fat)',
		'Brown Rice, Cooked (Salted, No Added Fat)',
		'Upma',
		'Dal',
		'Paneer',
		'Whole-Wheat Paratha (Commercially Prepared, Frozen)',
		'Mung Beans (Cooked, No Salt)',
		'Kidney Beans (Cooked, No Salt)',
		'Cauliflower (Boiled, No Salt)',
		'Cabbage (Boiled, No Salt)',
		'Green Beans (Boiled, No Salt)',
		'Green Peas (Boiled, No Salt)',
		'Onion (Raw)',
		'Green Bell Pepper (Raw)',
		'Eggplant (Boiled, No Salt)',
		'Sweet Potato (Boiled, No Skin)',
		'Mango (Raw)',
		'Watermelon (Raw)',
		'Papaya (Raw)',
		'Guava (Raw)',
	];
	foreach ($newCatalogFoods as $foodName) {
		$catalogMatch = $run($foodName, 100, 'g');
		$assert($catalogMatch['status'] === 'matched' && $catalogMatch['food']['name'] === $foodName, 'New canonical catalog food should match exactly: ' . $foodName . '.');
		foreach ($nutrients as $nutrient) {
			$assert(is_numeric($catalogMatch['nutrition'][$nutrient]) && is_finite((float) $catalogMatch['nutrition'][$nutrient]), 'Matched catalog nutrition should be present for ' . $foodName . ': ' . $nutrient . '.');
		}
	}
	fwrite(STDOUT, "PASS new Indian catalog canonical foods\n");

	foreach ([
		'white rice cooked with salt' => 'White Rice, Cooked (Salted, No Added Fat)',
		'brown rice cooked with salt' => 'Brown Rice, Cooked (Salted, No Added Fat)',
		'mango' => 'Mango (Raw)',
		'watermelon' => 'Watermelon (Raw)',
		'water melon' => 'Watermelon (Raw)',
		'papaya' => 'Papaya (Raw)',
		'guava' => 'Guava (Raw)',
	] as $alias => $canonicalName) {
		$aliasMatch = $run($alias, 100, 'g');
		$assert($aliasMatch['status'] === 'matched' && $aliasMatch['food']['name'] === $canonicalName, 'Curated safe alias should resolve: ' . $alias . '.');
	}
	fwrite(STDOUT, "PASS explicit preparation-specific rice and raw-fruit aliases\n");

	$standardDal = $run('dal', 100, 'g');
	$assert($standardDal['status'] === 'matched' && $standardDal['food']['name'] === 'Dal', 'Generic Dal may only match its explicit standardized USDA FNDDS catalog entry.');
	fwrite(STDOUT, "PASS generic Dal matches only its explicit standardized catalog record\n");

	foreach (['Moong Dal, Cooked', 'Masoor Dal, Cooked', 'Chana Dal, Cooked', 'chicken', 'fish', 'curry'] as $ambiguousName) {
		$ambiguous = $run($ambiguousName, 100, 'g');
		$assert($ambiguous['status'] === 'unresolved' && !array_key_exists('nutrition', $ambiguous), 'Ambiguous name must remain unresolved: ' . $ambiguousName . '.');
	}
	fwrite(STDOUT, "PASS specific dal varieties and generic meat/curry terms remain unresolved\n");

	fprintf(STDOUT, "All %d assertions passed; no meal logs were written.\n", $assertions);
} catch (Throwable $exception) {
	fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . "\n");
	exit(1);
}