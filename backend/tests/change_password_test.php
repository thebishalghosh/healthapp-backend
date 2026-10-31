<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
	fwrite(STDERR, "Run this test from the command line.\n");
	exit(1);
}

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/core/auth.php';
require_once dirname(__DIR__) . '/services/ChangePasswordService.php';

if (($argv[1] ?? null) === '--authenticate') {
	$_SERVER['HTTP_AUTHORIZATION'] = stream_get_contents(STDIN) ?: '';
	$user = authenticated_user();
	respond(['success' => true, 'user_id' => (int) $user['id']]);
}

final class ChangePasswordFailingPDO extends PDO
{
	public function __construct()
	{
	}

	public function beginTransaction(): bool
	{
		throw new PDOException('Simulated database failure.');
	}

	public function inTransaction(): bool
	{
		return false;
	}
}

$database = database_connection();
$assertions = 0;
$userIds = [];
$testTokens = [];
$assert = static function (bool $condition, string $message) use (&$assertions): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
	$assertions++;
};
$testUser = static function (PDO $database, array &$userIds, string $password): int {
	$email = 'change-password-test-' . bin2hex(random_bytes(8)) . '@example.invalid';
	$statement = $database->prepare('INSERT INTO users (uuid, email, password_hash, status) VALUES (?, ?, ?, ?)');
	$statement->execute([auth_uuid(), $email, password_hash($password, PASSWORD_DEFAULT), 'active']);
	$userIds[] = (int) $database->lastInsertId();

	return end($userIds);
};
$authenticationProbe = static function (string $token): array {
	$process = proc_open([PHP_BINARY, __FILE__, '--authenticate'], [
		0 => ['pipe', 'r'],
		1 => ['pipe', 'w'],
		2 => ['pipe', 'w'],
	], $pipes, dirname(__DIR__));
	if (!is_resource($process)) {
		throw new RuntimeException('Unable to start the authentication test process.');
	}
	fwrite($pipes[0], $token === '' ? '' : 'Bearer ' . $token);
	fclose($pipes[0]);
	$output = stream_get_contents($pipes[1]);
	fclose($pipes[1]);
	$error = stream_get_contents($pipes[2]);
	fclose($pipes[2]);
	$exitCode = proc_close($process);
	$body = json_decode($output, true);

	if ($error !== '' || !is_array($body)) {
		throw new RuntimeException('Authentication probe failed: ' . $error);
	}

	return [$exitCode, $body];
};
$expectInvalid = static function (array $body, string $field) use ($assert): void {
	try {
		ChangePasswordService::validate($body);
		throw new RuntimeException('Invalid password input was accepted.');
	} catch (ChangePasswordValidationException $exception) {
		$assert(isset($exception->fields[$field]), 'Validation error should identify ' . $field . '.');
	}
};

