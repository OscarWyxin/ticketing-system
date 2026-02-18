<?php
/**
 * API de Notificaciones Internas
 * Sistema de notificaciones para usuarios del sistema
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/ghl-notifications.php';

// Solo ejecutar routing si se llama directamente (no como include)
$isDirectCall = !defined('NOTIFICATIONS_INCLUDED');

if ($isDirectCall) {
    setCorsHeaders();
    
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
    
    $pdo = getConnection();
    $action = $_GET['action'] ?? 'list';
    
    switch ($action) {
        case 'list':
            listNotifications($pdo);
            break;
        case 'unread-count':
            getUnreadCount($pdo);
            break;
        case 'mark-read':
            markAsRead($pdo);
            break;
        case 'mark-all-read':
            markAllAsRead($pdo);
            break;
        case 'users':
            listUsers($pdo);
            break;
        default:
            echo json_encode(['error' => 'Acción no válida', 'actions' => ['list', 'unread-count', 'mark-read', 'mark-all-read', 'users']]);
    }
    exit;
}

/**
 * Listar notificaciones de un usuario
 */
function listNotifications($pdo) {
    $userId = $_GET['user_id'] ?? null;
    $limit = intval($_GET['limit'] ?? 50);
    $onlyUnread = isset($_GET['unread']) && $_GET['unread'] === '1';
    
    if (!$userId) {
        echo json_encode(['error' => 'user_id requerido']);
        return;
    }
    
    $sql = "SELECT 
                n.*,
                t.ticket_number,
                t.title as ticket_title,
                u.name as triggered_by_name,
                u.avatar as triggered_by_avatar
            FROM notifications n
            LEFT JOIN tickets t ON n.ticket_id = t.id
            LEFT JOIN users u ON n.triggered_by = u.id
            WHERE n.user_id = ?";
    
    if ($onlyUnread) {
        $sql .= " AND n.is_read = FALSE";
    }
    
    $sql .= " ORDER BY n.created_at DESC LIMIT ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId, $limit]);
    
    echo json_encode([
        'success' => true,
        'data' => $stmt->fetchAll()
    ]);
}

/**
 * Obtener conteo de notificaciones no leídas
 */
function getUnreadCount($pdo) {
    $userId = $_GET['user_id'] ?? null;
    
    if (!$userId) {
        echo json_encode(['error' => 'user_id requerido']);
        return;
    }
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = FALSE");
    $stmt->execute([$userId]);
    $result = $stmt->fetch();
    
    echo json_encode([
        'success' => true,
        'count' => (int)$result['count']
    ]);
}

/**
 * Marcar una notificación como leída
 */
function markAsRead($pdo) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Método no permitido']);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $notificationId = $input['notification_id'] ?? $_GET['id'] ?? null;
    
    if (!$notificationId) {
        echo json_encode(['error' => 'notification_id requerido']);
        return;
    }
    
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = TRUE, read_at = NOW() WHERE id = ?");
    $stmt->execute([$notificationId]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Notificación marcada como leída'
    ]);
}

/**
 * Marcar todas las notificaciones como leídas
 */
function markAllAsRead($pdo) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Método no permitido']);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $userId = $input['user_id'] ?? null;
    
    if (!$userId) {
        echo json_encode(['error' => 'user_id requerido']);
        return;
    }
    
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = TRUE, read_at = NOW() WHERE user_id = ? AND is_read = FALSE");
    $stmt->execute([$userId]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Todas las notificaciones marcadas como leídas',
        'updated' => $stmt->rowCount()
    ]);
}

/**
 * Listar usuarios (para autocomplete de @menciones)
 */
function listUsers($pdo) {
    $search = $_GET['search'] ?? '';
    
    $sql = "SELECT id, name, email, avatar, role FROM users WHERE is_active = 1";
    $params = [];
    
    if ($search) {
        $sql .= " AND (name LIKE ? OR email LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    $sql .= " ORDER BY name ASC LIMIT 20";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    echo json_encode([
        'success' => true,
        'data' => $stmt->fetchAll()
    ]);
}

// =====================================================
// Funciones de Creación de Notificaciones
// =====================================================

/**
 * Detectar @menciones en un texto
 * Busca patrones como @Oscar, @Alfonso, @Alicia
 * @return array IDs de usuarios mencionados
 */
