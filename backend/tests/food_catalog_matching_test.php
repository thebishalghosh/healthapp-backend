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
	if (!in_array($table, ['users', 'foods', 'meal_logs', 'food_scans'], true)) {
		throw new InvalidArgumentException('Unexpected test table.');
	}

	return (int) $database->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
};
$beforeCounts = [
	'users' => $countRows($database, 'users'),
	'foods' => $countRows($database, 'foods'),
	'meal_logs' => $countRows($database, 'meal_logs'),
	'food_scans' => $countRows($database, 'food_scans'),
];
$foodByName = static function (PDO $database, string $name): array {
	$statement = $database->prepare('SELECT id, name, calories FROM foods WHERE name = ? AND is_active = 1 LIMIT 1');
	$statement->execute([$name]);
	$food = $statement->fetch(PDO::FETCH_ASSOC);
	if (!$food) {
		throw new RuntimeException('Required active catalog row is missing: ' . $name);
	}

	return $food;
};
$aliasCases = [
	'roti' => 'Roti/Chapati (Plain, Commercially Prepared)',
	'chapati' => 'Roti/Chapati (Plain, Commercially Prepared)',
	'steamed white rice' => 'White Rice, Cooked (Salted, No Added Fat)',
	'cooked white rice' => 'White Rice, Cooked (Salted, No Added Fat)',
	'cucumber slices' => 'Cucumber with Peel (Raw)',
	'sliced cucumber' => 'Cucumber with Peel (Raw)',
	'tomato slices' => 'Tomato (Raw)',
	'sliced tomato' => 'Tomato (Raw)',
	'carrot' => 'Carrot (Raw)',
];

