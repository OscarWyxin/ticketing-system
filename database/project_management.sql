-- =====================================================
-- SISTEMA DE GESTIÓN DE PROYECTOS
-- Ejecutar en MySQL para agregar las nuevas tablas
-- =====================================================

-- 1. Modificar tabla projects existente (agregar campos)
ALTER TABLE projects 
ADD COLUMN IF NOT EXISTS responsible_id INT DEFAULT NULL,
ADD COLUMN IF NOT EXISTS start_date DATE DEFAULT NULL,
ADD COLUMN IF NOT EXISTS end_date DATE DEFAULT NULL,
ADD COLUMN IF NOT EXISTS progress INT DEFAULT 0;

-- Agregar FK para responsible_id (si no existe)
-- ALTER TABLE projects ADD CONSTRAINT fk_projects_responsible FOREIGN KEY (responsible_id) REFERENCES users(id) ON DELETE SET NULL;

-- 2. Tabla de Fases del proyecto
CREATE TABLE IF NOT EXISTS project_phases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT,
    sort_order INT DEFAULT 0,
    status ENUM('pending', 'in_progress', 'completed') DEFAULT 'pending',
    start_date DATE DEFAULT NULL,
    end_date DATE DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
);

-- 3. Tabla de Actividades (dentro de fases)
CREATE TABLE IF NOT EXISTS project_activities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    phase_id INT NOT NULL,
    project_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    contact_user_id INT DEFAULT NULL,
    assigned_to INT DEFAULT NULL,
    notes TEXT,
    video_url VARCHAR(500) DEFAULT NULL,
    status ENUM('pending', 'in_progress', 'completed', 'converted') DEFAULT 'pending',
    ticket_id INT DEFAULT NULL,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (phase_id) REFERENCES project_phases(id) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (contact_user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE SET NULL
);

-- Índices para mejor rendimiento
CREATE INDEX idx_phases_project ON project_phases(project_id);
CREATE INDEX idx_phases_status ON project_phases(status);
CREATE INDEX idx_activities_phase ON project_activities(phase_id);
CREATE INDEX idx_activities_project ON project_activities(project_id);
CREATE INDEX idx_activities_status ON project_activities(status);
CREATE INDEX idx_activities_assigned ON project_activities(assigned_to);
