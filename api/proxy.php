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
$messages = body()['messages']        ?? [];

if (!$apiKey) json_err('No tienes una API Key configurada. Añádela en Ajustes.', 402);
if (empty($messages)) json_err('Sin mensajes');

// ─── Route to provider ───────────────────────────────────────────────────────

if ($provider === 'openai') {
    $responseText = call_openai($apiKey, $messages);
} else {
    $responseText = call_gemini($apiKey, $messages);
}

json_ok(['text' => $responseText]);

// ─── OpenAI ──────────────────────────────────────────────────────────────────

function call_openai(string $key, array $messages): string {
    $payload = json_encode([
        'model'    => 'gpt-4o',
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

    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) json_err('Error de red (OpenAI): ' . $err, 502);

    $res = json_decode($raw, true);
    if (!empty($res['error'])) json_err('OpenAI: ' . $res['error']['message'], 502);

    return $res['choices'][0]['message']['content'] ?? '';
}

// ─── Google Gemini ───────────────────────────────────────────────────────────

function call_gemini(string $key, array $messages): string {
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

    $url     = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . urlencode($key);
    $payload = json_encode($body);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 60,
    ]);

    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) json_err('Error de red (Gemini): ' . $err, 502);

    $res = json_decode($raw, true);
    if (!empty($res['error'])) json_err('Gemini: ' . $res['error']['message'], 502);

    return $res['candidates'][0]['content']['parts'][0]['text'] ?? '';
}
