<?php
/**
 * Configuración de Base de Datos - PRODUCCIÓN (Hostinger)
 * Sistema de Ticketing - GHL Embeddable
 * 
 * INSTRUCCIONES:
 * 1. Renombra este archivo a: database.php
 * 2. Reemplaza los valores con los de tu panel de Hostinger
 */

// ⚠️ REEMPLAZA ESTOS VALORES CON LOS DE TU HOSTINGER:
define('DB_HOST', 'localhost');  // Generalmente es localhost en Hostinger
define('DB_NAME', 'u123456789_ticketing');  // Ejemplo: u123456789_nombrebd
define('DB_USER', 'u123456789_usuario');    // Ejemplo: u123456789_usuario
define('DB_PASS', 'TuPasswordSeguro123!');  // La contraseña que creaste

// Conexión PDO
function getConnection() {
    try {
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]
        );
        return $pdo;
    } catch (PDOException $e) {
        http_response_code(500);
        die(json_encode(['error' => 'Error de conexión: ' . $e->getMessage()]));
    }
}

// Headers CORS para embeber en GHL
function setCorsHeaders() {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    header('Content-Type: application/json; charset=utf-8');
    
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }
}
