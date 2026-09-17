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
$body = auth_json_body();
$planCode = strtoupper(trim((string) ($body['plan_code'] ?? '')));
if (!in_array($planCode, ['PERSONAL', 'PREMIUM'], true)) {
	response_error('INVALID_PLAN', 'Only PERSONAL and PREMIUM subscriptions can be created.', 422, ['plan_code' => 'Select a paid subscription plan.']);
}

$database = database_connection();
$planStatement = $database->prepare(
	'SELECT id, code, name, description, price, currency, billing_period, razorpay_plan_id
	 FROM subscription_plans WHERE code = :code AND is_active = 1 LIMIT 1'
);
$planStatement->execute(['code' => $planCode]);
$plan = $planStatement->fetch();
if (!$plan) {
	response_error('PLAN_NOT_FOUND', 'The selected subscription plan is not available.', 404);
}

SubscriptionService::reconcilePendingSubscription($database, (int) $user['id'], $planCode);

$existing = $database->prepare(
	"SELECT id, provider_subscription_id, status FROM subscriptions
	 WHERE user_id = :user_id AND plan_id = :plan_id AND provider = 'razorpay'
	 AND provider_subscription_id IS NOT NULL AND status IN ('pending', 'active')
	 ORDER BY id DESC LIMIT 1"
);
$existing->execute(['user_id' => $user['id'], 'plan_id' => $plan['id']]);
$existingSubscription = $existing->fetch();
$existingId = $existingSubscription['provider_subscription_id'] ?? null;
if (is_string($existingId) && $existingId !== '') {
	$checkoutUrl = null;
	if ($existingSubscription['status'] === 'pending') {
		$checkoutUrl = rtrim((string) app_config('APP_URL', ''), '/') . '/api/v1/subscription/checkout?token=' . rawurlencode(RazorpayService::createCheckoutToken((int) $user['id'], (int) $existingSubscription['id'], time() + 900));
	}
	response_success([
		'key_id' => RazorpayService::keyId(),
		'subscription_id' => $existingId,
		'checkout_url' => $checkoutUrl,
		'status' => $existingSubscription['status'],
		'already_active' => $existingSubscription['status'] === 'active',
		'plan' => [
			'code' => $plan['code'],
			'name' => $plan['name'],
			'price' => (float) $plan['price'],
			'currency' => $plan['currency'],
			'billing_period' => $plan['billing_period'],
		],
		'plan_code' => $plan['code'],
		'amount' => (float) $plan['price'],
		'currency' => $plan['currency'],
	], $existingSubscription['status'] === 'active' ? 'You are already subscribed to this plan.' : 'Existing Razorpay subscription checkout.');
}

$totalCount = (int) app_config('RAZORPAY_SUBSCRIPTION_TOTAL_COUNT', '12');
if ($totalCount < 1) {
	response_error('RAZORPAY_CONFIGURATION_ERROR', 'Razorpay subscription configuration is incomplete.', 500);
}

try {
	$providerPlanId = RazorpayService::ensurePlan($database, $plan);
	$providerSubscription = RazorpayService::createSubscription($providerPlanId, $totalCount);
	$providerSubscriptionId = $providerSubscription['id'] ?? null;
	if (!is_string($providerSubscriptionId) || $providerSubscriptionId === '') {
		throw new RuntimeException('Razorpay did not return a subscription ID.');
	}

	$insert = $database->prepare(
		"INSERT INTO subscriptions (user_id, plan_id, provider, provider_subscription_id, status, started_at, auto_renew, metadata)
		 VALUES (:user_id, :plan_id, 'razorpay', :provider_subscription_id, 'pending', NULL, TRUE, :metadata)"
	);
	$insert->execute([
		'user_id' => $user['id'],
		'plan_id' => $plan['id'],
		'provider_subscription_id' => $providerSubscriptionId,
		'metadata' => json_encode(['provider_status' => $providerSubscription['status'] ?? 'created'], JSON_THROW_ON_ERROR),
	]);
	$localSubscriptionId = (int) $database->lastInsertId();
	$checkoutToken = RazorpayService::createCheckoutToken((int) $user['id'], $localSubscriptionId, time() + 900);
	$checkoutUrl = rtrim((string) app_config('APP_URL', ''), '/') . '/api/v1/subscription/checkout?token=' . rawurlencode($checkoutToken);

	response_success([
		'key_id' => RazorpayService::keyId(),
		'subscription_id' => $providerSubscriptionId,
		'short_url' => $providerSubscription['short_url'] ?? null,
		'checkout_url' => $checkoutUrl,
		'plan' => [
			'code' => $plan['code'],
			'name' => $plan['name'],
			'price' => (float) $plan['price'],
			'currency' => $plan['currency'],
			'billing_period' => $plan['billing_period'],
		],
		'plan_code' => $plan['code'],
		'amount' => (float) $plan['price'],
		'currency' => $plan['currency'],
	], 'Razorpay subscription created.');
} catch (Throwable $exception) {
	if ($exception instanceof PDOException && $exception->getCode() === '23000') {
		response_error('SUBSCRIPTION_ALREADY_EXISTS', 'A subscription checkout is already in progress.', 409);
	}
	response_error('RAZORPAY_REQUEST_FAILED', 'Unable to create the Razorpay subscription.', 502);
}