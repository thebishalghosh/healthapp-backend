CREATE TABLE IF NOT EXISTS user_food_locations (
    user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    country VARCHAR(100) NOT NULL,
    country_code CHAR(2) NULL,
    state VARCHAR(100) NULL,
    city VARCHAR(100) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_food_locations_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT chk_user_food_locations_country CHECK (CHAR_LENGTH(TRIM(country)) BETWEEN 2 AND 100),
    CONSTRAINT chk_user_food_locations_country_code CHECK (country_code IS NULL OR country_code REGEXP '^[A-Z]{2}$'),
    INDEX idx_user_food_locations_region (country_code, state, city)
) ENGINE=InnoDB;

SET @food_recommendation_columns = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'ai_food_recommendations'
      AND column_name = 'regional_relevance'
);
SET @food_recommendation_sql = IF(
    @food_recommendation_columns = 0,
    'ALTER TABLE ai_food_recommendations ADD COLUMN regional_relevance VARCHAR(500) NULL AFTER reason',
    'DO 0'
);
PREPARE food_recommendation_stmt FROM @food_recommendation_sql;
EXECUTE food_recommendation_stmt;
DEALLOCATE PREPARE food_recommendation_stmt;

SET @food_recommendation_columns = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'ai_food_recommendations'
      AND column_name = 'preparation_guidance'
);
SET @food_recommendation_sql = IF(
    @food_recommendation_columns = 0,
    'ALTER TABLE ai_food_recommendations ADD COLUMN preparation_guidance VARCHAR(500) NULL AFTER regional_relevance',
    'DO 0'
);
PREPARE food_recommendation_stmt FROM @food_recommendation_sql;
EXECUTE food_recommendation_stmt;
DEALLOCATE PREPARE food_recommendation_stmt;

SET @food_recommendation_columns = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'ai_food_recommendations'
      AND column_name = 'nutrition_source'
);
SET @food_recommendation_sql = IF(
    @food_recommendation_columns = 0,
    'ALTER TABLE ai_food_recommendations ADD COLUMN nutrition_source VARCHAR(30) NOT NULL DEFAULT ''ai_estimate'' AFTER preparation_guidance',
    'DO 0'
);
PREPARE food_recommendation_stmt FROM @food_recommendation_sql;
EXECUTE food_recommendation_stmt;
DEALLOCATE PREPARE food_recommendation_stmt;
