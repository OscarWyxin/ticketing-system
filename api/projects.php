<?php
/**
 * API de Gestión de Proyectos
 * Maneja proyectos, fases y actividades
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/ghl-notifications.php';

$pdo = getConnection();
$action = $_GET['action'] ?? 'list';

try {
    switch ($action) {
        // =====================================================
        // PROYECTOS
        // =====================================================
        case 'list':
            listProjects($pdo);
            break;
        case 'get':
            getProject($pdo, $_GET['id'] ?? 0);
            break;
        case 'create':
            createProject($pdo);
            break;
        case 'update':
            updateProject($pdo, $_GET['id'] ?? 0);
            break;
        case 'delete':
            deleteProject($pdo, $_GET['id'] ?? 0);
            break;
            
        // =====================================================
        // FASES
        // =====================================================
        case 'create-phase':
            createPhase($pdo);
            break;
        case 'update-phase':
            updatePhase($pdo, $_GET['id'] ?? 0);
            break;
        case 'delete-phase':
            deletePhase($pdo, $_GET['id'] ?? 0);
            break;
        case 'reorder-phases':
            reorderPhases($pdo);
            break;
            
        // =====================================================
        // ACTIVIDADES
        // =====================================================
        case 'create-activity':
            createActivity($pdo);
            break;
        case 'update-activity':
            updateActivity($pdo, $_GET['id'] ?? 0);
            break;
        case 'delete-activity':
            deleteActivity($pdo, $_GET['id'] ?? 0);
            break;
        case 'convert-to-ticket':
            convertToTicket($pdo, $_GET['id'] ?? 0);
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Acción no válida']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

// =====================================================
// FUNCIONES DE PROYECTOS
// =====================================================

function listProjects($pdo) {
    $sql = "SELECT p.*, 
                   u.name as responsible_name,
                   a.name as client_name,
                   (SELECT COUNT(*) FROM project_phases WHERE project_id = p.id) as phase_count,
                   (SELECT COUNT(*) FROM project_activities WHERE project_id = p.id) as activity_count,
                   (SELECT COUNT(*) FROM project_activities WHERE project_id = p.id AND status = 'completed') as completed_activities
            FROM projects p
            LEFT JOIN users u ON p.responsible_id = u.id
            LEFT JOIN accounts a ON p.client_id = a.id
            WHERE p.status != 'archived'
            ORDER BY p.name ASC";
    
    $stmt = $pdo->query($sql);
    $projects = $stmt->fetchAll();
    
    // Calcular progreso
    foreach ($projects as &$project) {
        if ($project['activity_count'] > 0) {
            $project['progress'] = round(($project['completed_activities'] / $project['activity_count']) * 100);
        } else {
            $project['progress'] = 0;
        }
    }
    
    echo json_encode(['success' => true, 'projects' => $projects]);
}

function getProject($pdo, $id) {
    if (!$id) {
        echo json_encode(['success' => false, 'error' => 'ID requerido']);
        return;
    }
    
    // Obtener proyecto
    $stmt = $pdo->prepare("SELECT p.*, 
                                  u.name as responsible_name,
                                  a.name as client_name
                           FROM projects p
                           LEFT JOIN users u ON p.responsible_id = u.id
                           LEFT JOIN accounts a ON p.client_id = a.id
                           WHERE p.id = ?");
    $stmt->execute([$id]);
    $project = $stmt->fetch();
    
    if (!$project) {
        echo json_encode(['success' => false, 'error' => 'Proyecto no encontrado']);
        return;
    }
    
    // Obtener fases con sus actividades
    $stmt = $pdo->prepare("SELECT * FROM project_phases WHERE project_id = ? ORDER BY sort_order ASC");
    $stmt->execute([$id]);
    $phases = $stmt->fetchAll();
    
    // Para cada fase, obtener actividades
    foreach ($phases as &$phase) {
        $stmtActivities = $pdo->prepare("SELECT pa.*, 
                                      uc.name as contact_name,
                                      ua.name as assigned_name,
                                      t.ticket_number
                               FROM project_activities pa
                               LEFT JOIN users uc ON pa.contact_user_id = uc.id
                               LEFT JOIN users ua ON pa.assigned_to = ua.id
                               LEFT JOIN tickets t ON pa.ticket_id = t.id
                               WHERE pa.phase_id = ?
                               ORDER BY pa.sort_order ASC");
        $stmtActivities->execute([$phase['id']]);
        $phase['activities'] = $stmtActivities->fetchAll();
    }
    unset($phase); // Romper la referencia para evitar bug de PHP
    
    $project['phases'] = $phases;
    
    // Calcular estadísticas
    $totalActivities = 0;
    $completedActivities = 0;
    foreach ($phases as $phase) {
        $totalActivities += count($phase['activities']);
        foreach ($phase['activities'] as $activity) {
            if ($activity['status'] === 'completed' || $activity['status'] === 'converted') {
                $completedActivities++;
            }
        }
    }
    $project['stats'] = [
        'total_phases' => count($phases),
        'total_activities' => $totalActivities,
        'completed_activities' => $completedActivities,
        'progress' => $totalActivities > 0 ? round(($completedActivities / $totalActivities) * 100) : 0
    ];
    
    echo json_encode(['success' => true, 'project' => $project]);
}

function createProject($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (empty($input['name'])) {
        echo json_encode(['success' => false, 'error' => 'Nombre requerido']);
        return;
    }
    
    // Convertir strings vacíos a null para campos opcionales
    $clientId = !empty($input['client_id']) ? $input['client_id'] : null;
    $responsibleId = !empty($input['responsible_id']) ? $input['responsible_id'] : null;
    $startDate = !empty($input['start_date']) ? $input['start_date'] : null;
    $endDate = !empty($input['end_date']) ? $input['end_date'] : null;
    
    $stmt = $pdo->prepare("INSERT INTO projects (name, description, client_id, responsible_id, start_date, end_date, backlog_type, status) 
                           VALUES (?, ?, ?, ?, ?, ?, ?, 'active')");
    $stmt->execute([
        $input['name'],
        $input['description'] ?? null,
        $clientId,
        $responsibleId,
        $startDate,
        $endDate,
        $input['backlog_type'] ?? 'consultoria'
    ]);
    
    $projectId = $pdo->lastInsertId();
    
    // Generar formulario HTML automáticamente
    $formGenerated = generateProjectForm($projectId, $input['name'], $input['backlog_type'] ?? 'consultoria', $input['responsible_id'] ?? null);
    
    echo json_encode([
        'success' => true, 
        'id' => $projectId, 
        'message' => 'Proyecto creado',
        'form_generated' => $formGenerated
    ]);
}

function updateProject($pdo, $id) {
    if (!$id) {
        echo json_encode(['success' => false, 'error' => 'ID requerido']);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    $fields = [];
    $values = [];
    
    $allowedFields = ['name', 'description', 'client_id', 'responsible_id', 'start_date', 'end_date', 'status'];
    $integerFields = ['client_id', 'responsible_id'];
    
    foreach ($allowedFields as $field) {
        if (isset($input[$field])) {
            $fields[] = "$field = ?";
            // Convertir strings vacíos a null para campos integer
            $value = $input[$field];
            if (in_array($field, $integerFields) && $value === '') {
                $value = null;
            } elseif ($value === '') {
                $value = null;
            }
            $values[] = $value;
        }
    }
    
    if (empty($fields)) {
        echo json_encode(['success' => false, 'error' => 'No hay campos para actualizar']);
        return;
    }
    
    $values[] = $id;
    $sql = "UPDATE projects SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($values);
    
    echo json_encode(['success' => true, 'message' => 'Proyecto actualizado']);
}

function deleteProject($pdo, $id) {
    if (!$id) {
        echo json_encode(['success' => false, 'error' => 'ID requerido']);
        return;
    }
    
    // Soft delete - cambiar a archived
    $stmt = $pdo->prepare("UPDATE projects SET status = 'archived' WHERE id = ?");
    $stmt->execute([$id]);
    
    echo json_encode(['success' => true, 'message' => 'Proyecto archivado']);
}

// =====================================================
// FUNCIONES DE FASES
// =====================================================

function createPhase($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (empty($input['project_id']) || empty($input['name'])) {
        echo json_encode(['success' => false, 'error' => 'Proyecto y nombre requeridos']);
        return;
    }
    
    // Obtener el siguiente sort_order
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 as next_order FROM project_phases WHERE project_id = ?");
    $stmt->execute([$input['project_id']]);
    $nextOrder = $stmt->fetch()['next_order'];
    
    $stmt = $pdo->prepare("INSERT INTO project_phases (project_id, name, description, sort_order, status, start_date, end_date) 
                           VALUES (?, ?, ?, ?, 'pending', ?, ?)");
    $stmt->execute([
        $input['project_id'],
        $input['name'],
        $input['description'] ?? null,
        $nextOrder,
        $input['start_date'] ?? null,
        $input['end_date'] ?? null
    ]);
    
    $phaseId = $pdo->lastInsertId();
    
    echo json_encode(['success' => true, 'id' => $phaseId, 'message' => 'Fase creada']);
}

function updatePhase($pdo, $id) {
    if (!$id) {
        echo json_encode(['success' => false, 'error' => 'ID requerido']);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    $fields = [];
    $values = [];
    
    $allowedFields = ['name', 'description', 'status', 'start_date', 'end_date', 'sort_order'];
    
    foreach ($allowedFields as $field) {
        if (isset($input[$field])) {
            $fields[] = "$field = ?";
            $values[] = $input[$field] ?: null;
        }
    }
    
    if (empty($fields)) {
        echo json_encode(['success' => false, 'error' => 'No hay campos para actualizar']);
        return;
    }
    
    $values[] = $id;
    $sql = "UPDATE project_phases SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($values);
    
    echo json_encode(['success' => true, 'message' => 'Fase actualizada']);
}

function deletePhase($pdo, $id) {
    if (!$id) {
        echo json_encode(['success' => false, 'error' => 'ID requerido']);
        return;
    }
    
    // Verificar si tiene actividades convertidas a tickets
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM project_activities WHERE phase_id = ? AND ticket_id IS NOT NULL");
    $stmt->execute([$id]);
    $result = $stmt->fetch();
    
    if ($result['count'] > 0) {
        echo json_encode(['success' => false, 'error' => 'No se puede eliminar: tiene actividades convertidas a tickets']);
        return;
    }
    
    $stmt = $pdo->prepare("DELETE FROM project_phases WHERE id = ?");
    $stmt->execute([$id]);
    
    echo json_encode(['success' => true, 'message' => 'Fase eliminada']);
}

function reorderPhases($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (empty($input['phases']) || !is_array($input['phases'])) {
        echo json_encode(['success' => false, 'error' => 'Lista de fases requerida']);
        return;
    }
    
    $stmt = $pdo->prepare("UPDATE project_phases SET sort_order = ? WHERE id = ?");
    
    foreach ($input['phases'] as $index => $phaseId) {
        $stmt->execute([$index, $phaseId]);
    }
    
    echo json_encode(['success' => true, 'message' => 'Fases reordenadas']);
}

// =====================================================
// FUNCIONES DE ACTIVIDADES
// =====================================================

function createActivity($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (empty($input['phase_id']) || empty($input['title'])) {
        echo json_encode(['success' => false, 'error' => 'Fase y título requeridos']);
        return;
    }
    
    // Obtener project_id de la fase
    $stmt = $pdo->prepare("SELECT project_id FROM project_phases WHERE id = ?");
    $stmt->execute([$input['phase_id']]);
    $phase = $stmt->fetch();
    
    if (!$phase) {
        echo json_encode(['success' => false, 'error' => 'Fase no encontrada']);
        return;
    }
    
    // Obtener el siguiente sort_order
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 as next_order FROM project_activities WHERE phase_id = ?");
    $stmt->execute([$input['phase_id']]);
    $nextOrder = $stmt->fetch()['next_order'];
    
    // Convertir strings vacíos a null
    $contactUserId = !empty($input['contact_user_id']) ? $input['contact_user_id'] : null;
    $assignedTo = !empty($input['assigned_to']) ? $input['assigned_to'] : null;
    $startDate = !empty($input['start_date']) ? $input['start_date'] : null;
    $endDate = !empty($input['end_date']) ? $input['end_date'] : null;
    
    $stmt = $pdo->prepare("INSERT INTO project_activities 
                           (phase_id, project_id, title, description, contact_user_id, assigned_to, notes, video_url, start_date, end_date, sort_order, status) 
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
    $stmt->execute([
        $input['phase_id'],
        $phase['project_id'],
        $input['title'],
        $input['description'] ?? null,
        $contactUserId,
        $assignedTo,
        $input['notes'] ?? null,
        $input['video_url'] ?? null,
        $startDate,
        $endDate,
        $nextOrder
    ]);
    
    $activityId = $pdo->lastInsertId();
    
    // Notificar al responsable si se asignó uno
    if ($assignedTo && function_exists('notifyActivityAssignment')) {
        // Obtener nombre del proyecto
        $stmt = $pdo->prepare("SELECT name FROM projects WHERE id = ?");
        $stmt->execute([$phase['project_id']]);
        $projectName = $stmt->fetch()['name'] ?? 'Proyecto';
        
        notifyActivityAssignment($pdo, $assignedTo, [
            'activity_id' => $activityId,
            'title' => $input['title'],
            'description' => $input['description'] ?? '',
            'project_name' => $projectName,
            'project_id' => $phase['project_id']
        ]);
    }
    
    echo json_encode(['success' => true, 'id' => $activityId, 'message' => 'Actividad creada']);
}

function updateActivity($pdo, $id) {
    if (!$id) {
        echo json_encode(['success' => false, 'error' => 'ID requerido']);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Obtener actividad actual para comparar assigned_to
    $stmt = $pdo->prepare("SELECT pa.*, p.name as project_name FROM project_activities pa 
                           JOIN projects p ON pa.project_id = p.id WHERE pa.id = ?");
    $stmt->execute([$id]);
    $currentActivity = $stmt->fetch();
    
    $fields = [];
    $values = [];
    
    $allowedFields = ['title', 'description', 'contact_user_id', 'assigned_to', 'notes', 'video_url', 'status', 'sort_order', 'start_date', 'end_date'];
    $integerFields = ['contact_user_id', 'assigned_to', 'sort_order'];
    
    foreach ($allowedFields as $field) {
        if (isset($input[$field])) {
            $fields[] = "$field = ?";
            // Convertir strings vacíos a null
            $value = $input[$field];
            if ((in_array($field, $integerFields) || strpos($field, '_date') !== false) && $value === '') {
                $value = null;
            }
            $values[] = $value ?: null;
        }
    }
    
    if (empty($fields)) {
        echo json_encode(['success' => false, 'error' => 'No hay campos para actualizar']);
        return;
    }
    
    $values[] = $id;
    $sql = "UPDATE project_activities SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($values);
    
    // Notificar si cambió el asignado
    $newAssignedTo = isset($input['assigned_to']) && $input['assigned_to'] !== '' ? $input['assigned_to'] : null;
    if ($newAssignedTo && $newAssignedTo != $currentActivity['assigned_to'] && function_exists('notifyActivityAssignment')) {
        notifyActivityAssignment($pdo, $newAssignedTo, [
            'activity_id' => $id,
            'title' => $input['title'] ?? $currentActivity['title'],
            'description' => $input['description'] ?? $currentActivity['description'] ?? '',
            'project_name' => $currentActivity['project_name'],
            'project_id' => $currentActivity['project_id']
        ]);
    }
    
    echo json_encode(['success' => true, 'message' => 'Actividad actualizada']);
}

function deleteActivity($pdo, $id) {
    if (!$id) {
        echo json_encode(['success' => false, 'error' => 'ID requerido']);
        return;
    }
    
    // Verificar si está convertida a ticket
    $stmt = $pdo->prepare("SELECT ticket_id FROM project_activities WHERE id = ?");
    $stmt->execute([$id]);
    $activity = $stmt->fetch();
    
    if ($activity && $activity['ticket_id']) {
        echo json_encode(['success' => false, 'error' => 'No se puede eliminar: ya está convertida a ticket']);
        return;
    }
    
    $stmt = $pdo->prepare("DELETE FROM project_activities WHERE id = ?");
    $stmt->execute([$id]);
    
    echo json_encode(['success' => true, 'message' => 'Actividad eliminada']);
}

function convertToTicket($pdo, $id) {
    if (!$id) {
        echo json_encode(['success' => false, 'error' => 'ID requerido']);
        return;
    }
    
    // Obtener actividad con datos relacionados
    $stmt = $pdo->prepare("SELECT pa.*, 
                                  pp.project_id,
                                  p.name as project_name,
                                  uc.name as contact_name,
                                  uc.email as contact_email,
                                  uc.phone as contact_phone
                           FROM project_activities pa
                           JOIN project_phases pp ON pa.phase_id = pp.id
                           JOIN projects p ON pp.project_id = p.id
                           LEFT JOIN users uc ON pa.contact_user_id = uc.id
                           WHERE pa.id = ?");
    $stmt->execute([$id]);
    $activity = $stmt->fetch();
    
    if (!$activity) {
        echo json_encode(['success' => false, 'error' => 'Actividad no encontrada']);
        return;
    }
    
    if ($activity['ticket_id']) {
        echo json_encode(['success' => false, 'error' => 'Esta actividad ya fue convertida a ticket']);
        return;
    }
    
    // Generar número de ticket
    $stmt = $pdo->query("SELECT COUNT(*) + 1 as next FROM tickets");
    $next = $stmt->fetch()['next'];
    $ticketNumber = 'TKT-' . str_pad($next, 5, '0', STR_PAD_LEFT);
    
    // Verificar si el número ya existe y generar uno nuevo si es necesario
    $stmt = $pdo->prepare("SELECT id FROM tickets WHERE ticket_number = ?");
    $stmt->execute([$ticketNumber]);
    while ($stmt->fetch()) {
        $next++;
        $ticketNumber = 'TKT-' . str_pad($next, 5, '0', STR_PAD_LEFT);
        $stmt->execute([$ticketNumber]);
    }
    
    // Crear ticket
    $description = $activity['description'] ?? '';
    if ($activity['notes']) {
        $description .= "\n\n📝 Notas:\n" . $activity['notes'];
    }
    if ($activity['video_url']) {
        $description .= "\n\n🎥 Video: " . $activity['video_url'];
    }
    
    $stmt = $pdo->prepare("INSERT INTO tickets 
                           (ticket_number, title, description, project_id, assigned_to, 
                            contact_name, contact_email, contact_phone, video_url,
                            status, priority, source, created_at) 
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'open', 'medium', 'internal', NOW())");
    $stmt->execute([
        $ticketNumber,
        $activity['title'],
        $description,
        $activity['project_id'],
        $activity['assigned_to'],
        $activity['contact_name'],
        $activity['contact_email'],
        $activity['contact_phone'],
        $activity['video_url']
    ]);
    
    $ticketId = $pdo->lastInsertId();
    
    // Actualizar actividad
    $stmt = $pdo->prepare("UPDATE project_activities SET ticket_id = ?, status = 'converted', updated_at = NOW() WHERE id = ?");
    $stmt->execute([$ticketId, $id]);
    
    echo json_encode([
        'success' => true, 
        'ticket_id' => $ticketId,
        'ticket_number' => $ticketNumber,
        'message' => "Ticket $ticketNumber creado"
    ]);
}

/**
 * Genera un formulario HTML para un proyecto
 */
