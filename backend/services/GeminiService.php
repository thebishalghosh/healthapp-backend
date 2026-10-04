<?php

declare(strict_types=1);

final class GeminiResponseException extends RuntimeException
{
}

final class GeminiService
{
	public static function generateFoodRecommendations(array $context, ?string $mealType = null): array
	{
		$prompt = self::buildPrompt($context, $mealType);
		return self::parseResponse(self::sendRequest($prompt, true));
	}

	public static function detectFoodsFromImage(string $imageData, string $mimeType): array
	{
		$prompt = <<<'PROMPT'
Analyze the provided food photograph. Identify all clearly visible food items and do not invent foods that are not reasonably visible. Estimate the edible quantity in grams or millilitres where possible. Quantities are visual estimates, not exact measurements. Indicate uncertainty through confidence values and notes. If an item's quantity cannot be reasonably estimated, use null for quantity_g and "unknown" for quantity_unit. If no food can be confidently identified, return an empty foods array and explain the uncertainty in notes. Do not calculate or return calories, protein, carbohydrates, fat, fiber, or any other nutrition values. Return only JSON matching the required schema, with no markdown or extra fields.
PROMPT;
		$schema = [
			'type' => 'OBJECT',
			'properties' => [
				'foods' => [
					'type' => 'ARRAY',
					'items' => [
						'type' => 'OBJECT',
						'properties' => [
							'name' => ['type' => 'STRING'],
							'quantity_g' => ['type' => 'NUMBER', 'nullable' => true],
							'quantity_unit' => ['type' => 'STRING', 'enum' => ['g', 'ml', 'unknown']],
							'confidence' => ['type' => 'NUMBER'],
						],
						'required' => ['name', 'quantity_g', 'quantity_unit', 'confidence'],
					],
				],
				'overall_confidence' => ['type' => 'NUMBER'],
				'notes' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
			],
			'required' => ['foods', 'overall_confidence', 'notes'],
		];

		return self::parseFoodScanResponse(self::sendRequest($prompt, true, $imageData, $mimeType, $schema));
	}

