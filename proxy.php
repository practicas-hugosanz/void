<?php
/**
 * VOID API — /api/proxy.php
 *
 * Proxies AI requests to Gemini / OpenAI / Anthropic using the API key stored
 * server-side for the authenticated user. The key is NEVER sent to the browser.
 *
 * POST /api/proxy.php
 * Body: { messages: [...], provider?: 'gemini'|'openai'|'anthropic', model?: string, stream?: bool }
 *
 * When stream=true (default) → Server-Sent Events: data: {"chunk":"..."}\n\n
 *                               Terminated by:      data: [DONE]\n\n
 * When stream=false           → { ok: true, data: { text: "..." } }
 */

require_once __DIR__ . '/../includes/auth.php';
cors();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('Método no permitido', 405);

$user = require_auth();
$db   = get_db();

$row = $db->prepare("SELECT api_key, api_provider, api_model FROM users WHERE id = ?");
$row->execute([$user['id']]);
$settings = $row->fetch();

// Accept key from server DB, or from client header (direct/guest mode)
$apiKey   = $settings['api_key'] ?: ($_SERVER['HTTP_X_API_KEY'] ?? '');
$provider = body()['provider']     ?? ($settings['api_provider'] ?? 'gemini');
$model    = body()['model']        ?? ($settings['api_model']    ?? '');

// ─── Acción especial: generar título ──────────────────────────────────────────
if ((body()['action'] ?? '') === 'title') {
    if (!$apiKey) json_err('No tienes una API Key configurada.', 402);
    $messages = body()['messages'] ?? [];
    if (empty($messages)) json_err('Sin mensajes para generar título');

    // Construir un prompt compacto con los primeros intercambios
    $excerpt = '';
    foreach (array_slice($messages, 0, 4) as $m) {
        $role    = $m['role'] === 'assistant' ? 'Asistente' : 'Usuario';
        $content = is_string($m['content']) ? $m['content'] : '';
        $excerpt .= "$role: " . mb_substr($content, 0, 200) . "\n";
    }

    $defaultModels = [
        'gemini'    => 'gemini-2.0-flash',
        'openai'    => 'gpt-4o',
        'anthropic' => 'claude-haiku-4-5',
    ];
    if (!$model) $model = $defaultModels[$provider] ?? 'gemini-2.0-flash';

    $titlePrompt = [['role' => 'user', 'content' =>
        "Genera un título MUY corto (3-5 palabras) para esta conversación. " .
        "Solo devuelve el título, sin comillas ni puntuación final.\n\n$excerpt"
    ]];

    if ($provider === 'openai')       $title = call_openai($apiKey, $titlePrompt, $model);
    elseif ($provider === 'anthropic') $title = call_anthropic($apiKey, $titlePrompt, $model);
    else                               $title = call_gemini($apiKey, $titlePrompt, $model);

    $title = trim(preg_replace('/^["\'«»]+|["\'»«]+$/', '', trim($title)));
    if (!$title) $title = 'Conversación';
    json_ok(['title' => mb_substr($title, 0, 60)]);
}
$messages = body()['messages']     ?? [];
$doStream = body()['stream']       ?? true;

if (!$apiKey)        json_err('No tienes una API Key configurada. Añádela en Ajustes o pásala en X-Api-Key.', 402);
if (empty($messages)) json_err('Sin mensajes');

$defaultModels = [
    'gemini'    => 'gemini-2.0-flash',
    'openai'    => 'gpt-4o',
    'anthropic' => 'claude-sonnet-4-5',
];
if (!$model) $model = $defaultModels[$provider] ?? 'gpt-4o';

// ─── Streaming path ───────────────────────────────────────────────────────────

if ($doStream) {
    while (ob_get_level()) ob_end_clean();
    ini_set('output_buffering', 'off');
    ini_set('zlib.output_compression', false);
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');

    if ($provider === 'openai')      stream_openai($apiKey, $messages, $model);
    elseif ($provider === 'anthropic') stream_anthropic($apiKey, $messages, $model);
    else                               stream_gemini($apiKey, $messages, $model);
    exit;
}

// ─── Non-streaming fallback ───────────────────────────────────────────────────

