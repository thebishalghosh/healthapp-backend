<?php

declare(strict_types=1);

if (!function_exists('response_error')) {
	require_once dirname(__DIR__, 3) . '/bootstrap.php';
}
require_once dirname(__DIR__, 3) . '/core/auth.php';
require_once dirname(__DIR__, 3) . '/services/HealthTrackingService.php';

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'POST'], true)) {
	response_error('METHOD_NOT_ALLOWED', 'Method not allowed.', 405);
}

$user = authenticated_user();
$userId = (int) $user['id'];
$database = database_connection();
[$today, $tomorrow] = HealthTrackingService::todayBounds();
$start = $today->format('Y-m-d H:i:s');
$end = $tomorrow->format('Y-m-d H:i:s');

if ($method === 'POST') {
	$body = auth_json_body();
	$fields = [];
	if (!HealthTrackingService::validNumber($body['amount_ml'] ?? null, 0.01, 10000)) {
		$fields['amount_ml'] = 'Amount must be greater than 0 and at most 10000 ml.';
	}
	if ($fields !== []) {
		response_validation_error($fields);
	}

	$statement = $database->prepare('INSERT INTO water_logs (user_id, amount_ml, consumed_at) VALUES (:user_id, :amount_ml, :consumed_at)');
	$statement->execute([
		'user_id' => $userId,
		'amount_ml' => (float) $body['amount_ml'],
		'consumed_at' => HealthTrackingService::now()->format('Y-m-d H:i:s'),
	]);

	response_success([
		'water' => [
			'id' => (int) $database->lastInsertId(),
			'amount_ml' => (float) $body['amount_ml'],
		],
	], 'Water logged.', 201);
}

$nutrition = HealthTrackingService::nutritionOrError($database, $userId);
$statement = $database->prepare('SELECT COALESCE(SUM(amount_ml), 0) AS consumed_ml FROM water_logs WHERE user_id = :user_id AND consumed_at >= :start AND consumed_at < :end');
$statement->execute(['user_id' => $userId, 'start' => $start, 'end' => $end]);
$consumed = (float) $statement->fetch()['consumed_ml'];
$target = (float) $nutrition['water_ml'];
$percentage = $target > 0 ? min(100, ($consumed / $target) * 100) : 0;

response_success([
	'water' => [
		'consumed_ml' => round($consumed, 2),
		'target_ml' => round($target, 2),
		'remaining_ml' => round(max(0, $target - $consumed), 2),
		'percentage' => round($percentage, 2),
	],
], 'Water summary retrieved.');
