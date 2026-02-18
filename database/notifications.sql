-- =====================================================
-- Sistema de Notificaciones Internas
-- Tabla para almacenar notificaciones de usuarios
-- =====================================================

CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,                     -- Usuario que recibe la notificación
    type ENUM('mention', 'comment', 'assignment', 'status_change', 'overdue', 'review', 'info_complete') NOT NULL,
    ticket_id INT NOT NULL,
    comment_id INT DEFAULT NULL,              -- Si viene de un comentario
    message TEXT NOT NULL,                    -- Texto de la notificación
    triggered_by INT DEFAULT NULL,            -- Usuario que generó la notificación
    is_read BOOLEAN DEFAULT FALSE,
    email_sent BOOLEAN DEFAULT FALSE,
    ghl_task_created BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    read_at TIMESTAMP NULL,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    FOREIGN KEY (comment_id) REFERENCES comments(id) ON DELETE SET NULL,
    FOREIGN KEY (triggered_by) REFERENCES users(id) ON DELETE SET NULL,
    
    INDEX idx_notifications_user_unread (user_id, is_read),
    INDEX idx_notifications_created (created_at),
    INDEX idx_notifications_ticket (ticket_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vista para obtener notificaciones con información completa
CREATE OR REPLACE VIEW v_notifications AS
SELECT 
    n.*,
    t.ticket_number,
    t.title as ticket_title,
    u.name as triggered_by_name,
    u.avatar as triggered_by_avatar
FROM notifications n
LEFT JOIN tickets t ON n.ticket_id = t.id
LEFT JOIN users u ON n.triggered_by = u.id
ORDER BY n.created_at DESC;
