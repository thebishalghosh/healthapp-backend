<?php

declare(strict_types=1);

final class SubscriptionService
{
	private const FREE_PLAN_CODE = 'FREE';
	private const FEATURE_CODES = [
		'essential_nutrition', 'meal_tracking', 'workout_tracking', 'water_tracking', 'sleep_tracking',
		'smart_reminders', 'daily_goals', 'weekly_goals', 'monthly_goals', 'streaks', 'achievements',
		'weekly_health_report', 'ai_meal_planning', 'personalized_workout', 'location_food_preference',
		'ai_food_scanner', 'workout_songs', 'advanced_ai_insights', 'advanced_health_reports', 'ad_free',
		'referral_program', 'coins_rewards',
	];

	public static function getCurrentSubscription(PDO $database, int $userId): array
	{
		$statement = $database->prepare(
			"SELECT s.id, s.user_id, s.status, s.provider, s.provider_customer_id,
				s.provider_subscription_id, s.started_at, COALESCE(s.expires_at, s.current_period_end) AS expires_at,
				s.cancelled_at, p.id AS plan_id, p.code, p.name, p.description, p.price, p.currency,
				COALESCE(p.billing_period, p.billing_interval) AS billing_period
			 FROM subscriptions s
			 INNER JOIN subscription_plans p ON p.id = s.plan_id
			 WHERE s.user_id = :user_id
			 ORDER BY CASE WHEN s.status = 'active' AND (s.expires_at IS NULL OR s.expires_at >= UTC_TIMESTAMP()) THEN 0 ELSE 1 END,
				 s.created_at DESC, s.id DESC
			 LIMIT 1"
		);
		$statement->execute(['user_id' => $userId]);
		$subscription = $statement->fetch();

		if (!$subscription || !self::isEffective($subscription)) {
			return self::freeSubscription($database, $userId, $subscription && self::isExpired($subscription) ? 'expired' : 'active');
		}

		return self::formatSubscription($subscription);
	}

	public static function getCurrentPlan(PDO $database, int $userId): array
	{
		return self::getCurrentSubscription($database, $userId)['plan'];
	}

	public static function getSubscriptionById(PDO $database, int $subscriptionId, int $userId): ?array
	{
		$statement = $database->prepare(
			"SELECT s.id, s.user_id, s.status, s.provider, s.provider_customer_id,
				s.provider_subscription_id, s.started_at, COALESCE(s.expires_at, s.current_period_end) AS expires_at,
				s.cancelled_at, p.id AS plan_id, p.code, p.name, p.description, p.price, p.currency,
				COALESCE(p.billing_period, p.billing_interval) AS billing_period
			 FROM subscriptions s INNER JOIN subscription_plans p ON p.id = s.plan_id
			 WHERE s.id = :id AND s.user_id = :user_id LIMIT 1"
		);
		$statement->execute(['id' => $subscriptionId, 'user_id' => $userId]);
		$subscription = $statement->fetch();

		return $subscription ? self::formatSubscription($subscription) : null;
	}

	public static function hasFeature(PDO $database, int $userId, string $featureCode): bool
	{
		$featureCode = trim($featureCode);
		if ($featureCode === '') {
			return false;
		}

		$statement = $database->prepare(
			'SELECT pf.enabled
			 FROM plan_features pf
			 INNER JOIN subscription_plans p ON p.id = pf.plan_id
			 WHERE p.code = :code AND pf.feature_code = :feature_code AND pf.enabled = 1
			 LIMIT 1'
		);
		$statement->execute([
			'code' => self::getCurrentPlan($database, $userId)['code'],
			'feature_code' => $featureCode,
		]);

		return (bool) $statement->fetchColumn();
	}

	public static function getFeatures(PDO $database, int $userId): array
	{
		$statement = $database->prepare(
			' SELECT pf.feature_code, pf.enabled
			  FROM plan_features pf
			  INNER JOIN subscription_plans p ON p.id = pf.plan_id
			  WHERE p.code = :code'
		);
		$statement->execute(['code' => self::getCurrentPlan($database, $userId)['code']]);
		$features = array_fill_keys(self::FEATURE_CODES, false);
		while ($feature = $statement->fetch()) {
			$features[$feature['feature_code']] = (bool) $feature['enabled'];
		}

		return $features;
	}

