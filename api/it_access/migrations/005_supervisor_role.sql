-- =====================================================================
-- IT Access — supervisor permission role
-- Target database: pspf_helpdesk
--
-- Adds the `supervisor` role. A supervisor approves an IT access request
-- BEFORE it reaches the ICT team — the new first step in the chain once the
-- form opens to all users (feedback item 4). A superadmin grants this role to
-- selected people in Settings -> User Management; it appears there
-- automatically because that page lists every role (no allow-list to edit).
--
-- Like it_officer / it_director, this is a PERMISSION role: it is held to
-- unlock the supervisor-approval screen, and is never the active persona shown
-- in the role switcher.
--
-- NOTE: the live `roles.id` column is NOT AUTO_INCREMENT, so the next id is
-- computed explicitly (MAX(id)+1) rather than defaulting to 0 and colliding.
-- Guarded with NOT EXISTS so re-running never duplicates the role or disturbs
-- an edited description.
--
-- Run:  mysql -u root -p pspf_helpdesk < 005_supervisor_role.sql
--
-- Depends on the standard CRM `roles` table (already on live).
-- =====================================================================

INSERT INTO `roles` (`id`, `name`, `description`)
SELECT (SELECT COALESCE(MAX(`id`), -1) + 1 FROM `roles`),
       'supervisor',
       'Supervisor — approves IT access requests from their reports before ICT'
WHERE NOT EXISTS (SELECT 1 FROM `roles` r WHERE r.`name` = 'supervisor');
