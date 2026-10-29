-- "Keep me signed in" (remember me). One row per remembered browser. The cookie holds selector:validator; only a SHA-256 hash of
-- the validator is stored, so a copy of this table cannot be used to sign in. pw_marker ties the token to the password the user
-- had when it was issued: changing the password makes every older token useless. No foreign key on purpose: tokens of a
-- removed user are simply never accepted and are cleaned up when they expire.

CREATE TABLE remember_tokens (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    selector CHAR(24) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    pw_marker CHAR(32) NOT NULL,
    user_agent VARCHAR(255) NOT NULL DEFAULT '',
    ip VARCHAR(45) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL,
    last_used_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX idx_remember_selector ON remember_tokens (selector);
CREATE INDEX idx_remember_user ON remember_tokens (user_id);
CREATE INDEX idx_remember_expires ON remember_tokens (expires_at);
