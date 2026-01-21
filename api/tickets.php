<?php
/**
 * API de Tickets - Sistema de Ticketing GHL
 * Endpoints RESTful para gestión de tickets
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/ghl-notifications.php';
setCorsHeaders();

$pdo = getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Router simple
switch ($action) {
    case 'list':
        listTickets($pdo);
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
    case 'delete':
        deleteTicket($pdo, $_GET['id'] ?? 0);
        break;
    case 'stats':
        getStats($pdo);
        break;
    case 'comments':
        handleComments($pdo, $_GET['ticket_id'] ?? 0);
        break;
    case 'webhook':
        handleGHLWebhook($pdo);
        break;
    default:
        echo json_encode(['error' => 'Acción no válida', 'actions' => ['list', 'get', 'create', 'update', 'delete', 'stats', 'comments', 'webhook']]);
}

/**
 * Listar tickets con filtros y paginación
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
 * Obtener un ticket específico
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
    $sql = "SELECT al.*, u.name as user_name
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
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        $input = $_POST;
    }
    
    // Validaciones
    if (empty($input['title']) || empty($input['description'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Título y descripción son requeridos']);
        return;
    }
    
    // Generar número de ticket único
    $ticketNumber = 'TK-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));
    
    $sql = "INSERT INTO tickets (
                ticket_number, title, description, status, priority, 
                category_id, created_by, assigned_to, source,
                contact_name, contact_email, contact_phone,
                ghl_form_id, ghl_contact_id, due_date
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
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
        $input['contact_name'] ?? null,
        $input['contact_email'] ?? null,
        $input['contact_phone'] ?? null,
        $input['ghl_form_id'] ?? null,
        $input['ghl_contact_id'] ?? null,
        $input['due_date'] ?? null
    ]);
    
    $ticketId = $pdo->lastInsertId();
    
    // Registrar actividad
    logActivity($pdo, $ticketId, $input['created_by'] ?? null, 'ticket_created', null, 'Ticket creado');
    
    // Notificar al usuario asignado si existe
    $notificationResult = null;
    if (!empty($input['assigned_to'])) {
        $ticketData = [
            'id' => $ticketId,
            'ticket_number' => $ticketNumber,
            'title' => $input['title'],
            'description' => $input['description'],
            'priority' => $input['priority'] ?? 'medium',
            'due_date' => $input['due_date'] ?? null
        ];
        $notificationResult = notifyTicketAssignment($pdo, $input['assigned_to'], $ticketData);
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
}

/**
 * Actualizar ticket
 */
function updateTicket($pdo, $id) {
    $input = json_decode(file_get_contents('php://input'), true);
    
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
    $allowedFields = ['title', 'description', 'status', 'priority', 'category_id', 'assigned_to', 'due_date'];
    
    foreach ($allowedFields as $field) {
        if (isset($input[$field])) {
            $fields[] = "$field = ?";
            $params[] = $input[$field];
            
            // Log de cambios
            if ($current[$field] != $input[$field]) {
                logActivity($pdo, $id, $input['user_id'] ?? null, 
                    "changed_$field", $current[$field], $input[$field]);
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
    if (isset($input['assigned_to']) && $input['assigned_to'] != $current['assigned_to'] && !empty($input['assigned_to'])) {
        // Obtener datos actualizados del ticket
        $stmt = $pdo->prepare("SELECT * FROM tickets WHERE id = ?");
        $stmt->execute([$id]);
        $updatedTicket = $stmt->fetch();
        
        $ticketData = [
            'id' => $id,
            'ticket_number' => $updatedTicket['ticket_number'],
            'title' => $updatedTicket['title'],
            'description' => $updatedTicket['description'],
            'priority' => $updatedTicket['priority'],
            'due_date' => $updatedTicket['due_date']
        ];
        $notificationResult = notifyTicketAssignment($pdo, $input['assigned_to'], $ticketData);
    }
    
    echo json_encode([
        'success' => true, 
        'message' => 'Ticket actualizado',
        'notifications' => $notificationResult
    ]);
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
 * Obtener estadísticas
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
    
    // Por categoría
    $stmt = $pdo->query("SELECT c.name, COUNT(t.id) as count 
                         FROM categories c 
                         LEFT JOIN tickets t ON c.id = t.category_id 
                         GROUP BY c.id ORDER BY count DESC");
    $stats['by_category'] = $stmt->fetchAll();
    
    // Tiempo promedio de resolución (últimos 30 días)
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
    
    // Si hay campos personalizados, añadirlos a la descripción
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
