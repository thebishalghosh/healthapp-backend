<?php

declare(strict_types=1);

require_once __DIR__ . '/SubscriptionService.php';

final class EntitlementService
{
	private const FEATURE_REQUIREMENTS = [
		'ai' => 'PERSONAL',
		'ai_food_recommendations' => 'PERSONAL',
		'reminders' => 'PERSONAL',
	];

	public static function getCurrentPlan(PDO $database, int $userId): string
	{
		$plan = SubscriptionService::getCurrentPlan($database, $userId);
		$code = strtoupper(trim((string) ($plan['code'] ?? 'FREE')));

		return self::planExists($database, $code) ? $code : self::freePlanCode($database);
	}

	public static function hasFeature(PDO $database, int $userId, string $feature): bool
	{
		$feature = trim($feature);
		if (!isset(self::FEATURE_REQUIREMENTS[$feature])) {
			return false;
		}

		$currentPlan = self::getCurrentPlan($database, $userId);
		$requiredPlan = self::FEATURE_REQUIREMENTS[$feature];

		return self::planRank($database, $currentPlan) >= self::planRank($database, $requiredPlan);
	}

	public static function requireFeature(PDO $database, int $userId, string $feature): void
	{
		if (self::hasFeature($database, $userId, $feature)) {
			return;
		}

		$requiredPlan = self::FEATURE_REQUIREMENTS[$feature] ?? 'PERSONAL';
		$code = $requiredPlan === 'PREMIUM' ? 'FEATURE_REQUIRES_PREMIUM' : 'FEATURE_REQUIRES_SUBSCRIPTION';
		$message = $requiredPlan === 'PREMIUM'
			? 'This feature requires a Premium subscription.'
			: 'This feature requires an active subscription.';

		respond([
			'success' => false,
			'error' => [
				'code' => $code,
				'message' => $message,
				'required_plan' => $requiredPlan,
				'fields' => [],
			],
		], 403);
	}

	public static function entitlements(PDO $database, int $userId): array
	{
		$plan = self::getCurrentPlan($database, $userId);

		return [
			'plan' => $plan,
			'is_paid' => $plan !== self::freePlanCode($database),
			'features' => [
				'ai' => self::hasFeature($database, $userId, 'ai'),
				'ai_food_recommendations' => self::hasFeature($database, $userId, 'ai_food_recommendations'),
				'reminders' => self::hasFeature($database, $userId, 'reminders'),
			],
		];
	}

	private static function planRank(PDO $database, string $code): int
	{
		$statement = $database->prepare('SELECT code FROM subscription_plans WHERE code IN (:free, :personal, :premium)');
		$statement->execute(['free' => 'FREE', 'personal' => 'PERSONAL', 'premium' => 'PREMIUM']);
		$available = [];
		while ($row = $statement->fetch()) {
			$available[strtoupper((string) $row['code'])] = true;
		}

		$rank = ['FREE' => 0, 'PERSONAL' => 1, 'PREMIUM' => 2];
		return isset($available[$code], $rank[$code]) ? $rank[$code] : -1;
	}

	private static function planExists(PDO $database, string $code): bool
	{
		$statement = $database->prepare('SELECT 1 FROM subscription_plans WHERE code = :code LIMIT 1');
		$statement->execute(['code' => $code]);

		return (bool) $statement->fetchColumn();
	}

	private static function freePlanCode(PDO $database): string
	{
		$statement = $database->query("SELECT code FROM subscription_plans WHERE UPPER(code) = 'FREE' LIMIT 1");
		$code = $statement->fetchColumn();

		return is_string($code) ? strtoupper($code) : 'FREE';
	}
}