ALTER TABLE users
  ADD COLUMN auth_source ENUM('local','ad') NOT NULL DEFAULT 'local' AFTER password_hash,
  ADD COLUMN external_auth_id VARCHAR(255) NULL AFTER auth_source,
  ADD INDEX idx_users_auth_source (auth_source),
  ADD UNIQUE KEY uq_users_external_auth (auth_source, external_auth_id);

ALTER TABLE login_attempts
  ADD COLUMN auth_source ENUM('local','ad') NULL AFTER ip_address,
  ADD INDEX idx_login_attempts_source_window (auth_source, attempted_at);

CREATE TABLE IF NOT EXISTS system_audit_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  actor_id INT NULL,
  actor_name VARCHAR(100) NOT NULL,
  action VARCHAR(100) NOT NULL,
  subject_type VARCHAR(50) NULL,
  subject_id VARCHAR(255) NULL,
  details JSON NULL,
  ip_address VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_system_audit_action_time (action, created_at),
  INDEX idx_system_audit_actor_time (actor_id, created_at),
  INDEX idx_system_audit_subject_time (subject_type, subject_id, created_at),
  CONSTRAINT fk_system_audit_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
