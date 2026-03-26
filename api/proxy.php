<?php
/**
 * VOID API — /api/proxy.php
 *
 * Proxies AI requests to Gemini / OpenAI using the API key stored
 * server-side for the authenticated user. The key is NEVER sent to
 * the browser.
 *
 * POST /api/proxy.php
 * Body: { messages: [...], provider?: 'gemini'|'openai', model?: string }
 * Returns: { ok: true, data: { text: "..." } }
 */

require_once __DIR__ . '/../includes/auth.php';
cors();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('Método no permitido', 405);

$user = require_auth();
$db   = get_db();

// Fetch stored API key & provider for this user
$row = $db->prepare("SELECT api_key, api_provider FROM users WHERE id = ?");
$row->execute([$user['id']]);
$settings = $row->fetch();

$apiKey   = $settings['api_key']      ?? '';
$provider = body()['provider']        ?? ($settings['api_provider'] ?? 'gemini');
$model    = body()['model']           ?? ($settings['api_model']    ?? '');
$messages = body()['messages']        ?? [];

if (!$apiKey) json_err('No tienes una API Key configurada. Añádela en Ajustes.', 402);
if (empty($messages)) json_err('Sin mensajes');

// ─── Route to provider ────────────────────────────────────────────────────────

// Fallback to default model if not specified
$defaultModels = ['gemini' => 'gemini-2.0-flash', 'openai' => 'gpt-4o'];
if (!$model) $model = $defaultModels[$provider] ?? 'gpt-4o';

if ($provider === 'openai') {
    $responseText = call_openai($apiKey, $messages, $model);
} else {
    $responseText = call_gemini($apiKey, $messages, $model);
}

json_ok(['text' => $responseText]);

// ─── OpenAI ──────────────────────────────────────────────────────────────────

function call_openai(string $key, array $messages, string $model = 'gpt-4o'): string {
    $payload = json_encode([
        'model'    => $model,
        'messages' => $messages,
    ]);

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $key,
        ],
        CURLOPT_TIMEOUT        => 60,
    ]);

    $raw      = curl_exec($ch);
    $curlErr  = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlErr || $raw === false) {
        json_err('Error de red al conectar con OpenAI: ' . ($curlErr ?: 'sin respuesta'), 502);
    }

    $res = json_decode($raw, true);

    // API-level errors (invalid key, quota, etc.)
    if (!empty($res['error'])) {
        $msg  = $res['error']['message'] ?? 'Error desconocido de OpenAI';
        $code = $res['error']['code']    ?? '';
        if ($httpCode === 401 || $code === 'invalid_api_key')
            json_err('API Key de OpenAI inválida. Compruébala en Ajustes.', 401);
        if ($httpCode === 429 || $code === 'rate_limit_exceeded' || $code === 'insufficient_quota')
            json_err('Has superado el límite o no tienes créditos en tu cuenta de OpenAI.', 429);
        json_err('OpenAI (' . $httpCode . '): ' . $msg, 502);
    }

    if (!is_array($res) || $httpCode !== 200) {
        json_err('Respuesta inesperada de OpenAI (HTTP ' . $httpCode . '). Inténtalo de nuevo.', 502);
    }

    return $res['choices'][0]['message']['content'] ?? '';
}

// ─── Google Gemini ────────────────────────────────────────────────────────────

function call_gemini(string $key, array $messages, string $model = 'gemini-2.0-flash'): string {
    // Convert OpenAI-style messages to Gemini contents
    $contents = [];
    $systemInstruction = null;

    foreach ($messages as $msg) {
        $role = $msg['role'];
        $text = is_string($msg['content']) ? $msg['content'] : '';

        if ($role === 'system') {
            $systemInstruction = ['parts' => [['text' => $text]]];
            continue;
        }

        $contents[] = [
            'role'  => $role === 'assistant' ? 'model' : 'user',
            'parts' => [['text' => $text]],
        ];
    }

    $body = ['contents' => $contents];
    if ($systemInstruction) $body['systemInstruction'] = $systemInstruction;

    $url     = 'https://generativelanguage.googleapis.com/v1beta/models/' . urlencode($model) . ':generateContent?key=' . urlencode($key);
    $payload = json_encode($body);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 60,
    ]);

    $raw      = curl_exec($ch);
    $curlErr  = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlErr || $raw === false) {
        json_err('Error de red al conectar con Gemini: ' . ($curlErr ?: 'sin respuesta'), 502);
    }

    $res = json_decode($raw, true);

    if (!empty($res['error'])) {
        $msg    = $res['error']['message'] ?? 'Error desconocido de Gemini';
        $status = $res['error']['code']    ?? $httpCode;
        if ($status === 400) json_err('API Key inválida o solicitud incorrecta: ' . $msg, 400);
        if ($status === 403) json_err('API Key sin permisos para Gemini. Comprueba que esté activada en Google AI Studio.', 403);
        if ($status === 429) json_err('Has superado el límite de peticiones de tu API Key de Gemini. Espera un momento.', 429);
        json_err('Gemini (' . $status . '): ' . $msg, 502);
    }

    if (!is_array($res) || $httpCode !== 200) {
        json_err('Respuesta inesperada de Gemini (HTTP ' . $httpCode . '). Inténtalo de nuevo.', 502);
    }

    return $res['candidates'][0]['content']['parts'][0]['text'] ?? '';
}
