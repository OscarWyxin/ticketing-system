<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config/database.php';

$pdo = getDBConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "<h2>Testing by_agent query</h2>";
try {
    $stmt = $pdo->query("
        SELECT 
            u.name as agent_name,
            u.id as agent_id,
            SUM(CASE WHEN t.status = 'open' THEN 1 ELSE 0 END) as open,
            SUM(CASE WHEN t.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
            SUM(CASE WHEN t.status = 'waiting' THEN 1 ELSE 0 END) as waiting,
            SUM(CASE WHEN t.status IN ('resolved', 'closed') THEN 1 ELSE 0 END) as resolved,
            COUNT(t.id) as total
        FROM users u
        LEFT JOIN tickets t ON u.id = t.assigned_to 
            AND (t.backlog = FALSE OR t.backlog IS NULL)
        WHERE u.role IN ('admin', 'agent')
        GROUP BY u.id, u.name
        HAVING total > 0
        ORDER BY total DESC
    ");
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<pre>";
    print_r($result);
    echo "</pre>";
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
}

echo "<h2>Testing by_project query</h2>";
try {
    $stmt = $pdo->query("
        SELECT 
            p.name as project_name,
            p.id as project_id,
            SUM(CASE WHEN t.status = 'open' THEN 1 ELSE 0 END) as open,
            SUM(CASE WHEN t.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
            SUM(CASE WHEN t.status = 'waiting' THEN 1 ELSE 0 END) as waiting,
            SUM(CASE WHEN t.status IN ('resolved', 'closed') THEN 1 ELSE 0 END) as resolved,
            COUNT(t.id) as total
        FROM projects p
        LEFT JOIN tickets t ON p.id = t.project_id 
            AND (t.backlog = FALSE OR t.backlog IS NULL)
        GROUP BY p.id, p.name
        HAVING total > 0
        ORDER BY total DESC
    ");
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<pre>";
    print_r($result);
    echo "</pre>";
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
}
