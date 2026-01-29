<?php
/**
 * API de Tickets - Sistema de Ticketing GHL
 * Endpoints RESTful para gestión de tickets
 */

// Define absolute paths for logging
define('LOGS_PATH', 'C:\\laragon\\www\\Ticketing System\\logs');

// Error handling
error_reporting(E_ALL);
ini_set('display_errors', 1); // Cambiar a 1 para debug
ini_set('log_errors', 1);
ini_set('error_log', LOGS_PATH . '\\php_errors.log');

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("PHP Error [$errno]: $errstr in $errfile:$errline");
    if (defined('STDERR')) {
        fwrite(STDERR, "PHP Error [$errno]: $errstr in $errfile:$errline\n");
    }
    http_response_code(500);
    echo json_encode(['error' => 'Error del servidor: ' . $errstr]);
    exit;
});

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/ghl-notifications.php';

// Verificar si la funciÃ³n existe
if (!function_exists('notifyTicketCreatedWA')) {
    throw new Exception('notifyTicketCreatedWA not loaded');
}

setCorsHeaders();

$pdo = getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ROUTER EXECUTION TEST
error_log('ROUTER EXECUTING: action=' . $action . ' at ' . date('Y-m-d H:i:s'));

// Router simple
switch ($action) {
    case 'test-whatsapp':
        testWhatsAppFlow($pdo);
        break;
    case 'list':
        listTickets($pdo);
        break;
    case 'backlog':
        getBacklogTickets($pdo);
        break;
    case 'get':
        getTicket($pdo, $_GET['id'] ?? 0);
        break;
    case 'create':
        createTicket($pdo);
        break;
    case 'update':
        updateTicket($pdo, $_GET['id'] ?? 0);
        break;
    case 'request-review':
        requestReview($pdo, $_GET['id'] ?? 0);
        break;
    case 'approve':
        approveTicket($pdo, $_GET['id'] ?? 0);
        break;
    case 'reject':
        rejectTicket($pdo, $_GET['id'] ?? 0);
        break;
    case 'delete':
        deleteTicket($pdo, $_GET['id'] ?? 0);
        break;
    case 'delete-multiple':
        deleteMultipleTickets($pdo);
        break;
    case 'stats':
        getStats($pdo);
        break;
    /**
     * Eliminare più ticket tramite array di ID (POST)
     */
    function deleteMultipleTickets($pdo) {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!isset($input['ids']) || !is_array($input['ids']) || empty($input['ids'])) {
            http_response_code(400);
            echo json_encode(['error' => 'IDs non forniti o formato errato']);
            return;
        }
        $ids = $input['ids'];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("DELETE FROM tickets WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        echo json_encode([
            'success' => true,
            'deleted' => $stmt->rowCount(),
            'ids' => $ids
        ]);
    }
    case 'comments':
        handleComments($pdo, $_GET['ticket_id'] ?? 0);
        break;
    case 'tracking':
        getTicketTracking($pdo, $_GET['id'] ?? '', $_GET['token'] ?? '');
        break;
    case 'webhook':
        handleGHLWebhook($pdo);
        break;
    default:
        echo json_encode(['error' => 'Acción no válida', 'actions' => ['list', 'get', 'create', 'update', 'delete', 'stats', 'comments', 'webhook', 'test-whatsapp']]);
        exit;
}

/**
 * Listar tickets con filtros y paginaciÃ³n
 */
function listTickets($pdo) {
    $page = (int)($_GET['page'] ?? 1);
    $limit = (int)($_GET['limit'] ?? 20);
    $offset = ($page - 1) * $limit;
    
    $status = $_GET['status'] ?? '';
    $priority = $_GET['priority'] ?? '';
    $category = $_GET['category'] ?? '';
    $search = $_GET['search'] ?? '';
    $assigned = $_GET['assigned'] ?? '';
    
    $where = [];
    $params = [];
    
    // Excluir tickets en backlog de la lista principal
    $where[] = "(t.backlog = FALSE OR t.backlog IS NULL)";
    
    if ($status) {
        $where[] = "t.status = ?";
        $params[] = $status;
    }
    if ($priority) {
        $where[] = "t.priority = ?";
        $params[] = $priority;
    }
    if ($category) {
        $where[] = "t.category_id = ?";
        $params[] = $category;
    }
    if ($assigned) {
        $where[] = "t.assigned_to = ?";
        $params[] = $assigned;
    }
    if ($search) {
        $where[] = "(t.title LIKE ? OR t.description LIKE ? OR t.ticket_number LIKE ? OR t.contact_name LIKE ?)";
        $searchTerm = "%$search%";
        $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
    }
    
    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    
    // Contar total
    $countSql = "SELECT COUNT(*) FROM tickets t $whereClause";
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetchColumn();
    
    // Obtener tickets
    $sql = "SELECT t.*, 
            c.name as category_name, c.color as category_color,
            u1.name as created_by_name,
            u2.name as assigned_to_name,
            (SELECT COUNT(*) FROM comments WHERE ticket_id = t.id) as comment_count
            FROM tickets t
            LEFT JOIN categories c ON t.category_id = c.id
            LEFT JOIN users u1 ON t.created_by = u1.id
            LEFT JOIN users u2 ON t.assigned_to = u2.id
            $whereClause
            ORDER BY 
                CASE t.priority 
                    WHEN 'urgent' THEN 1 
                    WHEN 'high' THEN 2 
                    WHEN 'medium' THEN 3 
                    ELSE 4 
                END,
                t.created_at DESC
            LIMIT $limit OFFSET $offset";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $tickets = $stmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'data' => $tickets,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => (int)$total,
            'pages' => ceil($total / $limit)
        ]
    ]);
}

