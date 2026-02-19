<?php
/**
 * Verificar usuarios en base de datos local
 */
require_once __DIR__ . '/config/database.php';

$pdo = getConnection();

echo "<h1>Usuarios en Base de Datos Local</h1>";

$stmt = $pdo->query("SELECT id, name, email, role, is_active, password_hash IS NOT NULL AND password_hash != '' as has_password FROM users ORDER BY id LIMIT 30");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<table border='1' cellpadding='8' style='border-collapse: collapse;'>";
echo "<tr><th>ID</th><th>Nombre</th><th>Email</th><th>Rol</th><th>Activo</th><th>Password</th></tr>";

foreach ($users as $user) {
    $pwdColor = $user['has_password'] ? 'green' : 'red';
    echo "<tr>";
    echo "<td>{$user['id']}</td>";
    echo "<td>{$user['name']}</td>";
    echo "<td>{$user['email']}</td>";
    echo "<td>{$user['role']}</td>";
    echo "<td>" . ($user['is_active'] ? '✓' : '✗') . "</td>";
    echo "<td style='color:{$pwdColor}'>" . ($user['has_password'] ? '✓' : '✗') . "</td>";
    echo "</tr>";
}
echo "</table>";

echo "<br><a href='setup_auth.php'>Ejecutar Setup Auth →</a>";
