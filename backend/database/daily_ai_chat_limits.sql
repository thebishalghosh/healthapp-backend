CREATE TABLE ai_chat_daily_usage (
    user_id BIGINT UNSIGNED NOT NULL,
    usage_date DATE NOT NULL,
    used INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (user_id, usage_date),
    INDEX idx_ai_chat_daily_usage_date (usage_date),

    CONSTRAINT fk_ai_chat_daily_usage_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE ai_chat_idempotency (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    idempotency_key VARBINARY(191) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    usage_date DATE NOT NULL,
    session_id BIGINT UNSIGNED NOT NULL,
    user_message_id BIGINT UNSIGNED NOT NULL,
    assistant_message_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_ai_chat_idempotency_user_key (user_id, idempotency_key),
    INDEX idx_ai_chat_idempotency_usage (user_id, usage_date),

    CONSTRAINT fk_ai_chat_idempotency_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_ai_chat_idempotency_session
        FOREIGN KEY (session_id)
        REFERENCES chat_sessions(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_ai_chat_idempotency_user_message
        FOREIGN KEY (user_message_id)
        REFERENCES chat_messages(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_ai_chat_idempotency_assistant_message
        FOREIGN KEY (assistant_message_id)
        REFERENCES chat_messages(id)
        ON DELETE CASCADE
) ENGINE=InnoDB;
