<?php
/**
 * Script para crear datos de prueba en el dashboard
 * Ejecutar: php seed_test_data.php
 */

// Configuración de base de datos local (Laragon)
$host = 'localhost';
$dbname = 'ticketing_system';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✅ Conectado a la base de datos\n\n";
} catch (PDOException $e) {
    die("❌ Error de conexión: " . $e->getMessage() . "\n");
}

// Deshabilitar foreign keys temporalmente
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

echo "🗑️  Limpiando tablas...\n";

// Vaciar tablas en orden
$tables = ['activity_log', 'comments', 'attachments', 'ticket_tracking_tokens', 'tickets', 'project_activities', 'project_phases', 'projects'];
foreach ($tables as $table) {
    try {
        $pdo->exec("TRUNCATE TABLE $table");
        echo "   - $table vaciada\n";
    } catch (Exception $e) {
        echo "   - $table: " . $e->getMessage() . "\n";
    }
}

// Rehabilitar foreign keys
$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

echo "\n👥 Verificando usuarios/agentes...\n";

// Verificar que existen los agentes
$agents = [
    ['name' => 'Alfonso Bello', 'email' => 'alfonso@example.com', 'role' => 'admin'],
    ['name' => 'Alicia García', 'email' => 'alicia@example.com', 'role' => 'agent'],
    ['name' => 'Ángel Martínez', 'email' => 'angel@example.com', 'role' => 'agent'],
    ['name' => 'Oscar Calamita', 'email' => 'oscar@example.com', 'role' => 'agent'],
    ['name' => 'Fiorella Rossi', 'email' => 'fiorella@example.com', 'role' => 'agent'],
];

$agentIds = [];
foreach ($agents as $agent) {
    // Verificar si existe
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$agent['email']]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        $agentIds[] = $existing['id'];
        echo "   - {$agent['name']} ya existe (ID: {$existing['id']})\n";
    } else {
        $stmt = $pdo->prepare("INSERT INTO users (name, email, role, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$agent['name'], $agent['email'], $agent['role']]);
        $agentIds[] = $pdo->lastInsertId();
        echo "   - {$agent['name']} creado (ID: " . $pdo->lastInsertId() . ")\n";
    }
}

echo "\n📁 Creando proyectos...\n";

$projects = [
    ['name' => 'Central A y B', 'description' => 'Proyecto de centrales A y B', 'status' => 'active'],
    ['name' => 'Gómez Briones', 'description' => 'Proyecto Gómez Briones', 'status' => 'active'],
    ['name' => 'Clínica de Madrid', 'description' => 'Proyecto Clínica Madrid', 'status' => 'active'],
    ['name' => 'Torre Empresarial', 'description' => 'Proyecto Torre Empresarial', 'status' => 'active'],
    ['name' => 'Residencial Norte', 'description' => 'Proyecto Residencial Norte', 'status' => 'active'],
    ['name' => 'Centro Comercial Sur', 'description' => 'Proyecto Centro Comercial', 'status' => 'active'],
];

$projectIds = [];
foreach ($projects as $project) {
    $stmt = $pdo->prepare("INSERT INTO projects (name, description, status, created_at) VALUES (?, ?, ?, NOW())");
    $stmt->execute([$project['name'], $project['description'], $project['status']]);
    $projectIds[] = $pdo->lastInsertId();
    echo "   - {$project['name']} creado\n";
}

echo "\n📂 Verificando categorías...\n";

// Verificar categorías
$stmt = $pdo->query("SELECT id, name FROM categories");
$categories = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

if (empty($categories)) {
    $defaultCategories = [
        ['name' => 'Soporte Técnico', 'color' => '#3b82f6'],
        ['name' => 'Consultoría', 'color' => '#8b5cf6'],
        ['name' => 'Desarrollo', 'color' => '#22c55e'],
        ['name' => 'Diseño', 'color' => '#f59e0b'],
        ['name' => 'Administración', 'color' => '#ef4444'],
    ];
    
    foreach ($defaultCategories as $cat) {
        $stmt = $pdo->prepare("INSERT INTO categories (name, color) VALUES (?, ?)");
        $stmt->execute([$cat['name'], $cat['color']]);
        $categories[$pdo->lastInsertId()] = $cat['name'];
        echo "   - {$cat['name']} creada\n";
    }
} else {
    echo "   - " . count($categories) . " categorías existentes\n";
}

$categoryIds = array_keys($categories);

echo "\n🎫 Creando 100 tickets de prueba...\n";

// Estados y prioridades
$statuses = ['open', 'in_progress', 'waiting', 'resolved', 'closed'];
$statusWeights = [20, 25, 15, 30, 10]; // Porcentajes aproximados
$priorities = ['low', 'medium', 'high', 'urgent'];
$priorityWeights = [20, 40, 30, 10];

// Clientes de ejemplo
$clients = [
    ['name' => 'Juan Pérez', 'email' => 'juan@cliente.com'],
    ['name' => 'María López', 'email' => 'maria@cliente.com'],
    ['name' => 'Carlos Rodríguez', 'email' => 'carlos@cliente.com'],
    ['name' => 'Ana Martínez', 'email' => 'ana@cliente.com'],
    ['name' => 'Pedro Sánchez', 'email' => 'pedro@cliente.com'],
    ['name' => 'Laura García', 'email' => 'laura@cliente.com'],
    ['name' => 'Roberto Fernández', 'email' => 'roberto@cliente.com'],
    ['name' => 'Carmen Díaz', 'email' => 'carmen@cliente.com'],
];