if ($provider === 'openai')      $text = call_openai($apiKey, $messages, $model);
elseif ($provider === 'anthropic') $text = call_anthropic($apiKey, $messages, $model);
else                               $text = call_gemini($apiKey, $messages, $model);
json_ok(['text' => $text]);


// ─── SSE helpers ──────────────────────────────────────────────────────────────

function sse_chunk(string $text): void {
    echo 'data: ' . json_encode(['chunk' => $text]) . "\n\n";
    flush();
}
function sse_done(): void {
    echo "data: [DONE]\n\n";
    flush();
}
function sse_error(string $msg): void {
    echo 'data: ' . json_encode(['error' => $msg]) . "\n\n";
    sse_done();
}


// ─── OpenAI streaming ─────────────────────────────────────────────────────────

function stream_openai(string $key, array $messages, string $model): void {
    $payload = json_encode(['model' => $model, 'messages' => $messages, 'stream' => true]);

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST          => true,
        CURLOPT_POSTFIELDS    => $payload,
        CURLOPT_HTTPHEADER    => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
        CURLOPT_TIMEOUT       => 120,
        CURLOPT_WRITEFUNCTION => function($ch, $data) {
            foreach (explode("\n", $data) as $line) {
                $line = trim($line);
                if (!str_starts_with($line, 'data: ')) continue;
                $json = substr($line, 6);
                if ($json === '[DONE]') { sse_done(); return strlen($data); }
                $obj   = json_decode($json, true);
                $chunk = $obj['choices'][0]['delta']['content'] ?? '';
                if ($chunk !== '') sse_chunk($chunk);
            }
            return strlen($data);
        },
    ]);

    $ok  = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if (!$ok || $err) sse_error('Error de red con OpenAI: ' . ($err ?: 'sin respuesta'));
}


// ─── Anthropic (Claude) streaming ─────────────────────────────────────────────

function stream_anthropic(string $key, array $messages, string $model): void {
    $system  = '';
    $history = [];
    foreach ($messages as $m) {
        if ($m['role'] === 'system') { $system = is_string($m['content']) ? $m['content'] : ''; continue; }
        $history[] = ['role' => $m['role'], 'content' => $m['content']];
    }
    $body = ['model' => $model, 'max_tokens' => 4096, 'stream' => true, 'messages' => $history];
    if ($system) $body['system'] = $system;

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST          => true,
        CURLOPT_POSTFIELDS    => json_encode($body),
        CURLOPT_HTTPHEADER    => ['Content-Type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01'],
        CURLOPT_TIMEOUT       => 120,
        CURLOPT_WRITEFUNCTION => function($ch, $data) {
            foreach (explode("\n", $data) as $line) {
                $line = trim($line);
                if (!str_starts_with($line, 'data: ')) continue;
                $obj = json_decode(substr($line, 6), true);
                if (!$obj) continue;
                if (($obj['type'] ?? '') === 'content_block_delta') {
                    $chunk = $obj['delta']['text'] ?? '';
                    if ($chunk !== '') sse_chunk($chunk);
                }
                if (($obj['type'] ?? '') === 'message_stop') sse_done();
            }
            return strlen($data);
        },
    ]);

    $ok  = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if (!$ok || $err) sse_error('Error de red con Anthropic: ' . ($err ?: 'sin respuesta'));
}


// ─── Gemini streaming ─────────────────────────────────────────────────────────

