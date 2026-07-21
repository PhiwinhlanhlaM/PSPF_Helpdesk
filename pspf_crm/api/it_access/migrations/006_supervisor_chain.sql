-- =====================================================================
-- IT Access — supervisor approval step (feedback item 4)
-- Target database: pspf_helpdesk
--
-- Opens the IT Access form to all registered users by inserting a SUPERVISOR
-- approval ahead of the ICT team:
--
--     requester -> SUPERVISOR -> IT officer -> Director
--
-- Adds three things:
--   1. Where a requester's supervisor comes from (division default + per-user
--      override, with a delegate for cover).
--   2. The new 'awaiting-supervisor' request status.
--   3. The new 'supervisor' approval step_role.
--
-- HOW A SUPERVISOR IS RESOLVED
-- ----------------------------
--   1. users.supervisor_id        — an explicit override for this person
--   2. divisions.supervisor_id    — otherwise, their division's supervisor
--   3. (none)                     — otherwise the request skips the supervisor
--                                   step and goes straight to the ICT queue
--
-- Most divisions are small and flat, so setting 13 division supervisors covers
-- the majority of staff with no per-user data entry. The per-user override
-- exists for divisions with real internal tiers — Benefits, where branch
-- officers report to a branch supervisor rather than the division head — so
-- accuracy is bought only where it is actually needed.
--
-- The delegate columns cover absence: if the supervisor is away, their
-- delegate may action the step. A request never waits on a person who cannot
-- act — with no supervisor and no delegate it falls through to ICT rather than
-- rotting in a queue.
--
-- Idempotent: safe to re-run (IF NOT EXISTS guards, enum changes are absolute).
--
-- Run:  mysql -u root -p pspf_helpdesk < 006_supervisor_chain.sql
--
-- Depends on 005_supervisor_role.sql (the `supervisor` role must exist).
-- Requires MariaDB 10.4+/MySQL 8.0+ for ADD COLUMN IF NOT EXISTS.
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1. Division-level supervisor and delegate.
--
--    ON DELETE SET NULL, not CASCADE: removing a user account must never
--    delete the division. The link simply falls away and resolution moves on
--    to the next rule (which is exactly the intended behaviour).
-- ---------------------------------------------------------------------
ALTER TABLE `divisions`
  ADD COLUMN IF NOT EXISTS `supervisor_id` INT(11) DEFAULT NULL
    COMMENT 'Default approver for requests from this division'
    AFTER `division_name`,
  ADD COLUMN IF NOT EXISTS `delegate_id` INT(11) DEFAULT NULL
    COMMENT 'Acts for the supervisor when they are unavailable'
    AFTER `supervisor_id`;

-- Foreign keys are added separately: ADD CONSTRAINT has no IF NOT EXISTS in
-- MariaDB 10.4, so re-running this file would error. Guarded via the
-- information_schema check below.
SET @fk_div_sup := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'divisions'
    AND CONSTRAINT_NAME = 'divisions_supervisor_id'
);
SET @sql := IF(@fk_div_sup = 0,
  'ALTER TABLE `divisions` ADD CONSTRAINT `divisions_supervisor_id`
     FOREIGN KEY (`supervisor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL',
  'SELECT "divisions_supervisor_id already present"');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @fk_div_del := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'divisions'
    AND CONSTRAINT_NAME = 'divisions_delegate_id'
);
SET @sql := IF(@fk_div_del = 0,
  'ALTER TABLE `divisions` ADD CONSTRAINT `divisions_delegate_id`
     FOREIGN KEY (`delegate_id`) REFERENCES `users` (`id`) ON DELETE SET NULL',
  'SELECT "divisions_delegate_id already present"');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
-- 2. Per-user override. NULL for almost everyone — set only where a person's
--    supervisor differs from their division's default.
-- ---------------------------------------------------------------------
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `supervisor_id` INT(11) DEFAULT NULL
    COMMENT 'Overrides the division supervisor for this user; NULL = inherit';

SET @fk_usr_sup := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND CONSTRAINT_NAME = 'users_supervisor_id'
);
SET @sql := IF(@fk_usr_sup = 0,
  'ALTER TABLE `users` ADD CONSTRAINT `users_supervisor_id`
     FOREIGN KEY (`supervisor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL',
  'SELECT "users_supervisor_id already present"');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
-- 3. The new request status.
--
--    'awaiting-supervisor' becomes the state a request sits in between
--    submission and the ICT queue. Existing values are preserved exactly and
--    keep their meaning, so requests already in flight are unaffected; the
--    default stays 'new' because a request with no supervisor to approve
--    still enters the ICT queue directly.
-- ---------------------------------------------------------------------
ALTER TABLE `it_access_requests`
  MODIFY COLUMN `status`
    ENUM('awaiting-supervisor','new','claimed','awaiting-director','provisioned','rejected')
    NOT NULL DEFAULT 'new';

-- ---------------------------------------------------------------------
-- 4. The new approval step.
--
--    'supervisor' joins the existing steps. Order in the enum is display-
--    irrelevant but kept chronological for readability.
-- ---------------------------------------------------------------------
ALTER TABLE `it_request_approvals`
  MODIFY COLUMN `step_role`
    ENUM('manager','supervisor','officer-1','director') NOT NULL;

-- ---------------------------------------------------------------------
-- 5. Helpful index: finding "requests awaiting MY approval" is the
--    supervisor dashboard's main query.
-- ---------------------------------------------------------------------
ALTER TABLE `it_access_requests`
  ADD COLUMN IF NOT EXISTS `supervisor_id` INT(11) DEFAULT NULL
    COMMENT 'The supervisor this request was routed to (resolved at submit time)'
    AFTER `submitted_by`;

SET @fk_req_sup := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'it_access_requests'
    AND CONSTRAINT_NAME = 'it_access_requests_supervisor_id'
);
SET @sql := IF(@fk_req_sup = 0,
  'ALTER TABLE `it_access_requests` ADD CONSTRAINT `it_access_requests_supervisor_id`
     FOREIGN KEY (`supervisor_id`) REFERENCES `users` (`id`)',
  'SELECT "it_access_requests_supervisor_id already present"');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx_req_sup := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'it_access_requests'
    AND INDEX_NAME = 'idx_supervisor_status'
);
SET @sql := IF(@idx_req_sup = 0,
  'ALTER TABLE `it_access_requests` ADD INDEX `idx_supervisor_status` (`supervisor_id`, `status`)',
  'SELECT "idx_supervisor_status already present"');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
