-- Migration 014: Add email column to users table
--
-- Nullable: existing accounts predate this column and have no known value.
-- Unique: two accounts must never share a recovery inbox.
-- Used as the destination for Super Admin password-reset verification codes.

ALTER TABLE `users`
  ADD COLUMN `email` VARCHAR(191) NULL UNIQUE
  COMMENT 'Recovery email, required for Super Admin password reset verification'
  AFTER `username`;
