-- Migration 008: Add role column to users table
--
-- Adds a role column to distinguish super admins from regular users.
-- All existing accounts default to 'user'.
-- Manually UPDATE a specific row to 'super_admin' after running this:
--   UPDATE users SET role = 'super_admin' WHERE username = 'your_username';

ALTER TABLE `users`
  ADD COLUMN `role` ENUM('user', 'super_admin') NOT NULL DEFAULT 'user'
  COMMENT 'Access level: user = normal, super_admin = account management'
  AFTER `username`;
