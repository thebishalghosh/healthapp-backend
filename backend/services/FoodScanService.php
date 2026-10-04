<?php

declare(strict_types=1);

if (!function_exists('response_error')) {
	require_once dirname(__DIR__) . '/bootstrap.php';
}
require_once __DIR__ . '/GeminiService.php';
require_once __DIR__ . '/FoodNutritionLookupService.php';

final class FoodScanService
{
	private const MAX_IMAGE_BYTES = 10 * 1024 * 1024;
	private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
	private const MIN_CATALOG_SELECTION_CONFIDENCE = 0.8;

	public static function detectFromUploadedFile(mixed $upload): array
	{
		[$imageData, $mimeType] = self::readUploadedImage($upload);

		return GeminiService::detectFoodsFromImage($imageData, $mimeType);
	}

	public static function scanUploadedFile(PDO $database, mixed $upload): array
	{
		[$imageData, $mimeType] = self::readUploadedImage($upload);
		$foodDetection = GeminiService::detectFoodsFromImage($imageData, $mimeType);

		return self::enrichDetections($database, $foodDetection, $imageData, $mimeType);
	}

	private static function readUploadedImage(mixed $upload): array
	{
		if (!is_array($upload)) {
			response_validation_error(['image' => 'Upload an image file.']);
		}

		$uploadError = $upload['error'] ?? UPLOAD_ERR_NO_FILE;
		if ($uploadError === UPLOAD_ERR_NO_FILE) {
			response_validation_error(['image' => 'Upload an image file.']);
		}
		if (in_array($uploadError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
			response_error('PAYLOAD_TOO_LARGE', 'Image must be 10 MiB or smaller.', 413);
		}
		if ($uploadError !== UPLOAD_ERR_OK || !is_string($upload['tmp_name'] ?? null) || !is_uploaded_file($upload['tmp_name'])) {
			response_validation_error(['image' => 'The uploaded image is invalid.']);
		}

		$imageSize = filesize($upload['tmp_name']);
		if (!is_int($imageSize) || $imageSize < 1) {
			response_validation_error(['image' => 'The uploaded image is empty or invalid.']);
		}
		if ($imageSize > self::MAX_IMAGE_BYTES) {
			response_error('PAYLOAD_TOO_LARGE', 'Image must be 10 MiB or smaller.', 413);
		}

		$fileInfo = finfo_open(FILEINFO_MIME_TYPE);
		if ($fileInfo === false) {
			response_error('INTERNAL_ERROR', 'The uploaded image could not be validated.', 500);
		}
		$mimeType = finfo_file($fileInfo, $upload['tmp_name']);
		finfo_close($fileInfo);
		if (!is_string($mimeType) || !in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
			response_validation_error(['image' => 'Image must be JPEG, PNG, or WebP.']);
		}

		try {
			$imageInfo = getimagesize($upload['tmp_name']);
		} catch (Throwable) {
			$imageInfo = false;
		}
		if (!is_array($imageInfo) || ($imageInfo['mime'] ?? null) !== $mimeType) {
			response_validation_error(['image' => 'The uploaded file is not a valid JPEG, PNG, or WebP image.']);
		}

		$imageData = file_get_contents($upload['tmp_name']);
		if (!is_string($imageData)) {
			response_validation_error(['image' => 'The uploaded image could not be read.']);
		}

		return [$imageData, $mimeType];
	}

	public static function enrichDetections(
		PDO $database,
		array $foodDetection,
		?string $imageData = null,
		?string $imageMimeType = null,
		?callable $catalogSelector = null
	): array
	{
		if (!is_array($foodDetection['foods'] ?? null) || !is_numeric($foodDetection['overall_confidence'] ?? null) || !is_array($foodDetection['notes'] ?? null)) {
			throw new GeminiResponseException('Gemini food detection response has an invalid structure.');
		}

		$overallConfidence = (float) $foodDetection['overall_confidence'];
		if (!is_finite($overallConfidence) || $overallConfidence < 0 || $overallConfidence > 1) {
			throw new GeminiResponseException('Gemini food detection response has invalid overall confidence.');
		}

		$notes = [];
		foreach ($foodDetection['notes'] as $note) {
			if (!is_string($note)) {
				throw new GeminiResponseException('Gemini food detection response has invalid notes.');
			}
			$notes[] = $note;
		}

		$detections = [];
		$selectionRequests = [];
		$selectionInputs = [];
		$matchedCount = 0;
		$unresolvedCount = 0;
		foreach ($foodDetection['foods'] as $index => $food) {
			if (!is_array($food) || !is_string($food['name'] ?? null) || !is_string($food['quantity_unit'] ?? null) || !is_numeric($food['confidence'] ?? null)) {
				throw new GeminiResponseException('Gemini food detection response contains an invalid food item.');
			}

			$candidates = FoodNutritionLookupService::findCandidates($database, $food['name']);
			if (count($candidates) > 1) {
				$preflight = FoodNutritionLookupService::resolveCatalogCandidate(
					$database,
					$food['name'],
					$food['quantity_g'] ?? null,
					$food['quantity_unit'],
					$food['confidence'],
					$candidates[0]['food_id'],
					$candidates
				);
				if ($preflight['status'] === 'unresolved') {
					$detections[$index] = self::formatDetection($food, $preflight);
					self::logUnresolvedReason((string) ($preflight['reason'] ?? 'unknown'));
					$unresolvedCount++;
					continue;
				}

				$selectionRequests[] = [
					'detection_index' => $index,
					'detected_food' => $food['name'],
					'quantity' => ['value' => $food['quantity_g'] ?? null, 'unit' => $food['quantity_unit']],
					'candidates' => $candidates,
				];
				$selectionInputs[$index] = ['food' => $food, 'candidates' => $candidates];
				continue;
			}

			$lookup = count($candidates) === 1
				? FoodNutritionLookupService::resolveCatalogCandidate(
					$database,
					$food['name'],
					$food['quantity_g'] ?? null,
					$food['quantity_unit'],
					$food['confidence'],
					$candidates[0]['food_id'],
					$candidates
				)
				: FoodNutritionLookupService::resolve(
					$database,
					$food['name'],
					$food['quantity_g'] ?? null,
					$food['quantity_unit'],
					$food['confidence']
				);

			$detections[$index] = self::formatDetection($food, $lookup);
			if ($lookup['status'] === 'matched') {
				$matchedCount++;
			} else {
				self::logUnresolvedReason((string) ($lookup['reason'] ?? 'unknown'));
				$unresolvedCount++;
			}
		}

		if ($selectionRequests !== []) {
			$selections = $catalogSelector !== null
				? $catalogSelector($selectionRequests, $imageData, $imageMimeType)
				: GeminiService::selectCatalogCandidates($selectionRequests, $imageData, $imageMimeType);
			if (!is_array($selections)) {
				throw new GeminiResponseException('Catalog candidate selection returned an invalid result.');
			}

			foreach ($selectionInputs as $index => $selectionInput) {
				$food = $selectionInput['food'];
				$candidates = $selectionInput['candidates'];
				$selection = $selections[$index] ?? null;
				$selectedFoodId = is_array($selection) ? ($selection['catalog_food_id'] ?? null) : null;
				$selectionConfidence = is_array($selection) ? ($selection['confidence'] ?? null) : null;

				if (!is_numeric($selectionConfidence)
					|| !is_finite((float) $selectionConfidence)
					|| (float) $selectionConfidence < 0
					|| (float) $selectionConfidence > 1) {
					$lookup = FoodNutritionLookupService::unresolvedDetection(
						'invalid_catalog_selection',
						$food['name'],
						$food['quantity_g'] ?? null,
						$food['quantity_unit'],
						$food['confidence']
					);
				} elseif ($selectedFoodId !== null && !is_int($selectedFoodId)) {
					$lookup = FoodNutritionLookupService::unresolvedDetection(
						'invalid_catalog_selection',
						$food['name'],
						$food['quantity_g'] ?? null,
						$food['quantity_unit'],
						$selectionConfidence
					);
				} else {
					$lookup = FoodNutritionLookupService::resolveCatalogCandidate(
						$database,
						$food['name'],
						$food['quantity_g'] ?? null,
						$food['quantity_unit'],
						$selectionConfidence,
						$selectedFoodId,
						$candidates
					);
					if ((float) $selectionConfidence < self::MIN_CATALOG_SELECTION_CONFIDENCE
						&& !in_array($lookup['reason'] ?? null, ['invalid_catalog_selection', 'inactive_catalog_food'], true)) {
						$lookup = FoodNutritionLookupService::unresolvedDetection(
							'low_confidence_catalog_selection',
							$food['name'],
							$food['quantity_g'] ?? null,
							$food['quantity_unit'],
							$selectionConfidence
						);
					}
				}

				$detections[$index] = self::formatDetection($food, $lookup);
				if ($lookup['status'] === 'matched') {
					$matchedCount++;
				} else {
					self::logUnresolvedReason((string) ($lookup['reason'] ?? 'unknown'));
					$unresolvedCount++;
				}
			}
		}

		ksort($detections);

		return [
			'detections' => array_values($detections),
			'summary' => ['matched_count' => $matchedCount, 'unresolved_count' => $unresolvedCount],
			'overall_confidence' => round($overallConfidence, 4),
			'notes' => $notes,
		];
	}

	private static function formatDetection(array $food, array $lookup): array
	{
		$detection = [
			'status' => $lookup['status'],
			'detected_food' => $food['name'],
			'quantity' => $lookup['quantity'],
		];
		if (isset($lookup['confidence'])) {
			$detection['confidence'] = $lookup['confidence'];
		}

		if ($lookup['status'] === 'matched') {
			$detection['food'] = $lookup['food'];
			$detection['nutrition'] = $lookup['nutrition'];
		} else {
			$detection['reason'] = self::unresolvedReason((string) ($lookup['reason'] ?? 'food_not_found'));
		}

		return $detection;
	}

	private static function logUnresolvedReason(string $reason): void
	{
		if (PHP_SAPI !== 'cli' && function_exists('request_id')) {
			error_log(sprintf('[%s] Food scan item unresolved (%s).', request_id(), $reason));
		}
	}

	private static function unresolvedReason(string $reason): string
	{
		return match ($reason) {
			'food_not_found', 'ambiguous_catalog_name' => 'No safe catalog match for detected food',
			'ambiguous_catalog_selection' => 'Catalog candidates could not be distinguished safely',
			'low_confidence_catalog_selection' => 'Catalog selection confidence is below the required threshold',
			'invalid_catalog_selection' => 'Catalog selection was not in the supplied candidate list',
			'inactive_catalog_food' => 'Selected catalog food is no longer active',
			'volume_unit_not_supported' => 'Millilitre quantities are not supported by the current gram-based catalog',
			'unknown_quantity_unit' => 'Quantity unit is unknown',
			'unsupported_quantity_unit' => 'Quantity unit is not supported',
			'invalid_quantity' => 'Quantity must be a positive number',
			'missing_food_name' => 'Detected food name is missing',
			'invalid_food_name' => 'Detected food name is invalid',
			'invalid_confidence' => 'Detection confidence is invalid',
			'unsupported_catalog_basis' => 'Catalog food does not use the supported 100 g basis',
			'incomplete_catalog_nutrition' => 'Catalog nutrition is incomplete',
			default => 'Food could not be safely resolved',
		};
	}
}