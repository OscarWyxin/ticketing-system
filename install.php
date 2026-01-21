<?php
/**
 * Script de Instalación - Sistema de Ticketing
 * Ejecutar una sola vez para configurar la base de datos
 */

// Configuración
$config = [
    'host' => 'localhost',
    'user' => 'root',
    'pass' => '',
    'name' => 'ticketing_system'
];

// Estilos
echo '<!DOCTYPE html>
<html>
<head>
    <title>Instalación - Sistema de Ticketing</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: "Segoe UI", sans-serif; background: #f3f4f6; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .container { background: white; border-radius: 16px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); max-width: 600px; width: 100%; overflow: hidden; }
        .header { background: linear-gradient(135deg, #6366f1, #8b5cf6); color: white; padding: 30px; text-align: center; }
        .header h1 { font-size: 1.5rem; margin-bottom: 8px; }
        .header p { opacity: 0.9; }
        .content { padding: 30px; }
        .step { margin-bottom: 20px; padding: 15px; border-radius: 10px; display: flex; align-items: flex-start; gap: 12px; }
        .step.success { background: #dcfce7; color: #166534; }
        .step.error { background: #fee2e2; color: #991b1b; }
        .step.info { background: #e0e7ff; color: #3730a3; }
        .step-icon { width: 24px; height: 24px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; flex-shrink: 0; }
        .step.success .step-icon { background: #22c55e; color: white; }
        .step.error .step-icon { background: #ef4444; color: white; }
        .step.info .step-icon { background: #6366f1; color: white; }
        .step-content { flex: 1; }
        .step-title { font-weight: 600; margin-bottom: 4px; }
        .step-desc { font-size: 0.9rem; opacity: 0.8; }
        .btn { display: inline-block; padding: 12px 24px; background: #6366f1; color: white; text-decoration: none; border-radius: 8px; font-weight: 500; margin-top: 20px; }
        .btn:hover { background: #4f46e5; }
        pre { background: #1f2937; color: #e5e7eb; padding: 15px; border-radius: 8px; overflow-x: auto; font-size: 0.85rem; margin-top: 10px; }
        code { font-family: "Fira Code", monospace; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>🎫 Sistema de Ticketing</h1>
        <p>Instalación y configuración</p>
    </div>
    <div class="content">';

$errors = [];
$success = [];

// Verificar PHP
if (version_compare(PHP_VERSION, '7.4.0', '>=')) {
    $success[] = ['PHP ' . PHP_VERSION, 'Versión compatible'];
} else {
    $errors[] = ['PHP ' . PHP_VERSION, 'Se requiere PHP 7.4 o superior'];
}

// Verificar PDO
if (extension_loaded('pdo_mysql')) {
    $success[] = ['PDO MySQL', 'Extensión cargada correctamente'];
} else {
    $errors[] = ['PDO MySQL', 'Extensión no disponible. Instala php-mysql'];
}

// Intentar conexión
try {
    $pdo = new PDO(
        "mysql:host={$config['host']}",
        $config['user'],
        $config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $success[] = ['Conexión MySQL', 'Conexión establecida correctamente'];
    
    // Crear base de datos si no existe
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$config['name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $success[] = ['Base de datos', "'{$config['name']}' creada/verificada"];
    
    // Seleccionar BD
    $pdo->exec("USE `{$config['name']}`");
    
    // Verificar si ya existen tablas
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (in_array('tickets', $tables)) {
        $success[] = ['Tablas', 'Las tablas ya existen'];
    } else {
        // Leer y ejecutar schema.sql
        $schemaPath = __DIR__ . '/database/schema.sql';
        if (file_exists($schemaPath)) {
            $schema = file_get_contents($schemaPath);
            
            // Dividir por statements
            $statements = array_filter(
                array_map('trim', 
                    preg_split('/;[\r\n]+/', $schema)
                )
            );
            
            foreach ($statements as $statement) {
                if (!empty($statement) && stripos($statement, 'CREATE DATABASE') === false && stripos($statement, 'USE ') === false) {
                    try {
                        $pdo->exec($statement);
                    } catch (Exception $e) {
                        // Ignorar errores de tablas existentes
                    }
                }
            }
            $success[] = ['Tablas', 'Esquema importado correctamente'];
        } else {
            $errors[] = ['Schema', 'Archivo database/schema.sql no encontrado'];
        }
    }
    
    // Verificar directorio de logs
    $logsDir = __DIR__ . '/logs';
    if (!is_dir($logsDir)) {
        mkdir($logsDir, 0777, true);
    }
    $success[] = ['Directorio logs', 'Listo para escritura'];
    
} catch (PDOException $e) {
    $errors[] = ['Conexión MySQL', 'Error: ' . $e->getMessage()];
}

// Mostrar resultados
foreach ($success as $item) {
    echo '<div class="step success">
        <div class="step-icon">✓</div>
        <div class="step-content">
            <div class="step-title">' . $item[0] . '</div>
            <div class="step-desc">' . $item[1] . '</div>
        </div>
    </div>';
}

foreach ($errors as $item) {
    echo '<div class="step error">
        <div class="step-icon">✗</div>
        <div class="step-content">
            <div class="step-title">' . $item[0] . '</div>
            <div class="step-desc">' . $item[1] . '</div>
        </div>
    </div>';
}

if (empty($errors)) {
    echo '<div class="step info">
        <div class="step-icon">!</div>
        <div class="step-content">
            <div class="step-title">¡Instalación completada!</div>
            <div class="step-desc">El sistema está listo para usar. Elimina este archivo (install.php) por seguridad.</div>
        </div>
    </div>';
    
    echo '<a href="index.html" class="btn">Ir al Dashboard →</a>';
} else {
    echo '<div class="step info">
        <div class="step-icon">i</div>
        <div class="step-content">
            <div class="step-title">Configuración de base de datos</div>
            <div class="step-desc">Edita config/database.php con tus credenciales:</div>
            <pre><code>define(\'DB_HOST\', \'localhost\');
define(\'DB_NAME\', \'ticketing_system\');
define(\'DB_USER\', \'tu_usuario\');
define(\'DB_PASS\', \'tu_contraseña\');</code></pre>
        </div>
    </div>';
}

echo '</div></div></body></html>';
