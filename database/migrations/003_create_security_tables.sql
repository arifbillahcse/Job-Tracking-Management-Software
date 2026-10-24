-- Sign-in throttling data and the audit trail. audit_logs.user_id has no foreign key on purpose:
-- history must survive even if a user record is ever purged.

CREATE TABLE login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(190) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    successful TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_login_attempts_username ON login_attempts (username, created_at);
CREATE INDEX idx_login_attempts_ip ON login_attempts (ip, created_at);

CREATE TABLE audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    action VARCHAR(60) NOT NULL,
    entity VARCHAR(60) NULL,
    entity_id BIGINT UNSIGNED NULL,
    old_values TEXT NULL,
    new_values TEXT NULL,
    ip VARCHAR(45) NOT NULL DEFAULT '',
    user_agent VARCHAR(255) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_audit_entity ON audit_logs (entity, entity_id);
CREATE INDEX idx_audit_user ON audit_logs (user_id, created_at);
CREATE INDEX idx_audit_created ON audit_logs (created_at);
