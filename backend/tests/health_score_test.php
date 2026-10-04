<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
	fwrite(STDERR, "Run this test from the command line.\n");
	exit(1);
}

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/services/HealthTrackingService.php';
require_once dirname(__DIR__) . '/services/HealthScoreService.php';

$database = database_connection();
$assertions = 0;
$countRows = static function (PDO $database, string $table): int {
	if (!in_array($table, ['users', 'foods', 'meal_logs', 'water_logs', 'workout_logs', 'sleep_logs', 'food_scans'], true)) {
		throw new InvalidArgumentException('Unexpected test table.');
	}

	return (int) $database->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
};
$baselineCounts = [];
foreach (['users', 'foods', 'meal_logs', 'water_logs', 'workout_logs', 'sleep_logs', 'food_scans'] as $table) {
	$baselineCounts[$table] = $countRows($database, $table);
}
$assert = static function (bool $condition, string $message) use (&$assertions): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
	$assertions++;
};
$targets = [
	'calories_target' => 2000,
	'protein_g' => 100,
	'carbohydrates_g' => 250,
	'fat_g' => 70,
	'fiber_g' => 30,
	'water_ml' => 2000,
];
$emptyFood = ['meal_count' => 0, 'calories' => 0, 'protein_g' => 0, 'carbohydrates_g' => 0, 'fat_g' => 0, 'fiber_g' => 0];
$emptyWater = ['entry_count' => 0, 'consumed_ml' => 0];
$emptyWorkout = ['workout_count' => 0, 'duration_minutes' => 0, 'calories_burned' => 0];

$assertScoreBounds = static function (array $score) use ($assert): void {
	$assert($score['score'] === null || ($score['score'] >= 0 && $score['score'] <= 100), 'Final score must remain between 0 and 100.');
	foreach ($score['components'] as $component) {
		$assert($component === null || ($component >= 0 && $component <= 100), 'Every available component must remain between 0 and 100.');
	}
};

