-- Migration 015: Create password_reset_codes table
--
-- Stores one-time email verification codes used to gate Super Admin
-- password changes. Codes are hashed at rest; the plaintext code is only
-- ever emailed to the target account's own recovery address.

CREATE TABLE IF NOT EXISTS `password_reset_codes` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `requested_by_user_id` INT(11) DEFAULT NULL,
  `code_hash` VARCHAR(255) NOT NULL,
  `token` VARCHAR(64) NOT NULL,
  `verified_at` TIMESTAMP NULL DEFAULT NULL,
  `used_at` TIMESTAMP NULL DEFAULT NULL,
  `expires_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `password_reset_codes_token_unique` (`token`),
  KEY `idx_password_reset_codes_user_token` (`user_id`, `token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
