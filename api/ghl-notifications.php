<?php
/**
 * Funciones de Notificación GHL
 * Email y Tareas para usuarios asignados
 */

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

// Log file path
define('NOTIFICATION_LOG', __DIR__ . '/../logs/notifications.log');

/**
 * Helper para log
 */
function notifLog($message) {
    @file_put_contents(NOTIFICATION_LOG, date('Y-m-d H:i:s') . " - $message\n", FILE_APPEND);
}

/**
 * Llamada a la API de GHL (versión para notificaciones)
 */
function ghlNotificationApiCall($endpoint, $method = 'GET', $data = null, $locationId = null) {
    $url = GHL_API_BASE . $endpoint;
    
    $headers = [
        'Authorization: Bearer ' . GHL_API_KEY,
        'Version: ' . GHL_API_VERSION,
        'Content-Type: application/json',
        'Accept: application/json'
    ];
    
    if ($locationId) {
        $headers[] = 'Location: ' . $locationId;
    }
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    notifLog("API Call: $method $endpoint - HTTP $httpCode");
    
    if ($error) {
        notifLog("CURL Error: $error");
        return ['error' => $error, 'httpCode' => $httpCode];
    }
    
    $decoded = json_decode($response, true);
    if (!$decoded) {
        notifLog("Response decode failed: " . substr($response, 0, 200));
    }
    
    return $decoded ?: ['error' => 'Invalid response', 'httpCode' => $httpCode];
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
    
    $result = ghlNotificationApiCall('/conversations/messages', 'POST', $emailData, GHL_LOCATION_ID);
    notifLog("Resultado email: " . json_encode($result));
    
    return [
        'success' => !isset($result['error']),
        'type' => 'email',
        'result' => $result
    ];
}

/**
 * Buscar o crear contacto en GHL
 */
function findOrCreateContact($email, $name) {
    notifLog("Buscando/creando contacto para: $email");
    
    // Primero intentar buscar por email usando search
    $searchResult = ghlNotificationApiCall('/contacts/search/duplicate?locationId=' . GHL_LOCATION_ID . '&email=' . urlencode($email), 'GET', null, GHL_LOCATION_ID);
    notifLog("Busqueda duplicado: " . json_encode($searchResult));
    
    if (!empty($searchResult['contact']['id'])) {
        notifLog("Contacto encontrado via search: " . $searchResult['contact']['id']);
        return $searchResult['contact']['id'];
    }
    
    // Si no existe, crearlo
    notifLog("Creando nuevo contacto...");
    $createResult = ghlNotificationApiCall('/contacts/', 'POST', [
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
    
    $result = ghlNotificationApiCall("/contacts/{$contactId}/tasks", 'POST', $taskData, GHL_LOCATION_ID);
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
 * Funcion principal para notificar asignacion
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
