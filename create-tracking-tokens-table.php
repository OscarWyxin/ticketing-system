<?php
/**
 * Crear tabla ticket_tracking_tokens
 */

require_once 'config/db.php';

try {
    $sql = "
    CREATE TABLE IF NOT EXISTS ticket_tracking_tokens (
        id INT PRIMARY KEY AUTO_INCREMENT,
        ticket_id INT NOT NULL,
        token VARCHAR(255) UNIQUE NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        expires_at TIMESTAMP DEFAULT DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 90 DAY),
        FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
        INDEX idx_token (token),
        INDEX idx_ticket (ticket_id)
    )
    ";
    
    $pdo->exec($sql);
    
    echo "✅ Tabla ticket_tracking_tokens creada exitosamente\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>
