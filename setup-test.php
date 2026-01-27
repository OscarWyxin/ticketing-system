<?php
// Configuración de BD
define('DB_HOST', 'localhost');
define('DB_NAME', 'ticketing_system');
define('DB_USER', 'root');
define('DB_PASS', '');

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
    
    // 1. Crear tabla
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
    echo "✅ Tabla ticket_tracking_tokens creada\n";
    
    // 2. Crear ticket de prueba
    $ticketNumber = 'P-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));
    
    $stmt = $pdo->prepare("
        INSERT INTO tickets (
            ticket_number, title, description, status, priority, 
            assigned_to, contact_name, contact_email, contact_phone,
            work_type, created_by, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    
    $stmt->execute([
        $ticketNumber,
        'Ticket de Prueba WhatsApp',
        'Testing flujo WhatsApp integration con GHL - Número: +57301448821',
        'open',
        'high',
        10, // Oscar
        'Cliente Test',
        'test@example.com',
        '+57301448821',
        'puntual',
        1 // Created by user 1
    ]);
    
    $ticketId = $pdo->lastInsertId();
    echo "✅ Ticket creado: ID=$ticketId, Número=$ticketNumber\n";
    
    // 3. Generar token de seguimiento
    $token = hash('sha256', $ticketId . $ticketNumber . time());
    
    $stmt = $pdo->prepare("
        INSERT INTO ticket_tracking_tokens (ticket_id, token) 
        VALUES (?, ?)
    ");
    
    $stmt->execute([$ticketId, $token]);
    echo "✅ Token generado: " . substr($token, 0, 20) . "...\n";
    
    // 4. Log en actividades
    $stmt = $pdo->prepare("
        INSERT INTO activities (ticket_id, action, description, created_at)
        VALUES (?, ?, ?, NOW())
    ");
    
    $stmt->execute([
        $ticketId,
        'ticket_created',
        'Ticket creado con WhatsApp integration'
    ]);
    
    echo "✅ Ticket de prueba listo\n";
    echo "\n📋 RESUMEN:\n";
    echo "- Ticket ID: $ticketId\n";
    echo "- Ticket Number: $ticketNumber\n";
    echo "- Teléfono: +57301448821\n";
    echo "- Asignado a: Oscar (ID 10)\n";
    echo "- Link de seguimiento: http://localhost/Ticketing%20System/ticket-tracking.php?id=$ticketNumber&token=" . substr($token, 0, 20) . "...\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>
