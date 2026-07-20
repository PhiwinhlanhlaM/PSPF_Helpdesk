-- =====================================================================
-- department_digest_log — one-time guard for the daily department digest
-- Target database: pspf_helpdesk
--
-- The daily summary email (api/ticket/send_department_digests.php) is driven by
-- a scheduled task. This table records that a given division's digest for a
-- given reporting date has already been sent, so a second run the same day (a
-- retry, an overlapping schedule, a manual re-run) does not double-send. The
-- sender claims the day by INSERTing here; the UNIQUE key makes a duplicate
-- claim fail, and only the winning claim emails.
--
-- Additive and idempotent. Run:
--   mysql -u root -p pspf_helpdesk < 004_add_department_digest_log.sql
-- =====================================================================

CREATE TABLE IF NOT EXISTS `department_digest_log` (
    `id`           INT AUTO_INCREMENT PRIMARY KEY,
    `division_id`  INT          NOT NULL,
    `digest_date`  DATE         NOT NULL,
    `window_start` DATETIME     NULL,
    `window_end`   DATETIME     NULL,
    `ticket_count` INT          NOT NULL DEFAULT 0,
    `recipients`   VARCHAR(1000) NULL,
    `sent_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_division_date` (`division_id`, `digest_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
