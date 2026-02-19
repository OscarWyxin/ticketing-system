-- =====================================================
-- Sistema de Autenticación
-- Tabla de sesiones y campo de contraseña
-- =====================================================

-- Agregar campo password_hash a users
ALTER TABLE users ADD COLUMN IF NOT EXISTS password_hash VARCHAR(255) DEFAULT NULL;

-- Tabla de sesiones de usuario (SIN expiración)
CREATE TABLE IF NOT EXISTS user_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_sessions_token (token),
    INDEX idx_sessions_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Crear usuario SuperAdmin
INSERT INTO users (name, email, role, is_active, created_at) 
VALUES ('SuperAdmin', 'superadmin@ticketing.local', 'super_admin', 1, NOW())
ON DUPLICATE KEY UPDATE role = 'super_admin';

-- Actualizar roles: Alfonso, Alicia, Ángel como admin
UPDATE users SET role = 'admin' WHERE id IN (3, 6, 149);

-- Agentes específicos
UPDATE users SET role = 'agent' WHERE email IN (
    'oscar.calamita@wixyn.com',
    'victoria.aparicio@conmenospersonal.io', 
    'faguerre@abelross.com',
    'andrea@wixyn.com',
    'gabriela.carvajal@wixyn.com'
);

-- Nota: La contraseña se establece desde PHP al ejecutar setup_auth.php