/**
 * Obtener tickets del backlog (todos los tickets marcados como backlog)
 */
function getBacklogTickets($pdo) {
    $backlogType = $_GET['type'] ?? 'consultoria'; // 'consultoria' o 'aib'
    
    $sql = "SELECT t.*, 
            c.name as category_name, c.color as category_color,
            p.name as project_name,
            u1.name as created_by_name,
            u2.name as assigned_to_name,
            (SELECT COUNT(*) FROM comments WHERE ticket_id = t.id) as comment_count,
            (SELECT GROUP_CONCAT(CONCAT(u.id, ':', u.name, ':', ta.role) SEPARATOR '|') 
             FROM ticket_assignments ta 
             JOIN users u ON ta.user_id = u.id 
             WHERE ta.ticket_id = t.id) as assigned_users
            FROM tickets t
            LEFT JOIN categories c ON t.category_id = c.id
            LEFT JOIN projects p ON t.project_id = p.id
            LEFT JOIN users u1 ON t.created_by = u1.id
            LEFT JOIN users u2 ON t.assigned_to = u2.id
            WHERE t.backlog = TRUE AND t.backlog_type = ?
            ORDER BY 
                CASE t.priority 
                    WHEN 'urgent' THEN 1 
                    WHEN 'high' THEN 2 
                    WHEN 'medium' THEN 3 
                    ELSE 4 
                END,
                t.created_at DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$backlogType]);
    $tickets = $stmt->fetchAll();
    
    // Parsear assigned_users a array
    foreach ($tickets as &$ticket) {
        $ticket['assignments'] = [];
        if (!empty($ticket['assigned_users'])) {
            $users = explode('|', $ticket['assigned_users']);
            foreach ($users as $user) {
                $parts = explode(':', $user);
                if (count($parts) >= 3) {
                    $ticket['assignments'][] = [
                        'id' => (int)$parts[0],
                        'name' => $parts[1],
                        'role' => $parts[2]
                    ];
                }
            }
        }
        unset($ticket['assigned_users']);
    }
    
    echo json_encode([
        'success' => true,
        'data' => $tickets,
        'total' => count($tickets)
    ]);
}

/**
 * Obtener un ticket especÃ­fico
 */
function getTicket($pdo, $id) {
    $sql = "SELECT t.*, 
            c.name as category_name, c.color as category_color,
            u1.name as created_by_name, u1.email as created_by_email,
            u2.name as assigned_to_name, u2.email as assigned_to_email
            FROM tickets t
            LEFT JOIN categories c ON t.category_id = c.id
            LEFT JOIN users u1 ON t.created_by = u1.id
            LEFT JOIN users u2 ON t.assigned_to = u2.id
            WHERE t.id = ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    $ticket = $stmt->fetch();
    
    if (!$ticket) {
        http_response_code(404);
        echo json_encode(['error' => 'Ticket no encontrado']);
        return;
    }
    
    // Obtener comentarios
    $sql = "SELECT cm.*, u.name as user_name, u.avatar as user_avatar
            FROM comments cm
            LEFT JOIN users u ON cm.user_id = u.id
            WHERE cm.ticket_id = ?
            ORDER BY cm.created_at ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    $ticket['comments'] = $stmt->fetchAll();
    
    // Obtener historial
    $sql = "SELECT al.*, 
            u.name as user_name,
            (SELECT name FROM users WHERE id = al.new_value LIMIT 1) as assigned_to_name
            FROM activity_log al
            LEFT JOIN users u ON al.user_id = u.id
            WHERE al.ticket_id = ?
            ORDER BY al.created_at DESC
            LIMIT 20";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    $ticket['activity'] = $stmt->fetchAll();
    
    // Obtener tags
    $sql = "SELECT tg.* FROM tags tg
            INNER JOIN ticket_tags tt ON tg.id = tt.tag_id
            WHERE tt.ticket_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    $ticket['tags'] = $stmt->fetchAll();
    
    echo json_encode(['success' => true, 'data' => $ticket]);
}

/**
 * Crear nuevo ticket
 */
