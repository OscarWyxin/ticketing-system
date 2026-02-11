<?php
/**
 * Fix: Actualizar email de Alicia en GHL
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/api/ghl-notifications.php';

header('Content-Type: text/plain');

echo "=== FIX EMAIL ALICIA EN GHL ===\n\n";

// El contactId de Alicia que encontramos
$aliciaContactId = '6E8VpVqE0Y6DeccaGKEA';

// 1. Primero ver qué tiene el contacto actual
echo "1. Obteniendo datos actuales del contacto...\n";
$currentData = ghlApiCall("/contacts/{$aliciaContactId}", 'GET', null, GHL_LOCATION_ID);
echo "   Email actual: " . ($currentData['contact']['email'] ?? 'NO TIENE') . "\n";
echo "   Nombre: " . ($currentData['contact']['name'] ?? 'NO TIENE') . "\n\n";

// 2. Actualizar el email
echo "2. Actualizando email a alicia@wixyn.com...\n";
$updateResult = ghlApiCall("/contacts/{$aliciaContactId}", 'PUT', [
    'email' => 'alicia@wixyn.com'
], GHL_LOCATION_ID);
echo "   Resultado: " . json_encode($updateResult, JSON_PRETTY_PRINT) . "\n\n";

// 3. Verificar que se actualizó
echo "3. Verificando actualización...\n";
$verifyData = ghlApiCall("/contacts/{$aliciaContactId}", 'GET', null, GHL_LOCATION_ID);
echo "   Email ahora: " . ($verifyData['contact']['email'] ?? 'NO TIENE') . "\n\n";

// 4. Probar enviar email
echo "4. Probando envío de email...\n";
$emailData = [
    'type' => 'Email',
    'contactId' => $aliciaContactId,
    'subject' => '🔍 TEST - Revisión Requerida (después del fix)',
    'html' => '<h1>Test de notificación</h1><p>Si recibes esto, el fix funcionó!</p>'
];
$emailResult = ghlApiCall('/conversations/messages', 'POST', $emailData, GHL_LOCATION_ID);
echo "   Resultado email: " . json_encode($emailResult, JSON_PRETTY_PRINT) . "\n\n";

echo "=== FIN FIX ===\n";
