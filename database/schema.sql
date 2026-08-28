-- ============================================================
-- AI HEALTH & WELLNESS APP
-- DATABASE SCHEMA
-- Version: 1.0
-- Database: MySQL 8+
-- Engine: InnoDB
-- Charset: utf8mb4
-- ============================================================

CREATE DATABASE IF NOT EXISTS health_app
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE health_app;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;


-- ============================================================
-- 1. USERS & AUTHENTICATION
-- ============================================================

CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    uuid CHAR(36) NOT NULL UNIQUE,

    email VARCHAR(191) NOT NULL UNIQUE,
    phone VARCHAR(30) NULL UNIQUE,

    password_hash VARCHAR(255) NOT NULL,

    status ENUM(
        'active',
        'inactive',
        'suspended',
        'pending',
        'deleted'
    ) NOT NULL DEFAULT 'active',

    email_verified_at DATETIME NULL,
    phone_verified_at DATETIME NULL,

    last_login_at DATETIME NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,

    INDEX idx_users_status (status),
    INDEX idx_users_created_at (created_at),
    INDEX idx_users_deleted_at (deleted_at)
) ENGINE=InnoDB;


-- Authentication sessions / tokens
CREATE TABLE user_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id BIGINT UNSIGNED NOT NULL,

    token_hash CHAR(64) NOT NULL UNIQUE,

    device_id VARCHAR(191) NULL,
    device_name VARCHAR(191) NULL,
    device_type VARCHAR(50) NULL,

    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,

    expires_at DATETIME NOT NULL,
    last_used_at DATETIME NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_sessions_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    INDEX idx_sessions_user (user_id),
    INDEX idx_sessions_expiry (expires_at)
) ENGINE=InnoDB;


-- Password reset tokens
CREATE TABLE password_resets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id BIGINT UNSIGNED NOT NULL,

    token_hash CHAR(64) NOT NULL UNIQUE,

    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_password_resets_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    INDEX idx_password_resets_user (user_id),
    INDEX idx_password_resets_expiry (expires_at)
) ENGINE=InnoDB;


-- ============================================================
-- 2. USER PROFILE
-- ============================================================

CREATE TABLE user_profiles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id BIGINT UNSIGNED NOT NULL UNIQUE,

    first_name VARCHAR(100) NULL,
    last_name VARCHAR(100) NULL,

    date_of_birth DATE NULL,

    gender VARCHAR(50) NULL,

    height_cm DECIMAL(6,2) NULL,
    weight_kg DECIMAL(7,2) NULL,

    activity_level ENUM(
        'sedentary',
        'light',
        'moderate',
        'active',
        'very_active'
    ) NULL,

    fitness_goal ENUM(
        'weight_loss',
        'weight_gain',
        'muscle_gain',
        'maintenance',
        'general_wellness'
    ) NULL,

    dietary_preference VARCHAR(100) NULL,

    profile_image VARCHAR(500) NULL,

    bio VARCHAR(500) NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_profiles_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB;


-- ============================================================
-- 3. USER SETTINGS
-- ============================================================

CREATE TABLE user_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id BIGINT UNSIGNED NOT NULL UNIQUE,

    language VARCHAR(20) NOT NULL DEFAULT 'en',

    theme ENUM(
        'light',
        'dark',
        'system'
    ) NOT NULL DEFAULT 'system',

    timezone VARCHAR(100) NOT NULL DEFAULT 'UTC',

    notification_enabled BOOLEAN NOT NULL DEFAULT TRUE,

    email_notification_enabled BOOLEAN NOT NULL DEFAULT TRUE,

    push_notification_enabled BOOLEAN NOT NULL DEFAULT TRUE,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_settings_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB;


-- ============================================================
-- 4. USER FOOD PREFERENCES
-- ============================================================

CREATE TABLE food_preferences (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id BIGINT UNSIGNED NOT NULL,

    preference_type ENUM(
        'favorite',
        'disliked',
        'allergy',
        'diet',
        'restriction'
    ) NOT NULL,

    food_name VARCHAR(191) NOT NULL,

    notes VARCHAR(500) NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_food_preferences_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    INDEX idx_food_preferences_user (user_id),
    INDEX idx_food_preferences_type (preference_type)
) ENGINE=InnoDB;


-- ============================================================
-- 5. USER LOCATION / REGIONAL PREFERENCE
-- ============================================================

