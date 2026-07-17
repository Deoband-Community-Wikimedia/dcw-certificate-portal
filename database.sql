-- Database Schema for Certificate System

-- Create Admins Table
CREATE TABLE IF NOT EXISTS admin_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL
);

-- Insert Default Admin (username: Zaidusyy, password: password123)
INSERT INTO admin_users (username, password_hash) 
VALUES ('Zaidusyy', '$2y$10$p0Bv6TvSUHEQ6X86NOFaQ.LcuBV8EmkkZhGx51GPUJRx8huMP.GFW')
ON DUPLICATE KEY UPDATE id=id;

-- Create Events Table
CREATE TABLE IF NOT EXISTS events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    linkedin_caption TEXT NULL,
    custom_verification_text TEXT NULL,
    cert_prefix VARCHAR(50) DEFAULT 'DCW',
    certificate_issue_date DATE NULL,
    description TEXT NULL,
    partners VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

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
    full_name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create Event Participants Junction Table
CREATE TABLE IF NOT EXISTS event_participants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    role_id INT NULL,
    participant_id INT NOT NULL,
    certificate_id VARCHAR(50) UNIQUE,
    custom_certificate_text VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (role_id) REFERENCES event_roles(id) ON DELETE SET NULL,
    FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE,
    UNIQUE KEY unique_participant_event (event_id, participant_id)
);

-- Add Indexes for Performance
CREATE INDEX idx_event_id ON event_participants(event_id);
CREATE INDEX idx_participant_id ON event_participants(participant_id);

-- Create Audit Logs Table
CREATE TABLE IF NOT EXISTS audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_username VARCHAR(50) NOT NULL,
    action_type VARCHAR(50) NOT NULL,
    details VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create Email Logs Table
CREATE TABLE IF NOT EXISTS email_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    certificate_id VARCHAR(50) NOT NULL,
    recipient_email VARCHAR(255) NOT NULL,
    status VARCHAR(20) NOT NULL,
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (certificate_id) REFERENCES event_participants(certificate_id) ON DELETE CASCADE
);
