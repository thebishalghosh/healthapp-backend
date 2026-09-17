<?php

declare(strict_types=1);

if (!function_exists('response_error')) {
	require_once dirname(__DIR__, 3) . '/bootstrap.php';
}
require_once dirname(__DIR__, 3) . '/core/auth.php';
require_once dirname(__DIR__, 3) . '/services/RazorpayService.php';
require_once dirname(__DIR__, 3) . '/services/SubscriptionService.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
	response_error('METHOD_NOT_ALLOWED', 'Method not allowed.', 405);
}

$user = authenticated_user();
$database = database_connection();
$statement = $database->prepare(
	"SELECT s.*, p.code AS plan_code, p.name AS plan_name, p.price, p.currency,
			p.billing_period, p.billing_interval
	 FROM subscriptions s INNER JOIN subscription_plans p ON p.id = s.plan_id
	 WHERE s.user_id = :user_id AND s.provider = 'razorpay'
	   AND s.status = 'active'
	 ORDER BY CASE WHEN s.expires_at IS NULL OR s.expires_at >= UTC_TIMESTAMP() THEN 0 ELSE 1 END,
			s.id DESC LIMIT 1"
);
$statement->execute(['user_id' => $user['id']]);
$subscription = $statement->fetch();
if (!$subscription) {
	$cancelledStatement = $database->prepare(
		"SELECT s.id, s.user_id, s.status, s.provider, s.provider_customer_id,
				s.provider_subscription_id, s.started_at, COALESCE(s.expires_at, s.current_period_end) AS expires_at,
				s.cancelled_at, p.id AS plan_id, p.code, p.name, p.description, p.price, p.currency,
				COALESCE(p.billing_period, p.billing_interval) AS billing_period
		 FROM subscriptions s INNER JOIN subscription_plans p ON p.id = s.plan_id
		 WHERE s.user_id = :user_id AND s.provider = 'razorpay' AND s.status = 'cancelled'
		 ORDER BY s.id DESC LIMIT 1"
	);
	$cancelledStatement->execute(['user_id' => $user['id']]);
	$cancelledSubscription = $cancelledStatement->fetch();
	if ($cancelledSubscription) {
		response_success(['subscription' => SubscriptionService::getSubscriptionById($database, (int) $cancelledSubscription['id'], (int) $user['id'])], 'Subscription is already cancelled.');
	}
	response_error('SUBSCRIPTION_NOT_ACTIVE', 'No active subscription found.', 409);
}
if (!is_string($subscription['provider_subscription_id']) || trim($subscription['provider_subscription_id']) === '') {
	response_error('SUBSCRIPTION_PROVIDER_ID_MISSING', 'The active subscription provider ID is missing.', 500);
}

try {
	$providerSubscription = RazorpayService::fetchSubscription($subscription['provider_subscription_id']);
	$providerStatus = (string) ($providerSubscription['status'] ?? '');
	if (!in_array($providerStatus, ['cancelled', 'expired'], true)) {
		$providerSubscription = RazorpayService::cancelSubscription($subscription['provider_subscription_id'], false);
		$providerStatus = (string) ($providerSubscription['status'] ?? '');
	}
	if (($providerSubscription['id'] ?? null) !== $subscription['provider_subscription_id']
		|| !in_array($providerStatus, ['cancelled', 'expired'], true)) {
		response_error('RAZORPAY_CANCELLATION_UNCONFIRMED', 'Razorpay did not confirm subscription cancellation.', 502);
	}
	SubscriptionService::markSubscriptionCancelled($database, (int) $subscription['id']);
	$cancelledSubscription = SubscriptionService::getSubscriptionById($database, (int) $subscription['id'], (int) $user['id']);
	response_success(['subscription' => $cancelledSubscription], 'Subscription cancelled successfully.');
} catch (Throwable $exception) {
	response_error('RAZORPAY_CANCELLATION_FAILED', 'Unable to cancel the Razorpay subscription.', 502);
}