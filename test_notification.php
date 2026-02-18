<?php
/**
 * Test de Sistema de Notificaciones con Email
 */
require_once __DIR__ . '/config/database.php';
define('NOTIFICATIONS_INCLUDED', true);
require_once __DIR__ . '/api/notifications.php';

$pdo = getConnection();

echo "=== TEST NOTIFICACIÓN CON EMAIL ===\n\n";

// Crear notificación con email para Oscar (user_id=10)
$result = createNotification(
    $pdo,
    10,                          // userId: Oscar
    'mention',                   // type
    159,                         // ticketId
    'Admin te mencionó: Test de email del sistema de notificaciones',
    1,                           // triggeredBy: Admin
    null,                        // commentId
    true                         // sendEmail = TRUE
);

echo "Notificación creada ID: " . $result . "\n\n";

// Verificar en BD
$stmt = $pdo->query("SELECT id, user_id, type, LEFT(message, 50) as mensaje, email_sent FROM notifications ORDER BY id DESC LIMIT 1");
$notif = $stmt->fetch();
print_r($notif);

echo "\n\nemail_sent = " . ($notif['email_sent'] ? "SÍ" : "NO") . "\n";
