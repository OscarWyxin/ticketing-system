<?php
/**
 * Test directo de notificación a Alicia
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/api/ghl-notifications.php';

header('Content-Type: application/json');

$pdo = getConnection();

// Datos de prueba
$ticketData = [
    'id' => 999,
    'ticket_number' => 'TEST-001',
    'title' => 'Test de notificación a Alicia',
    'description' => 'Esto es una prueba directa',
    'deliverable' => 'https://ejemplo.com/entregable',
    'assigned_to_name' => 'Oscar Calamita'
];

echo "=== TEST NOTIFICACIÓN REVISIÓN ===\n\n";

// Test 1: Verificar contacto de Alicia en GHL
echo "1. Buscando/creando contacto de Alicia en GHL...\n";
$aliciaContactId = findOrCreateContact('alicia@wixyn.com', 'Alicia Urdiales Ramos');
echo "   ContactId Alicia: " . ($aliciaContactId ?: 'NO ENCONTRADO') . "\n\n";

// Test 2: Verificar contacto de Alfonso en GHL
echo "2. Buscando/creando contacto de Alfonso en GHL...\n";
$alfonsoContactId = findOrCreateContact('direccion@abelross.com', 'Alfonso Bello');
echo "   ContactId Alfonso: " . ($alfonsoContactId ?: 'NO ENCONTRADO') . "\n\n";

// Test 3: Intentar enviar email a Alicia
echo "3. Enviando email de prueba a Alicia...\n";
if ($aliciaContactId) {
    $emailData = [
        'type' => 'Email',
        'contactId' => $aliciaContactId,
        'subject' => '🔍 TEST - Revisión Requerida',
        'html' => '<h1>Test de notificación</h1><p>Si recibes esto, las notificaciones funcionan.</p>'
    ];
    
    $result = ghlApiCall('/conversations/messages', 'POST', $emailData, GHL_LOCATION_ID);
    echo "   Resultado: " . json_encode($result, JSON_PRETTY_PRINT) . "\n\n";
}

// Test 4: Intentar enviar email a Alfonso
echo "4. Enviando email de prueba a Alfonso...\n";
if ($alfonsoContactId) {
    $emailData = [
        'type' => 'Email',
        'contactId' => $alfonsoContactId,
        'subject' => '🔍 TEST - Revisión Requerida',
        'html' => '<h1>Test de notificación</h1><p>Si recibes esto, las notificaciones funcionan.</p>'
    ];
    
    $result = ghlApiCall('/conversations/messages', 'POST', $emailData, GHL_LOCATION_ID);
    echo "   Resultado: " . json_encode($result, JSON_PRETTY_PRINT) . "\n\n";
}

echo "=== FIN TEST ===\n";
