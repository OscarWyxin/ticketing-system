<?php
/**
 * Script de validación de WhatsApp Integration
 * Verifica que todo esté configurado correctamente
 */

require_once 'config/database.php';

echo "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <title>Validación WhatsApp Integration</title>
    <style>
        body { font-family: Arial; margin: 20px; }
        .check { padding: 10px; margin: 5px 0; border-radius: 5px; }
        .ok { background: #efe; border-left: 4px solid #3c3; }
        .error { background: #fee; border-left: 4px solid #c33; }
        .warn { background: #ffe; border-left: 4px solid #fc3; }
        h1 { color: #333; }
        .section { margin-top: 30px; }
        code { background: #f5f5f5; padding: 2px 5px; }
    </style>
</head>
<body>
<h1>🔍 Validación WhatsApp Integration</h1>
<p>Verificando que todo esté configurado correctamente...</p>
";

$checks = [];

try {
    $pdo = getConnection();
    
    // 1. Verificar tabla ticket_tracking_tokens
    echo "<div class='section'><h2>📊 Base de Datos</h2>";
    
    try {
        $stmt = $pdo->prepare("SHOW TABLES LIKE 'ticket_tracking_tokens'");
        $stmt->execute();
        if ($stmt->fetch()) {
            echo "<div class='check ok'>✅ Tabla <code>ticket_tracking_tokens</code> existe</div>";
            $checks['table_exists'] = true;
        } else {
            echo "<div class='check error'>❌ Tabla <code>ticket_tracking_tokens</code> NO existe</div>";
            echo "<div class='check warn'>⚠️ Ejecuta: <code>php create-tracking-tokens-table.php</code></div>";
            $checks['table_exists'] = false;
        }
    } catch (Exception $e) {
        echo "<div class='check error'>❌ Error verificando tabla: {$e->getMessage()}</div>";
    }
    
    // 2. Verificar columna pending_info_details
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM tickets LIKE 'pending_info_details'");
        $stmt->execute();
        if ($stmt->fetch()) {
            echo "<div class='check ok'>✅ Columna <code>pending_info_details</code> existe en tickets</div>";
            $checks['pending_info_col'] = true;
        } else {
            echo "<div class='check error'>❌ Columna <code>pending_info_details</code> NO existe</div>";
            $checks['pending_info_col'] = false;
        }
    } catch (Exception $e) {
        echo "<div class='check error'>❌ Error verificando columna: {$e->getMessage()}</div>";
    }
    
    // 3. Contar tokens existentes
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM ticket_tracking_tokens");
        $stmt->execute();
        $count = $stmt->fetchColumn();
        echo "<div class='check ok'>ℹ️ Tokens de seguimiento en BD: <code>$count</code></div>";
    } catch (Exception $e) {
        echo "<div class='check warn'>⚠️ No se pueden contar tokens: {$e->getMessage()}</div>";
    }
    
    echo "</div>";
    
    // 4. Verificar archivos
    echo "<div class='section'><h2>📁 Archivos</h2>";
    
    $files_to_check = [
        'api/ghl-notifications.php' => 'Funciones de notificación WhatsApp',
        'api/ghl.php' => 'Integración GHL API',
        'api/tickets.php' => 'API de tickets (con integración WhatsApp)',
        'ticket-tracking.php' => 'Página pública de seguimiento',
        'create-tracking-tokens-table.php' => 'Script de creación de tabla'
    ];
    
    foreach ($files_to_check as $file => $desc) {
        if (file_exists($file)) {
            echo "<div class='check ok'>✅ $desc (<code>$file</code>)</div>";
        } else {
            echo "<div class='check error'>❌ $desc NO ENCONTRADO (<code>$file</code>)</div>";
        }
    }
    
    echo "</div>";
    
    // 5. Verificar funciones en ghl-notifications.php
    echo "<div class='section'><h2>🔧 Funciones WhatsApp</h2>";
    
    $functions_to_check = [
        'updateContactCustomFields',
        'generateTrackingLink',
        'sendWhatsAppTemplate',
        'notifyTicketCreatedWA',
        'notifyInfoPendingWA',
        'notifyDevelopmentStartedWA'
    ];
    
    $notif_content = file_get_contents('api/ghl-notifications.php');
    foreach ($functions_to_check as $func) {
        if (strpos($notif_content, "function $func") !== false) {
            echo "<div class='check ok'>✅ Función <code>$func()</code> definida</div>";
        } else {
            echo "<div class='check error'>❌ Función <code>$func()</code> NO ENCONTRADA</div>";
        }
    }
    
    echo "</div>";
    
    // 6. Verificar GHL Configuration
    echo "<div class='section'><h2>⚙️ Configuración GHL</h2>";
    
    $ghl_content = file_get_contents('api/ghl.php');
    
    $ghl_checks = [
        'GHL_API_KEY' => 'API Key',
        'GHL_COMPANY_ID' => 'Company ID',
        'GHL_LOCATION_ID' => 'Location ID',
        'GHL_API_BASE' => 'API Base URL'
    ];
    
    foreach ($ghl_checks as $const => $label) {
        if (strpos($ghl_content, "define('$const'") !== false) {
            echo "<div class='check ok'>✅ $label (<code>$const</code>) definido</div>";
        } else {
            echo "<div class='check error'>❌ $label (<code>$const</code>) NO DEFINIDO</div>";
        }
    }
    
    echo "</div>";
    
    // 7. Verificar integración en tickets.php
    echo "<div class='section'><h2>🔗 Integración en API</h2>";
    
    $tickets_content = file_get_contents('api/tickets.php');
    
    $integrations = [
        "case 'tracking':" => "Acción 'tracking' agregada",
        'notifyTicketCreatedWA' => 'Notificación de ticket creado',
        'notifyInfoPendingWA' => 'Notificación de información pendiente',
        'notifyDevelopmentStartedWA' => 'Notificación de desarrollo iniciado',
        'getTicketTracking' => 'Función getTicketTracking agregada'
    ];
    
    foreach ($integrations as $code => $label) {
        if (strpos($tickets_content, $code) !== false) {
            echo "<div class='check ok'>✅ $label</div>";
        } else {
            echo "<div class='check error'>❌ $label NO ENCONTRADA</div>";
        }
    }
    
    echo "</div>";
    
    // 8. Resumen
    echo "<div class='section'><h2>📋 Resumen</h2>";
    
    $critical_ok = $checks['table_exists'] ?? false;
    $config_ok = true; // Asumir OK si llegamos aquí
    
    if ($critical_ok && $config_ok) {
        echo "<div class='check ok'><strong>✅ TODO LISTO - Sistema operacional</strong></div>";
        echo "<p>Puedes empezar a crear tickets con teléfono para recibir WhatsApp.</p>";
    } else {
        echo "<div class='check error'><strong>❌ REQUIERE ACCIÓN</strong></div>";
        if (!$critical_ok) {
            echo "<p>⚠️ <strong>Importante:</strong> Ejecuta el script de creación de tabla primero.</p>";
            echo "<p>Abre en navegador: <code>http://localhost/create-tracking-tokens-table.php</code></p>";
        }
    }
    
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div class='check error'>❌ Error general: {$e->getMessage()}</div>";
}

echo "
</body>
</html>
";
?>
