-- =====================================================================
-- tickets.category — automatic ticket classification
-- Target database: pspf_helpdesk
--
-- Every ticket is tagged with a coarse subject-matter category (Access &
-- Accounts, Hardware, Network & Connectivity, Finance & Payroll, ...) derived
-- from its title/description by the rule-based classifier in
-- api/includes/ticket_classifier.php. The category is stored on the row so it
-- can be reported on, filtered by, and rolled up in the daily department digest
-- without re-classifying every time.
--
-- New tickets are classified at submission time (submit_query2.php). Existing
-- rows are backfilled by:
--   php api/ticket/backfill_ticket_categories.php
--
-- Additive and idempotent. Run:
--   mysql -u root -p pspf_helpdesk < 003_add_ticket_category.sql
-- Requires MariaDB 10.4+/MySQL 8.0+ (ADD COLUMN IF NOT EXISTS).
-- =====================================================================

ALTER TABLE `tickets`
  ADD COLUMN IF NOT EXISTS `category` VARCHAR(60) DEFAULT NULL AFTER `query_type`;

-- Index so per-category rollups in the digest and dashboards stay cheap.
-- CREATE INDEX has no IF NOT EXISTS on older engines, so guard it.
SET @idx := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'tickets'
      AND index_name = 'idx_tickets_category'
);
SET @sql := IF(@idx = 0,
    'ALTER TABLE `tickets` ADD INDEX `idx_tickets_category` (`category`)',
    'SELECT "idx_tickets_category already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
