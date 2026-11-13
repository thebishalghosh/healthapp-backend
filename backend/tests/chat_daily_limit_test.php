<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
	fwrite(STDERR, "Run this test from the command line.\n");
	exit(1);
}

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/core/auth.php';
require_once dirname(__DIR__) . '/services/ChatbotService.php';

if (($argv[1] ?? null) === '--concurrency-worker') {
	$database = database_connection();
	try {
		ChatbotService::sendMessage(
			$database,
			(int) $argv[2],
			(int) $argv[3],
			'concurrent request',
			null,
			static function (): string {
				usleep(400000);
				return 'concurrent response';
			}
		);
		fwrite(STDOUT, "success\n");
		exit(0);
	} catch (ChatDailyLimitReachedException) {
		fwrite(STDOUT, "limited\n");
		exit(0);
	} catch (Throwable $exception) {
		fwrite(STDERR, $exception->getMessage() . "\n");
		exit(1);
	}
}

if (($argv[1] ?? null) === '--auth-worker') {
	unset($_SERVER['HTTP_AUTHORIZATION']);
	authenticated_user();
	fwrite(STDOUT, "unexpectedly authenticated\n");
	exit(1);
}

$unitAssertions = 0;
$unitAssert = static function (bool $condition, string $message) use (&$unitAssertions): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
	$unitAssertions++;
};
foreach (['FREE' => 1, 'PERSONAL' => 5, 'PREMIUM' => 20] as $plan => $limit) {
	$unitAssert(ChatbotService::dailyLimit($plan) === $limit, $plan . ' should resolve to its configured daily chat limit.');
}
$unitAssert(
	ChatbotService::resolveTimezone('Not/A-Timezone')->getName() === HealthTrackingService::TIMEZONE,
	'Invalid timezone should use the existing UTC fallback.'
);
$unitAssert(
	ChatbotService::resolveTimezone(null)->getName() === HealthTrackingService::TIMEZONE,
	'Missing timezone should use the existing UTC fallback.'
);
$kolkataWindow = ChatbotService::usageWindow(
	new DateTimeImmutable('2026-01-01 20:00:00', new DateTimeZone('UTC')),
	new DateTimeZone('Asia/Kolkata')
);
$unitAssert(
	$kolkataWindow === ['usage_date' => '2026-01-02', 'reset_at' => '2026-01-03T00:00:00+05:30'],
	'Usage dates and resets should follow the configured IANA timezone.'
);
$springForwardWindow = ChatbotService::usageWindow(
	new DateTimeImmutable('2026-03-08 01:30:00', new DateTimeZone('America/New_York')),
	new DateTimeZone('America/New_York')
);
$unitAssert(
	$springForwardWindow['reset_at'] === '2026-03-09T00:00:00-04:00',
	'Reset time should use the next local midnight across daylight-saving changes.'
);
$chatResponse = GeminiService::generateChatResponse(
	[['role' => 'user', 'message' => 'hello']],
	static function (string $prompt, string $systemInstruction): string {
		if (!str_contains($prompt, 'User: hello') || $systemInstruction === '') {
			throw new RuntimeException('Chat prompt did not preserve the request context.');
		}
		return json_encode(['candidates' => [['content' => ['parts' => [['text' => 'hello back']]]]]], JSON_THROW_ON_ERROR);
	}
);
$unitAssert($chatResponse === 'hello back', 'Gemini chat response parsing should return the generated text.');
if (($argv[1] ?? null) === '--unit-only') {
	fprintf(STDOUT, "All %d daily AI chat unit assertions passed.\n", $unitAssertions);
	exit(0);
}

$database = database_connection();
$assertions = 0;
$userIds = [];
$assert = static function (bool $condition, string $message) use (&$assertions): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
	$assertions++;
};
$assertThrows = static function (callable $call, string $exceptionClass, string $message) use ($assert): Throwable {
	try {
		$call();
	} catch (Throwable $exception) {
		$assert($exception instanceof $exceptionClass, $message);
		return $exception;
	}
	throw new RuntimeException($message);
};
$createUser = static function (string $timezone) use ($database, &$userIds): array {
	$email = 'chat-limit-test-' . bin2hex(random_bytes(8)) . '@example.invalid';
	$statement = $database->prepare('INSERT INTO users (uuid, email, password_hash, status) VALUES (?, ?, ?, ?)');
	$statement->execute([auth_uuid(), $email, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), 'active']);
	$userId = (int) $database->lastInsertId();
	$userIds[] = $userId;
	$settings = $database->prepare('INSERT INTO user_settings (user_id, timezone) VALUES (:user_id, :timezone)');
	$settings->execute(['user_id' => $userId, 'timezone' => $timezone]);
	$session = $database->prepare('INSERT INTO chat_sessions (user_id) VALUES (:user_id)');
	$session->execute(['user_id' => $userId]);

	return ['id' => $userId, 'session_id' => (int) $database->lastInsertId()];
};
$setPlan = static function (int $userId, string $plan) use ($database): void {
	$statement = $database->prepare(
		'INSERT INTO subscriptions (user_id, plan_id, status, started_at)
		 SELECT :user_id, id, :status, UTC_TIMESTAMP()
		 FROM subscription_plans WHERE code = :plan LIMIT 1'
	);
	$statement->execute(['user_id' => $userId, 'status' => 'active', 'plan' => $plan]);
	if ($statement->rowCount() !== 1) {
		throw new RuntimeException('Required subscription plan is missing: ' . $plan);
	}
};
$send = static function (array $user, ?string $key = null, ?callable $generate = null, string $message = 'hello'): array {
	return ChatbotService::sendMessage(
		database_connection(),
		$user['id'],
		$user['session_id'],
		$message,
		$key,
		$generate ?? static fn (): string => 'hello back'
	);
};
$usageCount = static function (int $userId) use ($database): int {
	$statement = $database->prepare('SELECT COALESCE(SUM(used), 0) FROM ai_chat_daily_usage WHERE user_id = :user_id');
	$statement->execute(['user_id' => $userId]);
	return (int) $statement->fetchColumn();
};

