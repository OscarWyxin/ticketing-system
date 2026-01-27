<?php
/**
 * Migration Script - Agregar columna pending_info_details
 */

require_once __DIR__ . '/database.php';

$pdo = getConnection();

try {
    // Verificar si la columna ya existe
    $stmt = $pdo->query("SHOW COLUMNS FROM tickets LIKE 'pending_info_details'");
    $exists = $stmt->fetch();
    
    if (!$exists) {
        $pdo->exec("ALTER TABLE tickets ADD COLUMN pending_info_details LONGTEXT NULL DEFAULT NULL");
        echo json_encode(['success' => true, 'message' => 'Columna pending_info_details agregada correctamente']);
    } else {
        echo json_encode(['success' => true, 'message' => 'Columna pending_info_details ya existe']);
    }
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
