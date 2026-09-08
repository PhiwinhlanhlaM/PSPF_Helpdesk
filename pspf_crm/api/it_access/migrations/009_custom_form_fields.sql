-- =====================================================================
-- IT Access - superadmin-defined custom form fields
-- Target database: pspf_helpdesk
--
-- Lets a superadmin add extra QUESTIONS to the request form itself, alongside
-- the fixed core fields (employee name, department, title, start date, systems,
-- justification). The core fields stay hardcoded and are never editable here;
-- custom fields are additive and fully optional to the schema.
--
-- This is REQUEST-LEVEL, distinct from it_system_suboptions (which are
-- PER-SYSTEM questions attached to one system). A custom field applies to the
-- whole request - e.g. "Cost centre", "Line manager email", "Contract end date".
--
-- Two pieces:
--   it_form_fields               one row per custom field definition
--   it_access_requests.custom_values   JSON map { field_key: answer } per request
--
-- WHY FIELDS CARRY AN IMMUTABLE `field_key`
-- -----------------------------------------
-- Same lesson as it_system_suboptions.sub_key (see 003): a request's answers are
-- stored keyed by the field's STABLE key, never by its position or label. A
-- superadmin can then rename a label, reorder, or retire a field without any
-- historical answer silently re-mapping to a different question. `field_key` is
-- assigned once at creation and never reused; display order lives in sort_order.
--
-- FIELD TYPES (`kind`)
--   'text'        single-line free text
--   'textarea'    multi-line free text
--   'number'      numeric input
--   'date'        date picker (stored as YYYY-MM-DD string)
--   'select'      pick exactly one of `options`
--   'multiselect' pick any number of `options`
--   'checkbox'    a single boolean (stored true/false)
-- `options` is a JSON array of strings, used only by select/multiselect; NULL
-- otherwise. MariaDB 10.4 aliases JSON to LONGTEXT and does not enforce CHECK
-- reliably, so shape/required validity is enforced by the API, not the column.
--
-- NOTE ON DELETION: like it_systems, a retired field is DEACTIVATED
-- (is_active=0) rather than deleted, so historical custom_values keyed by its
-- field_key still resolve to a label. The admin UI will only hard-delete a
-- field once no stored request references its key.
--
-- Idempotent:
--   * CREATE TABLE IF NOT EXISTS.
--   * custom_values via ADD COLUMN IF NOT EXISTS.
--   * No seed rows - the feature ships with zero custom fields; a superadmin
--     adds them. Re-running is a clean no-op.
--
-- Run:  mysql -u root -p pspf_helpdesk < 009_custom_form_fields.sql
--
-- Requires MariaDB 10.4+/MySQL 8.0+. Depends on 000_it_access_base.sql.
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1. it_form_fields - the custom field definitions.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `it_form_fields` (
  `id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `field_key`   VARCHAR(60)  NOT NULL COMMENT 'Immutable key stored in it_access_requests.custom_values. Assigned once, never reused.',
  `label`       VARCHAR(150) NOT NULL,
  `kind`        ENUM('text','textarea','number','date','select','multiselect','checkbox')
                             NOT NULL DEFAULT 'text',
  `options`     TEXT         DEFAULT NULL COMMENT 'JSON array of choices; used by select/multiselect, NULL otherwise',
  `placeholder` VARCHAR(255) DEFAULT NULL COMMENT 'Optional input placeholder',
  `help_text`   VARCHAR(500) DEFAULT NULL COMMENT 'Optional helper text shown under the field',
  `is_required` TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = submit is blocked until answered',
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '0 = retired; hidden from new requests, still resolves for history',
  `sort_order`  INT(11)      NOT NULL DEFAULT 0,
  `created_at`  DATETIME     NOT NULL DEFAULT current_timestamp(),
  `updated_at`  DATETIME     NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `field_key` (`field_key`),
  KEY `is_active_sort` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- 2. it_access_requests.custom_values - the answers.
--
-- JSON map of { field_key: answer }. answer is a string (text/textarea/number/
-- date/select/checkbox as "true"/"false") or an array of strings (multiselect).
-- Nullable: a request created before any custom field exists, or with none
-- answered, simply has NULL here. Placed at the end of the row.
-- ---------------------------------------------------------------------
ALTER TABLE `it_access_requests`
  ADD COLUMN IF NOT EXISTS `custom_values` TEXT DEFAULT NULL
    COMMENT 'JSON map { field_key: answer } of custom form-field answers'
    AFTER `sharepoint_id`;

-- ---------------------------------------------------------------------
-- 3. it_form_field_keys - permanent key ledger (tombstones).
--
-- Same guarantee as it_system_suboption_keys (see 004): a field_key is NEVER
-- reused, even after its field is hard-deleted. Stored answers in custom_values
-- are keyed by field_key; if a deleted key could be re-issued to a new field, a
-- historical answer would silently re-point at a different question. Every key
-- ever minted is recorded here and never removed; new keys are checked against
-- this ledger, not just the live it_form_fields table.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `it_form_field_keys` (
  `field_key`  VARCHAR(60) NOT NULL,
  `first_seen` DATETIME    NOT NULL DEFAULT current_timestamp(),
  `retired_at` DATETIME    DEFAULT NULL COMMENT 'Set when the field is deleted; the row itself is kept forever',
  PRIMARY KEY (`field_key`)
  -- Deliberately NO foreign key to it_form_fields: the ledger must outlive the
  -- field it describes.
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Backfill from any fields that already exist (none on first run).
INSERT INTO `it_form_field_keys` (`field_key`)
SELECT f.`field_key` FROM `it_form_fields` f
WHERE NOT EXISTS (
    SELECT 1 FROM `it_form_field_keys` k WHERE k.`field_key` = f.`field_key`
);
