<?php
require_once __DIR__ . '/config/database.php';

$pdo = getConnection();

$sql = "CREATE TABLE IF NOT EXISTS ticket_tracking_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL DEFAULT (DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 90 DAY)),
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    INDEX idx_ticket_id (ticket_id),
    INDEX idx_token (token)
)";

try {
    $pdo->exec($sql);
    echo json_encode(['success' => true, 'message' => 'Tabla ticket_tracking_tokens creada exitosamente']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