function createTicket($pdo) {
    try {
        // Add a custom header to prove execution
        header('X-Tickets-PHP-Executed: yes');
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            $input = $_POST;
        }
        
        // Validaciones
        if (empty($input['title']) || empty($input['description'])) {
            http_response_code(400);
            echo json_encode(['error' => 'TÃ­tulo y descripciÃ³n son requeridos']);
            return;
        }
        
        // Generar nÃºmero de ticket Ãºnico con prefijo segÃºn work_type
        $workType = $input['work_type'] ?? 'puntual';
        $prefixMap = ['puntual' => 'P', 'recurrente' => 'R', 'soporte' => 'S'];
        $prefix = $prefixMap[$workType] ?? 'P';
        $ticketNumber = $prefix . '-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));
        
        $sql = "INSERT INTO tickets (
                    ticket_number, title, description, status, priority, 
                    category_id, created_by, assigned_to, source, client_id,
                    work_type, start_date, end_date, hours_dedicated,
                    max_delivery_date, project_id, briefing_url, video_url,
                    monthly_hours, score,
                    contact_name, contact_email, contact_phone,
                    ghl_form_id, ghl_contact_id, due_date, backlog, backlog_type
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $ticketNumber,
            $input['title'],
            $input['description'],
            $input['status'] ?? 'open',
            $input['priority'] ?? 'medium',
            $input['category_id'] ?? null,
            $input['created_by'] ?? null,
            $input['assigned_to'] ?? null,
            $input['source'] ?? 'internal',
            $input['client_id'] ?? null,
            $workType,
            $input['start_date'] ?? null,
            $input['end_date'] ?? null,
            $input['hours_dedicated'] ?? 0,
            $input['max_delivery_date'] ?? null,
            $input['project_id'] ?? null,
            $input['briefing_url'] ?? null,
            $input['video_url'] ?? null,
            $input['monthly_hours'] ?? 0,
            $input['score'] ?? null,
            $input['contact_name'] ?? null,
            $input['contact_email'] ?? null,
            $input['contact_phone'] ?? null,
            $input['ghl_form_id'] ?? null,
            $input['ghl_contact_id'] ?? null,
            $input['due_date'] ?? null,
            isset($input['backlog']) ? (int)$input['backlog'] : 0,
            $input['backlog_type'] ?? null
        ]);
        
        $ticketId = $pdo->lastInsertId();
        
        // Crear asignaciones múltiples para tickets de backlog
        if (!empty($input['backlog'])) {
            // Asignar a Alfonso (3) como primario y Alicia (14) como secundario
            $assignStmt = $pdo->prepare("INSERT IGNORE INTO ticket_assignments (ticket_id, user_id, role) VALUES (?, ?, ?)");
            $assignStmt->execute([$ticketId, 3, 'primary']);   // Alfonso
            $assignStmt->execute([$ticketId, 14, 'secondary']); // Alicia
        } elseif (!empty($input['assigned_to'])) {
            // Para tickets normales, crear asignación primaria
            $assignStmt = $pdo->prepare("INSERT IGNORE INTO ticket_assignments (ticket_id, user_id, role) VALUES (?, ?, 'primary')");
            $assignStmt->execute([$ticketId, $input['assigned_to']]);
        }
        
        // Log activity (si la función existe)
        if (function_exists('logActivity')) {
            try {
                logActivity($pdo, $ticketId, $input['created_by'] ?? null, 'ticket_created', null, 'Ticket creado');
            } catch (Exception $e) {
                error_log('Activity log error: ' . $e->getMessage());
            }
        }
        
        // Notificar si es backlog
        $notificationResult = null;
        if (!empty($input['backlog'])) {
            // Notificar a Alfonso (3) y Alicia (14) sobre nuevo ticket en backlog
            $ticketData = [
                'id' => $ticketId,
                'ticket_number' => $ticketNumber,
                'title' => $input['title'],
                'description' => $input['description'],
                'priority' => $input['priority'] ?? 'medium',
                'due_date' => $input['due_date'] ?? null
            ];
            
            try {
                // Notificar a Alfonso y Alicia (revisores)
                if (function_exists('notifyTicketAssignment')) {
                    notifyTicketAssignment($pdo, 3, $ticketData);
                    notifyTicketAssignment($pdo, 6, $ticketData);
                }
            } catch (Exception $e) {
                error_log('Notification error: ' . $e->getMessage());
            }
        }
        
        // Test: Log todos los inputs recibidos
        file_put_contents(LOGS_PATH . '\\test_input.txt', date('Y-m-d H:i:s') . " - Input recibido:\n" . json_encode($input) . "\n", FILE_APPEND);
        
        // Notificar al cliente por WhatsApp si tiene telÃ©fono
        if (!empty($input['contact_phone'])) {
            file_put_contents(LOGS_PATH . '\\debug_whatsapp.txt', date('Y-m-d H:i:s') . " - Iniciando notificaciÃ³n WhatsApp\n", FILE_APPEND);
            
            try {
                file_put_contents(LOGS_PATH . '\\debug_whatsapp.txt', date('Y-m-d H:i:s') . " - function_exists test\n", FILE_APPEND);
                $exists = function_exists('notifyTicketCreatedWA');
                file_put_contents(LOGS_PATH . '\\debug_whatsapp.txt', date('Y-m-d H:i:s') . " - notifyTicketCreatedWA exists: " . ($exists ? 'YES' : 'NO') . "\n", FILE_APPEND);
                
                $fullTicketData = [
                    'id' => $ticketId,
                    'ticket_number' => $ticketNumber,
                    'title' => $input['title'],
                    'description' => $input['description'],
                    'priority' => $input['priority'] ?? 'medium',
                    'contact_name' => $input['contact_name'] ?? 'Cliente',
                    'contact_email' => $input['contact_email'] ?? null,
                    'contact_phone' => $input['contact_phone']
                ];
                
                if ($exists) {
                    file_put_contents(LOGS_PATH . '\\debug_whatsapp.txt', date('Y-m-d H:i:s') . " - Llamando notifyTicketCreatedWA\n", FILE_APPEND);
                    $whatsappResult = notifyTicketCreatedWA($pdo, $fullTicketData);
                    file_put_contents(LOGS_PATH . '\\debug_whatsapp.txt', date('Y-m-d H:i:s') . " - Resultado: " . json_encode($whatsappResult) . "\n", FILE_APPEND);
                    $notificationResult = array_merge($notificationResult ?? [], ['whatsapp' => $whatsappResult]);
                }
            } catch (Exception $e) {
                file_put_contents(LOGS_PATH . '\\debug_whatsapp.txt', date('Y-m-d H:i:s') . " - Exception: " . $e->getMessage() . "\n", FILE_APPEND);
                error_log('WhatsApp notification error: ' . $e->getMessage());
            }
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Ticket creado exitosamente',
            'data' => [
                'id' => $ticketId,
                'ticket_number' => $ticketNumber
            ],
            'notifications' => $notificationResult
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Error al crear ticket: ' . $e->getMessage()
        ]);
    }
}

