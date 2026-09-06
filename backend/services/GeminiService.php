<?php

declare(strict_types=1);

final class GeminiService
{
	public static function generateFoodRecommendations(array $context, ?string $mealType = null): array
	{
		$prompt = self::buildPrompt($context, $mealType);
		return self::parseResponse(self::sendRequest($prompt, true));
	}

	public static function testConnection(): string
	{
		$response = self::sendRequest('Reply with exactly: Gemini connection OK', false);
		$decoded = json_decode($response, true);
		$text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? null;
		$finishReason = $decoded['candidates'][0]['finishReason'] ?? null;

		if ($finishReason === 'SAFETY' || !is_string($text)) {
			throw new RuntimeException('Gemini did not return a usable test response.');
		}

		return self::text($text, 1000);
	}

	private static function sendRequest(string $prompt, bool $jsonResponse): string
	{
		$apiKey = trim((string) app_config('GEMINI_API_KEY', ''));
		$model = trim((string) app_config('GEMINI_MODEL', ''));
		$apiVersion = trim((string) app_config('GEMINI_API_VERSION', ''));
		$timeout = filter_var(app_config('GEMINI_TIMEOUT_SECONDS', ''), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 120]]);

		if ($apiKey === '' || $model === '' || $apiVersion === false || $apiVersion === '' || $timeout === false) {
			throw new RuntimeException('Gemini configuration is invalid.');
		}

		$url = 'https://generativelanguage.googleapis.com/' . rawurlencode($apiVersion) . '/models/' . rawurlencode($model) . ':generateContent';
		$generationConfig = ['temperature' => 0.4];
		if ($jsonResponse) {
			$generationConfig['responseMimeType'] = 'application/json';
			$generationConfig['responseSchema'] = [
				'type' => 'ARRAY',
				'items' => [
					'type' => 'OBJECT',
					'properties' => [
						'meal_type' => ['type' => 'STRING', 'enum' => ['breakfast', 'lunch', 'snack', 'dinner']],
						'title' => ['type' => 'STRING'],
						'description' => ['type' => 'STRING'],
						'foods' => [
							'type' => 'ARRAY',
							'items' => [
								'type' => 'OBJECT',
								'properties' => [
									'name' => ['type' => 'STRING'],
									'quantity' => ['type' => 'STRING'],
								],
								'required' => ['name', 'quantity'],
							],
						],
						'estimated_nutrition' => [
							'type' => 'OBJECT',
							'properties' => array_fill_keys(
								['calories', 'protein_g', 'carbohydrates_g', 'fat_g', 'fiber_g'],
								['type' => 'NUMBER']
							),
							'required' => ['calories', 'protein_g', 'carbohydrates_g', 'fat_g', 'fiber_g'],
						],
						'reason' => ['type' => 'STRING'],
					],
					'required' => ['meal_type', 'title', 'description', 'foods', 'estimated_nutrition', 'reason'],
				],
			];
		}

		$payload = json_encode([
			'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
			'generationConfig' => $generationConfig,
		], JSON_THROW_ON_ERROR);

		$curl = curl_init($url);
		if ($curl === false) {
			throw new RuntimeException('Gemini request could not be initialized.');
		}

		curl_setopt_array($curl, [
			CURLOPT_POST => true,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-goog-api-key: ' . $apiKey],
			CURLOPT_POSTFIELDS => $payload,
			CURLOPT_CONNECTTIMEOUT => min($timeout, 10),
			CURLOPT_TIMEOUT => $timeout,
		]);
		$responseBody = curl_exec($curl);
		$curlError = curl_error($curl);
		$status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
		curl_close($curl);

		if ($responseBody === false || $curlError !== '') {
			throw new RuntimeException('Gemini request failed.');
		}

		if ($status < 200 || $status >= 300) {
			$errorStatus = 'unknown';
			$errorResponse = json_decode((string) $responseBody, true);
			if (is_array($errorResponse) && is_array($errorResponse['error'] ?? null)) {
				$errorStatus = is_string($errorResponse['error']['status'] ?? null)
					? $errorResponse['error']['status']
					: (string) ($errorResponse['error']['code'] ?? 'unknown');
			}
			throw new RuntimeException('Gemini HTTP error ' . $status . ' (' . preg_replace('/[^A-Z0-9_-]/', '', $errorStatus) . ').');
		}

		return $responseBody;
	}

	private static function buildPrompt(array $context, ?string $mealType): string
	{
		$focus = $mealType === null ? 'appropriate for the user\'s current remaining daily nutrition' : 'with extra attention to ' . $mealType . ' while still completing the full day';
		$contextJson = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

		return <<<PROMPT
Act as a nutrition recommendation assistant, not a doctor. Recommend practical everyday foods and meals {$focus}.

Use the supplied calculated targets and logged foods as facts. Do not invent medical diagnoses. Do not prescribe medication or supplements. Do not claim to treat diseases. Do not contradict the calculated calorie or macronutrient targets. Prefer realistic everyday foods. Consider the user's fitness goal. Avoid extreme calorie restriction and extreme overeating.

Return a JSON array only, with exactly four recommendations in this order: breakfast, lunch, snack, dinner. Generate exactly one recommendation for each meal type, using the authenticated user's nutrition targets, profile, fitness goal, activity level, and available daily calorie/protein context. Each recommendation must contain meal_type (exactly one of breakfast, lunch, snack, dinner), title, description, foods (an array of objects with name and quantity), estimated_nutrition (calories, protein_g, carbohydrates_g, fat_g, fiber_g as non-negative numbers), and reason. Do not combine meal types, omit a meal type, duplicate a meal type, include generic recommendation categories, or include fields other than those requested. Do not include markdown.

User and nutrition context:
{$contextJson}
PROMPT;
	}

	private static function parseResponse(string $responseBody): array
	{
		$decoded = json_decode($responseBody, true);
		$text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? null;
		$finishReason = $decoded['candidates'][0]['finishReason'] ?? null;

		if ($finishReason === 'SAFETY' || !is_string($text) || trim($text) === '') {
			throw new RuntimeException('Gemini did not return usable recommendations.');
		}

		$text = trim($text);
		if (str_starts_with($text, '```') && str_ends_with($text, '```')) {
			$text = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $text) ?? '';
		}
		$recommendations = json_decode(trim($text), true);
		if (!is_array($recommendations) || isset($recommendations['recommendations'])) {
			$recommendations = $recommendations['recommendations'] ?? null;
		}

		if (!is_array($recommendations) || count($recommendations) !== 4) {
			throw new RuntimeException('Gemini returned malformed recommendations.');
		}

		$normalized = [];
		$mealTypes = ['breakfast', 'lunch', 'snack', 'dinner'];
		$seenMealTypes = [];
		foreach ($recommendations as $recommendation) {
			if (!is_array($recommendation) || !is_string($recommendation['meal_type'] ?? null) || !is_string($recommendation['title'] ?? null) || !is_string($recommendation['description'] ?? null) || !is_string($recommendation['reason'] ?? null) || !is_array($recommendation['foods'] ?? null) || !is_array($recommendation['estimated_nutrition'] ?? null)) {
				throw new RuntimeException('Gemini returned malformed recommendations.');
			}
			$mealType = strtolower(trim($recommendation['meal_type']));
			if (!in_array($mealType, $mealTypes, true) || isset($seenMealTypes[$mealType])) {
				throw new RuntimeException('Gemini returned invalid meal types.');
			}
			$seenMealTypes[$mealType] = true;

			if ($recommendation['foods'] === []) {
				throw new RuntimeException('Gemini returned malformed foods.');
			}
			$foods = [];
			foreach ($recommendation['foods'] as $food) {
				if (!is_array($food) || !is_string($food['name'] ?? null) || !is_string($food['quantity'] ?? null)) {
					throw new RuntimeException('Gemini returned malformed foods.');
				}
				$foods[] = ['name' => self::text($food['name']), 'quantity' => self::text($food['quantity'])];
			}

			$nutrition = [];
			foreach (['calories', 'protein_g', 'carbohydrates_g', 'fat_g', 'fiber_g'] as $field) {
				$value = $recommendation['estimated_nutrition'][$field] ?? null;
				if (!is_numeric($value) || !is_finite((float) $value) || (float) $value < 0) {
					throw new RuntimeException('Gemini returned malformed nutrition.');
				}
				$nutrition[$field] = round((float) $value, 2);
			}

			$normalized[] = [
				'meal_type' => $mealType,
				'title' => self::text($recommendation['title']),
				'description' => self::text($recommendation['description']),
				'foods' => $foods,
				'estimated_nutrition' => $nutrition,
				'reason' => self::text($recommendation['reason']),
			];
		}
		if (array_keys($seenMealTypes) !== $mealTypes) {
			throw new RuntimeException('Gemini returned incomplete meal types.');
		}

		return $normalized;
	}

	private static function text(string $value, int $maxLength = 500): string
	{
		$value = trim(preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '');
		if ($value === '' || strlen($value) > $maxLength) {
			throw new RuntimeException('Gemini returned invalid text.');
		}

		return $value;
	}
}