try {
	$empty = HealthScoreService::calculate($targets, $emptyFood, $emptyWater, $emptyWorkout, null);
	$assert($empty['score'] === 0, 'A completely empty day with valid targets must score zero, not perfect.');
	$assert($empty['components'] === ['nutrition' => 0.0, 'hydration' => 0.0, 'workout' => 0.0, 'sleep' => 0.0, 'consistency' => 0.0], 'Empty-day components should all be zero.');

	$partialFood = ['meal_count' => 1, 'calories' => 1000, 'protein_g' => 50, 'carbohydrates_g' => 125, 'fat_g' => 35, 'fiber_g' => 15];
	$partialNutrition = HealthScoreService::calculate($targets, $partialFood, $emptyWater, $emptyWorkout, null);
	$assert($partialNutrition['components']['nutrition'] === 60.0, 'Partial nutrition should score actual intake against targets, not reward the log itself.');

	$nearTargetFood = ['meal_count' => 2, 'calories' => 1800, 'protein_g' => 95, 'carbohydrates_g' => 240, 'fat_g' => 65, 'fiber_g' => 28];
	$nearTarget = HealthScoreService::calculate($targets, $nearTargetFood, $emptyWater, $emptyWorkout, null);
	$assert($nearTarget['components']['nutrition'] > 90.0 && $nearTarget['components']['nutrition'] <= 100.0, 'Nutrition close to targets should score well with tolerance for modest deviation.');
	$assert(HealthScoreService::calculate($targets, $emptyFood, $emptyWater, $emptyWorkout, null)['components']['nutrition'] === 0.0, 'No nutrition logging must not yield a perfect nutrition score.');
	$partialFiber = HealthScoreService::calculate(
		$targets,
		[
			'meal_count' => 1,
			'calories' => 2000,
			'protein_g' => 100,
			'carbohydrates_g' => 250,
			'fat_g' => 70,
			'fiber_g' => 0,
			'nutrient_counts' => ['calories' => 1, 'protein_g' => 1, 'carbohydrates_g' => 1, 'fat_g' => 1, 'fiber_g' => 0],
		],
		$emptyWater,
		$emptyWorkout,
		null
	);
	$assert($partialFiber['components']['nutrition'] === 100.0, 'A nutrient without complete logged values should be omitted rather than treated as zero intake.');

	foreach ([0 => 0.0, 1000 => 50.0, 2500 => 100.0, 5000 => 100.0] as $consumed => $expected) {
		$water = ['entry_count' => $consumed > 0 ? 1 : 0, 'consumed_ml' => $consumed];
		$score = HealthScoreService::calculate($targets, $emptyFood, $water, $emptyWorkout, null);
		$assert($score['components']['hydration'] === $expected, 'Hydration should be capped and accurately reflect target completion.');
	}

	$noWorkout = HealthScoreService::calculate($targets, $emptyFood, $emptyWater, $emptyWorkout, null);
	$completedWorkout = HealthScoreService::calculate(
		$targets,
		$emptyFood,
		$emptyWater,
		['workout_count' => 1, 'duration_minutes' => 30, 'calories_burned' => 0],
		null
	);
	$assert($noWorkout['components']['workout'] === 0.0, 'No workout should score zero.');
	$assert($completedWorkout['components']['workout'] === 100.0, 'A 30-minute recorded workout should meet the daily duration benchmark.');
	$longWorkout = HealthScoreService::calculate($targets, $emptyFood, $emptyWater, ['duration_minutes' => 120], null);
	$assert($longWorkout['components']['workout'] === 100.0, 'Workout component must cap at 100.');

	$missingSleep = HealthScoreService::calculate($targets, $emptyFood, $emptyWater, $emptyWorkout, null);
	$goodSleep = HealthScoreService::calculate(
		$targets,
		$emptyFood,
		$emptyWater,
		$emptyWorkout,
		['duration_minutes' => 480]
	);
	$assert($missingSleep['components']['sleep'] === 0.0, 'Missing sleep must not be treated as perfect.');
	$assert($goodSleep['components']['sleep'] === 100.0, 'Eight hours of recorded sleep should score within the full-score band.');
	$assert(HealthScoreService::calculate($targets, $emptyFood, $emptyWater, $emptyWorkout, ['duration_minutes' => 420])['components']['sleep'] === 100.0, 'Sleep scoring should tolerate a few minutes difference at the lower band.');

	$engagedFood = ['meal_count' => 1, 'calories' => 0, 'protein_g' => 0, 'carbohydrates_g' => 0, 'fat_g' => 0, 'fiber_g' => 0];
	$engagedWater = ['entry_count' => 1, 'consumed_ml' => 100];
	$engagedWorkout = ['workout_count' => 1, 'duration_minutes' => 1];
	$oneCategory = HealthScoreService::calculate($targets, $engagedFood, $emptyWater, $emptyWorkout, null);
	$assert($oneCategory['components']['consistency'] === 25.0, 'Consistency should reflect meaningful tracked categories, not app opens.');
	$noTargets = HealthScoreService::calculate(null, $emptyFood, $emptyWater, $emptyWorkout, null);
	$assert($noTargets['components']['nutrition'] === null && $noTargets['components']['hydration'] === null, 'Missing nutrition targets should make those components unavailable, not perfect or zero.');
	$assert(in_array('consistency', $noTargets['available_components'], true), 'Consistency must remain reportable when nutrition targets are unavailable.');

	$perfectFood = ['meal_count' => 3, 'calories' => 2000, 'protein_g' => 100, 'carbohydrates_g' => 250, 'fat_g' => 70, 'fiber_g' => 30];
	$perfectWater = ['entry_count' => 3, 'consumed_ml' => 2000];
	$perfectWorkout = ['workout_count' => 1, 'duration_minutes' => 30];
	$perfectSleep = ['duration_minutes' => 480];
	$perfect = HealthScoreService::calculate($targets, $perfectFood, $perfectWater, $perfectWorkout, $perfectSleep);
	$assert($perfect['score'] === 100, 'A near-perfect day should achieve a 100 weighted score.');
	$assert($perfect['components'] === ['nutrition' => 100.0, 'hydration' => 100.0, 'workout' => 100.0, 'sleep' => 100.0, 'consistency' => 100.0], 'Perfect day component scores should be 100.');

	$weighted = HealthScoreService::calculate(
		$targets,
		$perfectFood,
		['entry_count' => 1, 'consumed_ml' => 1000],
		$emptyWorkout,
		['duration_minutes' => 480]
	);
	$expectedWeighted = (100 * 0.30 + 50 * 0.20 + 0 * 0.20 + 100 * 0.20 + 75 * 0.10);
	$assert($weighted['score'] === (int) round($expectedWeighted), 'Final score must apply the specified component weights.');
	$assertScoreBounds($weighted);

	$firstUsers = $database->query('SELECT id FROM users ORDER BY id ASC LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
	if (count($firstUsers) < 2) {
		throw new RuntimeException('Two existing users are required for the user-isolation test.');
	}
	$userA = (int) $firstUsers[0];
	$userB = (int) $firstUsers[1];
	$database->beginTransaction();
	$localTimezone = new DateTimeZone('Asia/Kolkata');
	$localDate = new DateTimeImmutable('2024-01-16 00:00:00', $localTimezone);
	$insertMeal = $database->prepare(
		'INSERT INTO meal_logs (user_id, meal_type, food_name, calories, protein_g, carbohydrates_g, fat_g, fiber_g, consumed_at, source)
		 VALUES (:user_id, :meal_type, :food_name, :calories, :protein_g, :carbohydrates_g, :fat_g, :fiber_g, :consumed_at, :source)'
	);
	$insertMeal->execute([
		'user_id' => $userA,
		'meal_type' => 'breakfast',
		'food_name' => 'Health score timezone test',
		'calories' => 500,
		'protein_g' => 25,
		'carbohydrates_g' => 60,
		'fat_g' => 10,
		'fiber_g' => 5,
		'consumed_at' => '2024-01-15 19:00:00',
		'source' => 'manual',
	]);
	$insertMeal->execute([
		'user_id' => $userA,
		'meal_type' => 'snack',
		'food_name' => 'Health score previous local day test',
		'calories' => 999,
		'protein_g' => 99,
		'carbohydrates_g' => 99,
		'fat_g' => 99,
		'fiber_g' => 99,
		'consumed_at' => '2024-01-15 18:29:00',
		'source' => 'manual',
	]);
	$insertWater = $database->prepare('INSERT INTO water_logs (user_id, amount_ml, consumed_at, source) VALUES (?, ?, ?, ?)');
	$insertWater->execute([$userA, 1000, '2024-01-15 19:00:00', 'manual']);
	$insertWorkout = $database->prepare('INSERT INTO workout_logs (user_id, workout_type, duration_minutes, calories_burned, workout_date) VALUES (?, ?, ?, ?, ?)');
	$insertWorkout->execute([$userA, 'health score test', 30, 0, '2024-01-15 19:00:00']);
	$insertSleep = $database->prepare('INSERT INTO sleep_logs (user_id, sleep_start, sleep_end, duration_minutes, source) VALUES (?, ?, ?, ?, ?)');
	$insertSleep->execute([$userA, '2024-01-15 19:30:00', '2024-01-16 03:30:00', 480, 'manual']);

	$userASummary = HealthScoreService::dailySummary($database, $userA, $localDate, $localTimezone, $targets);
	$userBSummary = HealthScoreService::dailySummary($database, $userB, $localDate, $localTimezone, $targets);
	$assert($userASummary['date'] === '2024-01-16', 'The response date must be the selected local calendar day.');
	$assert($userASummary['food']['meal_count'] === 1 && $userASummary['food']['calories'] === 500.0, 'Local-day boundary should include only matching UTC food records.');
	$assert($userASummary['water']['consumed_ml'] === 1000.0, 'Local-day water totals should include records in the user timezone.');
	$assert($userASummary['workout']['duration_minutes'] === 30, 'Local-day workout totals should use the user timezone.');
	$assert($userASummary['sleep']['duration_minutes'] === 480 && $userASummary['sleep']['sleep_date'] === '2024-01-16', 'Sleep date and duration should use the user timezone.');
	$assert($userBSummary['food']['meal_count'] === 0 && $userBSummary['water']['consumed_ml'] === 0.0 && $userBSummary['workout']['duration_minutes'] === 0 && $userBSummary['sleep'] === null, 'One user’s records must not affect another user’s score.');
	foreach (['food', 'water', 'workout', 'sleep', 'health_score'] as $responseField) {
		$assert(array_key_exists($responseField, $userASummary), 'Daily summary must retain the ' . $responseField . ' response surface.');
	}

	$futureTimestamp = HealthTrackingService::now()->modify('+10 minutes')->format('Y-m-d H:i:s');
	$insertMeal->execute([
		'user_id' => $userB,
		'meal_type' => 'dinner',
		'food_name' => 'Health score future-record test',
		'calories' => 2000,
		'protein_g' => 100,
		'carbohydrates_g' => 250,
		'fat_g' => 70,
		'fiber_g' => 30,
		'consumed_at' => $futureTimestamp,
		'source' => 'manual',
	]);
	$insertWater->execute([$userB, 2000, $futureTimestamp, 'manual']);
	$insertWorkout->execute([$userB, 'future health score test', 30, 0, $futureTimestamp]);
	$insertSleep->execute([$userB, $futureTimestamp, (new DateTimeImmutable($futureTimestamp, new DateTimeZone('UTC')))->modify('+8 hours')->format('Y-m-d H:i:s'), 480, 'manual']);
	$utcTimezone = new DateTimeZone(HealthTrackingService::TIMEZONE);
	$currentUtcDate = new DateTimeImmutable('today', $utcTimezone);
	$futureSummary = HealthScoreService::dailySummary($database, $userB, $currentUtcDate, $utcTimezone, $targets);
	$assert($futureSummary['food']['meal_count'] === 0 && $futureSummary['water']['consumed_ml'] === 0.0, 'Future meal and water records must not count toward today.');
	$assert($futureSummary['workout']['duration_minutes'] === 0 && $futureSummary['sleep'] === null, 'Future workouts and sleep records must not count toward today.');
	$database->rollBack();
	$afterCounts = [];
	foreach (array_keys($baselineCounts) as $table) {
		$afterCounts[$table] = $countRows($database, $table);
	}
	$assert($afterCounts === $baselineCounts, 'Health Score tests must roll back every user, catalog, and health-log fixture.');

	$assertScoreBounds($empty);
	$assertScoreBounds($partialNutrition);
	$assertScoreBounds($nearTarget);
	$assertScoreBounds($noWorkout);
	$assertScoreBounds($goodSleep);
	$assertScoreBounds($perfect);

	fprintf(STDOUT, "All %d Health Score assertions passed; database fixtures were rolled back.\n", $assertions);
} catch (Throwable $exception) {
	if ($database->inTransaction()) {
		$database->rollBack();
	}
	fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . "\n");
	exit(1);
}