	public static function ensureFreeSubscription(PDO $database, int $userId): void
	{
		$plan = self::freePlan($database);
		$statement = $database->prepare(
			"INSERT INTO subscriptions (user_id, plan_id, status, started_at)
			 SELECT :user_id, :plan_id, 'active', UTC_TIMESTAMP()
			 WHERE NOT EXISTS (SELECT 1 FROM subscriptions WHERE user_id = :existing_user_id)"
		);
		$statement->execute(['user_id' => $userId, 'plan_id' => $plan['id'], 'existing_user_id' => $userId]);
	}

	public static function getActivePlans(PDO $database): array
	{
		$statement = $database->query(
			"SELECT code, name, description, price, currency, COALESCE(billing_period, billing_interval) AS billing_period
			 FROM subscription_plans WHERE is_active = 1 ORDER BY price ASC, id ASC"
		);

		$plans = $statement->fetchAll();
		foreach ($plans as &$plan) {
			$plan['price'] = (float) $plan['price'];
		}

		return $plans;
	}

	public static function requiredPlan(PDO $database, string $featureCode): ?string
	{
		$statement = $database->prepare(
			' SELECT p.code FROM plan_features pf
			  INNER JOIN subscription_plans p ON p.id = pf.plan_id
			  WHERE pf.feature_code = :feature_code AND pf.enabled = 1
			  ORDER BY p.price ASC, p.id ASC LIMIT 1'
		);
		$statement->execute(['feature_code' => $featureCode]);
		$code = $statement->fetchColumn();

		return is_string($code) ? $code : null;
	}

	public static function findProviderSubscription(PDO $database, string $providerSubscriptionId): ?array
	{
		$statement = $database->prepare(
			'SELECT s.*, p.code AS plan_code, p.price, p.currency, p.razorpay_plan_id
			 FROM subscriptions s INNER JOIN subscription_plans p ON p.id = s.plan_id
			 WHERE s.provider = :provider AND s.provider_subscription_id = :provider_subscription_id LIMIT 1'
		);
		$statement->execute(['provider' => 'razorpay', 'provider_subscription_id' => $providerSubscriptionId]);
		$subscription = $statement->fetch();

		return $subscription ?: null;
	}

	public static function reconcilePendingSubscription(PDO $database, int $userId, ?string $planCode = null): void
	{
		$sql = "SELECT s.id, s.provider_subscription_id, p.razorpay_plan_id
			FROM subscriptions s
			INNER JOIN subscription_plans p ON p.id = s.plan_id
			WHERE s.user_id = :user_id AND s.provider = 'razorpay'
			  AND s.status = 'pending' AND s.provider_subscription_id IS NOT NULL";
		$params = ['user_id' => $userId];
		if ($planCode !== null) {
			$sql .= ' AND p.code = :plan_code';
			$params['plan_code'] = $planCode;
		}
		$sql .= ' ORDER BY s.id DESC LIMIT 1';
		$statement = $database->prepare($sql);
		$statement->execute($params);
		$subscription = $statement->fetch();
		if (!$subscription) {
			return;
		}

		try {
			$providerSubscription = RazorpayService::fetchSubscription($subscription['provider_subscription_id']);
			if (($providerSubscription['id'] ?? null) !== $subscription['provider_subscription_id']
				|| ($providerSubscription['plan_id'] ?? null) !== $subscription['razorpay_plan_id']) {
				error_log(sprintf(
					'[razorpay] subscription reconciliation mismatch subscription_id=%s plan_id=%s',
					$subscription['provider_subscription_id'],
					$subscription['razorpay_plan_id']
				));
				return;
			}

			self::syncProviderSubscription($database, (int) $subscription['id'], $providerSubscription);
			if (in_array($providerSubscription['status'] ?? null, ['active', 'authenticated'], true)) {
				self::retirePreviousActiveSubscriptions($database, (int) $subscription['id']);
			}
		} catch (Throwable $exception) {
			error_log(sprintf(
				'[razorpay] subscription reconciliation failed subscription_id=%s error=%s',
				$subscription['provider_subscription_id'],
				$exception->getMessage()
			));
		}
	}

