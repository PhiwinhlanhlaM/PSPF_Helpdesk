-- Store a user's full display name (captured once via the IT Access form prompt).
-- Additive and idempotent. Run against the pspf_helpdesk database:
--   mysql -u root pspf_helpdesk < 2026_add_user_full_name.sql

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `full_name` VARCHAR(150) DEFAULT NULL AFTER `Username`;
