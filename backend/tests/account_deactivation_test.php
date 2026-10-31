<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
	fwrite(STDERR, "Run this test from the command line.\n");
	exit(1);
}

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/core/auth.php';
require_once dirname(__DIR__) . '/services/AccountDeletionService.php';

set_exception_handler(static function (Throwable $exception): never {
	fwrite(STDERR, $exception->getMessage() . PHP_EOL . $exception->getTraceAsString() . PHP_EOL);
	exit(1);
});

final class AccountDeactivationFailingPDO extends PDO
{
	public function __construct()
	{
		$dsn = sprintf(
			'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
			app_config('DB_HOST', '127.0.0.1'),
			app_config('DB_PORT', '3306'),
			app_config('DB_DATABASE', '')
		);
		parent::__construct($dsn, (string) app_config('DB_USERNAME', ''), (string) app_config('DB_PASSWORD', ''), [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::ATTR_EMULATE_PREPARES => false,
		]);
	}

	public function prepare(string $query, array $options = []): PDOStatement|false
	{
		if (str_starts_with(ltrim($query), 'DELETE FROM user_sessions')) {
			throw new PDOException('Simulated test database failure.');
		}

		return parent::prepare($query, $options);
	}
}

$database = database_connection();
$assertions = 0;
$userIds = [];
$serverProcess = null;
$serverLog = tempnam(sys_get_temp_dir(), 'account-deactivation-test-');
$assert = static function (bool $condition, string $message) use (&$assertions): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
	$assertions++;
};
$testUser = static function (PDO $database, array &$userIds, string $password): array {
	$email = 'account-deactivate-test-' . bin2hex(random_bytes(8)) . '@example.invalid';
	$statement = $database->prepare('INSERT INTO users (uuid, email, password_hash, status) VALUES (?, ?, ?, ?)');
	$statement->execute([auth_uuid(), $email, password_hash($password, PASSWORD_DEFAULT), 'active']);
	$userId = (int) $database->lastInsertId();
	$userIds[] = $userId;

	return [$userId, $email];
};
$sendRequest = static function (int $port, string $method, string $path, ?array $body = null, ?string $token = null) use ($serverLog): array {
	$headers = ['Accept: application/json'];
	$content = '';
	if ($body !== null) {
		$headers[] = 'Content-Type: application/json';
		$content = json_encode($body, JSON_THROW_ON_ERROR);
	}
	if ($token !== null) {
		$headers[] = 'Authorization: Bearer ' . $token;
	}
	$connection = stream_socket_client('tcp://127.0.0.1:' . $port, $errorCode, $errorMessage, 8);
	if (!is_resource($connection)) {
		throw new RuntimeException('Unable to connect to the local API test server: ' . $errorMessage);
	}
	$headers[] = 'Host: 127.0.0.1:' . $port;
	$headers[] = 'Connection: close';
	$headers[] = 'Content-Length: ' . strlen($content);
	fwrite($connection, $method . ' ' . $path . " HTTP/1.1\r\n" . implode("\r\n", $headers) . "\r\n\r\n" . $content);
	$response = stream_get_contents($connection);
	fclose($connection);
	if (!is_string($response) || !str_contains($response, "\r\n\r\n")) {
		throw new RuntimeException('The local API test server did not return a valid response.');
	}
	[$statusLine, $responseBody] = explode("\r\n\r\n", $response, 2);
	$statusParts = explode(' ', $statusLine);
	$status = isset($statusParts[1]) ? (int) $statusParts[1] : 0;
	$decoded = json_decode($responseBody, true);
	if (!is_array($decoded)) {
		throw new RuntimeException('The local API returned invalid JSON (HTTP ' . $status . '): ' . trim((string) file_get_contents($serverLog)));
	}

	return [$status, $decoded];
};
$profileSnapshot = static function (PDO $database, int $userId): array {
	$statement = $database->prepare('SELECT first_name, last_name, profile_image FROM user_profiles WHERE user_id = ?');
	$statement->execute([$userId]);

	return $statement->fetch(PDO::FETCH_ASSOC) ?: [];
};
$waterSnapshot = static function (PDO $database, int $userId): array {
	$statement = $database->prepare('SELECT amount_ml, consumed_at FROM water_logs WHERE user_id = ? ORDER BY id');
	$statement->execute([$userId]);

	return $statement->fetchAll(PDO::FETCH_ASSOC);
};
$subscriptionSnapshot = static function (PDO $database, int $userId): array {
	$statement = $database->prepare(
		'SELECT id, plan_id, provider, provider_subscription_id, status, auto_renew
		 FROM subscriptions WHERE user_id = ? ORDER BY id'
	);
	$statement->execute([$userId]);

	return $statement->fetchAll(PDO::FETCH_ASSOC);
};
$paymentSnapshot = static function (PDO $database, int $userId): array {
	$statement = $database->prepare(
		'SELECT subscription_id, provider, provider_payment_id, provider_order_id,
		        razorpay_subscription_id, amount, currency, status, payment_method, metadata
		 FROM payments WHERE user_id = ? ORDER BY id'
	);
	$statement->execute([$userId]);

	return $statement->fetchAll(PDO::FETCH_ASSOC);
};

