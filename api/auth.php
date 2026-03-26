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

switch ($action) {

    // ── REGISTER ────────────────────────────────────────────────────────────
    case 'register': {
        $name  = trim(require_field('name',  'Nombre'));
        $email = strtolower(trim(require_field('email', 'Email')));
        $pass  = require_field('password', 'Contraseña');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL))
            json_err('Email inválido');
        if (strlen($pass) < 6)
            json_err('La contraseña debe tener al menos 6 caracteres');

        $db = get_db();
        $exists = $db->prepare("SELECT id FROM users WHERE email = ?")->execute([$email]);
        if ($db->prepare("SELECT id FROM users WHERE email = ?")->execute([$email]) &&
            $db->query("SELECT id FROM users WHERE email = '$email'")->fetch())
            json_err('El email ya está registrado');

        // Safer check
        $chk = $db->prepare("SELECT id FROM users WHERE email = ?");
        $chk->execute([$email]);
        if ($chk->fetch()) json_err('El email ya está registrado');

        $hash = password_hash($pass, PASSWORD_BCRYPT);
        $stmt = $db->prepare("INSERT INTO users (name, email, password) VALUES (?, ?, ?)");
        $stmt->execute([$name, $email, $hash]);
        $userId = (int) $db->lastInsertId();

        $token = create_session($userId);
        json_ok([
            'token' => $token,
            'user'  => ['id' => $userId, 'name' => $name, 'email' => $email, 'avatar' => null, 'api_provider' => 'gemini']
        ]);
    }

    // ── LOGIN ────────────────────────────────────────────────────────────────
    case 'login': {
        $email = strtolower(trim(require_field('email', 'Email')));
        $pass  = require_field('password', 'Contraseña');

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
                'api_key'      => $user['api_key'] ? '***' : null, // never send raw key
                'api_provider' => $user['api_provider'],
            ]
        ]);
    }

    // ── LOGOUT ───────────────────────────────────────────────────────────────
    case 'logout': {
        destroy_session();
        json_ok();
    }

    // ── ME (check session) ───────────────────────────────────────────────────
    case 'me': {
        $user = require_auth();
        json_ok([
            'id'           => $user['id'],
            'name'         => $user['name'],
            'email'        => $user['email'],
            'avatar'       => $user['avatar'],
            'api_key'      => $user['api_key'] ? '***' : null,
            'api_provider' => $user['api_provider'],
        ]);
    }

    default:
        json_err('Acción desconocida', 404);
}
