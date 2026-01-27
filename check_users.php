<?php
require 'config/database.php';

$pdo = getConnection();
$stmt = $pdo->query("SELECT id, name, email, role FROM users WHERE role IN ('admin', 'agent', 'agency_admin', 'agency_agent') AND is_active = 1 ORDER BY name");
$users = $stmt->fetchAll();

echo "=== USUARIOS DEL SISTEMA ===\n\n";
foreach ($users as $user) {
    echo "ID: " . $user['id'] . " | Nombre: " . $user['name'] . " | Email: " . $user['email'] . " | Rol: " . $user['role'] . "\n";
}
