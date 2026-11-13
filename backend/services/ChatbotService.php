<?php

declare(strict_types=1);

require_once __DIR__ . '/EntitlementService.php';
require_once __DIR__ . '/GeminiService.php';
require_once __DIR__ . '/HealthTrackingService.php';

final class ChatDailyLimitReachedException extends RuntimeException
{
	public function __construct(public readonly array $usage)
	{
		parent::__construct('The daily AI chat limit has been reached.');
	}
}

final class ChatIdempotencyConflictException extends RuntimeException
{
}

final class ChatGenerationException extends RuntimeException
{
}

final class ChatQuotaLockException extends RuntimeException
{
}

final class ChatbotService
{
	private const PLAN_LIMITS = [
		'FREE' => 1,
		'PERSONAL' => 5,
		'PREMIUM' => 20,
	];

	public static function dailyLimit(string $plan): int
	{
		$plan = strtoupper(trim($plan));
		if (!isset(self::PLAN_LIMITS[$plan])) {
			throw new InvalidArgumentException('The effective subscription plan is unsupported.');
		}

		return self::PLAN_LIMITS[$plan];
	}

	public static function resolveTimezone(?string $timezone): DateTimeZone
	{
		if ($timezone !== null && in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
			return new DateTimeZone($timezone);
		}

		return new DateTimeZone(HealthTrackingService::TIMEZONE);
	}

	public static function usageWindow(DateTimeImmutable $now, DateTimeZone $timezone): array
	{
		$localNow = $now->setTimezone($timezone);
		$resetAt = $localNow->setTime(0, 0)->modify('+1 day');

		return [
			'usage_date' => $localNow->format('Y-m-d'),
			'reset_at' => $resetAt->format(DateTimeInterface::ATOM),
		];
	}

	public static function usageSummary(string $plan, int $used, string $resetAt): array
	{
		$limit = self::dailyLimit($plan);

		return [
			'plan' => strtoupper(trim($plan)),
			'daily_limit' => $limit,
			'used' => $used,
			'remaining' => max(0, $limit - $used),
			'reset_at' => $resetAt,
		];
	}