/**
 * Actualizar ticket
 */
function updateTicket($pdo, $id) {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        // DEBUG
        file_put_contents('/tmp/debug.log', "UPDATE INPUT: " . json_encode($input) . "\n", FILE_APPEND);
        
        // Obtener ticket actual
        $stmt = $pdo->prepare("SELECT * FROM tickets WHERE id = ?");
        $stmt->execute([$id]);
        $current = $stmt->fetch();
        
        if (!$current) {
            http_response_code(404);
            echo json_encode(['error' => 'Ticket no encontrado']);
            return;
        }
        
        $fields = [];
        $params = [];
        $allowedFields = ['title', 'description', 'status', 'priority', 'category_id', 'assigned_to', 'due_date', 
                          'work_type', 'hours_dedicated', 'max_delivery_date', 'project_id', 'briefing_url', 
                          'video_url', 'monthly_hours', 'score', 'info_pending_status', 'revision_status', 'end_date', 'client_id', 'backlog', 'backlog_type', 'pending_info_details'];
        
        foreach ($allowedFields as $field) {
            if (isset($input[$field])) {
                $fields[] = "$field = ?";
                
                // Convertir backlog a integer
                if ($field === 'backlog') {
                    $params[] = (int)$input[$field];
                } else {
                    $params[] = $input[$field];
                }
                
                // Log de cambios (si funciÃ³n existe)
                if (function_exists('logActivity') && $current[$field] != $input[$field]) {
                    try {
                        logActivity($pdo, $id, $input['user_id'] ?? null, 
                            "changed_$field", $current[$field], $input[$field]);
                    } catch (Exception $e) {
                        error_log('Activity log error: ' . $e->getMessage());
                    }
                }
            }
        }
        
        // Manejar estado resuelto/cerrado
        if (isset($input['status'])) {
            if ($input['status'] === 'resolved' && $current['status'] !== 'resolved') {
                $fields[] = "resolved_at = NOW()";
            }
            if ($input['status'] === 'closed' && $current['status'] !== 'closed') {
                $fields[] = "closed_at = NOW()";
            }
        }
        
        if (empty($fields)) {
            echo json_encode(['success' => true, 'message' => 'Nada que actualizar']);
            return;
        }
        
        $params[] = $id;
        $sql = "UPDATE tickets SET " . implode(', ', $fields) . " WHERE id = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        // Notificar si se cambió la asignación
        $notificationResult = null;
        if (isset($input['assigned_to']) && $input['assigned_to'] != $current['assigned_to']) {
            // Se está asignando a un nuevo usuario
            try {
                $stmt = $pdo->prepare("SELECT * FROM tickets WHERE id = ?");
                $stmt->execute([$id]);
                $updatedTicket = $stmt->fetch();
                
                if (function_exists('notifyTicketAssignment') && $updatedTicket && $input['assigned_to']) {
                    $ticketData = [
                        'id' => $id,
                        'ticket_number' => $updatedTicket['ticket_number'],
                        'title' => $updatedTicket['title'],
                        'description' => $updatedTicket['description'],
                        'priority' => $updatedTicket['priority'],
                        'due_date' => $updatedTicket['due_date']
                    ];
                    // Notificar al nuevo asignatario
                    notifyTicketAssignment($pdo, $input['assigned_to'], $ticketData);
                    
                    // También crear entrada en ticket_assignments
                    $assignStmt = $pdo->prepare("INSERT IGNORE INTO ticket_assignments (ticket_id, user_id, role, assigned_by) VALUES (?, ?, 'primary', ?)");
                    $assignStmt->execute([$id, $input['assigned_to'], $input['user_id'] ?? null]);
                }
            } catch (Exception $e) {
                error_log('Notification error: ' . $e->getMessage());
            }
        }
        
        // Notificar al cliente por WhatsApp si hay cambios importantes
        try {
            $stmt = $pdo->prepare("SELECT * FROM tickets WHERE id = ?");
            $stmt->execute([$id]);
            $updatedTicket = $stmt->fetch();

            // Notificar si pasa a status 'waiting' (información pendiente)
            if (isset($input['status']) && $input['status'] === 'waiting' &&
                $current['status'] !== 'waiting') {

                // Agregar información pendiente a los datos del ticket
                $ticketDataWithInfo = $updatedTicket;
                $ticketDataWithInfo['informacion_pendiente'] = $input['pending_info_details'] ?? '';
                $ticketDataWithInfo['link_seguimiento'] = generateTrackingLink($updatedTicket['id'], $updatedTicket['ticket_number'], $pdo);

                if (function_exists('notifyPendingInfo')) {
                    notifyPendingInfo($pdo, $ticketDataWithInfo);
                }
            }

            // Notificar si pasa a status 'in_progress' (en proceso)
            if (isset($input['status']) && $input['status'] === 'in_progress' &&
                $current['status'] !== 'in_progress') {

                // Agregar link de seguimiento a los datos del ticket
                $ticketDataWithLink = $updatedTicket;
                $ticketDataWithLink['link_seguimiento'] = generateTrackingLink($updatedTicket['id'], $updatedTicket['ticket_number'], $pdo);

                if (function_exists('notifyInProgress')) {
                    notifyInProgress($pdo, $ticketDataWithLink);
                }
            }
        } catch (Exception $e) {
            error_log('WhatsApp notification error: ' . $e->getMessage());
        }
        
        echo json_encode([
            'success' => true, 
            'message' => 'Ticket actualizado',
            'notifications' => $notificationResult
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Error al actualizar ticket: ' . $e->getMessage()
        ]);
    }
}

