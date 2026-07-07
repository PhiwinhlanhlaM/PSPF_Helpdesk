-- =====================================================================
-- tickets.notified_at — one-time notification guard
-- Target database: pspf_helpdesk
--
-- The ticket "logged" confirmation + assignment emails are sent from
-- ticket_success2.php, which runs on EVERY load of the success page. Without a
-- guard, refreshing that page (or the dedup redirects, or reopening the URL)
-- re-sends the emails for the same ticket. This column records when the
-- notification was sent so it fires exactly once: the success page atomically
-- claims it via  UPDATE ... SET notified_at = NOW() WHERE id = ? AND
-- notified_at IS NULL  and only emails when that claim wins.
--
-- Additive and idempotent. Run:
--   mysql -u root -p pspf_helpdesk < 001_add_ticket_notified_at.sql
-- Requires MariaDB 10.4+/MySQL 8.0+ (ADD COLUMN IF NOT EXISTS).
-- =====================================================================

ALTER TABLE `tickets`
  ADD COLUMN IF NOT EXISTS `notified_at` DATETIME DEFAULT NULL AFTER `updated_at`;

-- Backfill: existing tickets were already notified (at submission time), so
-- mark them notified to avoid a burst of re-sends the first time anyone opens
-- their success page after this deploys. Uses query_date as the best-known
-- original notification time.
UPDATE `tickets` SET `notified_at` = `query_date` WHERE `notified_at` IS NULL;