CREATE TABLE user_locations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id BIGINT UNSIGNED NOT NULL,

    country VARCHAR(100) NULL,
    state VARCHAR(100) NULL,
    city VARCHAR(100) NULL,

    latitude DECIMAL(10,7) NULL,
    longitude DECIMAL(10,7) NULL,

    is_primary BOOLEAN NOT NULL DEFAULT TRUE,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_locations_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    INDEX idx_locations_user (user_id),
    INDEX idx_locations_city (city),
    INDEX idx_locations_state (state)
) ENGINE=InnoDB;


-- ============================================================
-- 6. NUTRITION REQUIREMENTS
-- ============================================================

CREATE TABLE nutrition_requirements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id BIGINT UNSIGNED NOT NULL,

    calculated_date DATE NOT NULL,

    calories_target DECIMAL(8,2) NULL,

    protein_g DECIMAL(8,2) NULL,
    carbohydrates_g DECIMAL(8,2) NULL,
    fat_g DECIMAL(8,2) NULL,
    fiber_g DECIMAL(8,2) NULL,

    water_ml DECIMAL(8,2) NULL,

    calculation_method VARCHAR(100) NULL,

    calculation_version VARCHAR(50) NULL,

    source ENUM(
        'system',
        'ai',
        'manual'
    ) NOT NULL DEFAULT 'system',

    metadata JSON NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_nutrition_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    INDEX idx_nutrition_user_date (user_id, calculated_date),
    INDEX idx_nutrition_date (calculated_date)
) ENGINE=InnoDB;


-- ============================================================
-- 7. FOOD DATABASE
-- ============================================================

CREATE TABLE foods (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    name VARCHAR(191) NOT NULL,

    category VARCHAR(100) NULL,

    cuisine VARCHAR(100) NULL,

    serving_size DECIMAL(10,2) NULL,
    serving_unit VARCHAR(50) NULL,

    calories DECIMAL(10,2) NULL,
    protein_g DECIMAL(10,2) NULL,
    carbohydrates_g DECIMAL(10,2) NULL,
    fat_g DECIMAL(10,2) NULL,
    fiber_g DECIMAL(10,2) NULL,

    sugar_g DECIMAL(10,2) NULL,
    sodium_mg DECIMAL(10,2) NULL,

    vitamins JSON NULL,
    minerals JSON NULL,

    source VARCHAR(100) NULL,

    is_active BOOLEAN NOT NULL DEFAULT TRUE,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_foods_name (name),
    INDEX idx_foods_category (category),
    INDEX idx_foods_cuisine (cuisine)
) ENGINE=InnoDB;


-- ============================================================
-- 8. AI MEAL PLANS
-- ============================================================

CREATE TABLE meal_plans (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id BIGINT UNSIGNED NOT NULL,

    plan_date DATE NOT NULL,

    title VARCHAR(191) NULL,

    daily_calories DECIMAL(10,2) NULL,
    daily_protein_g DECIMAL(10,2) NULL,
    daily_carbs_g DECIMAL(10,2) NULL,
    daily_fat_g DECIMAL(10,2) NULL,

    generated_by ENUM(
        'ai',
        'system',
        'manual'
    ) NOT NULL DEFAULT 'ai',

    ai_model VARCHAR(100) NULL,

    prompt_version VARCHAR(50) NULL,

    metadata JSON NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_meal_plans_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    INDEX idx_meal_plans_user_date (user_id, plan_date)
) ENGINE=InnoDB;


CREATE TABLE meal_plan_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    meal_plan_id BIGINT UNSIGNED NOT NULL,

    food_id BIGINT UNSIGNED NULL,

    meal_type ENUM(
        'breakfast',
        'morning_snack',
        'lunch',
        'evening_snack',
        'dinner',
        'other'
    ) NOT NULL,

    food_name VARCHAR(191) NOT NULL,

    quantity DECIMAL(10,2) NULL,
    unit VARCHAR(50) NULL,

    calories DECIMAL(10,2) NULL,
    protein_g DECIMAL(10,2) NULL,
    carbohydrates_g DECIMAL(10,2) NULL,
    fat_g DECIMAL(10,2) NULL,
    fiber_g DECIMAL(10,2) NULL,

    instructions TEXT NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_meal_items_plan
        FOREIGN KEY (meal_plan_id)
        REFERENCES meal_plans(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_meal_items_food
        FOREIGN KEY (food_id)
        REFERENCES foods(id)
        ON DELETE SET NULL,

    INDEX idx_meal_items_plan (meal_plan_id),
    INDEX idx_meal_items_type (meal_type)
) ENGINE=InnoDB;


-- ============================================================
-- 9. MEAL LOGS
-- ============================================================