	public static function selectCatalogCandidates(
		array $selectionItems,
		?string $imageData,
		?string $mimeType
	): array {
		$schema = [
			'type' => 'OBJECT',
			'properties' => [
				'selections' => [
					'type' => 'ARRAY',
					'items' => [
						'type' => 'OBJECT',
						'properties' => [
							'detection_index' => ['type' => 'INTEGER'],
							'catalog_food_id' => ['type' => 'INTEGER', 'nullable' => true],
							'confidence' => ['type' => 'NUMBER'],
						],
						'required' => ['detection_index', 'catalog_food_id', 'confidence'],
					],
				],
			],
			'required' => ['selections'],
		];
		$prompt = <<<'PROMPT'
Select a catalog candidate for each detected food only when the supplied food name and image support a confident match. The image may be used only to distinguish the listed candidates. Do not select a food based on a broad category such as rice, chicken, lentils, vegetables, or curry. If the visible food or its preparation does not clearly distinguish a candidate, return a null catalog_food_id and low confidence. Return one selection for every detection_index. Use only catalog_food_id values from that detection's supplied candidates. Never invent an ID and never return nutrition, calories, macros, or any other fields. Return only JSON matching the required schema.

Detections and their allowed candidates:
PROMPT;
		$payload = json_encode($selectionItems, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

		$response = self::sendRequest(
			$prompt . "\n" . $payload,
			true,
			$imageData,
			$mimeType,
			$schema
		);

		return self::parseCatalogSelectionResponse($response, $selectionItems);
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

	private static function sendRequest(string $prompt, bool $jsonResponse, ?string $imageData = null, ?string $imageMimeType = null, ?array $responseSchema = null): string
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
			$generationConfig['responseSchema'] = $responseSchema ?? [
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

		$parts = [['text' => $prompt]];
		if ($imageData !== null && $imageMimeType !== null) {
			$parts[] = ['inlineData' => ['mimeType' => $imageMimeType, 'data' => base64_encode($imageData)]];
		}
		$payload = json_encode([
			'contents' => [['role' => 'user', 'parts' => $parts]],
			'generationConfig' => $generationConfig,
		], JSON_THROW_ON_ERROR);

		$maxAttempts = 2;
		$maxRetryDelaySeconds = 3.0;
		$lastStatus = null;
		$lastErrorStatus = 'unknown';

		for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
			$curl = curl_init($url);
			if ($curl === false) {
				throw new RuntimeException('Gemini request could not be initialized.');
			}

			curl_setopt_array($curl, [
				CURLOPT_POST => true,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_HEADER => true,
				CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-goog-api-key: ' . $apiKey],
				CURLOPT_POSTFIELDS => $payload,
				CURLOPT_CONNECTTIMEOUT => min($timeout, 10),
				CURLOPT_TIMEOUT => $timeout,
			]);
			$rawResponse = curl_exec($curl);
			$curlError = curl_error($curl);
			$curlErrorNumber = curl_errno($curl);
			$status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
			$headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
			curl_close($curl);

			$lastStatus = $status > 0 ? $status : null;
			if ($rawResponse === false || $curlErrorNumber !== CURLE_OK) {
				$curlErrorDescription = $curlError === '' ? 'unknown transport failure' : $curlError;
				if (!self::isTransientCurlError($curlErrorNumber) || $attempt >= $maxAttempts) {
					throw new RuntimeException('Gemini cURL error ' . $curlErrorNumber . ' (' . $curlErrorDescription . ').');
				}

				usleep((int) (min($maxRetryDelaySeconds, 0.5 * $attempt) * 1_000_000));
				continue;
			}

			$responseHeaders = substr((string) $rawResponse, 0, $headerSize);
			$responseBody = substr((string) $rawResponse, $headerSize);
			if ($status >= 200 && $status < 300) {
				return $responseBody;
			}

			$errorStatus = 'unknown';
			$errorCode = null;
			$errorResponse = json_decode($responseBody, true);
			if (is_array($errorResponse) && is_array($errorResponse['error'] ?? null)) {
				$errorCode = $errorResponse['error']['code'] ?? null;
				$errorStatus = is_string($errorResponse['error']['status'] ?? null)
					? $errorResponse['error']['status']
					: (string) ($errorResponse['error']['code'] ?? 'unknown');
			}
			$lastErrorStatus = preg_replace('/[^A-Z0-9_-]/', '', $errorStatus) ?: 'unknown';
			$retryable = in_array($status, [429, 503], true);
			$dailyFreeTierQuotaExceeded = false;
			if ($status === 429 && is_array($errorResponse['error']['details'] ?? null)) {
				foreach ($errorResponse['error']['details'] as $detail) {
					if (!is_array($detail) || !is_string($detail['@type'] ?? null)) {
						continue;
					}
					$detailType = $detail['@type'];
					if (str_ends_with($detailType, 'QuotaFailure') && is_array($detail['violations'] ?? null)) {
						foreach ($detail['violations'] as $violation) {
							if (!is_array($violation)) {
								continue;
							}
							$quotaId = $violation['quotaId'] ?? null;
							$quotaMetric = $violation['quotaMetric'] ?? null;
							if ($quotaId === 'GenerateRequestsPerDayPerProjectPerModel-FreeTier' || $quotaMetric === 'generativelanguage.googleapis.com/generate_content_free_tier_requests') {
								$dailyFreeTierQuotaExceeded = true;
							}
						}
					}
				}
			}
			if ($dailyFreeTierQuotaExceeded) {
				$retryable = false;
			}

			if (!$retryable || $attempt >= $maxAttempts) {
				$exceptionMessage = 'Gemini HTTP error ' . $status . ' (' . $lastErrorStatus . ').';
				if ($dailyFreeTierQuotaExceeded) {
					$exceptionMessage .= ' (daily free-tier quota exhausted; retry suppressed).';
				}
				throw new RuntimeException($exceptionMessage);
			}

			$retryDelaySeconds = null;
			if (preg_match('/^\s*Retry-After\s*:\s*([^\r\n]+)\s*$/im', $responseHeaders, $matches) === 1) {
				$retryAfter = trim($matches[1]);
				if (is_numeric($retryAfter)) {
					$retryDelaySeconds = (float) $retryAfter;
				} else {
					$retryAt = strtotime($retryAfter);
					if ($retryAt !== false) {
						$retryDelaySeconds = max(0.0, $retryAt - time());
					}
				}
			}
			if ($retryDelaySeconds === null && is_array($errorResponse['error']['details'] ?? null)) {
				foreach ($errorResponse['error']['details'] as $detail) {
					if (is_array($detail) && is_string($detail['retryDelay'] ?? null) && preg_match('/^\s*([0-9]+(?:\.[0-9]+)?)s\s*$/', $detail['retryDelay'], $matches) === 1) {
						$retryDelaySeconds = (float) $matches[1];
						break;
					}
				}
			}
			if ($retryDelaySeconds === null) {
				$retryDelaySeconds = 0.5 * (2 ** ($attempt - 1));
			}

			$selectedRetryDelaySeconds = min($maxRetryDelaySeconds, max(0.0, $retryDelaySeconds));
			usleep((int) ($selectedRetryDelaySeconds * 1_000_000));
		}

		$detail = $lastStatus === null ? 'unknown transport failure' : 'HTTP ' . $lastStatus . ' (' . $lastErrorStatus . ')';
		$exceptionMessage = 'Gemini request failed after ' . $maxAttempts . ' attempts (' . $detail . ').';
		throw new RuntimeException($exceptionMessage);
	}

	private static function isTransientCurlError(int $errorNumber): bool
	{
		return in_array($errorNumber, [
			CURLE_OPERATION_TIMEDOUT,
			CURLE_COULDNT_CONNECT,
			CURLE_COULDNT_RESOLVE_HOST,
			CURLE_COULDNT_RESOLVE_PROXY,
			CURLE_RECV_ERROR,
			CURLE_SEND_ERROR,
			CURLE_GOT_NOTHING,
			CURLE_PARTIAL_FILE,
		], true);
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

	private static function parseFoodScanResponse(string $responseBody): array
	{
		$decoded = json_decode($responseBody, true);
		$text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? null;
		$finishReason = $decoded['candidates'][0]['finishReason'] ?? null;

		if ($finishReason === 'SAFETY' || !is_string($text) || trim($text) === '') {
			throw new GeminiResponseException('Gemini did not return usable food detection results.');
		}

		$result = json_decode(trim($text), true);
		if (!is_array($result) || !is_array($result['foods'] ?? null) || !is_numeric($result['overall_confidence'] ?? null) || !is_array($result['notes'] ?? null)) {
			throw new GeminiResponseException('Gemini returned malformed food detection results.');
		}

		$overallConfidence = (float) $result['overall_confidence'];
		if (!is_finite($overallConfidence) || $overallConfidence < 0 || $overallConfidence > 1) {
			throw new GeminiResponseException('Gemini returned invalid food detection confidence.');
		}

		$foods = [];
		foreach ($result['foods'] as $food) {
			if (!is_array($food) || !is_string($food['name'] ?? null) || !is_string($food['quantity_unit'] ?? null) || !is_numeric($food['confidence'] ?? null)) {
				throw new GeminiResponseException('Gemini returned malformed food items.');
			}
			$quantity = $food['quantity_g'] ?? null;
			$confidence = (float) $food['confidence'];
			if (($quantity !== null && (!is_numeric($quantity) || !is_finite((float) $quantity) || (float) $quantity < 0)) || !in_array($food['quantity_unit'], ['g', 'ml', 'unknown'], true) || !is_finite($confidence) || $confidence < 0 || $confidence > 1) {
				throw new GeminiResponseException('Gemini returned invalid food item estimates.');
			}
			$foods[] = [
				'name' => self::foodScanText($food['name']),
				'quantity_g' => $quantity === null ? null : round((float) $quantity, 2),
				'quantity_unit' => $food['quantity_unit'],
				'confidence' => round($confidence, 4),
			];
		}

		$notes = [];
		foreach ($result['notes'] as $note) {
			if (!is_string($note)) {
				throw new GeminiResponseException('Gemini returned malformed food detection notes.');
			}
			$notes[] = self::foodScanText($note);
		}

		return [
			'foods' => $foods,
			'overall_confidence' => round($overallConfidence, 4),
			'notes' => $notes,
		];
	}

	private static function parseCatalogSelectionResponse(string $responseBody, array $selectionItems): array
	{
		$decoded = json_decode($responseBody, true);
		$text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? null;
		$finishReason = $decoded['candidates'][0]['finishReason'] ?? null;
		if ($finishReason === 'SAFETY' || !is_string($text) || trim($text) === '') {
			throw new GeminiResponseException('Gemini did not return usable catalog selections.');
		}

		$result = json_decode(trim($text), true);
		if (!is_array($result) || array_keys($result) !== ['selections'] || !is_array($result['selections'])) {
			throw new GeminiResponseException('Gemini returned malformed catalog selections.');
		}

		$expectedIndexes = [];
		foreach ($selectionItems as $item) {
			if (!is_array($item) || !is_int($item['detection_index'] ?? null) || !is_array($item['candidates'] ?? null)) {
				throw new GeminiResponseException('Catalog selection request was malformed.');
			}
			$expectedIndexes[$item['detection_index']] = true;
		}

		$selections = [];
		foreach ($result['selections'] as $selection) {
			if (!is_array($selection)
				|| count($selection) !== 3
				|| !array_key_exists('detection_index', $selection)
				|| !array_key_exists('catalog_food_id', $selection)
				|| !array_key_exists('confidence', $selection)
				|| !is_int($selection['detection_index'])
				|| !array_key_exists($selection['detection_index'], $expectedIndexes)
				|| array_key_exists($selection['detection_index'], $selections)
				|| ($selection['catalog_food_id'] !== null && (!is_int($selection['catalog_food_id']) || $selection['catalog_food_id'] < 1))
				|| !is_numeric($selection['confidence'])
				|| !is_finite((float) $selection['confidence'])
				|| (float) $selection['confidence'] < 0
				|| (float) $selection['confidence'] > 1) {
				throw new GeminiResponseException('Gemini returned invalid catalog selection fields.');
			}

			$selections[$selection['detection_index']] = [
				'catalog_food_id' => $selection['catalog_food_id'],
				'confidence' => round((float) $selection['confidence'], 4),
			];
		}

		if (count($selections) !== count($expectedIndexes)) {
			throw new GeminiResponseException('Gemini returned incomplete catalog selections.');
		}

		return $selections;
	}

	private static function foodScanText(string $value, int $maxLength = 500): string
	{
		$value = trim(preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '');
		if ($value === '' || strlen($value) > $maxLength) {
			throw new GeminiResponseException('Gemini returned invalid food detection text.');
		}

		return $value;
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