<?php
/**
 * API Auxiliar - Categorías, Usuarios, Tags
 */

require_once __DIR__ . '/../config/database.php';
setCorsHeaders();

$pdo = getConnection();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'categories':
        getCategories($pdo);
        break;
    case 'users':
        getUsers($pdo);
        break;
    case 'agents':
        getAgents($pdo);
        break;
    case 'tags':
        getTags($pdo);
        break;
    case 'clients':
        getClients($pdo);
        break;
    case 'projects':
        getProjects($pdo);
        break;
    case 'agent-stats':
        getAgentStats($pdo, $_GET['agent_id'] ?? null);
        break;
    case 'agent-tickets':
        getAgentTickets($pdo, $_GET['agent_id'] ?? null, $_GET['status'] ?? null);
        break;
    case 'backlog-stats':
        getBacklogStats($pdo, $_GET['type'] ?? 'consultoria');
        break;
    case 'backlog-history':
        getBacklogHistory($pdo, $_GET['type'] ?? 'consultoria');
        break;
    case 'backlog-review':
        getBacklogPendingReview($pdo, $_GET['type'] ?? 'consultoria');
        break;
    default:
        echo json_encode(['error' => 'Acción no válida']);
}

function getCategories($pdo) {
    $stmt = $pdo->query("SELECT * FROM categories WHERE active = 1 ORDER BY name");
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
}

function getUsers($pdo) {
    $stmt = $pdo->query("SELECT id, name, email, role, avatar FROM users ORDER BY name");
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
}

function getAgents($pdo) {
    $stmt = $pdo->query("SELECT id, name, email, avatar FROM users WHERE role IN ('admin', 'agent', 'agency_admin', 'agency_agent', 'client_admin') AND is_active = 1 ORDER BY name");
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
}

function getTags($pdo) {
    $stmt = $pdo->query("SELECT * FROM tags ORDER BY name");
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
}

function getClients($pdo) {
    $stmt = $pdo->query("SELECT id, name FROM accounts WHERE is_active = 1 ORDER BY name");
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
}

function getProjects($pdo) {
    $stmt = $pdo->query("SELECT id, name, client_id FROM projects WHERE status = 'active' ORDER BY name");
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
}
function getAgentStats($pdo, $agentId) {
    if (!$agentId) {
        echo json_encode(['error' => 'Agent ID required']);
        return;
    }

    $stats = [];

    // Total tickets
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM tickets WHERE assigned_to = ?");
    $stmt->execute([$agentId]);
    $stats['total'] = $stmt->fetch()['total'];

    // Open/Active tickets (includes waiting)
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tickets WHERE assigned_to = ? AND status IN ('open', 'in_progress', 'waiting')");
    $stmt->execute([$agentId]);
    $stats['open'] = $stmt->fetch()['count'];

    // Resolved tickets
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tickets WHERE assigned_to = ? AND status = 'resolved'");
    $stmt->execute([$agentId]);
    $stats['resolved'] = $stmt->fetch()['count'];

    // By status breakdown
    $stmt = $pdo->prepare("SELECT status, COUNT(*) as count FROM tickets WHERE assigned_to = ? GROUP BY status");
    $stmt->execute([$agentId]);
    $stats['by_status'] = $stmt->fetchAll();

    // Average resolution time (in hours)
    $stmt = $pdo->prepare("
        SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, resolved_at)) as avg_time
        FROM tickets 
        WHERE assigned_to = ? AND resolved_at IS NOT NULL
    ");
    $stmt->execute([$agentId]);
    $result = $stmt->fetch();
    $stats['avg_resolution_hours'] = $result['avg_time'] ? round($result['avg_time'], 1) : 0;

    // By priority
    $stmt = $pdo->prepare("SELECT priority, COUNT(*) as count FROM tickets WHERE assigned_to = ? GROUP BY priority");
    $stmt->execute([$agentId]);
    $stats['by_priority'] = $stmt->fetchAll();

    echo json_encode(['success' => true, 'data' => $stats]);
}

