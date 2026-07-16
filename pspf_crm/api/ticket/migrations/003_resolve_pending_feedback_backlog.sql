-- =====================================================================
-- Sweep the "Pending Feedback" backlog to "Resolved"
-- Target database: pspf_helpdesk
--
-- Closing a ticket used to move it to "Pending Feedback", where it sat until
-- the requester submitted a rating. Tickets that were actually done therefore
-- lingered indefinitely when no one rated them. The application now resolves
-- tickets straight away on close (see update_ticket_status_ajax.php), so this
-- one-off backfill clears the tickets already stuck in that limbo.
--
-- What this does / does NOT touch:
--   * Advances every ticket currently in "Pending Feedback" to "Resolved" and
--     bumps updated_at, mirroring what the app does on a status change.
--   * Leaves feedback_tokens alone: any outstanding (unused, unexpired) token
--     stays valid, so a requester can still rate a now-Resolved ticket. Rating
--     is validated on the token, not the ticket status, so this keeps working.
--   * Leaves ticket_closures alone: closure records were already written when
--     the tickets first entered Pending Feedback.
--   * The after_ticket_status_update trigger logs each change into
--     ticket_status_logs automatically (changed_by = SYSTEM when unassigned),
--     so the status history is preserved without an explicit insert here.
--
-- Idempotent: re-running matches zero rows once the backlog is cleared. Run:
--   mysql -u root -p pspf_helpdesk < 003_resolve_pending_feedback_backlog.sql
-- =====================================================================

-- Preview before running (optional):
--   SELECT id, title, status, updated_at
--   FROM `tickets`
--   WHERE `status` = 'Pending Feedback';

UPDATE `tickets`
SET `status`     = 'Resolved',
    `updated_at` = NOW()
WHERE `status` = 'Pending Feedback';

-- Verify after running (optional): expect 0 rows.
--   SELECT COUNT(*) AS still_pending
--   FROM `tickets`
--   WHERE `status` = 'Pending Feedback';
