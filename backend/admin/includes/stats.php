<?php

declare(strict_types=1);

function getDashboardStats(PDO $database): array
{
	$statement = $database->query(
		"WITH current_subscriptions AS (
			SELECT u.id AS user_id, s.id AS subscription_id, s.status, s.expires_at,
				 s.current_period_end, p.code AS plan_code
			FROM users u
			LEFT JOIN subscriptions s ON s.id = (
				SELECT s2.id
				FROM subscriptions s2
				WHERE s2.user_id = u.id
				ORDER BY CASE
					WHEN s2.status = 'active'
						AND (s2.expires_at IS NULL OR s2.expires_at >= UTC_TIMESTAMP()) THEN 0
					ELSE 1
				END, s2.created_at DESC, s2.id DESC
				LIMIT 1
			)
			LEFT JOIN subscription_plans p ON p.id = s.plan_id
			WHERE u.deleted_at IS NULL
		), effective_subscriptions AS (
			SELECT *
			FROM current_subscriptions
			WHERE subscription_id IS NOT NULL
				AND status = 'active'
				AND (COALESCE(expires_at, current_period_end) IS NULL OR COALESCE(expires_at, current_period_end) >= UTC_TIMESTAMP())
		)
		SELECT
			(SELECT COUNT(*) FROM users WHERE deleted_at IS NULL) AS total_users,
			(SELECT COUNT(*) FROM users WHERE status = 'active' AND deleted_at IS NULL) AS active_users,
			(SELECT COUNT(*) FROM effective_subscriptions) AS active_subscriptions,
			(SELECT COUNT(*) FROM effective_subscriptions WHERE plan_code = 'PERSONAL') AS active_personal_subscribers,
			(SELECT COUNT(*) FROM effective_subscriptions WHERE plan_code = 'PREMIUM') AS active_premium_subscribers,
			(SELECT COUNT(*) FROM subscriptions WHERE status = 'cancelled') AS cancelled_subscriptions"
	);

	$stats = $statement->fetch();
	if (!$stats) {
		throw new RuntimeException('Unable to calculate dashboard statistics.');
	}

	return array_map('intval', $stats);
}