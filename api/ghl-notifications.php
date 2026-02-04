<?php

/**
 * Funciones de Notificación GHL
 * Email y Tareas para usuarios asignados
 */

// Incluir ghl.php una sola vez
require_once __DIR__ . '/ghl.php';

// Configuración GHL - usa variables de entorno (definidas en ghl.php)
if (!defined('GHL_API_BASE')) {
    define('GHL_API_BASE', getenv('GHL_API_BASE') ?: 'https://services.leadconnectorhq.com');
}

if (!defined('GHL_API_KEY')) {
    define('GHL_API_KEY', getenv('GHL_API_KEY') ?: '');
}

if (!defined('GHL_API_VERSION')) {
    define('GHL_API_VERSION', getenv('GHL_API_VERSION') ?: '2021-07-28');
}

if (!defined('GHL_LOCATION_ID')) {
    define('GHL_LOCATION_ID', getenv('GHL_LOCATION_ID') ?: '');
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
    $token = hash('sha256', $ticketId . $ticketNumber . time() . bin2hex(random_bytes(8)));
    
    // Guardar token en BD con expiración de 90 días
    if ($pdo) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO ticket_tracking_tokens (ticket_id, ticket_number, token, created_at, expires_at) 
                VALUES (?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 90 DAY))
                ON DUPLICATE KEY UPDATE token = VALUES(token), ticket_number = VALUES(ticket_number), created_at = NOW(), expires_at = DATE_ADD(NOW(), INTERVAL 90 DAY)
            ");
            $stmt->execute([$ticketId, $ticketNumber, $token]);
            notifLog("Token guardado para ticket $ticketNumber: " . substr($token, 0, 20) . "...");
        } catch (Exception $e) {
            notifLog("Error guardando token: " . $e->getMessage());
        }
    }
    
    // URL del cliente - usar dominio de producción si está disponible
    $baseUrl = getenv('APP_URL') ?: ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    return $baseUrl . '/ticket-tracking.php?id=' . urlencode($ticketNumber) . '&token=' . substr($token, 0, 20);
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
 * Enviar email al contacto del ticket
 * @param PDO $pdo - Conexión a BD
 * @param array $ticketData - Datos del ticket
 * @param string $notificationType - Tipo: ticket_created, pending_info, in_progress, ticket_approved
 */
function sendEmailToContact($pdo, $ticketData, $notificationType) {
    notifLog("=== ENVIANDO EMAIL AL CONTACTO: $notificationType ===");
    
    $contactEmail = $ticketData['contact_email'] ?? null;
    $contactName = $ticketData['contact_name'] ?? 'Cliente';
    $contactPhone = $ticketData['contact_phone'] ?? null;
    
    if (empty($contactEmail)) {
        notifLog("sendEmailToContact: No hay email de contacto");
        return ['success' => false, 'error' => 'No contact email'];
    }
    
    notifLog("Email destino: $contactEmail - Nombre: $contactName");
    
    // Buscar o crear contacto en GHL
    $contactId = null;
    if ($contactPhone) {
        $contactId = findOrCreateGHLContact($contactPhone, $contactName, $contactEmail);
    } else {
        $contactId = findOrCreateContact($contactEmail, $contactName);
    }
    
    if (!$contactId) {
        notifLog("ERROR: No se pudo obtener/crear contacto en GHL");
        return ['success' => false, 'error' => 'Could not create GHL contact'];
    }
    
    // Generar el template de email
    $emailContent = getClientEmailTemplate($notificationType, $ticketData, $contactName);
    $subject = $emailContent['subject'];
    $html = $emailContent['html'];
    
    // Enviar email via GHL
    $emailData = [
        'type' => 'Email',
        'contactId' => $contactId,
        'subject' => $subject,
        'html' => $html
    ];
    
    $result = ghlApiCall('/conversations/messages', 'POST', $emailData, GHL_LOCATION_ID);
    notifLog("Resultado email a contacto: " . json_encode($result));
    
    $success = !isset($result['error']);
    notifLog("Email enviado: " . ($success ? 'OK' : 'ERROR'));
    
    return [
        'success' => $success,
        'contactId' => $contactId,
        'result' => $result
    ];
}

/**
 * Generar template de email para clientes
 * @param string $type - Tipo de notificación
 * @param array $data - Datos del ticket
 * @param string $contactName - Nombre del contacto
 * @return array - ['subject' => string, 'html' => string]
 */
