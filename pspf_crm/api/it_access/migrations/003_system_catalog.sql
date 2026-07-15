-- =====================================================================
-- IT Access — database-backed system catalog
-- Target database: pspf_helpdesk
--
-- Moves the system catalog out of the React source (SYSTEM_CATALOG in
-- `IT Access Form/app/data.jsx`) and into the database, so a superadmin can
-- manage it from the CRM and other modules (e.g. the ticket Title dropdown)
-- can read the same list instead of keeping a second hardcoded copy.
--
-- Three tables:
--   it_systems             one row per system (the catalog entry itself)
--   it_system_roles        the `roles: []` array — access levels per system
--   it_system_suboptions   the `subOptions` — extra questions per system
--
-- WHY SUB-OPTIONS GET A STABLE `sub_key`
-- --------------------------------------
-- ManagerForm.jsx keys a request's stored answers by the sub-option's ARRAY
-- POSITION (`sub_0`, `sub_1`) and those keys are persisted verbatim into
-- it_request_systems.sub_values as JSON. That is safe only while the catalog
-- is a constant in a source file. Once a superadmin can reorder, insert or
-- remove a sub-option, every historical record pointing at `sub_1` silently
-- re-maps to a different question — a request that recorded "After hours"
-- would start reading as "Board room". No error; the data just goes quietly
-- wrong, which is exactly what an audit would surface.
--
-- So each sub-option carries an immutable `sub_key` assigned once at creation
-- and never reused. Display order lives in `sort_order` and can change freely
-- without touching stored data. New requests store answers keyed by sub_key.
--
-- NOTE ON EXISTING DATA: it_request_systems.system_id is a plain VARCHAR with
-- no foreign key to this catalog, and that decoupling is deliberate and kept —
-- it is what lets a historical request stay readable after its system is
-- retired. Consequently nothing at the DB level prevents deleting a system
-- that old requests reference, so the admin UI offers DEACTIVATE (is_active=0)
-- and only permits hard delete when the reference count is zero.
--
-- Idempotent: safe to re-run. Seed rows are guarded with NOT EXISTS, so an
-- edited catalog is never clobbered by a second run.
--
-- Run:  mysql -u root -p pspf_helpdesk < 003_system_catalog.sql
--
-- Requires MariaDB 10.4+/MySQL 8.0+. Depends on 000_it_access_base.sql.
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1. it_systems — the catalog entry.
--
-- `id` keeps the existing VARCHAR slug ('inpensions', 'ad', ...) rather than
-- introducing a surrogate key, so the 55 rows of request history already
-- written against those slugs keep resolving without a data migration.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `it_systems` (
  `id`          VARCHAR(100) NOT NULL COMMENT 'Stable slug, referenced by it_request_systems.system_id',
  `name`        VARCHAR(255) NOT NULL,
  `description` VARCHAR(500) DEFAULT NULL,
  `icon`        VARCHAR(50)  NOT NULL DEFAULT 'archive' COMMENT 'Icon name understood by Icon.jsx',
  `multi_role`  TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = requester may pick several roles at once',
  `sort_order`  INT(11)      NOT NULL DEFAULT 0,
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '0 = retired; hidden from new requests, still resolves for history',
  `created_at`  DATETIME     NOT NULL DEFAULT current_timestamp(),
  `updated_at`  DATETIME     NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `is_active_sort` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- 2. it_system_roles — the `roles: []` array.
--
-- A system with no rows here simply has no role selector (e.g. 'ad',
-- 'physical'), which matches how ManagerForm.jsx already renders it.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `it_system_roles` (
  `id`         INT(11)      NOT NULL AUTO_INCREMENT,
  `system_id`  VARCHAR(100) NOT NULL,
  `label`      VARCHAR(100) NOT NULL,
  `sort_order` INT(11)      NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `system_role` (`system_id`, `label`),
  CONSTRAINT `it_system_roles_system_id`
    FOREIGN KEY (`system_id`) REFERENCES `it_systems` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- 3. it_system_suboptions — the `subOptions` structure.
--
-- The array-vs-single-object shape in data.jsx disappears here: a system just
-- has zero, one or many sub-option rows ordered by sort_order. The read API
-- rebuilds whatever shape the existing JSX expects.
--
-- `kind`:
--   'single' — pick exactly one of `options`      (was multi:false)
--   'multi'  — pick any number of `options`       (was multi:true)
--   'text'   — free text, `options` is NULL       (was text:true)
--
-- `options` is a JSON array of strings. MariaDB 10.4 aliases JSON to LONGTEXT
-- and does not enforce CHECK constraints reliably, so validity is enforced by
-- the API rather than the column type.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `it_system_suboptions` (
  `id`         INT(11)      NOT NULL AUTO_INCREMENT,
  `system_id`  VARCHAR(100) NOT NULL,
  `sub_key`    VARCHAR(60)  NOT NULL COMMENT 'Immutable key stored in request sub_values. Assigned once, never reused.',
  `label`      VARCHAR(150) NOT NULL,
  `kind`       ENUM('single','multi','text') NOT NULL DEFAULT 'single',
  `options`    TEXT         DEFAULT NULL COMMENT 'JSON array of choices; NULL when kind = text',
  `sort_order` INT(11)      NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `system_subkey` (`system_id`, `sub_key`),
  CONSTRAINT `it_system_suboptions_system_id`
    FOREIGN KEY (`system_id`) REFERENCES `it_systems` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =====================================================================
-- SEED — mirrors SYSTEM_CATALOG in data.jsx exactly as of this migration.
--
-- Every INSERT is guarded with NOT EXISTS so re-running never overwrites an
-- edited catalog. sub_key values are descriptive and permanent; they are the
-- contract with stored request data from here on.
-- =====================================================================

-- ---- 1. INPENSIONS --------------------------------------------------
INSERT INTO `it_systems` (`id`, `name`, `description`, `icon`, `multi_role`, `sort_order`)
SELECT 'inpensions', 'INPENSIONS', 'Pension member records, contributions, benefit calculations', 'shield', 0, 10
WHERE NOT EXISTS (SELECT 1 FROM `it_systems` WHERE `id` = 'inpensions');

-- ---- 2. SMARTSTREAM / SAGE300 ---------------------------------------
INSERT INTO `it_systems` (`id`, `name`, `description`, `icon`, `multi_role`, `sort_order`)
SELECT 'smartstream', 'SMARTSTREAM / SAGE300', 'Financial management & general ledger', 'bank', 0, 20
WHERE NOT EXISTS (SELECT 1 FROM `it_systems` WHERE `id` = 'smartstream');

-- ---- 3. ACTIVE DIRECTORY & EMAIL ACCESS -----------------------------
INSERT INTO `it_systems` (`id`, `name`, `description`, `icon`, `multi_role`, `sort_order`)
SELECT 'ad', 'ACTIVE DIRECTORY & EMAIL ACCESS', 'Windows login, Outlook mailbox, Teams, OneDrive', 'key', 0, 30
WHERE NOT EXISTS (SELECT 1 FROM `it_systems` WHERE `id` = 'ad');

-- ---- 4. PHYSICAL ACCESS ---------------------------------------------
INSERT INTO `it_systems` (`id`, `name`, `description`, `icon`, `multi_role`, `sort_order`)
SELECT 'physical', 'PHYSICAL ACCESS', 'Door and room access', 'door', 0, 40
WHERE NOT EXISTS (SELECT 1 FROM `it_systems` WHERE `id` = 'physical');

-- ---- 5. TELEPHONE SYSTEM ACCESS -------------------------------------
INSERT INTO `it_systems` (`id`, `name`, `description`, `icon`, `multi_role`, `sort_order`)
SELECT 'telephone', 'TELEPHONE SYSTEM ACCESS', 'PABX dialing privileges', 'phone', 0, 50
WHERE NOT EXISTS (SELECT 1 FROM `it_systems` WHERE `id` = 'telephone');

-- ---- 6. DATASTOR ACCESS ---------------------------------------------
INSERT INTO `it_systems` (`id`, `name`, `description`, `icon`, `multi_role`, `sort_order`)
SELECT 'datastor', 'DATASTOR ACCESS', 'Document management archive', 'archive', 0, 60
WHERE NOT EXISTS (SELECT 1 FROM `it_systems` WHERE `id` = 'datastor');

-- ---- 7. BANKING ACCESS ----------------------------------------------
INSERT INTO `it_systems` (`id`, `name`, `description`, `icon`, `multi_role`, `sort_order`)
SELECT 'banking', 'BANKING ACCESS', 'Payment processing & reconciliation', 'bank', 0, 70
WHERE NOT EXISTS (SELECT 1 FROM `it_systems` WHERE `id` = 'banking');

-- ---- 8. HELPDESK / CRM ----------------------------------------------
INSERT INTO `it_systems` (`id`, `name`, `description`, `icon`, `multi_role`, `sort_order`)
SELECT 'helpdesk', 'HELPDESK / CRM', 'PSPF internal helpdesk and CRM access', 'shield-check', 1, 80
WHERE NOT EXISTS (SELECT 1 FROM `it_systems` WHERE `id` = 'helpdesk');

-- ---- 9. TRUST ACCESS ------------------------------------------------
INSERT INTO `it_systems` (`id`, `name`, `description`, `icon`, `multi_role`, `sort_order`)
SELECT 'trust', 'TRUST ACCESS', 'Trust fund administration', 'scale', 0, 90
WHERE NOT EXISTS (SELECT 1 FROM `it_systems` WHERE `id` = 'trust');

-- ---- 10. BIOMETRIC ACCESS -------------------------------------------
INSERT INTO `it_systems` (`id`, `name`, `description`, `icon`, `multi_role`, `sort_order`)
SELECT 'biometric', 'BIOMETRIC ACCESS', 'Biometric device operator access', 'key', 0, 100
WHERE NOT EXISTS (SELECT 1 FROM `it_systems` WHERE `id` = 'biometric');

-- ---- 11. OTHER SYSTEM -----------------------------------------------
INSERT INTO `it_systems` (`id`, `name`, `description`, `icon`, `multi_role`, `sort_order`)
SELECT 'other', 'OTHER SYSTEM', 'Any system not listed above', 'archive', 0, 110
WHERE NOT EXISTS (SELECT 1 FROM `it_systems` WHERE `id` = 'other');

-- ---------------------------------------------------------------------
-- Roles. Systems absent here (ad, physical, telephone, other) intentionally
-- have no role selector.
-- ---------------------------------------------------------------------
INSERT INTO `it_system_roles` (`system_id`, `label`, `sort_order`)
SELECT * FROM (
    SELECT 'inpensions' AS s, 'Capturer'   AS l, 10 AS o UNION ALL
    SELECT 'inpensions', 'Viewer',     20 UNION ALL
    SELECT 'inpensions', 'Authorizer', 30 UNION ALL
    SELECT 'inpensions', 'Admin',      40 UNION ALL

    SELECT 'smartstream', 'Capturer',   10 UNION ALL
    SELECT 'smartstream', 'Viewer',     20 UNION ALL
    SELECT 'smartstream', 'Authorizer', 30 UNION ALL
    SELECT 'smartstream', 'Admin',      40 UNION ALL

    SELECT 'datastor', 'Capturer',   10 UNION ALL
    SELECT 'datastor', 'Viewer',     20 UNION ALL
    SELECT 'datastor', 'Authorizer', 30 UNION ALL
    SELECT 'datastor', 'Admin',      40 UNION ALL

    SELECT 'banking', 'Capturer',   10 UNION ALL
    SELECT 'banking', 'Viewer',     20 UNION ALL
    SELECT 'banking', 'Authorizer', 30 UNION ALL
    SELECT 'banking', 'Admin',      40 UNION ALL

    SELECT 'helpdesk', 'User',       10 UNION ALL
    SELECT 'helpdesk', 'Agent',      20 UNION ALL
    SELECT 'helpdesk', 'Admin',      30 UNION ALL
    SELECT 'helpdesk', 'Superadmin', 40 UNION ALL

    SELECT 'trust', 'Capturer',   10 UNION ALL
    SELECT 'trust', 'Viewer',     20 UNION ALL
    SELECT 'trust', 'Authorizer', 30 UNION ALL
    SELECT 'trust', 'Admin',      40 UNION ALL

    SELECT 'biometric', 'Operator', 10 UNION ALL
    SELECT 'biometric', 'Approver', 20 UNION ALL
    SELECT 'biometric', 'Admin',    30
) AS seed
WHERE NOT EXISTS (
    SELECT 1 FROM `it_system_roles` r
    WHERE r.`system_id` = seed.s AND r.`label` = seed.l
);

-- ---------------------------------------------------------------------
-- Sub-options.
--
-- sub_key mapping from the current positional keys (data.jsx assigns sub_0,
-- sub_1 by array index). These names are now permanent:
--
--   ad        sub_0 -> ad_duration
--   physical  sub_0 -> physical_room
--   physical  sub_1 -> physical_duration
--   telephone sub_0 -> telephone_level
--   datastor  sub_0 -> datastor_path
--   banking   sub_0 -> banking_platform
--   other     sub_0 -> other_system_name
--   other     sub_1 -> other_role
--
-- No UPDATE of it_request_systems.sub_values is needed: the table is empty on
-- every environment (local and live both verified at 0 rows before this ran).
-- Were that ever untrue, the positional keys above would have to be rewritten
-- to these names before the API starts reading by sub_key.
-- ---------------------------------------------------------------------
INSERT INTO `it_system_suboptions` (`system_id`, `sub_key`, `label`, `kind`, `options`, `sort_order`)
SELECT * FROM (
    SELECT 'ad' AS s, 'ad_duration' AS k, 'Duration' AS l, 'single' AS t,
           '["Normal hours","After hours"]' AS o, 10 AS so
    UNION ALL SELECT 'physical', 'physical_room', 'Room', 'multi',
           '["Server room","Board room"]', 10
    UNION ALL SELECT 'physical', 'physical_duration', 'Duration', 'single',
           '["Normal hours","After hours"]', 20
    UNION ALL SELECT 'telephone', 'telephone_level', 'Level', 'single',
           '["Local","Cell","SA","International"]', 10
    UNION ALL SELECT 'datastor', 'datastor_path', 'Stor (folder/path)', 'text',
           NULL, 10
    UNION ALL SELECT 'banking', 'banking_platform', 'Platform', 'multi',
           '["FNB","STD","MTN MoMo","Nedbank","E-Mali","Eswatini Bank"]', 10
    UNION ALL SELECT 'other', 'other_system_name', 'System name', 'text',
           NULL, 10
    UNION ALL SELECT 'other', 'other_role', 'Role / access level', 'text',
           NULL, 20
) AS seed
WHERE NOT EXISTS (
    SELECT 1 FROM `it_system_suboptions` x
    WHERE x.`system_id` = seed.s AND x.`sub_key` = seed.k
);
