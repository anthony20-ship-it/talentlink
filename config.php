<?php
error_reporting(0);
ini_set('display_errors', 0);
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'talentlink_db');
define('DB_PORT', 3306);
define('JWT_SECRET', 'talentlink_secret_2024');
define('JWT_EXPIRY', 8 * 3600);

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO("mysql:host=".DB_HOST.";port=".DB_PORT.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            die(json_encode(['error' => 'DB error: ' . $e->getMessage()]));
        }
    }
    return $pdo;
}

function jsonOk($data) { echo json_encode($data, JSON_UNESCAPED_UNICODE); exit(); }
function jsonError($msg, $code=400) { http_response_code($code); echo json_encode(['error'=>$msg]); exit(); }
function getBody() { return json_decode(file_get_contents('php://input'), true) ?? []; }

function b64url($d) { return rtrim(strtr(base64_encode($d),'+/','-_'),'='); }
function createToken($payload) {
    $h = b64url(json_encode(['alg'=>'HS256','typ'=>'JWT']));
    $payload['exp'] = time() + JWT_EXPIRY;
    $b = b64url(json_encode($payload));
    $s = b64url(hash_hmac('sha256',"$h.$b",JWT_SECRET,true));
    return "$h.$b.$s";
}
function verifyToken($token) {
    $p = explode('.',$token);
    if (count($p)!==3) return null;
    [$h,$b,$s] = $p;
    if (!hash_equals(b64url(hash_hmac('sha256',"$h.$b",JWT_SECRET,true)),$s)) return null;
    $pl = json_decode(base64_decode(strtr($b,'-_','+/')),true);
    if (!$pl || $pl['exp'] < time()) return null;
    return $pl;
}
function requireAuth() {
    $t = str_replace('Bearer ','', $_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (!$t) jsonError('Token manquant', 401);
    $u = verifyToken($t);
    if (!$u) jsonError('Token invalide', 401);
    return $u;
}

if (basename($_SERVER['PHP_SELF']) === 'config.php') {
    try {
        $tables = getDB()->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        jsonOk(['status'=>'OK','tables'=>$tables]);
    } catch(Exception $e) { jsonError($e->getMessage()); }
}
