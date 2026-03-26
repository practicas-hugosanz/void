<?php
/**
 * VOID — Auth Helpers
 */

require_once __DIR__ . '/db.php';

const SESSION_COOKIE = 'void_token';
const SESSION_TTL    = 60 * 60 * 24 * 30; // 30 days

// ─── JSON helpers ────────────────────────────────────────────────────────────

function json_ok(mixed $data = null): never {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'data' => $data]);
    exit;
}

function json_err(string $message, int $status = 400): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

function body(): array {
    static $parsed = null;
    if ($parsed !== null) return $parsed;
    $raw = file_get_contents('php://input');
    $parsed = json_decode($raw ?: '{}', true) ?: [];
    return $parsed;
}

function require_field(string $key, string $label = ''): mixed {
    $val = body()[$key] ?? null;
    if ($val === null || $val === '') json_err(($label ?: $key) . ' es obligatorio');
    return $val;
}

// ─── CORS ─────────────────────────────────────────────────────────────────────

function cors(): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
}

// ─── Session ─────────────────────────────────────────────────────────────────

function create_session(int $user_id): string {
    $token = bin2hex(random_bytes(32));
    $db = get_db();
    $db->prepare("INSERT INTO sessions (token, user_id) VALUES (?, ?)")
       ->execute([$token, $user_id]);
    // Optionally set an HttpOnly cookie
    setcookie(SESSION_COOKIE, $token, [
        'expires'  => time() + SESSION_TTL,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    return $token;
}

function resolve_session(): ?array {
    // Token from cookie OR Authorization: Bearer <token> header
    $token = $_COOKIE[SESSION_COOKIE] ?? null;
    if (!$token) {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (str_starts_with($header, 'Bearer ')) {
            $token = substr($header, 7);
        }
    }
    if (!$token) return null;

    $db = get_db();
    $stmt = $db->prepare("
        SELECT u.id, u.name, u.email, u.avatar, u.api_key, u.api_provider
        FROM sessions s JOIN users u ON u.id = s.user_id
        WHERE s.token = ? AND datetime(s.last_seen, '+30 days') > datetime('now')
    ");
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    if (!$user) return null;

    // Refresh last_seen periodically (every hour)
    $db->prepare("UPDATE sessions SET last_seen = datetime('now') WHERE token = ?")
       ->execute([$token]);
    return $user;
}

function require_auth(): array {
    $user = resolve_session();
    if (!$user) json_err('No autenticado', 401);
    return $user;
}

function destroy_session(): void {
    $token = $_COOKIE[SESSION_COOKIE] ?? null;
    if (!$token) return;
    get_db()->prepare("DELETE FROM sessions WHERE token = ?")->execute([$token]);
    setcookie(SESSION_COOKIE, '', ['expires' => 1, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
}
