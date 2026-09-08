-- Tokens that let a supervisor or HRM approve/reject a vehicle request by
-- replying to the notification email. Each token is single-use, tied to one
-- approver and one workflow stage, and expires after a few days.
--
-- Run once against the vehicle_requisition database:
--   mysql -u root vehicle_requisition < email_action_tokens.sql

CREATE TABLE IF NOT EXISTS email_action_tokens (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    token            CHAR(32)    NOT NULL,
    request_id       INT         NOT NULL,
    stage            VARCHAR(20) NOT NULL,          -- 'supervisor' | 'hrm'
    approver_user_id INT         NOT NULL,          -- users.user_id the token was issued to
    used_at          DATETIME    NULL,              -- set when the token is consumed
    result           VARCHAR(20) NULL,              -- 'approved' | 'rejected' once acted on
    expires_at       DATETIME    NOT NULL,
    created_at       DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_token (token),
    KEY idx_request (request_id),
    KEY idx_approver (approver_user_id)
);
