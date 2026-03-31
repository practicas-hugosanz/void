<?php
/**
 * VOID API — /api/proxy.php
 * Funciona con o sin base de datos (DATABASE_URL).
 * Sin BD/sesión: acepta API key desde el header X-Api-Key.
 */

// ─── Cargar helpers (json_ok, json_err, body, cors, resolve_session…) ────────
require_once __DIR__ . '/../includes/auth.php';

// ─── Capturar errores fatales y devolverlos como JSON ────────────────────────
set_exception_handler(function(Throwable $e) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['ok' => false, 'error' => 'Error interno: ' . $e->getMessage()]);
    exit;
});

cors();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('Método no permitido', 405);

// ─── Obtener API key ──────────────────────────────────────────────────────────
// Prioridad: 1) Variables de entorno Railway  2) Preferencias BD usuario  3) Header X-Api-Key
$apiKey     = '';
$dbProvider = 'gemini';
$dbModel    = '';

// 1) Variables de entorno del servidor (Railway) — máxima prioridad
$envGemini    = trim((string) getenv('GEMINI_API_KEY'));
$envOpenAI    = trim((string) getenv('OPENAI_API_KEY'));
$envAnthropic = trim((string) getenv('ANTHROPIC_API_KEY'));

// Determinar proveedor por defecto según qué variable de entorno está configurada
if ($envGemini)        { $dbProvider = 'gemini';    }
elseif ($envOpenAI)    { $dbProvider = 'openai';    }
elseif ($envAnthropic) { $dbProvider = 'anthropic'; }

// 2) Si hay BD, leer preferencias del usuario (proveedor/modelo), pero NO su api_key personal
if (getenv('DATABASE_URL')) {
    try {
        $dbUser = resolve_session();
        if ($dbUser) {
            $db  = get_db();
            $row = $db->prepare("SELECT api_provider, api_model FROM users WHERE id = ?");
            $row->execute([$dbUser['id']]);
            $s = $row->fetch() ?: [];
            if (!empty($s['api_provider'])) $dbProvider = trim($s['api_provider']) ?: $dbProvider;
            if (!empty($s['api_model']))    $dbModel    = trim($s['api_model']);
        }
    } catch (Throwable $e) {
        // BD no disponible — continuar sin ella
    }
}

$provider = trim(body()['provider'] ?? $dbProvider);
$model    = trim(body()['model']    ?? $dbModel);

// Asignar la key del servidor según el proveedor seleccionado
if ($provider === 'openai')        $apiKey = $envOpenAI;
elseif ($provider === 'anthropic') $apiKey = $envAnthropic;
else                               $apiKey = $envGemini;

// 3) Fallback: header X-Api-Key del cliente (solo si no hay key de servidor)
if (!$apiKey) {
    $apiKey = trim($_SERVER['HTTP_X_API_KEY'] ?? '');
}

// ─── Acción: generar título ───────────────────────────────────────────────────
if ((body()['action'] ?? '') === 'title') {
    if (!$apiKey) json_err('El servicio no está configurado. Contacta al administrador.', 503);
    $messages = body()['messages'] ?? [];
    if (empty($messages)) json_err('Sin mensajes para generar título');

    $excerpt = '';
    foreach (array_slice($messages, 0, 4) as $m) {
        $role    = $m['role'] === 'assistant' ? 'Asistente' : 'Usuario';
        $content = is_string($m['content']) ? $m['content'] : '';
        $excerpt .= "$role: " . mb_substr($content, 0, 200) . "\n";
    }
    $dm = ['gemini'=>'gemini-2.5-flash','openai'=>'gpt-4o','anthropic'=>'claude-haiku-4-5-20251001'];
    if (!$model) $model = $dm[$provider] ?? 'gemini-2.5-flash';

    $titlePrompt = [['role'=>'user','content'=>
        "Genera un título MUY corto (3-5 palabras) para esta conversación. ".
        "Solo devuelve el título, sin comillas ni puntuación final.\n\n$excerpt"]];

    if ($provider === 'openai')        $title = call_openai($apiKey, $titlePrompt, $model);
    elseif ($provider === 'anthropic') $title = call_anthropic($apiKey, $titlePrompt, $model);
    else                               $title = call_gemini($apiKey, $titlePrompt, $model);

    $title = trim(preg_replace('/^["\'«»]+|["\'»«]+$/', '', trim((string)$title)));
    if (!$title) $title = 'Conversación';
    json_ok(['title' => mb_substr($title, 0, 60)]);
}

