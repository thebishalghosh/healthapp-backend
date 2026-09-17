<?php

declare(strict_types=1);

if (!function_exists('response_error')) {
	require_once dirname(__DIR__, 3) . '/bootstrap.php';
}
require_once dirname(__DIR__, 3) . '/services/RazorpayService.php';
require_once dirname(__DIR__, 3) . '/services/SubscriptionService.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
	response_error('METHOD_NOT_ALLOWED', 'Method not allowed.', 405);
}

$rawBody = file_get_contents('php://input') ?: '';
$signature = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';
if (!is_string($signature) || $signature === '' || !RazorpayService::verifyWebhookSignature($rawBody, $signature)) {
	response_error('INVALID_WEBHOOK_SIGNATURE', 'Webhook signature verification failed.', 400);
}

$payload = json_decode($rawBody, true);
if (!is_array($payload) || !is_string($payload['event'] ?? null)) {
	response_error('INVALID_WEBHOOK_PAYLOAD', 'Webhook payload is invalid.', 400);
}

$eventName = $payload['event'];
$eventKey = $_SERVER['HTTP_X_RAZORPAY_EVENT_ID'] ?? hash('sha256', $rawBody);
$database = database_connection();
$database->beginTransaction();
try {
	$ledger = $database->prepare(
		'INSERT INTO payment_webhook_events (provider, event_key, event_name, payload_hash) VALUES (:provider, :event_key, :event_name, :payload_hash)'
	);
	$ledger->execute(['provider' => 'razorpay', 'event_key' => $eventKey, 'event_name' => $eventName, 'payload_hash' => hash('sha256', $rawBody)]);

	$subscriptionEntity = $payload['payload']['subscription']['entity'] ?? null;
	$subscription = null;
	if (is_array($subscriptionEntity) && is_string($subscriptionEntity['id'] ?? null)) {
		$subscription = SubscriptionService::findProviderSubscription($database, $subscriptionEntity['id']);
		if ($subscription) {
			SubscriptionService::syncProviderSubscription($database, (int) $subscription['id'], $subscriptionEntity);
			$paymentEntity = $payload['payload']['payment']['entity'] ?? null;
			if (is_array($paymentEntity)) {
				$paymentStatus = $eventName === 'subscription.charged' || ($paymentEntity['status'] ?? '') === 'captured' ? 'success' : 'failed';
				SubscriptionService::recordProviderPayment($database, $subscription, $paymentEntity, $paymentStatus);
			}
		}
	}
	$database->commit();
	if (is_array($subscriptionEntity) && in_array($subscriptionEntity['status'] ?? null, ['active', 'authenticated'], true) && $subscription) {
		SubscriptionService::retirePreviousActiveSubscriptions($database, (int) $subscription['id']);
	}
	response_success([], 'Webhook accepted.');
} catch (Throwable $exception) {
	if ($database->inTransaction()) {
		$database->rollBack();
	}
	if ($exception instanceof PDOException && $exception->getCode() === '23000') {
		response_success([], 'Webhook already processed.');
	}
	response_error('WEBHOOK_PROCESSING_FAILED', 'Webhook could not be processed.', 500);
}