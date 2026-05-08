<?php
/* ============================================================
   auth.php — Authentification et gestion du profil
   Route : /talentlink/api/auth.php
   
   POST ?action=login    → Connexion
   POST ?action=register → Inscription
   PUT  ?action=profile  → Mise à jour du profil
   DELETE ?action=delete → Suppression du compte
============================================================ */

require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$body = getBody();
$db = getDB();

/* ============================================================
   LOGIN
============================================================ */
if ($method === 'POST' && $action === 'login') {
    try {
        $email = trim($body['email'] ?? '');
        $pwd = $body['password'] ?? '';

        if (!$email || !$pwd) {
            jsonError('Email and password required');
        }

        // Validation email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            log_auth_attempt($email, false);
            jsonError('Invalid email or password', 401);
        }

        $stmt = $db->prepare('SELECT * FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($pwd, $user['password'])) {
            log_auth_attempt($email, false);
            jsonError('Invalid email or password', 401);
        }

        log_auth_attempt($email, true);
        $token = createToken([
            'id' => $user['id'],
            'email' => $user['email'],
            'role' => $user['role']
        ]);

        jsonOk([
            'token' => $token,
            'user' => [
                'id' => $user['id'],
                'firstName' => $user['first_name'],
                'lastName' => $user['last_name'],
                'email' => $user['email'],
                'role' => $user['role'],
                'city' => $user['city'],
                'skills' => $user['skills'],
                'diploma' => $user['diploma'],
                'exp' => $user['exp'],
                'company' => $user['company'],
                'sector' => $user['sector'],
                'bio' => $user['bio'],
                'status' => $user['status'] ?? 'disponible',
                'cvData' => $user['cv_data'],
                'cvName' => $user['cv_name'],
                'avatarData' => $user['avatar_data'],
                'coverData' => $user['cover_data'],
            ]
        ]);
    } catch (Exception $e) {
        log_error('Login error: ' . $e->getMessage());
        jsonError('Server error', 500);
    }
}

/* ============================================================
   REGISTER
============================================================ */
if ($method === 'POST' && $action === 'register') {
    try {
        $email = trim($body['email'] ?? '');
        $pwd = $body['password'] ?? '';
        $fName = trim($body['firstName'] ?? '');
        $lName = trim($body['lastName'] ?? '');
        $role = $body['role'] ?? 'candidat';
        $city = trim($body['city'] ?? '');

        // Validation des champs requis
        if (!$email || !$pwd || !$fName || !$lName) {
            jsonError('All fields are required');
        }

        // Validation email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            jsonError('Invalid email format');
        }

        // Validation mot de passe (minimum 8 caractères)
        if (strlen($pwd) < 8) {
            jsonError('Password must be at least 8 characters long');
        }

        // Validation rôle
        if (!in_array($role, ['candidat', 'recruteur', 'particulier'])) {
            jsonError('Invalid role');
        }

        // Vérifier doublon email
        $check = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $check->execute([$email]);
        if ($check->fetch()) {
            jsonError('Email already in use');
        }

        // Hash du mot de passe (bcrypt avec cost=12)
        $hash = password_hash($pwd, PASSWORD_BCRYPT, ['cost' => 12]);

        // Insertion
        $stmt = $db->prepare('
            INSERT INTO users (
                first_name, last_name, email, password, role, city,
                skills, diploma, exp, company, sector, service_type, bio, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $fName,
            $lName,
            $email,
            $hash,
            $role,
            $city,
            $body['skills'] ?? '',
            $body['diploma'] ?? '',
            intval($body['exp'] ?? 0),
            $body['company'] ?? '',
            $body['sector'] ?? '',
            $body['serviceType'] ?? '',
            $body['bio'] ?? '',
            'disponible'
        ]);

        $newId = $db->lastInsertId();
        log_auth_attempt($email, true);

        $token = createToken([
            'id' => $newId,
            'email' => $email,
            'role' => $role
        ]);

        jsonOk([
            'token' => $token,
            'user' => [
                'id' => $newId,
                'firstName' => $fName,
                'lastName' => $lName,
                'email' => $email,
                'role' => $role,
                'city' => $city,
                'skills' => $body['skills'] ?? '',
                'diploma' => $body['diploma'] ?? '',
                'company' => $body['company'] ?? '',
                'sector' => $body['sector'] ?? '',
                'status' => 'disponible',
            ]
        ]);
    } catch (Exception $e) {
        log_error('Register error: ' . $e->getMessage());
        jsonError('Server error', 500);
    }
}

/* ============================================================
   UPDATE PROFILE
============================================================ */
if ($method === 'PUT' && $action === 'profile') {
    try {
        $auth = requireAuth();

        $fieldMap = [
            'firstName' => 'first_name',
            'lastName' => 'last_name',
            'city' => 'city',
            'bio' => 'bio',
            'skills' => 'skills',
            'diploma' => 'diploma',
            'exp' => 'exp',
            'company' => 'company',
            'sector' => 'sector',
            'status' => 'status',
            'serviceType' => 'service_type',
            'cvName' => 'cv_name'
        ];

        $updates = [];
        $values = [];

        foreach ($fieldMap as $bodyKey => $dbCol) {
            if (isset($body[$bodyKey])) {
                $updates[] = "$dbCol = ?";
                $values[] = $body[$bodyKey];
            }
        }

        // Données binaires (base64)
        if (array_key_exists('avatarData', $body)) {
            $updates[] = 'avatar_data = ?';
            $values[] = $body['avatarData'];
        }
        if (array_key_exists('coverData', $body)) {
            $updates[] = 'cover_data = ?';
            $values[] = $body['coverData'];
        }
        if (array_key_exists('cvData', $body)) {
            $updates[] = 'cv_data = ?';
            $values[] = $body['cvData'];
        }

        // Mot de passe
        if (!empty($body['password']) && strlen($body['password']) >= 8) {
            $updates[] = 'password = ?';
            $values[] = password_hash($body['password'], PASSWORD_BCRYPT, ['cost' => 12]);
        }

        if (!empty($updates)) {
            $values[] = $auth['id'];
            $query = 'UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ?';
            $db->prepare($query)->execute($values);
        }

        jsonOk(['success' => true]);
    } catch (Exception $e) {
        log_error('Profile update error: ' . $e->getMessage());
        jsonError('Server error', 500);
    }
}

/* ============================================================
   DELETE ACCOUNT
============================================================ */
if ($method === 'DELETE' && $action === 'delete') {
    try {
        $auth = requireAuth();
        $db->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$auth['id']]);
        jsonOk(['success' => true]);
    } catch (Exception $e) {
        log_error('Account deletion error: ' . $e->getMessage());
        jsonError('Server error', 500);
    }
}

jsonError('Unknown action', 400);