/**
 * Eliminar ticket
 */
function deleteTicket($pdo, $id) {
    $stmt = $pdo->prepare("DELETE FROM tickets WHERE id = ?");
    $stmt->execute([$id]);
    
    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true, 'message' => 'Ticket eliminado']);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Ticket no encontrado']);
    }
}

/**
 * Obtener estadÃ­sticas
 */
function getStats($pdo) {
    $stats = [];
    
    // Por estado
    $stmt = $pdo->query("SELECT status, COUNT(*) as count FROM tickets GROUP BY status");
    $stats['by_status'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // Por prioridad
    $stmt = $pdo->query("SELECT priority, COUNT(*) as count FROM tickets GROUP BY priority");
    $stats['by_priority'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // Totales
    $stmt = $pdo->query("SELECT COUNT(*) FROM tickets");
    $stats['total'] = (int)$stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM tickets WHERE status IN ('open', 'in_progress', 'waiting')");
    $stats['open'] = (int)$stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM tickets WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $stats['last_7_days'] = (int)$stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM tickets WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $stats['last_30_days'] = (int)$stmt->fetchColumn();
    
    // Por categorÃ­a
    $stmt = $pdo->query("SELECT c.name, COUNT(t.id) as count 
                         FROM categories c 
                         LEFT JOIN tickets t ON c.id = t.category_id 
                         GROUP BY c.id ORDER BY count DESC");
    $stats['by_category'] = $stmt->fetchAll();
    
    // Tiempo promedio de resoluciÃ³n (Ãºltimos 30 dÃ­as)
    $stmt = $pdo->query("SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, resolved_at)) as avg_hours
                         FROM tickets 
                         WHERE resolved_at IS NOT NULL 
                         AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $stats['avg_resolution_hours'] = round($stmt->fetchColumn() ?? 0, 1);
    
    echo json_encode(['success' => true, 'data' => $stats]);
}

/**
 * Manejar comentarios
 */
function handleComments($pdo, $ticketId) {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $sql = "SELECT cm.*, u.name as user_name, u.avatar 
                FROM comments cm 
                LEFT JOIN users u ON cm.user_id = u.id 
                WHERE cm.ticket_id = ? 
                ORDER BY cm.created_at ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$ticketId]);
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
        return;
    }
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (empty($input['content'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Contenido requerido']);
            return;
        }
        
        $sql = "INSERT INTO comments (ticket_id, user_id, author_name, author_email, content, is_internal)
                VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $ticketId,
            $input['user_id'] ?? null,
            $input['author_name'] ?? null,
            $input['author_email'] ?? null,
            $input['content'],
            $input['is_internal'] ?? false
        ]);
        
        // Actualizar ticket
        $pdo->prepare("UPDATE tickets SET updated_at = NOW() WHERE id = ?")->execute([$ticketId]);
        
        logActivity($pdo, $ticketId, $input['user_id'] ?? null, 'comment_added', null, 'Comentario agregado');
        
        echo json_encode(['success' => true, 'message' => 'Comentario agregado', 'id' => $pdo->lastInsertId()]);
    }
}

