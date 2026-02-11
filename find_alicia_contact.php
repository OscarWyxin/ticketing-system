<?php
/**
 * Buscar contacto correcto de Alicia por email info@conmenospersonal.io
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/api/ghl-notifications.php';

header('Content-Type: text/plain');

echo "=== BUSCAR CONTACTO ALICIA ===\n\n";

// Buscar por el email que mencionó el usuario
$email = 'info@conmenospersonal.io';
echo "1. Buscando contacto con email: $email\n";
$searchResult = ghlApiCall('/contacts/search/duplicate?locationId=' . GHL_LOCATION_ID . '&email=' . urlencode($email), 'GET', null, GHL_LOCATION_ID);
echo "   Resultado: " . json_encode($searchResult, JSON_PRETTY_PRINT) . "\n\n";

if (!empty($searchResult['contact']['id'])) {
    $contactId = $searchResult['contact']['id'];
    echo "2. ContactId encontrado: $contactId\n";
    
    // Obtener datos completos
    $contactData = ghlApiCall("/contacts/{$contactId}", 'GET', null, GHL_LOCATION_ID);
    echo "   Nombre: " . ($contactData['contact']['firstName'] ?? '') . " " . ($contactData['contact']['lastName'] ?? '') . "\n";
    echo "   Email: " . ($contactData['contact']['email'] ?? 'NO TIENE') . "\n";
    echo "   Phone: " . ($contactData['contact']['phone'] ?? 'NO TIENE') . "\n\n";
    
    // Probar enviar email a este contacto
    echo "3. Probando envío de email a este contacto...\n";
    $emailData = [
        'type' => 'Email',
        'contactId' => $contactId,
        'subject' => '🔍 TEST - Revisión Requerida para Alicia',
        'html' => '<h1>Test de notificación</h1><p>Si recibes esto, las notificaciones funcionan!</p>'
    ];
    $emailResult = ghlApiCall('/conversations/messages', 'POST', $emailData, GHL_LOCATION_ID);
    echo "   Resultado: " . json_encode($emailResult, JSON_PRETTY_PRINT) . "\n\n";
}

echo "=== FIN ===\n";
