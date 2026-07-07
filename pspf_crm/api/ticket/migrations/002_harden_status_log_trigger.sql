-- =====================================================================
-- Harden the after_ticket_status_update trigger
-- Target database: pspf_helpdesk
--
-- The trigger logs every ticket status change into ticket_status_logs, using
-- NEW.assigned_to as changed_by. But ticket_status_logs.changed_by is NOT NULL,
-- so when a ticket has no assignee (assigned_to IS NULL) ANY status change
-- throws "Column 'changed_by' cannot be null" and rolls back the whole
-- operation — e.g. a requester submitting feedback on an unassigned ticket, or
-- an agent changing its status. This wraps the value in COALESCE so a missing
-- assignee is recorded as 'SYSTEM' instead of crashing.
--
-- Idempotent: drops and recreates the trigger. Run:
--   mysql -u root -p pspf_helpdesk < 002_harden_status_log_trigger.sql
-- =====================================================================

DROP TRIGGER IF EXISTS `after_ticket_status_update`;

DELIMITER $$

CREATE TRIGGER `after_ticket_status_update`
AFTER UPDATE ON `tickets`
FOR EACH ROW
BEGIN
    IF OLD.status <> NEW.status THEN
        INSERT INTO ticket_status_logs (ticket_id, old_status, new_status, changed_by)
        VALUES (
            NEW.id,
            OLD.status,
            NEW.status,
            -- Fall back to 'SYSTEM' when the ticket has no assignee, so the
            -- NOT NULL changed_by column never causes the update to fail.
            COALESCE(NULLIF(TRIM(COALESCE(NEW.assigned_to, '')), ''), 'SYSTEM')
        );
    END IF;
END$$

DELIMITER ;
