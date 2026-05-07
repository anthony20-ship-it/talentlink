<?php
/* ============================================================
   actions.php — Candidatures, Messagerie, Rendez-vous
   Route : /talentlink/api/actions.php
   
   ?action=candidatures  → gérer les candidatures
   ?action=messages      → messagerie directe
   ?action=creneaux      → prise de rendez-vous
   ?action=notifs        → notifications RDV
============================================================ */
require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$id     = intval($_GET['id'] ?? 0);
$body   = getBody();

$auth = requireAuth();
$db   = getDB();

/* ============================================================
   CANDIDATURES
============================================================ */
if ($action === 'candidatures') {

    // GET → mes candidatures (vue candidat)
    if ($method === 'GET') {
        $stmt = $db->prepare('
            SELECT c.*, 
                   o.titre AS offre_titre, o.author_id AS offre_author,
                   u.first_name, u.last_name, u.company
            FROM candidatures c
            LEFT JOIN offres o ON c.offre_id = o.id AND c.type = "offre"
            LEFT JOIN missions m ON c.offre_id = m.id AND c.type = "mission"
            LEFT JOIN users u ON (o.author_id = u.id OR m.author_id = u.id)
            WHERE c.candidat_id = ?
            ORDER BY c.created_at DESC
        ');
        $stmt->execute([$auth['id']]);
        jsonOk($stmt->fetchAll());
    }

    // GET ?action=candidatures&vue=recruteur → candidatures reçues
    if ($method === 'GET' && ($_GET['vue'] ?? '') === 'recruteur') {
        $stmt = $db->prepare('
            SELECT c.*, u.first_name, u.last_name, u.city, u.skills, u.diploma, u.avatar_data,
                   o.titre AS offre_titre
            FROM candidatures c
            JOIN users u ON c.candidat_id = u.id
            LEFT JOIN offres o ON c.offre_id = o.id
            WHERE o.author_id = ?
            ORDER BY c.created_at DESC
        ');
        $stmt->execute([$auth['id']]);
        jsonOk($stmt->fetchAll());
    }

    // POST → postuler
    if ($method === 'POST') {
        $offreId = intval($body['offreId'] ?? 0);
        $type    = $body['type'] ?? 'offre';
        if (!$offreId) jsonError('ID offre manquant');
        // Vérifier doublon
        $check = $db->prepare('SELECT id FROM candidatures WHERE offre_id = ? AND candidat_id = ? LIMIT 1');
        $check->execute([$offreId, $auth['id']]);
        if ($check->fetch()) jsonError('Vous avez déjà postulé à cette offre');
        $db->prepare('INSERT INTO candidatures (offre_id, type, candidat_id, statut) VALUES (?, ?, ?, ?)')
           ->execute([$offreId, $type, $auth['id'], 'En attente']);
        jsonOk(['success' => true, 'message' => 'Candidature envoyée !']);
    }

    // PUT → changer statut (recruteur)
    if ($method === 'PUT' && $id > 0) {
        $statut = $body['statut'] ?? 'En attente';
        $db->prepare('UPDATE candidatures SET statut = ? WHERE id = ?')->execute([$statut, $id]);
        jsonOk(['success' => true]);
    }

    // DELETE → retirer sa candidature
    if ($method === 'DELETE' && $id > 0) {
        $db->prepare('DELETE FROM candidatures WHERE id = ? AND candidat_id = ?')->execute([$id, $auth['id']]);
        jsonOk(['success' => true]);
    }
}

/* ============================================================
   MESSAGERIE
============================================================ */
if ($action === 'messages') {

    // GET ?action=messages&convId=X → messages d'une conversation
    if ($method === 'GET') {
        $convId = intval($_GET['convId'] ?? 0);
        if (!$convId) {
            // Lister toutes mes conversations
            $stmt = $db->prepare('
                SELECT c.*, 
                       u1.first_name AS u1_fname, u1.last_name AS u1_lname, u1.avatar_data AS u1_avatar, u1.role AS u1_role,
                       u2.first_name AS u2_fname, u2.last_name AS u2_lname, u2.avatar_data AS u2_avatar, u2.role AS u2_role
                FROM conversations c
                JOIN users u1 ON c.user1_id = u1.id
                JOIN users u2 ON c.user2_id = u2.id
                WHERE c.user1_id = ? OR c.user2_id = ?
                ORDER BY c.last_at DESC
            ');
            $stmt->execute([$auth['id'], $auth['id']]);
            jsonOk($stmt->fetchAll());
        }
        // Marquer les messages comme lus
        $db->prepare('UPDATE messages SET read_at = NOW() WHERE conv_id = ? AND to_id = ? AND read_at IS NULL')
           ->execute([$convId, $auth['id']]);
        // Récupérer les messages
        $stmt = $db->prepare('SELECT * FROM messages WHERE conv_id = ? ORDER BY created_at ASC');
        $stmt->execute([$convId]);
        jsonOk($stmt->fetchAll());
    }

    // POST → démarrer ou envoyer un message
    if ($method === 'POST') {
        $toId    = intval($body['toId'] ?? 0);
        $content = trim($body['content'] ?? '');
        if (!$content) jsonError('Message vide');

        // Vérifier / créer la conversation
        $convId = intval($body['convId'] ?? 0);
        if (!$convId) {
            if (!$toId) jsonError('Destinataire manquant');
            // Chercher une conversation existante
            $stmt = $db->prepare('
                SELECT id FROM conversations 
                WHERE (user1_id = ? AND user2_id = ?) OR (user1_id = ? AND user2_id = ?) LIMIT 1
            ');
            $stmt->execute([$auth['id'], $toId, $toId, $auth['id']]);
            $conv = $stmt->fetch();
            if ($conv) {
                $convId = $conv['id'];
            } else {
                // Seul un recruteur/particulier peut initier
                if (!in_array($auth['role'], ['recruteur', 'particulier'])) {
                    jsonError('Seuls les recruteurs peuvent initier une conversation', 403);
                }
                $db->prepare('INSERT INTO conversations (user1_id, user2_id, last_msg, last_at) VALUES (?, ?, ?, NOW())')
                   ->execute([$auth['id'], $toId, $content]);
                $convId = $db->lastInsertId();
            }
        }
        // Récupérer le destinataire depuis la conversation
        $conv = $db->prepare('SELECT * FROM conversations WHERE id = ?');
        $conv->execute([$convId]);
        $convRow = $conv->fetch();
        $realToId = $convRow['user1_id'] == $auth['id'] ? $convRow['user2_id'] : $convRow['user1_id'];

        // Insérer le message
        $db->prepare('INSERT INTO messages (conv_id, from_id, to_id, content) VALUES (?, ?, ?, ?)')
           ->execute([$convId, $auth['id'], $realToId, $content]);

        // Mettre à jour le dernier message de la conversation
        $db->prepare('UPDATE conversations SET last_msg = ?, last_at = NOW() WHERE id = ?')
           ->execute([mb_substr($content, 0, 100), $convId]);

        jsonOk(['success' => true, 'convId' => $convId]);
    }
}

/* ============================================================
   RENDEZ-VOUS (Créneaux)
============================================================ */
if ($action === 'creneaux') {

    // GET → créneaux visibles par l'utilisateur
    if ($method === 'GET') {
        $date = $_GET['date'] ?? '';
        if ($date) {
            // Créneaux d'un jour précis
            if (in_array($auth['role'], ['recruteur', 'particulier'])) {
                $stmt = $db->prepare('SELECT c.*, u.first_name, u.last_name FROM creneaux c LEFT JOIN users u ON c.candidat_id = u.id WHERE c.recruteur_id = ? AND c.date = ? ORDER BY c.heure');
                $stmt->execute([$auth['id'], $date]);
            } else {
                $stmt = $db->prepare('SELECT c.*, u.first_name AS rec_fname, u.last_name AS rec_lname, u.company FROM creneaux c LEFT JOIN users u ON c.recruteur_id = u.id WHERE c.date = ? AND (c.statut = "disponible" OR c.candidat_id = ?) ORDER BY c.heure');
                $stmt->execute([$date, $auth['id']]);
            }
        } else {
            // Tous les créneaux (pour afficher les points sur le calendrier)
            if (in_array($auth['role'], ['recruteur', 'particulier'])) {
                $stmt = $db->prepare('SELECT date, statut FROM creneaux WHERE recruteur_id = ?');
                $stmt->execute([$auth['id']]);
            } else {
                $stmt = $db->prepare('SELECT date, statut FROM creneaux WHERE statut = "disponible" OR candidat_id = ?');
                $stmt->execute([$auth['id']]);
            }
        }
        jsonOk($stmt->fetchAll());
    }

    // POST → proposer des créneaux (recruteur)
    if ($method === 'POST') {
        if (!in_array($auth['role'], ['recruteur', 'particulier'])) jsonError('Accès refusé', 403);
        $date   = $body['date'] ?? '';
        $heures = $body['heures'] ?? [];
        $note   = $body['note'] ?? '';
        if (!$date || empty($heures)) jsonError('Date et horaires requis');

        $inserted = 0;
        foreach ($heures as $heure) {
            // Éviter les doublons
            $check = $db->prepare('SELECT id FROM creneaux WHERE date = ? AND heure = ? AND recruteur_id = ? LIMIT 1');
            $check->execute([$date, $heure, $auth['id']]);
            if (!$check->fetch()) {
                $db->prepare('INSERT INTO creneaux (date, heure, note, statut, recruteur_id) VALUES (?, ?, ?, "disponible", ?)')
                   ->execute([$date, $heure, $note, $auth['id']]);
                $inserted++;
            }
        }
        jsonOk(['success' => true, 'inserted' => $inserted]);
    }

    // PUT → modifier le statut d'un créneau (réserver, confirmer, annuler)
    if ($method === 'PUT' && $id > 0) {
        $statut    = $body['statut'] ?? '';
        $candidatId = intval($body['candidatId'] ?? 0);

        if ($statut === 'réservé') {
            // Candidat réserve
            $slot = $db->prepare('SELECT * FROM creneaux WHERE id = ? AND statut = "disponible" LIMIT 1');
            $slot->execute([$id]);
            if (!$slot->fetch()) jsonError('Ce créneau n\'est plus disponible');
            $db->prepare('UPDATE creneaux SET statut = "réservé", candidat_id = ? WHERE id = ?')->execute([$auth['id'], $id]);
            // Notification pour le recruteur
            $cren = $db->prepare('SELECT * FROM creneaux WHERE id = ?'); $cren->execute([$id]);
            $c    = $cren->fetch();
            $db->prepare('INSERT INTO rdv_notifs (to_id, from_id, slot_id, type, message) VALUES (?, ?, ?, "demande", ?)')
               ->execute([$c['recruteur_id'], $auth['id'], $id, "Demande de RDV le {$c['date']} à {$c['heure']}"]);

        } elseif ($statut === 'confirmé') {
            // Recruteur confirme
            $db->prepare('UPDATE creneaux SET statut = "confirmé" WHERE id = ? AND recruteur_id = ?')->execute([$id, $auth['id']]);
            $cren = $db->prepare('SELECT * FROM creneaux WHERE id = ?'); $cren->execute([$id]);
            $c    = $cren->fetch();
            if ($c['candidat_id']) {
                $db->prepare('INSERT INTO rdv_notifs (to_id, from_id, slot_id, type, message) VALUES (?, ?, ?, "confirmation", ?)')
                   ->execute([$c['candidat_id'], $auth['id'], $id, "Votre RDV du {$c['date']} à {$c['heure']} est confirmé ✅"]);
            }

        } elseif ($statut === 'annulé') {
            // Annulation par l'une ou l'autre partie
            $cren = $db->prepare('SELECT * FROM creneaux WHERE id = ?'); $cren->execute([$id]);
            $c    = $cren->fetch();
            $db->prepare('UPDATE creneaux SET statut = "annulé", candidat_id = NULL WHERE id = ?')->execute([$id]);
            $otherId = $auth['id'] == $c['recruteur_id'] ? $c['candidat_id'] : $c['recruteur_id'];
            if ($otherId) {
                $db->prepare('INSERT INTO rdv_notifs (to_id, from_id, slot_id, type, message) VALUES (?, ?, ?, "annulation", ?)')
                   ->execute([$otherId, $auth['id'], $id, "Le RDV du {$c['date']} à {$c['heure']} a été annulé"]);
            }
        }
        jsonOk(['success' => true]);
    }

    // DELETE → supprimer un créneau libre
    if ($method === 'DELETE' && $id > 0) {
        $db->prepare('DELETE FROM creneaux WHERE id = ? AND recruteur_id = ? AND statut = "disponible"')->execute([$id, $auth['id']]);
        jsonOk(['success' => true]);
    }
}

/* ============================================================
   NOTIFICATIONS RDV
============================================================ */
if ($action === 'notifs') {
    if ($method === 'GET') {
        $stmt = $db->prepare('SELECT n.*, u.first_name, u.last_name FROM rdv_notifs n LEFT JOIN users u ON n.from_id = u.id WHERE n.to_id = ? ORDER BY n.created_at DESC');
        $stmt->execute([$auth['id']]);
        jsonOk($stmt->fetchAll());
    }
    if ($method === 'PUT' && $id > 0) {
        // Marquer comme lue
        $db->prepare('UPDATE rdv_notifs SET lue = 1 WHERE id = ? AND to_id = ?')->execute([$id, $auth['id']]);
        jsonOk(['success' => true]);
    }
}

jsonError('Action non reconnue');
