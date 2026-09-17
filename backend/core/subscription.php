<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/services/SubscriptionService.php';

function require_feature(PDO $database, int $userId, string $featureCode): void
{
	if (SubscriptionService::hasFeature($database, $userId, $featureCode)) {
		return;
	}

	response_error(
		'FEATURE_REQUIRES_UPGRADE',
		'Upgrade your subscription to access this feature.',
		403,
		[
			'required_plan' => SubscriptionService::requiredPlan($database, $featureCode),
			'feature' => $featureCode,
		]
	);
}