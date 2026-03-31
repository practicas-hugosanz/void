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

// ─── Extraer texto de documentos adjuntos (PDF, DOCX, XLSX) ──────────────────
$messages = extract_docs_from_messages($messages);

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
// OpenAI
// ═══════════════════════════════════════════════════════════════════════════════
function stream_openai(string $key, array $messages, string $model): void {
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
    $system = ''; $history = [];
    foreach ($messages as $m) {
        if ($m['role'] === 'system') { $system = is_string($m['content']) ? $m['content'] : ''; continue; }
        $history[] = ['role'=>$m['role'],'content'=>$m['content']];
    }
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
    $system = ''; $history = [];
    foreach ($messages as $m) {
        if ($m['role'] === 'system') { $system = $m['content']; continue; }
        $history[] = $m;
    }
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


// ═══════════════════════════════════════════════════════════════════════════════
// Extracción de documentos (PDF, DOCX, XLSX)
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Recorre los mensajes buscando partes de tipo 'doc_extract',
 * extrae el texto y las convierte a partes de tipo 'text'.
 */
function extract_docs_from_messages(array $messages): array {
    foreach ($messages as &$msg) {
        if (!is_array($msg['content'])) continue;
        $newParts = [];
        foreach ($msg['content'] as $part) {
            if (($part['type'] ?? '') !== 'doc_extract') {
                $newParts[] = $part;
                continue;
            }
            $name     = $part['name']     ?? 'documento';
            $mime     = $part['mimeType'] ?? '';
            $b64      = $part['base64']   ?? '';
            if (!$b64) { $newParts[] = ['type'=>'text','text'=>"[{$name}: no se pudo leer]"]; continue; }

            $extracted = extract_document_text($b64, $mime, $name);
            $newParts[] = ['type' => 'text', 'text' => "\n[Documento adjunto: {$name}]\n{$extracted}"];
        }
        $msg['content'] = $newParts;
    }
    unset($msg);
    return $messages;
}

/**
 * Extrae texto de un documento codificado en base64.
 * Estrategia por tipo:
 *   PDF  → pdftotext (poppler) si está disponible, si no extracción PHP pura
 *   DOCX → pandoc si está disponible, si no descomprime XML interno
 *   XLSX → lee XML interno de la hoja
 *   Resto → intenta leer como UTF-8
 */
function extract_document_text(string $b64, string $mime, string $filename): string {
    $bytes = base64_decode($b64, true);
    if ($bytes === false) return '[Error: base64 inválido]';

    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $isPdf  = $ext === 'pdf'  || str_contains($mime, 'pdf');
    $isDocx = $ext === 'docx' || str_contains($mime, 'wordprocessingml');
    $isDoc  = $ext === 'doc'  || $mime === 'application/msword';
    $isXlsx = in_array($ext, ['xlsx','xls']) || str_contains($mime, 'spreadsheetml') || str_contains($mime, 'ms-excel');

    $tmp = tempnam(sys_get_temp_dir(), 'void_doc_');
    file_put_contents($tmp, $bytes);

    try {
        if ($isPdf)  return extract_pdf($tmp, $filename);
        if ($isDocx) return extract_docx($tmp, $filename);
        if ($isDoc)  return extract_doc($tmp, $filename);
        if ($isXlsx) return extract_xlsx($tmp, $filename);
        // Fallback: texto plano
        $text = @file_get_contents($tmp);
        return mb_detect_encoding($text, 'UTF-8,ISO-8859-1', true)
            ? mb_convert_encoding($text, 'UTF-8')
            : '[Formato no soportado para extracción de texto]';
    } finally {
        @unlink($tmp);
    }
}

function extract_pdf(string $tmpFile, string $name): string {
    // Intenta pdftotext (poppler-utils)
    if (which_cmd('pdftotext')) {
        $out = tempnam(sys_get_temp_dir(), 'void_pdf_out_');
        exec('pdftotext -enc UTF-8 ' . escapeshellarg($tmpFile) . ' ' . escapeshellarg($out) . ' 2>/dev/null', $_, $rc);
        if ($rc === 0 && file_exists($out)) {
            $text = trim(file_get_contents($out));
            @unlink($out);
            if ($text !== '') return truncate_doc($text, $name);
        }
        @unlink($out);
    }

    // Fallback PHP puro: busca streams de texto comprimidos en el PDF
    $raw = file_get_contents($tmpFile);
    $text = '';
    // Extraer streams
    preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $raw, $matches);
    foreach ($matches[1] as $stream) {
        $dec = @gzuncompress($stream);
        if ($dec === false) $dec = @gzinflate($stream);
        if ($dec === false) $dec = $stream;
        // Buscar texto en formato PDF (Tj, TJ operators)
        preg_match_all('/\(([^)]{1,200})\)\s*Tj/', $dec, $tj);
        foreach ($tj[1] as $t) $text .= pdf_unescape($t) . ' ';
        preg_match_all('/\[([^\]]+)\]\s*TJ/', $dec, $TJ);
        foreach ($TJ[1] as $t) {
            preg_match_all('/\(([^)]{1,200})\)/', $t, $inner);
            foreach ($inner[1] as $i) $text .= pdf_unescape($i) . ' ';
        }
    }
    $text = trim(preg_replace('/\s{3,}/', "\n", $text));
    if ($text !== '') return truncate_doc($text, $name);

    return '[PDF: no se pudo extraer texto. Instala poppler-utils (pdftotext) en el servidor para soporte completo.]';
}

