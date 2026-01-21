<?php
/**
 * API de Integración con GoHighLevel
 * Maneja sincronización de locations y usuarios
 */

require_once __DIR__ . '/../config/database.php';
setCorsHeaders();

// Configuración GHL
define('GHL_API_BASE', 'https://services.leadconnectorhq.com');
define('GHL_API_KEY', 'pit-2c52c956-5347-4a29-99a8-723a0e2d4afd');
define('GHL_API_VERSION', '2021-07-28');

// IMPORTANTE: Para Private Integration Tokens, necesitas especificar tu Company ID
// Encuéntralo en: Settings > Business Profile > Company ID en GHL
define('GHL_COMPANY_ID', 'Pv6up4LdwbGskR3X9qdH');

// Location ID principal para sincronización
define('GHL_LOCATION_ID', 'sBhcSc6UurgGMeTV10TC'); // <-- Agregar tu Company ID aquí

$pdo = getConnection();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'sync-locations':
        syncLocations($pdo);
        break;
    case 'sync-users':
        syncUsers($pdo, $_GET['location_id'] ?? null);
        break;
    case 'get-locations':
        getLocations($pdo);
        break;
    case 'get-ghl-users':
        getGHLUsers($pdo);
        break;
    case 'test-connection':
        testConnection();
        break;
    case 'debug-token':
        debugToken();
        break;
    default:
        echo json_encode(['error' => 'Acción no válida', 'actions' => ['sync-locations', 'sync-users', 'get-locations', 'get-ghl-users', 'test-connection', 'debug-token']]);
}

/**
 * Llamada a la API de GHL
 */
function ghlApiCall($endpoint, $method = 'GET', $data = null, $locationId = null) {
    $url = GHL_API_BASE . $endpoint;
    
    $headers = [
        'Authorization: Bearer ' . GHL_API_KEY,
        'Version: ' . GHL_API_VERSION,
        'Content-Type: application/json',
        'Accept: application/json'
    ];
    
    // Para algunos endpoints se necesita Location ID en header
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
    
    if ($error) {
        return ['error' => $error, 'httpCode' => $httpCode];
    }
    
    $decoded = json_decode($response, true);
    
    // Log para debug
    @file_put_contents(__DIR__ . '/../logs/ghl_api.log', 
        date('Y-m-d H:i:s') . " - $method $endpoint - HTTP $httpCode\n" . 
        "Response: " . substr($response, 0, 1000) . "\n\n", 
        FILE_APPEND);
    
    return $decoded ?: ['error' => 'Invalid response', 'raw' => $response, 'httpCode' => $httpCode];
}

/**
 * Debug del token - muestra info útil
 */
function debugToken() {
    $tokenInfo = [
        'token_type' => 'Private Integration Token (pit-)',
        'token_preview' => substr(GHL_API_KEY, 0, 20) . '...',
        'api_version' => GHL_API_VERSION,
        'company_id_set' => !empty(GHL_COMPANY_ID),
        'instructions' => [
            '1. Ve a Settings > Company en tu cuenta GHL',
            '2. Copia tu Company ID',
            '3. Edita api/ghl.php y agrega el Company ID en GHL_COMPANY_ID',
            '4. Asegúrate que tu Private Integration tiene los scopes: locations.readonly, users.readonly'
        ]
    ];
    
    // Intentar obtener info del token
    $result = ghlApiCall('/oauth/installedLocations?companyId=' . GHL_COMPANY_ID . '&limit=10');
    
    echo json_encode([
        'token_info' => $tokenInfo,
        'api_test' => $result,
        'next_steps' => empty(GHL_COMPANY_ID) 
            ? 'Necesitas agregar tu Company ID en api/ghl.php' 
            : 'Verifica los scopes de tu Private Integration'
    ], JSON_PRETTY_PRINT);
}

/**
 * Probar conexión con GHL
 */
function testConnection() {
    if (empty(GHL_LOCATION_ID)) {
        echo json_encode([
            'success' => false,
            'error' => 'Location ID no configurado',
            'instructions' => 'Edita api/ghl.php y agrega tu Location ID'
        ]);
        return;
    }
    
    // Probar obteniendo usuarios de la location
    $result = ghlApiCall('/users/?locationId=' . GHL_LOCATION_ID);
    
    if (isset($result['error']) || isset($result['statusCode'])) {
        echo json_encode([
            'success' => false,
            'error' => $result['message'] ?? $result['error'] ?? 'Error desconocido',
            'details' => $result
        ]);
        return;
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Conexión exitosa con GHL',
        'users_found' => count($result['users'] ?? []),
        'location_id' => GHL_LOCATION_ID
    ]);
}

