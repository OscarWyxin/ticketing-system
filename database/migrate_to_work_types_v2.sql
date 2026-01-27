-- =====================================================
-- Migration: Add work types support to tickets
-- =====================================================

USE ticketing_system;

-- Create Projects table first
CREATE TABLE IF NOT EXISTS projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    client_id INT NULL,
    status ENUM('active', 'inactive', 'archived') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Add work_type column if not exists
ALTER TABLE tickets ADD COLUMN IF NOT EXISTS work_type ENUM('puntual', 'recurrente', 'soporte') DEFAULT 'puntual' AFTER source;

-- Add Puntual fields
ALTER TABLE tickets ADD COLUMN IF NOT EXISTS start_date DATETIME DEFAULT CURRENT_TIMESTAMP AFTER work_type;
ALTER TABLE tickets ADD COLUMN IF NOT EXISTS end_date DATETIME NULL AFTER start_date;
ALTER TABLE tickets ADD COLUMN IF NOT EXISTS hours_dedicated DECIMAL(10, 2) DEFAULT 0 AFTER end_date;
ALTER TABLE tickets ADD COLUMN IF NOT EXISTS max_delivery_date DATE NULL AFTER hours_dedicated;
ALTER TABLE tickets ADD COLUMN IF NOT EXISTS project_id INT NULL AFTER max_delivery_date;
ALTER TABLE tickets ADD COLUMN IF NOT EXISTS briefing_url VARCHAR(500) NULL AFTER project_id;
ALTER TABLE tickets ADD COLUMN IF NOT EXISTS video_url VARCHAR(500) NULL AFTER briefing_url;
ALTER TABLE tickets ADD COLUMN IF NOT EXISTS info_pending_status BOOLEAN DEFAULT FALSE AFTER video_url;
ALTER TABLE tickets ADD COLUMN IF NOT EXISTS revision_status BOOLEAN DEFAULT FALSE AFTER info_pending_status;

-- Add Recurrente fields
ALTER TABLE tickets ADD COLUMN IF NOT EXISTS monthly_hours DECIMAL(10, 2) DEFAULT 0 AFTER revision_status;

-- Add Soporte fields
ALTER TABLE tickets ADD COLUMN IF NOT EXISTS score DECIMAL(3, 1) NULL AFTER monthly_hours;

-- Add client_id field
ALTER TABLE tickets ADD COLUMN IF NOT EXISTS client_id INT NULL AFTER assigned_to;

-- Add foreign keys with proper error handling
-- These might already exist, so we check in a separate script if needed

-- Show final structure
DESCRIBE tickets;
