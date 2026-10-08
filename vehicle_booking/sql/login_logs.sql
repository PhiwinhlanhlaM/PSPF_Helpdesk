-- Records each sign-in to the Vehicle Booking System so the Administrator
-- dashboard can report how many people are using it (System Statistics >
-- Sign-ins). Optional: until this is run the dashboard simply hides the
-- sign-in figures, and login keeps working.
--
--   mysql -u root vehicle_requisition < sql/login_logs.sql

CREATE TABLE IF NOT EXISTS login_logs (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT          NOT NULL,           -- users.user_id
    method       VARCHAR(10)  NOT NULL,           -- 'password' | 'sso'
    ip_address   VARCHAR(45)  NULL,
    user_agent   VARCHAR(255) NULL,
    logged_in_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_logged_in_at (logged_in_at),
    KEY idx_user (user_id)
);
