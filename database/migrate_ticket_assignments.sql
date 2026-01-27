-- Migración: Tabla de asignaciones múltiples para tickets
-- Fecha: 2026-01-26

-- Crear tabla de asignaciones múltiples
CREATE TABLE IF NOT EXISTS ticket_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    user_id INT NOT NULL,
    role ENUM('primary', 'secondary', 'watcher') DEFAULT 'primary',
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    assigned_by INT NULL,
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY unique_ticket_user (ticket_id, user_id)
);

-- Índices para búsquedas rápidas
CREATE INDEX idx_ticket_assignments_ticket ON ticket_assignments(ticket_id);
CREATE INDEX idx_ticket_assignments_user ON ticket_assignments(user_id);

-- Migrar asignaciones existentes (del campo assigned_to)
INSERT IGNORE INTO ticket_assignments (ticket_id, user_id, role)
SELECT id, assigned_to, 'primary'
FROM tickets
WHERE assigned_to IS NOT NULL;

-- Para tickets de backlog, agregar asignación secundaria a Alfonso (3) y Alicia (14)
-- Solo si no están ya asignados
INSERT IGNORE INTO ticket_assignments (ticket_id, user_id, role)
SELECT t.id, 3, 'primary'
FROM tickets t
WHERE t.backlog = TRUE AND NOT EXISTS (
    SELECT 1 FROM ticket_assignments ta WHERE ta.ticket_id = t.id AND ta.user_id = 3
);

INSERT IGNORE INTO ticket_assignments (ticket_id, user_id, role)
SELECT t.id, 14, 'secondary'
FROM tickets t
WHERE t.backlog = TRUE AND NOT EXISTS (
    SELECT 1 FROM ticket_assignments ta WHERE ta.ticket_id = t.id AND ta.user_id = 14
);

-- Verificar
SELECT 'Migración completada' as status;
SELECT COUNT(*) as total_assignments FROM ticket_assignments;
