-- Director Role Migration
-- Adds the it_director role. Safe to re-run (INSERT IGNORE).

USE `pspf_helpdesk`;

INSERT IGNORE INTO `roles` (`id`, `name`, `description`)
VALUES (5, 'it_director', 'Department Director — provides final sign-off on IT access requests and views department performance');
