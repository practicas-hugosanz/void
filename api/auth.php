<?php
/**
 * VOID API — /api/auth.php
 *
 * POST /api/auth.php?action=login
 * POST /api/auth.php?action=logout
 * GET  /api/auth.php?action=me
 *
 * El registro de usuarios no se expone aquí; se gestiona mediante la
 * whitelist: el administrador aprueba la solicitud y la cuenta se crea
 * automáticamente en admin.php / api/whitelist.php?action=approve.
 */

require_once __DIR__ . '/../includes/auth.php';
cors();

$action = $_GET['action'] ?? '';

// Helper: check whitelist approval
function check_whitelist(string $email): void {
    $db   = get_db();
    $stmt = $db->prepare("SELECT status FROM whitelist WHERE email = ?");
    $stmt->execute([$email]);
    $row  = $stmt->fetch();

    if (!$row || $row['status'] !== 'approved') {
        $status = $row ? $row['status'] : 'not_found';
        if ($status === 'pending')
            json_err('Tu solicitud de acceso está pendiente de aprobación.', 403);
        elseif ($status === 'rejected')
            json_err('Tu solicitud fue rechazada. Contacta al administrador.', 403);
        else
            json_err('Tu email no está en la whitelist. Solicita acceso primero.', 403);
    }
}

switch ($action) {

    // ── LOGIN ─────────────────────────────────────────────────────────────────
    case 'login': {
        $email = strtolower(trim(require_field('email', 'Email')));
        $pass  = require_field('password', 'Contraseña');

        check_whitelist($email);

        $db   = get_db();
        $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($pass, $user['password']))
            json_err('Credenciales incorrectas', 401);

        $token = create_session((int) $user['id']);
        json_ok([
            'token' => $token,
            'user'  => [
                'id'           => $user['id'],
                'name'         => $user['name'],
                'email'        => $user['email'],
                'avatar'       => $user['avatar'],
                'api_provider' => $user['api_provider'],
            ]
        ]);
    }

    // ── LOGOUT ────────────────────────────────────────────────────────────────
    case 'logout': {
        destroy_session();
        json_ok();
    }

    // ── ME ────────────────────────────────────────────────────────────────────
    case 'me': {
        $user = require_auth();
        json_ok([
            'id'           => $user['id'],
            'name'         => $user['name'],
            'email'        => $user['email'],
            'avatar'       => $user['avatar'],
            'api_provider' => $user['api_provider'],
            'api_model'    => $user['api_model'] ?? null,
        ]);
    }

    default:
        json_err('Acción desconocida', 404);
}