CREATE TABLE meal_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id BIGINT UNSIGNED NOT NULL,

    food_id BIGINT UNSIGNED NULL,

    meal_type ENUM(
        'breakfast',
        'morning_snack',
        'snack',
        'lunch',
        'evening_snack',
        'dinner',
        'other'
    ) NOT NULL,

    food_name VARCHAR(191) NOT NULL,

    quantity DECIMAL(10,2) NULL,
    unit VARCHAR(50) NULL,

    calories DECIMAL(10,2) NULL,
    protein_g DECIMAL(10,2) NULL,
    carbohydrates_g DECIMAL(10,2) NULL,
    fat_g DECIMAL(10,2) NULL,
    fiber_g DECIMAL(10,2) NULL,

    consumed_at DATETIME NOT NULL,

    source ENUM(
        'manual',
        'meal_plan',
        'food_scan'
    ) NOT NULL DEFAULT 'manual',

    metadata JSON NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_meal_logs_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_meal_logs_food
        FOREIGN KEY (food_id)
        REFERENCES foods(id)
        ON DELETE SET NULL,

    INDEX idx_meal_logs_user_date (user_id, consumed_at),
    INDEX idx_meal_logs_type (meal_type)
) ENGINE=InnoDB;


-- ============================================================
-- 10. AI FOOD SCANNER
-- ============================================================

CREATE TABLE food_scans (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id BIGINT UNSIGNED NOT NULL,

    image_path VARCHAR(500) NULL,

    detected_food_name VARCHAR(191) NULL,

    estimated_serving DECIMAL(10,2) NULL,
    serving_unit VARCHAR(50) NULL,

    calories DECIMAL(10,2) NULL,
    protein_g DECIMAL(10,2) NULL,
    carbohydrates_g DECIMAL(10,2) NULL,
    fat_g DECIMAL(10,2) NULL,
    fiber_g DECIMAL(10,2) NULL,

    confidence DECIMAL(5,2) NULL,

    ai_model VARCHAR(100) NULL,

    raw_response JSON NULL,

    scan_status ENUM(
        'pending',
        'completed',
        'failed'
    ) NOT NULL DEFAULT 'pending',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_food_scans_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    INDEX idx_food_scans_user_date (user_id, created_at),
    INDEX idx_food_scans_status (scan_status)
) ENGINE=InnoDB;


-- ============================================================
-- 11. WATER TRACKING
-- ============================================================

CREATE TABLE water_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id BIGINT UNSIGNED NOT NULL,

    amount_ml DECIMAL(10,2) NOT NULL,

    consumed_at DATETIME NOT NULL,

    source ENUM(
        'manual',
        'reminder'
    ) NOT NULL DEFAULT 'manual',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_water_logs_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    INDEX idx_water_logs_user_date (user_id, consumed_at)
) ENGINE=InnoDB;


-- ============================================================
-- 12. WORKOUT TRACKING
-- ============================================================

CREATE TABLE workout_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id BIGINT UNSIGNED NOT NULL,

    workout_name VARCHAR(191) NULL,
    workout_type VARCHAR(100) NULL,

    duration_minutes INT UNSIGNED NULL,

    calories_burned DECIMAL(10,2) NULL,

    workout_date DATETIME NOT NULL,

    notes TEXT NULL,

    source ENUM(
        'manual',
        'planned',
        'reminder'
    ) NOT NULL DEFAULT 'manual',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_workout_logs_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    INDEX idx_workout_logs_user_date (user_id, workout_date)
) ENGINE=InnoDB;


-- ============================================================
-- 13. SLEEP TRACKING
-- ============================================================

CREATE TABLE sleep_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id BIGINT UNSIGNED NOT NULL,

    sleep_start DATETIME NOT NULL,
    sleep_end DATETIME NOT NULL,

    duration_minutes INT UNSIGNED NULL,

    sleep_quality TINYINT UNSIGNED NULL,

    notes TEXT NULL,

    source ENUM(
        'manual',
        'device'
    ) NOT NULL DEFAULT 'manual',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_sleep_logs_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    INDEX idx_sleep_logs_user_date (user_id, sleep_start)
) ENGINE=InnoDB;


-- ============================================================
-- 14. REMINDERS
-- ============================================================

CREATE TABLE reminders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id BIGINT UNSIGNED NOT NULL,

    reminder_type ENUM(
        'water',
        'meal',
        'workout',
        'sleep',
        'custom'
    ) NOT NULL,

    title VARCHAR(191) NOT NULL,

    message VARCHAR(500) NULL,

    reminder_time TIME NULL,

    start_date DATE NULL,
    end_date DATE NULL,

    repeat_type ENUM(
        'once',
        'daily',
        'weekly',
        'custom'
    ) NOT NULL DEFAULT 'daily',

    repeat_days JSON NULL,

    is_enabled BOOLEAN NOT NULL DEFAULT TRUE,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_reminders_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    INDEX idx_reminders_user (user_id),
    INDEX idx_reminders_enabled (is_enabled),
    INDEX idx_reminders_time (reminder_time)
) ENGINE=InnoDB;


