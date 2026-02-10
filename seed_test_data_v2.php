<?php
/**
 * Script para poblar datos de prueba CON FECHAS VARIADAS
 * Esto permite probar los filtros de fecha del dashboard
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config/database.php';

echo "<h1>🎯 Seed de Datos de Prueba v2 - Con Fechas Variadas</h1>";
echo "<pre>";

try {
    $pdo = getConnection();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Primero eliminar tickets de prueba anteriores
    echo "🗑️ Eliminando tickets de prueba anteriores...\n";
    $stmt = $pdo->prepare("DELETE FROM tickets WHERE title LIKE '[TEST]%' OR title LIKE '[PRUEBA]%'");
    $stmt->execute();
    $deleted = $stmt->rowCount();
    echo "   Eliminados: $deleted tickets\n\n";
    
    // Obtener agentes existentes
    $stmt = $pdo->query("SELECT id, name FROM users WHERE role IN ('admin', 'agent') ORDER BY id");
    $agents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($agents)) {
        die("❌ No hay agentes en la base de datos. Crea usuarios primero.\n");
    }
    
    echo "👥 Agentes disponibles:\n";
    foreach ($agents as $agent) {
        echo "   - {$agent['name']} (ID: {$agent['id']})\n";
    }
    echo "\n";
    
    // Obtener proyectos
    $stmt = $pdo->query("SELECT id, name FROM projects ORDER BY id");
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($projects)) {
        echo "⚠️ No hay proyectos. Creando proyectos de prueba...\n";
        $projectNames = ['Clínica Madrid', 'Central A y B', 'Gómez Briones', 'Torres del Sol', 'Plaza Norte', 'Residencial Oasis'];
        foreach ($projectNames as $name) {
            $stmt = $pdo->prepare("INSERT INTO projects (name, created_at) VALUES (?, NOW())");
            $stmt->execute([$name]);
        }
        $stmt = $pdo->query("SELECT id, name FROM projects ORDER BY id");
        $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    echo "🏗️ Proyectos disponibles:\n";
    foreach ($projects as $project) {
        echo "   - {$project['name']} (ID: {$project['id']})\n";
    }
    echo "\n";
    
    // Obtener categorías
    $stmt = $pdo->query("SELECT id, name FROM categories ORDER BY id");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($categories)) {
        echo "⚠️ No hay categorías. Creando categorías de prueba...\n";
        $catNames = ['Soporte Técnico', 'Ventas', 'Facturación', 'General', 'Urgente'];
        foreach ($catNames as $name) {
            $stmt = $pdo->prepare("INSERT INTO categories (name, created_at) VALUES (?, NOW())");
            $stmt->execute([$name]);
        }
        $stmt = $pdo->query("SELECT id, name FROM categories ORDER BY id");
        $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Configuración de datos
    $statuses = ['open', 'in_progress', 'waiting', 'resolved', 'closed'];
    $priorities = ['low', 'medium', 'high', 'urgent'];
    
    // Asuntos de ejemplo
    $subjects = [
        '[TEST] Problema con acceso al sistema',
        '[TEST] Error en facturación mensual',
        '[TEST] Solicitud de nuevo usuario',
        '[TEST] Consulta sobre servicios',
        '[TEST] Problema técnico urgente',
        '[TEST] Actualización de datos',
        '[TEST] Incidencia en el portal',
        '[TEST] Solicitud de información',
        '[TEST] Error al procesar pago',
        '[TEST] Configuración de cuenta',
        '[TEST] Reporte de bug en app',
        '[TEST] Pregunta sobre funcionalidad',
        '[TEST] Cambio de contraseña',
        '[TEST] Problema de rendimiento',
        '[TEST] Solicitud de capacitación'
    ];
    
    $descriptions = [
        'El usuario reporta que no puede acceder correctamente al sistema desde hace varios días.',
        'Se detectó un error en la factura del mes pasado, favor revisar los montos.',
        'Se requiere crear un nuevo usuario para el departamento de ventas.',
        'Cliente solicita información detallada sobre los servicios disponibles.',
        'Sistema caído, urgente resolver para continuar operaciones.',
        'Necesito actualizar mi información de contacto y dirección.',
        'El portal web muestra errores intermitentes al cargar.',
        'Quisiera saber más sobre las opciones de pago disponibles.',
        'El pago no se procesó correctamente, favor verificar.',
        'Necesito ayuda para configurar mi cuenta de usuario.',
        'Encontré un error en la aplicación móvil al guardar datos.',
        'Tengo dudas sobre cómo usar la función de reportes.',
        'Olvidé mi contraseña y no puedo recuperarla.',
        'El sistema está muy lento últimamente.',
        'Necesitamos capacitación para el equipo nuevo.'
    ];
    
    // Definir rangos de fechas para distribución realista
    // 40% últimos 30 días, 30% últimos 90 días, 20% últimos 6 meses, 10% último año
    $dateRanges = [
        ['days' => 30, 'weight' => 40],   // Últimos 30 días
        ['days' => 90, 'weight' => 30],   // Últimos 90 días (incluye trimestre)
        ['days' => 180, 'weight' => 20],  // Últimos 6 meses
        ['days' => 365, 'weight' => 10],  // Último año
    ];
    
    function getRandomDate($dateRanges) {
        $rand = mt_rand(1, 100);
        $cumulative = 0;
        $maxDays = 30;
        
        foreach ($dateRanges as $range) {
            $cumulative += $range['weight'];
            if ($rand <= $cumulative) {
                $maxDays = $range['days'];
                break;
            }
        }
        
        $daysAgo = mt_rand(0, $maxDays);
        $date = new DateTime();
        $date->modify("-{$daysAgo} days");
        
        // Hora aleatoria
        $date->setTime(mt_rand(8, 18), mt_rand(0, 59), mt_rand(0, 59));
        
        return $date->format('Y-m-d H:i:s');
    }
    
    // Generar tickets
    $ticketsToCreate = 100;
    $created = 0;
    $statusCounts = array_fill_keys($statuses, 0);
    $agentCounts = [];
    $projectCounts = [];
    $dateCounts = ['last_7' => 0, 'last_30' => 0, 'last_90' => 0, 'older' => 0];
    
    echo "📝 Creando $ticketsToCreate tickets de prueba con fechas variadas...\n\n";
    
    // Obtener el último ticket_number
    $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(ticket_number, 5) AS UNSIGNED)) FROM tickets WHERE ticket_number LIKE 'TKT-%'");
    $lastNum = (int)$stmt->fetchColumn();
    $ticketCounter = $lastNum + 1;
    
    $insertStmt = $pdo->prepare("
        INSERT INTO tickets (
            ticket_number, title, description, status, priority, 
            category_id, project_id, assigned_to,
            created_at, updated_at, resolved_at, backlog
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, FALSE)
    ");
    
    for ($i = 0; $i < $ticketsToCreate; $i++) {
        $agent = $agents[array_rand($agents)];
        $project = $projects[array_rand($projects)];
        $category = $categories[array_rand($categories)];
        $status = $statuses[array_rand($statuses)];
        $priority = $priorities[array_rand($priorities)];
        $title = $subjects[array_rand($subjects)] . ' #' . ($i + 1);
        $description = $descriptions[array_rand($descriptions)];
        $ticketNumber = 'TKT-' . str_pad($ticketCounter++, 5, '0', STR_PAD_LEFT);
        
        // Fecha de creación aleatoria
        $createdAt = getRandomDate($dateRanges);
        $createdDateTime = new DateTime($createdAt);
        
        // Fecha de actualización (entre creación y hoy)
        $now = new DateTime();
        $daysDiff = $now->diff($createdDateTime)->days;
        $updateDaysAgo = mt_rand(0, max(0, $daysDiff));
        $updatedAt = (clone $now)->modify("-{$updateDaysAgo} days")->format('Y-m-d H:i:s');
        
        // Fecha de resolución (solo si está resuelto o cerrado)
        $resolvedAt = null;
        if (in_array($status, ['resolved', 'closed'])) {
            $resolveDaysAgo = mt_rand(0, max(0, $daysDiff));
            $resolvedAt = (clone $now)->modify("-{$resolveDaysAgo} days")->format('Y-m-d H:i:s');
        }
        
        try {
            $insertStmt->execute([
                $ticketNumber,
                $title,
                $description,
                $status,
                $priority,
                $category['id'],
                $project['id'],
                $agent['id'],
                $createdAt,
                $updatedAt,
                $resolvedAt
            ]);
            $created++;
            
            // Contadores
            $statusCounts[$status]++;
            $agentCounts[$agent['name']] = ($agentCounts[$agent['name']] ?? 0) + 1;
            $projectCounts[$project['name']] = ($projectCounts[$project['name']] ?? 0) + 1;
            
            // Contar por rango de fecha
            $daysAgo = $now->diff($createdDateTime)->days;
            if ($daysAgo <= 7) {
                $dateCounts['last_7']++;
            } elseif ($daysAgo <= 30) {
                $dateCounts['last_30']++;
            } elseif ($daysAgo <= 90) {
                $dateCounts['last_90']++;
            } else {
                $dateCounts['older']++;
            }
            
        } catch (Exception $e) {
            echo "   ⚠️ Error creando ticket: " . $e->getMessage() . "\n";
        }
    }
    
    echo "✅ Tickets creados: $created\n\n";
    
    echo "📊 Distribución por Estado:\n";
    foreach ($statusCounts as $status => $count) {
        $bar = str_repeat('█', $count / 2);
        echo "   $status: $count $bar\n";
    }
    
    echo "\n📊 Distribución por Fecha:\n";
    echo "   Últimos 7 días:   {$dateCounts['last_7']}\n";
    echo "   8-30 días:        {$dateCounts['last_30']}\n";
    echo "   31-90 días:       {$dateCounts['last_90']}\n";
    echo "   Más de 90 días:   {$dateCounts['older']}\n";
    
    echo "\n👥 Distribución por Agente:\n";
    arsort($agentCounts);
    foreach ($agentCounts as $name => $count) {
        $bar = str_repeat('█', $count / 2);
        echo "   $name: $count $bar\n";
    }
    
    echo "\n🏗️ Distribución por Proyecto:\n";
    arsort($projectCounts);
    foreach ($projectCounts as $name => $count) {
        $bar = str_repeat('█', $count / 2);
        echo "   $name: $count $bar\n";
    }
    
    // Verificación final
    echo "\n" . str_repeat('=', 50) . "\n";
    echo "🔍 VERIFICACIÓN FINAL\n";
    echo str_repeat('=', 50) . "\n";
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM tickets WHERE backlog = FALSE OR backlog IS NULL");
    $totalActive = $stmt->fetchColumn();
    echo "Total tickets activos (no backlog): $totalActive\n";
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM tickets WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $last7 = $stmt->fetchColumn();
    echo "Tickets últimos 7 días: $last7\n";
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM tickets WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $last30 = $stmt->fetchColumn();
    echo "Tickets últimos 30 días: $last30\n";
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM tickets WHERE QUARTER(created_at) = QUARTER(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())");
    $thisQuarter = $stmt->fetchColumn();
    echo "Tickets este trimestre: $thisQuarter\n";
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM tickets WHERE YEAR(created_at) = YEAR(CURDATE())");
    $thisYear = $stmt->fetchColumn();
    echo "Tickets este año: $thisYear\n";
    
    echo "\n✅ ¡Seed completado exitosamente!\n";
    echo "📌 Ahora puedes probar los filtros del dashboard:\n";
    echo "   - 'Todo el tiempo' debería mostrar ~$totalActive tickets\n";
    echo "   - 'Este mes' debería mostrar ~$last30 tickets\n";
    echo "   - 'Este trimestre' debería mostrar ~$thisQuarter tickets\n";
    echo "   - 'Este año' debería mostrar ~$thisYear tickets\n";
    echo "   - 'Fecha personalizada' permite seleccionar rangos específicos\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "</pre>";