// Títulos de tickets de ejemplo
$ticketTitles = [
    'Error en el sistema de login',
    'Actualización de diseño web',
    'Problema con la conexión',
    'Solicitud de nuevo módulo',
    'Bug en el formulario de contacto',
    'Mejora de rendimiento',
    'Integración con API externa',
    'Configuración de servidor',
    'Problema de seguridad detectado',
    'Actualización de base de datos',
    'Error en reportes PDF',
    'Cambio de dominio',
    'Instalación de certificado SSL',
    'Optimización de imágenes',
    'Error en proceso de pago',
    'Migración de datos',
    'Capacitación del sistema',
    'Mantenimiento preventivo',
    'Auditoría de código',
    'Backup y recuperación',
];

function weightedRandom($items, $weights) {
    $totalWeight = array_sum($weights);
    $rand = mt_rand(1, $totalWeight);
    $cumulative = 0;
    
    foreach ($items as $i => $item) {
        $cumulative += $weights[$i];
        if ($rand <= $cumulative) {
            return $item;
        }
    }
    return $items[0];
}

$ticketCount = 0;
$statusCounts = array_fill_keys($statuses, 0);

for ($i = 1; $i <= 100; $i++) {
    $status = weightedRandom($statuses, $statusWeights);
    $priority = weightedRandom($priorities, $priorityWeights);
    $agentId = $agentIds[array_rand($agentIds)];
    $projectId = $projectIds[array_rand($projectIds)];
    $categoryId = $categoryIds[array_rand($categoryIds)];
    $client = $clients[array_rand($clients)];
    $title = $ticketTitles[array_rand($ticketTitles)];
    
    // Generar número de ticket único
    $ticketNumber = 'T-' . date('Ymd') . '-' . str_pad($i, 4, '0', STR_PAD_LEFT);
    
    // Fechas aleatorias en los últimos 90 días
    $daysAgo = mt_rand(0, 90);
    $createdAt = date('Y-m-d H:i:s', strtotime("-$daysAgo days -" . mt_rand(0, 23) . " hours"));
    
    // Fecha de resolución si está resuelto
    $resolvedAt = null;
    if (in_array($status, ['resolved', 'closed'])) {
        $resolvedDaysAgo = mt_rand(0, $daysAgo);
        $resolvedAt = date('Y-m-d H:i:s', strtotime("-$resolvedDaysAgo days"));
    }
    
    $description = "Descripción del ticket #$i: $title. Cliente: {$client['name']}. " .
                   "Lorem ipsum dolor sit amet, consectetur adipiscing elit. " .
                   "Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.";
    
    $stmt = $pdo->prepare("
        INSERT INTO tickets (
            ticket_number, title, description, status, priority,
            category_id, project_id, assigned_to,
            contact_name, contact_email,
            created_at, updated_at, resolved_at, backlog
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, FALSE)
    ");
    
    $stmt->execute([
        $ticketNumber,
        $title . " - Ticket #$i",
        $description,
        $status,
        $priority,
        $categoryId,
        $projectId,
        $agentId,
        $client['name'],
        $client['email'],
        $createdAt,
        $createdAt,
        $resolvedAt
    ]);
    
    $statusCounts[$status]++;
    $ticketCount++;
    
    if ($i % 25 == 0) {
        echo "   - $i tickets creados...\n";
    }
}

echo "\n✅ ¡Completado! Se crearon $ticketCount tickets:\n\n";
echo "📊 Distribución por estado:\n";
foreach ($statusCounts as $status => $count) {
    $bar = str_repeat('█', (int)($count / 2));
    echo "   $status: $count $bar\n";
}

echo "\n📊 Distribución por agente:\n";
$stmt = $pdo->query("
    SELECT u.name, COUNT(*) as total,
           SUM(CASE WHEN t.status IN ('open', 'in_progress') THEN 1 ELSE 0 END) as open,
           SUM(CASE WHEN t.status = 'waiting' THEN 1 ELSE 0 END) as waiting,
           SUM(CASE WHEN t.status IN ('resolved', 'closed') THEN 1 ELSE 0 END) as resolved
    FROM tickets t
    JOIN users u ON t.assigned_to = u.id
    GROUP BY u.id, u.name
    ORDER BY total DESC
");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "   {$row['name']}: {$row['total']} total ({$row['open']} abiertos, {$row['waiting']} espera, {$row['resolved']} resueltos)\n";
}

echo "\n📊 Distribución por proyecto:\n";
$stmt = $pdo->query("
    SELECT p.name, COUNT(*) as total
    FROM tickets t
    JOIN projects p ON t.project_id = p.id
    GROUP BY p.id, p.name
    ORDER BY total DESC
");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "   {$row['name']}: {$row['total']} tickets\n";
}

echo "\n🎉 ¡Listo! Ahora abre el dashboard para ver las métricas.\n";