/**
 * Sincronizar Locations (Sub-cuentas) desde GHL
 */
function syncLocations($pdo) {
    if (empty(GHL_COMPANY_ID)) {
        echo json_encode([
            'success' => false,
            'error' => 'Company ID no configurado. Edita api/ghl.php línea 18.'
        ]);
        return;
    }
    
    // Usar endpoint correcto para Private Integration
    $result = ghlApiCall('/oauth/installedLocations?companyId=' . GHL_COMPANY_ID . '&limit=100');
    
    if (isset($result['error']) || isset($result['statusCode'])) {
        echo json_encode([
            'success' => false,
            'error' => $result['message'] ?? $result['error'] ?? 'Error al obtener locations',
            'details' => $result
        ]);
        return;
    }
    
    $locations = $result['locations'] ?? [];
    $synced = 0;
    
    foreach ($locations as $loc) {
        $location = $loc['location'] ?? $loc;
        
        $stmt = $pdo->prepare("
            INSERT INTO accounts (name, account_type, ghl_location_id, contact_email, contact_phone)
            VALUES (?, 'client', ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                name = VALUES(name),
                contact_email = VALUES(contact_email),
                contact_phone = VALUES(contact_phone),
                updated_at = NOW()
        ");
        
        $stmt->execute([
            $location['name'] ?? 'Sin nombre',
            $location['id'] ?? $location['_id'] ?? null,
            $location['email'] ?? null,
            $location['phone'] ?? null
        ]);
        $synced++;
    }
    
    echo json_encode([
        'success' => true,
        'synced' => $synced,
        'message' => "Sincronizados $synced sub-cuentas",
        'data' => $locations
    ]);
}

/**
 * Sincronizar Usuarios desde GHL
 */
function syncUsers($pdo, $locationId = null) {
    $locId = $locationId ?: GHL_LOCATION_ID;
    
    if (empty($locId)) {
        echo json_encode([
            'success' => false,
            'error' => 'Location ID requerido. Configura GHL_LOCATION_ID en api/ghl.php'
        ]);
        return;
    }
    
    $result = ghlApiCall("/users/?locationId=$locId");
    
    if (isset($result['error']) || isset($result['statusCode'])) {
        echo json_encode([
            'success' => false,
            'error' => $result['message'] ?? $result['error'] ?? 'Error al obtener usuarios',
            'details' => $result
        ]);
        return;
    }
    
    $users = $result['users'] ?? [];
    $synced = 0;
    
    foreach ($users as $user) {
        // Saltar usuarios eliminados
        if ($user['deleted'] ?? false) continue;
        
        // Determinar rol basado en el tipo de usuario en GHL
        $role = 'agency_agent';
        $accountType = 'agency';
        
        if (isset($user['roles']['type'])) {
            if ($user['roles']['type'] === 'agency') {
                $role = ($user['roles']['role'] === 'admin') ? 'agency_admin' : 'agency_agent';
                $accountType = 'agency';
            } else {
                $role = ($user['roles']['role'] === 'admin') ? 'client_admin' : 'client_user';
                $accountType = 'client';
            }
        }
        
        $fullName = trim(($user['firstName'] ?? '') . ' ' . ($user['lastName'] ?? ''));
        $avatar = $user['profilePhoto'] ?? null;
        
        $stmt = $pdo->prepare("
            INSERT INTO users (name, email, role, account_type, avatar, ghl_user_id, ghl_location_id)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                name = VALUES(name),
                role = VALUES(role),
                avatar = VALUES(avatar),
                ghl_user_id = VALUES(ghl_user_id),
                ghl_location_id = VALUES(ghl_location_id),
                updated_at = NOW()
        ");
        
        $stmt->execute([
            $fullName ?: $user['email'],
            $user['email'],
            $role,
            $accountType,
            $avatar,
            $user['id'] ?? null,
            $locId
        ]);
        $synced++;
    }
    
    echo json_encode([
        'success' => true,
        'synced' => $synced,
        'message' => "Sincronizados $synced usuarios de GHL"
    ]);
}

/**
 * Enviar notificación por email al usuario asignado
 * Usa la API de Conversations de GHL
 */
function sendEmailNotification($pdo, $userId, $ticketData) {
    // Obtener datos del usuario
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user || empty($user['email'])) {
        return ['success' => false, 'error' => 'Usuario no encontrado o sin email'];
    }
    
    // Primero necesitamos buscar o crear el contacto en GHL
    $contactId = $user['ghl_user_id'] ?? null;
    
    if (!$contactId) {
        // Buscar contacto por email
        $searchResult = ghlApiCall('/contacts/lookup?email=' . urlencode($user['email']), 'GET', null, GHL_LOCATION_ID);
        
        if (!empty($searchResult['contacts'][0]['id'])) {
            $contactId = $searchResult['contacts'][0]['id'];
        } else {
            // Crear contacto si no existe
            $createResult = ghlApiCall('/contacts/', 'POST', [
                'email' => $user['email'],
                'name' => $user['name'],
                'locationId' => GHL_LOCATION_ID
            ], GHL_LOCATION_ID);
            
            if (!empty($createResult['contact']['id'])) {
                $contactId = $createResult['contact']['id'];
            }
        }
    }
    
    if (!$contactId) {
        return ['success' => false, 'error' => 'No se pudo obtener/crear contacto en GHL'];
    }
    
    // Enviar email usando Conversations API
    $emailData = [
        'type' => 'Email',
        'contactId' => $contactId,
        'subject' => '🎫 Nuevo Ticket Asignado: ' . $ticketData['ticket_number'],
        'message' => buildEmailTemplate($ticketData, $user),
        'emailFrom' => 'noreply@' . parse_url(GHL_API_BASE, PHP_URL_HOST)
    ];
    
    $result = ghlApiCall('/conversations/messages', 'POST', $emailData, GHL_LOCATION_ID);
    
    // Log de la notificación
    @file_put_contents(__DIR__ . '/../logs/notifications.log', 
        date('Y-m-d H:i:s') . " - Email a {$user['email']} para ticket {$ticketData['ticket_number']}\n" .
        "Resultado: " . json_encode($result) . "\n\n", 
        FILE_APPEND);
    
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
    // Obtener datos del usuario
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        return ['success' => false, 'error' => 'Usuario no encontrado'];
    }
    
    // Buscar contacto por email para asociar la tarea
    $contactId = null;
    $searchResult = ghlApiCall('/contacts/lookup?email=' . urlencode($user['email']), 'GET', null, GHL_LOCATION_ID);
    
    if (!empty($searchResult['contacts'][0]['id'])) {
        $contactId = $searchResult['contacts'][0]['id'];
    } else {
        // Crear contacto si no existe
        $createResult = ghlApiCall('/contacts/', 'POST', [
            'email' => $user['email'],
            'name' => $user['name'],
            'locationId' => GHL_LOCATION_ID
        ], GHL_LOCATION_ID);
        
        if (!empty($createResult['contact']['id'])) {
            $contactId = $createResult['contact']['id'];
        }
    }
    
    if (!$contactId) {
        return ['success' => false, 'error' => 'No se pudo obtener/crear contacto en GHL'];
    }
    
    // Calcular fecha de vencimiento
    $dueDate = !empty($ticketData['due_date']) 
        ? $ticketData['due_date'] 
        : date('Y-m-d', strtotime('+3 days'));
    
    // Mapear prioridad a descripción
    $priorityMap = [
        'urgent' => '🔴 URGENTE',
        'high' => '🟠 Alta',
        'medium' => '🟡 Media',
        'low' => '🟢 Baja'
    ];
    $priorityLabel = $priorityMap[$ticketData['priority'] ?? 'medium'] ?? '🟡 Media';
    
    // Crear tarea
    $taskData = [
        'title' => "🎫 {$ticketData['ticket_number']}: {$ticketData['title']}",
        'body' => "**Ticket Asignado**\n\n" .
                  "📋 **Número:** {$ticketData['ticket_number']}\n" .
                  "📌 **Prioridad:** {$priorityLabel}\n" .
                  "📝 **Descripción:**\n{$ticketData['description']}\n\n" .
                  "🔗 Ver ticket en el sistema de ticketing",
        'dueDate' => $dueDate . 'T12:00:00Z',
        'completed' => false,
        'assignedTo' => $user['ghl_user_id'] ?? null
    ];
    
    $result = ghlApiCall("/contacts/{$contactId}/tasks", 'POST', $taskData, GHL_LOCATION_ID);
    
    // Log de la tarea
    @file_put_contents(__DIR__ . '/../logs/notifications.log', 
        date('Y-m-d H:i:s') . " - Tarea creada para {$user['name']} - Ticket {$ticketData['ticket_number']}\n" .
        "Resultado: " . json_encode($result) . "\n\n", 
        FILE_APPEND);
    
    return [
        'success' => !isset($result['error']),
        'type' => 'task',
        'result' => $result
    ];
}

/**
 * Template de email para notificación
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
    
    return "
    <html>
    <head>
        <style>
            body { font-family: 'Segoe UI', Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; border-radius: 10px 10px 0 0; }
            .content { background: #f8f9fa; padding: 30px; border: 1px solid #e9ecef; }
            .ticket-info { background: white; border-radius: 8px; padding: 20px; margin: 15px 0; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
            .priority-badge { display: inline-block; padding: 5px 12px; border-radius: 20px; color: white; font-weight: bold; font-size: 12px; }
            .footer { text-align: center; padding: 20px; color: #6c757d; font-size: 12px; }
            .btn { display: inline-block; padding: 12px 30px; background: #667eea; color: white; text-decoration: none; border-radius: 5px; margin-top: 20px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1 style='margin:0;'>🎫 Nuevo Ticket Asignado</h1>
                <p style='margin:10px 0 0 0; opacity: 0.9;'>Se te ha asignado un ticket que requiere tu atención</p>
            </div>
            <div class='content'>
                <p>Hola <strong>{$user['name']}</strong>,</p>
                <p>Se te ha asignado un nuevo ticket en el sistema:</p>
                
                <div class='ticket-info'>
                    <h2 style='margin-top:0; color: #333;'>{$ticketData['title']}</h2>
                    <p><strong>Número:</strong> {$ticketData['ticket_number']}</p>
                    <p><strong>Prioridad:</strong> <span class='priority-badge' style='background:{$priorityColor};'>{$priorityLabel}</span></p>
                    <p><strong>Fecha límite:</strong> {$dueDate}</p>
                    <hr style='border: none; border-top: 1px solid #eee; margin: 15px 0;'>
                    <p><strong>Descripción:</strong></p>
                    <p style='background: #f8f9fa; padding: 15px; border-radius: 5px;'>" . nl2br(htmlspecialchars($ticketData['description'])) . "</p>
                </div>
                
                <center>
                    <a href='#' class='btn'>Ver Ticket en el Sistema</a>
                </center>
            </div>
            <div class='footer'>
                <p>Este es un mensaje automático del Sistema de Ticketing.</p>
                <p>Por favor, no respondas directamente a este email.</p>
            </div>
        </div>
    </body>
    </html>";
}

/**
 * Función principal para notificar asignación
 * Llama a ambos métodos: email y tarea
 */
function notifyTicketAssignment($pdo, $userId, $ticketData) {
    $results = [];
    
    // Enviar email
    $results['email'] = sendEmailNotification($pdo, $userId, $ticketData);
    
    // Crear tarea en GHL
    $results['task'] = createGHLTask($pdo, $userId, $ticketData);
    
    return $results;
}

/**
 * Obtener locations guardadas en BD local
 */
function getLocations($pdo) {
    $stmt = $pdo->query("
        SELECT * FROM accounts 
        WHERE is_active = 1 
        ORDER BY account_type DESC, name ASC
    ");
    
    echo json_encode([
        'success' => true,
        'data' => $stmt->fetchAll()
    ]);
}

/**
 * Obtener usuarios de GHL guardados en BD local
 */
function getGHLUsers($pdo) {
    $role = $_GET['role'] ?? '';
    $accountType = $_GET['account_type'] ?? '';
    
    $where = ['1=1'];
    $params = [];
    
    if ($role) {
        $where[] = "role = ?";
        $params[] = $role;
    }
    
    if ($accountType) {
        $where[] = "account_type = ?";
        $params[] = $accountType;
    }
    
    $whereClause = implode(' AND ', $where);
    
    $stmt = $pdo->prepare("
        SELECT u.*, 
               (SELECT COUNT(*) FROM tickets t WHERE t.assigned_to = u.id) as assigned_tickets,
               (SELECT COUNT(*) FROM tickets t WHERE t.assigned_to = u.id AND t.status IN ('open', 'in_progress')) as open_assigned
        FROM users u 
        WHERE $whereClause AND u.is_active = 1
        ORDER BY u.role, u.name ASC
    ");
    
    $stmt->execute($params);
    
    echo json_encode([
        'success' => true,
        'data' => $stmt->fetchAll()
    ]);
}