/**
 * Webhook para GHL Forms
 */
function handleGHLWebhook($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Log del webhook para debug
    file_put_contents(__DIR__ . '/../logs/webhooks.log', 
        date('Y-m-d H:i:s') . " - " . json_encode($input) . "\n", 
        FILE_APPEND);
    
    // Mapear campos de GHL a ticket
    $ticketData = [
        'title' => $input['form_name'] ?? $input['title'] ?? 'Ticket desde formulario',
        'description' => $input['message'] ?? $input['description'] ?? $input['body'] ?? '',
        'contact_name' => $input['full_name'] ?? $input['first_name'] . ' ' . ($input['last_name'] ?? ''),
        'contact_email' => $input['email'] ?? '',
        'contact_phone' => $input['phone'] ?? '',
        'ghl_contact_id' => $input['contact_id'] ?? $input['contactId'] ?? null,
        'ghl_form_id' => $input['form_id'] ?? $input['formId'] ?? null,
        'source' => 'form',
        'priority' => mapPriority($input['priority'] ?? 'medium'),
        'category_id' => $input['category_id'] ?? null
    ];
    
    // Si hay campos personalizados, aÃ±adirlos a la descripciÃ³n
    if (isset($input['customFields']) && is_array($input['customFields'])) {
        $ticketData['description'] .= "\n\n--- Campos adicionales ---\n";
        foreach ($input['customFields'] as $key => $value) {
            $ticketData['description'] .= "$key: $value\n";
        }
    }
    
    // Crear el ticket
    $_POST = $ticketData;
    createTicket($pdo);
}

/**
 * Registrar actividad
 */
function logActivity($pdo, $ticketId, $userId, $action, $oldValue, $newValue) {
    $sql = "INSERT INTO activity_log (ticket_id, user_id, action, old_value, new_value) 
            VALUES (?, ?, ?, ?, ?)";
    $pdo->prepare($sql)->execute([$ticketId, $userId, $action, $oldValue, $newValue]);
}

/**
 * Mapear prioridad
 */
function mapPriority($priority) {
    $map = [
        'baja' => 'low',
        'low' => 'low',
        'media' => 'medium',
        'medium' => 'medium',
        'alta' => 'high',
        'high' => 'high',
        'urgente' => 'urgent',
        'urgent' => 'urgent'
    ];
    return $map[strtolower($priority)] ?? 'medium';
}
/**
 * Obtener ticket con validaciÃ³n de token de seguimiento
 */