// ─── Chat normal ──────────────────────────────────────────────────────────────
$messages = body()['messages'] ?? [];
$doStream = body()['stream']   ?? true;

if (!$apiKey)         json_err('El servicio no está configurado. Contacta al administrador.', 503);
if (empty($messages)) json_err('Sin mensajes', 400);


$dm = ['gemini'=>'gemini-2.5-flash','openai'=>'gpt-4o','anthropic'=>'claude-sonnet-4-6'];
if (!$model) $model = $dm[$provider] ?? 'gemini-2.5-flash';

if ($doStream) {
    while (ob_get_level()) ob_end_clean();
    ini_set('output_buffering', 'off');
    ini_set('zlib.output_compression', false);
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    header('X-VOID-Provider: ' . $provider);
    header('X-VOID-Model: ' . $model);
    header('X-VOID-HasKey: ' . ($apiKey ? 'yes' : 'NO'));

    if ($provider === 'openai')        stream_openai($apiKey, $messages, $model);
    elseif ($provider === 'anthropic') stream_anthropic($apiKey, $messages, $model);
    else                               stream_gemini($apiKey, $messages, $model);
    exit;
}

if ($provider === 'openai')        $text = call_openai($apiKey, $messages, $model);
elseif ($provider === 'anthropic') $text = call_anthropic($apiKey, $messages, $model);
else                               $text = call_gemini($apiKey, $messages, $model);
json_ok(['text' => $text]);


// ═══════════════════════════════════════════════════════════════════════════════
// SSE helpers
// ═══════════════════════════════════════════════════════════════════════════════
function sse_chunk(string $text): void { echo 'data: '.json_encode(['chunk'=>$text])."\n\n"; flush(); }
function sse_done(): void              { echo "data: [DONE]\n\n"; flush(); }
function sse_error(string $msg): void  { echo 'data: '.json_encode(['error'=>$msg])."\n\n"; sse_done(); }


// ═══════════════════════════════════════════════════════════════════════════════
// Helpers de conversión de mensajes multipart
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Convierte mensajes del formato genérico (OpenAI-like) al formato nativo de Anthropic.
 * - image_url con data URI  →  {"type":"image","source":{"type":"base64",...}}
 * - content string          →  sin cambios (string sigue siendo válido en Anthropic)
 */
function anthropic_convert_messages(array $messages): array {
    $history = [];
    foreach ($messages as $m) {
        if ($m['role'] === 'system') continue; // system se maneja aparte
        $content = $m['content'];
        if (is_array($content)) {
            $parts = [];
            foreach ($content as $part) {
                $type = $part['type'] ?? '';
                if ($type === 'text') {
                    $parts[] = ['type' => 'text', 'text' => $part['text'] ?? ''];
                } elseif ($type === 'image_url') {
                    $url = $part['image_url']['url'] ?? '';
                    if (str_starts_with($url, 'data:')) {
                        [$meta, $b64] = explode(',', $url, 2);
                        preg_match('/data:([^;]+)/', $meta, $mt);
                        $mime = $mt[1] ?? 'image/jpeg';
                        $parts[] = [
                            'type'   => 'image',
                            'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $b64],
                        ];
                    }
                }
                // tipo desconocido: ignorar
            }
            if (!empty($parts)) {
                $history[] = ['role' => $m['role'], 'content' => $parts];
                continue;
            }
        }
        $history[] = ['role' => $m['role'], 'content' => $content];
    }
    return $history;
}

/**
 * Normaliza mensajes para OpenAI.
 * OpenAI acepta el formato image_url nativamente, solo nos aseguramos
 * de que el content sea array de parts cuando corresponde y que esté bien formado.
 */
function openai_convert_messages(array $messages): array {
    $out = [];
    foreach ($messages as $m) {
        $content = $m['content'];
        if (is_array($content)) {
            $parts = [];
            foreach ($content as $part) {
                $type = $part['type'] ?? '';
                if ($type === 'text') {
                    $parts[] = ['type' => 'text', 'text' => $part['text'] ?? ''];
                } elseif ($type === 'image_url') {
                    $parts[] = [
                        'type'      => 'image_url',
                        'image_url' => ['url' => $part['image_url']['url'] ?? '', 'detail' => $part['image_url']['detail'] ?? 'auto'],
                    ];
                }
            }
            if (!empty($parts)) {
                $out[] = ['role' => $m['role'], 'content' => $parts];
                continue;
            }
        }
        $out[] = $m;
    }
    return $out;
}

