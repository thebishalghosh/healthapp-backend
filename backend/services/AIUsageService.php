<?php

declare(strict_types=1);

final class AIUsageService
{
	public static function record(
		PDO $database,
		int $userId,
		string $provider,
		string $model,
		string $status,
		int $httpStatus,
		int $responseTimeMs,
		?string $errorMessage = null
	): void {
		$statement = $database->prepare(
			'INSERT INTO ai_usage_logs
			 (user_id, feature_type, ai_provider, ai_model, request_id, status, requested_at, http_status, response_time_ms, error_message)
			 VALUES (:user_id, :feature_type, :ai_provider, :ai_model, :request_id, :status, UTC_TIMESTAMP(), :http_status, :response_time_ms, :error_message)'
		);
		$statement->execute([
			'user_id' => $userId,
			'feature_type' => 'food_recommendations',
			'ai_provider' => $provider,
			'ai_model' => $model,
			'request_id' => request_id(),
			'status' => $status,
			'http_status' => $httpStatus,
			'response_time_ms' => $responseTimeMs,
			'error_message' => $errorMessage === null ? null : substr($errorMessage, 0, 2000),
		]);
	}
}