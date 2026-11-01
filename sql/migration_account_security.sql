-- Phase 6A: additive readiness only. Does not enroll or enforce MFA.
ALTER TABLE users
 ADD COLUMN security_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
 ADD COLUMN account_kind ENUM('unclassified','named','shared') NOT NULL DEFAULT 'unclassified';
