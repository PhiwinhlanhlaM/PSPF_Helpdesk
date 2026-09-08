-- =====================================================================
-- PSPF Helpdesk — Continuous Delivery pipeline schema
-- Migration 001: deploy_requests + deploy_state
--
-- Applies to the pspf_helpdesk database. Manual migration (by policy the
-- deploy pipeline never runs SQL itself). Idempotent: safe to re-run.
--
-- Roles / actors:
--   * The CRM web tier (Apache/PHP) only ever reads/writes these two
--     tables. It NEVER runs git or shell commands.
--   * The PowerShell runner (privileged service account) is the only actor
--     that touches git and live files; it reads/writes these tables too.
--
-- See deploy/PIPELINE_DESIGN.md for the full design and security model.
-- =====================================================================

-- ---------------------------------------------------------------------
-- deploy_requests — the work queue AND the immutable audit trail.
-- One row per operator action (check for updates / deploy).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `deploy_requests` (
    `id`              INT AUTO_INCREMENT PRIMARY KEY,

    -- What kind of request this is.
    --   check  = look for repo updates (runner fetches, computes diff+drift)
    --   deploy = apply an already-reviewed commit to live
    `type`            ENUM('check','deploy') NOT NULL DEFAULT 'check',

    -- Lifecycle. The runner and the dashboard cooperate on this column:
    --   pending    : just created by the dashboard, runner has not picked it up
    --   checking   : runner is fetching / computing the diff
    --   ready      : diff+drift computed, awaiting a human decision
    --   no_change  : repo is not ahead of last_deployed_sha (nothing to do)
    --   approved   : a superadmin approved; runner will deploy on next cycle
    --   declined   : a superadmin declined (reason recorded)
    --   deploying  : runner is applying the deploy
    --   deployed   : deploy succeeded; last_deployed_sha updated
    --   failed     : check or deploy failed (see log_excerpt); safe state
    `status`          ENUM(
                        'pending','checking','ready','no_change',
                        'approved','declined','deploying','deployed','failed'
                      ) NOT NULL DEFAULT 'pending',

    `commit_sha`      VARCHAR(40)  NULL,          -- target commit (full SHA)
    `commit_msg`      TEXT         NULL,          -- the change-request description
    `commit_author`   VARCHAR(150) NULL,          -- author name from git

    -- What the approver reviews. JSON produced by the runner.
    `diff_summary`    MEDIUMTEXT   NULL,          -- files NEW/CHANGED + counts
    `drift_report`    MEDIUMTEXT   NULL,          -- live files differing from last-deployed SHA

    `requested_by`    INT          NULL,          -- users.id who clicked Check
    `decided_by`      INT          NULL,          -- users.id who approved/declined
    `decided_at`      DATETIME     NULL,
    `decision_reason` TEXT         NULL,          -- required on decline

    `deployed_sha`    VARCHAR(40)  NULL,          -- recorded after a successful deploy
    `log_excerpt`     MEDIUMTEXT   NULL,          -- tail of the runner/deploy log

    `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                   ON UPDATE CURRENT_TIMESTAMP,

    KEY `idx_status`      (`status`),
    KEY `idx_type_status` (`type`, `status`),
    KEY `idx_created`     (`created_at`),
    CONSTRAINT `fk_deploy_requested_by`
        FOREIGN KEY (`requested_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_deploy_decided_by`
        FOREIGN KEY (`decided_by`)   REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- deploy_state — a tiny single-row marker of the last commit that was
-- successfully deployed to live. Drift is measured against this SHA.
-- The fixed id=1 row is the singleton; the runner UPSERTs it.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `deploy_state` (
    `id`                INT PRIMARY KEY DEFAULT 1,
    `last_deployed_sha` VARCHAR(40) NULL,
    `last_deployed_at`  DATETIME    NULL,
    `last_deployed_by`  INT         NULL,          -- users.id of the approver
    `runner_heartbeat`  DATETIME    NULL,          -- runner writes each cycle (health)
    `updated_at`        DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `chk_deploy_state_singleton` CHECK (`id` = 1),
    CONSTRAINT `fk_deploy_state_by`
        FOREIGN KEY (`last_deployed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed the singleton row (no-op if it already exists).
INSERT INTO `deploy_state` (`id`, `last_deployed_sha`, `updated_at`)
VALUES (1, NULL, NOW())
ON DUPLICATE KEY UPDATE `id` = `id`;
