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
    define('GHL_API_KEY', 'pit-a1b15aff-7e5e-4066-adb9-c9eebab897ea');
}

if (!defined('GHL_API_VERSION')) {
    define('GHL_API_VERSION', '2021-07-28');
}

if (!defined('GHL_LOCATION_ID')) {
    define('GHL_LOCATION_ID', 'NYp3yidBIbmOdKtTKdgU');
}

// Log file path - relative path (works on any server)
define('NOTIFICATION_LOG', __DIR__ . '/../logs/notifications.log');

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
 * WEBHOOK URL para notificaciones WhatsApp
 */
define('WHATSAPP_WEBHOOK_URL', 'https://services.leadconnectorhq.com/hooks/NYp3yidBIbmOdKtTKdgU/webhook-trigger/8bf417a6-ce81-4cfc-886d-0b0d289b590a');

/**
 * Buscar o crear contacto en GHL y obtener su ID
 * Intenta crear el contacto - si ya existe, obtiene el ID del error
 */
function findOrCreateGHLContact($contactPhone, $contactName = null, $contactEmail = null) {
    if (empty($contactPhone)) {
        notifLog("findOrCreateGHLContact: No hay teléfono");
        return null;
    }
    
    notifLog("=== findOrCreateGHLContact: $contactPhone ===");
    
    // Preparar datos del contacto
    $contactData = [
        'phone' => $contactPhone,
        'locationId' => GHL_LOCATION_ID
    ];
    
    if ($contactName) {
        $contactData['name'] = $contactName;
    }
    if ($contactEmail) {
        $contactData['email'] = $contactEmail;
    }
    
    // Intentar crear contacto via API
    $ch = curl_init('https://services.leadconnectorhq.com/contacts/');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($contactData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . GHL_API_KEY,
        'Version: 2021-07-28',
        'Content-Type: application/json',
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $result = json_decode($response, true);
    notifLog("GHL Contact API response (HTTP $httpCode): " . $response);
    
    $contactId = null;
    
    // Si se creó exitosamente (201 o 200)
    if ($httpCode >= 200 && $httpCode < 300) {
        $contactId = $result['contact']['id'] ?? null;
        notifLog("Contacto CREADO: $contactId");
    }
    // Si ya existe (400 con meta.contactId)
    elseif ($httpCode == 400 && !empty($result['meta']['contactId'])) {
        $contactId = $result['meta']['contactId'];
        notifLog("Contacto YA EXISTE: $contactId");
    }
    // Otro error
    else {
        notifLog("ERROR al crear/buscar contacto: " . $response);
    }
    
    return $contactId;
}

/**
 * Enviar notificación via Webhook (reemplaza WhatsApp directo)
 * El webhook dispara el flujo de WhatsApp en GHL
 */
function sendTicketWebhook($pdo, $ticketData, $notificationType) {
    notifLog("=== ENVIANDO WEBHOOK: $notificationType ===");
    
    // PRIMERO: Buscar o crear contacto en GHL para obtener contact_id
    $contactPhone = $ticketData['contact_phone'] ?? null;
    $contactName = $ticketData['contact_name'] ?? null;
    $contactEmail = $ticketData['contact_email'] ?? null;
    $contactId = null;
    
    if ($contactPhone) {
        $contactId = findOrCreateGHLContact($contactPhone, $contactName, $contactEmail);
    }
    
    // Obtener info del creador
    $creatorId = $ticketData['created_by'] ?? $ticketData['user_id'] ?? null;
    $creator = null;
    if ($creatorId && $pdo) {
        $stmt = $pdo->prepare("SELECT id, name, email, phone FROM users WHERE id = ?");
        $stmt->execute([$creatorId]);
        $creator = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Obtener info del asignado
    $assignedId = $ticketData['assigned_to'] ?? null;
    $assigned = null;
    if ($assignedId && $pdo) {
        $stmt = $pdo->prepare("SELECT id, name, email, phone FROM users WHERE id = ?");
        $stmt->execute([$assignedId]);
        $assigned = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Construir payload completo
    $payload = [
        'notification_type' => $notificationType,
        'timestamp' => date('c'),
        'ticket' => [
            'id' => $ticketData['id'] ?? null,
            'ticket_number' => $ticketData['ticket_number'] ?? null,
            'title' => $ticketData['title'] ?? null,
            'description' => $ticketData['description'] ?? null,
            'status' => $ticketData['status'] ?? null,
            'priority' => $ticketData['priority'] ?? null,
            'deliverable' => $ticketData['deliverable'] ?? null,
            'tracking_link' => $ticketData['tracking_link'] ?? $ticketData['link_seguimiento'] ?? null,
            'due_date' => $ticketData['due_date'] ?? null,
            'project_id' => $ticketData['project_id'] ?? null,
            'category_id' => $ticketData['category_id'] ?? null,
            'informacion_pendiente' => $ticketData['informacion_pendiente'] ?? null
        ],
        'contact' => [
            'contact_id' => $contactId,
            'name' => $contactName,
            'email' => $contactEmail,
            'phone' => $contactPhone
        ],
        'creator' => $creator ? [
            'user_id' => $creator['id'],
            'name' => $creator['name'],
            'email' => $creator['email'],
            'phone' => $creator['phone']
        ] : null,
        'assigned_to' => $assigned ? [
            'user_id' => $assigned['id'],
            'name' => $assigned['name'],
            'email' => $assigned['email'],
            'phone' => $assigned['phone']
        ] : null
    ];
    
    notifLog("Webhook payload: " . json_encode($payload));
    
    // Enviar webhook
    $ch = curl_init(WHATSAPP_WEBHOOK_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    notifLog("Webhook response - HTTP $httpCode: " . $response);
    
    if ($error) {
        notifLog("Webhook CURL Error: $error");
        return ['success' => false, 'error' => $error];
    }
    
    $success = $httpCode >= 200 && $httpCode < 300;
    notifLog("Webhook enviado: " . ($success ? 'OK' : 'ERROR'));
    
    return [
        'success' => $success,
        'httpCode' => $httpCode,
        'response' => $response
    ];
}

/**
 * Enviar mensaje WhatsApp directo (DEPRECATED - usa webhook)
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
 * Notificar ticket creado - Envía webhook
 */
function notifyTicketCreatedWA($pdo, $ticketData) {
    try {
        notifLog("=== INICIANDO notifyTicketCreatedWA (WEBHOOK) ===");
        
        if (empty($ticketData['contact_phone'])) {
            notifLog("Error: Sin teléfono en ticketData");
            return ['success' => false, 'error' => 'Sin teléfono'];
        }
        
        notifLog("Ticket: {$ticketData['ticket_number']} - Teléfono: {$ticketData['contact_phone']}");
        
        // Generar link de seguimiento
        $trackingLink = generateTrackingLink($ticketData['id'], $ticketData['ticket_number'], $pdo);
        notifLog("Tracking link generado: " . $trackingLink);
        
        // Agregar tracking link a los datos
        $ticketData['tracking_link'] = $trackingLink;
        
        // Enviar webhook en lugar de WhatsApp directo
        $result = sendTicketWebhook($pdo, $ticketData, 'ticket_created');
        
        notifLog("FIN: notifyTicketCreatedWA - Result: " . json_encode($result));
        
        return [
            'success' => $result['success'],
            'trackingLink' => $trackingLink,
            'webhookResult' => $result
        ];
    } catch (Exception $e) {
        notifLog("EXCEPCIÓN en notifyTicketCreatedWA: " . $e->getMessage() . " - Línea: " . $e->getLine());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Notificar cuando información está pendiente - Envía webhook
 */
function notifyPendingInfo($pdo, $ticketData) {
    $contactPhone = $ticketData['contact_phone'] ?? null;
    $ticketNumber = $ticketData['ticket_number'] ?? 'UNKNOWN';
    
    if (!$contactPhone) {
        notifLog("notifyPendingInfo: No hay teléfono de contacto para ticket $ticketNumber");
        return ['success' => false, 'error' => 'No contact phone'];
    }
    
    notifLog("=== INICIANDO notifyPendingInfo (WEBHOOK) ===");
    notifLog("Ticket: $ticketNumber - Teléfono: $contactPhone");
    
    // Enviar webhook en lugar de WhatsApp directo
    $result = sendTicketWebhook($pdo, $ticketData, 'pending_info');
    
    notifLog("FIN: notifyPendingInfo - Result: " . json_encode($result));
    
    return $result;
}

/**
 * Notificar cuando ticket está en proceso - Envía webhook
 */
function notifyInProgress($pdo, $ticketData) {
    $contactPhone = $ticketData['contact_phone'] ?? null;
    $ticketNumber = $ticketData['ticket_number'] ?? 'UNKNOWN';
    
    if (!$contactPhone) {
        notifLog("notifyInProgress: No hay teléfono de contacto para ticket $ticketNumber");
        return ['success' => false, 'error' => 'No contact phone'];
    }
    
    notifLog("=== INICIANDO notifyInProgress (WEBHOOK) ===");
    notifLog("Ticket: $ticketNumber - Teléfono: $contactPhone");
    
    // Enviar webhook en lugar de WhatsApp directo
    $result = sendTicketWebhook($pdo, $ticketData, 'in_progress');
    
    notifLog("FIN: notifyInProgress - Result: " . json_encode($result));
    
    return $result;
}

/**
 * Buscar o crear contacto en GHL por email
 */
function findOrCreateContact($email, $name) {
    notifLog("Buscando/creando contacto para: $email");
    
    // Primero intentar buscar por email usando search
    $searchResult = ghlApiCall('/contacts/search/duplicate?locationId=' . GHL_LOCATION_ID . '&email=' . urlencode($email), 'GET', null, GHL_LOCATION_ID);
    notifLog("Busqueda duplicado: " . json_encode($searchResult));
    
    if (!empty($searchResult['contact']['id'])) {
        notifLog("Contacto encontrado via search: " . $searchResult['contact']['id']);
        return $searchResult['contact']['id'];
    }
    
    // Si no existe, crearlo
    notifLog("Creando nuevo contacto...");
    $createResult = ghlApiCall('/contacts/', 'POST', [
        'email' => $email,
        'name' => $name,
        'locationId' => GHL_LOCATION_ID
    ], GHL_LOCATION_ID);
    
    notifLog("Resultado crear: " . json_encode($createResult));
    
    // Si ya existe (duplicado), extraer el ID del error
    if (!empty($createResult['meta']['contactId'])) {
        notifLog("Contacto ya existia, usando ID: " . $createResult['meta']['contactId']);
        return $createResult['meta']['contactId'];
    }
    
    if (!empty($createResult['contact']['id'])) {
        notifLog("Contacto creado: " . $createResult['contact']['id']);
        return $createResult['contact']['id'];
    }
    
    notifLog("ERROR: No se pudo obtener contactId");
    return null;
}

/**
 * Enviar notificación por email al usuario asignado
 */
function sendEmailNotification($pdo, $userId, $ticketData) {
    notifLog("=== INICIO sendEmailNotification userId: $userId ===");
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user || empty($user['email'])) {
        notifLog("ERROR: Usuario $userId no encontrado o sin email");
        return ['success' => false, 'error' => 'Usuario no encontrado o sin email'];
    }
    
    notifLog("Usuario: {$user['name']} ({$user['email']})");
    
    // Buscar o crear contacto en GHL
    $contactId = findOrCreateContact($user['email'], $user['name']);
    
    if (!$contactId) {
        return ['success' => false, 'error' => 'No se pudo obtener/crear contacto en GHL'];
    }
    
    // Enviar email
    notifLog("Enviando email...");
    $emailData = [
        'type' => 'Email',
        'contactId' => $contactId,
        'subject' => 'Nuevo Ticket Asignado: ' . $ticketData['ticket_number'],
        'html' => buildEmailTemplate($ticketData, $user)
    ];
    
    $result = ghlApiCall('/conversations/messages', 'POST', $emailData, GHL_LOCATION_ID);
    notifLog("Resultado email: " . json_encode($result));
    
    return [
        'success' => !isset($result['error']),
        'type' => 'email',
        'result' => $result
    ];
}

/**
 * Crear tarea en GHL para el usuario asignado
 */
function createGHLTask($pdo, $userId, $ticketData) {
    notifLog("=== INICIO createGHLTask userId: $userId ===");
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        notifLog("ERROR: Usuario no encontrado");
        return ['success' => false, 'error' => 'Usuario no encontrado'];
    }
    
    notifLog("Usuario: {$user['name']}");
    
    // Buscar o crear contacto
    $contactId = findOrCreateContact($user['email'], $user['name']);
    
    if (!$contactId) {
        notifLog("ERROR: No se pudo obtener/crear contacto para tarea");
        return ['success' => false, 'error' => 'No se pudo obtener/crear contacto en GHL'];
    }
    
    notifLog("Creando tarea para contacto: $contactId");
    
    $dueDate = !empty($ticketData['due_date']) 
        ? $ticketData['due_date'] 
        : date('Y-m-d', strtotime('+3 days'));
    
    $priorityMap = [
        'urgent' => 'URGENTE',
        'high' => 'Alta',
        'medium' => 'Media',
        'low' => 'Baja'
    ];
    $priorityLabel = $priorityMap[$ticketData['priority'] ?? 'medium'] ?? 'Media';
    
    $taskData = [
        'title' => "Ticket {$ticketData['ticket_number']}: {$ticketData['title']}",
        'body' => "Ticket Asignado\n\nNumero: {$ticketData['ticket_number']}\nPrioridad: {$priorityLabel}\nDescripcion:\n{$ticketData['description']}",
        'dueDate' => $dueDate . 'T12:00:00Z',
        'completed' => false,
        'assignedTo' => $user['ghl_user_id'] ?? null
    ];
    
    $result = ghlApiCall("/contacts/{$contactId}/tasks", 'POST', $taskData, GHL_LOCATION_ID);
    notifLog("Resultado tarea: " . json_encode($result));
    
    return [
        'success' => !isset($result['error']),
        'type' => 'task',
        'result' => $result
    ];
}

/**
 * Template de email HTML
 */
function buildEmailTemplate($ticketData, $user) {
    $priorityColors = [
        'urgent' => '#dc3545',
        'high' => '#fd7e14',
        'medium' => '#ffc107',
        'low' => '#28a745'
    ];
    $priorityLabels = [
        'urgent' => 'URGENTE',
        'high' => 'Alta',
        'medium' => 'Media',
        'low' => 'Baja'
    ];
    
    $priority = $ticketData['priority'] ?? 'medium';
    $priorityColor = $priorityColors[$priority] ?? '#ffc107';
    $priorityLabel = $priorityLabels[$priority] ?? 'Media';
    
    $dueDate = !empty($ticketData['due_date']) 
        ? date('d/m/Y', strtotime($ticketData['due_date']))
        : 'No especificada';
    
    $description = nl2br(htmlspecialchars($ticketData['description'] ?? ''));
    
    return "
    <html>
    <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
        <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
            <div style='background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; border-radius: 10px 10px 0 0;'>
                <h1 style='margin:0;'>Nuevo Ticket Asignado</h1>
            </div>
            <div style='background: #f8f9fa; padding: 30px; border: 1px solid #e9ecef;'>
                <p>Hola <strong>{$user['name']}</strong>,</p>
                <p>Se te ha asignado un nuevo ticket:</p>
                
                <div style='background: white; border-radius: 8px; padding: 20px; margin: 15px 0;'>
                    <h2 style='margin-top:0; color: #333;'>{$ticketData['title']}</h2>
                    <p><strong>Numero:</strong> {$ticketData['ticket_number']}</p>
                    <p><strong>Prioridad:</strong> <span style='background:{$priorityColor}; color:white; padding: 3px 10px; border-radius: 10px;'>{$priorityLabel}</span></p>
                    <p><strong>Fecha limite:</strong> {$dueDate}</p>
                    <hr style='border: none; border-top: 1px solid #eee;'>
                    <p><strong>Descripcion:</strong></p>
                    <p style='background: #f8f9fa; padding: 15px; border-radius: 5px;'>{$description}</p>
                </div>
            </div>
            <div style='text-align: center; padding: 20px; color: #6c757d; font-size: 12px;'>
                <p>Sistema de Ticketing - Mensaje automatico</p>
            </div>
        </div>
    </body>
    </html>";
}

/**
 * Función principal para notificar asignación de ticket
 * Envía email + crea tarea en GHL
 */
function notifyTicketAssignment($pdo, $userId, $ticketData) {
    notifLog("========================================");
    notifLog("NOTIFICACION DE TICKET ASIGNADO");
    notifLog("Ticket: " . ($ticketData['ticket_number'] ?? 'N/A'));
    notifLog("Usuario destino: $userId");
    notifLog("========================================");
    
    $results = [];
    
    // Enviar email
    $results['email'] = sendEmailNotification($pdo, $userId, $ticketData);
    
    // Crear tarea en GHL
    $results['task'] = createGHLTask($pdo, $userId, $ticketData);
    
    notifLog("RESULTADO FINAL: " . json_encode($results));
    notifLog("========================================\n");
    
    return $results;
}

/**
 * Notificar solicitud de revisión a Alfonso y Alicia
 */
function notifyReviewRequest($pdo, $ticketData) {
    notifLog("========================================");
    notifLog("NOTIFICACION DE REVISION SOLICITADA");
    notifLog("Ticket: " . ($ticketData['ticket_number'] ?? 'N/A'));
    notifLog("========================================");
    
    $results = [];
    $reviewers = [3, 6]; // Alfonso (direccion@abelross.com) y Alicia (alicia@wixyn.com)
    
    foreach ($reviewers as $userId) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!$user || empty($user['email'])) continue;
        
        // Buscar/crear contacto
        $contactId = findOrCreateContact($user['email'], $user['name']);
        if (!$contactId) continue;
        
        // Enviar email
        $emailData = [
            'type' => 'Email',
            'contactId' => $contactId,
            'subject' => '🔍 Revisión Requerida: ' . $ticketData['ticket_number'],
            'html' => buildReviewEmailTemplate($ticketData, $user)
        ];
        
        $emailResult = ghlApiCall('/conversations/messages', 'POST', $emailData, GHL_LOCATION_ID);
        notifLog("Email a {$user['name']}: " . json_encode($emailResult));
        
        // Crear tarea urgente
        $taskData = [
            'title' => "🔍 REVISAR: {$ticketData['ticket_number']} - {$ticketData['title']}",
            'body' => "Revisión pendiente\n\nTicket: {$ticketData['ticket_number']}\nTítulo: {$ticketData['title']}\n\nEntregable:\n{$ticketData['deliverable']}",
            'dueDate' => date('Y-m-d', strtotime('+1 day')) . 'T12:00:00Z',
            'completed' => false,
            'assignedTo' => $user['ghl_user_id'] ?? null
        ];
        
        $taskResult = ghlApiCall("/contacts/{$contactId}/tasks", 'POST', $taskData, GHL_LOCATION_ID);
        notifLog("Tarea a {$user['name']}: " . json_encode($taskResult));
        
        $results[$userId] = ['email' => $emailResult, 'task' => $taskResult];
    }
    
    return $results;
}

/**
 * Template de email para revisión
 */
function buildReviewEmailTemplate($ticketData, $user) {
    $ticketNumber = $ticketData['ticket_number'] ?? 'N/A';
    $title = htmlspecialchars($ticketData['title'] ?? 'Sin título');
    $deliverable = htmlspecialchars($ticketData['deliverable'] ?? 'No especificado');
    $assignedBy = htmlspecialchars($ticketData['assigned_to_name'] ?? 'Sin asignar');
    
    return "
    <html>
    <body style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
        <div style='background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); padding: 30px; text-align: center;'>
            <h1 style='color: white; margin: 0;'>🔍 Revisión Requerida</h1>
        </div>
        <div style='padding: 30px; background: #f8f9fa;'>
            <p style='font-size: 16px;'>Hola <strong>{$user['name']}</strong>,</p>
            <p>El siguiente ticket necesita tu revisión y aprobación:</p>
            
            <div style='background: white; border-radius: 10px; padding: 20px; margin: 20px 0; border-left: 4px solid #f59e0b;'>
                <p><strong>Ticket:</strong> {$ticketNumber}</p>
                <p><strong>Título:</strong> {$title}</p>
                <p><strong>Enviado por:</strong> {$assignedBy}</p>
            </div>
            
            <div style='background: white; border-radius: 10px; padding: 20px; margin: 20px 0;'>
                <p><strong>📦 Entregable:</strong></p>
                <p style='background: #e8f5e9; padding: 15px; border-radius: 5px; word-break: break-all;'>{$deliverable}</p>
            </div>
            
            <p style='text-align: center;'>
                <a href='http://localhost/ticketing/' style='display: inline-block; background: #f59e0b; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; font-weight: bold;'>
                    Revisar Ticket
                </a>
            </p>
        </div>
        <div style='text-align: center; padding: 20px; color: #6c757d; font-size: 12px;'>
            <p>Sistema de Ticketing - Mensaje automático</p>
        </div>
    </body>
    </html>";
}

/**
 * Notificar al cliente que el ticket fue aprobado - Envía webhook
 */
function notifyTicketApproved($pdo, $ticketData) {
    notifLog("========================================");
    notifLog("NOTIFICACION TICKET APROBADO (WEBHOOK)");
    notifLog("Ticket: " . ($ticketData['ticket_number'] ?? 'N/A'));
    notifLog("Teléfono: " . ($ticketData['contact_phone'] ?? 'N/A'));
    notifLog("========================================");
    
    $phone = $ticketData['contact_phone'] ?? '';
    if (empty($phone)) {
        notifLog("ERROR: No hay teléfono de contacto");
        return ['success' => false, 'error' => 'No hay teléfono'];
    }
    
    // Enviar webhook en lugar de WhatsApp directo
    $result = sendTicketWebhook($pdo, $ticketData, 'ticket_approved');
    
    notifLog("FIN: notifyTicketApproved - Result: " . json_encode($result));
    
    return $result;
}

/**
 * Notificar al agente que el ticket fue rechazado
 */
function notifyTicketRejected($pdo, $ticketData) {
    notifLog("========================================");
    notifLog("NOTIFICACION TICKET RECHAZADO");
    notifLog("Ticket: " . ($ticketData['ticket_number'] ?? 'N/A'));
    notifLog("Agente: " . ($ticketData['assigned_to_name'] ?? 'N/A'));
    notifLog("========================================");
    
    $userId = $ticketData['assigned_to'] ?? null;
    if (!$userId) {
        notifLog("ERROR: No hay agente asignado");
        return ['success' => false, 'error' => 'No hay agente asignado'];
    }
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user || empty($user['email'])) {
        notifLog("ERROR: Usuario no encontrado o sin email");
        return ['success' => false, 'error' => 'Usuario no encontrado'];
    }
    
    $contactId = findOrCreateContact($user['email'], $user['name']);
    if (!$contactId) {
        return ['success' => false, 'error' => 'No se pudo crear contacto'];
    }
    
    $reason = htmlspecialchars($ticketData['reason'] ?? 'Sin motivo especificado');
    
    // Enviar email
    $emailData = [
        'type' => 'Email',
        'contactId' => $contactId,
        'subject' => '❌ Ticket Rechazado: ' . $ticketData['ticket_number'],
        'html' => "
        <html>
        <body style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
            <div style='background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); padding: 30px; text-align: center;'>
                <h1 style='color: white; margin: 0;'>❌ Ticket Rechazado</h1>
            </div>
            <div style='padding: 30px; background: #f8f9fa;'>
                <p style='font-size: 16px;'>Hola <strong>{$user['name']}</strong>,</p>
                <p>El siguiente ticket ha sido rechazado y requiere correcciones:</p>
                
                <div style='background: white; border-radius: 10px; padding: 20px; margin: 20px 0; border-left: 4px solid #ef4444;'>
                    <p><strong>Ticket:</strong> {$ticketData['ticket_number']}</p>
                    <p><strong>Título:</strong> {$ticketData['title']}</p>
                </div>
                
                <div style='background: #fef2f2; border-radius: 10px; padding: 20px; margin: 20px 0;'>
                    <p><strong>📝 Motivo del rechazo:</strong></p>
                    <p style='color: #dc2626;'>{$reason}</p>
                </div>
                
                <p>Por favor, realiza las correcciones necesarias y vuelve a enviar a revisión.</p>
            </div>
        </body>
        </html>"
    ];
    
    $result = ghlApiCall('/conversations/messages', 'POST', $emailData, GHL_LOCATION_ID);
    notifLog("Resultado email rechazo: " . json_encode($result));
    
    return [
        'success' => !isset($result['error']),
        'result' => $result
    ];
}