-- ============================================================
-- 15. GOALS
-- ============================================================

CREATE TABLE goals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id BIGINT UNSIGNED NOT NULL,

    goal_type ENUM(
        'daily',
        'weekly',
        'monthly'
    ) NOT NULL,

    category ENUM(
        'nutrition',
        'water',
        'meal',
        'workout',
        'sleep',
        'general'
    ) NOT NULL,

    title VARCHAR(191) NOT NULL,

    description VARCHAR(500) NULL,

    target_value DECIMAL(12,2) NULL,
    current_value DECIMAL(12,2) NOT NULL DEFAULT 0,

    unit VARCHAR(50) NULL,

    start_date DATE NOT NULL,
    end_date DATE NOT NULL,

    status ENUM(
        'active',
        'completed',
        'failed',
        'cancelled'
    ) NOT NULL DEFAULT 'active',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_goals_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    INDEX idx_goals_user_type (user_id, goal_type),
    INDEX idx_goals_dates (start_date, end_date),
    INDEX idx_goals_status (status)
) ENGINE=InnoDB;


-- ============================================================
-- 16. STREAKS
-- ============================================================

CREATE TABLE user_streaks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id BIGINT UNSIGNED NOT NULL,

    streak_type VARCHAR(100) NOT NULL,

    current_streak INT UNSIGNED NOT NULL DEFAULT 0,
    longest_streak INT UNSIGNED NOT NULL DEFAULT 0,

    last_activity_date DATE NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_streaks_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    UNIQUE KEY uq_user_streak_type (user_id, streak_type)
) ENGINE=InnoDB;


-- ============================================================
-- 17. ACHIEVEMENTS
-- ============================================================

CREATE TABLE achievements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    code VARCHAR(100) NOT NULL UNIQUE,

    title VARCHAR(191) NOT NULL,

    description VARCHAR(500) NULL,

    icon VARCHAR(500) NULL,

    category VARCHAR(100) NULL,

    requirement_type VARCHAR(100) NULL,

    requirement_value DECIMAL(12,2) NULL,

    reward_coins INT UNSIGNED NOT NULL DEFAULT 0,

    is_active BOOLEAN NOT NULL DEFAULT TRUE,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;


CREATE TABLE user_achievements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id BIGINT UNSIGNED NOT NULL,

    achievement_id BIGINT UNSIGNED NOT NULL,

    achieved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    reward_claimed BOOLEAN NOT NULL DEFAULT FALSE,

    CONSTRAINT fk_user_achievements_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_user_achievements_achievement
        FOREIGN KEY (achievement_id)
        REFERENCES achievements(id)
        ON DELETE CASCADE,

    UNIQUE KEY uq_user_achievement (user_id, achievement_id),

    INDEX idx_user_achievements_user (user_id),
    INDEX idx_user_achievements_date (achieved_at)
) ENGINE=InnoDB;


-- ============================================================
-- 18. WEEKLY HEALTH REPORTS
-- ============================================================

CREATE TABLE health_reports (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id BIGINT UNSIGNED NOT NULL,

    report_type ENUM(
        'weekly',
        'monthly'
    ) NOT NULL DEFAULT 'weekly',

    period_start DATE NOT NULL,
    period_end DATE NOT NULL,

    calories_summary JSON NULL,
    nutrition_summary JSON NULL,
    water_summary JSON NULL,
    meal_summary JSON NULL,
    workout_summary JSON NULL,
    sleep_summary JSON NULL,
    goal_summary JSON NULL,
    streak_summary JSON NULL,
    achievement_summary JSON NULL,

    overall_score DECIMAL(5,2) NULL,

    ai_summary TEXT NULL,
    ai_recommendations JSON NULL,

    report_status ENUM(
        'pending',
        'generated',
        'failed'
    ) NOT NULL DEFAULT 'pending',

    generated_at DATETIME NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_health_reports_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    UNIQUE KEY uq_health_report_period (
        user_id,
        report_type,
        period_start,
        period_end
    ),

    INDEX idx_health_reports_user (user_id),
    INDEX idx_health_reports_period (period_start, period_end)
) ENGINE=InnoDB;


-- ============================================================
-- 19. AI CHATBOT
-- ============================================================

