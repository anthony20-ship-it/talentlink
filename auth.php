<?php
error_reporting(0);
ini_set('display_errors', 0);
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$body   = getBody();

if ($method === 'POST' && $action === 'login') {
    $email = trim($body['email'] ?? '');
    $pwd   = $body['password'] ?? '';
    if (!$email || !$pwd) { jsonError('Email et mot de passe requis'); }
    try {
        $db   = getDB();
        $stmt = $db->prepare('SELECT * FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($pwd, $user['password'])) { jsonError('Email ou mot de passe incorrect', 401); }
        $token = createToken(['id' => $user['id'], 'email' => $user['email'], 'role' => $user['role']]);
        jsonOk(['token' => $token, 'user' => [
            'id' => $user['id'], 'firstName' => $user['first_name'], 'lastName' => $user['last_name'],
            'email' => $user['email'], 'role' => $user['role'], 'city' => $user['city'],
            'skills' => $user['skills'], 'diploma' => $user['diploma'], 'exp' => $user['exp'],
            'company' => $user['company'], 'sector' => $user['sector'], 'bio' => $user['bio'],
            'status' => $user['status'] ?? 'disponible', 'cvData' => $user['cv_data'],
            'cvName' => $user['cv_name'], 'avatarData' => $user['avatar_data'], 'coverData' => $user['cover_data'],
        ]]);
    } catch (Exception $e) { jsonError('Erreur: ' . $e->getMessage(), 500); }
}

if ($method === 'POST' && $action === 'register') {
    $email = trim($body['email'] ?? '');
    $pwd   = $body['password'] ?? '';
    $fName = trim($body['firstName'] ?? '');
    $lName = trim($body['lastName'] ?? '');
    $role  = $body['role'] ?? 'candidat';
    $city  = $body['city'] ?? '';
    if (!$email || !$pwd || !$fName || !$lName) { jsonError('Tous les champs sont requis'); }
    if (strlen($pwd) < 6) { jsonError('Mot de passe trop court (6 min)'); }
    if (!in_array($role, ['candidat','recruteur','particulier'])) { jsonError('Role invalide'); }
    try {
        $db = getDB();
        $chk = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $chk->execute([$email]);
        if ($chk->fetch()) { jsonError('Email deja utilise'); }
        $hash = password_hash($pwd, PASSWORD_BCRYPT);
        $db->prepare('INSERT INTO users (first_name,last_name,email,password,role,city,skills,diploma,exp,company,sector,service_type,bio,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
           ->execute([$fName,$lName,$email,$hash,$role,$city,$body['skills']??'',$body['diploma']??'',intval($body['exp']??0),$body['company']??'',$body['sector']??'',$body['serviceType']??'',$body['bio']??'','disponible']);
        $newId = $db->lastInsertId();
        $token = createToken(['id' => $newId, 'email' => $email, 'role' => $role]);
        jsonOk(['token' => $token, 'user' => [
            'id' => $newId, 'firstName' => $fName, 'lastName' => $lName,
            'email' => $email, 'role' => $role, 'city' => $city,
            'skills' => $body['skills']??'', 'diploma' => $body['diploma']??'',
            'company' => $body['company']??'', 'sector' => $body['sector']??'', 'status' => 'disponible',
        ]]);
    } catch (Exception $e) { jsonError('Erreur: ' . $e->getMessage(), 500); }
}

if ($method === 'PUT' && $action === 'profile') {
    try {
        $auth = requireAuth();
        $db   = getDB();
        $map  = ['firstName'=>'first_name','lastName'=>'last_name','city'=>'city','bio'=>'bio','skills'=>'skills','diploma'=>'diploma','exp'=>'exp','company'=>'company','sector'=>'sector','status'=>'status','cvName'=>'cv_name'];
        $sets = []; $vals = [];
        foreach ($map as $k => $col) { if (isset($body[$k])) { $sets[] = "$col = ?"; $vals[] = $body[$k]; } }
        if (array_key_exists('avatarData',$body)) { $sets[] = 'avatar_data = ?'; $vals[] = $body['avatarData']; }
        if (array_key_exists('coverData',$body))  { $sets[] = 'cover_data = ?';  $vals[] = $body['coverData']; }
        if (array_key_exists('cvData',$body))     { $sets[] = 'cv_data = ?';     $vals[] = $body['cvData']; }
        if (!empty($body['password']) && strlen($body['password'])>=6) { $sets[] = 'password = ?'; $vals[] = password_hash($body['password'],PASSWORD_BCRYPT); }
        if (!empty($sets)) { $vals[] = $auth['id']; $db->prepare('UPDATE users SET '.implode(', ',$sets).' WHERE id = ?')->execute($vals); }
        jsonOk(['success' => true]);
    } catch (Exception $e) { jsonError('Erreur: '.$e->getMessage(), 500); }
}

if ($method === 'DELETE' && $action === 'delete') {
    try {
        $auth = requireAuth();
        getDB()->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$auth['id']]);
        jsonOk(['success' => true]);
    } catch (Exception $e) { jsonError('Erreur: '.$e->getMessage(), 500); }
}

jsonError('Action non reconnue');
