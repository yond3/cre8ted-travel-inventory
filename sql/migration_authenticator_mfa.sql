-- Phase 6B: additive structures. Existing users are not automatically enrolled.
ALTER TABLE users ADD COLUMN mfa_required TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE login_attempts ADD COLUMN stage ENUM('password','mfa','reauth') NOT NULL DEFAULT 'password';
CREATE TABLE auth_policy (
 id TINYINT PRIMARY KEY, enforce_mfa TINYINT(1) NOT NULL DEFAULT 0,
 CHECK(id=1), CHECK(enforce_mfa IN (0,1))
) ENGINE=InnoDB;
INSERT INTO auth_policy(id) VALUES(1);
CREATE TABLE user_authenticators (
 user_id INT PRIMARY KEY,
 enrollment_id CHAR(32) NOT NULL UNIQUE,
 secret_blob TEXT NOT NULL,
 confirmed_at DATETIME NOT NULL,
 revoked_at DATETIME NULL,
 last_step BIGINT NULL,
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE auth_enrollment_grants (
 id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
 token_hash CHAR(64) NOT NULL UNIQUE,
 security_version BIGINT UNSIGNED NOT NULL,
 purpose ENUM('initial','reset','replace') NOT NULL,
 issued_by VARCHAR(100) NOT NULL, reason TEXT NOT NULL,
 expires_at DATETIME NOT NULL, consumed_at DATETIME NULL, revoked_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT,
 KEY grant_user_expiry(user_id,expires_at)
) ENGINE=InnoDB;
CREATE TABLE auth_challenges (
 id CHAR(64) PRIMARY KEY, user_id INT NOT NULL,
 purpose ENUM('login','enroll') NOT NULL,
 security_version BIGINT UNSIGNED NOT NULL,
 binding_hash CHAR(64) NOT NULL,
 grant_id INT NULL, secret_blob TEXT NULL,
 expires_at DATETIME NOT NULL, attempts INT NOT NULL DEFAULT 0,
 consumed_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY(grant_id) REFERENCES auth_enrollment_grants(id) ON DELETE RESTRICT,
 KEY challenge_user_expiry(user_id,expires_at), KEY challenge_expiry(expires_at)
) ENGINE=InnoDB;
CREATE TABLE user_recovery_codes (
 id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
 enrollment_id CHAR(32) NOT NULL, code_hash CHAR(64) NOT NULL UNIQUE,
 consumed_at DATETIME NULL, revoked_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT,
 KEY recovery_user(user_id,enrollment_id)
) ENGINE=InnoDB;
