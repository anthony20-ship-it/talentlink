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
$id = intval($_GET['id'] ?? 0);
$body = getBody();

$auth = requireAuth();
$db = getDB();

/* ============================================================
   CANDIDATURES
============================================================ */
if ($action === 'candidatures') {

    // GET → mes candidatures (vue candidat)
    if ($method === 'GET') {
        try {
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
        } catch (Exception $e) {
            log_error('Get candidatures error: ' . $e->getMessage());
            jsonError('Server error', 500);
        }
    }

    // GET ?vue=recruteur → candidatures reçues (sécurisé)
    if ($method === 'GET' && ($_GET['vue'] ?? '') === 'recruteur') {
        try {
            if (!in_array($auth['role'], ['recruteur', 'particulier'])) {
                jsonError('Unauthorized', 403);
            }

            $stmt = $db->prepare('
                SELECT c.*,
                       u.first_name, u.last_name, u.city, u.skills, u.diploma, u.avatar_data,
                       o.titre AS offre_titre, o.author_id
                FROM candidatures c
                JOIN users u ON c.candidat_id = u.id
                LEFT JOIN offres o ON c.offre_id = o.id AND c.type = "offre"
                WHERE o.author_id = ? AND c.type = "offre"
                ORDER BY c.created_at DESC
            ');
            $stmt->execute([$auth['id']]);
            jsonOk($stmt->fetchAll());
        } catch (Exception $e) {
            log_error('Get recruiter candidatures error: ' . $e->getMessage());
            jsonError('Server error', 500);
        }
    }

    // POST → postuler
    if ($method === 'POST') {
        try {
            $offreId = intval($body['offreId'] ?? 0);
            $type = $body['type'] ?? 'offre';

            if (!$offreId) {
                jsonError('Offer ID is required');
            }

            if (!in_array($type, ['offre', 'mission'])) {
                jsonError('Invalid type');
            }

            // Vérifier que l'offre existe
            $table = $type === 'mission' ? 'missions' : 'offres';
            $check = $db->prepare("SELECT id FROM $table WHERE id = ? LIMIT 1");
            $check->execute([$offreId]);
            if (!$check->fetch()) {
                jsonError('Offer not found', 404);
            }

            // Vérifier doublon
            $checkDup = $db->prepare('
                SELECT id FROM candidatures
                WHERE offre_id = ? AND candidat_id = ? AND type = ? LIMIT 1
            ');
            $checkDup->execute([$offreId, $auth['id'], $type]);
            if ($checkDup->fetch()) {
                jsonError('You already applied to this offer');
            }

            $db->prepare('
                INSERT INTO candidatures (offre_id, type, candidat_id, statut)
                VALUES (?, ?, ?, ?)
            ')->execute([$offreId, $type, $auth['id'], 'En attente']);

            jsonOk(['success' => true, 'message' => 'Application sent!']);
        } catch (Exception $e) {
            log_error('Create candidature error: ' . $e->getMessage());
            jsonError('Server error', 500);
        }
    }

    // PUT → changer statut (recruteur)
    if ($method === 'PUT' && $id > 0) {
        try {
            $statut = $body['statut'] ?? 'En attente';

            if (!in_array($statut, ['En attente', 'Accepte', 'Rejete'])) {
                jsonError('Invalid status');
            }

            // Vérifier que l'utilisateur est le recruteur
            $check = $db->prepare('
                SELECT c.id, o.author_id
                FROM candidatures c
                LEFT JOIN offres o ON c.offre_id = o.id AND c.type = "offre"
                LEFT JOIN missions m ON c.offre_id = m.id AND c.type = "mission"
                WHERE c.id = ? AND (o.author_id = ? OR m.author_id = ?)
                LIMIT 1
            ');
            $check->execute([$id, $auth['id'], $auth['id']]);
            if (!$check->fetch()) {
                jsonError('Unauthorized', 403);
            }

            $db->prepare('UPDATE candidatures SET statut = ? WHERE id = ?')->execute([$statut, $id]);
            jsonOk(['success' => true]);
        } catch (Exception $e) {
            log_error('Update candidature error: ' . $e->getMessage());
            jsonError('Server error', 500);
        }
    }

    // DELETE → retirer sa candidature
    if ($method === 'DELETE' && $id > 0) {
        try {
            $db->prepare('DELETE FROM candidatures WHERE id = ? AND candidat_id = ?')->execute([$id, $auth['id']]);
            jsonOk(['success' => true]);
        } catch (Exception $e) {
            log_error('Delete candidature error: ' . $e->getMessage());
            jsonError('Server error', 500);
        }
    }
}

/* ============================================================
   MESSAGERIE
============================================================ */
if ($action === 'messages') {

    // GET → messages d'une conversation
    if ($method === 'GET') {
        try {
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

            // Vérifier l'accès à la conversation
            $convCheck = $db->prepare('
                SELECT id FROM conversations
                WHERE id = ? AND (user1_id = ? OR user2_id = ?)
                LIMIT 1
            ');
            $convCheck->execute([$convId, $auth['id'], $auth['id']]);
            if (!$convCheck->fetch()) {
                jsonError('Unauthorized', 403);
            }

            // Marquer comme lus
            $db->prepare('
                UPDATE messages SET read_at = NOW()
                WHERE conv_id = ? AND to_id = ? AND read_at IS NULL
            ')->execute([$convId, $auth['id']]);

            // Récupérer les messages
            $stmt = $db->prepare('SELECT * FROM messages WHERE conv_id = ? ORDER BY created_at ASC');
            $stmt->execute([$convId]);
            jsonOk($stmt->fetchAll());
        } catch (Exception $e) {
            log_error('Get messages error: ' . $e->getMessage());
            jsonError('Server error', 500);
        }
    }

    // POST → envoyer un message
    if ($method === 'POST') {
        try {
            $toId = intval($body['toId'] ?? 0);
            $content = trim($body['content'] ?? '');
            if (!$content) {
                jsonError('Message cannot be empty');
            }

            if (strlen($content) > 5000) {
                jsonError('Message too long (max 5000 characters)');
            }

            // Vérifier / créer la conversation
            $convId = intval($body['convId'] ?? 0);
            if (!$convId) {
                if (!$toId) {
                    jsonError('Recipient missing');
                }

                // Vérifier que le destinataire existe
                $userCheck = $db->prepare('SELECT id FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
                $userCheck->execute([$toId]);
                if (!$userCheck->fetch()) {
                    jsonError('Recipient not found', 404);
                }

                // Chercher conversation existante
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
                        jsonError('Only recruiters can start conversations', 403);
                    }

                    $db->prepare('
                        INSERT INTO conversations (user1_id, user2_id, last_msg, last_at)
                        VALUES (?, ?, ?, NOW())
                    ')->execute([$auth['id'], $toId, mb_substr($content, 0, 100)]);
                    $convId = $db->lastInsertId();
                }
            }

            // Récupérer le destinataire
            $convRow = $db->prepare('SELECT * FROM conversations WHERE id = ?');
            $convRow->execute([$convId]);
            $conv = $convRow->fetch();

            if (!$conv || ($conv['user1_id'] != $auth['id'] && $conv['user2_id'] != $auth['id'])) {
                jsonError('Unauthorized', 403);
            }

            $realToId = $conv['user1_id'] == $auth['id'] ? $conv['user2_id'] : $conv['user1_id'];

            // Insérer le message
            $db->prepare('
                INSERT INTO messages (conv_id, from_id, to_id, content)
                VALUES (?, ?, ?, ?)
            ')->execute([$convId, $auth['id'], $realToId, $content]);

            // Mettre à jour la conversation
            $db->prepare('
                UPDATE conversations SET last_msg = ?, last_at = NOW() WHERE id = ?
            ')->execute([mb_substr($content, 0, 100), $convId]);

            jsonOk(['success' => true, 'convId' => $convId]);
        } catch (Exception $e) {
            log_error('Create message error: ' . $e->getMessage());
            jsonError('Server error', 500);
        }
    }
}

/* ============================================================
   RENDEZ-VOUS (Créneaux)
============================================================ */
if ($action === 'creneaux') {

    // GET → créneaux visibles
    if ($method === 'GET') {
        try {
            $date = $_GET['date'] ?? '';

            if ($date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                jsonError('Invalid date format (YYYY-MM-DD)');
            }

            if ($date) {
                if (in_array($auth['role'], ['recruteur', 'particulier'])) {
                    $stmt = $db->prepare('
                        SELECT c.*, u.first_name, u.last_name
                        FROM creneaux c
                        LEFT JOIN users u ON c.candidat_id = u.id
                        WHERE c.recruteur_id = ? AND c.date = ?
                        ORDER BY c.heure
                    ');
                    $stmt->execute([$auth['id'], $date]);
                } else {
                    $stmt = $db->prepare('
                        SELECT c.*, u.first_name AS rec_fname, u.last_name AS rec_lname, u.company
                        FROM creneaux c
                        LEFT JOIN users u ON c.recruteur_id = u.id
                        WHERE c.date = ? AND (c.statut = "disponible" OR c.candidat_id = ?)
                        ORDER BY c.heure
                    ');
                    $stmt->execute([$date, $auth['id']]);
                }
            } else {
                if (in_array($auth['role'], ['recruteur', 'particulier'])) {
                    $stmt = $db->prepare('SELECT date, statut FROM creneaux WHERE recruteur_id = ? ORDER BY date DESC');
                    $stmt->execute([$auth['id']]);
                } else {
                    $stmt = $db->prepare('
                        SELECT DISTINCT date, statut FROM creneaux
                        WHERE statut = "disponible" OR candidat_id = ?
                        ORDER BY date DESC
                    ');
                    $stmt->execute([$auth['id']]);
                }
            }
            jsonOk($stmt->fetchAll());
        } catch (Exception $e) {
            log_error('Get creneaux error: ' . $e->getMessage());
            jsonError('Server error', 500);
        }
    }

    // POST → proposer des créneaux
    if ($method === 'POST') {
        try {
            if (!in_array($auth['role'], ['recruteur', 'particulier'])) {
                jsonError('Access denied', 403);
            }

            $date = $body['date'] ?? '';
            $heures = $body['heures'] ?? [];
            $note = trim($body['note'] ?? '');

            if (!$date || empty($heures)) {
                jsonError('Date and hours are required');
            }

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                jsonError('Invalid date format (YYYY-MM-DD)');
            }

            if (!is_array($heures)) {
                jsonError('Hours must be an array');
            }

            $db->beginTransaction();
            $inserted = 0;

            foreach ($heures as $heure) {
                if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $heure)) {
                    continue;
                }

                $check = $db->prepare('
                    SELECT id FROM creneaux
                    WHERE date = ? AND heure = ? AND recruteur_id = ? LIMIT 1
                ');
                $check->execute([$date, $heure, $auth['id']]);
                if (!$check->fetch()) {
                    $db->prepare('
                        INSERT INTO creneaux (date, heure, note, statut, recruteur_id)
                        VALUES (?, ?, ?, "disponible", ?)
                    ')->execute([$date, $heure, $note, $auth['id']]);
                    $inserted++;
                }
            }

            $db->commit();
            jsonOk(['success' => true, 'inserted' => $inserted]);
        } catch (Exception $e) {
            $db->rollBack();
            log_error('Create creneaux error: ' . $e->getMessage());
            jsonError('Server error', 500);
        }
    }

    // PUT → modifier statut d'un créneau
    if ($method === 'PUT' && $id > 0) {
        try {
            $statut = $body['statut'] ?? '';

            if (!in_array($statut, ['réservé', 'confirmé', 'annulé'])) {
                jsonError('Invalid status');
            }

            $slot = $db->prepare('SELECT * FROM creneaux WHERE id = ? LIMIT 1');
            $slot->execute([$id]);
            $slotRow = $slot->fetch();

            if (!$slotRow) {
                jsonError('Slot not found', 404);
            }

            $db->beginTransaction();

            if ($statut === 'réservé') {
                if ($slotRow['statut'] !== 'disponible') {
                    jsonError('This slot is no longer available');
                }

                $db->prepare('
                    UPDATE creneaux SET statut = "réservé", candidat_id = ? WHERE id = ?
                ')->execute([$auth['id'], $id]);

                $db->prepare('
                    INSERT INTO rdv_notifs (to_id, from_id, slot_id, type, message)
                    VALUES (?, ?, ?, "demande", ?)
                ')->execute([
                    $slotRow['recruteur_id'],
                    $auth['id'],
                    $id,
                    "Meeting request for {$slotRow['date']} at {$slotRow['heure']}"
                ]);

            } elseif ($statut === 'confirmé') {
                if ($slotRow['recruteur_id'] != $auth['id']) {
                    jsonError('Unauthorized', 403);
                }

                $db->prepare('UPDATE creneaux SET statut = "confirmé" WHERE id = ?')->execute([$id]);

                if ($slotRow['candidat_id']) {
                    $db->prepare('
                        INSERT INTO rdv_notifs (to_id, from_id, slot_id, type, message)
                        VALUES (?, ?, ?, "confirmation", ?)
                    ')->execute([
                        $slotRow['candidat_id'],
                        $auth['id'],
                        $id,
                        "Your meeting on {$slotRow['date']} at {$slotRow['heure']} is confirmed ✅"
                    ]);
                }

            } elseif ($statut === 'annulé') {
                $otherId = $auth['id'] == $slotRow['recruteur_id'] ? $slotRow['candidat_id'] : $slotRow['recruteur_id'];

                $db->prepare('UPDATE creneaux SET statut = "annulé", candidat_id = NULL WHERE id = ?')->execute([$id]);

                if ($otherId) {
                    $db->prepare('
                        INSERT INTO rdv_notifs (to_id, from_id, slot_id, type, message)
                        VALUES (?, ?, ?, "annulation", ?)
                    ')->execute([
                        $otherId,
                        $auth['id'],
                        $id,
                        "The meeting on {$slotRow['date']} at {$slotRow['heure']} has been cancelled"
                    ]);
                }
            }

            $db->commit();
            jsonOk(['success' => true]);
        } catch (Exception $e) {
            $db->rollBack();
            log_error('Update creneaux error: ' . $e->getMessage());
            jsonError('Server error', 500);
        }
    }

    // DELETE → supprimer un créneau
    if ($method === 'DELETE' && $id > 0) {
        try {
            $db->prepare('
                DELETE FROM creneaux
                WHERE id = ? AND recruteur_id = ? AND statut = "disponible"
            ')->execute([$id, $auth['id']]);

            jsonOk(['success' => true]);
        } catch (Exception $e) {
            log_error('Delete creneaux error: ' . $e->getMessage());
            jsonError('Server error', 500);
        }
    }
}

/* ============================================================
   NOTIFICATIONS RDV
============================================================ */
if ($action === 'notifs') {
    try {
        if ($method === 'GET') {
            $stmt = $db->prepare('
                SELECT n.*, u.first_name, u.last_name
                FROM rdv_notifs n
                LEFT JOIN users u ON n.from_id = u.id
                WHERE n.to_id = ?
                ORDER BY n.created_at DESC
            ');
            $stmt->execute([$auth['id']]);
            jsonOk($stmt->fetchAll());
        }

        if ($method === 'PUT' && $id > 0) {
            $db->prepare('UPDATE rdv_notifs SET lue = 1 WHERE id = ? AND to_id = ?')->execute([$id, $auth['id']]);
            jsonOk(['success' => true]);
        }
    } catch (Exception $e) {
        log_error('Notifs error: ' . $e->getMessage());
        jsonError('Server error', 500);
    }
}

jsonError('Unknown action', 400);
