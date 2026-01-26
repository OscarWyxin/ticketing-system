<?php

/**
 * Funciones de Notificación GHL
 * Email y Tareas para usuarios asignados
 */

// Incluir ghl.php una sola vez
require_once __DIR__ . '/ghl.php';

// Configuración GHL (si no están definidas)
if (!defined('GHL_API_BASE')) {
    define('GHL_API_BASE', 'https://services.leadconnectorhq.com');
}

if (!defined('GHL_API_KEY')) {
    define('GHL_API_KEY', 'pit-2c52c956-5347-4a29-99a8-723a0e2d4afd');
}

if (!defined('GHL_API_VERSION')) {
    define('GHL_API_VERSION', '2021-07-28');
}

if (!defined('GHL_LOCATION_ID')) {
    define('GHL_LOCATION_ID', 'sBhcSc6UurgGMeTV10TC');
}

// Log file path - absolute path for Windows
define('NOTIFICATION_LOG', 'C:\\laragon\\www\\Ticketing System\\logs\\notifications.log');

// Log de inclusión
file_put_contents(NOTIFICATION_LOG, date('Y-m-d H:i:s') . " - ghl-notifications.php included\n", FILE_APPEND);

/**
 * Helper para log
 */
function notifLog($message) {
    file_put_contents(NOTIFICATION_LOG, date('Y-m-d H:i:s') . " - $message\n", FILE_APPEND);
}

/**
 * Generar link de seguimiento del ticket
 */
function generateTrackingLink($ticketId, $ticketNumber, $pdo = null) {
    // Generar token
    $token = hash('sha256', $ticketId . $ticketNumber . time());
    
    // Guardar token en BD
    if ($pdo) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO ticket_tracking_tokens (ticket_id, token, created_at) 
                VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE token = VALUES(token), created_at = NOW()
            ");
            $stmt->execute([$ticketId, $token]);
        } catch (Exception $e) {
            notifLog("Error guardando token: " . $e->getMessage());
        }
    }
    
    // URL del cliente
    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
    return $baseUrl . '/ticket-tracking.php?id=' . $ticketNumber . '&token=' . substr($token, 0, 20);
}

/**
 * Actualizar custom fields en contacto GHL
 */
function updateContactCustomFields($contactId, $customFields = []) {
    if (empty($customFields)) {
        return ['success' => true, 'message' => 'No custom fields to update'];
    }
    
    $updateData = [
        'customFields' => $customFields
    ];
    
    $result = ghlApiCall('/contacts/' . $contactId, 'PUT', $updateData, GHL_LOCATION_ID);
    
    notifLog("Custom fields actualizados para contacto {$contactId}: " . json_encode($result));
    
    return [
        'success' => !isset($result['error']),
        'contactId' => $contactId,
        'result' => $result
    ];
}

/**
 * Enviar mensaje WhatsApp directo
 */
function sendWhatsAppMessage($pdo, $contactId, $contactPhone, $messageText) {
    $messageData = [
        'type' => 'WhatsApp',
        'contactId' => $contactId,
        'message' => $messageText,
        'status' => 'pending'
    ];
    
    $result = ghlApiCall('/conversations/messages', 'POST', $messageData, GHL_LOCATION_ID);
    
    notifLog("WhatsApp enviado a {$contactPhone}: " . $messageText);
    
    return [
        'success' => !isset($result['error']),
        'contactId' => $contactId,
        'result' => $result
    ];
}

/**
 * Enviar WhatsApp usando template
 */
function sendWhatsAppTemplate($pdo, $contactPhone, $templateName, $variables = []) {
    // Buscar contacto - usar endpoint correcto con query parameter
    $searchResult = ghlApiCall('/contacts/?locationId=' . GHL_LOCATION_ID . '&query=' . urlencode($contactPhone), 'GET', null, GHL_LOCATION_ID);
    
    $contactId = null;
    if (!empty($searchResult['contacts'][0]['id'])) {
        $contactId = $searchResult['contacts'][0]['id'];
    } else {
        $createResult = ghlApiCall('/contacts/', 'POST', [
            'phone' => $contactPhone,
            'locationId' => GHL_LOCATION_ID
        ], GHL_LOCATION_ID);
        
        if (!empty($createResult['contact']['id'])) {
            $contactId = $createResult['contact']['id'];
        }
    }
    
    if (!$contactId) {
        notifLog("Error: No se pudo obtener/crear contacto para {$contactPhone}");
        return ['success' => false, 'error' => 'No se pudo obtener/crear contacto en GHL'];
    }
    
    // Enviar WhatsApp con template
    // Las variables deben ser un array ordenado para el template
    $variableValues = [];
    if (is_array($variables)) {
        $variableValues = array_values($variables);
    }
    
    $messageData = [
        'type' => 'WhatsApp',
        'contactId' => $contactId,
        'templateName' => $templateName,
        'variables' => $variableValues,
        'status' => 'pending'
    ];
    
    notifLog("Enviando mensaje con template: $templateName, variables: " . json_encode($variableValues));
    $result = ghlApiCall('/conversations/messages', 'POST', $messageData, GHL_LOCATION_ID);
    
    notifLog("WhatsApp enviado a {$contactPhone} con template {$templateName}: " . json_encode($result));
    
    return [
        'success' => !isset($result['error']),
        'contactId' => $contactId,
        'result' => $result
    ];
}

