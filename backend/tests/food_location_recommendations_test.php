<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
	fwrite(STDERR, "Run this test from the command line.\n");
	exit(1);
}

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/core/auth.php';
require_once dirname(__DIR__) . '/services/FoodLocationService.php';
require_once dirname(__DIR__) . '/services/GeminiService.php';
require_once dirname(__DIR__) . '/services/FoodRecommendationHistoryService.php';
require_once dirname(__DIR__) . '/services/HealthMedicalProfileService.php';

$database = database_connection();
$assertions = 0;
$userIds = [];
$assert = static function (bool $condition, string $message) use (&$assertions): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
	$assertions++;
};
$testUser = static function (PDO $database, array &$userIds): int {
	$email = 'food-location-test-' . bin2hex(random_bytes(8)) . '@example.invalid';
	$statement = $database->prepare('INSERT INTO users (uuid, email, password_hash, status) VALUES (?, ?, ?, ?)');
	$statement->execute([auth_uuid(), $email, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), 'active']);
	$userIds[] = (int) $database->lastInsertId();

	return end($userIds);
};
$valid = static function (string $region): array {
	return [
		[
			'meal_type' => 'breakfast',
			'title' => $region === 'West Bengal' ? 'Vegetable chire pulao' : 'Punjabi moong dal chilla',
			'description' => 'A balanced meal made with vegetables and whole ingredients.',
			'foods' => [['name' => $region === 'West Bengal' ? 'Flattened rice with peas and vegetables' : 'Moong dal with spinach', 'quantity' => '1 serving']],
			'estimated_nutrition' => ['calories' => 320, 'protein_g' => 14, 'carbohydrates_g' => 48, 'fat_g' => 8, 'fiber_g' => 9],
			'reason' => 'A balanced regional meal.',
			'regional_relevance' => $region . ' home-style ingredients.',
			'preparation_guidance' => 'Lightly sauté vegetables; use minimal oil.',
		],
		[
			'meal_type' => 'lunch',
			'title' => 'Seasonal vegetable dal with rice',
			'description' => 'Pulses, vegetables and whole grains.',
			'foods' => [['name' => 'Lentils, seasonal vegetables and rice', 'quantity' => '1 balanced plate']],
			'estimated_nutrition' => ['calories' => 540, 'protein_g' => 24, 'carbohydrates_g' => 78, 'fat_g' => 12, 'fiber_g' => 15],
			'reason' => 'Provides protein and fiber.',
			'regional_relevance' => $region . ' vegetables and grains.',
			'preparation_guidance' => 'Simmer the dal and steam the rice.',
		],
		[
			'meal_type' => 'snack',
			'title' => 'Fresh guava with roasted chana',
			'description' => 'Fruit with a portion of pulses.',
			'foods' => [['name' => 'Guava and roasted chickpeas', 'quantity' => '1 portion']],
			'estimated_nutrition' => ['calories' => 210, 'protein_g' => 9, 'carbohydrates_g' => 33, 'fat_g' => 5, 'fiber_g' => 8],
			'reason' => 'A whole-food snack.',
			'regional_relevance' => 'Locally available seasonal fruit.',
			'preparation_guidance' => 'Serve fresh fruit with plain roasted chickpeas.',
		],
		[
			'meal_type' => 'dinner',
			'title' => 'Grilled fish with vegetables',
			'description' => 'A protein-rich meal with vegetables.',
			'foods' => [['name' => 'Fresh fish and seasonal greens', 'quantity' => '1 serving']],
			'estimated_nutrition' => ['calories' => 460, 'protein_g' => 36, 'carbohydrates_g' => 35, 'fat_g' => 16, 'fiber_g' => 9],
			'reason' => 'A balanced evening meal.',
			'regional_relevance' => $region . ' seasonal ingredients.',
			'preparation_guidance' => 'Grill or steam with herbs; avoid deep frying.',
		],
	];
};
$mockResponse = static function (array $recommendations): string {
	return json_encode([
		'candidates' => [[
			'finishReason' => 'STOP',
			'content' => ['parts' => [['text' => json_encode($recommendations, JSON_THROW_ON_ERROR)]]],
		]],
	], JSON_THROW_ON_ERROR);
};
$expectInvalidLocation = static function (array $location, string $field) use ($assert): void {
	try {
		FoodLocationService::validate($location);
		throw new RuntimeException('Invalid food location was accepted.');
	} catch (FoodLocationValidationException $exception) {
		$assert(isset($exception->fields[$field]), 'Food location error should identify ' . $field . '.');
	}
};

