<?php
/**
 * VOID API — /api/oauth_google.php
 *
 * Flujo OAuth 2.0 con Google:
 *
 * PASO 1 — Redirigir al usuario a Google:
 *   GET /api/oauth_google.php?action=redirect
 *
 * PASO 2 — Google llama de vuelta a esta URL con ?code=...:
 *   GET /api/oauth_google.php?action=callback&code=...&state=...
 *
 * Configura GOOGLE_CLIENT_ID y GOOGLE_CLIENT_SECRET abajo
 * (o mejor aún, en variables de entorno del servidor).
 */

require_once __DIR__ . '/../includes/auth.php';
cors();

// ─── Configuración ────────────────────────────────────────────────────────────
// Rellena estos valores con los de tu Google Cloud Console:
// https://console.cloud.google.com/apis/credentials
define('GOOGLE_CLIENT_ID',     getenv('GOOGLE_CLIENT_ID')     ?: 'TU_CLIENT_ID_AQUI');
define('GOOGLE_CLIENT_SECRET', getenv('GOOGLE_CLIENT_SECRET') ?: 'TU_CLIENT_SECRET_AQUI');

// URL de retorno — debe coincidir EXACTAMENTE con la configurada en Google Cloud Console
// Ejemplo: https://tudominio.com/api/oauth_google.php?action=callback
define('GOOGLE_REDIRECT_URI',  getenv('GOOGLE_REDIRECT_URI')  ?: (
    (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') .
    '/api/oauth_google.php?action=callback'
));

$action = $_GET['action'] ?? 'redirect';

switch ($action) {

    // ── PASO 1: Generar URL de autorización y redirigir ──────────────────────
    case 'redirect': {
        // Generar state CSRF
        $state = bin2hex(random_bytes(16));
        setcookie('void_oauth_state', $state, [
            'expires'  => time() + 600, // 10 min
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        $params = http_build_query([
            'client_id'     => GOOGLE_CLIENT_ID,
            'redirect_uri'  => GOOGLE_REDIRECT_URI,
            'response_type' => 'code',
            'scope'         => 'openid email profile',
            'access_type'   => 'online',
            'state'         => $state,
            'prompt'        => 'select_account',
        ]);

        header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $params);
        exit;
    }

    // ── PASO 2: Recibir el código y canjearlo por tokens ─────────────────────
    case 'callback': {
        // Verificar state CSRF
        $receivedState = $_GET['state'] ?? '';
        $expectedState = $_COOKIE['void_oauth_state'] ?? '';
        setcookie('void_oauth_state', '', ['expires' => 1, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);

        if (!$receivedState || !hash_equals($expectedState, $receivedState)) {
            redirectWithError('Error de seguridad OAuth (state inválido). Inténtalo de nuevo.');
        }

        $code = $_GET['code'] ?? '';
        if (!$code) {
            redirectWithError('Google no devolvió un código de autorización.');
        }

        // Canjear código por access_token + id_token
        $tokenRes = httpPost('https://oauth2.googleapis.com/token', [
            'code'          => $code,
            'client_id'     => GOOGLE_CLIENT_ID,
            'client_secret' => GOOGLE_CLIENT_SECRET,
            'redirect_uri'  => GOOGLE_REDIRECT_URI,
            'grant_type'    => 'authorization_code',
        ]);

        if (!$tokenRes || empty($tokenRes['id_token'])) {
            redirectWithError('No se pudo obtener el token de Google.');
        }

        // Verificar y decodificar el id_token (JWT, sin verificar firma para simplificar)
        // En producción, verifica la firma con las claves públicas de Google
        $idToken  = $tokenRes['id_token'];
        $payload  = jwtDecode($idToken);

        if (!$payload || empty($payload['email'])) {
            redirectWithError('No se pudo leer el perfil de Google.');
        }

        $email    = strtolower(trim($payload['email']));
        $name     = trim($payload['name'] ?? explode('@', $email)[0]);
        $googleId = $payload['sub'] ?? null;
        $avatar   = $payload['picture'] ?? null;

        // Buscar o crear usuario en la base de datos
        $db = get_db();
        $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user) {
            // Registrar nuevo usuario (sin contraseña — acceso sólo vía OAuth)
            // Guardamos una contraseña imposible de usar para login normal
            $fakeHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT);
            $db->prepare("INSERT INTO users (name, email, password, avatar) VALUES (?, ?, ?, ?)")
               ->execute([$name, $email, $fakeHash, $avatar]);
            $userId = (int) $db->lastInsertId();
        } else {
            $userId = (int) $user['id'];
            // Actualizar nombre y avatar si han cambiado en Google
            $newAvatar = $avatar ?? $user['avatar'];
            $db->prepare("UPDATE users SET name = ?, avatar = ? WHERE id = ?")
               ->execute([$name, $newAvatar, $userId]);
        }

        // Crear sesión
        create_session($userId);

        // Redirigir al frontend (el JS detectará la sesión activa vía /me)
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

function httpPost(string $url, array $data): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($data),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    return $raw ? json_decode($raw, true) : null;
}

function jwtDecode(string $token): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    $payload = $parts[1];
    // Fix base64url padding
    $payload = str_replace(['-', '_'], ['+', '/'], $payload);
    $payload = base64_decode(str_pad($payload, strlen($payload) + (4 - strlen($payload) % 4) % 4, '='));
    return $payload ? json_decode($payload, true) : null;
}