CREATE TABLE chat_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id BIGINT UNSIGNED NOT NULL,

    title VARCHAR(191) NULL,

    status ENUM(
        'active',
        'archived'
    ) NOT NULL DEFAULT 'active',

    ai_model VARCHAR(100) NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_chat_sessions_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    INDEX idx_chat_sessions_user (user_id),
    INDEX idx_chat_sessions_updated (updated_at)
) ENGINE=InnoDB;


CREATE TABLE chat_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    session_id BIGINT UNSIGNED NOT NULL,

    user_id BIGINT UNSIGNED NOT NULL,

    role ENUM(
        'user',
        'assistant',
        'system'
    ) NOT NULL,

    message LONGTEXT NOT NULL,

    ai_model VARCHAR(100) NULL,

    input_tokens INT UNSIGNED NULL,
    output_tokens INT UNSIGNED NULL,

    metadata JSON NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_chat_messages_session
        FOREIGN KEY (session_id)
        REFERENCES chat_sessions(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_chat_messages_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    INDEX idx_chat_messages_session (session_id, created_at),
    INDEX idx_chat_messages_user (user_id, created_at)
) ENGINE=InnoDB;


-- ============================================================
-- 20. SUBSCRIPTION PLANS
-- ============================================================

CREATE TABLE subscription_plans (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    code VARCHAR(100) NOT NULL UNIQUE,

    name VARCHAR(191) NOT NULL,

    description VARCHAR(500) NULL,

    billing_interval ENUM(
        'monthly',
        'quarterly',
        'yearly',
        'lifetime'
    ) NOT NULL,

    price DECIMAL(12,2) NOT NULL DEFAULT 0,
    currency VARCHAR(10) NOT NULL DEFAULT 'INR',

    trial_days INT UNSIGNED NOT NULL DEFAULT 0,

    features JSON NULL,

    is_active BOOLEAN NOT NULL DEFAULT TRUE,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;


-- ============================================================
-- 21. USER SUBSCRIPTIONS
-- ============================================================

CREATE TABLE subscriptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id BIGINT UNSIGNED NOT NULL,

    plan_id BIGINT UNSIGNED NOT NULL,

    provider VARCHAR(100) NULL,

    provider_subscription_id VARCHAR(191) NULL,

    status ENUM(
        'trial',
        'active',
        'paused',
        'cancelled',
        'expired',
        'pending'
    ) NOT NULL DEFAULT 'pending',

    started_at DATETIME NULL,
    current_period_start DATETIME NULL,
    current_period_end DATETIME NULL,

    cancelled_at DATETIME NULL,

    auto_renew BOOLEAN NOT NULL DEFAULT TRUE,

    metadata JSON NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_subscriptions_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_subscriptions_plan
        FOREIGN KEY (plan_id)
        REFERENCES subscription_plans(id)
        ON DELETE RESTRICT,

    INDEX idx_subscriptions_user (user_id),
    INDEX idx_subscriptions_status (status),
    INDEX idx_subscriptions_period (current_period_end),

    UNIQUE KEY uq_provider_subscription (
        provider,
        provider_subscription_id
    )
) ENGINE=InnoDB;


-- ============================================================
-- 22. PAYMENTS
-- ============================================================

CREATE TABLE payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id BIGINT UNSIGNED NOT NULL,

    subscription_id BIGINT UNSIGNED NULL,

    provider VARCHAR(100) NOT NULL,

    provider_payment_id VARCHAR(191) NULL,
    provider_order_id VARCHAR(191) NULL,

    amount DECIMAL(12,2) NOT NULL,
    currency VARCHAR(10) NOT NULL DEFAULT 'INR',

    status ENUM(
        'pending',
        'success',
        'failed',
        'refunded',
        'cancelled'
    ) NOT NULL DEFAULT 'pending',

    payment_method VARCHAR(100) NULL,

    metadata JSON NULL,

    paid_at DATETIME NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_payments_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_payments_subscription
        FOREIGN KEY (subscription_id)
        REFERENCES subscriptions(id)
        ON DELETE SET NULL,

    INDEX idx_payments_user (user_id),
    INDEX idx_payments_status (status),
    INDEX idx_payments_provider_payment (provider, provider_payment_id)
) ENGINE=InnoDB;


-- ============================================================
-- 23. ADVERTISEMENT CONFIGURATION
-- ============================================================