try {
	$oldPassword = 'Old-password-123';
	$newPassword = 'New-password-456';
	$userId = $testUser($database, $userIds, $oldPassword);
	$otherUserId = $testUser($database, $userIds, 'Other-password-789');
	[$currentToken] = auth_create_session($database, $userId);
	[$previousToken] = auth_create_session($database, $userId);
	[$otherToken] = auth_create_session($database, $otherUserId);
	$testTokens = [$currentToken, $previousToken, $otherToken];

	$expectInvalid(['new_password' => $newPassword, 'confirm_password' => $newPassword], 'current_password');
	$expectInvalid(['current_password' => $oldPassword, 'confirm_password' => $newPassword], 'new_password');
	$expectInvalid(['current_password' => $oldPassword, 'new_password' => $newPassword], 'confirm_password');
	fwrite(STDOUT, "PASS required fields\n");

	$expectInvalid(['current_password' => $oldPassword, 'new_password' => $newPassword, 'confirm_password' => 'different'], 'confirm_password');
	$expectInvalid(['current_password' => $oldPassword, 'new_password' => 'short', 'confirm_password' => 'short'], 'new_password');
	$expectInvalid(['current_password' => $oldPassword, 'new_password' => str_repeat('a', 73), 'confirm_password' => str_repeat('a', 73)], 'new_password');
	$assert(ChangePasswordService::validate([
		'current_password' => $oldPassword,
		'new_password' => 'पासवर्ड-पासवर्ड',
		'confirm_password' => 'पासवर्ड-पासवर्ड',
	])[1] === 'पासवर्ड-पासवर्ड', 'Password minimum should count Unicode characters without trimming.');
	fwrite(STDOUT, "PASS confirmation, length, maximum, and Unicode validation\n");

	try {
		ChangePasswordService::change($database, $userId, 'incorrect-password', $newPassword);
		throw new RuntimeException('Incorrect current password was accepted.');
	} catch (ChangePasswordInvalidCurrentPasswordException) {
		$assert(true, 'Incorrect current password should be rejected.');
	}
	$unchangedHash = $database->prepare('SELECT password_hash FROM users WHERE id = ?');
	$unchangedHash->execute([$userId]);
	$assert(password_verify($oldPassword, (string) $unchangedHash->fetchColumn()), 'Incorrect current password must not update the stored hash.');
	fwrite(STDOUT, "PASS incorrect current password leaves account unchanged\n");

	try {
		ChangePasswordService::change($database, $userId, $oldPassword, $oldPassword);
		throw new RuntimeException('Reusing the current password was accepted.');
	} catch (ChangePasswordSamePasswordException) {
		$assert(true, 'The same current password should be rejected.');
	}

	try {
		ChangePasswordService::change(new ChangePasswordFailingPDO(), $userId, $oldPassword, $newPassword);
		throw new RuntimeException('Database failure was not propagated.');
	} catch (PDOException) {
		$assert(true, 'Database failures should propagate for the API to return an error response.');
	}
	fwrite(STDOUT, "PASS same-password rejection and database failure propagation\n");

	ChangePasswordService::change($database, $userId, $oldPassword, $newPassword);
	$storedHash = $database->prepare('SELECT password_hash FROM users WHERE id = ?');
	$storedHash->execute([$userId]);
	$passwordHash = (string) $storedHash->fetchColumn();
	$assert(password_verify($newPassword, $passwordHash), 'New password should authenticate against the stored hash.');
	$assert(!password_verify($oldPassword, $passwordHash), 'Old password should no longer authenticate.');
	$sessionCount = $database->prepare('SELECT COUNT(*) FROM user_sessions WHERE user_id = ?');
	$sessionCount->execute([$userId]);
	$assert((int) $sessionCount->fetchColumn() === 0, 'All sessions for the changed account should be invalidated.');
	$sessionCount->execute([$otherUserId]);
	$assert((int) $sessionCount->fetchColumn() === 1, 'Another user session should remain unaffected.');
	fwrite(STDOUT, "PASS password update and user-scoped session invalidation\n");

	foreach ([$currentToken, $previousToken] as $invalidatedToken) {
		[$exitCode, $body] = $authenticationProbe($invalidatedToken);
		$assert($exitCode === 0 && ($body['error']['code'] ?? null) === 'UNAUTHENTICATED', 'Invalidated session tokens must no longer authenticate.');
	}
	[$exitCode, $body] = $authenticationProbe($otherToken);
	$assert($exitCode === 0 && ($body['success'] ?? false) === true && $body['user_id'] === $otherUserId, 'Another user session must continue to authenticate.');
	[$exitCode, $body] = $authenticationProbe('');
	$assert($exitCode === 0 && ($body['error']['code'] ?? null) === 'UNAUTHENTICATED', 'Unauthenticated requests must be rejected.');
	[$exitCode, $body] = $authenticationProbe(str_repeat('x', 32));
	$assert($exitCode === 0 && ($body['error']['code'] ?? null) === 'UNAUTHENTICATED', 'Unknown bearer tokens must be rejected.');

	$expiredToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
	$expiredSession = $database->prepare(
		'INSERT INTO user_sessions (user_id, token_hash, ip_address, user_agent, expires_at) VALUES (?, ?, ?, ?, ?)'
	);
	$expiredSession->execute([$otherUserId, hash('sha256', $expiredToken), null, null, gmdate('Y-m-d H:i:s', time() - 60)]);
	$testTokens[] = $expiredToken;
	[$exitCode, $body] = $authenticationProbe($expiredToken);
	$assert($exitCode === 0 && ($body['error']['code'] ?? null) === 'UNAUTHENTICATED', 'Expired bearer tokens must be rejected.');
	fwrite(STDOUT, "PASS invalidated, missing, unknown, and expired bearer token checks\n");

	fwrite(STDOUT, "OK {$assertions} assertions\n");
} finally {
	if ($testTokens !== []) {
		$deleteSessions = $database->prepare('DELETE FROM user_sessions WHERE token_hash = ?');
		foreach ($testTokens as $token) {
			$deleteSessions->execute([hash('sha256', $token)]);
		}
	}
	if ($userIds !== []) {
		$placeholders = implode(',', array_fill(0, count($userIds), '?'));
		$deleteUsers = $database->prepare('DELETE FROM users WHERE id IN (' . $placeholders . ')');
		$deleteUsers->execute($userIds);
	}
}