// ═══════════════════════════════════════════════════════════════════════════════
// OpenAI
// ═══════════════════════════════════════════════════════════════════════════════
function stream_openai(string $key, array $messages, string $model): void {
    $messages = openai_convert_messages($messages);
    $sentDone = false; $sentChunk = false; $rawBuf = '';
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST          => true,
        CURLOPT_POSTFIELDS    => json_encode(['model'=>$model,'messages'=>$messages,'stream'=>true]),
        CURLOPT_HTTPHEADER    => ['Content-Type: application/json','Authorization: Bearer '.$key],
        CURLOPT_TIMEOUT       => 120,
        CURLOPT_WRITEFUNCTION => function($ch, $data) use (&$sentDone, &$sentChunk, &$rawBuf) {
            $rawBuf .= $data;
            foreach (explode("\n", $data) as $line) {
                $line = trim($line);
                if (!str_starts_with($line, 'data: ')) continue;
                $json = substr($line, 6);
                if ($json === '[DONE]') { sse_done(); $sentDone = true; return strlen($data); }
                $obj = json_decode($json, true);
                if (!empty($obj['error'])) {
                    $msg = $obj['error']['message'] ?? 'Error de OpenAI';
                    sse_error($msg); return strlen($data);
                }
                $chunk = $obj['choices'][0]['delta']['content'] ?? '';
                if ($chunk !== '') { sse_chunk($chunk); $sentChunk = true; }
            }
            return strlen($data);
        },
    ]);
    $ok = curl_exec($ch); $err = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!$ok || $err) { sse_error('Error de red con OpenAI: '.($err?:'sin respuesta')); return; }
    if ($httpCode >= 400 && !$sentChunk) {
        $errBody = json_decode($rawBuf, true);
        $msg = $errBody['error']['message'] ?? 'Error HTTP '.$httpCode.' de OpenAI';
        if ($httpCode === 401) $msg = 'API Key de OpenAI inválida.';
        if ($httpCode === 429) $msg = 'Cuota de OpenAI superada.';
        sse_error($msg); return;
    }
    if (!$sentDone) sse_done();
}

function call_openai(string $key, array $messages, string $model = 'gpt-4o'): string {
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS     => json_encode(['model'=>$model,'messages'=>$messages]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json','Authorization: Bearer '.$key],
        CURLOPT_TIMEOUT        => 60,
    ]);
    $raw = curl_exec($ch); $err = curl_error($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($err || !$raw) json_err('Error de red con OpenAI: '.($err?:''), 502);
    $res = json_decode($raw, true);
    if (!empty($res['error'])) {
        $c = $res['error']['code'] ?? '';
        if ($code === 401 || $c === 'invalid_api_key') json_err('API Key de OpenAI inválida.', 401);
        if ($code === 429) json_err('Cuota de OpenAI superada.', 429);
        json_err('OpenAI: '.($res['error']['message']??''), 502);
    }
    return $res['choices'][0]['message']['content'] ?? '';
}


// ═══════════════════════════════════════════════════════════════════════════════
// Anthropic
// ═══════════════════════════════════════════════════════════════════════════════
function stream_anthropic(string $key, array $messages, string $model): void {
    // Extraer system prompt y convertir mensajes al formato nativo de Anthropic
    $system  = '';
    foreach ($messages as $m) {
        if ($m['role'] === 'system') { $system = is_string($m['content']) ? $m['content'] : ''; break; }
    }
    $history = anthropic_convert_messages($messages);
    $body = ['model'=>$model,'max_tokens'=>4096,'stream'=>true,'messages'=>$history];
    if ($system) $body['system'] = $system;

    $sentDone = false;
    $rawBuf   = '';

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST          => true,
        CURLOPT_POSTFIELDS    => json_encode($body),
        CURLOPT_HTTPHEADER    => ['Content-Type: application/json','x-api-key: '.$key,'anthropic-version: 2023-06-01'],
        CURLOPT_TIMEOUT       => 120,
        CURLOPT_WRITEFUNCTION => function($ch, $data) use (&$sentDone, &$rawBuf) {
            $rawBuf .= $data;
            foreach (explode("\n", $data) as $line) {
                $line = trim($line);
                if (!str_starts_with($line, 'data: ')) continue;
                $obj = json_decode(substr($line, 6), true);
                if (!$obj) continue;
                // Error devuelto dentro del stream (p.ej. model not found, overloaded)
                if (($obj['type']??'') === 'error') {
                    $msg = $obj['error']['message'] ?? 'Error de Anthropic';
                    sse_error($msg); $sentDone = true; return strlen($data);
                }
                if (($obj['type']??'') === 'content_block_delta') {
                    $chunk = $obj['delta']['text'] ?? '';
                    if ($chunk !== '') sse_chunk($chunk);
                }
                if (($obj['type']??'') === 'message_stop') {
                    sse_done(); $sentDone = true;
                }
            }
            return strlen($data);
        },
    ]);
    $ok = curl_exec($ch); $err = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$ok || $err) { sse_error('Error de red con Anthropic: '.($err?:'sin respuesta')); return; }
    if ($httpCode >= 400 && !$sentDone) {
        $errBody = json_decode($rawBuf, true);
        $msg = $errBody['error']['message'] ?? 'Error HTTP '.$httpCode.' de Anthropic';
        if ($httpCode === 401) $msg = 'API Key de Anthropic inválida.';
        if ($httpCode === 429) $msg = 'Límite de uso de Anthropic superado.';
        sse_error($msg); return;
    }
    if (!$sentDone) sse_done();
}