CREATE TABLE ad_configurations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    platform ENUM(
        'android',
        'ios',
        'all'
    ) NOT NULL DEFAULT 'all',

    ad_network VARCHAR(100) NOT NULL,

    ad_type ENUM(
        'banner',
        'interstitial',
        'rewarded',
        'native'
    ) NOT NULL,

    placement VARCHAR(100) NOT NULL,

    ad_unit_id VARCHAR(255) NOT NULL,

    is_active BOOLEAN NOT NULL DEFAULT TRUE,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_ads_platform_type (platform, ad_type),
    INDEX idx_ads_active (is_active)
) ENGINE=InnoDB;


-- ============================================================
-- 24. REFERRAL PROGRAM
-- ============================================================

CREATE TABLE referral_codes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id BIGINT UNSIGNED NOT NULL UNIQUE,

    code VARCHAR(50) NOT NULL UNIQUE,

    is_active BOOLEAN NOT NULL DEFAULT TRUE,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_referral_codes_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB;


CREATE TABLE referrals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    referrer_user_id BIGINT UNSIGNED NOT NULL,

    referred_user_id BIGINT UNSIGNED NOT NULL,

    referral_code_id BIGINT UNSIGNED NULL,

    status ENUM(
        'pending',
        'qualified',
        'rewarded',
        'rejected'
    ) NOT NULL DEFAULT 'pending',

    qualified_at DATETIME NULL,
    rewarded_at DATETIME NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_referrals_referrer
        FOREIGN KEY (referrer_user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_referrals_referred
        FOREIGN KEY (referred_user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_referrals_code
        FOREIGN KEY (referral_code_id)
        REFERENCES referral_codes(id)
        ON DELETE SET NULL,

    UNIQUE KEY uq_referred_user (referred_user_id),

    INDEX idx_referrals_referrer (referrer_user_id),
    INDEX idx_referrals_status (status)
) ENGINE=InnoDB;


-- ============================================================
-- 25. COINS / REWARD SYSTEM
-- ============================================================

CREATE TABLE user_coin_accounts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id BIGINT UNSIGNED NOT NULL UNIQUE,

    balance BIGINT UNSIGNED NOT NULL DEFAULT 0,

    lifetime_earned BIGINT UNSIGNED NOT NULL DEFAULT 0,
    lifetime_spent BIGINT UNSIGNED NOT NULL DEFAULT 0,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_coin_accounts_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB;


CREATE TABLE coin_transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id BIGINT UNSIGNED NOT NULL,

    transaction_type ENUM(
        'earned',
        'spent',
        'expired',
        'adjustment',
        'refund'
    ) NOT NULL,

    source_type VARCHAR(100) NULL,

    source_id BIGINT UNSIGNED NULL,

    amount BIGINT NOT NULL,

    balance_after BIGINT UNSIGNED NOT NULL,

    description VARCHAR(500) NULL,

    metadata JSON NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_coin_transactions_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    INDEX idx_coin_transactions_user_date (user_id, created_at),
    INDEX idx_coin_transactions_source (source_type, source_id)
) ENGINE=InnoDB;


-- ============================================================
-- 26. DEVICE / PUSH NOTIFICATIONS
-- ============================================================

CREATE TABLE user_devices (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id BIGINT UNSIGNED NOT NULL,

    device_token VARCHAR(500) NOT NULL,

    platform ENUM(
        'android',
        'ios',
        'web',
        'other'
    ) NOT NULL,

    device_id VARCHAR(191) NULL,

    app_version VARCHAR(50) NULL,

    is_active BOOLEAN NOT NULL DEFAULT TRUE,

    last_seen_at DATETIME NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_devices_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    UNIQUE KEY uq_device_token (device_token),

    INDEX idx_devices_user (user_id),
    INDEX idx_devices_active (is_active)
) ENGINE=InnoDB;


CREATE TABLE notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id BIGINT UNSIGNED NOT NULL,

    notification_type VARCHAR(100) NOT NULL,

    title VARCHAR(191) NOT NULL,
    message VARCHAR(500) NOT NULL,

    data JSON NULL,

    is_read BOOLEAN NOT NULL DEFAULT FALSE,

    sent_at DATETIME NULL,
    read_at DATETIME NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_notifications_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    INDEX idx_notifications_user (user_id, created_at),
    INDEX idx_notifications_unread (user_id, is_read)
) ENGINE=InnoDB;


-- ============================================================
-- 27. FAQ
-- ============================================================

CREATE TABLE faqs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    category VARCHAR(100) NULL,

    question VARCHAR(500) NOT NULL,

    answer TEXT NOT NULL,

    sort_order INT NOT NULL DEFAULT 0,

    is_active BOOLEAN NOT NULL DEFAULT TRUE,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_faq_category (category),
    INDEX idx_faq_active_sort (is_active, sort_order)
) ENGINE=InnoDB;


