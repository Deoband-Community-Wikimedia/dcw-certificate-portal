-- Database Schema for Certificate System (Multi-Tenant Support)

-- Create Organizations Table
CREATE TABLE IF NOT EXISTS organizations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(50) NOT NULL UNIQUE,
    cert_code VARCHAR(20) NOT NULL UNIQUE,
    logo_path VARCHAR(255) NULL,
    home_url VARCHAR(255) NULL,
    about_url VARCHAR(255) NULL,
    contact_email VARCHAR(255) NULL,
    linkedin_org_id VARCHAR(50) NULL,
    social_links TEXT NULL,
    smtp_host VARCHAR(255) NULL,
    smtp_port INT NULL,
    smtp_user VARCHAR(255) NULL,
    smtp_pass_encrypted TEXT NULL,
    smtp_secure VARCHAR(10) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert Default Organization: Deoband Community Wikimedia (ID: 1)
INSERT INTO organizations (id, name, slug, cert_code, logo_path, home_url, about_url, contact_email, linkedin_org_id, is_active)
VALUES (1, 'Deoband Community Wikimedia', 'dcw', 'DCW', 'assets/DCW_logo.png', 'https://dcwwiki.org/', 'https://dcwwiki.org/About', 'moderator@dcwwiki.org', '92536649', 1)
ON DUPLICATE KEY UPDATE id=id;

-- Create Admins Table
CREATE TABLE IF NOT EXISTS admin_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    email VARCHAR(255) NULL,
    role ENUM('super_admin', 'org_admin') NOT NULL DEFAULT 'org_admin',
    reset_token_hash VARCHAR(255) NULL,
    reset_expires_at DATETIME NULL,
    CONSTRAINT fk_admin_users_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL
);

-- Insert Default Admin (username: admin, password: password123, role: super_admin)
-- This account exists only so a new operator can log in the first time.
-- Change the password from "Manage Users" before the portal is reachable
-- from the internet. See the Quick Start Guide in README.md.
INSERT INTO admin_users (username, password_hash, role, organization_id)
VALUES ('admin', '$2y$10$p0Bv6TvSUHEQ6X86NOFaQ.LcuBV8EmkkZhGx51GPUJRx8huMP.GFW', 'super_admin', 1)
ON DUPLICATE KEY UPDATE id=id;

-- Create Events Table
CREATE TABLE IF NOT EXISTS events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NOT NULL DEFAULT 1,
    name VARCHAR(255) NOT NULL,
    category VARCHAR(50) NULL,
    linkedin_caption TEXT NULL,
    custom_verification_text TEXT NULL,
    cert_prefix VARCHAR(50) DEFAULT 'DCW',
    certificate_issue_date DATE NULL,
    completion_date DATE NULL,
    description TEXT NULL,
    partners VARCHAR(255) NULL,
    color_presets TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_events_org_id (organization_id),
    CONSTRAINT fk_events_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
);

-- completion_date is the date the participants actually finished the event
-- (e.g. a course or internship). It is deliberately separate from
-- certificate_issue_date (when the credential was issued) and created_at (when
-- the event row was added), which are unrelated to completion. Leave it NULL
-- for events where a completion date has no meaning (conferences, editathons).

-- color_presets stores a small JSON array of "#RRGGBB" strings (issue #90): custom
-- brand colors an organiser saves in the visual editor so certificate elements can
-- reuse the template's palette. Read/written defensively, so an install that hasn't
-- run the migration below simply shows no custom presets rather than erroring.

-- Create Event Roles Table
CREATE TABLE IF NOT EXISTS event_roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    role_name VARCHAR(255) NOT NULL,
    template_file VARCHAR(255) NOT NULL,
    visual_settings TEXT NULL,
    rotation FLOAT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
);

-- Create Participants Table
CREATE TABLE IF NOT EXISTS participants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NOT NULL DEFAULT 1,
    full_name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_org_participant (organization_id, email),
    CONSTRAINT fk_participants_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
);

-- Create Event Participants Junction Table
CREATE TABLE IF NOT EXISTS event_participants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    role_id INT NULL,
    participant_id INT NOT NULL,
    certificate_id VARCHAR(50) UNIQUE,
    custom_certificate_text VARCHAR(255) NULL,
    issue_date DATE NULL,
    notification_sent TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_event_id (event_id),
    INDEX idx_participant_id (participant_id),
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (role_id) REFERENCES event_roles(id) ON DELETE SET NULL,
    FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE,
    UNIQUE KEY unique_participant_event (event_id, participant_id)
);

-- Create Audit Logs Table
CREATE TABLE IF NOT EXISTS audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NULL,
    admin_username VARCHAR(50) NOT NULL,
    action_type VARCHAR(50) NOT NULL,
    details VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_logs_org_id (organization_id),
    CONSTRAINT fk_audit_logs_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL
);

-- Create Email Logs Table
CREATE TABLE IF NOT EXISTS email_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    certificate_id VARCHAR(50) NULL,
    recipient_email VARCHAR(255) NOT NULL,
    status VARCHAR(20) NOT NULL,
    -- Which flow produced this row: 'notification', 'download', or 'password_reset'.
    trigger_type VARCHAR(20) NOT NULL DEFAULT 'download',
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (certificate_id) REFERENCES event_participants(certificate_id) ON DELETE CASCADE
);
