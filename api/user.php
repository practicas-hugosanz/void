<?php
/**
 * VOID API — /api/user.php
 *
 * PUT /api/user.php?action=profile   — update name / email / password
 * PUT /api/user.php?action=avatar    — upload/remove avatar (base64 in body)
 * PUT /api/user.php?action=settings  — save api_key + api_provider
 * GET /api/user.php?action=settings  — get api_key (masked) + provider
 */

require_once __DIR__ . '/../includes/auth.php';
cors();

$user   = require_auth();
$action = $_GET['action'] ?? '';
$db     = get_db();
$method = $_SERVER['REQUEST_METHOD'];

switch ($action) {

    // ── UPDATE PROFILE ───────────────────────────────────────────────────────
    case 'profile': {
        if ($method !== 'PUT' && $method !== 'POST') json_err('Método no permitido', 405);

        $newName  = trim(body()['name']  ?? $user['name']);
        $newEmail = strtolower(trim(body()['email'] ?? $user['email']));
        $passOld  = body()['password_current'] ?? '';
        $passNew  = body()['password_new']     ?? '';
        $passConf = body()['password_confirm'] ?? '';

        if (!$newName) json_err('El nombre no puede estar vacío');
        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) json_err('Email inválido');

        // Email uniqueness check (if changed)
        if ($newEmail !== strtolower($user['email'])) {
            $chk = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $chk->execute([$newEmail, $user['id']]);
            if ($chk->fetch()) json_err('Ese email ya está en uso');
        }

        // Password change
        if ($passNew !== '' || $passOld !== '') {
            if (!$passOld) json_err('Introduce tu contraseña actual');
            $row = $db->prepare("SELECT password FROM users WHERE id = ?");
            $row->execute([$user['id']]);
            $stored = $row->fetchColumn();
            if (!password_verify($passOld, $stored)) json_err('Contraseña actual incorrecta');
            if (strlen($passNew) < 6) json_err('La nueva contraseña debe tener al menos 6 caracteres');
            if ($passNew !== $passConf) json_err('Las contraseñas no coinciden');

            $hash = password_hash($passNew, PASSWORD_BCRYPT);
            $db->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hash, $user['id']]);
        }

        $db->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?")
           ->execute([$newName, $newEmail, $user['id']]);

        json_ok(['name' => $newName, 'email' => $newEmail]);
    }

    // ── AVATAR ───────────────────────────────────────────────────────────────
    case 'avatar': {
        if ($method !== 'PUT' && $method !== 'POST') json_err('Método no permitido', 405);

        $avatarData = body()['avatar'] ?? null; // base64 data-URL or '__remove__'

        if ($avatarData === '__remove__' || $avatarData === null) {
            $db->prepare("UPDATE users SET avatar = NULL WHERE id = ?")->execute([$user['id']]);
            json_ok(['avatar' => null]);
        }

        // Validate it's a base64 data URL
        if (!preg_match('#^data:image/(jpeg|png|gif|webp);base64,#', $avatarData))
            json_err('Formato de avatar inválido');

        // Size check: ~2MB base64 ≈ ~1.5MB binary
        if (strlen($avatarData) > 3 * 1024 * 1024)
            json_err('La imagen no puede superar 2 MB');

        $db->prepare("UPDATE users SET avatar = ? WHERE id = ?")->execute([$avatarData, $user['id']]);
        json_ok(['avatar' => $avatarData]);
    }


    // ── MEMORY ───────────────────────────────────────────────────────────────
    case 'memory': {
        if ($method === 'GET') {
            $row = $db->prepare("SELECT memory FROM users WHERE id = ?");
            $row->execute([$user['id']]);
            $mem = $row->fetchColumn();
            json_ok(['memory' => $mem ?? '']);
        }
        $memory = body()['memory'] ?? '';
        if (strlen($memory) > 4000) json_err('La memoria no puede superar 4000 caracteres');
        $db->prepare("UPDATE users SET memory = ? WHERE id = ?")
           ->execute([$memory ?: null, $user['id']]);
        json_ok(['memory' => $memory]);
    }

    // ── API SETTINGS ─────────────────────────────────────────────────────────
    case 'settings': {
        if ($method === 'GET') {
            $row = $db->prepare("SELECT api_provider, api_model FROM users WHERE id = ?");
            $row->execute([$user['id']]);
            $s = $row->fetch();
            json_ok([
                'api_provider' => $s['api_provider'] ?? 'gemini',
                'api_model'    => $s['api_model'] ?? null,
            ]);
        }

        // PUT / POST — save
        $provider = trim(body()['api_provider'] ?? 'gemini');
        $model    = trim(body()['api_model']    ?? '');

        if (!in_array($provider, ['gemini', 'openai', 'anthropic'], true))
            json_err('Proveedor inválido');

        // Allowed models whitelist
        $allowedModels = [
            'gemini'    => ['gemini-2.0-flash','gemini-2.5-flash','gemini-2.5-pro','gemini-2.0-flash-lite','gemini-2.0-flash-001','gemini-2.0-flash-lite-001'],
            'openai'    => ['gpt-4o','gpt-4o-mini','gpt-4-turbo','gpt-3.5-turbo','o1-mini'],
            'anthropic' => ['claude-opus-4-6','claude-sonnet-4-6','claude-haiku-4-5-20251001'],
        ];
        if ($model && !in_array($model, $allowedModels[$provider] ?? [], true))
            json_err('Modelo no permitido para este proveedor');

        // Solo actualizar proveedor y modelo — la api_key la gestiona el servidor vía env vars
        $db->prepare("UPDATE users SET api_provider = ?, api_model = ? WHERE id = ?")
           ->execute([$provider, $model ?: null, $user['id']]);

        json_ok(['api_provider' => $provider, 'api_model' => $model]);
    }

    default:
        json_err('Acción desconocida', 404);
}
