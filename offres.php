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
$type = $_GET['type'] ?? 'offres';
$id = intval($_GET['id'] ?? 0);
$body = getBody();
$db = getDB();

// Auth optionnelle pour GET public, obligatoire pour POST/PUT/DELETE
if ($method !== 'GET' || in_array($type, ['profile'])) {
    $auth = requireAuth();
} else {
    // GET public : essayer le token si présent
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = str_replace('Bearer ', '', $authHeader);
    $auth = $token ? (verifyToken($token) ?? ['id' => 0, 'role' => 'guest']) : ['id' => 0, 'role' => 'guest'];
}

/* ── LISTER les offres ou missions ── */
if ($method === 'GET') {
    try {
        if ($type === 'offres') {
            $stmt = $db->prepare('
                SELECT o.*, u.first_name, u.last_name, u.company, u.cover_data
                FROM offres o
                LEFT JOIN users u ON o.author_id = u.id
                ORDER BY o.created_at DESC
            ');
            $stmt->execute();
            $rows = $stmt->fetchAll();

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
            $stats['total_candidats'] = $db->query("SELECT COUNT(*) FROM users WHERE role='candidat'")->fetchColumn();
            $stats['offres_actives'] = $db->query("SELECT COUNT(*) FROM offres WHERE statut='Actif'")->fetchColumn();
            $stats['total_entretiens'] = $db->query("SELECT COUNT(*) FROM creneaux WHERE statut='confirmé'")->fetchColumn();
            $stats['employes_actifs'] = $db->query("SELECT COUNT(*) FROM users WHERE role='recruteur' AND is_active=1")->fetchColumn();
            jsonOk($stats);
        }

        // Candidats (pour le fil recruteur)
        if ($type === 'candidats') {
            $stmt = $db->prepare("SELECT id, first_name, last_name, city, skills, diploma, exp, bio, avatar_data, cv_data, cv_name, status, role FROM users WHERE role='candidat' AND is_active=1");
            $stmt->execute();
            jsonOk($stmt->fetchAll());
        }
    } catch (Exception $e) {
        log_error('Get offers error: ' . $e->getMessage());
        jsonError('Server error', 500);
    }
}

/* ── CRÉER une offre (sécurisé) ── */
if ($method === 'POST' && $type === 'offres') {
    try {
        $titre = trim($body['titre'] ?? '');
        if (!$titre || strlen($titre) < 3 || strlen($titre) > 200) {
            jsonError('Title must be between 3 and 200 characters');
        }

        $contractType = $body['type'] ?? 'CDI';
        if (!in_array($contractType, ['CDI', 'CDD', 'Stage', 'Freelance', 'Alternance'])) {
            jsonError('Invalid contract type');
        }

        $db->beginTransaction();

        $stmt = $db->prepare('
            INSERT INTO offres (
                titre, type_contrat, statut, city, description, tags, required_skills,
                salary, diploma_required, informal, author_id
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $titre,
            $contractType,
            'Actif',
            trim($body['city'] ?? ''),
            trim($body['desc'] ?? ''),
            json_encode($body['tags'] ?? []),
            json_encode($body['requiredSkills'] ?? $body['tags'] ?? []),
            trim($body['salary'] ?? ''),
            isset($body['diplomaRequired']) && $body['diplomaRequired'] ? 1 : 0,
            isset($body['informal']) && $body['informal'] ? 1 : 0,
            $auth['id'],
        ]);

        $newId = $db->lastInsertId();
        $row = $db->prepare('SELECT * FROM offres WHERE id = ?');
        $row->execute([$newId]);
        $offre = $row->fetch();
        $offre['tags'] = json_decode($offre['tags'], true) ?: [];

        $db->commit();
        jsonOk($offre);
    } catch (Exception $e) {
        $db->rollBack();
        log_error('Create offer error: ' . $e->getMessage());
        jsonError('Server error', 500);
    }
}

/* ── CRÉER une mission (sécurisé) ── */
if ($method === 'POST' && $type === 'missions') {
    try {
        $titre = trim($body['titre'] ?? '');
        if (!$titre || strlen($titre) < 3 || strlen($titre) > 200) {
            jsonError('Title must be between 3 and 200 characters');
        }

        $db->beginTransaction();

        $stmt = $db->prepare('
            INSERT INTO missions (titre, description, pay, duration, city, tags, statut, author_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $titre,
            trim($body['desc'] ?? ''),
            trim($body['pay'] ?? ''),
            trim($body['duration'] ?? ''),
            trim($body['city'] ?? ''),
            json_encode($body['tags'] ?? []),
            'Actif',
            $auth['id'],
        ]);

        $newId = $db->lastInsertId();
        $row = $db->prepare('SELECT * FROM missions WHERE id = ?');
        $row->execute([$newId]);
        $mission = $row->fetch();
        $mission['tags'] = json_decode($mission['tags'], true) ?: [];

        $db->commit();
        jsonOk($mission);
    } catch (Exception $e) {
        $db->rollBack();
        log_error('Create mission error: ' . $e->getMessage());
        jsonError('Server error', 500);
    }
}

/* ── MODIFIER le statut (sécurisé avec whitelist) ── */
if ($method === 'PUT' && $id > 0) {
    try {
        // Whitelist des types valides
        if (!in_array($type, ['offres', 'missions'])) {
            jsonError('Invalid type', 400);
        }
        $table = $type === 'missions' ? 'missions' : 'offres';

        $statut = $body['statut'] ?? 'Actif';
        $validStatuts = $type === 'missions' ? ['Actif', 'Termine', 'Annule'] : ['Actif', 'Ferme', 'Brouillon'];
        if (!in_array($statut, $validStatuts)) {
            jsonError('Invalid status');
        }

        $db->prepare("UPDATE $table SET statut = ? WHERE id = ? AND author_id = ?")
            ->execute([$statut, $id, $auth['id']]);
        jsonOk(['success' => true]);
    } catch (Exception $e) {
        log_error('Update offer error: ' . $e->getMessage());
        jsonError('Server error', 500);
    }
}

/* ── SUPPRIMER une offre ou mission ── */
if ($method === 'DELETE' && $id > 0) {
    try {
        if (!in_array($type, ['offres', 'missions'])) {
            jsonError('Invalid type', 400);
        }
        $table = $type === 'missions' ? 'missions' : 'offres';

        $db->prepare("DELETE FROM $table WHERE id = ? AND author_id = ?")
            ->execute([$id, $auth['id']]);
        jsonOk(['success' => true]);
    } catch (Exception $e) {
        log_error('Delete offer error: ' . $e->getMessage());
        jsonError('Server error', 500);
    }
}

jsonError('Unknown action', 400);
