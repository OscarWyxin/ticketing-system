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