	public static function sendMessage(
		PDO $database,
		int $userId,
		int $sessionId,
		string $message,
		?string $idempotencyKey = null,
		?callable $generate = null
	): array {
		$sessionStatement = $database->prepare(
			'SELECT id, status FROM chat_sessions WHERE id = :session_id AND user_id = :user_id LIMIT 1'
		);
		$sessionStatement->execute(['session_id' => $sessionId, 'user_id' => $userId]);
		$session = $sessionStatement->fetch();
		if (!$session) {
			throw new OutOfBoundsException('Chat session was not found.');
		}
		if ($session['status'] !== 'active') {
			throw new DomainException('Chat session is archived.');
		}

		$lockName = 'chat:' . substr(hash('sha256', (string) $userId), 0, 59);
		$lockStatement = $database->prepare('SELECT GET_LOCK(:lock_name, 10)');
		$lockStatement->execute(['lock_name' => $lockName]);
		if ((int) $lockStatement->fetchColumn() !== 1) {
			throw new ChatQuotaLockException('The chat quota is temporarily unavailable.');
		}

		try {
			$plan = strtoupper((string) EntitlementService::getCurrentPlan($database, $userId));
			$dailyLimit = self::dailyLimit($plan);
			$timezoneStatement = $database->prepare('SELECT timezone FROM user_settings WHERE user_id = :user_id LIMIT 1');
			$timezoneStatement->execute(['user_id' => $userId]);
			$timezone = self::resolveTimezone($timezoneStatement->fetchColumn() ?: null);
			$window = self::usageWindow(new DateTimeImmutable('now', $timezone), $timezone);
			$usage = self::readUsage($database, $userId, $window['usage_date']);

			if ($idempotencyKey !== null) {
				$cached = self::findIdempotentRequest($database, $userId, $idempotencyKey);
				if ($cached !== null) {
					if ((int) $cached['session_id'] !== $sessionId || !hash_equals($cached['request_hash'], hash('sha256', $message))) {
						throw new ChatIdempotencyConflictException('The idempotency key was already used for a different message.');
					}

					return [
						'session_id' => $sessionId,
						'user_message' => $cached['user_message'],
						'assistant_message' => $cached['assistant_message'],
						'usage' => self::usageSummary($plan, $usage, $window['reset_at']),
					];
				}
			}

			$usageSummary = self::usageSummary($plan, $usage, $window['reset_at']);
			if ($usage >= $dailyLimit) {
				throw new ChatDailyLimitReachedException($usageSummary);
			}

			$historyStatement = $database->prepare(
				'SELECT role, message FROM chat_messages
				 WHERE session_id = :session_id AND user_id = :user_id
				 ORDER BY id DESC LIMIT 20'
			);
			$historyStatement->execute(['session_id' => $sessionId, 'user_id' => $userId]);
			$history = array_reverse($historyStatement->fetchAll());
			$history[] = ['role' => 'user', 'message' => $message];

			try {
				$assistantMessage = $generate !== null
					? $generate($history)
					: GeminiService::generateChatResponse($history);
			} catch (Throwable $exception) {
				throw new ChatGenerationException('Chat generation failed.', 0, $exception);
			}
			if (!is_string($assistantMessage) || trim($assistantMessage) === '') {
				throw new ChatGenerationException('Chat generation returned an empty response.');
			}
			$assistantMessage = trim($assistantMessage);

			$database->beginTransaction();
			try {
				$userMessageId = self::insertMessage($database, $sessionId, $userId, 'user', $message, null);
				$model = (string) app_config('GEMINI_MODEL', 'unknown');
				$assistantMessageId = self::insertMessage($database, $sessionId, $userId, 'assistant', $assistantMessage, $model);
				$increment = $database->prepare(
					'INSERT INTO ai_chat_daily_usage (user_id, usage_date, used)
					 VALUES (:user_id, :usage_date, 1)
					 ON DUPLICATE KEY UPDATE used = used + 1'
				);
				$increment->execute(['user_id' => $userId, 'usage_date' => $window['usage_date']]);

				if ($idempotencyKey !== null) {
					$record = $database->prepare(
						'INSERT INTO ai_chat_idempotency
						 (user_id, idempotency_key, request_hash, usage_date, session_id, user_message_id, assistant_message_id)
						 VALUES (:user_id, :idempotency_key, :request_hash, :usage_date, :session_id, :user_message_id, :assistant_message_id)'
					);
					$record->execute([
						'user_id' => $userId,
						'idempotency_key' => $idempotencyKey,
						'request_hash' => hash('sha256', $message),
						'usage_date' => $window['usage_date'],
						'session_id' => $sessionId,
						'user_message_id' => $userMessageId,
						'assistant_message_id' => $assistantMessageId,
					]);
				}

				$updateSession = $database->prepare(
					'UPDATE chat_sessions SET updated_at = UTC_TIMESTAMP(), ai_model = :ai_model
					 WHERE id = :session_id AND user_id = :user_id'
				);
				$updateSession->execute(['ai_model' => $model, 'session_id' => $sessionId, 'user_id' => $userId]);
				$used = self::readUsage($database, $userId, $window['usage_date']);
				$database->commit();
			} catch (Throwable $exception) {
				if ($database->inTransaction()) {
					$database->rollBack();
				}
				throw $exception;
			}

			return [
				'session_id' => $sessionId,
				'user_message' => $message,
				'assistant_message' => $assistantMessage,
				'usage' => self::usageSummary($plan, $used, $window['reset_at']),
			];
		} finally {
			$release = $database->prepare('SELECT RELEASE_LOCK(:lock_name)');
			$release->execute(['lock_name' => $lockName]);
			if ((int) $release->fetchColumn() !== 1) {
				error_log('Unable to release the AI chat quota lock for user ' . $userId . '.');
			}
		}
	}

	private static function readUsage(PDO $database, int $userId, string $usageDate): int
	{
		$statement = $database->prepare(
			'SELECT used FROM ai_chat_daily_usage WHERE user_id = :user_id AND usage_date = :usage_date LIMIT 1'
		);
		$statement->execute(['user_id' => $userId, 'usage_date' => $usageDate]);

		return (int) ($statement->fetchColumn() ?: 0);
	}

	private static function findIdempotentRequest(PDO $database, int $userId, string $idempotencyKey): ?array
	{
		$statement = $database->prepare(
			'SELECT i.request_hash, i.session_id, user_message.message AS user_message,
			 assistant_message.message AS assistant_message
			 FROM ai_chat_idempotency i
			 INNER JOIN chat_messages user_message ON user_message.id = i.user_message_id
			 INNER JOIN chat_messages assistant_message ON assistant_message.id = i.assistant_message_id
			 WHERE i.user_id = :user_id AND i.idempotency_key = :idempotency_key LIMIT 1'
		);
		$statement->execute(['user_id' => $userId, 'idempotency_key' => $idempotencyKey]);
		$request = $statement->fetch();

		return $request ?: null;
	}

	private static function insertMessage(
		PDO $database,
		int $sessionId,
		int $userId,
		string $role,
		string $message,
		?string $model
	): int {
		$statement = $database->prepare(
			'INSERT INTO chat_messages (session_id, user_id, role, message, ai_model)
			 VALUES (:session_id, :user_id, :role, :message, :ai_model)'
		);
		$statement->execute([
			'session_id' => $sessionId,
			'user_id' => $userId,
			'role' => $role,
			'message' => $message,
			'ai_model' => $model,
		]);

		return (int) $database->lastInsertId();
	}
}
