<?php

declare(strict_types=1);

if (!function_exists('response_error')) {
	require_once dirname(__DIR__, 3) . '/bootstrap.php';
}
require_once dirname(__DIR__, 3) . '/core/auth.php';
require_once dirname(__DIR__, 3) . '/services/EntitlementService.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
	response_error('METHOD_NOT_ALLOWED', 'Method not allowed.', 405);
}

$user = authenticated_user();
$database = database_connection();
EntitlementService::requireFeature($database, (int) $user['id'], 'ai_food_recommendations');

try {
	$statement = $database->prepare(
		'SELECT
			 SUM(status = :total_success_status) AS total_requests,
			 SUM(status = :success_status) AS successful_requests,
			 SUM(status = :failed_status) AS failed_requests,
			 SUM(status = :period_request_success_status AND requested_at >= DATE_FORMAT(UTC_TIMESTAMP(), \'%Y-%m-01 00:00:00\')) AS current_period_requests,
			 SUM(status = :period_success_status AND requested_at >= DATE_FORMAT(UTC_TIMESTAMP(), \'%Y-%m-01 00:00:00\')) AS current_period_successful_requests,
			 SUM(status = :period_failed_status AND requested_at >= DATE_FORMAT(UTC_TIMESTAMP(), \'%Y-%m-01 00:00:00\')) AS current_period_failed_requests
		 FROM ai_usage_logs
		 WHERE user_id = :user_id AND feature_type = :feature_type'
	);
	$statement->execute([
		'success_status' => 'success',
		'total_success_status' => 'success',
		'failed_status' => 'failed',
		'period_request_success_status' => 'success',
		'period_success_status' => 'success',
		'period_failed_status' => 'failed',
		'user_id' => (int) $user['id'],
		'feature_type' => 'food_recommendations',
	]);
	$usage = $statement->fetch() ?: [];
} catch (Throwable $exception) {
	error_log(sprintf('[%s] AI usage lookup failed: %s', request_id(), $exception->getMessage()));
	response_error('INTERNAL_ERROR', 'AI usage could not be retrieved.', 500);
}

foreach (['total_requests', 'successful_requests', 'failed_requests', 'current_period_requests', 'current_period_successful_requests', 'current_period_failed_requests'] as $field) {
	$usage[$field] = (int) ($usage[$field] ?? 0);
}

response_success(['usage' => $usage], 'AI usage retrieved.');