function stream_gemini(string $key, array $messages, string $model): void {
    $contents = []; $systemInstruction = null;
    foreach ($messages as $msg) {
        $role       = $msg['role'];
        $rawContent = $msg['content'];
        if (is_string($rawContent)) {
            $parts = [['text' => $rawContent]];
        } else {
            $parts = [];
            foreach ($rawContent as $part) {
                if (($part['type'] ?? '') === 'text') {
                    $parts[] = ['text' => $part['text']];
                } elseif (($part['type'] ?? '') === 'image_url') {
                    $url = $part['image_url']['url'] ?? '';
                    if (str_starts_with($url, 'data:')) {
                        [$meta, $b64] = explode(',', $url, 2);
                        preg_match('/data:([^;]+)/', $meta, $m2);
                        $parts[] = ['inlineData' => ['mimeType' => $m2[1] ?? 'image/jpeg', 'data' => $b64]];
                    }
                }
            }
        }
        if ($role === 'system') { $systemInstruction = ['parts' => $parts]; continue; }
        $contents[] = ['role' => $role === 'assistant' ? 'model' : 'user', 'parts' => $parts];
    }
    $body = ['contents' => $contents];
    if ($systemInstruction) $body['systemInstruction'] = $systemInstruction;

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
         . urlencode($model) . ':streamGenerateContent?alt=sse&key=' . urlencode($key);

    $lineBuf = '';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST          => true,
        CURLOPT_POSTFIELDS    => json_encode($body),
        CURLOPT_HTTPHEADER    => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT       => 120,
        CURLOPT_WRITEFUNCTION => function($ch, $data) use (&$lineBuf) {
            $lineBuf .= $data;
            while (($pos = strpos($lineBuf, "\n")) !== false) {
                $line    = trim(substr($lineBuf, 0, $pos));
                $lineBuf = substr($lineBuf, $pos + 1);
                if (!str_starts_with($line, 'data: ')) continue;
                $obj   = json_decode(substr($line, 6), true);
                $chunk = $obj['candidates'][0]['content']['parts'][0]['text'] ?? '';
                if ($chunk !== '') sse_chunk($chunk);
            }
            return strlen($data);
        },
    ]);

    $ok  = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if (!$ok || $err) { sse_error('Error de red con Gemini: ' . ($err ?: 'sin respuesta')); return; }
    sse_done();
}


// ─── Non-streaming fallbacks ──────────────────────────────────────────────────

function call_openai(string $key, array $messages, string $model = 'gpt-4o'): string {
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS     => json_encode(['model' => $model, 'messages' => $messages]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
        CURLOPT_TIMEOUT        => 60,
    ]);
    $raw  = curl_exec($ch); $err = curl_error($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($err || !$raw) json_err('Error de red con OpenAI: ' . ($err ?: ''), 502);
    $res  = json_decode($raw, true);
    if (!empty($res['error'])) {
        $c = $res['error']['code'] ?? '';
        if ($code === 401 || $c === 'invalid_api_key') json_err('API Key de OpenAI inválida.', 401);
        if ($code === 429) json_err('Cuota de OpenAI superada.', 429);
        json_err('OpenAI: ' . ($res['error']['message'] ?? ''), 502);
    }
    return $res['choices'][0]['message']['content'] ?? '';
}

function call_anthropic(string $key, array $messages, string $model = 'claude-sonnet-4-5'): string {
    $system = ''; $history = [];
    foreach ($messages as $m) {
        if ($m['role'] === 'system') { $system = $m['content']; continue; }
        $history[] = $m;
    }
    $body = ['model' => $model, 'max_tokens' => 4096, 'messages' => $history];
    if ($system) $body['system'] = $system;
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01'],
        CURLOPT_TIMEOUT        => 60,
    ]);
    $raw = curl_exec($ch); curl_close($ch);
    $res = json_decode($raw, true);
    return $res['content'][0]['text'] ?? '';
}

function call_gemini(string $key, array $messages, string $model = 'gemini-2.0-flash'): string {
    $contents = []; $sys = null;
    foreach ($messages as $msg) {
        $role = $msg['role']; $text = is_string($msg['content']) ? $msg['content'] : '';
        if ($role === 'system') { $sys = ['parts' => [['text' => $text]]]; continue; }
        $contents[] = ['role' => $role === 'assistant' ? 'model' : 'user', 'parts' => [['text' => $text]]];
    }
    $body = ['contents' => $contents];
    if ($sys) $body['systemInstruction'] = $sys;
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . urlencode($model) . ':generateContent?key=' . urlencode($key);
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 60,
    ]);
    $raw = curl_exec($ch); $err = curl_error($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($err || !$raw) json_err('Error de red con Gemini: ' . ($err ?: ''), 502);
    $res = json_decode($raw, true);
    if (!empty($res['error'])) {
        $st = $res['error']['code'] ?? $code;
        if ($st === 400) json_err('Gemini: solicitud incorrecta o key inválida.', 400);
        if ($st === 403) json_err('Gemini: key sin permisos.', 403);
        if ($st === 429) json_err('Gemini: límite de peticiones superado.', 429);
        json_err('Gemini: ' . ($res['error']['message'] ?? ''), 502);
    }
    return $res['candidates'][0]['content']['parts'][0]['text'] ?? '';
}
