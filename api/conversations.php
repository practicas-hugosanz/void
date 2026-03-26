<?php
/**
 * VOID API — /api/conversations.php
 *
 * GET    ?action=list            — all conversations for current user
 * POST   ?action=save            — upsert a conversation {id, title, messages}
 * DELETE ?action=delete&id=xxx   — delete a conversation
 * POST   ?action=clear           — delete ALL conversations for user
 */

require_once __DIR__ . '/../includes/auth.php';
cors();

$user   = require_auth();
$db     = get_db();
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

switch ($action) {

    // ── LIST ─────────────────────────────────────────────────────────────────
    case 'list': {
        $stmt = $db->prepare("
            SELECT id, title, messages, created_at, updated_at
            FROM conversations
            WHERE user_id = ?
            ORDER BY updated_at DESC
        ");
        $stmt->execute([$user['id']]);
        $rows = $stmt->fetchAll();
        // Decode messages JSON for each row
        foreach ($rows as &$r) {
            $r['messages'] = json_decode($r['messages'], true) ?: [];
        }
        json_ok($rows);
    }

    // ── SAVE (create or update) ───────────────────────────────────────────────
    case 'save': {
        $id       = trim(body()['id']       ?? '');
        $title    = trim(body()['title']    ?? 'Nueva conversación');
        $messages = body()['messages']      ?? [];

        if (!$id) json_err('ID de conversación requerido');
        if (!is_array($messages)) json_err('Messages debe ser un array');

        $messagesJson = json_encode($messages, JSON_UNESCAPED_UNICODE);

        // Check ownership if already exists
        $owns = $db->prepare("SELECT id FROM conversations WHERE id = ? AND user_id = ?");
        $owns->execute([$id, $user['id']]);

        if ($owns->fetch()) {
            // Update
            $db->prepare("
                UPDATE conversations
                SET title = ?, messages = ?, updated_at = datetime('now')
                WHERE id = ? AND user_id = ?
            ")->execute([$title, $messagesJson, $id, $user['id']]);
        } else {
            // Insert
            $db->prepare("
                INSERT INTO conversations (id, user_id, title, messages)
                VALUES (?, ?, ?, ?)
            ")->execute([$id, $user['id'], $title, $messagesJson]);
        }

        json_ok(['id' => $id, 'title' => $title]);
    }

    // ── DELETE ONE ───────────────────────────────────────────────────────────
    case 'delete': {
        $id = $_GET['id'] ?? (body()['id'] ?? '');
        if (!$id) json_err('ID requerido');

        $db->prepare("DELETE FROM conversations WHERE id = ? AND user_id = ?")
           ->execute([$id, $user['id']]);

        json_ok();
    }

    // ── CLEAR ALL ────────────────────────────────────────────────────────────
    case 'clear': {
        $db->prepare("DELETE FROM conversations WHERE user_id = ?")
           ->execute([$user['id']]);
        json_ok();
    }

    default:
        json_err('Acción desconocida', 404);
}
