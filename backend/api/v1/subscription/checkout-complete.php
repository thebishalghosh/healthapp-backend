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

$body = auth_json_body();
$claims = RazorpayService::verifyCheckoutToken((string) ($body['token'] ?? ''));
$paymentId = trim((string) ($body['razorpay_payment_id'] ?? ''));
$providerSubscriptionId = trim((string) ($body['razorpay_subscription_id'] ?? ''));
$signature = trim((string) ($body['razorpay_signature'] ?? ''));
if ($claims === null || $paymentId === '' || $providerSubscriptionId === '' || $signature === '') {
	response_error('INVALID_CHECKOUT_VERIFICATION', 'Checkout verification failed.', 400);
}

$database = database_connection();
$subscription = SubscriptionService::findProviderSubscription($database, $providerSubscriptionId);
if (!$subscription || (int) $subscription['id'] !== $claims['subscription_id'] || (int) $subscription['user_id'] !== $claims['user_id']) {
	response_error('SUBSCRIPTION_NOT_FOUND', 'The subscription could not be verified.', 404);
}
if (!RazorpayService::verifyCheckoutSignature($paymentId, $providerSubscriptionId, $signature)) {
	error_log(sprintf(
		'[razorpay] checkout signature rejected payment_id=%s subscription_id=%s',
		$paymentId,
		$providerSubscriptionId
	));
	response_error('INVALID_RAZORPAY_SIGNATURE', 'Checkout signature verification failed.', 400);
}

try {
	$providerSubscription = RazorpayService::fetchSubscription($providerSubscriptionId);
	if (($providerSubscription['id'] ?? null) !== $providerSubscriptionId || ($providerSubscription['plan_id'] ?? null) !== ($subscription['razorpay_plan_id'] ?? null)) {
		response_error('RAZORPAY_SUBSCRIPTION_MISMATCH', 'The Razorpay subscription does not match the local plan.', 400);
	}
	$database->beginTransaction();
	SubscriptionService::syncProviderSubscription($database, (int) $subscription['id'], $providerSubscription);
	SubscriptionService::recordProviderPayment($database, $subscription, ['id' => $paymentId, 'currency' => $subscription['currency']], 'success');
	$database->commit();
	if (in_array($providerSubscription['status'] ?? null, ['active', 'authenticated'], true)) {
		SubscriptionService::retirePreviousActiveSubscriptions($database, (int) $subscription['id']);
	}
	$providerStatus = (string) ($providerSubscription['status'] ?? 'created');
	if ($providerStatus !== 'active' && $providerStatus !== 'authenticated') {
		response_success(
			['status' => 'pending', 'provider_status' => $providerStatus],
			'Payment received. Subscription activation is being confirmed.'
		);
	}
	response_success(['status' => 'verified'], 'Razorpay subscription verified.');
} catch (Throwable $exception) {
	if ($database->inTransaction()) {
		$database->rollBack();
	}
	response_error('RAZORPAY_VERIFICATION_FAILED', 'Unable to verify the Razorpay subscription.', 502);
}