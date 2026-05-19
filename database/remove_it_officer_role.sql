-- Remove explicit it_officer role assignments.
-- ICT-department agents are now recognised as IT officers automatically
-- via the isITOfficer() helper (agent role + department = 'ICT').
-- Run this ONCE after deploying the code changes.

USE `pspf_helpdesk`;

DELETE FROM `user_roles`
WHERE `role_id` = (SELECT `id` FROM `roles` WHERE `name` = 'it_officer');

-- The role row itself is left in the table so existing data references don't break.
-- It is no longer assigned to anyone and the code no longer checks for it.
