-- ============================================================
-- MIGRATION: Vendor Team Members (sub-accounts)
-- Run in phpMyAdmin AFTER database.sql and migration_subscription.sql
-- ============================================================
USE product_enquiry;

-- Add per-plan team member limit (0 = no team access on that plan)
ALTER TABLE subscription_plans
    ADD COLUMN IF NOT EXISTS team_member_limit INT DEFAULT 0 AFTER image_limit;

UPDATE subscription_plans SET team_member_limit = 0  WHERE slug = 'free';
UPDATE subscription_plans SET team_member_limit = 5  WHERE slug = 'starter';
UPDATE subscription_plans SET team_member_limit = 10 WHERE slug = 'professional';
UPDATE subscription_plans SET team_member_limit = 50 WHERE slug = 'enterprise';

-- Team member sub-accounts, created by a vendor
CREATE TABLE IF NOT EXISTS vendor_team_members (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    vendor_id       INT NOT NULL,
    name            VARCHAR(100) NOT NULL,
    username        VARCHAR(150) NOT NULL UNIQUE,
    email           VARCHAR(150) DEFAULT NULL,
    phone           VARCHAR(20)  DEFAULT NULL,
    password        VARCHAR(255) NOT NULL,
    designation     VARCHAR(100) DEFAULT NULL,
    permissions     TEXT,                                  -- JSON array of permission keys
    status          ENUM('active','inactive') DEFAULT 'active',
    last_login      DATETIME DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (vendor_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Lightweight activity log so admin/vendor can see what a team member did
CREATE TABLE IF NOT EXISTS vendor_team_activity (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    vendor_id       INT NOT NULL,
    team_member_id  INT NOT NULL,
    action          VARCHAR(150) NOT NULL,
    details         VARCHAR(255) DEFAULT '',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (vendor_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (team_member_id) REFERENCES vendor_team_members(id) ON DELETE CASCADE
);