function generateProjectForm($projectId, $projectName, $backlogType, $responsibleId) {
    $templatePath = __DIR__ . '/../forms/template.html';
    $formsDir = __DIR__ . '/../forms/';
    
    if (!file_exists($templatePath)) {
        error_log("Template no encontrado: $templatePath");
        return false;
    }
    
    // Crear slug del nombre del proyecto
    $slug = strtolower(trim($projectName));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    
    $formFilename = "form-{$slug}.html";
    $formPath = $formsDir . $formFilename;
    
    // Leer template
    $template = file_get_contents($templatePath);
    
    // Reemplazar placeholders
    $template = str_replace('{{PROJECT_NAME}}', htmlspecialchars($projectName), $template);
    $template = str_replace('{{PROJECT_ID}}', $projectId, $template);
    $template = str_replace('{{BACKLOG_TYPE}}', $backlogType, $template);
    $template = str_replace('{{ASSIGNED_USER_ID}}', $responsibleId ?: 'null', $template);
    
    // Guardar archivo
    if (file_put_contents($formPath, $template)) {
        error_log("Formulario creado: $formPath");
        return [
            'filename' => $formFilename,
            'url' => "forms/{$formFilename}"
        ];
    }
    
    error_log("Error al crear formulario: $formPath");
    return false;
}
?>
