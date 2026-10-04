<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
	fwrite(STDERR, "Run this test from the command line.\n");
	exit(1);
}

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/core/auth.php';
require_once dirname(__DIR__) . '/services/FoodScanConfirmationService.php';

$database = database_connection();
$assertions = 0;
$testUserId = null;
$inactiveFoodId = null;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
	$assertions++;
};
$userEmail = 'food-scan-confirm-test-' . bin2hex(random_bytes(8)) . '@example.invalid';

try {
	$bananaQuery = $database->prepare('SELECT id, calories, protein_g, carbohydrates_g, fat_g, fiber_g FROM foods WHERE name = ? AND is_active = 1 LIMIT 1');
	$bananaQuery->execute(['Banana (Raw)']);
	$banana = $bananaQuery->fetch(PDO::FETCH_ASSOC);
	$appleQuery = $database->prepare('SELECT id FROM foods WHERE name = ? AND is_active = 1 LIMIT 1');
	$appleQuery->execute(['Apple with Skin (Raw)']);
	$appleId = $appleQuery->fetchColumn();
	if (!$banana || $appleId === false) {
		throw new RuntimeException('Required active catalog foods are missing.');
	}

	$database->beginTransaction();
	$createUser = $database->prepare('INSERT INTO users (uuid, email, password_hash, status) VALUES (?, ?, ?, ?)');
	$createUser->execute([auth_uuid(), $userEmail, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), 'active']);
	$testUserId = (int) $database->lastInsertId();
	$createInactiveFood = $database->prepare(
		'INSERT INTO foods (name, serving_size, serving_unit, calories, protein_g, carbohydrates_g, fat_g, fiber_g, sugar_g, sodium_mg, source, is_active)
		 VALUES (?, 100, ?, 1, 1, 1, 1, 1, 1, 1, ?, 0)'
	);
	$createInactiveFood->execute(['Temporary Inactive Food Scan Test', 'g', 'temporary test fixture']);
	$inactiveFoodId = (int) $database->lastInsertId();
	$database->commit();

	$beforeUserRows = (int) $database->query('SELECT COUNT(*) FROM meal_logs WHERE user_id = ' . $testUserId)->fetchColumn();
	$single = FoodScanConfirmationService::confirm($database, $testUserId, [
		'meal_type' => 'lunch',
		'items' => [['food_id' => (int) $banana['id'], 'quantity' => 150, 'unit' => 'g']],
	]);
	$singleRowId = $single['items'][0]['meal_log_id'];
	$stored = $database->prepare('SELECT user_id, food_id, food_name, quantity, unit, calories, protein_g, carbohydrates_g, fat_g, fiber_g, source FROM meal_logs WHERE id = ?');
	$stored->execute([$singleRowId]);
	$singleRow = $stored->fetch(PDO::FETCH_ASSOC);
	$assert($single['meal_type'] === 'lunch' && count($single['items']) === 1, 'Single confirmation should return one item.');
	$assert((int) $singleRow['user_id'] === $testUserId, 'Meal row must belong to the authenticated test user.');
	$assert((int) $singleRow['food_id'] === (int) $banana['id'] && $singleRow['food_name'] === 'Banana (Raw)', 'Canonical food ID and name must be used.');
	$assert((float) $singleRow['quantity'] === 150.0 && $singleRow['unit'] === 'g', 'Confirmed quantity and unit must be stored.');
	$assert($singleRow['source'] === 'food_scan', 'Meal row source must be food_scan.');
	foreach (['calories', 'protein_g', 'carbohydrates_g', 'fat_g', 'fiber_g'] as $nutrient) {
		$assert(abs((float) $singleRow[$nutrient] - round((float) $banana[$nutrient] * 1.5, 2)) < 0.0001, 'Server-calculated value should be stored for ' . $nutrient . '.');
	}
	fwrite(STDOUT, "PASS single valid food and server-side nutrition\n");

	$spoof = ['meal_type' => 'lunch', 'items' => [[
		'food_id' => (int) $banana['id'], 'quantity' => 150, 'unit' => 'g', 'calories' => 99999, 'protein_g' => 99999,
	]]];
	try {
		FoodScanConfirmationService::confirm($database, $testUserId, $spoof);
		throw new RuntimeException('Nutrition spoofing fields were accepted.');
	} catch (FoodScanConfirmationValidationException $exception) {
		$assert(isset($exception->fields['items.0.calories']) && isset($exception->fields['items.0.protein_g']), 'Spoofed nutrition fields should be explicitly rejected.');
	}
	fwrite(STDOUT, "PASS client-supplied nutrition rejected\n");

	$multiple = FoodScanConfirmationService::confirm($database, $testUserId, [
		'meal_type' => 'breakfast',
		'items' => [
			['food_id' => (int) $banana['id'], 'quantity' => 150, 'unit' => 'g'],
			['food_id' => (int) $appleId, 'quantity' => 100, 'unit' => 'g'],
		],
	]);
	$assert(count($multiple['items']) === 2, 'Two confirmed foods should return two rows.');
	$multipleRows = $database->prepare('SELECT COUNT(*) FROM meal_logs WHERE user_id = ? AND source = ?');
	$multipleRows->execute([$testUserId, 'food_scan']);
	$assert((int) $multipleRows->fetchColumn() === 3, 'Single and multi-item confirmations should each create one row per food.');
	fwrite(STDOUT, "PASS multiple catalog foods create separate rows\n");

	$reject = static function (array $request, string $field, string $message) use ($database, $testUserId, $assert): void {
		try {
			FoodScanConfirmationService::confirm($database, $testUserId, $request);
			throw new RuntimeException($message);
		} catch (FoodScanConfirmationValidationException $exception) {
			$assert(isset($exception->fields[$field]), $message);
		}
	};
	$rowsBeforeInvalid = (int) $database->query('SELECT COUNT(*) FROM meal_logs WHERE user_id = ' . $testUserId)->fetchColumn();
	$reject(['meal_type' => 'lunch', 'items' => []], 'items', 'Empty items array should be rejected.');
	$reject(['items' => [['food_id' => (int) $banana['id'], 'quantity' => 100, 'unit' => 'g']]], 'meal_type', 'Missing meal type should be rejected.');
	$reject(['meal_type' => 'lunch', 'items' => [['quantity' => 100, 'unit' => 'g']]], 'items.0.food_id', 'Missing food ID should be rejected.');
	$reject(['meal_type' => 'lunch', 'items' => [['food_id' => (string) $banana['id'], 'quantity' => 100, 'unit' => 'g']]], 'items.0.food_id', 'String food ID should be rejected.');
	$reject(['user_id' => $testUserId, 'meal_type' => 'lunch', 'items' => [['food_id' => (int) $banana['id'], 'quantity' => 100, 'unit' => 'g']]], 'user_id', 'Client-supplied user ID should be rejected.');
	$reject(['meal_type' => 'lunch', 'items' => [['food_id' => 999999999, 'quantity' => 100, 'unit' => 'g']]], 'items.0.food_id', 'Unknown food ID should be rejected.');
	$reject(['meal_type' => 'lunch', 'items' => [['food_id' => (int) $inactiveFoodId, 'quantity' => 100, 'unit' => 'g']]], 'items.0.food_id', 'Inactive food ID should be rejected.');
	foreach ([0, -10, 'not-a-number', INF] as $quantity) {
		$reject(['meal_type' => 'lunch', 'items' => [['food_id' => (int) $banana['id'], 'quantity' => $quantity, 'unit' => 'g']]], 'items.0.quantity', 'Invalid quantity should be rejected.');
	}
	$reject(['meal_type' => 'morning_snack', 'items' => [['food_id' => (int) $banana['id'], 'quantity' => 100, 'unit' => 'g']]], 'meal_type', 'Unsupported meal type should be rejected.');
	$reject(['meal_type' => 'lunch', 'items' => [['food_id' => (int) $banana['id'], 'quantity' => 100, 'unit' => 'ml']]], 'items.0.unit', 'Incompatible unit should be rejected.');
	$reject(['meal_type' => 'lunch', 'items' => [['food_id' => (int) $banana['id'], 'quantity' => 100, 'unit' => 'g', 'food_name' => 'Spoofed']]], 'items.0.food_name', 'Client-supplied food name should be rejected.');
	$assert((int) $database->query('SELECT COUNT(*) FROM meal_logs WHERE user_id = ' . $testUserId)->fetchColumn() === $rowsBeforeInvalid, 'Invalid requests must not add rows.');
	fwrite(STDOUT, "PASS invalid IDs, inactive food, quantities, meal type, units, and names rejected\n");

	$rollbackBefore = (int) $database->query('SELECT COUNT(*) FROM meal_logs WHERE user_id = ' . $testUserId)->fetchColumn();
	$reject([
		'meal_type' => 'lunch',
		'items' => [
			['food_id' => (int) $banana['id'], 'quantity' => 100, 'unit' => 'g'],
			['food_id' => 999999999, 'quantity' => 100, 'unit' => 'g'],
		],
	], 'items.1.food_id', 'Mixed valid/invalid request should fail atomically.');
	$assert((int) $database->query('SELECT COUNT(*) FROM meal_logs WHERE user_id = ' . $testUserId)->fetchColumn() === $rollbackBefore, 'A later invalid item must leave no partial row.');
	fwrite(STDOUT, "PASS multi-item request has no partial persistence on validation failure\n");

	$assert($beforeUserRows === 0, 'Test user should have started without meal rows.');
	fprintf(STDOUT, "All %d confirmation assertions passed.\n", $assertions);
} finally {
	if ($database->inTransaction()) {
		$database->rollBack();
	}
	if ($testUserId !== null) {
		$deleteUser = $database->prepare('DELETE FROM users WHERE id = ? AND email = ?');
		$deleteUser->execute([$testUserId, $userEmail]);
	}
	if ($inactiveFoodId !== null) {
		$deleteFood = $database->prepare('DELETE FROM foods WHERE id = ? AND name = ? AND is_active = 0');
		$deleteFood->execute([$inactiveFoodId, 'Temporary Inactive Food Scan Test']);
	}
}