	public static function syncProviderSubscription(PDO $database, int $subscriptionId, array $providerSubscription): void
	{
		$providerStatus = (string) ($providerSubscription['status'] ?? 'created');
		$currentEnd = self::timestampToDate($providerSubscription['current_end'] ?? null);
		$status = match ($providerStatus) {
			'active', 'authenticated' => 'active',
			'pending', 'halted', 'paused', 'created' => 'pending',
			'completed', 'expired' => 'expired',
			'cancelled' => ($currentEnd !== null && strtotime($currentEnd) > time()) ? 'active' : 'cancelled',
			default => 'pending',
		};
		$statement = $database->prepare(
			'UPDATE subscriptions SET status = :status, provider_customer_id = :provider_customer_id,
			 current_period_start = :current_period_start, current_period_end = :current_period_end,
			 expires_at = :expires_at, cancelled_at = :cancelled_at, auto_renew = :auto_renew
			 WHERE id = :id'
		);
		$statement->execute([
			'status' => $status,
			'provider_customer_id' => $providerSubscription['customer_id'] ?? null,
			'current_period_start' => self::timestampToDate($providerSubscription['current_start'] ?? null),
			'current_period_end' => $currentEnd,
			'expires_at' => $currentEnd,
			'cancelled_at' => self::timestampToDate($providerSubscription['ended_at'] ?? null),
			'auto_renew' => $providerStatus === 'cancelled' ? 0 : 1,
			'id' => $subscriptionId,
		]);
	}

	public static function markSubscriptionCancelled(PDO $database, int $subscriptionId): void
	{
		$statement = $database->prepare(
			"UPDATE subscriptions SET status = 'cancelled', cancelled_at = COALESCE(cancelled_at, UTC_TIMESTAMP()), auto_renew = FALSE
			 WHERE id = :id AND provider = 'razorpay'"
		);
		$statement->execute(['id' => $subscriptionId]);
	}

	public static function retirePreviousActiveSubscriptions(PDO $database, int $subscriptionId): void
	{
		$currentStatement = $database->prepare(
			"SELECT user_id, plan_id FROM subscriptions
			 WHERE id = :id AND provider = 'razorpay' AND status = 'active' LIMIT 1"
		);
		$currentStatement->execute(['id' => $subscriptionId]);
		$current = $currentStatement->fetch();
		if (!$current) {
			return;
		}

		$previousStatement = $database->prepare(
			"SELECT id, provider_subscription_id FROM subscriptions
			 WHERE user_id = :user_id AND plan_id <> :plan_id AND provider = 'razorpay'
			   AND provider_subscription_id IS NOT NULL AND status = 'active'
			 ORDER BY id ASC"
		);
		$previousStatement->execute(['user_id' => $current['user_id'], 'plan_id' => $current['plan_id']]);
		$previousSubscriptions = $previousStatement->fetchAll();

		foreach ($previousSubscriptions as $previous) {
			try {
				RazorpayService::cancelSubscription($previous['provider_subscription_id'], false);
				$update = $database->prepare(
					"UPDATE subscriptions SET status = 'cancelled', cancelled_at = UTC_TIMESTAMP(), auto_renew = FALSE
					 WHERE id = :id AND status = 'active'"
				);
				$update->execute(['id' => $previous['id']]);
			} catch (Throwable $exception) {
				error_log(sprintf(
					'[razorpay] previous subscription retirement failed subscription_id=%s error=%s',
					$previous['provider_subscription_id'],
					$exception->getMessage()
				));
			}
		}
	}

