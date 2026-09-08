-- Per-system claim/action support for IT Access requests.
-- Lets multiple IT officers each claim and action a different subset of the
-- systems on one request. The request only advances to the Director once every
-- system has been actioned.
--
-- Safe to run more than once: each ALTER is guarded with IF NOT EXISTS
-- (MariaDB 10.4+ / MySQL 8.0+ support IF NOT EXISTS on ADD COLUMN).
--
-- Run against the pspf_helpdesk database:
--   mysql -u root pspf_helpdesk < 2026_add_per_system_claims.sql

ALTER TABLE `it_request_systems`
  ADD COLUMN IF NOT EXISTS `status` ENUM('pending','claimed','actioned')
      NOT NULL DEFAULT 'pending' AFTER `sub_values`,
  ADD COLUMN IF NOT EXISTS `claimed_by`  INT(11)  DEFAULT NULL AFTER `status`,
  ADD COLUMN IF NOT EXISTS `claimed_at`  DATETIME DEFAULT NULL AFTER `claimed_by`,
  ADD COLUMN IF NOT EXISTS `actioned_by` INT(11)  DEFAULT NULL AFTER `claimed_at`,
  ADD COLUMN IF NOT EXISTS `actioned_at` DATETIME DEFAULT NULL AFTER `actioned_by`;

-- Backfill: any systems on requests that were already provisioned/actioned under
-- the old whole-request model are treated as fully actioned so history stays sane.
UPDATE `it_request_systems` s
JOIN `it_access_requests` r ON r.id = s.request_id
SET s.status = 'actioned',
    s.actioned_by = r.claimed_by,
    s.actioned_at = COALESCE(r.provisioned_at, NOW())
WHERE r.status IN ('awaiting-director','provisioned')
  AND s.status = 'pending';
