<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
	fwrite(STDERR, "Run this test from the command line.\n");
	exit(1);
}

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/services/FoodScanService.php';

$database = database_connection();
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
	$assertions++;
};
$countRows = static function (PDO $database, string $table): int {
	if (!in_array($table, ['meal_logs', 'food_scans', 'foods'], true)) {
		throw new InvalidArgumentException('Unexpected test table.');
	}

	return (int) $database->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
};
$beforeCounts = [
	'meal_logs' => $countRows($database, 'meal_logs'),
	'food_scans' => $countRows($database, 'food_scans'),
	'foods' => $countRows($database, 'foods'),
];
$bananaStatement = $database->prepare(
	'SELECT id, calories, protein_g, carbohydrates_g, fat_g, fiber_g, sugar_g, sodium_mg
	 FROM foods WHERE name = :name AND is_active = 1 LIMIT 1'
);
$bananaStatement->execute(['name' => 'Banana (Raw)']);
$banana = $bananaStatement->fetch(PDO::FETCH_ASSOC);
if (!$banana) {
	fwrite(STDERR, "Required live catalog row is missing: Banana (Raw).\n");
	exit(1);
}

$fixture = [
	'foods' => [
		['name' => 'Banana (Raw)', 'quantity_g' => 120, 'quantity_unit' => 'g', 'confidence' => 0.94],
		['name' => 'Chicken Curry', 'quantity_g' => 180, 'quantity_unit' => 'g', 'confidence' => 0.81],
		['name' => 'Banana (Raw)', 'quantity_g' => 200, 'quantity_unit' => 'g', 'confidence' => 0.9],
	],
	'overall_confidence' => 0.88,
	'notes' => ['Test fixture only.'],
];

try {
	$result = FoodScanService::enrichDetections($database, $fixture);
	$assert(count($result['detections']) === 3, 'Each Gemini food should produce one independent detection result.');
	$assert($result['summary'] === ['matched_count' => 2, 'unresolved_count' => 1], 'Matched and unresolved counts should be independent.');
	$first = $result['detections'][0];
	$assert($first['status'] === 'matched', 'Banana should match.');
	$assert($first['food']['id'] === (int) $banana['id'], 'Matched ID should come from the live catalog.');
	$assert($first['quantity'] === ['value' => 120.0, 'unit' => 'g'], 'Gemini quantity and unit should be preserved.');
	$assert($first['confidence'] === 0.94, 'Detection confidence should be preserved.');
	foreach (['calories', 'protein_g', 'carbohydrates_g', 'fat_g', 'fiber_g', 'sugar_g', 'sodium_mg'] as $nutrient) {
		$expected = round((float) $banana[$nutrient] * 1.2, 2);
		$assert(abs($first['nutrition'][$nutrient] - $expected) < 0.0001, '120 g scaling should match the live catalog for ' . $nutrient . '.');
	}

	$unresolved = $result['detections'][1];
	$assert($unresolved['status'] === 'unresolved', 'Chicken Curry should remain unresolved.');
	$assert($unresolved['reason'] === 'No safe catalog match for detected food', 'Unknown foods should have a clear unresolved reason.');
	$assert(!array_key_exists('nutrition', $unresolved), 'Unresolved foods must not include nutrition.');
	$assert($result['detections'][2]['status'] === 'matched', 'A later valid detection should still match after an unresolved item.');

	$empty = FoodScanService::enrichDetections($database, ['foods' => [], 'overall_confidence' => 0.2, 'notes' => []]);
	$assert($empty['detections'] === [] && $empty['summary'] === ['matched_count' => 0, 'unresolved_count' => 0], 'No detections should return a successful empty result.');

	$afterCounts = [
		'meal_logs' => $countRows($database, 'meal_logs'),
		'food_scans' => $countRows($database, 'food_scans'),
		'foods' => $countRows($database, 'foods'),
	];
	$assert($afterCounts === $beforeCounts, 'Pipeline test must not write meal logs, food scans, or catalog rows.');

	fprintf(STDOUT, "All %d pipeline assertions passed; no persistent rows were written.\n", $assertions);
} catch (Throwable $exception) {
	fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . "\n");
	exit(1);
}