-- =====================================================================
-- IT Access — partial (per-system) rejection loop
-- Target database: pspf_helpdesk
--
-- Today rejection is all-or-nothing: an officer can only reject an ENTIRE
-- request. This adds PER-SYSTEM rejection so an officer can grant some systems
-- on a request and reject others, each with its own reason.
--
-- Design (agreed):
--   * An officer marks each of their claimed systems as GRANTED (-> actioned)
--     or REJECTED (-> rejected, with a reason).
--   * A request with any rejected-and-unresolved system PAUSES at the new
--     request status 'awaiting-requester' — it does NOT advance to the director
--     and nothing provisions while the requester still owes a response.
--   * The requester resolves each rejected system by either:
--       ACCEPT  -> the system is 'dropped' (final, not granted), or
--       APPEAL  -> the system returns to the officer queue ('pending') carrying
--                  its full context (original request + the rejection reason);
--                  appeal_count is incremented.
--   * ONE appeal per system: a system rejected again after it was already
--     appealed (appeal_count >= 1) is auto-'dropped' (final) — mirrors the
--     one-appeal rule of the whole-request appeal loop (007).
--   * The request advances to the director only once EVERY system is terminal
--     for this stage: 'actioned' (granted) or 'dropped' (denied). Granted
--     systems are held — a single director sign-off, a single provisioning
--     event, one PDF that shows both granted and denied systems.
--
-- Idempotent:
--   * Enum widenings use MODIFY COLUMN, which simply re-asserts the full enum;
--     re-running is a no-op.
--   * New columns use ADD COLUMN IF NOT EXISTS.
--
-- Run:  mysql -u root -p pspf_helpdesk < 008_partial_rejection.sql
--
-- Depends on 007_appeal_loop.sql (and the per-system claim columns from the
-- base migration / 2026_add_per_system_claims).
-- =====================================================================

-- ---------------------------------------------------------------------
-- Per-system status: add 'rejected' (officer denied it, awaiting requester)
-- and 'dropped' (finally not granted — requester accepted the denial, or a
-- re-rejected appeal). 'pending'/'claimed'/'actioned' keep their meaning.
-- ---------------------------------------------------------------------
ALTER TABLE `it_request_systems`
  MODIFY COLUMN `status`
    ENUM('pending','claimed','actioned','rejected','dropped')
    NOT NULL DEFAULT 'pending';

-- Per-system rejection detail. All nullable — only set when a system is
-- rejected. reject_reason is shown to the requester and printed on the PDF.
ALTER TABLE `it_request_systems`
  ADD COLUMN IF NOT EXISTS `reject_reason` TEXT DEFAULT NULL
    COMMENT 'Why the officer denied this system (set when status=rejected/dropped)'
    AFTER `actioned_at`;

ALTER TABLE `it_request_systems`
  ADD COLUMN IF NOT EXISTS `rejected_by` INT(11) DEFAULT NULL
    COMMENT 'Officer who rejected this system'
    AFTER `reject_reason`;

ALTER TABLE `it_request_systems`
  ADD COLUMN IF NOT EXISTS `rejected_at` DATETIME DEFAULT NULL
    COMMENT 'When the system was rejected (UTC)'
    AFTER `rejected_by`;

-- One-appeal guard per system. 0 = never appealed; 1 = appealed once (a further
-- rejection is final). Mirrors the whole-request one-appeal rule.
ALTER TABLE `it_request_systems`
  ADD COLUMN IF NOT EXISTS `appeal_count` TINYINT(3) UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'Times the requester has appealed this system (max 1 before final)'
    AFTER `rejected_at`;

-- FK for rejected_by -> users. ON DELETE SET NULL: never lose the system row if
-- the officer account is removed. Guarded (10.4 has no ADD CONSTRAINT IF NOT EXISTS).
SET @fk_rej := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'it_request_systems'
    AND CONSTRAINT_NAME = 'it_request_systems_rejected_by'
);
SET @sql := IF(@fk_rej = 0,
  'ALTER TABLE `it_request_systems` ADD CONSTRAINT `it_request_systems_rejected_by`
     FOREIGN KEY (`rejected_by`) REFERENCES `users` (`id`) ON DELETE SET NULL',
  'SELECT "it_request_systems_rejected_by already present"');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
-- Request-level: add 'awaiting-requester' — the request is paused because one
-- or more systems were rejected and the requester has not yet accepted or
-- appealed them. Every other value keeps its meaning.
-- ---------------------------------------------------------------------
ALTER TABLE `it_access_requests`
  MODIFY COLUMN `status`
    ENUM('awaiting-supervisor','new','claimed','awaiting-requester','awaiting-director','provisioned','rejected')
    NOT NULL DEFAULT 'new';
