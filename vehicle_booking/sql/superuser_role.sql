-- Adds the IT 'superuser' role to the Vehicle Booking System.
-- A superuser can switch between every dashboard (Staff, Driver, Supervisor,
-- HRM, Administrator, Viewer) with full rights, and still raise requests as
-- themselves. See superuser.php.
--
--   mysql -u root vehicle_requisition < sql/superuser_role.sql
--
-- Then give IT staff the role via Users > Edit, or directly:
--   UPDATE users SET role = 'superuser' WHERE email = 'someone@pspf.co.sz';

ALTER TABLE `users`
  MODIFY `role` enum('user','driver','supervisor','hrm','admin','viewer','superuser') DEFAULT 'user';