	public static function recordProviderPayment(PDO $database, array $subscription, array $payment, string $status = 'success'): void
	{
		$providerPaymentId = $payment['id'] ?? null;
		if (!is_string($providerPaymentId) || $providerPaymentId === '') {
			return;
		}
		$amount = isset($payment['amount']) ? ((float) $payment['amount'] / 100) : (float) $subscription['price'];
		$statement = $database->prepare(
			'INSERT INTO payments
			 (user_id, subscription_id, provider, provider_payment_id, provider_order_id, razorpay_subscription_id,
			  amount, currency, status, payment_method, metadata, paid_at)
			 VALUES (:user_id, :subscription_id, :provider, :provider_payment_id, :provider_order_id, :razorpay_subscription_id,
			  :amount, :currency, :status, :payment_method, :metadata, :paid_at)
			 ON DUPLICATE KEY UPDATE subscription_id = VALUES(subscription_id), status = VALUES(status),
			 amount = VALUES(amount), currency = VALUES(currency), payment_method = VALUES(payment_method),
			 metadata = VALUES(metadata), paid_at = VALUES(paid_at)'
		);
		$statement->execute([
			'user_id' => $subscription['user_id'],
			'subscription_id' => $subscription['id'],
			'provider' => 'razorpay',
			'provider_payment_id' => $providerPaymentId,
			'provider_order_id' => $payment['order_id'] ?? null,
			'razorpay_subscription_id' => $subscription['provider_subscription_id'],
			'amount' => $amount,
			'currency' => $payment['currency'] ?? $subscription['currency'],
			'status' => $status,
			'payment_method' => $payment['method'] ?? null,
			'metadata' => json_encode(['invoice_id' => $payment['invoice_id'] ?? null], JSON_THROW_ON_ERROR),
			'paid_at' => $status === 'success' ? gmdate('Y-m-d H:i:s') : null,
		]);
	}

	private static function timestampToDate(mixed $timestamp): ?string
	{
		return is_numeric($timestamp) && (int) $timestamp > 0 ? gmdate('Y-m-d H:i:s', (int) $timestamp) : null;
	}

	private static function freePlan(PDO $database): array
	{
		$statement = $database->prepare('SELECT id, code, name, description, price, currency, COALESCE(billing_period, billing_interval) AS billing_period FROM subscription_plans WHERE code = :code AND is_active = 1 LIMIT 1');
		$statement->execute(['code' => self::FREE_PLAN_CODE]);
		$plan = $statement->fetch();
		if (!$plan) {
			throw new RuntimeException('The FREE subscription plan is not configured.');
		}

		return $plan;
	}

	private static function freeSubscription(PDO $database, int $userId, string $status): array
	{
		$plan = self::freePlan($database);
		return [
			'id' => null,
			'user_id' => $userId,
			'status' => $status,
			'provider' => null,
			'provider_customer_id' => null,
			'provider_subscription_id' => null,
			'started_at' => null,
			'expires_at' => null,
			'cancelled_at' => null,
			'plan' => self::formatPlan($plan),
		];
	}

	private static function isExpired(array $subscription): bool
	{
		return $subscription['status'] === 'expired'
			|| ($subscription['expires_at'] !== null && strtotime((string) $subscription['expires_at']) < time());
	}

	private static function isEffective(array $subscription): bool
	{
		return $subscription['status'] === 'active' && !self::isExpired($subscription);
	}

	private static function formatSubscription(array $subscription): array
	{
		return [
			'id' => (int) $subscription['id'],
			'user_id' => (int) $subscription['user_id'],
			'status' => self::isExpired($subscription) ? 'expired' : $subscription['status'],
			'provider' => $subscription['provider'],
			'provider_customer_id' => $subscription['provider_customer_id'],
			'provider_subscription_id' => $subscription['provider_subscription_id'],
			'started_at' => $subscription['started_at'],
			'expires_at' => $subscription['expires_at'],
			'cancelled_at' => $subscription['cancelled_at'],
			'plan' => self::formatPlan($subscription),
		];
	}

	private static function formatPlan(array $plan): array
	{
		return [
			'code' => $plan['code'],
			'name' => $plan['name'],
			'description' => $plan['description'],
			'price' => (float) $plan['price'],
			'currency' => $plan['currency'],
			'billing_period' => $plan['billing_period'],
		];
	}
}