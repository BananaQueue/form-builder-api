-- Migration 007: Add owner (created_by) column to forms table
--
-- Every form will now belong to a user.
-- We use ON DELETE SET NULL so that if a user account is ever deleted,
-- their forms are NOT deleted with them — they just become "unowned".
-- This is a safer default than CASCADE (which would wipe all their forms).
--
-- NULL means "no owner assigned yet" — this covers all your existing forms
-- that were created before this column existed.

ALTER TABLE `forms`
  ADD COLUMN `created_by` INT(11) DEFAULT NULL
  COMMENT 'FK to users.id — which user created this form'
  AFTER `id`;

ALTER TABLE `forms`
  ADD CONSTRAINT `fk_forms_created_by`
  FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
  ON DELETE SET NULL;

-- Optional: assign all existing forms to a specific user.
-- Replace 1 with whichever user id you want to own the legacy forms.
-- Comment this out if you'd rather leave existing forms unowned.
UPDATE `forms` SET `created_by` = 1 WHERE `created_by` IS NULL;