function getAgentTickets($pdo, $agentId, $statusFilter = null) {
    if (!$agentId) {
        echo json_encode(['error' => 'Agent ID required']);
        return;
    }

    // Build status filter
    $statusCondition = '';
    if ($statusFilter === 'active') {
        $statusCondition = " AND t.status IN ('open', 'in_progress', 'waiting')";
    } elseif ($statusFilter === 'resolved') {
        $statusCondition = " AND t.status IN ('resolved', 'closed')";
    }

    $stmt = $pdo->prepare("
        SELECT 
            t.id, 
            t.ticket_number,
            t.title,
            t.description,
            t.status,
            t.priority,
            t.category_id,
            t.assigned_to,
            t.created_at,
            t.due_date,
            c.name as category_name,
            a.name as client_name
        FROM tickets t
        LEFT JOIN categories c ON t.category_id = c.id
        LEFT JOIN accounts a ON t.client_id = a.id
        WHERE t.assigned_to = ? $statusCondition
        ORDER BY 
            CASE 
                WHEN t.status = 'open' THEN 1
                WHEN t.status = 'in_progress' THEN 2
                WHEN t.status = 'waiting' THEN 3
                ELSE 4
            END,
            t.created_at DESC
        LIMIT 100
    ");
    $stmt->execute([$agentId]);
    $tickets = $stmt->fetchAll();

    echo json_encode(['success' => true, 'data' => $tickets]);
}

/**
 * Obtener estadísticas del backlog
 */
function getBacklogStats($pdo, $type) {
    $stats = [];
    
    // Pendientes (en backlog)
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tickets WHERE backlog = 1 AND backlog_type = ?");
    $stmt->execute([$type]);
    $stats['pending'] = (int)$stmt->fetch()['count'];
    
    // Pendientes de revisión (backlog=1, revision_status=1)
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tickets WHERE backlog = 1 AND backlog_type = ? AND revision_status = 1");
    $stmt->execute([$type]);
    $stats['pending_review'] = (int)$stmt->fetch()['count'];
    
    // Asignados (salieron del backlog, no resueltos/cerrados)
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tickets WHERE backlog = 0 AND backlog_type = ? AND status NOT IN ('resolved', 'closed')");
    $stmt->execute([$type]);
    $stats['assigned'] = (int)$stmt->fetch()['count'];
    
    // Resueltos (del backlog)
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tickets WHERE backlog_type = ? AND status IN ('resolved', 'closed')");
    $stmt->execute([$type]);
    $stats['resolved'] = (int)$stmt->fetch()['count'];
    
    // Tiempo promedio de resolución
    $stmt = $pdo->prepare("
        SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, resolved_at)) as avg_time
        FROM tickets 
        WHERE backlog_type = ? AND resolved_at IS NOT NULL
    ");
    $stmt->execute([$type]);
    $result = $stmt->fetch();
    $stats['avg_time'] = $result['avg_time'] ? round($result['avg_time'], 1) : 0;
    
    // Total histórico (todos los que salieron del backlog)
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tickets WHERE backlog = 0 AND backlog_type = ?");
    $stmt->execute([$type]);
    $stats['history_total'] = (int)$stmt->fetch()['count'];
    
    echo json_encode(['success' => true, 'data' => $stats]);
}

/**
 * Obtener histórico del backlog (tickets que salieron)
 */
function getBacklogHistory($pdo, $type) {
    $sql = "SELECT t.*, 
            c.name as category_name, c.color as category_color,
            p.name as project_name,
            u1.name as created_by_name,
            u2.name as assigned_to_name,
            (SELECT COUNT(*) FROM comments WHERE ticket_id = t.id) as comment_count
            FROM tickets t
            LEFT JOIN categories c ON t.category_id = c.id
            LEFT JOIN projects p ON t.project_id = p.id
            LEFT JOIN users u1 ON t.created_by = u1.id
            LEFT JOIN users u2 ON t.assigned_to = u2.id
            WHERE t.backlog = 0 AND t.backlog_type = ?
            ORDER BY t.updated_at DESC
            LIMIT 100";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$type]);
    $tickets = $stmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'data' => $tickets,
        'total' => count($tickets)
    ]);
}

/**
 * Obtener tickets pendientes de revisión en el backlog
 */
function getBacklogPendingReview($pdo, $type) {
    $sql = "SELECT t.*, 
            c.name as category_name, c.color as category_color,
            p.name as project_name,
            u1.name as created_by_name,
            u2.name as assigned_to_name,
            (SELECT COUNT(*) FROM comments WHERE ticket_id = t.id) as comment_count
            FROM tickets t
            LEFT JOIN categories c ON t.category_id = c.id
            LEFT JOIN projects p ON t.project_id = p.id
            LEFT JOIN users u1 ON t.created_by = u1.id
            LEFT JOIN users u2 ON t.assigned_to = u2.id
            WHERE t.backlog = 1 AND t.backlog_type = ? AND t.revision_status = 1
            ORDER BY t.updated_at DESC
            LIMIT 100";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$type]);
    $tickets = $stmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'data' => $tickets,
        'total' => count($tickets)
    ]);
}