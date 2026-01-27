<?php
/**
 * API de Integración con GoHighLevel
 * Librería de funciones para sincronización y API calls
 */

// Configuración GHL - usa variables de entorno
if (!defined("GHL_API_BASE")) {
    require_once __DIR__ . "/../config/database.php";
    setCorsHeaders();
    define("GHL_API_BASE", getenv('GHL_API_BASE') ?: "https://services.leadconnectorhq.com");
    define("GHL_API_KEY", getenv('GHL_API_KEY') ?: "");
    define("GHL_API_VERSION", getenv('GHL_API_VERSION') ?: "2021-07-28");
    define("GHL_COMPANY_ID", getenv('GHL_COMPANY_ID') ?: "");
    define("GHL_LOCATION_ID", getenv('GHL_LOCATION_ID') ?: "");
}

/**
 * Llamada a la API de GHL
 */
function ghlApiCall($endpoint, $method = "GET", $data = null, $locationId = null) {
    $url = GHL_API_BASE . $endpoint;
    
    $headers = [
        "Authorization: Bearer " . GHL_API_KEY,
        "Version: " . GHL_API_VERSION,
        "Content-Type: application/json",
        "Accept: application/json"
    ];
    
    if ($locationId) {
        $headers[] = "Location: " . $locationId;
    }
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    if ($method === "POST") {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
    } elseif ($method === "PUT" || $method === "PATCH") {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ["error" => $error, "httpCode" => $httpCode];
    }
    
    $decoded = json_decode($response, true);
    
    @file_put_contents(__DIR__ . "/../logs/ghl_api.log", 
        date("Y-m-d H:i:s") . " - $method $endpoint - HTTP $httpCode\n" . 
        "Response: " . substr($response, 0, 1000) . "\n\n", 
        FILE_APPEND);
    
    return $decoded ?: ["error" => "Invalid response", "raw" => $response, "httpCode" => $httpCode];
}

function testConnection() {
    $result = ghlApiCall("/locations?limit=1", "GET", null, GHL_LOCATION_ID);
    echo json_encode($result);
}

function getLocations($pdo) {
    $stmt = $pdo->query("SELECT * FROM accounts WHERE is_active = 1 ORDER BY name ASC");
    echo json_encode(["success" => true, "data" => $stmt->fetchAll()]);
}

function getGHLUsers($pdo) {
    $stmt = $pdo->query("SELECT u.* FROM users u WHERE u.is_active = 1 ORDER BY u.role, u.name ASC");
    echo json_encode(["success" => true, "data" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

function syncLocations($pdo) {
    $result = ghlApiCall("/locations?limit=20", "GET", null, GHL_LOCATION_ID);
    if (isset($result["error"])) {
        echo json_encode(["success" => false, "error" => $result["error"]]);
        return;
    }
    
    $synced = 0;
    foreach ($result["locations"] ?? [] as $location) {
        $stmt = $pdo->prepare("INSERT INTO accounts (id, name, account_type, is_active) VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE name = ?, is_active = 1");
        $stmt->execute([$location["id"], $location["name"], "location", $location["name"]]);
        $synced++;
    }
    echo json_encode(["success" => true, "synced" => $synced]);
}

function syncUsers($pdo, $locationId = null) {
    $locationId = $locationId ?: GHL_LOCATION_ID;
    $result = ghlApiCall("/users/?locationId=" . urlencode($locationId), "GET", null, $locationId);
    if (isset($result["error"])) {
        echo json_encode(["success" => false, "error" => $result["error"]]);
        return;
    }
    
    $synced = 0;
    foreach ($result["users"] ?? [] as $user) {
        // Check if user already exists by ghl_user_id
        $checkStmt = $pdo->prepare("SELECT id FROM users WHERE ghl_user_id = ?");
        $checkStmt->execute([$user["id"] ?? null]);
        $existingUser = $checkStmt->fetch();
        
        if ($existingUser) {
            // Update existing user
            $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, phone = ?, ghl_location_id = ?, is_active = 1 WHERE ghl_user_id = ?");
            $stmt->execute([
                trim(($user["firstName"] ?? "") . " " . ($user["lastName"] ?? "")),
                $user["email"] ?? "",
                $user["phone"] ?? "",
                $locationId,
                $user["id"]
            ]);
        } else {
            // Insert new user
            $stmt = $pdo->prepare("INSERT INTO users (name, email, phone, role, ghl_user_id, ghl_location_id, is_active) VALUES (?, ?, ?, 'agent', ?, ?, 1)");
            $stmt->execute([
                trim(($user["firstName"] ?? "") . " " . ($user["lastName"] ?? "")),
                $user["email"] ?? "",
                $user["phone"] ?? "",
                $user["id"],
                $locationId
            ]);
        }
        $synced++;
    }
    echo json_encode(["success" => true, "synced" => $synced]);
}

function debugToken() {
    echo json_encode(["message" => "Token debug", "api_base" => GHL_API_BASE, "api_key" => "pit-****"]);
}

// Solo ejecutar router si se accede DIRECTAMENTE a este archivo (no incluido)
$isDirectAccess = basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'ghl.php';
if ($isDirectAccess && php_sapi_name() !== "cli" && isset($_GET["action"])) {
    $action = $_GET["action"];
    $pdo = getConnection(); // Obtener conexión a la base de datos
    
    switch ($action) {
        case "sync-locations":
            syncLocations($pdo);
            break;
        case "sync-users":
            syncUsers($pdo, $_GET["location_id"] ?? null);
            break;
        case "get-locations":
            getLocations($pdo);
            break;
        case "get-ghl-users":
            getGHLUsers($pdo);
            break;
        case "test-connection":
            testConnection();
            break;
        case "debug-token":
            debugToken();
            break;
        default:
            echo json_encode(["error" => "Acción no válida"]);
    }
}