<?php
/* ============================================================
   config.php — Configuration centralisée et fonctions utilitaires
   Chargement des variables d'environnement + Sécurité
============================================================ */

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Headers de sécurité
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// CORS dynamique (à configurer en .env)
$cors_origin = getenv('CORS_ORIGIN');
if ($cors_origin) {
    header('Access-Control-Allow-Origin: ' . $cors_origin);
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ============================================================
// Chargement de l'environnement
// ============================================================

function loadEnv($path = '.env') {
    if (!file_exists($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        putenv("$key=$value");
    }
}

loadEnv(__DIR__ . '/.env');
loadEnv(__DIR__ . '/.env.local');

// ============================================================
// Configuration
// ============================================================

define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('DB_PORT') ?: 3306);
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'talentlink_db');
define('JWT_SECRET', getenv('JWT_SECRET') ?: null);
define('JWT_EXPIRY_HOURS', getenv('JWT_EXPIRY_HOURS') ?: 8);
define('JWT_EXPIRY', JWT_EXPIRY_HOURS * 3600);
define('APP_ENV', getenv('APP_ENV') ?: 'development');
define('APP_DEBUG', getenv('APP_DEBUG') === 'true');
define('LOG_PATH', getenv('LOG_PATH') ?: __DIR__ . '/logs/');

// ============================================================
// Validation des secrets
// ============================================================

if (!JWT_SECRET || strlen(JWT_SECRET) < 32) {
    error_log('[SECURITY] JWT_SECRET is missing or too short in .env');
    die(json_encode([
        'error' => 'Server configuration error',
        'details' => APP_DEBUG ? 'JWT_SECRET must be at least 32 characters' : null
    ]));
}

if (!is_dir(LOG_PATH)) {
    @mkdir(LOG_PATH, 0755, true);
}

// ============================================================
// Fonctions utilitaires
// ============================================================

function log_error($msg) {
    $file = LOG_PATH . 'errors_' . date('Y-m-d') . '.log';
    $time = date('Y-m-d H:i:s');
    $entry = "[$time] $msg\n";
    error_log($entry, 3, $file);
}

function log_auth_attempt($email, $success) {
    $file = LOG_PATH . 'auth_' . date('Y-m-d') . '.log';
    $time = date('Y-m-d H:i:s');
    $status = $success ? 'SUCCESS' : 'FAILED';
    $entry = "[$time] $status: $email\n";
    error_log($entry, 3, $file);
}

function jsonOk($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit();
}

function jsonError($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit();
}

function getBody() {
    return json_decode(file_get_contents('php://input'), true) ?? [];
}

// ============================================================
// Base de données
// ============================================================

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $e) {
            log_error('Database connection error: ' . $e->getMessage());
            http_response_code(500);
            die(json_encode([
                'error' => 'Database error',
                'details' => APP_DEBUG ? $e->getMessage() : null
            ]));
        }
    }
    return $pdo;
}

// ============================================================
// JWT (Token)
// ============================================================

function b64url($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function createToken($payload) {
    $header = b64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload['exp'] = time() + JWT_EXPIRY;
    $payload['iat'] = time();
    $body = b64url(json_encode($payload));
    $signature = b64url(hash_hmac('sha256', "$header.$body", JWT_SECRET, true));
    return "$header.$body.$signature";
}

function verifyToken($token) {
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }
    [$header, $body, $signature] = $parts;

    // Vérifier la signature
    $expected_sig = b64url(hash_hmac('sha256', "$header.$body", JWT_SECRET, true));
    if (!hash_equals($expected_sig, $signature)) {
        return null;
    }

    // Décoder et vérifier l'expiration
    $payload = json_decode(base64_decode(strtr($body, '-_', '+/')), true);
    if (!$payload || ($payload['exp'] ?? 0) < time()) {
        return null;
    }

    return $payload;
}

function requireAuth() {
    $auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = str_replace('Bearer ', '', $auth_header);

    if (!$token) {
        jsonError('Missing token', 401);
    }

    $payload = verifyToken($token);
    if (!$payload) {
        jsonError('Invalid or expired token', 401);
    }

    return $payload;
}

// ============================================================
// Health check
// ============================================================

if (basename($_SERVER['PHP_SELF']) === 'config.php') {
    try {
        $db = getDB();
        $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        jsonOk([
            'status' => 'OK',
            'tables' => $tables,
            'environment' => APP_ENV,
            'debug' => APP_DEBUG
        ]);
    } catch (Exception $e) {
        log_error('Health check error: ' . $e->getMessage());
        jsonError('Health check failed');
    }
}
