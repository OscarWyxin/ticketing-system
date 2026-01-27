-- =====================================================
-- Migration: Add work types support to tickets
-- =====================================================

USE ticketing_system;

-- Add work_type column if not exists
ALTER TABLE tickets ADD COLUMN work_type ENUM('puntual', 'recurrente', 'soporte') DEFAULT 'puntual' AFTER source;

-- Add Puntual fields
ALTER TABLE tickets ADD COLUMN start_date DATETIME DEFAULT CURRENT_TIMESTAMP AFTER work_type;
ALTER TABLE tickets ADD COLUMN end_date DATETIME NULL AFTER start_date;
ALTER TABLE tickets ADD COLUMN hours_dedicated DECIMAL(10, 2) DEFAULT 0 AFTER end_date;
ALTER TABLE tickets ADD COLUMN max_delivery_date DATE NULL AFTER hours_dedicated;
ALTER TABLE tickets ADD COLUMN project_id INT NULL AFTER max_delivery_date;
ALTER TABLE tickets ADD COLUMN briefing_url VARCHAR(500) NULL AFTER project_id;
ALTER TABLE tickets ADD COLUMN video_url VARCHAR(500) NULL AFTER briefing_url;
ALTER TABLE tickets ADD COLUMN info_pending_status BOOLEAN DEFAULT FALSE AFTER video_url;
ALTER TABLE tickets ADD COLUMN revision_status BOOLEAN DEFAULT FALSE AFTER info_pending_status;

-- Add Recurrente fields
ALTER TABLE tickets ADD COLUMN monthly_hours DECIMAL(10, 2) DEFAULT 0 AFTER revision_status;

-- Add Soporte fields
ALTER TABLE tickets ADD COLUMN score DECIMAL(3, 1) NULL AFTER monthly_hours;

-- Add client_id field
ALTER TABLE tickets ADD COLUMN client_id INT NULL AFTER assigned_to;

-- Create Projects table
CREATE TABLE IF NOT EXISTS projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    client_id INT NULL,
    status ENUM('active', 'inactive', 'archived') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES accounts(id) ON DELETE SET NULL
);

-- Add foreign key constraints if not exist
ALTER TABLE tickets ADD CONSTRAINT fk_tickets_project_id FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL;
ALTER TABLE tickets ADD CONSTRAINT fk_tickets_client_id FOREIGN KEY (client_id) REFERENCES accounts(id) ON DELETE SET NULL;

-- Insert some sample projects
INSERT INTO projects (name, description, client_id, status) 
VALUES 
    ('Proyecto Demo 1', 'Proyecto de ejemplo 1', 2, 'active'),
    ('Proyecto Demo 2', 'Proyecto de ejemplo 2', 3, 'active'),
    ('Proyecto Demo 3', 'Proyecto de ejemplo 3', 4, 'active');

-- Show final structure
DESCRIBE tickets;