function detectMentions($text, $pdo) {
    // Buscar patrones @palabra (nombre de usuario)
    preg_match_all('/@([a-zA-ZáéíóúñÁÉÍÓÚÑ]+)/u', $text, $matches);
    
    if (empty($matches[1])) {
        return [];
    }
    
    $mentionedIds = [];
    
    foreach ($matches[1] as $name) {
        // Buscar usuario por nombre (parcial)
        $stmt = $pdo->prepare("SELECT id FROM users WHERE name LIKE ? AND is_active = 1 LIMIT 1");
        $stmt->execute(["%$name%"]);
        $user = $stmt->fetch();
        
        if ($user) {
            $mentionedIds[] = (int)$user['id'];
        }
    }
    
    return array_unique($mentionedIds);
}

/**
 * Crear una notificación interna
 * @param PDO $pdo
 * @param int $userId - Usuario que recibe la notificación
 * @param string $type - Tipo: mention, comment, assignment, status_change, overdue, review, info_complete
 * @param int $ticketId
 * @param string $message
 * @param int|null $triggeredBy - Usuario que generó la notificación
 * @param int|null $commentId
 * @param bool $sendEmail - Si debe enviar email via GHL
 * @return int ID de la notificación creada
 */
function createNotification($pdo, $userId, $type, $ticketId, $message, $triggeredBy = null, $commentId = null, $sendEmail = true) {
    // No notificar a uno mismo
    if ($userId == $triggeredBy) {
        return null;
    }
    
    // Insertar notificación
    $stmt = $pdo->prepare("
        INSERT INTO notifications (user_id, type, ticket_id, comment_id, message, triggered_by) 
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$userId, $type, $ticketId, $commentId, $message, $triggeredBy]);
    $notificationId = $pdo->lastInsertId();
    
    // Enviar email via GHL si está habilitado
    if ($sendEmail && $notificationId) {
        try {
            $emailResult = sendInternalNotificationEmail($pdo, $userId, $ticketId, $type, $message, $triggeredBy);
            
            if (!empty($emailResult['success'])) {
                $pdo->prepare("UPDATE notifications SET email_sent = TRUE WHERE id = ?")
                    ->execute([$notificationId]);
            }
        } catch (Exception $e) {
            error_log("Error enviando email de notificación: " . $e->getMessage());
        }
    }
    
    return $notificationId;
}

/**
 * Enviar email de notificación interna via GHL
 */
function sendInternalNotificationEmail($pdo, $userId, $ticketId, $type, $message, $triggeredBy = null) {
    // Obtener datos del usuario destinatario
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user || empty($user['email'])) {
        return ['success' => false, 'error' => 'Usuario sin email'];
    }
    
    // Obtener datos del ticket
    $stmt = $pdo->prepare("SELECT * FROM tickets WHERE id = ?");
    $stmt->execute([$ticketId]);
    $ticket = $stmt->fetch();
    
    if (!$ticket) {
        return ['success' => false, 'error' => 'Ticket no encontrado'];
    }
    
    // Obtener quien generó la notificación
    $triggeredByName = 'Sistema';
    if ($triggeredBy) {
        $stmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
        $stmt->execute([$triggeredBy]);
        $triggerUser = $stmt->fetch();
        if ($triggerUser) {
            $triggeredByName = $triggerUser['name'];
        }
    }
    
    // Determinar asunto y emoji según tipo
    $typeConfig = [
        'mention' => ['emoji' => '💬', 'subject' => 'Te mencionaron'],
        'comment' => ['emoji' => '📝', 'subject' => 'Nuevo comentario de seguimiento'],
        'assignment' => ['emoji' => '📋', 'subject' => 'Ticket asignado'],
        'status_change' => ['emoji' => '🔄', 'subject' => 'Cambio de estado'],
        'overdue' => ['emoji' => '⚠️', 'subject' => 'Ticket en retraso'],
        'review' => ['emoji' => '🔍', 'subject' => 'Revisión solicitada'],
        'info_complete' => ['emoji' => '✅', 'subject' => 'Información completada']
    ];
    
    $config = $typeConfig[$type] ?? ['emoji' => '🔔', 'subject' => 'Notificación'];
    $subject = "{$config['emoji']} {$config['subject']} - Ticket #{$ticket['ticket_number']}";
    
    // Construir HTML del email
    $html = buildNotificationEmailTemplate($user, $ticket, $type, $message, $triggeredByName, $config);
    
    // Buscar o crear contacto en GHL
    $contactId = findOrCreateContact($user['email'], $user['name']);
    
    if (!$contactId) {
        return ['success' => false, 'error' => 'No se pudo crear contacto GHL'];
    }
    
    // Enviar email via GHL
    $emailData = [
        'type' => 'Email',
        'contactId' => $contactId,
        'subject' => $subject,
        'html' => $html
    ];
    
    $result = ghlApiCall('/conversations/messages', 'POST', $emailData, GHL_LOCATION_ID);
    
    return [
        'success' => !isset($result['error']),
        'result' => $result
    ];
}