function pdf_unescape(string $s): string {
    return stripcslashes($s);
}

function extract_docx(string $tmpFile, string $name): string {
    // Intenta pandoc
    if (which_cmd('pandoc')) {
        $out = shell_exec('pandoc -f docx -t plain ' . escapeshellarg($tmpFile) . ' 2>/dev/null');
        if ($out && trim($out) !== '') return truncate_doc(trim($out), $name);
    }

    // Fallback: descomprimir ZIP y leer word/document.xml
    $text = extract_ooxml_text($tmpFile, 'word/document.xml');
    if ($text !== '') return truncate_doc($text, $name);

    return '[DOCX: no se pudo extraer texto. Instala pandoc en el servidor para soporte completo.]';
}

function extract_doc(string $tmpFile, string $name): string {
    if (which_cmd('antiword')) {
        $out = shell_exec('antiword ' . escapeshellarg($tmpFile) . ' 2>/dev/null');
        if ($out && trim($out) !== '') return truncate_doc(trim($out), $name);
    }
    if (which_cmd('pandoc')) {
        $out = shell_exec('pandoc -f doc -t plain ' . escapeshellarg($tmpFile) . ' 2>/dev/null');
        if ($out && trim($out) !== '') return truncate_doc(trim($out), $name);
    }
    return '[DOC antiguo: instala antiword o pandoc en el servidor para soporte completo.]';
}

function extract_xlsx(string $tmpFile, string $name): string {
    $text = extract_ooxml_text($tmpFile, 'xl/sharedStrings.xml');
    // También intentar hoja directamente
    if ($text === '') $text = extract_ooxml_text($tmpFile, 'xl/worksheets/sheet1.xml');
    if ($text !== '') return truncate_doc($text, $name);
    return '[XLSX: no se pudo extraer texto del archivo Excel.]';
}

/**
 * Descomprime un ZIP/OOXML y extrae texto limpio de un XML interno.
 */
function extract_ooxml_text(string $zipFile, string $entry): string {
    if (!class_exists('ZipArchive')) return '';
    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) return '';
    $xml = $zip->getFromName($entry);
    $zip->close();
    if ($xml === false) return '';
    // Strip XML tags, decode entities
    $text = strip_tags(str_replace(['</w:p>', '</w:r>', '</t>'], "\n", $xml));
    $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    return trim(preg_replace('/\n{3,}/', "\n\n", $text));
}

function truncate_doc(string $text, string $name, int $maxChars = 30000): string {
    if (mb_strlen($text) <= $maxChars) return $text;
    return mb_substr($text, 0, $maxChars) . "\n\n[... documento truncado a {$maxChars} caracteres — '{$name}' es muy largo]";
}

function which_cmd(string $cmd): bool {
    $out = shell_exec('which ' . escapeshellarg($cmd) . ' 2>/dev/null');
    return $out && trim($out) !== '';
}
