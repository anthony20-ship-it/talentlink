<?php
/* ============================================================
   offres.php — Gestion des offres d'emploi et missions
   Route : /talentlink/api/offres.php
   
   GET    ?type=offres|missions        → lister
   POST   ?type=offres|missions        → créer
   PUT    ?type=offres|missions&id=X   → modifier statut
   DELETE ?type=offres|missions&id=X   → supprimer
============================================================ */
require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];
$type   = $_GET['type'] ?? 'offres';   // 'offres' ou 'missions'
$id     = intval($_GET['id'] ?? 0);
$body   = getBody();

// Auth optionnelle pour GET public, obligatoire pour POST/PUT/DELETE
$db = getDB();
if ($method !== 'GET' || in_array($type, ['profile'])) {
    $auth = requireAuth();
} else {
    // GET public : essayer le token si présent
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $tok = str_replace('Bearer ', '', $authHeader);
    $auth = $tok ? (verifyToken($tok) ?? ['id' => 0, 'role' => 'guest']) : ['id' => 0, 'role' => 'guest'];
}

/* ── LISTER les offres ou missions ── */
if ($method === 'GET') {
    if ($type === 'offres') {
        // Récupérer les offres avec le nom de l'auteur
        $stmt = $db->prepare('
            SELECT o.*, u.first_name, u.last_name, u.company, u.cover_data
            FROM offres o
            LEFT JOIN users u ON o.author_id = u.id
            ORDER BY o.created_at DESC
        ');
        $stmt->execute();
        $rows = $stmt->fetchAll();
        // Formater les tags (stockés en JSON)
        foreach ($rows as &$r) {
            $r['tags'] = json_decode($r['tags'] ?? '[]', true) ?: [];
            $r['requiredSkills'] = json_decode($r['required_skills'] ?? '[]', true) ?: [];
        }
        jsonOk($rows);
    }

    if ($type === 'missions') {
        $stmt = $db->prepare('
            SELECT m.*, u.first_name, u.last_name, u.company, u.cover_data
            FROM missions m
            LEFT JOIN users u ON m.author_id = u.id
            ORDER BY m.created_at DESC
        ');
        $stmt->execute();
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['tags'] = json_decode($r['tags'] ?? '[]', true) ?: [];
        }
        jsonOk($rows);
    }

    // Stats pour le dashboard
    if ($type === 'stats') {
        $stats = [];
        $stats['total_candidats']  = $db->query("SELECT COUNT(*) FROM users WHERE role='candidat'")->fetchColumn();
        $stats['offres_actives']   = $db->query("SELECT COUNT(*) FROM offres WHERE statut='Actif'")->fetchColumn();
        $stats['total_entretiens'] = $db->query("SELECT COUNT(*) FROM creneaux WHERE statut='confirmé'")->fetchColumn();
        $stats['employes_actifs']  = $db->query("SELECT COUNT(*) FROM users WHERE role='recruteur' AND is_active=1")->fetchColumn();
        jsonOk($stats);
    }

    // Candidats (pour le fil recruteur)
    if ($type === 'candidats') {
        $stmt = $db->prepare("SELECT id, first_name, last_name, city, skills, diploma, exp, bio, avatar_data, cv_data, cv_name, status, role FROM users WHERE role='candidat' AND is_active=1");
        $stmt->execute();
        jsonOk($stmt->fetchAll());
    }
}

/* ── CRÉER une offre ── */
if ($method === 'POST' && $type === 'offres') {
    $titre = trim($body['titre'] ?? '');
    if (!$titre) jsonError('Le titre est obligatoire');

    $stmt = $db->prepare('
        INSERT INTO offres (titre, type_contrat, statut, city, description, tags, required_skills, salary, diploma_required, informal, author_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $titre,
        $body['type'] ?? 'CDI',
        'Actif',
        $body['city'] ?? '',
        $body['desc'] ?? '',
        json_encode($body['tags'] ?? []),
        json_encode($body['requiredSkills'] ?? $body['tags'] ?? []),
        $body['salary'] ?? '',
        isset($body['diplomaRequired']) && $body['diplomaRequired'] ? 1 : 0,
        isset($body['informal']) && $body['informal'] ? 1 : 0,
        $auth['id'],
    ]);
    $newId = $db->lastInsertId();
    $row   = $db->prepare('SELECT * FROM offres WHERE id = ?');
    $row->execute([$newId]);
    $offre = $row->fetch();
    $offre['tags'] = json_decode($offre['tags'], true) ?: [];
    jsonOk($offre);
}

/* ── CRÉER une mission ── */
if ($method === 'POST' && $type === 'missions') {
    $titre = trim($body['titre'] ?? '');
    if (!$titre) jsonError('Le titre est obligatoire');

    $stmt = $db->prepare('
        INSERT INTO missions (titre, description, pay, duration, city, tags, statut, author_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $titre,
        $body['desc'] ?? '',
        $body['pay'] ?? '',
        $body['duration'] ?? '',
        $body['city'] ?? '',
        json_encode($body['tags'] ?? []),
        'Actif',
        $auth['id'],
    ]);
    $newId = $db->lastInsertId();
    $row   = $db->prepare('SELECT * FROM missions WHERE id = ?');
    $row->execute([$newId]);
    $mission = $row->fetch();
    $mission['tags'] = json_decode($mission['tags'], true) ?: [];
    jsonOk($mission);
}

/* ── MODIFIER le statut d'une offre ── */
if ($method === 'PUT' && $id > 0) {
    $table  = $type === 'missions' ? 'missions' : 'offres';
    $statut = $body['statut'] ?? 'Actif';
    $db->prepare("UPDATE $table SET statut = ? WHERE id = ? AND author_id = ?")
       ->execute([$statut, $id, $auth['id']]);
    jsonOk(['success' => true]);
}

/* ── SUPPRIMER une offre ou mission ── */
if ($method === 'DELETE' && $id > 0) {
    $table = $type === 'missions' ? 'missions' : 'offres';
    $db->prepare("DELETE FROM $table WHERE id = ? AND author_id = ?")
       ->execute([$id, $auth['id']]);
    jsonOk(['success' => true]);
}

jsonError('Action non reconnue');