function getClientEmailTemplate($type, $data, $contactName) {
    $ticketNumber = $data['ticket_number'] ?? 'N/A';
    $title = htmlspecialchars($data['title'] ?? 'Sin título');
    $description = nl2br(htmlspecialchars($data['description'] ?? ''));
    $priority = $data['priority'] ?? 'medium';
    $trackingLink = $data['tracking_link'] ?? '#';
    $deliverable = htmlspecialchars($data['deliverable'] ?? '');
    $assignedName = htmlspecialchars($data['assigned_to_name'] ?? 'Nuestro equipo');
    $pendingInfo = htmlspecialchars($data['informacion_pendiente'] ?? 'Por favor, proporciona la información solicitada.');
    
    $priorityColors = [
        'urgent' => '#dc3545',
        'high' => '#fd7e14', 
        'medium' => '#ffc107',
        'low' => '#28a745'
    ];
    $priorityLabels = [
        'urgent' => 'Urgente',
        'high' => 'Alta',
        'medium' => 'Media',
        'low' => 'Baja'
    ];
    $priorityColor = $priorityColors[$priority] ?? '#ffc107';
    $priorityLabel = $priorityLabels[$priority] ?? 'Media';
    
    switch ($type) {
        case 'ticket_created':
            return [
                'subject' => "✅ Ticket #{$ticketNumber} Creado - {$title}",
                'html' => "
<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
</head>
<body style='margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, \"Helvetica Neue\", Arial, sans-serif; background-color: #f4f4f5;'>
    <table role='presentation' width='100%' cellspacing='0' cellpadding='0' style='background-color: #f4f4f5; padding: 40px 20px;'>
        <tr>
            <td align='center'>
                <table role='presentation' width='600' cellspacing='0' cellpadding='0' style='background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);'>
                    <!-- Header -->
                    <tr>
                        <td style='background: linear-gradient(135deg, #8b5cf6 0%, #6366f1 50%, #4f46e5 100%); padding: 40px 30px; text-align: center;'>
                            <div style='font-size: 48px; margin-bottom: 15px;'>✅</div>
                            <h1 style='margin: 0; color: #ffffff; font-size: 28px; font-weight: 700;'>¡Ticket Creado!</h1>
                            <p style='margin: 10px 0 0; color: rgba(255,255,255,0.9); font-size: 16px;'>Hemos recibido tu solicitud</p>
                        </td>
                    </tr>
                    <!-- Content -->
                    <tr>
                        <td style='padding: 40px 30px;'>
                            <p style='margin: 0 0 25px; font-size: 16px; color: #374151;'>Hola <strong>{$contactName}</strong>,</p>
                            <p style='margin: 0 0 30px; font-size: 16px; color: #6b7280; line-height: 1.6;'>Tu ticket ha sido creado exitosamente y nuestro equipo lo revisará pronto.</p>
                            
                            <!-- Ticket Info Box -->
                            <table role='presentation' width='100%' cellspacing='0' cellpadding='0' style='background: linear-gradient(135deg, #faf5ff 0%, #f0f9ff 100%); border-radius: 12px; border: 1px solid #e9d5ff;'>
                                <tr>
                                    <td style='padding: 25px;'>
                                        <table role='presentation' width='100%' cellspacing='0' cellpadding='0'>
                                            <tr>
                                                <td style='padding-bottom: 15px;'>
                                                    <span style='color: #6b7280; font-size: 12px; text-transform: uppercase; letter-spacing: 1px;'>Número de Ticket</span>
                                                    <div style='color: #8b5cf6; font-size: 24px; font-weight: 700; margin-top: 5px;'>#{$ticketNumber}</div>
                                                </td>
                                                <td style='padding-bottom: 15px; text-align: right;'>
                                                    <span style='background-color: {$priorityColor}; color: #ffffff; padding: 6px 14px; border-radius: 20px; font-size: 13px; font-weight: 600;'>{$priorityLabel}</span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td colspan='2' style='padding-top: 15px; border-top: 1px solid #e9d5ff;'>
                                                    <span style='color: #6b7280; font-size: 12px; text-transform: uppercase; letter-spacing: 1px;'>Asunto</span>
                                                    <div style='color: #1f2937; font-size: 18px; font-weight: 600; margin-top: 5px;'>{$title}</div>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- CTA Button -->
                            <table role='presentation' width='100%' cellspacing='0' cellpadding='0' style='margin-top: 30px;'>
                                <tr>
                                    <td align='center'>
                                        <a href='{$trackingLink}' style='display: inline-block; background: linear-gradient(135deg, #8b5cf6 0%, #6366f1 100%); color: #ffffff; padding: 16px 40px; text-decoration: none; border-radius: 10px; font-weight: 600; font-size: 16px;'>
                                            📋 Seguir Mi Ticket
                                        </a>
                                    </td>
                                </tr>
                            </table>
                            
                            <p style='margin: 30px 0 0; font-size: 14px; color: #9ca3af; text-align: center;'>Te notificaremos cuando haya actualizaciones en tu ticket.</p>
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td style='background-color: #f9fafb; padding: 25px 30px; text-align: center; border-top: 1px solid #e5e7eb;'>
                            <p style='margin: 0; font-size: 13px; color: #9ca3af;'>Sistema de Tickets • Mensaje automático</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>"
            ];
            
        case 'pending_info':
            return [
                'subject' => "⚠️ Información Requerida - Ticket #{$ticketNumber}",
                'html' => "
<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
</head>
<body style='margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, \"Helvetica Neue\", Arial, sans-serif; background-color: #f4f4f5;'>
    <table role='presentation' width='100%' cellspacing='0' cellpadding='0' style='background-color: #f4f4f5; padding: 40px 20px;'>
        <tr>
            <td align='center'>
                <table role='presentation' width='600' cellspacing='0' cellpadding='0' style='background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);'>
                    <!-- Header -->
                    <tr>
                        <td style='background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); padding: 40px 30px; text-align: center;'>
                            <div style='font-size: 48px; margin-bottom: 15px;'>⚠️</div>
                            <h1 style='margin: 0; color: #ffffff; font-size: 28px; font-weight: 700;'>Información Requerida</h1>
                            <p style='margin: 10px 0 0; color: rgba(255,255,255,0.9); font-size: 16px;'>Necesitamos más datos para continuar</p>
                        </td>
                    </tr>
                    <!-- Content -->
                    <tr>
                        <td style='padding: 40px 30px;'>
                            <p style='margin: 0 0 25px; font-size: 16px; color: #374151;'>Hola <strong>{$contactName}</strong>,</p>
                            <p style='margin: 0 0 30px; font-size: 16px; color: #6b7280; line-height: 1.6;'>Para poder continuar con tu ticket <strong>#{$ticketNumber}</strong>, necesitamos que nos proporciones información adicional.</p>
                            
                            <!-- Alert Box -->
                            <table role='presentation' width='100%' cellspacing='0' cellpadding='0' style='background-color: #fffbeb; border-radius: 12px; border: 2px solid #fbbf24;'>
                                <tr>
                                    <td style='padding: 25px;'>
                                        <table role='presentation' width='100%' cellspacing='0' cellpadding='0'>
                                            <tr>
                                                <td width='50' valign='top'>
                                                    <div style='font-size: 28px;'>📋</div>
                                                </td>
                                                <td>
                                                    <div style='color: #92400e; font-size: 14px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 10px;'>Información Solicitada</div>
                                                    <div style='color: #78350f; font-size: 16px; line-height: 1.6;'>{$pendingInfo}</div>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- CTA Button -->
                            <table role='presentation' width='100%' cellspacing='0' cellpadding='0' style='margin-top: 30px;'>
                                <tr>
                                    <td align='center'>
                                        <a href='{$trackingLink}' style='display: inline-block; background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: #ffffff; padding: 16px 40px; text-decoration: none; border-radius: 10px; font-weight: 600; font-size: 16px;'>
                                            💬 Responder Ahora
                                        </a>
                                    </td>
                                </tr>
                            </table>
                            
                            <p style='margin: 30px 0 0; font-size: 14px; color: #9ca3af; text-align: center;'>Tu respuesta nos ayudará a resolver tu solicitud más rápido.</p>
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td style='background-color: #f9fafb; padding: 25px 30px; text-align: center; border-top: 1px solid #e5e7eb;'>
                            <p style='margin: 0; font-size: 13px; color: #9ca3af;'>Sistema de Tickets • Mensaje automático</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>"
            ];
            
        case 'in_progress':
            return [
                'subject' => "🔧 En Proceso - Ticket #{$ticketNumber}",
                'html' => "
<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
</head>
<body style='margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, \"Helvetica Neue\", Arial, sans-serif; background-color: #f4f4f5;'>
    <table role='presentation' width='100%' cellspacing='0' cellpadding='0' style='background-color: #f4f4f5; padding: 40px 20px;'>
        <tr>
            <td align='center'>
                <table role='presentation' width='600' cellspacing='0' cellpadding='0' style='background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);'>
                    <!-- Header -->
                    <tr>
                        <td style='background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%); padding: 40px 30px; text-align: center;'>
                            <div style='font-size: 48px; margin-bottom: 15px;'>🔧</div>
                            <h1 style='margin: 0; color: #ffffff; font-size: 28px; font-weight: 700;'>¡Estamos Trabajando!</h1>
                            <p style='margin: 10px 0 0; color: rgba(255,255,255,0.9); font-size: 16px;'>Tu solicitud está en proceso</p>
                        </td>
                    </tr>
                    <!-- Content -->
                    <tr>
                        <td style='padding: 40px 30px;'>
                            <p style='margin: 0 0 25px; font-size: 16px; color: #374151;'>Hola <strong>{$contactName}</strong>,</p>
                            <p style='margin: 0 0 30px; font-size: 16px; color: #6b7280; line-height: 1.6;'>Queremos informarte que ya estamos trabajando en tu ticket.</p>
                            
                            <!-- Status Box -->
                            <table role='presentation' width='100%' cellspacing='0' cellpadding='0' style='background: linear-gradient(135deg, #eff6ff 0%, #f0f9ff 100%); border-radius: 12px; border: 1px solid #bfdbfe;'>
                                <tr>
                                    <td style='padding: 25px;'>
                                        <table role='presentation' width='100%' cellspacing='0' cellpadding='0'>
                                            <tr>
                                                <td width='60' valign='top'>
                                                    <div style='width: 50px; height: 50px; background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%); border-radius: 12px; text-align: center; line-height: 50px; font-size: 24px;'>⚙️</div>
                                                </td>
                                                <td style='padding-left: 15px;'>
                                                    <div style='color: #6b7280; font-size: 12px; text-transform: uppercase; letter-spacing: 1px;'>Estado Actual</div>
                                                    <div style='color: #1d4ed8; font-size: 20px; font-weight: 700; margin-top: 5px;'>En Proceso</div>
                                                    <div style='color: #6b7280; font-size: 14px; margin-top: 5px;'>Ticket #{$ticketNumber}</div>
                                                </td>
                                            </tr>
                                        </table>
                                        <table role='presentation' width='100%' cellspacing='0' cellpadding='0' style='margin-top: 20px; padding-top: 20px; border-top: 1px solid #bfdbfe;'>
                                            <tr>
                                                <td>
                                                    <div style='color: #6b7280; font-size: 12px; text-transform: uppercase; letter-spacing: 1px;'>Asignado a</div>
                                                    <div style='color: #1f2937; font-size: 16px; font-weight: 600; margin-top: 5px;'>👤 {$assignedName}</div>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- CTA Button -->
                            <table role='presentation' width='100%' cellspacing='0' cellpadding='0' style='margin-top: 30px;'>
                                <tr>
                                    <td align='center'>
                                        <a href='{$trackingLink}' style='display: inline-block; background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%); color: #ffffff; padding: 16px 40px; text-decoration: none; border-radius: 10px; font-weight: 600; font-size: 16px;'>
                                            👁️ Ver Progreso
                                        </a>
                                    </td>
                                </tr>
                            </table>
                            
                            <p style='margin: 30px 0 0; font-size: 14px; color: #9ca3af; text-align: center;'>Te notificaremos cuando esté listo.</p>
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td style='background-color: #f9fafb; padding: 25px 30px; text-align: center; border-top: 1px solid #e5e7eb;'>
                            <p style='margin: 0; font-size: 13px; color: #9ca3af;'>Sistema de Tickets • Mensaje automático</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>"
            ];
            
        case 'ticket_approved':
            return [
                'subject' => "🎉 ¡Completado! - Ticket #{$ticketNumber}",
                'html' => "
<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
</head>
<body style='margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, \"Helvetica Neue\", Arial, sans-serif; background-color: #f4f4f5;'>
    <table role='presentation' width='100%' cellspacing='0' cellpadding='0' style='background-color: #f4f4f5; padding: 40px 20px;'>
        <tr>
            <td align='center'>
                <table role='presentation' width='600' cellspacing='0' cellpadding='0' style='background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);'>
                    <!-- Header -->
                    <tr>
                        <td style='background: linear-gradient(135deg, #10b981 0%, #059669 100%); padding: 40px 30px; text-align: center;'>
                            <div style='font-size: 48px; margin-bottom: 15px;'>🎉</div>
                            <h1 style='margin: 0; color: #ffffff; font-size: 28px; font-weight: 700;'>¡Ticket Completado!</h1>
                            <p style='margin: 10px 0 0; color: rgba(255,255,255,0.9); font-size: 16px;'>Tu solicitud ha sido finalizada</p>
                        </td>
                    </tr>
                    <!-- Content -->
                    <tr>
                        <td style='padding: 40px 30px;'>
                            <p style='margin: 0 0 25px; font-size: 16px; color: #374151;'>Hola <strong>{$contactName}</strong>,</p>
                            <p style='margin: 0 0 30px; font-size: 16px; color: #6b7280; line-height: 1.6;'>¡Excelentes noticias! Tu ticket <strong>#{$ticketNumber}</strong> ha sido completado y aprobado.</p>
                            
                            <!-- Success Box -->
                            <table role='presentation' width='100%' cellspacing='0' cellpadding='0' style='background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%); border-radius: 12px; border: 1px solid #6ee7b7;'>
                                <tr>
                                    <td style='padding: 25px;'>
                                        <table role='presentation' width='100%' cellspacing='0' cellpadding='0'>
                                            <tr>
                                                <td width='60' valign='top'>
                                                    <div style='width: 50px; height: 50px; background: linear-gradient(135deg, #10b981 0%, #059669 100%); border-radius: 50%; text-align: center; line-height: 50px; font-size: 24px;'>✓</div>
                                                </td>
                                                <td style='padding-left: 15px;'>
                                                    <div style='color: #065f46; font-size: 14px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px;'>Entregable</div>
                                                    <div style='color: #047857; font-size: 16px; margin-top: 8px; line-height: 1.5;'>" . ($deliverable ?: 'Tu solicitud ha sido procesada exitosamente.') . "</div>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- CTA Button -->
                            <table role='presentation' width='100%' cellspacing='0' cellpadding='0' style='margin-top: 30px;'>
                                <tr>
                                    <td align='center'>
                                        <a href='{$trackingLink}' style='display: inline-block; background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: #ffffff; padding: 16px 40px; text-decoration: none; border-radius: 10px; font-weight: 600; font-size: 16px;'>
                                            📄 Ver Detalles
                                        </a>
                                    </td>
                                </tr>
                            </table>
                            
                            <p style='margin: 30px 0 0; font-size: 14px; color: #9ca3af; text-align: center;'>¡Gracias por confiar en nosotros!</p>
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td style='background-color: #f9fafb; padding: 25px 30px; text-align: center; border-top: 1px solid #e5e7eb;'>
                            <p style='margin: 0; font-size: 13px; color: #9ca3af;'>Sistema de Tickets • Mensaje automático</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>"
            ];
            
        default:
            return [
                'subject' => "Actualización Ticket #{$ticketNumber}",
                'html' => "<p>Hola {$contactName}, hay una actualización en tu ticket #{$ticketNumber}.</p>"
            ];
    }
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
 * Notificar ticket creado - Envía webhook + email
 */
function notifyTicketCreatedWA($pdo, $ticketData) {
    try {
        notifLog("=== INICIANDO notifyTicketCreatedWA (WEBHOOK + EMAIL) ===");
        
        if (empty($ticketData['contact_phone']) && empty($ticketData['contact_email'])) {
            notifLog("Error: Sin teléfono ni email en ticketData");
            return ['success' => false, 'error' => 'Sin contacto'];
        }
        
        notifLog("Ticket: {$ticketData['ticket_number']} - Tel: " . ($ticketData['contact_phone'] ?? 'N/A') . " - Email: " . ($ticketData['contact_email'] ?? 'N/A'));
        
        // Generar link de seguimiento
        $trackingLink = generateTrackingLink($ticketData['id'], $ticketData['ticket_number'], $pdo);
        notifLog("Tracking link generado: " . $trackingLink);
        
        // Agregar tracking link a los datos
        $ticketData['tracking_link'] = $trackingLink;
        
        $results = ['trackingLink' => $trackingLink];
        
        // Enviar webhook (WhatsApp) si hay teléfono
        if (!empty($ticketData['contact_phone'])) {
            $results['webhook'] = sendTicketWebhook($pdo, $ticketData, 'ticket_created');
        }
        
        // Enviar email si hay email
        if (!empty($ticketData['contact_email'])) {
            $results['email'] = sendEmailToContact($pdo, $ticketData, 'ticket_created');
        }
        
        $success = (!empty($results['webhook']['success']) || !empty($results['email']['success']));
        notifLog("FIN: notifyTicketCreatedWA - Success: " . ($success ? 'YES' : 'NO'));
        
        return [
            'success' => $success,
            'trackingLink' => $trackingLink,
            'results' => $results
        ];
    } catch (Exception $e) {
        notifLog("EXCEPCIÓN en notifyTicketCreatedWA: " . $e->getMessage() . " - Línea: " . $e->getLine());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Notificar cuando información está pendiente - Envía webhook + email
 */
function notifyPendingInfo($pdo, $ticketData) {
    $contactPhone = $ticketData['contact_phone'] ?? null;
    $contactEmail = $ticketData['contact_email'] ?? null;
    $ticketNumber = $ticketData['ticket_number'] ?? 'UNKNOWN';
    
    if (!$contactPhone && !$contactEmail) {
        notifLog("notifyPendingInfo: No hay teléfono ni email de contacto para ticket $ticketNumber");
        return ['success' => false, 'error' => 'No contact info'];
    }
    
    notifLog("=== INICIANDO notifyPendingInfo (WEBHOOK + EMAIL) ===");
    notifLog("Ticket: $ticketNumber - Tel: " . ($contactPhone ?? 'N/A') . " - Email: " . ($contactEmail ?? 'N/A'));
    
    $results = [];
    
    // Enviar webhook (WhatsApp) si hay teléfono
    if ($contactPhone) {
        $results['webhook'] = sendTicketWebhook($pdo, $ticketData, 'pending_info');
    }
    
    // Enviar email si hay email
    if ($contactEmail) {
        $results['email'] = sendEmailToContact($pdo, $ticketData, 'pending_info');
    }
    
    $success = (!empty($results['webhook']['success']) || !empty($results['email']['success']));
    notifLog("FIN: notifyPendingInfo - Success: " . ($success ? 'YES' : 'NO'));
    
    return [
        'success' => $success,
        'results' => $results
    ];
}

/**
 * Notificar al agente asignado que la información está completa
 * Se dispara cuando el ticket pasa de 'waiting' a 'in_progress'
 */
function notifyAgentInfoComplete($pdo, $ticketData) {
    $ticketNumber = $ticketData['ticket_number'] ?? 'UNKNOWN';
    $assignedTo = $ticketData['assigned_to'] ?? null;
    
    notifLog("=== INICIANDO notifyAgentInfoComplete ===");
    notifLog("Ticket: $ticketNumber - Asignado a: " . ($assignedTo ?? 'N/A'));
    
    if (!$assignedTo) {
        notifLog("notifyAgentInfoComplete: No hay usuario asignado para ticket $ticketNumber");
        return ['success' => false, 'error' => 'No assigned user'];
    }
    
    // Obtener datos del usuario asignado
    $stmt = $pdo->prepare("SELECT id, name, email, phone FROM users WHERE id = ?");
    $stmt->execute([$assignedTo]);
    $agent = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$agent || empty($agent['email'])) {
        notifLog("ERROR: Usuario $assignedTo no encontrado o sin email");
        return ['success' => false, 'error' => 'Agent not found or no email'];
    }
    
    notifLog("Agente: {$agent['name']} ({$agent['email']})");
    
    // Buscar o crear contacto en GHL
    $contactId = findOrCreateContact($agent['email'], $agent['name']);
    
    if (!$contactId) {
        notifLog("ERROR: No se pudo crear contacto GHL para el agente");
        return ['success' => false, 'error' => 'Could not create GHL contact for agent'];
    }
    
    // Construir email para el agente
    $title = htmlspecialchars($ticketData['title'] ?? 'Sin título');
    $contactName = htmlspecialchars($ticketData['contact_name'] ?? 'Cliente');
    $priority = $ticketData['priority'] ?? 'medium';
    
    $priorityColors = [
        'urgent' => '#dc3545',
        'high' => '#fd7e14', 
        'medium' => '#ffc107',
        'low' => '#28a745'
    ];
    $priorityLabels = [
        'urgent' => 'Urgente',
        'high' => 'Alta',
        'medium' => 'Media',
        'low' => 'Baja'
    ];
    $priorityColor = $priorityColors[$priority] ?? '#ffc107';
    $priorityLabel = $priorityLabels[$priority] ?? 'Media';
    
    $subject = "✅ Información Completa - Ticket #{$ticketNumber}";
    $html = "
<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
</head>
<body style='margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, \"Helvetica Neue\", Arial, sans-serif; background-color: #f4f4f5;'>
    <table role='presentation' width='100%' cellspacing='0' cellpadding='0' style='background-color: #f4f4f5; padding: 40px 20px;'>
        <tr>
            <td align='center'>
                <table role='presentation' width='600' cellspacing='0' cellpadding='0' style='background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);'>
                    <!-- Header -->
                    <tr>
                        <td style='background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%); padding: 40px 30px; text-align: center;'>
                            <div style='font-size: 48px; margin-bottom: 15px;'>✅</div>
                            <h1 style='margin: 0; color: #ffffff; font-size: 28px; font-weight: 700;'>¡Información Completa!</h1>
                            <p style='margin: 10px 0 0; color: rgba(255,255,255,0.9); font-size: 16px;'>El cliente ha proporcionado la información</p>
                        </td>
                    </tr>
                    <!-- Content -->
                    <tr>
                        <td style='padding: 40px 30px;'>
                            <p style='margin: 0 0 25px; font-size: 16px; color: #374151;'>Hola <strong>{$agent['name']}</strong>,</p>
                            <p style='margin: 0 0 30px; font-size: 16px; color: #6b7280; line-height: 1.6;'>El cliente <strong>{$contactName}</strong> ha proporcionado la información solicitada. El ticket está listo para continuar.</p>
                            
                            <!-- Ticket Info Box -->
                            <table role='presentation' width='100%' cellspacing='0' cellpadding='0' style='background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%); border-radius: 12px; border: 1px solid #86efac;'>
                                <tr>
                                    <td style='padding: 25px;'>
                                        <table role='presentation' width='100%' cellspacing='0' cellpadding='0'>
                                            <tr>
                                                <td style='padding-bottom: 15px;'>
                                                    <span style='color: #6b7280; font-size: 12px; text-transform: uppercase; letter-spacing: 1px;'>Número de Ticket</span>
                                                    <div style='color: #16a34a; font-size: 24px; font-weight: 700; margin-top: 5px;'>#{$ticketNumber}</div>
                                                </td>
                                                <td style='padding-bottom: 15px; text-align: right;'>
                                                    <span style='background-color: {$priorityColor}; color: #ffffff; padding: 6px 14px; border-radius: 20px; font-size: 13px; font-weight: 600;'>{$priorityLabel}</span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td colspan='2' style='padding-top: 15px; border-top: 1px solid #86efac;'>
                                                    <span style='color: #6b7280; font-size: 12px; text-transform: uppercase; letter-spacing: 1px;'>Asunto</span>
                                                    <div style='color: #1f2937; font-size: 18px; font-weight: 600; margin-top: 5px;'>{$title}</div>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            
                            <p style='margin: 30px 0 0; font-size: 14px; color: #9ca3af; text-align: center;'>Puedes continuar trabajando en este ticket.</p>
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td style='background-color: #f9fafb; padding: 25px 30px; text-align: center; border-top: 1px solid #e5e7eb;'>
                            <p style='margin: 0; font-size: 13px; color: #9ca3af;'>Sistema de Tickets • Notificación Automática</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>";
    
    // Enviar email via GHL
    $emailData = [
        'type' => 'Email',
        'contactId' => $contactId,
        'subject' => $subject,
        'html' => $html
    ];
    
    $result = ghlApiCall('/conversations/messages', 'POST', $emailData, GHL_LOCATION_ID);
    notifLog("Resultado email a agente: " . json_encode($result));
    
    $success = !isset($result['error']);
    notifLog("FIN: notifyAgentInfoComplete - Success: " . ($success ? 'YES' : 'NO'));
    
    return [
        'success' => $success,
        'agentId' => $assignedTo,
        'agentEmail' => $agent['email'],
        'result' => $result
    ];
}

/**
 * Notificar cuando ticket está en proceso - Envía webhook + email
 */
function notifyInProgress($pdo, $ticketData) {
    $contactPhone = $ticketData['contact_phone'] ?? null;
    $contactEmail = $ticketData['contact_email'] ?? null;
    $ticketNumber = $ticketData['ticket_number'] ?? 'UNKNOWN';
    
    if (!$contactPhone && !$contactEmail) {
        notifLog("notifyInProgress: No hay teléfono ni email de contacto para ticket $ticketNumber");
        return ['success' => false, 'error' => 'No contact info'];
    }
    
    notifLog("=== INICIANDO notifyInProgress (WEBHOOK + EMAIL) ===");
    notifLog("Ticket: $ticketNumber - Tel: " . ($contactPhone ?? 'N/A') . " - Email: " . ($contactEmail ?? 'N/A'));
    
    $results = [];
    
    // Enviar webhook (WhatsApp) si hay teléfono
    if ($contactPhone) {
        $results['webhook'] = sendTicketWebhook($pdo, $ticketData, 'in_progress');
    }
    
    // Enviar email si hay email
    if ($contactEmail) {
        $results['email'] = sendEmailToContact($pdo, $ticketData, 'in_progress');
    }
    
    $success = (!empty($results['webhook']['success']) || !empty($results['email']['success']));
    notifLog("FIN: notifyInProgress - Success: " . ($success ? 'YES' : 'NO'));
    
    return [
        'success' => $success,
        'results' => $results
    ];
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
 * Notificar al cliente que el ticket fue aprobado - Envía webhook + email
 */
function notifyTicketApproved($pdo, $ticketData) {
    notifLog("========================================");
    notifLog("NOTIFICACION TICKET APROBADO (WEBHOOK + EMAIL)");
    notifLog("Ticket: " . ($ticketData['ticket_number'] ?? 'N/A'));
    notifLog("Teléfono: " . ($ticketData['contact_phone'] ?? 'N/A'));
    notifLog("Email: " . ($ticketData['contact_email'] ?? 'N/A'));
    notifLog("========================================");
    
    $phone = $ticketData['contact_phone'] ?? '';
    $email = $ticketData['contact_email'] ?? '';
    
    if (empty($phone) && empty($email)) {
        notifLog("ERROR: No hay teléfono ni email de contacto");
        return ['success' => false, 'error' => 'No contact info'];
    }
    
    $results = [];
    
    // Enviar webhook (WhatsApp) si hay teléfono
    if (!empty($phone)) {
        $results['webhook'] = sendTicketWebhook($pdo, $ticketData, 'ticket_approved');
    }
    
    // Enviar email si hay email
    if (!empty($email)) {
        $results['email'] = sendEmailToContact($pdo, $ticketData, 'ticket_approved');
    }
    
    $success = (!empty($results['webhook']['success']) || !empty($results['email']['success']));
    notifLog("FIN: notifyTicketApproved - Success: " . ($success ? 'YES' : 'NO'));
    
    return [
        'success' => $success,
        'results' => $results
    ];
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

/**
 * Notificar asignación de actividad de proyecto
 */
function notifyActivityAssignment($pdo, $userId, $activityData) {
    notifLog("========================================");
    notifLog("NOTIFICACION DE ACTIVIDAD ASIGNADA");
    notifLog("Actividad: " . ($activityData['title'] ?? 'N/A'));
    notifLog("Proyecto: " . ($activityData['project_name'] ?? 'N/A'));
    notifLog("Usuario destino: $userId");
    notifLog("========================================");
    
    // Obtener datos del usuario
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user || empty($user['email'])) {
        notifLog("Usuario no encontrado o sin email");
        return ['success' => false, 'error' => 'Usuario no encontrado'];
    }
    
    // Buscar/crear contacto en GHL
    $contactId = findOrCreateContact($user['email'], $user['name']);
    if (!$contactId) {
        notifLog("No se pudo crear contacto GHL");
        return ['success' => false, 'error' => 'No se pudo crear contacto'];
    }
    
    // Construir email
    $projectName = $activityData['project_name'] ?? 'Proyecto';
    $title = $activityData['title'] ?? 'Nueva actividad';
    $description = $activityData['description'] ?? '';
    
    $emailData = [
        'type' => 'Email',
        'contactId' => $contactId,
        'subject' => "📋 Nueva actividad asignada: {$title}",
        'html' => "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
        </head>
        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
            <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                <div style='background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 20px; border-radius: 8px 8px 0 0;'>
                    <h1 style='color: white; margin: 0; font-size: 24px;'>📋 Nueva Actividad Asignada</h1>
                </div>
                
                <div style='background: #f9fafb; padding: 20px; border: 1px solid #e5e7eb;'>
                    <p>Hola <strong>{$user['name']}</strong>,</p>
                    
                    <p>Se te ha asignado una nueva actividad en el proyecto <strong>{$projectName}</strong>:</p>
                    
                    <div style='background: white; padding: 15px; border-radius: 8px; border-left: 4px solid #667eea; margin: 15px 0;'>
                        <h3 style='margin: 0 0 10px 0; color: #1f2937;'>{$title}</h3>
                        " . ($description ? "<p style='color: #6b7280; margin: 0;'>{$description}</p>" : "") . "
                    </div>
                    
                    <p style='margin-top: 20px;'>
                        <a href='https://tickets.srv764777.hstgr.cloud/' 
                           style='background: #667eea; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; display: inline-block;'>
                            Ver en el Sistema
                        </a>
                    </p>
                </div>
                
                <div style='text-align: center; padding: 15px; color: #9ca3af; font-size: 12px;'>
                    Sistema de Gestión de Proyectos - Abel Ross
                </div>
            </div>
        </body>
        </html>"
    ];
    
    $result = ghlApiCall('/conversations/messages', 'POST', $emailData, GHL_LOCATION_ID);
    notifLog("Resultado email actividad: " . json_encode($result));
    
    return [
        'success' => !isset($result['error']),
        'result' => $result
    ];
}