function call_anthropic(string $key, array $messages, string $model = 'claude-sonnet-4-6'): string {
    $system  = '';
    foreach ($messages as $m) {
        if ($m['role'] === 'system') { $system = is_string($m['content']) ? $m['content'] : ''; break; }
    }
    $history = anthropic_convert_messages($messages);
    $body = ['model'=>$model,'max_tokens'=>4096,'messages'=>$history];
    if ($system) $body['system'] = $system;
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json','x-api-key: '.$key,'anthropic-version: 2023-06-01'],
        CURLOPT_TIMEOUT        => 60,
    ]);
    $raw = curl_exec($ch); $err = curl_error($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($err || !$raw) json_err('Error de red con Anthropic: '.($err ?: ''), 502);
    $res = json_decode($raw, true);
    if (!empty($res['error'])) {
        if ($code === 401) json_err('API Key de Anthropic inválida.', 401);
        if ($code === 429) json_err('Límite de uso de Anthropic superado.', 429);
        json_err('Anthropic: '.($res['error']['message'] ?? 'error desconocido'), 502);
    }
    return $res['content'][0]['text'] ?? '';
}


// ═══════════════════════════════════════════════════════════════════════════════
// Gemini
// ═══════════════════════════════════════════════════════════════════════════════
// Lista de modelos Gemini en orden de prioridad para fallback automático
function gemini_fallback_models(string $preferredModel): array {
    $all = ['gemini-2.5-flash', 'gemini-2.0-flash', 'gemini-2.0-flash-lite'];
    $ordered = [$preferredModel];
    foreach ($all as $m) {
        if ($m !== $preferredModel) $ordered[] = $m;
    }
    return $ordered;
}

// Construye los 'contents' y 'systemInstruction' a partir de los mensajes
function gemini_build_contents(array $messages): array {
    $contents = []; $systemInstruction = null;
    foreach ($messages as $msg) {
        $role = $msg['role']; $rawContent = $msg['content'];
        if (is_string($rawContent)) {
            $parts = [['text' => $rawContent]];
        } else {
            $parts = [];
            foreach ($rawContent as $part) {
                if (($part['type']??'') === 'text') {
                    $parts[] = ['text' => $part['text']];
                } elseif (($part['type']??'') === 'image_url') {
                    $url = $part['image_url']['url'] ?? '';
                    if (str_starts_with($url, 'data:')) {
                        [$meta, $b64] = explode(',', $url, 2);
                        preg_match('/data:([^;]+)/', $meta, $m2);
                        $parts[] = ['inlineData'=>['mimeType'=>$m2[1]??'image/jpeg','data'=>$b64]];
                    }
                }
            }
        }
        if ($role === 'system') { $systemInstruction = ['parts'=>$parts]; continue; }
        $contents[] = ['role'=>$role==='assistant'?'model':'user','parts'=>$parts];
    }
    return ['contents' => $contents, 'systemInstruction' => $systemInstruction];
}