try {
	$database->query('SELECT user_id FROM user_food_locations LIMIT 0');
	$database->query('SELECT regional_relevance, preparation_guidance, nutrition_source FROM ai_food_recommendations LIMIT 0');
	$userId = $testUser($database, $userIds);
	$otherUserId = $testUser($database, $userIds);
	$database->prepare(
		'INSERT INTO user_health_profiles
		 (user_id, smoking_status, cigarettes_per_day, years_smoked, alcohol_consumption, no_known_medical_conditions)
		 VALUES (?, ?, NULL, NULL, ?, FALSE)'
	)->execute([$userId, 'never', 'never']);
	$database->prepare('INSERT INTO user_allergies (user_id, allergen) VALUES (?, ?)')->execute([$userId, 'test-only peanut allergy']);
	$assert(HealthMedicalProfileService::consentedFoodAllergies($database, $userId) === [], 'Allergies must not be sent without explicit current consent.');
	$consentInsert = $database->prepare('INSERT INTO user_health_consents (user_id, consent_version, consented_at) VALUES (?, ?, UTC_TIMESTAMP())');
	$consentInsert->execute([$userId, '0.9']);
	$assert(HealthMedicalProfileService::consentedFoodAllergies($database, $userId) === [], 'Outdated consent must not authorize allergy processing.');
	$consentInsert->execute([$userId, HealthMedicalProfileService::CONSENT_VERSION]);
	$assert(HealthMedicalProfileService::consentedFoodAllergies($database, $userId) === ['test-only peanut allergy'], 'Only the current consent should allow user-scoped allergy processing.');
	$database->prepare('UPDATE user_health_consents SET withdrawn_at = UTC_TIMESTAMP() WHERE user_id = ? AND consent_version = ?')
		->execute([$userId, HealthMedicalProfileService::CONSENT_VERSION]);
	$assert(HealthMedicalProfileService::consentedFoodAllergies($database, $userId) === [], 'Withdrawn consent must prevent allergy processing.');

	$assert(FoodLocationService::get($database, $userId) === null, 'User without a food location should receive null.');
	$firstLocation = FoodLocationService::save($database, $userId, [
		'country' => 'India',
		'country_code' => 'in',
		'state' => 'West Bengal',
		'city' => 'Kolkata',
	]);
	$assert($firstLocation['country'] === 'India' && $firstLocation['country_code'] === 'IN', 'Country and ISO code should be normalized and saved.');
	$assert($firstLocation['state'] === 'West Bengal' && $firstLocation['city'] === 'Kolkata', 'State and city should be saved.');
	$assert(FoodLocationService::get($database, $otherUserId) === null, 'A different user must not read the saved location.');

	$countryOnly = FoodLocationService::save($database, $userId, ['country' => 'Canada']);
	$assert($countryOnly['country'] === 'Canada' && $countryOnly['state'] === null && $countryOnly['city'] === null, 'Country may be saved without state or city.');
	$updatedLocation = FoodLocationService::save($database, $userId, [
		'country' => 'India',
		'country_code' => 'IN',
		'state' => 'Punjab',
		'city' => null,
	]);
	$assert($updatedLocation['state'] === 'Punjab' && $updatedLocation['city'] === null, 'An existing location should be replaced.');
	$expectInvalidLocation(['state' => 'West Bengal'], 'country');
	$expectInvalidLocation(['country' => ''], 'country');
	$expectInvalidLocation(['country' => '123'], 'country');
	$expectInvalidLocation(['country' => 'India', 'country_code' => 'IND'], 'country_code');
	FoodLocationService::save($database, $otherUserId, ['country' => 'Japan', 'state' => 'Osaka']);
	$assert(FoodLocationService::delete($database, $userId), 'The owner should be able to delete their location.');
	$assert(FoodLocationService::get($database, $otherUserId)['country'] === 'Japan', 'Deleting one user location must not affect another user.');
	$assert(!FoodLocationService::delete($database, $userId) && FoodLocationService::get($database, $userId) === null, 'Location deletion should be safely repeatable.');
	fwrite(STDOUT, "PASS location validation, CRUD, and user isolation\n");

	$westBengalContext = [
		'food_location' => ['country' => 'India', 'country_code' => 'IN', 'state' => 'West Bengal', 'city' => 'Kolkata'],
		'health_constraints' => ['allergies' => [], 'dietary_restrictions' => []],
		'nutrition_targets' => ['calories' => 2000, 'protein_g' => 100],
	];
	$punjabContext = $westBengalContext;
	$punjabContext['food_location']['state'] = 'Punjab';
	$capturedPrompts = [];
	$systemInstructions = [];
	$westBengal = GeminiService::generateFoodRecommendations($westBengalContext, null, static function (string $prompt, int $attempt, string $systemInstruction) use (&$capturedPrompts, &$systemInstructions, $mockResponse, $valid): string {
		$capturedPrompts[] = $prompt;
		$systemInstructions[] = $systemInstruction;
		return $mockResponse($valid('West Bengal'));
	});
	$punjab = GeminiService::generateFoodRecommendations($punjabContext, null, static function (string $prompt, int $attempt, string $systemInstruction) use (&$capturedPrompts, &$systemInstructions, $mockResponse, $valid): string {
		$capturedPrompts[] = $prompt;
		$systemInstructions[] = $systemInstruction;
		return $mockResponse($valid('Punjab'));
	});
	$assert(str_contains($capturedPrompts[0], '"country": "India"') && str_contains($capturedPrompts[0], '"state": "West Bengal"') && str_contains($capturedPrompts[0], '"city": "Kolkata"') && str_contains($capturedPrompts[1], '"state": "Punjab"'), 'Gemini prompt should contain country, state, and optional city.');
	$assert(str_contains($systemInstructions[0], 'Never recommend junk food') && str_contains($systemInstructions[1], 'Respect all listed food allergies'), 'Gemini system instruction should enforce healthy foods and allergy constraints.');
	$assert($westBengal[0]['title'] !== $punjab[0]['title'], 'Different regions should produce region-specific mocked choices.');
	$assert($westBengal[0]['nutrition_source'] === 'ai_estimate' && isset($westBengal[0]['regional_relevance'], $westBengal[0]['preparation_guidance']), 'Existing response keys should remain with additive regional fields and estimated source.');
	$healthy = $valid('West Bengal');
	$assert(count(GeminiService::validateFoodRecommendations($healthy, $westBengalContext)) === 4, 'Healthy regional meals should pass validation.');
	fwrite(STDOUT, "PASS location-aware prompt, regional output, and healthy regional recommendations\n");

	$unsafe = $valid('West Bengal');
	$unsafe[0]['title'] = 'Vegetable pizza';
	$attempts = 0;
	$retried = GeminiService::generateFoodRecommendations($westBengalContext, null, static function (string $prompt, int $attempt) use (&$attempts, $unsafe, $valid, $mockResponse): string {
		$attempts++;
		return $mockResponse($attempt === 1 ? $unsafe : $valid('West Bengal'));
	});
	$assert($attempts === 2 && $retried[0]['title'] !== 'Vegetable pizza', 'Prohibited foods should be rejected and retried once.');
	foreach (['Vegetable pizza', 'Bean burger', 'French fries', 'Potato chips', 'Sugary soda', 'Packaged sugary snacks', 'Frozen ready meals'] as $junkTitle) {
		$junkPlan = $valid('West Bengal');
		$junkPlan[0]['title'] = $junkTitle;
		try {
			GeminiService::validateFoodRecommendations($junkPlan, $westBengalContext);
			throw new RuntimeException('Prohibited food was accepted: ' . $junkTitle);
		} catch (GeminiResponseException) {
			$assert(true, 'Prohibited food should be rejected: ' . $junkTitle);
		}
	}
	$deepFried = $valid('West Bengal');
	$deepFried[3]['preparation_guidance'] = 'Deep-fried in oil.';
	try {
		GeminiService::validateFoodRecommendations($deepFried, $westBengalContext);
		throw new RuntimeException('Deep-fried recommendation was accepted.');
	} catch (GeminiResponseException) {
		$assert(true, 'Deep-fried preparation should be rejected.');
	}
	$healthyTraditional = $valid('West Bengal');
	$healthyTraditional[0]['title'] = 'Traditional Bengali vegetable preparation';
	$assert(count(GeminiService::validateFoodRecommendations($healthyTraditional, $westBengalContext)) === 4, 'Regional traditional meals should not be rejected by broad name matching.');

	$unsafeAttempts = 0;
	try {
		GeminiService::generateFoodRecommendations($westBengalContext, null, static function (string $prompt, int $attempt) use (&$unsafeAttempts, $unsafe, $mockResponse): string {
			$unsafeAttempts++;
			return $mockResponse($unsafe);
		});
		throw new RuntimeException('All-junk generated recommendations were returned.');
	} catch (GeminiResponseException) {
		$assert($unsafeAttempts === 2, 'If every result is rejected, the service should fail after its bounded retry.');
	}
	$allergyContext = $westBengalContext;
	$allergyContext['health_constraints']['allergies'] = ['fish'];
	try {
		GeminiService::validateFoodRecommendations($healthy, $allergyContext);
		throw new RuntimeException('A recommendation containing a listed allergy was accepted.');
	} catch (GeminiResponseException) {
		$assert(true, 'A recommendation conflicting with a consented allergy should be rejected.');
	}
	fwrite(STDOUT, "PASS prohibited food filtering, bounded retries, allergy checks, and safe regional dishes\n");

	$malformedAttempts = 0;
	$recovered = GeminiService::generateFoodRecommendations($westBengalContext, null, static function (string $prompt, int $attempt) use (&$malformedAttempts, $valid, $mockResponse): string {
		$malformedAttempts++;
		return $attempt === 1 ? '{"candidates":[' : $mockResponse($valid('West Bengal'));
	});
	$assert($malformedAttempts === 2 && count($recovered) === 4, 'Malformed output should be retried and recover with a valid response.');
	$timeoutAttempts = 0;
	try {
		GeminiService::generateFoodRecommendations($westBengalContext, null, static function () use (&$timeoutAttempts): string {
			$timeoutAttempts++;
			throw new RuntimeException('Mock Gemini timeout.');
		});
		throw new RuntimeException('Mock Gemini timeout did not fail.');
	} catch (RuntimeException $exception) {
		$assert($timeoutAttempts === 1 && $exception->getMessage() === 'Mock Gemini timeout.', 'Provider unavailability should surface and should not be retried as invalid content.');
	}
	fwrite(STDOUT, "PASS malformed output retry and Gemini unavailability handling\n");

	$recommendations = GeminiService::generateFoodRecommendations($westBengalContext, null, static fn (string $prompt, int $attempt): string => $mockResponse($valid('West Bengal')));
	$database->beginTransaction();
	$generationId = FoodRecommendationHistoryService::save($database, $userId, null, [
		'food_location' => $westBengalContext['food_location'],
		'health_constraints' => ['allergies' => ['test-only allergy'], 'dietary_restrictions' => []],
		'nutrition_targets' => $westBengalContext['nutrition_targets'],
	], $recommendations, 'gemini', 'mock-model');
	$database->commit();
	$history = FoodRecommendationHistoryService::history($database, $userId, 20, 0);
	$matchingHistory = array_values(array_filter($history, static fn (array $entry): bool => $entry['generation_id'] === $generationId));
	$assert(count($matchingHistory) === 1 && $matchingHistory[0]['recommendations'][0]['regional_relevance'] !== null, 'History should retain regional guidance and recommendation structure.');
	$assert($matchingHistory[0]['recommendations'][0]['nutrition_source'] === 'ai_estimate', 'History should retain nutrition provenance.');
	$assert(!isset($matchingHistory[0]['daily_context']['health_constraints']['allergies']), 'Recommendation history should not persist medical allergy names.');
	$deleteGeneration = $database->prepare('DELETE FROM ai_food_recommendation_generations WHERE id = ? AND user_id = ?');
	$deleteGeneration->execute([$generationId, $userId]);

	$router = file_get_contents(dirname(__DIR__) . '/index.php');
	$recommendationApi = file_get_contents(dirname(__DIR__) . '/api/v1/ai/food-recommendations.php');
	$assert(str_contains($router, "'GET /api/v1/health/food-location'") && str_contains($router, "'PUT /api/v1/health/food-location'") && str_contains($router, "'DELETE /api/v1/health/food-location'"), 'All required location routes should be registered.');
	$assert(str_contains($recommendationApi, "EntitlementService::requireFeature") && str_contains($recommendationApi, 'AIUsageService::record'), 'Existing subscription entitlement and usage recording should remain enforced.');
	fwrite(STDOUT, "PASS history compatibility, allergy minimization, route registration, entitlement, and usage checks\n");
	fwrite(STDOUT, "OK {$assertions} assertions\n");
} finally {
	if ($database->inTransaction()) {
		$database->rollBack();
	}
	if ($userIds !== []) {
		$placeholders = implode(',', array_fill(0, count($userIds), '?'));
		$deleteUsers = $database->prepare('DELETE FROM users WHERE id IN (' . $placeholders . ')');
		$deleteUsers->execute($userIds);
	}
}
