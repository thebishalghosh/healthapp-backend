CREATE TABLE IF NOT EXISTS user_health_profiles (
    user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    smoking_status ENUM('never', 'former', 'current') NOT NULL,
    cigarettes_per_day SMALLINT UNSIGNED NULL,
    years_smoked DECIMAL(5,2) NULL,
    alcohol_consumption ENUM('never', 'former', 'occasionally', 'weekly', 'frequently') NOT NULL,
    no_known_medical_conditions BOOLEAN NOT NULL DEFAULT FALSE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_health_profiles_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT chk_health_profile_smoking_details CHECK (
        (smoking_status = 'never' AND cigarettes_per_day IS NULL AND years_smoked IS NULL)
        OR
        (smoking_status IN ('former', 'current') AND cigarettes_per_day IS NOT NULL AND years_smoked IS NOT NULL)
    ),
    CONSTRAINT chk_health_profile_smoking_years CHECK (years_smoked IS NULL OR (years_smoked > 0 AND years_smoked <= 100)),
    CONSTRAINT chk_health_profile_cigarettes CHECK (cigarettes_per_day IS NULL OR (cigarettes_per_day BETWEEN 1 AND 200))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_medical_conditions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    condition_name ENUM(
        'diabetes', 'hypertension', 'hypotension', 'thyroid_disorders',
        'heart_disease', 'asthma', 'kidney_disease', 'liver_disease',
        'pcos_pcod', 'arthritis', 'high_cholesterol', 'other'
    ) NOT NULL,
    description VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_medical_conditions_profile
        FOREIGN KEY (user_id) REFERENCES user_health_profiles(user_id) ON DELETE CASCADE,
    CONSTRAINT uq_medical_condition_per_user UNIQUE (user_id, condition_name),
    CONSTRAINT chk_medical_condition_other_description CHECK (
        (condition_name = 'other' AND (description IS NULL OR CHAR_LENGTH(TRIM(description)) > 0))
        OR (condition_name <> 'other' AND description IS NULL)
    )
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_medical_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    history_type ENUM('surgery', 'hospitalization', 'major_illness') NOT NULL,
    description VARCHAR(1000) NOT NULL,
    occurred_at DATE NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_medical_history_profile
        FOREIGN KEY (user_id) REFERENCES user_health_profiles(user_id) ON DELETE CASCADE,
    INDEX idx_medical_history_user_type (user_id, history_type)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_medications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    medication_name VARCHAR(150) NOT NULL,
    dosage VARCHAR(100) NULL,
    frequency VARCHAR(100) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_medications_profile
        FOREIGN KEY (user_id) REFERENCES user_health_profiles(user_id) ON DELETE CASCADE,
    INDEX idx_medications_user (user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_allergies (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    allergen VARCHAR(150) NOT NULL,
    reaction VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_allergies_profile
        FOREIGN KEY (user_id) REFERENCES user_health_profiles(user_id) ON DELETE CASCADE,
    INDEX idx_allergies_user (user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_insurance (
    user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    has_coverage BOOLEAN NOT NULL,
    company_name VARCHAR(191) NULL,
    insurance_type ENUM('individual', 'family_floater', 'employer_provided', 'other') NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_insurance_profile
        FOREIGN KEY (user_id) REFERENCES user_health_profiles(user_id) ON DELETE CASCADE,
    CONSTRAINT chk_insurance_coverage_details CHECK (
        (has_coverage = TRUE AND company_name IS NOT NULL AND CHAR_LENGTH(TRIM(company_name)) > 0 AND insurance_type IS NOT NULL)
        OR (has_coverage = FALSE AND company_name IS NULL AND insurance_type IS NULL)
    )
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_health_consents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    consent_version VARCHAR(30) NOT NULL,
    consented_at DATETIME NOT NULL,
    withdrawn_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_health_consents_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_health_consents_user_current (user_id, withdrawn_at, id)
) ENGINE=InnoDB;
