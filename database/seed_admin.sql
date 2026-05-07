-- Default superadmin account for pspf_helpdesk.
-- Password: Admin@1234  (bcrypt hash — change immediately after first login)
--
-- Run this after schema.sql:
--   mysql -u root pspf_helpdesk < database/seed_admin.sql
--
-- To generate a new hash for a different password, run:
--   php -r "echo password_hash('YourPassword', PASSWORD_BCRYPT);"

USE `pspf_helpdesk`;

INSERT INTO `users` (`Username`, `department`, `division_id`, `Email`, `Password`, `is_active`)
VALUES ('admin', 'ICT', 10, 'admin@pspf.co.sz', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 1);

-- Assign superadmin role (role_id 3) to the new user
INSERT INTO `user_roles` (`user_id`, `role_id`)
VALUES (LAST_INSERT_ID(), 3);
