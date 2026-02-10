<?php
/**
 * API de Cierres Diarios
 * Endpoints para gestión de cierres de día de agentes
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/ghl-notifications.php';

setCorsHeaders();

$pdo = getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'create':
        createClosure($pdo);
        break;
    case 'update':
        updateClosure($pdo);
        break;
    case 'list':
        listClosures($pdo);
        break;
    case 'get-today':
        getTodayClosure($pdo);
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Acción no válida']);
}

/**
 * Crear cierre del día
 */
function createClosure($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (empty($input['agent_id']) || empty($input['summary'])) {
        http_response_code(400);
        echo json_encode(['error' => 'agent_id y summary son requeridos']);
        return;
    }
    
    $agentId = (int)$input['agent_id'];
    $summary = trim($input['summary']);
    $closureDate = $input['closure_date'] ?? date('Y-m-d');
    
    // Verificar si ya existe cierre para este agente y fecha
    $checkStmt = $pdo->prepare("SELECT id FROM daily_closures WHERE agent_id = ? AND closure_date = ?");
    $checkStmt->execute([$agentId, $closureDate]);
    
    if ($checkStmt->fetch()) {
        // Actualizar existente
        $stmt = $pdo->prepare("UPDATE daily_closures SET summary = ?, created_at = NOW() WHERE agent_id = ? AND closure_date = ?");
        $stmt->execute([$summary, $agentId, $closureDate]);
        $closureId = $pdo->query("SELECT id FROM daily_closures WHERE agent_id = $agentId AND closure_date = '$closureDate'")->fetchColumn();
    } else {
        // Crear nuevo
        $stmt = $pdo->prepare("INSERT INTO daily_closures (agent_id, closure_date, summary) VALUES (?, ?, ?)");
        $stmt->execute([$agentId, $closureDate, $summary]);
        $closureId = $pdo->lastInsertId();
    }
    
    // Obtener datos del agente
    $agentStmt = $pdo->prepare("SELECT name, email FROM users WHERE id = ?");
    $agentStmt->execute([$agentId]);
    $agent = $agentStmt->fetch();
    
    // Notificar a Alicia (ID=6) por WhatsApp
    $notificationResult = null;
    try {
        if (function_exists('notifyDailyClosure')) {
            $closureData = [
                'agent_name' => $agent['name'],
                'closure_date' => $closureDate,
                'summary' => $summary
            ];
            $notificationResult = notifyDailyClosure($pdo, 6, $closureData);
        }
    } catch (Exception $e) {
        error_log('Error notificando cierre del día: ' . $e->getMessage());
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'id' => $closureId,
            'agent_id' => $agentId,
            'agent_name' => $agent['name'],
            'closure_date' => $closureDate,
            'summary' => $summary
        ],
        'notification' => $notificationResult
    ]);
}

/**
 * Actualizar cierre existente
 */
function updateClosure($pdo) {
    $closureId = $_GET['id'] ?? '';
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (empty($closureId) || empty($input['summary'])) {
        http_response_code(400);
        echo json_encode(['error' => 'id y summary son requeridos']);
        return;
    }
    
    $summary = trim($input['summary']);
    
    $stmt = $pdo->prepare("UPDATE daily_closures SET summary = ?, created_at = NOW() WHERE id = ?");
    $stmt->execute([$summary, $closureId]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Cierre actualizado correctamente'
    ]);
}

/**
 * Listar cierres con filtros
 */
function listClosures($pdo) {
    $agentId = $_GET['agent_id'] ?? '';
    $dateFrom = $_GET['date_from'] ?? '';
    $dateTo = $_GET['date_to'] ?? '';
    $limit = (int)($_GET['limit'] ?? 50);
    
    $where = [];
    $params = [];
    
    if ($agentId) {
        $where[] = "dc.agent_id = ?";
        $params[] = $agentId;
    }
    if ($dateFrom) {
        $where[] = "dc.closure_date >= ?";
        $params[] = $dateFrom;
    }
    if ($dateTo) {
        $where[] = "dc.closure_date <= ?";
        $params[] = $dateTo;
    }
    
    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    
    $sql = "SELECT dc.*, u.name as agent_name, u.email as agent_email, u.avatar as agent_avatar
            FROM daily_closures dc
            JOIN users u ON dc.agent_id = u.id
            $whereClause
            ORDER BY dc.closure_date DESC, dc.created_at DESC
            LIMIT $limit";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $closures = $stmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'data' => $closures
    ]);
}

/**
 * Obtener cierre de hoy para un agente
 */
function getTodayClosure($pdo) {
    $agentId = $_GET['agent_id'] ?? '';
    
    if (!$agentId) {
        http_response_code(400);
        echo json_encode(['error' => 'agent_id es requerido']);
        return;
    }
    
    $today = date('Y-m-d');
    $stmt = $pdo->prepare("SELECT * FROM daily_closures WHERE agent_id = ? AND closure_date = ?");
    $stmt->execute([$agentId, $today]);
    $closure = $stmt->fetch();
    
    echo json_encode([
        'success' => true,
        'data' => $closure ?: null,
        'has_closure' => (bool)$closure
    ]);
}
