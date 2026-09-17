-- One-time subscription foundation migration for an existing health_app database.
-- No payment provider integration or payment writes are included.

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE subscription_plans ADD COLUMN billing_period ENUM(\'monthly\', \'quarterly\', \'yearly\', \'lifetime\') NOT NULL DEFAULT \'monthly\' AFTER billing_interval',
        'DO 0'
    )
    FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'subscription_plans' AND column_name = 'billing_period'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE subscriptions ADD COLUMN provider_customer_id VARCHAR(191) NULL AFTER provider',
        'DO 0'
    )
    FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'subscriptions' AND column_name = 'provider_customer_id'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE subscriptions ADD COLUMN expires_at DATETIME NULL AFTER current_period_end',
        'DO 0'
    )
    FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'subscriptions' AND column_name = 'expires_at'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(COUNT(*) = 0,
        'CREATE INDEX idx_subscriptions_expiry ON subscriptions (expires_at)',
        'DO 0'
    )
    FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'subscriptions' AND index_name = 'idx_subscriptions_expiry'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS plan_features (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    plan_id BIGINT UNSIGNED NOT NULL,
    feature_code VARCHAR(100) NOT NULL,
    enabled BOOLEAN NOT NULL DEFAULT FALSE,
    value VARCHAR(191) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_plan_features_plan FOREIGN KEY (plan_id) REFERENCES subscription_plans(id) ON DELETE CASCADE,
    UNIQUE KEY uq_plan_feature (plan_id, feature_code),
    INDEX idx_plan_features_code (feature_code)
) ENGINE=InnoDB;

UPDATE subscription_plans SET code = 'FREE', name = 'Free', price = 0.00, billing_period = billing_interval WHERE code = 'free';
UPDATE subscription_plans SET code = 'PREMIUM', name = 'Premium', price = 499.00, billing_period = billing_interval WHERE code = 'premium_monthly';

INSERT INTO subscription_plans (code, name, description, billing_interval, billing_period, price, currency, trial_days, is_active)
SELECT 'PERSONAL', 'Personal', 'Personal health and wellness plan', 'monthly', 'monthly', 99.00, 'INR', 0, TRUE
WHERE NOT EXISTS (SELECT 1 FROM subscription_plans WHERE code = 'PERSONAL');

INSERT IGNORE INTO plan_features (plan_id, feature_code, enabled)
SELECT plans.id, features.feature_code, features.enabled
FROM subscription_plans plans
JOIN (
    SELECT 'FREE' AS code, 'essential_nutrition' AS feature_code, TRUE AS enabled UNION ALL SELECT 'FREE', 'meal_tracking', TRUE UNION ALL SELECT 'FREE', 'workout_tracking', TRUE UNION ALL SELECT 'FREE', 'water_tracking', TRUE UNION ALL SELECT 'FREE', 'sleep_tracking', TRUE UNION ALL SELECT 'FREE', 'smart_reminders', TRUE UNION ALL SELECT 'FREE', 'daily_goals', TRUE UNION ALL SELECT 'FREE', 'weekly_goals', TRUE UNION ALL SELECT 'FREE', 'monthly_goals', TRUE UNION ALL SELECT 'FREE', 'streaks', TRUE UNION ALL SELECT 'FREE', 'achievements', TRUE UNION ALL SELECT 'FREE', 'weekly_health_report', TRUE UNION ALL SELECT 'FREE', 'referral_program', TRUE UNION ALL
    SELECT 'PERSONAL', 'essential_nutrition', TRUE UNION ALL SELECT 'PERSONAL', 'meal_tracking', TRUE UNION ALL SELECT 'PERSONAL', 'workout_tracking', TRUE UNION ALL SELECT 'PERSONAL', 'water_tracking', TRUE UNION ALL SELECT 'PERSONAL', 'sleep_tracking', TRUE UNION ALL SELECT 'PERSONAL', 'smart_reminders', TRUE UNION ALL SELECT 'PERSONAL', 'daily_goals', TRUE UNION ALL SELECT 'PERSONAL', 'weekly_goals', TRUE UNION ALL SELECT 'PERSONAL', 'monthly_goals', TRUE UNION ALL SELECT 'PERSONAL', 'streaks', TRUE UNION ALL SELECT 'PERSONAL', 'achievements', TRUE UNION ALL SELECT 'PERSONAL', 'weekly_health_report', TRUE UNION ALL SELECT 'PERSONAL', 'referral_program', TRUE UNION ALL SELECT 'PERSONAL', 'ai_meal_planning', TRUE UNION ALL SELECT 'PERSONAL', 'personalized_workout', TRUE UNION ALL SELECT 'PERSONAL', 'location_food_preference', TRUE UNION ALL SELECT 'PERSONAL', 'advanced_ai_insights', TRUE UNION ALL
    SELECT 'PREMIUM', 'essential_nutrition', TRUE UNION ALL SELECT 'PREMIUM', 'meal_tracking', TRUE UNION ALL SELECT 'PREMIUM', 'workout_tracking', TRUE UNION ALL SELECT 'PREMIUM', 'water_tracking', TRUE UNION ALL SELECT 'PREMIUM', 'sleep_tracking', TRUE UNION ALL SELECT 'PREMIUM', 'smart_reminders', TRUE UNION ALL SELECT 'PREMIUM', 'daily_goals', TRUE UNION ALL SELECT 'PREMIUM', 'weekly_goals', TRUE UNION ALL SELECT 'PREMIUM', 'monthly_goals', TRUE UNION ALL SELECT 'PREMIUM', 'streaks', TRUE UNION ALL SELECT 'PREMIUM', 'achievements', TRUE UNION ALL SELECT 'PREMIUM', 'weekly_health_report', TRUE UNION ALL SELECT 'PREMIUM', 'referral_program', TRUE UNION ALL SELECT 'PREMIUM', 'ai_meal_planning', TRUE UNION ALL SELECT 'PREMIUM', 'personalized_workout', TRUE UNION ALL SELECT 'PREMIUM', 'location_food_preference', TRUE UNION ALL SELECT 'PREMIUM', 'advanced_ai_insights', TRUE UNION ALL SELECT 'PREMIUM', 'ai_food_scanner', TRUE UNION ALL SELECT 'PREMIUM', 'workout_songs', TRUE UNION ALL SELECT 'PREMIUM', 'advanced_health_reports', TRUE UNION ALL SELECT 'PREMIUM', 'ad_free', TRUE
) features ON features.code = plans.code;