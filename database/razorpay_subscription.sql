-- Additive, rerunnable Razorpay subscription integration migration.

SET @sql = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE subscription_plans ADD COLUMN razorpay_plan_id VARCHAR(191) NULL AFTER features',
    'DO 0') FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'subscription_plans' AND column_name = 'razorpay_plan_id');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE payments ADD COLUMN razorpay_subscription_id VARCHAR(191) NULL AFTER provider_order_id',
    'DO 0') FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'payments' AND column_name = 'razorpay_subscription_id');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
    'CREATE UNIQUE INDEX uq_payments_provider_payment ON payments (provider, provider_payment_id)',
    'DO 0') FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'payments' AND index_name = 'uq_payments_provider_payment');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS payment_webhook_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(100) NOT NULL,
    event_key VARCHAR(191) NOT NULL,
    event_name VARCHAR(100) NOT NULL,
    payload_hash CHAR(64) NOT NULL,
    processed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_webhook_provider_event (provider, event_key),
    INDEX idx_webhook_event_name (event_name)
) ENGINE=InnoDB;