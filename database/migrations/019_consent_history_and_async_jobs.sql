CREATE TABLE newsletter_consent_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subscription_id BIGINT UNSIGNED NOT NULL,
    event_key CHAR(64) NOT NULL,
    event_type ENUM('consent_requested','consent_confirmed','consent_withdrawn') NOT NULL,
    email_hash CHAR(64) NOT NULL,
    consent_version VARCHAR(40) NOT NULL,
    consent_statement VARCHAR(500) NOT NULL,
    consent_proof_hash CHAR(64) NOT NULL,
    source VARCHAR(80) NOT NULL,
    ip_hash CHAR(64) NULL,
    user_agent_hash CHAR(64) NULL,
    metadata JSON NULL,
    occurred_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_newsletter_consent_event_key (event_key),
    INDEX idx_newsletter_consent_subscription_time (subscription_id, occurred_at),
    CONSTRAINT fk_newsletter_consent_subscription FOREIGN KEY (subscription_id) REFERENCES newsletter_subscriptions(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO newsletter_consent_events(subscription_id,event_key,event_type,email_hash,consent_version,consent_statement,consent_proof_hash,source,ip_hash,user_agent_hash,metadata,occurred_at)
SELECT id,SHA2(CONCAT('migration-019|',id,'|',status,'|',consented_at),256),CASE status WHEN 'active' THEN 'consent_confirmed' WHEN 'unsubscribed' THEN 'consent_withdrawn' ELSE 'consent_requested' END,SHA2(LOWER(email),256),consent_version,consent_statement,consent_proof_hash,source,ip_hash,user_agent_hash,JSON_OBJECT('origin','migration_019','original_status',status),COALESCE(unsubscribed_at,confirmed_at,consented_at)
FROM newsletter_subscriptions;

CREATE TRIGGER trg_newsletter_consent_events_no_update BEFORE UPDATE ON newsletter_consent_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Newsletter consent events are immutable';
CREATE TRIGGER trg_newsletter_consent_events_no_delete BEFORE DELETE ON newsletter_consent_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Newsletter consent events are immutable';

ALTER TABLE mail_deliveries
    ADD COLUMN unique_key VARCHAR(191) NULL AFTER id,
    ADD COLUMN message_body MEDIUMTEXT NULL AFTER subject,
    ADD UNIQUE KEY uk_mail_deliveries_unique_key (unique_key);

CREATE TABLE async_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    queue VARCHAR(60) NOT NULL DEFAULT 'default',
    job_type VARCHAR(100) NOT NULL,
    unique_key VARCHAR(191) NULL,
    payload JSON NOT NULL,
    status ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
    priority SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 5,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reserved_at DATETIME NULL,
    reserved_by VARCHAR(100) NULL,
    completed_at DATETIME NULL,
    failed_at DATETIME NULL,
    last_error VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_async_jobs_unique_key (unique_key),
    INDEX idx_async_jobs_available (status, queue, available_at, priority, id),
    INDEX idx_async_jobs_reserved (status, reserved_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
