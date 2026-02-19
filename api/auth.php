<?php
/**
 * API de Autenticación
 * Login, logout, verificar sesión, cambiar contraseña
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../config/database.php';

// Obtener conexión
$pdo = getConnection();

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'login':
            handleLogin();
            break;
        case 'logout':
            handleLogout();
            break;
        case 'me':
            handleMe();
            break;
        case 'change-password':
            handleChangePassword();
            break;
        case 'my-metrics':
            handleMyMetrics();
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Acción no válida']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

/**
 * Login - Autenticar usuario
 */
function handleLogin() {
    global $pdo;
    
    $data = json_decode(file_get_contents('php://input'), true);
    $email = trim($data['email'] ?? '');
    $password = $data['password'] ?? '';
    
    if (empty($email) || empty($password)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Email y contraseña requeridos']);
        return;
    }
    
    // Buscar usuario por email
    $stmt = $pdo->prepare("SELECT id, name, email, password_hash, role, is_active FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Credenciales incorrectas']);
        return;
    }
    
    if (!$user['is_active']) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Usuario desactivado']);
        return;
    }
    
    // Solo permitir login a super_admin, admin y agent
    $allowedRoles = ['super_admin', 'admin', 'agent'];
    if (!in_array($user['role'], $allowedRoles)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No tienes permiso para acceder']);
        return;
    }
    
    // Verificar contraseña
    if (!password_verify($password, $user['password_hash'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Credenciales incorrectas']);
        return;
    }
    
    // Generar token de sesión
    $token = bin2hex(random_bytes(32));
    
    // Guardar sesión (sin expiración)
    $stmt = $pdo->prepare("INSERT INTO user_sessions (user_id, token, ip_address, user_agent) VALUES (?, ?, ?, ?)");
    $stmt->execute([
        $user['id'],
        $token,
        $_SERVER['REMOTE_ADDR'] ?? null,
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)
    ]);
    
    // Responder con datos del usuario
    echo json_encode([
        'success' => true,
        'token' => $token,
        'user' => [
            'id' => (int)$user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role']
        ]
    ]);
}

/**
 * Logout - Cerrar sesión
 */
function handleLogout() {
    global $pdo;
    
    $token = getBearerToken();
    
    if (!$token) {
        echo json_encode(['success' => true]);
        return;
    }
    
    // Eliminar sesión
    $stmt = $pdo->prepare("DELETE FROM user_sessions WHERE token = ?");
    $stmt->execute([$token]);
    
    echo json_encode(['success' => true]);
}

/**
 * Me - Obtener usuario actual
 */
function handleMe() {
    global $pdo;
    
    $token = getBearerToken();
    
    if (!$token) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'No autenticado']);
        return;
    }
    
    // Buscar sesión (sin verificar expiración)
    $stmt = $pdo->prepare("
        SELECT u.id, u.name, u.email, u.role, u.is_active
        FROM user_sessions s
        JOIN users u ON s.user_id = u.id
        WHERE s.token = ? AND u.is_active = 1
    ");
    $stmt->execute([$token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Sesión inválida']);
        return;
    }
    
    echo json_encode([
        'success' => true,
        'user' => [
            'id' => (int)$user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role']
        ]
    ]);
}

/**
 * Cambiar contraseña
 */
function handleChangePassword() {
    global $pdo;
    
    $token = getBearerToken();
    $user = getUserFromToken($token);
    
    if (!$user) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'No autenticado']);
        return;
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    $currentPassword = $data['current_password'] ?? '';
    $newPassword = $data['new_password'] ?? '';
    
    if (empty($newPassword) || strlen($newPassword) < 6) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'La nueva contraseña debe tener al menos 6 caracteres']);
        return;
    }
    
    // Obtener hash actual
    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
    $stmt->execute([$user['id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Verificar contraseña actual
    if (!password_verify($currentPassword, $row['password_hash'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Contraseña actual incorrecta']);
        return;
    }
    
    // Actualizar contraseña
    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
    $stmt->execute([$newHash, $user['id']]);
    
    echo json_encode(['success' => true, 'message' => 'Contraseña actualizada']);
}

/**
 * Métricas del agente actual
 */
function handleMyMetrics() {
    global $pdo;
    
    $token = getBearerToken();
    $user = getUserFromToken($token);
    
    if (!$user) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'No autenticado']);
        return;
    }
    
    $userId = $user['id'];
    
    // Tickets asignados totales
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE assigned_to = ?");
    $stmt->execute([$userId]);
    $totalAssigned = $stmt->fetchColumn();
    
    // Tickets pendientes (abiertos o en progreso)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE assigned_to = ? AND status IN ('open', 'in_progress')");
    $stmt->execute([$userId]);
    $pending = $stmt->fetchColumn();
    
    // Tickets completados este mes
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM tickets 
        WHERE assigned_to = ? 
        AND status IN ('resolved', 'closed') 
        AND MONTH(updated_at) = MONTH(NOW()) 
        AND YEAR(updated_at) = YEAR(NOW())
    ");
    $stmt->execute([$userId]);
    $completedThisMonth = $stmt->fetchColumn();
    
    // Tickets vencidos
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM tickets 
        WHERE assigned_to = ? 
        AND status IN ('open', 'in_progress') 
        AND due_date < CURDATE()
    ");
    $stmt->execute([$userId]);
    $overdue = $stmt->fetchColumn();
    
    // Tiempo promedio de resolución (en horas)
    $stmt = $pdo->prepare("
        SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, updated_at)) as avg_hours
        FROM tickets 
        WHERE assigned_to = ? 
        AND status IN ('resolved', 'closed')
    ");
    $stmt->execute([$userId]);
    $avgResolution = round($stmt->fetchColumn() ?? 0, 1);
    
    // Tickets por estado
    $stmt = $pdo->prepare("
        SELECT status, COUNT(*) as count 
        FROM tickets 
        WHERE assigned_to = ? 
        GROUP BY status
    ");
    $stmt->execute([$userId]);
    $byStatus = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // Tickets completados por mes (últimos 6 meses)
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(updated_at, '%Y-%m') as month, COUNT(*) as count
        FROM tickets 
        WHERE assigned_to = ? 
        AND status IN ('resolved', 'closed')
        AND updated_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(updated_at, '%Y-%m')
        ORDER BY month
    ");
    $stmt->execute([$userId]);
    $monthlyCompleted = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'metrics' => [
            'total_assigned' => (int)$totalAssigned,
            'pending' => (int)$pending,
            'completed_this_month' => (int)$completedThisMonth,
            'overdue' => (int)$overdue,
            'avg_resolution_hours' => $avgResolution,
            'by_status' => $byStatus,
            'monthly_completed' => $monthlyCompleted
        ]
    ]);
}

/**
 * Obtener token del header Authorization
 */
function getBearerToken() {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    
    if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        return $matches[1];
    }
    
    return null;
}

/**
 * Obtener usuario desde token
 */
function getUserFromToken($token) {
    global $pdo;
    
    if (!$token) {
        return null;
    }
    
    $stmt = $pdo->prepare("
        SELECT u.id, u.name, u.email, u.role
        FROM user_sessions s
        JOIN users u ON s.user_id = u.id
        WHERE s.token = ? AND u.is_active = 1
    ");
    $stmt->execute([$token]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}