try {
	if (!is_string($serverLog)) {
		throw new RuntimeException('Unable to allocate the local API test server log.');
	}
	$socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
	if (!is_resource($socket)) {
		throw new RuntimeException('Unable to reserve a local API test port: ' . $errorMessage);
	}
	$socketName = stream_socket_get_name($socket, false);
	fclose($socket);
	if (!is_string($socketName) || !str_contains($socketName, ':')) {
		throw new RuntimeException('Unable to determine the local API test port.');
	}
	$port = (int) substr($socketName, strrpos($socketName, ':') + 1);
	$serverProcess = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', dirname(__DIR__)], [
		0 => ['file', 'NUL', 'r'],
		1 => ['file', $serverLog, 'a'],
		2 => ['file', $serverLog, 'a'],
	], $serverPipes, dirname(__DIR__));
	if (!is_resource($serverProcess)) {
		throw new RuntimeException('Unable to start the local API test server.');
	}

	$serverReady = false;
	for ($attempt = 0; $attempt < 50; $attempt++) {
		try {
			$connection = fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, 0.1);
		} catch (ErrorException) {
			$connection = false;
		}
		if (is_resource($connection)) {
			fclose($connection);
			$serverReady = true;
			break;
		}
		usleep(100_000);
	}
	if (!$serverReady) {
		throw new RuntimeException('The local API test server did not start.');
	}

	$password = 'Deactivate-account-123';
	[$userId, $email] = $testUser($database, $userIds, $password);
	[$rollbackUserId] = $testUser($database, $userIds, $password);
	[$token] = auth_create_session($database, $userId);
	[$secondToken] = auth_create_session($database, $userId);
	[$rollbackToken] = auth_create_session($database, $rollbackUserId);
	$planId = $database->query("SELECT id FROM subscription_plans WHERE code = 'FREE' LIMIT 1")->fetchColumn();
	if ($planId === false) {
		throw new RuntimeException('The FREE plan is unavailable for this test.');
	}

	$profile = $database->prepare('INSERT INTO user_profiles (user_id, first_name, last_name) VALUES (?, ?, ?)');
	$profile->execute([$userId, 'Test', 'Deactivation']);
	$water = $database->prepare('INSERT INTO water_logs (user_id, amount_ml, consumed_at) VALUES (?, ?, UTC_TIMESTAMP())');
	$water->execute([$userId, 250]);
	$subscription = $database->prepare(
		"INSERT INTO subscriptions (user_id, plan_id, provider, status, auto_renew)
		 VALUES (?, ?, 'test-only', 'active', TRUE)"
	);
	$subscription->execute([$userId, $planId]);
	$subscriptionId = (int) $database->lastInsertId();
	$providerPaymentId = 'account-deactivation-test-' . bin2hex(random_bytes(12));
	$payment = $database->prepare(
		'INSERT INTO payments
		 (user_id, subscription_id, provider, provider_payment_id, provider_order_id,
		  razorpay_subscription_id, amount, currency, status, payment_method, metadata)
		 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
	);
	$payment->execute([
		$userId,
		$subscriptionId,
		'test-only',
		$providerPaymentId,
		'test-order-reference',
		'test-subscription-reference',
		19.99,
		'INR',
		'success',
		'test-method',
		json_encode(['invoice_id' => 'test-invoice-reference'], JSON_THROW_ON_ERROR),
	]);

	$profileBefore = $profileSnapshot($database, $userId);
	$waterBefore = $waterSnapshot($database, $userId);
	$subscriptionsBefore = $subscriptionSnapshot($database, $userId);
	$paymentsBefore = $paymentSnapshot($database, $userId);
	$deactivateBody = [
		'current_password' => $password,
		'confirmation' => AccountDeactivationService::CONFIRMATION,
	];

	[$status, $response] = $sendRequest($port, 'DELETE', '/api/v1/account.php', [
		'current_password' => 'incorrect-password',
		'confirmation' => AccountDeactivationService::CONFIRMATION,
	], $token);
	$assert($status === 400 && ($response['error']['code'] ?? null) === 'INVALID_CURRENT_PASSWORD', 'Incorrect password must be rejected.');
	[$status, $response] = $sendRequest($port, 'DELETE', '/api/v1/account.php', [
		'current_password' => $password,
		'confirmation' => 'DELETE MY ACCOUNT',
	], $token);
	$assert($status === 422 && isset($response['error']['fields']['confirmation']), 'Incorrect confirmation phrase must be rejected.');
	$account = $database->prepare('SELECT status, deleted_at FROM users WHERE id = ?');
	$account->execute([$userId]);
	$accountBeforeSuccess = $account->fetch(PDO::FETCH_ASSOC);
	$assert($accountBeforeSuccess['status'] === 'active' && $accountBeforeSuccess['deleted_at'] === null, 'Rejected requests must not change account status.');

	[$status, $response] = $sendRequest($port, 'DELETE', '/api/v1/account.php', $deactivateBody, $token);
	$assert($status === 200 && ($response['success'] ?? false) === true, 'Valid deactivation must succeed (HTTP ' . $status . ').');
	$assert(($response['data']['account_deactivated'] ?? false) === true
		&& ($response['data']['sessions_invalidated'] ?? false) === true
		&& ($response['data']['subscription_billing_cancelled'] ?? true) === false
		&& ($response['data']['subscription_billing_may_continue'] ?? false) === true, 'Success must accurately report deactivation, session invalidation, and unchanged billing.');

	$account->execute([$userId]);
	$deactivated = $account->fetch(PDO::FETCH_ASSOC);
	$assert($deactivated['status'] === 'inactive' && $deactivated['deleted_at'] === null, 'Deactivation must use the existing inactive status without marking the account deleted.');
	$sessionCount = $database->prepare('SELECT COUNT(*) FROM user_sessions WHERE user_id = ?');
	$sessionCount->execute([$userId]);
	$assert((int) $sessionCount->fetchColumn() === 0, 'All sessions for the deactivated account must be invalidated.');
	$assert($profileSnapshot($database, $userId) === $profileBefore, 'User profile data must remain unchanged.');
	$assert($waterSnapshot($database, $userId) === $waterBefore, 'Health records must remain unchanged.');
	$assert($subscriptionSnapshot($database, $userId) === $subscriptionsBefore, 'Subscription records and auto-renew settings must remain unchanged.');
	$assert($paymentSnapshot($database, $userId) === $paymentsBefore, 'Payment and accounting records must remain unchanged.');

	foreach ([$token, $secondToken] as $existingToken) {
		[$status, $response] = $sendRequest($port, 'GET', '/api/v1/health/water.php', null, $existingToken);
		$assert($status === 401 && ($response['error']['code'] ?? null) === 'UNAUTHENTICATED', 'Existing tokens must not access protected health APIs after deactivation.');
	}
	[$status, $response] = $sendRequest($port, 'GET', '/api/v1/auth/me.php', null, $token);
	$assert($status === 401 && ($response['error']['code'] ?? null) === 'UNAUTHENTICATED', 'Existing tokens must not access authenticated account APIs.');
	[$status, $response] = $sendRequest($port, 'POST', '/api/v1/auth/login.php', [
		'email' => $email,
		'password' => $password,
	]);
	$assert($status === 401 && ($response['error']['code'] ?? null) === 'INVALID_CREDENTIALS', 'Login must reject a deactivated account.');
	[$status, $response] = $sendRequest($port, 'DELETE', '/api/v1/account.php', $deactivateBody, $token);
	$assert($status === 401 && ($response['error']['code'] ?? null) === 'UNAUTHENTICATED', 'Repeated deactivation with an invalidated token must be safely rejected.');
	fwrite(STDOUT, "PASS deactivation, authentication revocation, and personal/financial data preservation\n");

	$failingDatabase = new AccountDeactivationFailingPDO();
	try {
		AccountDeactivationService::deactivate($failingDatabase, $rollbackUserId, $password);
		throw new RuntimeException('Simulated session invalidation failure did not fail deactivation.');
	} catch (PDOException $exception) {
		$assert($exception->getMessage() === 'Simulated test database failure.', 'The simulated database failure must be propagated safely.');
	}
	$account->execute([$rollbackUserId]);
	$rollbackAccount = $account->fetch(PDO::FETCH_ASSOC);
	$assert($rollbackAccount['status'] === 'active' && $rollbackAccount['deleted_at'] === null, 'Database failure must roll back the account status change.');
	$sessionCount->execute([$rollbackUserId]);
	$assert((int) $sessionCount->fetchColumn() === 1, 'Database failure must preserve the account session.');
	[$status, $response] = $sendRequest($port, 'GET', '/api/v1/auth/me.php', null, $rollbackToken);
	$assert($status === 200 && ($response['success'] ?? false) === true, 'The token must remain usable after the failed transaction rolls back.');
	fwrite(STDOUT, "PASS transactional rollback on session invalidation failure\n");
} finally {
	if ($database instanceof PDO && $userIds !== []) {
		$placeholders = implode(',', array_fill(0, count($userIds), '?'));
		foreach (['user_sessions', 'payments', 'subscriptions', 'water_logs', 'user_profiles'] as $table) {
			$cleanup = $database->prepare('DELETE FROM ' . $table . ' WHERE user_id IN (' . $placeholders . ')');
			$cleanup->execute($userIds);
		}
		$cleanup = $database->prepare('DELETE FROM users WHERE id IN (' . $placeholders . ')');
		$cleanup->execute($userIds);
	}
	if (is_resource($serverProcess)) {
		proc_terminate($serverProcess);
		proc_close($serverProcess);
	}
	if (is_string($serverLog) && is_file($serverLog)) {
		unlink($serverLog);
	}
}

fwrite(STDOUT, 'OK ' . $assertions . " assertions\n");