function stream_gemini(string $key, array $messages, string $model): void {
    $built = gemini_build_contents($messages);
    $contents          = $built['contents'];
    $systemInstruction = $built['systemInstruction'];

    $body = ['contents' => $contents];
    if ($systemInstruction) $body['systemInstruction'] = $systemInstruction;

    $modelsToTry = gemini_fallback_models($model);

    foreach ($modelsToTry as $tryModel) {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
             . urlencode($tryModel).':streamGenerateContent?alt=sse&key='.urlencode($key);
        $lineBuf = ''; $rawBuf = ''; $sentChunk = false; $hit429 = false;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST          => true,
            CURLOPT_POSTFIELDS    => json_encode($body),
            CURLOPT_HTTPHEADER    => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT       => 120,
            CURLOPT_WRITEFUNCTION => function($ch, $data) use (&$lineBuf, &$rawBuf, &$sentChunk, &$hit429) {
                $rawBuf  .= $data;
                $lineBuf .= $data;
                while (($pos = strpos($lineBuf, "\n")) !== false) {
                    $line    = trim(substr($lineBuf, 0, $pos));
                    $lineBuf = substr($lineBuf, $pos + 1);
                    if (!str_starts_with($line, 'data: ')) {
                        if (str_starts_with($line, '{')) {
                            $plain = json_decode($line, true);
                            if (!empty($plain['error'])) {
                                $code = (int)($plain['error']['code'] ?? 0);
                                if ($code === 429) { $hit429 = true; return strlen($data); }
                                if ($code === 400) { sse_error('Modelo no disponible o solicitud inválida.'); return strlen($data); }
                                if ($code === 401 || $code === 403) { sse_error('API Key de Gemini inválida o sin permisos.'); return strlen($data); }
                                sse_error($plain['error']['message'] ?? 'Error de Gemini');
                            }
                        }
                        continue;
                    }
                    $obj = json_decode(substr($line, 6), true);
                    if (!empty($obj['error'])) {
                        $code = (int)($obj['error']['code'] ?? 0);
                        if ($code === 429) { $hit429 = true; return strlen($data); }
                        if ($code === 401 || $code === 403) { sse_error('API Key de Gemini inválida o sin permisos.'); return strlen($data); }
                        sse_error($obj['error']['message'] ?? 'Error de Gemini'); return strlen($data);
                    }
                    $chunk = $obj['candidates'][0]['content']['parts'][0]['text'] ?? '';
                    if ($chunk !== '') { sse_chunk($chunk); $sentChunk = true; }
                }
                return strlen($data);
            },
        ]);
        $ok = curl_exec($ch); $err = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$ok || $err) { sse_error('Error de red con Gemini: '.($err?:'sin respuesta')); return; }

        // Si fue 429 (en header HTTP o detectado en stream), probar siguiente modelo
        if ($httpCode === 429 || $hit429) continue;

        if ($httpCode >= 400 && !$sentChunk) {
            $errBody = json_decode($rawBuf, true);
            if ($httpCode === 400) { sse_error('Modelo no disponible o solicitud inválida.'); return; }
            if ($httpCode === 401 || $httpCode === 403) { sse_error('API Key de Gemini inválida o sin permisos.'); return; }
            sse_error($errBody['error']['message'] ?? 'Error HTTP '.$httpCode.' de Gemini'); return;
        }

        // Éxito
        sse_done(); return;
    }

    // Todos los modelos agotaron su cuota
    sse_error('Límite diario de Gemini alcanzado en todos los modelos. Inténtalo mañana.');
}

function call_gemini(string $key, array $messages, string $model = 'gemini-2.5-flash'): string {
    $modelsToTry = gemini_fallback_models($model);

    foreach ($modelsToTry as $tryModel) {
        $contents = []; $sys = null;
        foreach ($messages as $msg) {
            $role = $msg['role']; $text = is_string($msg['content']) ? $msg['content'] : '';
            if ($role === 'system') { $sys = ['parts'=>[['text'=>$text]]]; continue; }
            $contents[] = ['role'=>$role==='assistant'?'model':'user','parts'=>[['text'=>$text]]];
        }
        $body = ['contents'=>$contents];
        if ($sys) $body['systemInstruction'] = $sys;

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
             . urlencode($tryModel).':generateContent?key='.urlencode($key);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 60,
        ]);
        $raw = curl_exec($ch); $err = curl_error($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($err || !$raw) json_err('Error de red con Gemini: '.($err?:''), 502);
        $res = json_decode($raw, true);
        if (!empty($res['error'])) {
            $st = (int)($res['error']['code'] ?? $code);
            if ($st === 429) continue; // Intentar siguiente modelo
            if ($st === 400) json_err('Gemini: key inválida o solicitud incorrecta.', 400);
            if ($st === 403) json_err('Gemini: API Key sin permisos.', 403);
            json_err('Gemini: '.($res['error']['message']??'error desconocido'), 502);
        }
        return $res['candidates'][0]['content']['parts'][0]['text'] ?? '';
    }

    json_err('Límite diario de Gemini alcanzado en todos los modelos. Inténtalo mañana.', 429);
}


