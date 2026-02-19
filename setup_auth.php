<?php
/**
 * Setup de Autenticación
 * Ejecutar una vez para configurar contraseñas y roles
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config/database.php';

// Obtener conexión
$pdo = getConnection();

echo "<h1>Setup de Autenticación</h1>\n";

try {
    // 1. Verificar si ya existe password_hash
    echo "<h2>1. Verificando columna password_hash...</h2>\n";
    
    $checkColumn = $pdo->query("SHOW COLUMNS FROM users LIKE 'password_hash'");
    if ($checkColumn->rowCount() === 0) {
        $pdo->exec("ALTER TABLE users ADD COLUMN password_hash VARCHAR(255) DEFAULT NULL AFTER email");
        echo "<p style='color:green'>✓ Columna password_hash creada</p>\n";
    } else {
        echo "<p style='color:blue'>ℹ Columna password_hash ya existe</p>\n";
    }
    
    // 2. Crear tabla de sesiones si no existe
    echo "<h2>2. Verificando tabla user_sessions...</h2>\n";
    
    $checkTable = $pdo->query("SHOW TABLES LIKE 'user_sessions'");
    if ($checkTable->rowCount() === 0) {
        $pdo->exec("
            CREATE TABLE user_sessions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                token VARCHAR(64) NOT NULL UNIQUE,
                ip_address VARCHAR(45) DEFAULT NULL,
                user_agent VARCHAR(255) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_sessions_token (token),
                INDEX idx_sessions_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "<p style='color:green'>✓ Tabla user_sessions creada</p>\n";
    } else {
        // Verificar si tiene columna expires_at (vieja versión) y eliminarla
        $checkExpires = $pdo->query("SHOW COLUMNS FROM user_sessions LIKE 'expires_at'");
        if ($checkExpires->rowCount() > 0) {
            $pdo->exec("ALTER TABLE user_sessions DROP COLUMN expires_at");
            echo "<p style='color:green'>✓ Columna expires_at eliminada (sesiones sin expiración)</p>\n";
        }
        echo "<p style='color:blue'>ℹ Tabla user_sessions ya existe</p>\n";
    }
    
    // 2.5. Modificar columna role para que sea VARCHAR en lugar de ENUM
    echo "<h2>2.5. Verificando tipo de columna role...</h2>\n";
    
    $columnInfo = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
    if ($columnInfo && strpos($columnInfo['Type'], 'enum') !== false) {
        $pdo->exec("ALTER TABLE users MODIFY COLUMN role VARCHAR(50) DEFAULT 'agent'");
        echo "<p style='color:green'>✓ Columna role convertida a VARCHAR</p>\n";
    } else {
        echo "<p style='color:blue'>ℹ Columna role ya es VARCHAR</p>\n";
    }
    
    // 3. Crear usuario SuperAdmin si no existe
    echo "<h2>3. Verificando usuario SuperAdmin...</h2>\n";
    
    $checkSuperAdmin = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $checkSuperAdmin->execute(['superadmin@ticketing.local']);
    
    if ($checkSuperAdmin->rowCount() === 0) {
        $stmt = $pdo->prepare("INSERT INTO users (name, email, role, is_active, created_at) VALUES (?, ?, ?, 1, NOW())");
        $stmt->execute(['SuperAdmin', 'superadmin@ticketing.local', 'super_admin']);
        echo "<p style='color:green'>✓ Usuario SuperAdmin creado</p>\n";
    } else {
        // Actualizar rol por si acaso
        $pdo->prepare("UPDATE users SET role = 'super_admin' WHERE email = ?")->execute(['superadmin@ticketing.local']);
        echo "<p style='color:blue'>ℹ Usuario SuperAdmin ya existe</p>\n";
    }
    
    // 4. Establecer contraseña por defecto: Wixyn2026!
    echo "<h2>4. Estableciendo contraseñas...</h2>\n";
    
    $defaultPassword = 'Wixyn2026!';
    $hash = password_hash($defaultPassword, PASSWORD_DEFAULT);
    
    $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE password_hash IS NULL OR password_hash = ''");
    $stmt->execute([$hash]);
    $affected = $stmt->rowCount();
    
    echo "<p style='color:green'>✓ Contraseña establecida para {$affected} usuarios</p>\n";
    echo "<p><strong>Contraseña por defecto:</strong> <code>{$defaultPassword}</code></p>\n";
    
    // 5. Actualizar roles
    echo "<h2>5. Actualizando roles...</h2>\n";
    
    // Admins - emails correctos de la VPS
    $admins = ['direccion@abelross.com', 'alicia@wixyn.com', 'angel.aparicio92@gmail.com'];
    $stmt = $pdo->prepare("UPDATE users SET role = 'admin' WHERE email = ?");
    foreach ($admins as $email) {
        $stmt->execute([$email]);
    }
    echo "<p style='color:green'>✓ Roles de admin actualizados para: " . implode(', ', $admins) . "</p>\n";
    
    // Agentes - emails correctos de la VPS
    $agents = [
        'oscar.calamita@wixyn.com',
        'victoria.aparicio@conmenospersonal.io',
        'faguerre@abelross.com',
        'andrea@wixyn.com',
        'gabriela.carvajal@wixyn.com'
    ];
    $stmt = $pdo->prepare("UPDATE users SET role = 'agent' WHERE email = ?");
    foreach ($agents as $email) {
        $stmt->execute([$email]);
    }
    echo "<p style='color:green'>✓ Roles de agent actualizados para: " . implode(', ', $agents) . "</p>\n";
    
    // 6. Mostrar resumen de usuarios
    echo "<h2>6. Usuarios configurados:</h2>\n";
    echo "<table border='1' cellpadding='8' style='border-collapse: collapse;'>\n";
    echo "<tr><th>ID</th><th>Nombre</th><th>Email</th><th>Rol</th><th>Activo</th><th>Password</th></tr>\n";
    
    $users = $pdo->query("
        SELECT id, name, email, role, is_active, 
               CASE WHEN password_hash IS NOT NULL AND password_hash != '' THEN '✓' ELSE '✗' END as has_pwd
        FROM users 
        WHERE role IN ('super_admin', 'admin', 'agent')
        ORDER BY FIELD(role, 'super_admin', 'admin', 'agent'), name
    ");
    
    while ($user = $users->fetch(PDO::FETCH_ASSOC)) {
        $roleColor = $user['role'] === 'super_admin' ? '#8b5cf6' : ($user['role'] === 'admin' ? '#3b82f6' : '#22c55e');
        echo "<tr>";
        echo "<td>{$user['id']}</td>";
        echo "<td>{$user['name']}</td>";
        echo "<td>{$user['email']}</td>";
        echo "<td style='color:{$roleColor}; font-weight:bold;'>{$user['role']}</td>";
        echo "<td>" . ($user['is_active'] ? '✓' : '✗') . "</td>";
        echo "<td style='color:" . ($user['has_pwd'] === '✓' ? 'green' : 'red') . "'>{$user['has_pwd']}</td>";
        echo "</tr>\n";
    }
    echo "</table>\n";
    
    echo "<h2 style='color:green; margin-top:30px;'>✅ Setup completado exitosamente</h2>\n";
    echo "<p><a href='login.html'>Ir a Login →</a></p>\n";
    
} catch (PDOException $e) {
    echo "<p style='color:red'>❌ Error: " . $e->getMessage() . "</p>\n";
}
