-- =====================================================================
-- IT Access — sub-option key ledger (tombstones)
-- Target database: pspf_helpdesk
--
-- Guarantees a sub-option key is NEVER reused, even after the sub-option it
-- belonged to is deleted.
--
-- WHY THIS EXISTS
-- ---------------
-- Stored request answers (it_request_systems.sub_values) are keyed by sub_key.
-- 003 made those keys stable so reordering is safe. But uniqueness enforced
-- only against the live it_system_suboptions table has a hole: delete the
-- sub-option "Building" (key physical_building), then add a new sub-option
-- also called "Building", and the key physical_building is free again. Any
-- historical answer stored under that key silently re-points at the new
-- question — which may ask something entirely different. No error is raised;
-- the record just quietly changes meaning. That is precisely the failure this
-- design set out to prevent, so the ledger closes it.
--
-- Every key ever issued for a system is recorded here and never removed. New
-- keys are checked against the ledger, not just against the live table.
--
-- Idempotent: safe to re-run. Backfills from the current catalog.
--
-- Run:  mysql -u root -p pspf_helpdesk < 004_suboption_key_ledger.sql
--
-- Depends on 003_system_catalog.sql.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `it_system_suboption_keys` (
  `system_id`  VARCHAR(100) NOT NULL,
  `sub_key`    VARCHAR(60)  NOT NULL,
  `first_seen` DATETIME     NOT NULL DEFAULT current_timestamp(),
  `retired_at` DATETIME     DEFAULT NULL COMMENT 'Set when the sub-option is deleted; the row itself is kept forever',
  PRIMARY KEY (`system_id`, `sub_key`)
  -- Deliberately NO foreign key to it_systems: the ledger must outlive the
  -- system it describes, so that deleting and recreating a system under the
  -- same slug cannot recycle its old sub-option keys either.
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Backfill from the catalog as it stands, so keys seeded by 003 are claimed.
INSERT INTO `it_system_suboption_keys` (`system_id`, `sub_key`)
SELECT s.`system_id`, s.`sub_key`
FROM `it_system_suboptions` s
WHERE NOT EXISTS (
    SELECT 1 FROM `it_system_suboption_keys` k
    WHERE k.`system_id` = s.`system_id` AND k.`sub_key` = s.`sub_key`
);