function getTicketTracking($pdo, $ticketNumber, $token) {
    if (empty($ticketNumber) || empty($token)) {
        http_response_code(400);
        echo json_encode(['error' => 'ParÃ¡metros incompletos']);
        return;
    }
    
    try {
        // Obtener ticket por nÃºmero
        $stmt = $pdo->prepare("
            SELECT t.*, 
                   u2.name as assigned_to_name,
                   c.name as category_name
            FROM tickets t
            LEFT JOIN users u2 ON t.assigned_to = u2.id
            LEFT JOIN categories c ON t.category_id = c.id
            WHERE t.ticket_number = ?
        ");
        $stmt->execute([$ticketNumber]);
        $ticket = $stmt->fetch();
        
        if (!$ticket) {
            http_response_code(404);
            echo json_encode(['error' => 'Ticket no encontrado']);
            return;
        }
        
        // Validar token
        $stmt = $pdo->prepare("
            SELECT * FROM ticket_tracking_tokens 
            WHERE ticket_id = ? AND token LIKE ? AND expires_at > NOW()
        ");
        $tokenPrefix = substr($token, 0, 20) . '%';
        $stmt->execute([$ticket['id'], $tokenPrefix]);
        $tokenRecord = $stmt->fetch();
        
        if (!$tokenRecord) {
            http_response_code(403);
            echo json_encode(['error' => 'Enlace de seguimiento invÃ¡lido o expirado']);
            return;
        }
        
        // Obtener actividades del ticket
        $stmt = $pdo->prepare("
            SELECT id, ticket_id, user_id, action, old_value, new_value, description, created_at
            FROM activities
            WHERE ticket_id = ?
            ORDER BY created_at DESC
            LIMIT 10
        ");
        $stmt->execute([$ticket['id']]);
        $activities = $stmt->fetchAll();
        
        echo json_encode([
            'success' => true,
            'ticket' => $ticket,
            'activities' => $activities
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error al obtener ticket: ' . $e->getMessage()]);
    }
}

/**
 * Test WhatsApp Flow paso a paso
 */
function testWhatsAppFlow($pdo) {
    echo "=== TEST WHATSAPP FLOW ===\n\n";
    
    $phone = $_GET['phone'] ?? '+57301448821';
    $ticketId = 19;
    
    require_once __DIR__ . '/ghl-notifications.php';
    require_once __DIR__ . '/ghl.php';
    
    echo "Paso 1: Buscar contacto con telÃ©fono $phone\n";
    
    $searchResult = ghlApiCall('/contacts/lookup?phone=' . urlencode($phone), 'GET', null, GHL_LOCATION_ID);
    
    echo "Resultado bÃºsqueda:\n";
    echo json_encode($searchResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";
    
    if (!empty($searchResult['contacts'][0]['id'])) {
        $contactId = $searchResult['contacts'][0]['id'];
        echo "âœ… Contacto encontrado: $contactId\n\n";
    } else {
        echo "âŒ Contacto NO encontrado. Intentando crear...\n";
        
        echo "Paso 2: Crear contacto\n";
        
        $createResult = ghlApiCall('/contacts/', 'POST', [
            'phone' => $phone,
            'name' => 'Cliente Test',
            'locationId' => GHL_LOCATION_ID
        ], GHL_LOCATION_ID);
        
        echo "Resultado creaciÃ³n:\n";
        echo json_encode($createResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";
        
        if (!empty($createResult['contact']['id'])) {
            $contactId = $createResult['contact']['id'];
            echo "âœ… Contacto creado: $contactId\n\n";
        } else {
            echo "âŒ Error creando contacto\n";
            echo json_encode($createResult) . "\n";
            return;
        }
    }
    
    echo "Paso 3: Actualizar custom fields\n";
    
    $customFieldsResult = updateContactCustomFields($contactId, [
        'ticket_id' => 'P-20260126-651F',
        'link_seguimiento' => 'http://localhost/Ticketing System/ticket-tracking.php?id=P-20260126-651F&token=abc123'
    ]);
    
    echo "Resultado custom fields:\n";
    echo json_encode($customFieldsResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";
    
    echo "Paso 4: Enviar WhatsApp con template\n";
    
    $templateResult = sendWhatsAppTemplate($pdo, $phone, 'ticket_creado', [
        'ticket_id' => 'P-20260126-651F',
        'link_seguimiento' => 'http://localhost/Ticketing System/ticket-tracking.php?id=P-20260126-651F&token=abc123'
    ]);
    
    echo "Resultado template:\n";
    echo json_encode($templateResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";
    
    echo "=== FIN TEST ===\n";
}

/**
 * Solicitar revisión del ticket
 * Cambia revision_status a 1 y notifica a Alfonso y Alicia
 */
function requestReview($pdo, $id) {
    if (!$id) {
        echo json_encode(['success' => false, 'error' => 'ID de ticket requerido']);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $deliverable = $input['deliverable'] ?? null;
    
    try {
        // Obtener ticket actual
        $stmt = $pdo->prepare("SELECT * FROM tickets WHERE id = ?");
        $stmt->execute([$id]);
        $ticket = $stmt->fetch();
        
        if (!$ticket) {
            echo json_encode(['success' => false, 'error' => 'Ticket no encontrado']);
            return;
        }
        
        // Verificar que tiene entregable
        $finalDeliverable = $deliverable ?? $ticket['deliverable'];
        if (empty($finalDeliverable)) {
            echo json_encode(['success' => false, 'error' => 'Debe ingresar un entregable antes de enviar a revisión']);
            return;
        }
        
        // Actualizar ticket - vuelve al backlog con revision_status = 1
        $stmt = $pdo->prepare("UPDATE tickets SET revision_status = 1, deliverable = ?, status = 'waiting', backlog = 1, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$finalDeliverable, $id]);
        
        // Notificar a Alfonso (3) y Alicia (14)
        if (function_exists('notifyReviewRequest')) {
            $ticketData = [
                'id' => $id,
                'ticket_number' => $ticket['ticket_number'],
                'title' => $ticket['title'],
                'description' => $ticket['description'],
                'deliverable' => $finalDeliverable,
                'assigned_to_name' => $ticket['assigned_to_name'] ?? 'Sin asignar'
            ];
            notifyReviewRequest($pdo, $ticketData);
        }
        
        // Registrar actividad
        $stmt = $pdo->prepare("INSERT INTO activity_log (ticket_id, user_id, action, new_value, created_at) VALUES (?, ?, 'review_requested', ?, NOW())");
        $stmt->execute([$id, $input['user_id'] ?? 1, $finalDeliverable]);
        
        echo json_encode(['success' => true, 'message' => 'Ticket enviado a revisión']);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * Aprobar ticket
 * Cambia revision_status a 2, status a resolved, y envía WhatsApp al cliente
 */
function approveTicket($pdo, $id) {
    if (!$id) {
        echo json_encode(['success' => false, 'error' => 'ID de ticket requerido']);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    try {
        // Obtener ticket
        $stmt = $pdo->prepare("SELECT * FROM tickets WHERE id = ?");
        $stmt->execute([$id]);
        $ticket = $stmt->fetch();
        
        if (!$ticket) {
            echo json_encode(['success' => false, 'error' => 'Ticket no encontrado']);
            return;
        }
        
        if ($ticket['revision_status'] != 1) {
            echo json_encode(['success' => false, 'error' => 'El ticket no está en revisión']);
            return;
        }
        
        // Actualizar ticket - sale del backlog y se resuelve
        $stmt = $pdo->prepare("UPDATE tickets SET revision_status = 2, status = 'resolved', backlog = 0, resolved_at = NOW(), updated_at = NOW() WHERE id = ?");
        $stmt->execute([$id]);
        
        // Enviar WhatsApp al cliente con el entregable
        if (!empty($ticket['contact_phone']) && function_exists('notifyTicketApproved')) {
            $ticketData = [
                'id' => $id,
                'ticket_number' => $ticket['ticket_number'],
                'title' => $ticket['title'],
                'deliverable' => $ticket['deliverable'],
                'contact_name' => $ticket['contact_name'],
                'contact_phone' => $ticket['contact_phone'],
                'contact_email' => $ticket['contact_email']
            ];
            notifyTicketApproved($pdo, $ticketData);
        }
        
        // Registrar actividad
        $stmt = $pdo->prepare("INSERT INTO activity_log (ticket_id, user_id, action, new_value, created_at) VALUES (?, ?, 'approved', ?, NOW())");
        $stmt->execute([$id, $input['user_id'] ?? 1, 'Aprobado']);
        
        echo json_encode(['success' => true, 'message' => 'Ticket aprobado']);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * Rechazar ticket
 * Vuelve revision_status a 0 y notifica al agente asignado
 */
function rejectTicket($pdo, $id) {
    if (!$id) {
        echo json_encode(['success' => false, 'error' => 'ID de ticket requerido']);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $reason = $input['reason'] ?? '';
    
    try {
        // Obtener ticket
        $stmt = $pdo->prepare("SELECT t.*, u.name as assigned_to_name, u.email as assigned_to_email 
                               FROM tickets t 
                               LEFT JOIN users u ON t.assigned_to = u.id 
                               WHERE t.id = ?");
        $stmt->execute([$id]);
        $ticket = $stmt->fetch();
        
        if (!$ticket) {
            echo json_encode(['success' => false, 'error' => 'Ticket no encontrado']);
            return;
        }
        
        if ($ticket['revision_status'] != 1) {
            echo json_encode(['success' => false, 'error' => 'El ticket no está en revisión']);
            return;
        }
        
        // Actualizar ticket - volver a revision_status = 0, sale del backlog para que el agente corrija
        $stmt = $pdo->prepare("UPDATE tickets SET revision_status = 0, status = 'in_progress', backlog = 0, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$id]);
        
        // Agregar comentario interno con el motivo del rechazo
        $stmt = $pdo->prepare("INSERT INTO comments (ticket_id, user_id, content, is_internal, created_at) VALUES (?, ?, ?, 1, NOW())");
        $stmt->execute([$id, $input['user_id'] ?? 1, "🔴 RECHAZADO: " . $reason]);
        
        // Notificar al agente asignado
        if ($ticket['assigned_to'] && function_exists('notifyTicketRejected')) {
            $ticketData = [
                'id' => $id,
                'ticket_number' => $ticket['ticket_number'],
                'title' => $ticket['title'],
                'reason' => $reason,
                'assigned_to' => $ticket['assigned_to'],
                'assigned_to_name' => $ticket['assigned_to_name'],
                'assigned_to_email' => $ticket['assigned_to_email']
            ];
            notifyTicketRejected($pdo, $ticketData);
        }
        
        // Registrar actividad
        $stmt = $pdo->prepare("INSERT INTO activity_log (ticket_id, user_id, action, new_value, created_at) VALUES (?, ?, 'rejected', ?, NOW())");
        $stmt->execute([$id, $input['user_id'] ?? 1, $reason]);
        
        echo json_encode(['success' => true, 'message' => 'Ticket rechazado']);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}
?>