<?php
/**
 * VOID API — /api/whitelist.php
 *
 * POST /api/whitelist.php?action=request    — solicitar acceso (público)
 * GET  /api/whitelist.php?action=check      — comprobar si email está aprobado (público)
 * GET  /api/whitelist.php?action=list       — listar todas las solicitudes (solo admin)
 * POST /api/whitelist.php?action=approve    — aprobar email (solo admin)
 * POST /api/whitelist.php?action=reject     — rechazar email (solo admin)
 *
 * El admin se autentica con la cabecera:
 *   X-Admin-Secret: <ADMIN_SECRET definido abajo>
 */

require_once __DIR__ . '/../includes/auth.php';
cors();

function is_admin(): bool {
    $header = $_SERVER['HTTP_X_ADMIN_SECRET'] ?? '';
    return hash_equals(ADMIN_SECRET, $header);
}

function require_admin(): void {
    if (!is_admin()) json_err('No autorizado', 403);
}

// ─── Router ───────────────────────────────────────────────────────────────────
$action = $_GET['action'] ?? '';

switch ($action) {

    // ── Contar aprobados (público) ─────────────────────────────────────────────
    case 'count': {
        $db  = get_db();
        $row = $db->query("SELECT COUNT(*) as total FROM whitelist WHERE status = 'approved'")->fetch();
        json_ok(['count' => (int)($row['total'] ?? 0)]);
    }

    // ── Solicitar acceso ───────────────────────────────────────────────────────
    case 'request': {
        $body     = json_decode(file_get_contents('php://input'), true) ?? [];
        $name     = trim($body['name'] ?? '');
        $email    = strtolower(trim($body['email'] ?? ''));
        $password = $body['password'] ?? '';

        if (!$name)                                    json_err('El nombre es obligatorio');
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) json_err('Email inválido');
        if (!$password || strlen($password) < 6)       json_err('La contraseña debe tener al menos 6 caracteres');

        $db = get_db();

        // Comprobar si ya existe
        $stmt = $db->prepare("SELECT status FROM whitelist WHERE email = ?");
        $stmt->execute([$email]);
        $row = $stmt->fetch();

        if ($row) {
            if ($row['status'] === 'approved') json_err('Este email ya está aprobado en la whitelist');
            if ($row['status'] === 'pending')  json_err('Ya tienes una solicitud pendiente de revisión');
            if ($row['status'] === 'rejected') json_err('Tu solicitud fue rechazada. Contacta al administrador');
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);

        $db->prepare("INSERT INTO whitelist (name, email, password_hash, status) VALUES (?, ?, ?, 'pending')")
           ->execute([$name, $email, $hash]);

        json_ok(['message' => 'Solicitud enviada. El administrador la revisará pronto.']);
    }

    // ── Comprobar si email está en whitelist aprobada ──────────────────────────
    case 'check': {
        $email = strtolower(trim($_GET['email'] ?? ''));
        if (!$email) json_err('Email requerido');

        $db   = get_db();
        $stmt = $db->prepare("SELECT status FROM whitelist WHERE email = ?");
        $stmt->execute([$email]);
        $row  = $stmt->fetch();

        json_ok([
            'approved' => $row && $row['status'] === 'approved',
            'status'   => $row ? $row['status'] : 'not_found',
        ]);
    }

    // ── Listar solicitudes (admin) ─────────────────────────────────────────────
    case 'list': {
        require_admin();
        $db   = get_db();
        $rows = $db->query("SELECT id, name, email, status, requested_at, reviewed_at FROM whitelist ORDER BY requested_at DESC")->fetchAll();
        json_ok($rows);
    }

    // ── Aprobar (admin) ────────────────────────────────────────────────────────
    case 'approve': {
        require_admin();
        $body  = json_decode(file_get_contents('php://input'), true) ?? [];
        $email = strtolower(trim($body['email'] ?? ''));
        if (!$email) json_err('Email requerido');

        $db = get_db();

        // Aprobar en whitelist
        $stmt = $db->prepare("UPDATE whitelist SET status = 'approved', reviewed_at = NOW() WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->rowCount() === 0) json_err('Email no encontrado en la whitelist');

        // Crear usuario en tabla users si no existe todavía
        $wl = $db->prepare("SELECT name, password_hash FROM whitelist WHERE email = ?");
        $wl->execute([$email]);
        $wlRow = $wl->fetch();

        $exists = $db->prepare("SELECT id FROM users WHERE email = ?");
        $exists->execute([$email]);
        if (!$exists->fetch() && $wlRow) {
            $db->prepare("INSERT INTO users (name, email, password) VALUES (?, ?, ?)")
               ->execute([$wlRow['name'], $email, $wlRow['password_hash']]);
        }

        json_ok(['message' => "Email $email aprobado"]);
    }

    // ── Rechazar (admin) ───────────────────────────────────────────────────────
    case 'reject': {
        require_admin();
        $body  = json_decode(file_get_contents('php://input'), true) ?? [];
        $email = strtolower(trim($body['email'] ?? ''));
        if (!$email) json_err('Email requerido');

        $db   = get_db();
        $stmt = $db->prepare("UPDATE whitelist SET status = 'rejected', reviewed_at = NOW() WHERE email = ?");
        $stmt->execute([$email]);

        if ($stmt->rowCount() === 0) json_err('Email no encontrado en la whitelist');
        json_ok(['message' => "Email $email rechazado"]);
    }

    default:
        json_err('Acción desconocida', 404);
}
