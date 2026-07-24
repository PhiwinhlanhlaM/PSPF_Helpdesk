-- =====================================================================
-- IT Access — reject / appeal loop (feedback item 3)
-- Target database: pspf_helpdesk
--
-- Today a rejection is a dead end: the reason is stored but the requester is
-- never emailed and has no way to respond. This adds the ability to APPEAL a
-- rejected request by submitting a revised, linked copy.
--
-- Design (agreed):
--   * An appeal is a NEW request linked to the original via appeal_of. The
--     original — a signed, audit-relevant rejection — is never rewritten.
--   * An appeal re-enters the chain from the top (supervisor -> ICT -> director),
--     so it gets a genuinely fresh review rather than going back to the same 'no'.
--   * ONE appeal only. A request that is itself an appeal (appeal_of IS NOT NULL)
--     cannot be appealed again — so a rejected appeal is final. The link column
--     is therefore also the limit; no separate counter is needed.
--
-- The email gap is closed in approve.php (a rejection now notifies the
-- requester with the reason and a link); this migration only adds the link.
--
-- Idempotent: ADD COLUMN IF NOT EXISTS, and the FK is guarded via
-- information_schema (MariaDB 10.4 has no ADD CONSTRAINT IF NOT EXISTS).
--
-- Run:  mysql -u root -p pspf_helpdesk < 007_appeal_loop.sql
--
-- Depends on 006_supervisor_chain.sql.
-- =====================================================================

-- ---------------------------------------------------------------------
-- appeal_of — the request this one is appealing, or NULL for an original.
--
-- ON DELETE SET NULL: if the original is ever deleted, the appeal survives as
-- a standalone request rather than cascading away. Self-referential FK on the
-- same table.
-- ---------------------------------------------------------------------
ALTER TABLE `it_access_requests`
  ADD COLUMN IF NOT EXISTS `appeal_of` INT(11) DEFAULT NULL
    COMMENT 'The rejected request this one appeals; NULL for an original request'
    AFTER `submitted_by`;

SET @fk_appeal := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'it_access_requests'
    AND CONSTRAINT_NAME = 'it_access_requests_appeal_of'
);
SET @sql := IF(@fk_appeal = 0,
  'ALTER TABLE `it_access_requests` ADD CONSTRAINT `it_access_requests_appeal_of`
     FOREIGN KEY (`appeal_of`) REFERENCES `it_access_requests` (`id`) ON DELETE SET NULL',
  'SELECT "it_access_requests_appeal_of already present"');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Index: "has this request already been appealed?" is checked before offering
-- the appeal action, so look-ups by appeal_of should be quick.
SET @idx_appeal := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'it_access_requests'
    AND INDEX_NAME = 'idx_appeal_of'
);
SET @sql := IF(@idx_appeal = 0,
  'ALTER TABLE `it_access_requests` ADD INDEX `idx_appeal_of` (`appeal_of`)',
  'SELECT "idx_appeal_of already present"');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
