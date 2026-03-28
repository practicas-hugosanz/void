<?php
/**
 * VOID API — /api/auth.php
 *
 * POST /api/auth.php?action=register
 * POST /api/auth.php?action=login
 * POST /api/auth.php?action=logout
 * GET  /api/auth.php?action=me
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

    // ── REGISTER ─────────────────────────────────────────────────────────────
    case 'register': {
        $name  = trim(require_field('name',  'Nombre'));
        $email = strtolower(trim(require_field('email', 'Email')));
        $pass  = require_field('password', 'Contraseña');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL))
            json_err('Email inválido');
        if (strlen($pass) < 6)
            json_err('La contraseña debe tener al menos 6 caracteres');

        check_whitelist($email);

        $db  = get_db();
        $chk = $db->prepare("SELECT id FROM users WHERE email = ?");
        $chk->execute([$email]);
        if ($chk->fetch()) json_err('El email ya está registrado');

        $hash = password_hash($pass, PASSWORD_BCRYPT);
        $stmt = $db->prepare("INSERT INTO users (name, email, password) VALUES (?, ?, ?) RETURNING id");
        $stmt->execute([$name, $email, $hash]);
        $userId = (int) $stmt->fetchColumn();

        $token = create_session($userId);
        json_ok([
            'token' => $token,
            'user'  => ['id' => $userId, 'name' => $name, 'email' => $email, 'avatar' => null, 'api_provider' => 'gemini']
        ]);
    }

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