-- ============================================================
-- 28. SUPPORT TICKETS
-- ============================================================

CREATE TABLE support_tickets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id BIGINT UNSIGNED NOT NULL,

    ticket_number VARCHAR(50) NOT NULL UNIQUE,

    category ENUM(
        'general',
        'technical',
        'billing',
        'subscription',
        'account',
        'other'
    ) NOT NULL DEFAULT 'general',

    subject VARCHAR(191) NOT NULL,

    status ENUM(
        'open',
        'in_progress',
        'resolved',
        'closed'
    ) NOT NULL DEFAULT 'open',

    priority ENUM(
        'low',
        'normal',
        'high',
        'urgent'
    ) NOT NULL DEFAULT 'normal',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    closed_at DATETIME NULL,

    CONSTRAINT fk_support_tickets_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    INDEX idx_support_tickets_user (user_id),
    INDEX idx_support_tickets_status (status)
) ENGINE=InnoDB;


CREATE TABLE support_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    ticket_id BIGINT UNSIGNED NOT NULL,

    user_id BIGINT UNSIGNED NULL,

    sender_type ENUM(
        'user',
        'support',
        'system'
    ) NOT NULL,

    message TEXT NOT NULL,

    attachment VARCHAR(500) NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_support_messages_ticket
        FOREIGN KEY (ticket_id)
        REFERENCES support_tickets(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_support_messages_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE SET NULL,

    INDEX idx_support_messages_ticket (ticket_id, created_at)
) ENGINE=InnoDB;


-- ============================================================
-- 29. BUG REPORTS
-- ============================================================

CREATE TABLE bug_reports (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id BIGINT UNSIGNED NOT NULL,

    title VARCHAR(191) NOT NULL,

    description TEXT NOT NULL,

    severity ENUM(
        'low',
        'medium',
        'high',
        'critical'
    ) NOT NULL DEFAULT 'medium',

    platform VARCHAR(50) NULL,
    app_version VARCHAR(50) NULL,
    device_info JSON NULL,

    attachment VARCHAR(500) NULL,

    status ENUM(
        'open',
        'investigating',
        'fixed',
        'closed'
    ) NOT NULL DEFAULT 'open',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_bug_reports_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    INDEX idx_bug_reports_user (user_id),
    INDEX idx_bug_reports_status (status)
) ENGINE=InnoDB;


-- ============================================================
-- 30. FEATURE REQUESTS
-- ============================================================

CREATE TABLE feature_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id BIGINT UNSIGNED NOT NULL,

    title VARCHAR(191) NOT NULL,

    description TEXT NOT NULL,

    status ENUM(
        'submitted',
        'under_review',
        'planned',
        'in_progress',
        'completed',
        'rejected'
    ) NOT NULL DEFAULT 'submitted',

    votes INT UNSIGNED NOT NULL DEFAULT 0,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_feature_requests_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    INDEX idx_feature_requests_status (status),
    INDEX idx_feature_requests_votes (votes)
) ENGINE=InnoDB;


-- ============================================================
-- 31. REVIEWS
-- ============================================================

CREATE TABLE reviews (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id BIGINT UNSIGNED NOT NULL,

    rating TINYINT UNSIGNED NOT NULL,

    review_text TEXT NULL,

    status ENUM(
        'pending',
        'approved',
        'rejected'
    ) NOT NULL DEFAULT 'pending',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT chk_review_rating
        CHECK (rating BETWEEN 1 AND 5),

    CONSTRAINT fk_reviews_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    INDEX idx_reviews_rating (rating),
    INDEX idx_reviews_status (status)
) ENGINE=InnoDB;


-- ============================================================
-- 32. SYSTEM CONFIGURATION
-- ============================================================

CREATE TABLE system_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    setting_key VARCHAR(191) NOT NULL UNIQUE,

    setting_value TEXT NULL,

    setting_type ENUM(
        'string',
        'integer',
        'boolean',
        'json'
    ) NOT NULL DEFAULT 'string',

    description VARCHAR(500) NULL,

    is_public BOOLEAN NOT NULL DEFAULT FALSE,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;


-- ============================================================
-- 33. AI USAGE TRACKING
-- ============================================================