try {
	foreach (['FREE', 'PERSONAL', 'PREMIUM'] as $plan) {
		$assert(
			ChatbotService::dailyLimit($plan) === ['FREE' => 1, 'PERSONAL' => 5, 'PREMIUM' => 20][$plan],
			$plan . ' should resolve to its configured daily chat limit.'
		);
	}
	$assert(
		ChatbotService::resolveTimezone('Not/A-Timezone')->getName() === HealthTrackingService::TIMEZONE,
		'Invalid timezone should use the existing UTC fallback.'
	);
	$assert(
		ChatbotService::resolveTimezone(null)->getName() === HealthTrackingService::TIMEZONE,
		'Missing timezone should use the existing UTC fallback.'
	);
	$dayBeforeSpringForward = ChatbotService::usageWindow(
		new DateTimeImmutable('2026-03-08 01:30:00', new DateTimeZone('America/New_York')),
		new DateTimeZone('America/New_York')
	);
	$assert(
		$dayBeforeSpringForward['reset_at'] === '2026-03-09T00:00:00-04:00',
		'Reset time should use the next local midnight across daylight-saving changes.'
	);

	$plans = $database->query("SELECT code FROM subscription_plans WHERE code IN ('FREE', 'PERSONAL', 'PREMIUM')")->fetchAll(PDO::FETCH_COLUMN);
	$assert(count($plans) === 3, 'FREE, PERSONAL, and PREMIUM plans must exist.');
	$database->query('SELECT used FROM ai_chat_daily_usage LIMIT 0');
	$database->query('SELECT id FROM ai_chat_idempotency LIMIT 0');

	$free = $createUser('Asia/Kolkata');
	$setPlan($free['id'], 'FREE');
	$freeResult = $send($free);
	$assert($freeResult['usage']['plan'] === 'FREE' && $freeResult['usage']['used'] === 1, 'A successful FREE chat should consume one chat.');
	$freeLimit = $assertThrows(static fn () => $send($free), ChatDailyLimitReachedException::class, 'A second FREE chat should be rejected.');
	$assert($freeLimit->usage['remaining'] === 0, 'FREE limit response should report no remaining chats.');

	$personal = $createUser('UTC');
	$setPlan($personal['id'], 'PERSONAL');
	for ($index = 0; $index < 5; $index++) {
		$personalResult = $send($personal, null, null, 'personal ' . $index);
	}
	$assert($personalResult['usage']['used'] === 5, 'PERSONAL should allow five successful chats.');
	$personalLimit = $assertThrows(static fn () => $send($personal), ChatDailyLimitReachedException::class, 'A sixth PERSONAL chat should be rejected.');
	$assert($personalLimit->usage['plan'] === 'PERSONAL' && $personalLimit->usage['daily_limit'] === 5, 'PERSONAL limit response should identify its plan and limit.');

	$premium = $createUser('UTC');
	$setPlan($premium['id'], 'PREMIUM');
	for ($index = 0; $index < 20; $index++) {
		$premiumResult = $send($premium, null, null, 'premium ' . $index);
	}
	$assert($premiumResult['usage']['used'] === 20, 'PREMIUM should allow twenty successful chats.');
	$assertThrows(static fn () => $send($premium), ChatDailyLimitReachedException::class, 'A twenty-first PREMIUM chat should be rejected.');

	$otherFree = $createUser('UTC');
	$setPlan($otherFree['id'], 'FREE');
	$assert($send($otherFree)['usage']['used'] === 1, 'Another user should have an independent FREE quota.');

	$failed = $createUser('UTC');
	$setPlan($failed['id'], 'FREE');
	$assertThrows(
		static fn () => $send($failed, null, static function (): never {
			throw new RuntimeException('simulated Gemini failure');
		}),
		ChatGenerationException::class,
		'Failed AI processing should return a generation failure.'
	);
	$assert($usageCount($failed['id']) === 0, 'Failed AI processing must not consume quota.');

	$idempotent = $createUser('UTC');
	$setPlan($idempotent['id'], 'FREE');
	$generationCount = 0;
	$generator = static function () use (&$generationCount): string {
		$generationCount++;
		return 'cached response';
	};
	$first = $send($idempotent, 'retry-token-1', $generator);
	$retry = $send($idempotent, 'retry-token-1', $generator);
	$assert($first['assistant_message'] === $retry['assistant_message'] && $generationCount === 1, 'An idempotent retry should replay the prior response without another AI call.');
	$assert($usageCount($idempotent['id']) === 1, 'An idempotent retry must consume quota exactly once.');
	$assertThrows(
		static fn () => $send($idempotent, 'retry-token-1', $generator, 'different message'),
		ChatIdempotencyConflictException::class,
		'Reusing an idempotency key for different content should be rejected.'
	);

	$changedPlan = $createUser('UTC');
	$setPlan($changedPlan['id'], 'FREE');
	$send($changedPlan, null, null, 'before upgrade');
	$database->prepare('UPDATE subscriptions SET plan_id = (SELECT id FROM subscription_plans WHERE code = :plan) WHERE user_id = :user_id')
		->execute(['plan' => 'PERSONAL', 'user_id' => $changedPlan['id']]);
	$assert($send($changedPlan, null, null, 'after upgrade')['usage']['plan'] === 'PERSONAL', 'An upgrade should immediately use the new effective plan.');
	$database->prepare('UPDATE subscriptions SET plan_id = (SELECT id FROM subscription_plans WHERE code = :plan) WHERE user_id = :user_id')
		->execute(['plan' => 'FREE', 'user_id' => $changedPlan['id']]);
	$changedPlanLimit = $assertThrows(static fn () => $send($changedPlan), ChatDailyLimitReachedException::class, 'A downgrade should enforce the lower current plan limit.');
	$assert($changedPlanLimit->usage['used'] === 2, 'Downgrade should retain chats already consumed that day.');

	$invalidTimezone = $createUser('Not/A-Timezone');
	$setPlan($invalidTimezone['id'], 'FREE');
	$invalidTimezoneResult = $send($invalidTimezone);
	$utcWindow = ChatbotService::usageWindow(new DateTimeImmutable('now', new DateTimeZone('UTC')), new DateTimeZone('UTC'));
	$usageDateStatement = $database->prepare('SELECT usage_date FROM ai_chat_daily_usage WHERE user_id = :user_id');
	$usageDateStatement->execute(['user_id' => $invalidTimezone['id']]);
	$assert($usageDateStatement->fetchColumn() === $utcWindow['usage_date'], 'Invalid configured timezone should record usage in the UTC fallback date.');
	$assert($invalidTimezoneResult['usage']['reset_at'] === $utcWindow['reset_at'], 'Invalid configured timezone should reset at UTC midnight.');

	$concurrent = $createUser('UTC');
	$setPlan($concurrent['id'], 'FREE');
	if (!function_exists('proc_open')) {
		throw new RuntimeException('proc_open is required for the concurrent quota test.');
	}
	$workers = [];
	for ($index = 0; $index < 2; $index++) {
		$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
		$process = proc_open([PHP_BINARY, __FILE__, '--concurrency-worker', (string) $concurrent['id'], (string) $concurrent['session_id']], $descriptors, $pipes, dirname(__DIR__));
		if (!is_resource($process)) {
			throw new RuntimeException('Concurrent quota worker could not be started.');
		}
		fclose($pipes[0]);
		$workers[] = [$process, $pipes[1], $pipes[2]];
	}
	$workerResults = [];
	foreach ($workers as [$process, $stdout, $stderr]) {
		$output = trim(stream_get_contents($stdout));
		$error = trim(stream_get_contents($stderr));
		fclose($stdout);
		fclose($stderr);
		$exitCode = proc_close($process);
		$assert($exitCode === 0, 'Concurrent worker failed: ' . $error);
		$workerResults[] = $output;
	}
	sort($workerResults);
	$assert($workerResults === ['limited', 'success'], 'Concurrent requests must not exceed the FREE limit.');
	$assert($usageCount($concurrent['id']) === 1, 'Concurrent successful usage should be committed exactly once.');

	$authCommand = [PHP_BINARY, __FILE__, '--auth-worker'];
	$authDescriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
	$authProcess = proc_open($authCommand, $authDescriptors, $authPipes, dirname(__DIR__));
	if (!is_resource($authProcess)) {
		throw new RuntimeException('Authentication test worker could not be started.');
	}
	fclose($authPipes[0]);
	$authOutput = stream_get_contents($authPipes[1]);
	$authError = stream_get_contents($authPipes[2]);
	fclose($authPipes[1]);
	fclose($authPipes[2]);
	proc_close($authProcess);
	$assert(str_contains($authOutput, 'UNAUTHENTICATED'), 'Missing authentication must be rejected by the existing auth middleware: ' . $authError);

	fprintf(STDOUT, "All %d daily AI chat limit assertions passed.\n", $assertions);
} finally {
	if ($userIds !== []) {
		$placeholders = implode(',', array_fill(0, count($userIds), '?'));
		$delete = $database->prepare('DELETE FROM users WHERE id IN (' . $placeholders . ')');
		$delete->execute($userIds);
	}
}
