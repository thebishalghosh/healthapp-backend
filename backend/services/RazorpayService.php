<?php

declare(strict_types=1);

final class RazorpayService
{
	private const BASE_URL = 'https://api.razorpay.com/v1';

	public static function ensurePlan(PDO $database, array $plan): string
	{
		$database->beginTransaction();
		try {
			$statement = $database->prepare('SELECT id, razorpay_plan_id FROM subscription_plans WHERE id = :id FOR UPDATE');
			$statement->execute(['id' => $plan['id']]);
			$current = $statement->fetch();
			if (!$current) {
				throw new RuntimeException('Subscription plan was not found.');
			}
			if (is_string($current['razorpay_plan_id']) && $current['razorpay_plan_id'] !== '') {
				$database->commit();
				return $current['razorpay_plan_id'];
			}

			$response = self::request('POST', '/plans', [
				'period' => 'monthly',
				'interval' => 1,
				'item' => [
					'name' => $plan['name'],
					'amount' => (int) round((float) $plan['price'] * 100),
					'currency' => $plan['currency'],
					'description' => $plan['description'] ?? $plan['name'],
				],
			]);
			$providerPlanId = $response['id'] ?? null;
			if (!is_string($providerPlanId) || $providerPlanId === '') {
				throw new RuntimeException('Razorpay did not return a plan ID.');
			}

			$update = $database->prepare('UPDATE subscription_plans SET razorpay_plan_id = :provider_plan_id WHERE id = :id');
			$update->execute(['provider_plan_id' => $providerPlanId, 'id' => $plan['id']]);
			$database->commit();
			return $providerPlanId;
		} catch (Throwable $exception) {
			if ($database->inTransaction()) {
				$database->rollBack();
			}
			throw $exception;
		}
	}

	public static function fetchPlan(string $planId): array
	{
		return self::request('GET', '/plans/' . rawurlencode($planId));
	}

	public static function createSubscription(string $planId, int $totalCount): array
	{
		return self::request('POST', '/subscriptions', [
			'plan_id' => $planId,
			'total_count' => $totalCount,
			'quantity' => 1,
			'customer_notify' => true,
		]);
	}

	public static function fetchSubscription(string $subscriptionId): array
	{
		return self::request('GET', '/subscriptions/' . rawurlencode($subscriptionId));
	}

	public static function cancelSubscription(string $subscriptionId, bool $atCycleEnd = true): array
	{
		return self::request('POST', '/subscriptions/' . rawurlencode($subscriptionId) . '/cancel', [
			'cancel_at_cycle_end' => $atCycleEnd,
		]);
	}

	public static function verifyCheckoutSignature(string $paymentId, string $subscriptionId, string $signature): bool
	{
		$secret = self::secret('RAZORPAY_KEY_SECRET');
		$expected = hash_hmac('sha256', $paymentId . '|' . $subscriptionId, $secret);
		return hash_equals($expected, $signature);
	}

	public static function verifyWebhookSignature(string $rawBody, string $signature): bool
	{
		$expected = hash_hmac('sha256', $rawBody, self::secret('RAZORPAY_WEBHOOK_SECRET'));
		return hash_equals($expected, $signature);
	}

	public static function keyId(): string
	{
		return self::secret('RAZORPAY_KEY_ID');
	}

	public static function createCheckoutToken(int $userId, int $subscriptionId, int $expiresAt): string
	{
		$payload = self::base64UrlEncode(json_encode([
			'user_id' => $userId,
			'subscription_id' => $subscriptionId,
			'expires_at' => $expiresAt,
		], JSON_THROW_ON_ERROR));
		$signature = hash_hmac('sha256', $payload, self::secret('RAZORPAY_KEY_SECRET'));

		return $payload . '.' . $signature;
	}

	public static function verifyCheckoutToken(string $token): ?array
	{
		$parts = explode('.', $token, 2);
		if (count($parts) !== 2 || !preg_match('/^[a-f0-9]{64}$/', $parts[1])) {
			return null;
		}
		$expected = hash_hmac('sha256', $parts[0], self::secret('RAZORPAY_KEY_SECRET'));
		if (!hash_equals($expected, $parts[1])) {
			return null;
		}
		$decoded = json_decode(self::base64UrlDecode($parts[0]), true);
		if (!is_array($decoded) || !isset($decoded['user_id'], $decoded['subscription_id'], $decoded['expires_at']) || (int) $decoded['expires_at'] < time()) {
			return null;
		}

		return [
			'user_id' => (int) $decoded['user_id'],
			'subscription_id' => (int) $decoded['subscription_id'],
		];
	}

	private static function request(string $method, string $path, ?array $body = null): array
	{
		if (strtolower((string) app_config('RAZORPAY_MODE', '')) !== 'test') {
			throw new RuntimeException('Razorpay Test Mode is not enabled.');
		}

		$handle = curl_init(self::BASE_URL . $path);
		if ($handle === false) {
			throw new RuntimeException('Unable to initialize Razorpay request.');
		}
		curl_setopt_array($handle, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_USERPWD => self::secret('RAZORPAY_KEY_ID') . ':' . self::secret('RAZORPAY_KEY_SECRET'),
			CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
			CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
			CURLOPT_TIMEOUT => 20,
		]);
		if ($body !== null) {
			curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
		}
		$responseBody = curl_exec($handle);
		$status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
		$error = curl_error($handle);
		curl_close($handle);

		if ($responseBody === false || $error !== '') {
			throw new RuntimeException('Razorpay request failed.');
		}
		$decoded = json_decode($responseBody, true);
		if ($status < 200 || $status >= 300 || !is_array($decoded)) {
			$identifier = self::requestIdentifier($method, $path, $body);
			error_log(sprintf(
				'[razorpay] request rejected method=%s identifier_type=%s identifier_id=%s http_status=%d error_code=%s',
				$method,
				$identifier['type'],
				$identifier['id'],
				$status,
				is_array($decoded) ? (string) ($decoded['error']['code'] ?? 'unknown') : 'invalid_response'
			));
			throw new RuntimeException('Razorpay request was rejected with HTTP ' . $status . '.');
		}

		return $decoded;
	}

	private static function requestIdentifier(string $method, string $path, ?array $body): array
	{
		if (is_array($body) && isset($body['plan_id']) && is_string($body['plan_id'])) {
			return ['type' => 'plan_id', 'id' => $body['plan_id']];
		}
		if (preg_match('#/plans/([^/]+)#', $path, $matches) === 1) {
			return ['type' => 'plan_id', 'id' => rawurldecode($matches[1])];
		}
		if (preg_match('#/subscriptions/([^/]+)#', $path, $matches) === 1) {
			return ['type' => 'subscription_id', 'id' => rawurldecode($matches[1])];
		}
		if (preg_match('#/payments/([^/]+)#', $path, $matches) === 1) {
			return ['type' => 'payment_id', 'id' => rawurldecode($matches[1])];
		}

		return ['type' => 'none', 'id' => '-'];
	}

	private static function secret(string $name): string
	{
		$value = app_config($name, '');
		if (!is_string($value) || trim($value) === '') {
			throw new RuntimeException('Razorpay configuration is incomplete.');
		}

		return trim($value);
	}

	private static function base64UrlEncode(string $value): string
	{
		return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
	}

	private static function base64UrlDecode(string $value): string
	{
		$padding = strlen($value) % 4;
		return base64_decode(strtr($value . ($padding ? str_repeat('=', 4 - $padding) : ''), '-_', '+/'), true) ?: '';
	}
}