CREATE TABLE ai_usage_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id BIGINT UNSIGNED NULL,

    feature_type ENUM(
        'meal_planning',
        'food_scanner',
        'chatbot',
        'health_report',
        'other'
    ) NOT NULL,

    ai_provider VARCHAR(100) NULL,
    ai_model VARCHAR(100) NULL,

    request_id VARCHAR(191) NULL,

    input_tokens INT UNSIGNED NULL,
    output_tokens INT UNSIGNED NULL,

    estimated_cost DECIMAL(12,6) NULL,
    currency VARCHAR(10) DEFAULT 'USD',

    status ENUM(
        'success',
        'failed'
    ) NOT NULL,

    error_message TEXT NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_ai_usage_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE SET NULL,

    INDEX idx_ai_usage_user_date (user_id, created_at),
    INDEX idx_ai_usage_feature (feature_type, created_at),
    INDEX idx_ai_usage_request (request_id)
) ENGINE=InnoDB;


-- ============================================================
-- 34. AUDIT LOG
-- ============================================================

CREATE TABLE audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id BIGINT UNSIGNED NULL,

    action VARCHAR(100) NOT NULL,

    entity_type VARCHAR(100) NULL,
    entity_id BIGINT UNSIGNED NULL,

    ip_address VARCHAR(45) NULL,

    user_agent TEXT NULL,

    metadata JSON NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_audit_logs_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE SET NULL,

    INDEX idx_audit_user_date (user_id, created_at),
    INDEX idx_audit_entity (entity_type, entity_id),
    INDEX idx_audit_action (action)
) ENGINE=InnoDB;


-- ============================================================
-- 35. INITIAL SUBSCRIPTION PLANS
-- ============================================================

INSERT INTO subscription_plans
(
    code,
    name,
    description,
    billing_interval,
    price,
    currency,
    trial_days,
    features
)
VALUES
(
    'free',
    'Free',
    'Free health and wellness plan',
    'monthly',
    0.00,
    'INR',
    0,
    JSON_OBJECT(
        'ai_food_scanner', true,
        'ai_meal_planning', true,
        'smart_reminders', true,
        'goals', true,
        'streaks', true,
        'achievements', true,
        'weekly_reports', true,
        'workout_music', true,
        'advertisements', true,
        'ai_chatbot', false
    )
),
(
    'premium_monthly',
    'Premium Monthly',
    'Premium monthly subscription',
    'monthly',
    0.00,
    'INR',
    0,
    JSON_OBJECT(
        'ai_food_scanner', true,
        'ai_meal_planning', true,
        'smart_reminders', true,
        'goals', true,
        'streaks', true,
        'achievements', true,
        'weekly_reports', true,
        'workout_music', true,
        'advertisements', false,
        'ai_chatbot', true
    )
);


-- ============================================================
-- 36. INITIAL ACHIEVEMENTS
-- ============================================================

INSERT INTO achievements
(
    code,
    title,
    description,
    category,
    requirement_type,
    requirement_value,
    reward_coins
)
VALUES
(
    'FIRST_GOAL',
    'First Goal',
    'Complete your first goal',
    'goals',
    'goal_completed',
    1,
    10
),
(
    'THREE_DAY_STREAK',
    '3 Day Streak',
    'Maintain a 3 day health streak',
    'streak',
    'streak_days',
    3,
    10
),
(
    'SEVEN_DAY_STREAK',
    '7 Day Streak',
    'Maintain a 7 day health streak',
    'streak',
    'streak_days',
    7,
    25
),
(
    'THIRTY_DAY_STREAK',
    '30 Day Streak',
    'Maintain a 30 day health streak',
    'streak',
    'streak_days',
    30,
    100
),
(
    'FIRST_FOOD_SCAN',
    'Food Explorer',
    'Complete your first AI food scan',
    'food',
    'food_scan',
    1,
    10
),
(
    'FIRST_MEAL_PLAN',
    'Meal Planner',
    'Generate your first AI meal plan',
    'meal',
    'meal_plan',
    1,
    10
);


-- ============================================================
-- 37. INITIAL SYSTEM SETTINGS
-- ============================================================

INSERT INTO system_settings
(
    setting_key,
    setting_value,
    setting_type,
    description,
    is_public
)
VALUES
(
    'app_name',
    'AI Health & Wellness',
    'string',
    'Application name',
    TRUE
),
(
    'api_version',
    'v1',
    'string',
    'Current API version',
    TRUE
),
(
    'maintenance_mode',
    'false',
    'boolean',
    'Enable or disable maintenance mode',
    TRUE
),
(
    'default_water_target_ml',
    '2500',
    'integer',
    'Default daily water target',
    TRUE
);


-- ============================================================
-- 38. RESTORE FOREIGN KEY CHECKS
-- ============================================================

SET FOREIGN_KEY_CHECKS = 1;


-- ============================================================
-- END OF SCHEMA
-- ============================================================