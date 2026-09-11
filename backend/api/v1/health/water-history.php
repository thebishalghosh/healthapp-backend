<?php

declare(strict_types=1);

if (!function_exists('response_error')) {
	require_once dirname(__DIR__, 3) . '/bootstrap.php';
}
require_once dirname(__DIR__, 3) . '/core/auth.php';
require_once dirname(__DIR__, 3) . '/services/HealthTrackingService.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
	response_error('METHOD_NOT_ALLOWED', 'Method not allowed.', 405);
}

$user = authenticated_user();
$userId = (int) $user['id'];
$database = database_connection();
$date = $_GET['date'] ?? HealthTrackingService::now()->format('Y-m-d');
if (!HealthTrackingService::validDate($date)) {
	response_validation_error(['date' => 'Date must be a valid YYYY-MM-DD date.']);
}

$timezone = $_GET['timezone'] ?? HealthTrackingService::TIMEZONE;
if (!HealthTrackingService::validTimezone($timezone)) {
	response_validation_error(['timezone' => 'Timezone must be a valid IANA timezone identifier.']);
}
[$start, $end] = HealthTrackingService::utcDateBounds($date, $timezone);

$nutrition = HealthTrackingService::nutritionOrError($database, $userId);
$entriesStatement = $database->prepare(
	'SELECT id, amount_ml, consumed_at, source, created_at
	 FROM water_logs
	 WHERE user_id = :user_id AND consumed_at >= :start AND consumed_at < :end
	 ORDER BY consumed_at DESC, id DESC'
);
$entriesStatement->execute(['user_id' => $userId, 'start' => $start, 'end' => $end]);
$entries = [];
while ($entry = $entriesStatement->fetch()) {
	$entry['id'] = (int) $entry['id'];
	$entry['amount_ml'] = (float) $entry['amount_ml'];
	$entries[] = $entry;
}

$summaryStatement = $database->prepare('SELECT COALESCE(SUM(amount_ml), 0) AS consumed_ml FROM water_logs WHERE user_id = :user_id AND consumed_at >= :start AND consumed_at < :end');
$summaryStatement->execute(['user_id' => $userId, 'start' => $start, 'end' => $end]);
$consumed = (float) $summaryStatement->fetch()['consumed_ml'];
$target = (float) $nutrition['water_ml'];
$percentage = $target > 0 ? min(100, ($consumed / $target) * 100) : 0;

response_success([
	'water' => [
		'date' => $date,
		'entries' => $entries,
		'consumed_ml' => round($consumed, 2),
		'target_ml' => round($target, 2),
		'remaining_ml' => round(max(0, $target - $consumed), 2),
		'percentage' => round($percentage, 2),
	],
], 'Water history retrieved.');