try {
	foreach ($aliasCases as $detectedName => $canonicalName) {
		$candidates = FoodNutritionLookupService::findCandidates($database, $detectedName);
		$assert(count($candidates) === 1 && $candidates[0]['name'] === $canonicalName, $detectedName . ' should produce one safe canonical catalog candidate.');
		$match = FoodNutritionLookupService::resolve($database, $detectedName, 100, 'g', 0.9);
		$assert($match['status'] === 'matched' && $match['food']['name'] === $canonicalName, $detectedName . ' should resolve deterministically.');
	}
	$deterministicScan = FoodScanService::enrichDetections(
		$database,
		[
			'foods' => array_map(
				static fn (string $name): array => ['name' => $name, 'quantity_g' => 100, 'quantity_unit' => 'g', 'confidence' => 0.9],
				array_keys($aliasCases)
			),
			'overall_confidence' => 0.9,
			'notes' => [],
		],
		null,
		null,
		static function (): never {
			throw new RuntimeException('Single deterministic candidates must not invoke Gemini selection.');
		}
	);
	$assert($deterministicScan['summary'] === ['matched_count' => count($aliasCases), 'unresolved_count' => 0], 'Single curated candidates should resolve without a Gemini selection request.');

	$rice = $foodByName($database, 'White Rice, Cooked (Salted, No Added Fat)');
	$cucumber = $foodByName($database, 'Cucumber with Peel (Raw)');
	$riceMatch = FoodNutritionLookupService::resolve($database, 'steamed white rice', 90, 'g', 0.9);
	$assert($riceMatch['status'] === 'matched' && $riceMatch['food']['id'] === (int) $rice['id'], '90 g steamed white rice should resolve to the catalog white rice record.');
	$assert($riceMatch['nutrition']['calories'] === round((float) $rice['calories'] * 0.9, 2), 'Rice nutrition must be calculated from the catalog.');
	$cucumberMatch = FoodNutritionLookupService::resolve($database, 'sliced cucumber', 20, 'g', 0.9);
	$assert($cucumberMatch['status'] === 'matched' && $cucumberMatch['food']['id'] === (int) $cucumber['id'], '20 g sliced cucumber should resolve to the catalog cucumber record.');
	$assert($cucumberMatch['nutrition']['calories'] === round((float) $cucumber['calories'] * 0.2, 2), 'Cucumber nutrition must be calculated from the catalog.');

	foreach (['yellow dal', 'chicken curry', 'pickle', 'carrot salad'] as $ambiguousName) {
		$assert(FoodNutritionLookupService::findCandidates($database, $ambiguousName) === [], $ambiguousName . ' must not be assigned an unsafe candidate.');
		$match = FoodNutritionLookupService::resolve($database, $ambiguousName, 100, 'g', 0.95);
		$assert($match['status'] === 'unresolved' && !array_key_exists('nutrition', $match), $ambiguousName . ' must remain unresolved without nutrition.');
	}

	$riceCandidates = FoodNutritionLookupService::findCandidates($database, 'rice');
	$assert(count($riceCandidates) === 2, 'Generic rice should expose only the two explicit rice candidates and not select one.');
	$genericRice = FoodNutritionLookupService::resolve($database, 'rice', 100, 'g', 0.95);
	$assert($genericRice['status'] === 'unresolved' && $genericRice['reason'] === 'ambiguous_catalog_name', 'Generic rice must remain ambiguous without catalog selection.');

	$dalMillilitres = FoodNutritionLookupService::resolve($database, 'dal', 150, 'ml', 0.9);
	$assert($dalMillilitres['status'] === 'unresolved' && $dalMillilitres['reason'] === 'volume_unit_not_supported', '150 ml dal must not be converted to grams.');

	$selectionCallCount = 0;
	$scan = FoodScanService::enrichDetections(
		$database,
		[
			'foods' => [['name' => 'rice', 'quantity_g' => 90, 'quantity_unit' => 'g', 'confidence' => 0.94]],
			'overall_confidence' => 0.9,
			'notes' => [],
		],
		'jpeg fixture bytes',
		'image/jpeg',
		static function (array $items, ?string $imageData, ?string $mimeType) use (&$selectionCallCount, $rice): array {
			$selectionCallCount++;
			if (count($items) !== 1 || $imageData !== 'jpeg fixture bytes' || $mimeType !== 'image/jpeg') {
				throw new RuntimeException('Catalog selection must be batched and retain the scan image.');
			}
			$candidateIds = array_column($items[0]['candidates'], 'food_id');
			if (!in_array((int) $rice['id'], $candidateIds, true)) {
				throw new RuntimeException('Gemini was not given the allowed white rice candidate.');
			}

			return [0 => ['catalog_food_id' => (int) $rice['id'], 'confidence' => 0.96]];
		}
	);
	$assert($selectionCallCount === 1, 'Ambiguous catalog candidates should be selected in one batch.');
	$assert($scan['detections'][0]['status'] === 'matched' && $scan['detections'][0]['food']['id'] === (int) $rice['id'], 'A valid supplied candidate may be selected.');
	$assert($scan['detections'][0]['nutrition']['calories'] === round((float) $rice['calories'] * 0.9, 2), 'Selected food nutrition must still come from the database.');

	$unselected = FoodScanService::enrichDetections(
		$database,
		['foods' => [['name' => 'rice', 'quantity_g' => 90, 'quantity_unit' => 'g', 'confidence' => 0.94]], 'overall_confidence' => 0.9, 'notes' => []],
		null,
		null,
		static fn (): array => [0 => ['catalog_food_id' => null, 'confidence' => 0.9]]
	);
	$assert($unselected['detections'][0]['status'] === 'unresolved' && $unselected['detections'][0]['reason'] === 'Catalog candidates could not be distinguished safely', 'A null selection must remain unresolved.');

	$lowConfidence = FoodScanService::enrichDetections(
		$database,
		['foods' => [['name' => 'rice', 'quantity_g' => 90, 'quantity_unit' => 'g', 'confidence' => 0.94]], 'overall_confidence' => 0.9, 'notes' => []],
		null,
		null,
		static fn (): array => [0 => ['catalog_food_id' => (int) $rice['id'], 'confidence' => 0.79]]
	);
	$assert($lowConfidence['detections'][0]['status'] === 'unresolved' && $lowConfidence['detections'][0]['reason'] === 'Catalog selection confidence is below the required threshold', 'Selections below 0.80 must remain unresolved.');

	foreach ([999999999, (int) $foodByName($database, 'Dal')['id']] as $invalidId) {
		$invalidSelection = FoodScanService::enrichDetections(
			$database,
			['foods' => [['name' => 'rice', 'quantity_g' => 90, 'quantity_unit' => 'g', 'confidence' => 0.94]], 'overall_confidence' => 0.9, 'notes' => []],
			null,
			null,
			static fn () => [0 => ['catalog_food_id' => $invalidId, 'confidence' => 0.96]]
		);
		$assert($invalidSelection['detections'][0]['status'] === 'unresolved' && $invalidSelection['detections'][0]['reason'] === 'Catalog selection was not in the supplied candidate list', 'Unknown and out-of-list IDs must be rejected.');
	}
	$invalidLowConfidence = FoodScanService::enrichDetections(
		$database,
		['foods' => [['name' => 'rice', 'quantity_g' => 90, 'quantity_unit' => 'g', 'confidence' => 0.94]], 'overall_confidence' => 0.9, 'notes' => []],
		null,
		null,
		static fn (): array => [0 => ['catalog_food_id' => 999999999, 'confidence' => 0.79]]
	);
	$assert($invalidLowConfidence['detections'][0]['reason'] === 'Catalog selection was not in the supplied candidate list', 'Invalid IDs must still be rejected when the selection confidence is low.');

	$selectionRequest = [[
		'detection_index' => 0,
		'candidates' => $riceCandidates,
	]];
	$parser = new ReflectionMethod(GeminiService::class, 'parseCatalogSelectionResponse');
	$validSelectionText = json_encode([
		'selections' => [[
			'detection_index' => 0,
			'catalog_food_id' => (int) $rice['id'],
			'confidence' => 0.95,
		]],
	], JSON_THROW_ON_ERROR);
	$validEnvelope = json_encode([
		'candidates' => [[
			'content' => ['parts' => [['text' => $validSelectionText]]],
			'finishReason' => 'STOP',
		]],
	], JSON_THROW_ON_ERROR);
	$parsedSelections = $parser->invoke(null, $validEnvelope, $selectionRequest);
	$assert($parsedSelections[0]['catalog_food_id'] === (int) $rice['id'], 'Gemini selection schema should retain a supplied ID.');
	$nutritionSelectionText = json_encode([
		'selections' => [[
			'detection_index' => 0,
			'catalog_food_id' => (int) $rice['id'],
			'confidence' => 0.95,
			'calories' => 99999,
		]],
	], JSON_THROW_ON_ERROR);
	$nutritionEnvelope = json_encode([
		'candidates' => [[
			'content' => ['parts' => [['text' => $nutritionSelectionText]]],
			'finishReason' => 'STOP',
		]],
	], JSON_THROW_ON_ERROR);
	try {
		$parser->invoke(null, $nutritionEnvelope, $selectionRequest);
		throw new RuntimeException('Gemini nutrition fields were accepted by the selection schema.');
	} catch (GeminiResponseException) {
		$assert(true, 'Gemini nutrition fields must be rejected by catalog selection parsing.');
	}

	$database->beginTransaction();
	$insertInactive = $database->prepare(
		'INSERT INTO foods
			(name, serving_size, serving_unit, calories, protein_g, carbohydrates_g, fat_g, fiber_g, sugar_g, sodium_mg, source, is_active)
		 VALUES (?, 100, ?, 1, 1, 1, 1, 1, 1, 1, ?, 0)'
	);
	$inactiveName = 'Temporary Catalog Candidate ' . bin2hex(random_bytes(8));
	$insertInactive->execute([$inactiveName, 'g', 'temporary catalog matching test']);
	$inactiveId = (int) $database->lastInsertId();
	$inactiveCandidate = [['food_id' => $inactiveId, 'name' => $inactiveName, 'serving_unit' => 'g']];
	$inactiveMatch = FoodNutritionLookupService::resolveCatalogCandidate(
		$database,
		$inactiveName,
		100,
		'g',
		0.95,
		$inactiveId,
		$inactiveCandidate
	);
	$assert($inactiveMatch['status'] === 'unresolved' && $inactiveMatch['reason'] === 'inactive_catalog_food', 'Inactive catalog rows must not be selected.');
	$database->rollBack();

	$afterCounts = [
		'users' => $countRows($database, 'users'),
		'foods' => $countRows($database, 'foods'),
		'meal_logs' => $countRows($database, 'meal_logs'),
		'food_scans' => $countRows($database, 'food_scans'),
	];
	$assert($afterCounts === $beforeCounts, 'Catalog matching tests must not persist catalog, user, meal, or scan rows.');

	fprintf(STDOUT, "All %d catalog-aware matching assertions passed; no persistent rows were written.\n", $assertions);
} catch (Throwable $exception) {
	if ($database->inTransaction()) {
		$database->rollBack();
	}
	fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . "\n");
	exit(1);
}
