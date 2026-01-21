-- =====================================================
-- Sistema de Ticketing - Schema de Base de Datos
-- Compatible con GHL Embedding
-- =====================================================

CREATE DATABASE IF NOT EXISTS ticketing_system 
CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE ticketing_system;

-- Tabla de Usuarios/Agentes
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) UNIQUE NOT NULL,
    password_hash VARCHAR(255) DEFAULT NULL,
    role ENUM('agency_admin', 'agency_agent', 'client_admin', 'client_user') DEFAULT 'client_user',
    account_type ENUM('agency', 'client') DEFAULT 'client',
    account_name VARCHAR(150) DEFAULT NULL,
    ghl_user_id VARCHAR(100) DEFAULT NULL,
    ghl_location_id VARCHAR(100) DEFAULT NULL,
    ghl_contact_id VARCHAR(100) DEFAULT NULL,
    avatar VARCHAR(255) DEFAULT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    last_login TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Tabla de Cuentas/Sub-cuentas (GHL Locations)
CREATE TABLE IF NOT EXISTS accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    account_type ENUM('agency', 'client') DEFAULT 'client',
    ghl_location_id VARCHAR(100) DEFAULT NULL,
    contact_email VARCHAR(150) DEFAULT NULL,
    contact_phone VARCHAR(50) DEFAULT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Tabla de Categorías
CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    color VARCHAR(7) DEFAULT '#6366f1',
    icon VARCHAR(50) DEFAULT 'folder',
    active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tabla de Tickets
CREATE TABLE IF NOT EXISTS tickets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_number VARCHAR(20) UNIQUE NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    status ENUM('open', 'in_progress', 'waiting', 'resolved', 'closed') DEFAULT 'open',
    priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
    category_id INT DEFAULT NULL,
    account_id INT DEFAULT NULL,
    created_by INT DEFAULT NULL,
    assigned_to INT DEFAULT NULL,
    ticket_type ENUM('internal', 'client') DEFAULT 'client',
    source ENUM('internal', 'external', 'form', 'api') DEFAULT 'internal',
    ghl_form_id VARCHAR(100) DEFAULT NULL,
    ghl_contact_id VARCHAR(100) DEFAULT NULL,
    ghl_location_id VARCHAR(100) DEFAULT NULL,
    contact_name VARCHAR(150) DEFAULT NULL,
    contact_email VARCHAR(150) DEFAULT NULL,
    contact_phone VARCHAR(50) DEFAULT NULL,
    related_url VARCHAR(500) DEFAULT NULL,
    due_date DATE DEFAULT NULL,
    resolved_at TIMESTAMP NULL,
    closed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
);

-- Tabla de Comentarios/Respuestas
CREATE TABLE IF NOT EXISTS comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    user_id INT DEFAULT NULL,
    author_name VARCHAR(100) DEFAULT NULL,
    author_email VARCHAR(150) DEFAULT NULL,
    content TEXT NOT NULL,
    is_internal BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Tabla de Archivos Adjuntos
CREATE TABLE IF NOT EXISTS attachments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT DEFAULT NULL,
    comment_id INT DEFAULT NULL,
    filename VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    file_type VARCHAR(100),
    file_size INT,
    uploaded_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    FOREIGN KEY (comment_id) REFERENCES comments(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Tabla de Historial de Actividad
CREATE TABLE IF NOT EXISTS activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    user_id INT DEFAULT NULL,
    action VARCHAR(100) NOT NULL,
    old_value TEXT DEFAULT NULL,
    new_value TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Tabla de Etiquetas/Tags
CREATE TABLE IF NOT EXISTS tags (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    color VARCHAR(7) DEFAULT '#8b5cf6',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tabla intermedia Tickets-Tags
CREATE TABLE IF NOT EXISTS ticket_tags (
    ticket_id INT NOT NULL,
    tag_id INT NOT NULL,
    PRIMARY KEY (ticket_id, tag_id),
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
);

-- Índices para mejor rendimiento
CREATE INDEX idx_tickets_status ON tickets(status);
CREATE INDEX idx_tickets_priority ON tickets(priority);
CREATE INDEX idx_tickets_created_at ON tickets(created_at);
CREATE INDEX idx_tickets_assigned_to ON tickets(assigned_to);
CREATE INDEX idx_comments_ticket_id ON comments(ticket_id);

-- Insertar datos iniciales

-- Cuenta principal de la agencia
INSERT INTO accounts (name, account_type, contact_email) VALUES
('ConMenosPeersonal', 'agency', 'admin@conmenospeersonal.com');

-- Cuentas de clientes de ejemplo
INSERT INTO accounts (name, account_type, contact_email) VALUES
('Cliente Demo 1', 'client', 'cliente1@email.com'),
('Cliente Demo 2', 'client', 'cliente2@email.com'),
('Cliente Demo 3', 'client', 'cliente3@email.com');

INSERT INTO categories (name, color, icon) VALUES
('Soporte Técnico', '#ef4444', 'wrench'),
('Ventas', '#22c55e', 'shopping-cart'),
('Facturación', '#f59e0b', 'credit-card'),
('General', '#6366f1', 'help-circle'),
('Urgente', '#dc2626', 'alert-triangle');

-- Usuario admin de la agencia
INSERT INTO users (name, email, role, account_type, account_name) VALUES
('Administrador', 'admin@conmenospeersonal.com', 'agency_admin', 'agency', 'ConMenosPeersonal'),
('Agente Soporte', 'soporte@conmenospeersonal.com', 'agency_agent', 'agency', 'ConMenosPeersonal');

INSERT INTO tags (name, color) VALUES
('Bug', '#ef4444'),
('Mejora', '#22c55e'),
('Pregunta', '#3b82f6'),
('Documentación', '#8b5cf6'),
('Urgente', '#f59e0b');