/**
 * Notificar ticket creado
 */
function notifyTicketCreatedWA($pdo, $ticketData) {
    try {
        notifLog("=== INICIANDO notifyTicketCreatedWA ===");
        
        if (empty($ticketData['contact_phone'])) {
            notifLog("Error: Sin teléfono en ticketData");
            return ['success' => false, 'error' => 'Sin teléfono'];
        }
        
        notifLog("INICIANDO: Notificar ticket creado - {$ticketData['ticket_number']} - Teléfono: {$ticketData['contact_phone']}");
        
        // Generar link
        $trackingLink = generateTrackingLink($ticketData['id'], $ticketData['ticket_number'], $pdo);
        
        notifLog("Tracking link generado: " . $trackingLink);
        
        // Buscar o crear contacto
        $searchResult = ghlApiCall('/contacts/?locationId=' . GHL_LOCATION_ID . '&query=' . urlencode($ticketData['contact_phone']), 'GET', null, GHL_LOCATION_ID);
        
        notifLog("Resultado búsqueda: " . json_encode($searchResult));
        
        $contactId = null;
        if (!empty($searchResult['contacts'][0]['id'])) {
            $contactId = $searchResult['contacts'][0]['id'];
            notifLog("Contacto encontrado: $contactId");
        } else {
            notifLog("Contacto no encontrado, creando nuevo...");
            $createResult = ghlApiCall('/contacts/', 'POST', [
                'phone' => $ticketData['contact_phone'],
                'name' => $ticketData['contact_name'] ?? 'Cliente',
                'email' => $ticketData['contact_email'] ?? null,
                'locationId' => GHL_LOCATION_ID
            ], GHL_LOCATION_ID);
            
            notifLog("Contacto create result: " . json_encode($createResult));
            
            // Verificar diferentes estructuras de respuesta
            if (!empty($createResult['contact']['id'])) {
                $contactId = $createResult['contact']['id'];
                notifLog("Contacto creado (estructura 1): $contactId");
            } elseif (!empty($createResult['meta']['contactId'])) {
                $contactId = $createResult['meta']['contactId'];
                notifLog("Contacto creado (estructura 2): $contactId");
            } else {
                notifLog("ERROR: Respuesta de create no tiene contactId: " . json_encode($createResult));
            }
        }
        
        if (!$contactId) {
            notifLog("Error: No se pudo crear contacto para {$ticketData['contact_phone']}");
            return ['success' => false, 'error' => 'No se pudo crear contacto'];
        }
        
        // ACTUALIZAR custom fields ANTES de enviar el mensaje
        notifLog("Actualizando custom fields para contacto: " . $contactId);
        $customFields = [
            [
                'id' => 'ticket_id_field',
                'key' => 'ticket_id',
                'field_value' => $ticketData['ticket_number'] ?? ''
            ],
            [
                'id' => 'link_seguimiento_field',
                'key' => 'link_seguimiento',
                'field_value' => $trackingLink
            ]
        ];
        updateContactCustomFields($contactId, $customFields);
        
        // Enviar WhatsApp con mensaje personalizado
        $messageText = "¡Hola! Tu ticket " . $ticketData['ticket_number'] . " ha sido creado exitosamente. Puedes consultarlo en: " . $trackingLink;
        notifLog("Enviando WhatsApp: " . $messageText);
        $result = sendWhatsAppMessage($pdo, $contactId, $ticketData['contact_phone'], $messageText);
        
        notifLog("FIN: Notificar ticket creado - Result: " . json_encode($result));
        
        return [
            'success' => $result['success'],
            'contactId' => $contactId,
            'trackingLink' => $trackingLink
        ];
    } catch (Exception $e) {
        notifLog("EXCEPCIÓN en notifyTicketCreatedWA: " . $e->getMessage() . " - Línea: " . $e->getLine());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Notificar cuando información está pendiente
 */
function notifyPendingInfo($pdo, $ticketData) {
    $contactPhone = $ticketData['contact_phone'] ?? null;
    $ticketNumber = $ticketData['ticket_number'] ?? 'UNKNOWN';
    
    if (!$contactPhone) {
        notifLog("notifyPendingInfo: No hay teléfono de contacto para ticket $ticketNumber");
        return ['success' => false, 'error' => 'No contact phone'];
    }
    
    notifLog("=== INICIANDO notifyPendingInfo ===");
    notifLog("INICIANDO: Información pendiente - $ticketNumber - Teléfono: $contactPhone");
    
    notifLog("Iniciando búsqueda de contacto");
    
    // PRIMERO: Buscar o crear contacto en GHL
    $searchResult = ghlApiCall('/contacts/?locationId=' . GHL_LOCATION_ID . '&query=' . urlencode($contactPhone), 'GET', null, GHL_LOCATION_ID);
    
    notifLog("Resultado búsqueda: " . json_encode($searchResult));
    
    $contactId = null;
    if (!empty($searchResult['contacts'][0]['id'])) {
        $contactId = $searchResult['contacts'][0]['id'];
        notifLog("Contacto encontrado: " . $contactId);
    } else {
        notifLog("Contacto no encontrado, creando nuevo");
        $createResult = ghlApiCall('/contacts/', 'POST', [
            'phone' => $contactPhone,
            'locationId' => GHL_LOCATION_ID
        ], GHL_LOCATION_ID);
        
        notifLog("Resultado creación contacto: " . json_encode($createResult));
        
        if (!empty($createResult['contact']['id'])) {
            $contactId = $createResult['contact']['id'];
            notifLog("Contacto creado: " . $contactId);
        }
    }
    
    if (!$contactId) {
        notifLog("Error: No se pudo obtener/crear contacto para {$contactPhone}");
        return ['success' => false, 'error' => 'No contact phone'];
    }
    
    // SEGUNDO: Actualizar custom fields en GHL ANTES de enviar el mensaje
    $customFields = [
        [
            'id' => 'ticket_id_field',
            'key' => 'ticket_id',
            'field_value' => $ticketData['ticket_number'] ?? $ticketNumber
        ],
        [
            'id' => 'informacion_pendiente_field',
            'key' => 'informacion_pendiente',
            'field_value' => $ticketData['informacion_pendiente'] ?? ''
        ],
        [
            'id' => 'link_seguimiento_field',
            'key' => 'link_seguimiento',
            'field_value' => $ticketData['link_seguimiento'] ?? ''
        ]
    ];
    
    notifLog("Actualizando custom fields para contacto: " . $contactId);
    updateContactCustomFields($contactId, $customFields);
    
    // TERCERO: Enviar WhatsApp con template copy_info_pendiente2
    $variables = [
        'ticket_id' => $ticketData['ticket_number'] ?? $ticketNumber,
        'informacion_pendiente' => $ticketData['informacion_pendiente'] ?? '',
        'link_seguimiento' => $ticketData['link_seguimiento'] ?? ''
    ];
    
    $templateResult = sendWhatsAppTemplate($pdo, $contactPhone, 'copy_info_pendiente2', $variables);
    
    return $templateResult;
}

/**
 * Notificar cuando ticket está en proceso
 */
function notifyInProgress($pdo, $ticketData) {
    $contactPhone = $ticketData['contact_phone'] ?? null;
    $ticketNumber = $ticketData['ticket_number'] ?? 'UNKNOWN';
    
    if (!$contactPhone) {
        notifLog("notifyInProgress: No hay teléfono de contacto para ticket $ticketNumber");
        return ['success' => false, 'error' => 'No contact phone'];
    }
    
    notifLog("=== INICIANDO notifyInProgress ===");
    notifLog("INICIANDO: Ticket en proceso - $ticketNumber - Teléfono: $contactPhone");
    
    // PRIMERO: Buscar o crear contacto en GHL
    $searchResult = ghlApiCall('/contacts/?locationId=' . GHL_LOCATION_ID . '&query=' . urlencode($contactPhone), 'GET', null, GHL_LOCATION_ID);
    
    $contactId = null;
    if (!empty($searchResult['contacts'][0]['id'])) {
        $contactId = $searchResult['contacts'][0]['id'];
    } else {
        $createResult = ghlApiCall('/contacts/', 'POST', [
            'phone' => $contactPhone,
            'locationId' => GHL_LOCATION_ID
        ], GHL_LOCATION_ID);
        
        if (!empty($createResult['contact']['id'])) {
            $contactId = $createResult['contact']['id'];
        }
    }
    
    if (!$contactId) {
        notifLog("Error: No se pudo obtener/crear contacto para {$contactPhone}");
        return ['success' => false, 'error' => 'No contact phone'];
    }
    
    // SEGUNDO: Enviar WhatsApp con template en_desarrollo
    $variables = [
        'ticket_id' => $ticketData['ticket_number'] ?? $ticketNumber,
        'link_seguimiento' => $ticketData['link_seguimiento'] ?? ''
    ];
    
    $templateResult = sendWhatsAppTemplate($pdo, $contactPhone, 'en_desarrollo', $variables);
    
    return $templateResult;
}
