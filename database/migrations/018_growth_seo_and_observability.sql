CREATE TABLE newsletter_subscriptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL,
    status ENUM('pending','active','unsubscribed') NOT NULL DEFAULT 'pending',
    consent_version VARCHAR(40) NOT NULL,
    consent_statement VARCHAR(500) NOT NULL,
    consent_proof_hash CHAR(64) NOT NULL,
    source VARCHAR(80) NOT NULL DEFAULT 'site_footer',
    ip_hash CHAR(64) NULL,
    user_agent_hash CHAR(64) NULL,
    confirmation_token_hash CHAR(64) NULL,
    unsubscribe_token_hash CHAR(64) NOT NULL,
    consented_at DATETIME NOT NULL,
    confirmed_at DATETIME NULL,
    unsubscribed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_newsletter_email (email),
    UNIQUE KEY uk_newsletter_confirmation_token (confirmation_token_hash),
    UNIQUE KEY uk_newsletter_unsubscribe_token (unsubscribe_token_hash),
    INDEX idx_newsletter_status_consented (status, consented_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE application_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id VARCHAR(64) NULL,
    level ENUM('debug','info','warning','error','critical') NOT NULL,
    channel VARCHAR(80) NOT NULL DEFAULT 'application',
    message VARCHAR(1000) NOT NULL,
    context JSON NULL,
    request_method VARCHAR(10) NULL,
    request_path VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_application_logs_level_created (level, created_at),
    INDEX idx_application_logs_request (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE service_health_checks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    service VARCHAR(80) NOT NULL,
    status ENUM('ok','degraded','down') NOT NULL,
    response_time_ms INT UNSIGNED NULL,
    details JSON NULL,
    checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_health_service_checked (service, checked_at),
    INDEX idx_health_status_checked (status, checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE system_alerts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    fingerprint CHAR(64) NOT NULL,
    severity ENUM('warning','critical') NOT NULL,
    source VARCHAR(80) NOT NULL,
    message VARCHAR(1000) NOT NULL,
    status ENUM('open','resolved') NOT NULL DEFAULT 'open',
    occurrence_count INT UNSIGNED NOT NULL DEFAULT 1,
    first_occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notified_at DATETIME NULL,
    resolved_at DATETIME NULL,
    UNIQUE KEY uk_system_alert_fingerprint (fingerprint),
    INDEX idx_system_alerts_status_severity (status, severity, last_occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE backup_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NULL,
    status ENUM('running','completed','failed') NOT NULL DEFAULT 'running',
    size_bytes BIGINT UNSIGNED NULL,
    checksum_sha256 CHAR(64) NULL,
    error_message VARCHAR(1000) NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    INDEX idx_backup_runs_status_started (status, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
