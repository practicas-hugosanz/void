<?php
/**
 * VOID API — /api/oauth_github.php
 *
 * Flujo OAuth 2.0 con GitHub:
 *
 * PASO 1 — Redirigir al usuario a GitHub:
 *   GET /api/oauth_github.php?action=redirect
 *
 * PASO 2 — GitHub llama de vuelta a esta URL con ?code=...&state=...:
 *   GET /api/oauth_github.php?action=callback&code=...&state=...
 *
 * Configura las variables de entorno GITHUB_CLIENT_ID y GITHUB_CLIENT_SECRET
 * en tu servidor (Railway, .env, etc.) antes de usar este archivo.
 */

require_once __DIR__ . '/../includes/auth.php';
cors();

// ─── Configuración ────────────────────────────────────────────────────────────
// Rellena estos valores con los de tu GitHub OAuth App:
// https://github.com/settings/developers  →  OAuth Apps  →  New OAuth App
define('GITHUB_CLIENT_ID',     getenv('GITHUB_CLIENT_ID'));
define('GITHUB_CLIENT_SECRET', getenv('GITHUB_CLIENT_SECRET'));

// URL de retorno — debe coincidir EXACTAMENTE con la "Authorization callback URL"
// configurada en GitHub. Ejemplo: https://tudominio.com/api/oauth_github.php?action=callback
define('GITHUB_REDIRECT_URI', getenv('GITHUB_REDIRECT_URI') ?: (
    (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') .
    '/api/oauth_github.php?action=callback'
));

$action = $_GET['action'] ?? 'redirect';

switch ($action) {

    // ── PASO 1: Generar URL de autorización y redirigir ──────────────────────
    case 'redirect': {
        // Generar state CSRF
        $state = bin2hex(random_bytes(16));
        setcookie('void_oauth_state_github', $state, [
            'expires'  => time() + 600, // 10 min
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        $params = http_build_query([
            'client_id'    => GITHUB_CLIENT_ID,
            'redirect_uri' => GITHUB_REDIRECT_URI,
            'scope'        => 'read:user user:email',
            'state'        => $state,
        ]);

        header('Location: https://github.com/login/oauth/authorize?' . $params);
        exit;
    }

    // ── PASO 2: Recibir el código y canjearlo por access_token ───────────────
    case 'callback': {
        // Verificar state CSRF
        $receivedState = $_GET['state'] ?? '';
        $expectedState = $_COOKIE['void_oauth_state_github'] ?? '';
        setcookie('void_oauth_state_github', '', ['expires' => 1, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);

        if (!$receivedState || !hash_equals($expectedState, $receivedState)) {
            redirectWithError('Error de seguridad OAuth (state inválido). Inténtalo de nuevo.');
        }

        $code = $_GET['code'] ?? '';
        if (!$code) {
            redirectWithError('GitHub no devolvió un código de autorización.');
        }

        // Canjear código por access_token
        $tokenRes = httpPostGithub('https://github.com/login/oauth/access_token', [
            'client_id'     => GITHUB_CLIENT_ID,
            'client_secret' => GITHUB_CLIENT_SECRET,
            'code'          => $code,
            'redirect_uri'  => GITHUB_REDIRECT_URI,
        ]);

        if (!$tokenRes || empty($tokenRes['access_token'])) {
            redirectWithError('No se pudo obtener el token de GitHub.');
        }

        $accessToken = $tokenRes['access_token'];

        // Obtener perfil del usuario
        $profile = httpGetGithub('https://api.github.com/user', $accessToken);
        if (!$profile || empty($profile['id'])) {
            redirectWithError('No se pudo leer el perfil de GitHub.');
        }

        // Obtener emails (el email puede ser privado y no estar en el perfil)
        $email = null;
        if (!empty($profile['email'])) {
            $email = strtolower(trim($profile['email']));
        } else {
            // Consultar el endpoint de emails para obtener el email principal verificado
            $emails = httpGetGithub('https://api.github.com/user/emails', $accessToken);
            if (is_array($emails)) {
                foreach ($emails as $e) {
                    if (!empty($e['primary']) && !empty($e['verified'])) {
                        $email = strtolower(trim($e['email']));
                        break;
                    }
                }
                // Si no hay email primario verificado, usar el primero disponible
                if (!$email) {
                    foreach ($emails as $e) {
                        if (!empty($e['email'])) {
                            $email = strtolower(trim($e['email']));
                            break;
                        }
                    }
                }
            }
        }

        if (!$email) {
            redirectWithError('No se pudo obtener un email de tu cuenta de GitHub. Asegúrate de tener un email público o verificado.');
        }

        $githubId = (string) $profile['id'];
        $name     = trim($profile['name'] ?? $profile['login'] ?? explode('@', $email)[0]);
        $avatar   = $profile['avatar_url'] ?? null;

        // Buscar o crear usuario en la base de datos
        $db   = get_db();
        $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // ── Verificar whitelist ──────────────────────────────────────────────
        $wlStmt = $db->prepare("SELECT status FROM whitelist WHERE email = ?");
        $wlStmt->execute([$email]);
        $wlRow = $wlStmt->fetch();

        if (!$wlRow || $wlRow['status'] !== 'approved') {
            $reason = !$wlRow
                ? 'no_request'
                : $wlRow['status']; // 'pending' o 'rejected'
            redirectWithError('Tu cuenta no tiene acceso aprobado a VOID. Solicita acceso a la Whitelist.|wl:' . $reason);
        }

        if (!$user) {
            $fakeHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT);
            $ins = $db->prepare("INSERT INTO users (name, email, password, avatar) VALUES (?, ?, ?, ?) RETURNING id");
            $ins->execute([$name, $email, $fakeHash, $avatar]);
            $userId = (int) $ins->fetchColumn();
        } else {
            $userId = (int) $user['id'];
            // Actualizar nombre y avatar si han cambiado en GitHub
            $newAvatar = $avatar ?? $user['avatar'];
            $db->prepare("UPDATE users SET name = ?, avatar = ? WHERE id = ?")
               ->execute([$name, $newAvatar, $userId]);
        }

        // Crear sesión
        create_session($userId);

        // Redirigir al frontend
        header('Location: /');
        exit;
    }

    default:
        json_err('Acción desconocida', 404);
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

function redirectWithError(string $msg): never {
    $encoded = urlencode($msg);
    header('Location: /?oauth_error=' . $encoded . '#auth');
    exit;
}

function httpPostGithub(string $url, array $data): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($data),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
            'User-Agent: VOID-App',
        ],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err || !$raw) return null;
    return json_decode($raw, true);
}

/**
 * GET autenticado a la API de GitHub
 */
function httpGetGithub(string $url, string $token): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: VOID-App',
        ],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err || !$raw) return null;
    return json_decode($raw, true);
}
