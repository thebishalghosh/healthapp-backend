<?php

declare(strict_types=1);

if (!function_exists('response_error')) {
	require_once dirname(__DIR__, 3) . '/bootstrap.php';
}
require_once dirname(__DIR__, 3) . '/core/auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
	response_error('METHOD_NOT_ALLOWED', 'Method not allowed.', 405);
}

$user = authenticated_user();
$body = auth_json_body();
$platform = $body['platform'] ?? null;
$deviceToken = is_string($body['device_token'] ?? null) ? trim($body['device_token']) : '';
$fields = [];
if (!in_array($platform, ['android', 'ios', 'web', 'other'], true)) {
	$fields['platform'] = 'Platform must be android, ios, web, or other.';
}
if ($deviceToken === '' || strlen($deviceToken) > 500) {
	$fields['device_token'] = 'Device token must be 1 to 500 characters.';
}
foreach (['device_id' => 191, 'app_version' => 50] as $field => $maxLength) {
	if (array_key_exists($field, $body) && $body[$field] !== null && (!is_string($body[$field]) || strlen($body[$field]) > $maxLength)) {
		$fields[$field] = ucfirst(str_replace('_', ' ', $field)) . " must be at most {$maxLength} characters.";
	}
}
if ($fields !== []) {
	response_validation_error($fields);
}

try {
	$database = database_connection();
	$statement = $database->prepare(
		'INSERT INTO user_devices (user_id, device_token, platform, device_id, app_version, is_active, last_seen_at)
		 VALUES (:user_id, :device_token, :platform, :device_id, :app_version, 1, UTC_TIMESTAMP())
		 ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), platform = VALUES(platform), device_id = VALUES(device_id), app_version = VALUES(app_version), is_active = 1, last_seen_at = UTC_TIMESTAMP()'
	);
	$statement->execute([
		'user_id' => (int) $user['id'],
		'device_token' => $deviceToken,
		'platform' => $platform,
		'device_id' => $body['device_id'] ?? null,
		'app_version' => $body['app_version'] ?? null,
	]);
	response_success(['device' => ['platform' => $platform, 'registered' => true]], 'Device registered.');
} catch (Throwable $exception) {
	error_log(sprintf('[%s] Device registration failed: %s', request_id(), $exception->getMessage()));
	response_error('INTERNAL_ERROR', 'Device could not be registered.', 500);
}