/**
 * Template de email para notificaciones internas
 */
function buildNotificationEmailTemplate($user, $ticket, $type, $message, $triggeredByName, $config) {
    $ticketNumber = $ticket['ticket_number'];
    $title = htmlspecialchars($ticket['title']);
    $userName = htmlspecialchars($user['name']);
    $messageSafe = htmlspecialchars($message);
    
    // Colores según tipo
    $typeColors = [
        'mention' => ['bg' => '#8b5cf6', 'gradient' => 'linear-gradient(135deg, #8b5cf6 0%, #6366f1 100%)'],
        'comment' => ['bg' => '#3b82f6', 'gradient' => 'linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%)'],
        'assignment' => ['bg' => '#10b981', 'gradient' => 'linear-gradient(135deg, #10b981 0%, #059669 100%)'],
        'status_change' => ['bg' => '#f59e0b', 'gradient' => 'linear-gradient(135deg, #f59e0b 0%, #d97706 100%)'],
        'overdue' => ['bg' => '#ef4444', 'gradient' => 'linear-gradient(135deg, #ef4444 0%, #dc2626 100%)'],
        'review' => ['bg' => '#f59e0b', 'gradient' => 'linear-gradient(135deg, #f59e0b 0%, #d97706 100%)'],
        'info_complete' => ['bg' => '#22c55e', 'gradient' => 'linear-gradient(135deg, #22c55e 0%, #16a34a 100%)']
    ];
    
    $colors = $typeColors[$type] ?? $typeColors['comment'];
    
    return "
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
                        <td style='background: {$colors['gradient']}; padding: 30px; text-align: center;'>
                            <div style='font-size: 40px; margin-bottom: 10px;'>{$config['emoji']}</div>
                            <h1 style='margin: 0; color: #ffffff; font-size: 24px; font-weight: 700;'>{$config['subject']}</h1>
                        </td>
                    </tr>
                    <!-- Content -->
                    <tr>
                        <td style='padding: 30px;'>
                            <p style='margin: 0 0 20px; font-size: 16px; color: #374151;'>Hola <strong>{$userName}</strong>,</p>
                            
                            <!-- Notification Box -->
                            <table role='presentation' width='100%' cellspacing='0' cellpadding='0' style='background: #f8fafc; border-radius: 12px; border-left: 4px solid {$colors['bg']};'>
                                <tr>
                                    <td style='padding: 20px;'>
                                        <div style='color: #6b7280; font-size: 12px; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 8px;'>De: {$triggeredByName}</div>
                                        <div style='color: #1f2937; font-size: 16px; line-height: 1.6;'>{$messageSafe}</div>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- Ticket Info -->
                            <table role='presentation' width='100%' cellspacing='0' cellpadding='0' style='margin-top: 20px; background: #faf5ff; border-radius: 12px; border: 1px solid #e9d5ff;'>
                                <tr>
                                    <td style='padding: 20px;'>
                                        <div style='color: #6b7280; font-size: 12px; text-transform: uppercase; letter-spacing: 1px;'>Ticket</div>
                                        <div style='color: #8b5cf6; font-size: 20px; font-weight: 700; margin-top: 5px;'>#{$ticketNumber}</div>
                                        <div style='color: #1f2937; font-size: 14px; margin-top: 8px;'>{$title}</div>
                                    </td>
                                </tr>
                            </table>
                            
                            <p style='margin: 25px 0 0; font-size: 14px; color: #9ca3af; text-align: center;'>Accede al sistema para ver más detalles.</p>
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td style='background-color: #f9fafb; padding: 20px; text-align: center; border-top: 1px solid #e5e7eb;'>
                            <p style='margin: 0; font-size: 12px; color: #9ca3af;'>Sistema de Tickets • Notificación Automática</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>";
}
