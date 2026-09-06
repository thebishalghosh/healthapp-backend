<?php

declare(strict_types=1);

if (!function_exists('response_error')) {
	require_once dirname(__DIR__, 3) . '/bootstrap.php';
}
require_once dirname(__DIR__, 3) . '/core/auth.php';
require_once dirname(__DIR__, 3) . '/services/FoodRecommendationHistoryService.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
	response_error('METHOD_NOT_ALLOWED', 'Method not allowed.', 405);
}

$user = authenticated_user();
$limit = filter_var($_GET['limit'] ?? 20, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
$offset = filter_var($_GET['offset'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
if ($limit === false || $offset === false) {
	response_validation_error(['limit' => 'Limit must be between 1 and 100.', 'offset' => 'Offset must be zero or greater.']);
}

try {
	$history = FoodRecommendationHistoryService::history(database_connection(), (int) $user['id'], $limit, $offset);
} catch (Throwable $exception) {
	error_log(sprintf('[%s] AI recommendation history failed: %s', request_id(), $exception->getMessage()));
	response_error('INTERNAL_ERROR', 'Recommendation history could not be retrieved.', 500);
}

response_success([
	'history' => $history,
	'limit' => $limit,
	'offset' => $offset,
], 'Food recommendation history retrieved.');