<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Tokyo');

header('Content-Type: text/plain; charset=UTF-8');

$CHATGPT_ENDPOINT = 'https://api.openai.com/v1/responses';
$CHATGPT_MODEL    = 'gpt-5.4-nano';
$GEMINI_ENDPOINT  = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';
$GEMINI_MODEL     = 'gemini-2.5-flash-lite';
$XAI_ENDPOINT     = 'https://api.x.ai/v1/responses';
$XAI_MODEL        = 'grok-4-1-fast-reasoning';
function respond_and_exit(array $lines, int $httpCode = 200): void {
    http_response_code($httpCode);
    foreach ($lines as $line) {
        echo $line . "\n";
    }
    exit;
}

function post_value(string $key, string $default = ''): string {
    if (!isset($_POST[$key]) || is_array($_POST[$key])) {
        return $default;
    }
    return trim((string)$_POST[$key]);
}

function safe_profile_id(string $profileId): bool {
    return (bool)preg_match('/^[a-zA-Z0-9_\-]+$/', $profileId);
}

function normalize_provider(string $provider): string {
    $provider = strtolower(trim($provider));
    if ($provider === '2') {
        return 'gemini';
    }
    if ($provider === '3' || $provider === 'xai') {
        return 'xai';
    }
    if ($provider === '1' || $provider === 'openai') {
        return 'chatgpt';
    }
    if ($provider === 'gemini') {
        return 'gemini';
    }
    return ($provider === 'xai') ? 'xai' : 'chatgpt';
}

function ensure_dir(string $dir): void {
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        respond_and_exit(['status=error', 'signal=0', 'message=failed_to_create_directory', 'signal_id=', 'created_unix=0', 'expire_unix=0'], 500);
    }
}

function read_json_file(string $path): ?array {
    if (!file_exists($path) || !is_readable($path)) {
        return null;
    }
    $json = file_get_contents($path);
    if ($json === false || $json === '') {
        return null;
    }
    $data = json_decode($json, true);
    return is_array($data) ? $data : null;
}

function save_json_file(string $path, array $data): bool {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        return false;
    }
    return file_put_contents($path, $json, LOCK_EX) !== false;
}

function append_signal_log(string $logFile, array $row): void {
    $dir = dirname($logFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $isNew = !file_exists($logFile);
    $fp = fopen($logFile, 'a');
    if ($fp === false) {
        return;
    }
    if ($isNew) {
        fputcsv($fp, ['updated_at', 'profile_id', 'account_number', 'symbol', 'provider', 'status', 'signal', 'signal_id', 'message', 'rss_chars'], ',', '"', '\\');
    }
    fputcsv($fp, $row, ',', '"', '\\');
    fclose($fp);
}

function fetch_url(string $url, int $timeoutSec): array {
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'body' => '', 'error' => 'curl_not_available'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => $timeoutSec,
        CURLOPT_USERAGENT      => 'ai-trading-demo-free/1.00',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'ok'    => ($body !== false && $code >= 200 && $code < 300),
        'body'  => ($body !== false ? (string)$body : ''),
        'error' => ($body !== false ? '' : $err),
        'code'  => $code,
    ];
}

function call_chatgpt_responses(string $apiKey, string $instructions, string $input): array {
    global $CHATGPT_ENDPOINT, $CHATGPT_MODEL;

    $payload = [
        'model'             => $CHATGPT_MODEL,
        'instructions'      => $instructions,
        'input'             => $input,
        'max_output_tokens' => 50,
    ];

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return ['ok' => false, 'text' => '', 'error' => 'json_encode_failed'];
    }

    $ch = curl_init($CHATGPT_ENDPOINT);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS     => $json,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $code < 200 || $code >= 300) {
        return ['ok' => false, 'text' => '', 'error' => 'chatgpt_http_error:' . $code . ':' . $err];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['ok' => false, 'text' => '', 'error' => 'chatgpt_invalid_json'];
    }

    $text = '';
    if (!empty($data['output_text']) && is_string($data['output_text'])) {
        $text = trim($data['output_text']);
    }
    if ($text === '' && !empty($data['output']) && is_array($data['output'])) {
        foreach ($data['output'] as $out) {
            if (empty($out['content']) || !is_array($out['content'])) {
                continue;
            }
            foreach ($out['content'] as $content) {
                if (isset($content['text']) && is_string($content['text'])) {
                    $text .= $content['text'] . "\n";
                }
            }
        }
        $text = trim($text);
    }

    return ['ok' => ($text !== ''), 'text' => $text, 'error' => ($text !== '' ? '' : 'chatgpt_empty_output')];
}

function call_gemini_generate_content(string $apiKey, string $instructions, string $input): array {
    global $GEMINI_ENDPOINT, $GEMINI_MODEL;

    $payload = [
        'system_instruction' => [
            'parts' => [
                ['text' => $instructions],
            ],
        ],
        'contents' => [
            [
                'parts' => [
                    ['text' => $input],
                ],
            ],
        ],
        'generationConfig' => [
            'temperature'     => 0.1,
            'maxOutputTokens' => 200,
        ],
    ];

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return ['ok' => false, 'text' => '', 'error' => 'json_encode_failed'];
    }

    $endpoint = sprintf($GEMINI_ENDPOINT, rawurlencode($GEMINI_MODEL));
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-goog-api-key: ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS     => $json,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $code < 200 || $code >= 300) {
        return ['ok' => false, 'text' => '', 'error' => 'gemini_http_error:' . $code . ':' . $err];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['ok' => false, 'text' => '', 'error' => 'gemini_invalid_json'];
    }

    $text = '';
    $finishReason = '';
    if (!empty($data['candidates']) && is_array($data['candidates'])) {
        foreach ($data['candidates'] as $candidate) {
            if ($finishReason === '' && !empty($candidate['finishReason']) && is_string($candidate['finishReason'])) {
                $finishReason = strtolower($candidate['finishReason']);
            }
            if (empty($candidate['content']['parts']) || !is_array($candidate['content']['parts'])) {
                continue;
            }
            foreach ($candidate['content']['parts'] as $part) {
                if (isset($part['text']) && is_string($part['text'])) {
                    $text .= $part['text'] . "\n";
                }
            }
        }
    }

    $text = trim($text);
    if ($text !== '') {
        return ['ok' => true, 'text' => $text, 'error' => ''];
    }

    $blockReason = '';
    if (!empty($data['promptFeedback']['blockReason']) && is_string($data['promptFeedback']['blockReason'])) {
        $blockReason = strtolower($data['promptFeedback']['blockReason']);
    }
    if ($blockReason !== '') {
        return ['ok' => false, 'text' => '', 'error' => 'gemini_blocked:' . $blockReason];
    }
    if ($finishReason !== '') {
        return ['ok' => false, 'text' => '', 'error' => 'gemini_finish_reason:' . $finishReason];
    }
    if (empty($data['candidates']) || !is_array($data['candidates'])) {
        return ['ok' => false, 'text' => '', 'error' => 'gemini_no_candidates'];
    }

    return ['ok' => false, 'text' => '', 'error' => 'gemini_empty_output'];
}

function call_xai_responses(string $apiKey, string $instructions, string $input): array {
    global $XAI_ENDPOINT, $XAI_MODEL;

    $payload = [
        'model' => $XAI_MODEL,
        'store' => false,
        'input' => [
            [
                'role' => 'system',
                'content' => $instructions,
            ],
            [
                'role' => 'user',
                'content' => $input,
            ],
        ],
    ];

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return ['ok' => false, 'text' => '', 'error' => 'json_encode_failed'];
    }

    $ch = curl_init($XAI_ENDPOINT);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS     => $json,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $code < 200 || $code >= 300) {
        return ['ok' => false, 'text' => '', 'error' => 'xai_http_error:' . $code . ':' . $err];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['ok' => false, 'text' => '', 'error' => 'xai_invalid_json'];
    }

    $text = '';
    if (!empty($data['output_text']) && is_string($data['output_text'])) {
        $text = trim($data['output_text']);
    }
    if ($text === '' && !empty($data['output']) && is_array($data['output'])) {
        foreach ($data['output'] as $out) {
            if (empty($out['content']) || !is_array($out['content'])) {
                continue;
            }
            foreach ($out['content'] as $content) {
                if (isset($content['text']) && is_string($content['text'])) {
                    $text .= $content['text'] . "\n";
                }
            }
        }
        $text = trim($text);
    }

    return ['ok' => ($text !== ''), 'text' => $text, 'error' => ($text !== '' ? '' : 'xai_empty_output')];
}

function call_ai_response(string $provider, string $apiKey, string $instructions, string $input): array {
    if ($provider === 'gemini') {
        return call_gemini_generate_content($apiKey, $instructions, $input);
    }
    if ($provider === 'xai') {
        return call_xai_responses($apiKey, $instructions, $input);
    }
    return call_chatgpt_responses($apiKey, $instructions, $input);
}

function default_expire_seconds(int $triggerMode): int {
    switch ($triggerMode) {
        case 1: return 90;
        case 2: return 9 * 60;
        case 3: return 50 * 60;
        case 4: return 230 * 60;
        case 5: return 23 * 60 * 60;
        default: return 15 * 60;
    }
}

function save_latest_signal(string $latestFile, string $status, int $signal, string $signalId, int $createdUnix, int $expireUnix, string $symbol, string $message, string $provider, int $rssChars): bool {
    return save_json_file($latestFile, [
        'status'       => $status,
        'signal'       => $signal,
        'signal_id'    => $signalId,
        'created_unix' => $createdUnix,
        'expire_unix'  => $expireUnix,
        'symbol'       => $symbol,
        'message'      => $message,
        'message_ja'   => $message,
        'provider'     => $provider,
        'rss_chars'    => $rssChars,
        'updated_at'   => date('Y-m-d H:i:s'),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_and_exit(['status=error', 'signal=0', 'message=invalid_method', 'signal_id=', 'created_unix=0', 'expire_unix=0'], 405);
}

$profileId     = post_value('profile_id');
$accountNumber = post_value('account_number');
$symbol        = post_value('symbol');
$timeframe     = post_value('timeframe');

if ($profileId === '' || !safe_profile_id($profileId)) {
    respond_and_exit(['status=error', 'signal=0', 'message=invalid_profile_id', 'signal_id=', 'created_unix=0', 'expire_unix=0'], 400);
}
if ($accountNumber === '' || !preg_match('/^\d{4,12}$/', $accountNumber)) {
    respond_and_exit(['status=error', 'signal=0', 'message=invalid_account_number', 'signal_id=', 'created_unix=0', 'expire_unix=0'], 400);
}
if ($symbol === '') {
    respond_and_exit(['status=error', 'signal=0', 'message=missing_symbol', 'signal_id=', 'created_unix=0', 'expire_unix=0'], 400);
}

$baseDir       = __DIR__;
$settingsDir   = $baseDir . '/settings';
$signalsDir    = $baseDir . '/signals';
$logsDir       = $baseDir . '/logs';
ensure_dir($settingsDir);
ensure_dir($signalsDir);
ensure_dir($logsDir);

$settingsFile   = $settingsDir . '/' . $profileId . '.json';
$latestFile     = $signalsDir . '/latest_' . $profileId . '.json';
$processingFile = $signalsDir . '/processing_' . $profileId . '.lock';
$signalLogFile  = $logsDir . '/signals_' . date('Ym') . '.csv';

$settings = read_json_file($settingsFile);
if ($settings === null) {
    respond_and_exit(['status=error', 'signal=0', 'message=settings_not_found', 'signal_id=', 'created_unix=0', 'expire_unix=0'], 404);
}

$latest = read_json_file($latestFile);
if (
    is_array($latest)
    && (string)($latest['status'] ?? '') === 'ready'
    && (int)($latest['signal'] ?? 0) === 1
    && (int)($latest['expire_unix'] ?? 0) > time()
) {
    respond_and_exit([
        'status=ready',
        'signal=' . (int)$latest['signal'],
        'message=' . (string)($latest['message'] ?? 'ok'),
        'signal_id=' . (string)($latest['signal_id'] ?? ''),
        'created_unix=' . (int)($latest['created_unix'] ?? 0),
        'expire_unix=' . (int)($latest['expire_unix'] ?? 0)
    ], 200);
}

$provider   = normalize_provider((string)($settings['provider'] ?? '1'));
$apiKey     = trim((string)($settings['api_key'] ?? ''));
$rssFeedUrl = trim((string)($settings['rss_feed_url'] ?? ''));
$promptText = trim((string)($settings['prompt_text'] ?? ''));
$aiEnabled  = !empty($settings['ai_enabled']);

if (!$aiEnabled || $apiKey === '' || $rssFeedUrl === '' || $promptText === '') {
    $now = time();
    save_latest_signal($latestFile, 'stale', 0, '', $now, $now, $symbol, 'ai_disabled', $provider, 0);
    respond_and_exit(['status=stale', 'signal=0', 'message=ai_disabled', 'signal_id=', 'created_unix=' . $now, 'expire_unix=' . $now], 200);
}

if (file_exists($processingFile)) {
    $latest = read_json_file($latestFile);
    respond_and_exit([
        'status=processing',
        'signal=' . (int)($latest['signal'] ?? 0),
        'message=processing_now',
        'signal_id=' . (string)($latest['signal_id'] ?? ''),
        'created_unix=' . (int)($latest['created_unix'] ?? 0),
        'expire_unix=' . (int)($latest['expire_unix'] ?? 0)
    ], 200);
}

$lockFp = fopen($processingFile, 'c+');
if ($lockFp === false || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    if (is_resource($lockFp)) {
        fclose($lockFp);
    }
    respond_and_exit(['status=processing', 'signal=0', 'message=lock_busy', 'signal_id=', 'created_unix=0', 'expire_unix=0'], 200);
}

register_shutdown_function(function () use ($lockFp, $processingFile) {
    @flock($lockFp, LOCK_UN);
    @fclose($lockFp);
    if (file_exists($processingFile)) {
        @unlink($processingFile);
    }
});

$rss = fetch_url($rssFeedUrl, 20);
if (!$rss['ok']) {
    $now = time();
    save_latest_signal($latestFile, 'error', 0, '', $now, $now, $symbol, 'rss_fetch_failed', $provider, 0);
    respond_and_exit(['status=error', 'signal=0', 'message=rss_fetch_failed', 'signal_id=', 'created_unix=' . $now, 'expire_unix=' . $now], 200);
}

$rssBody = trim((string)$rss['body']);
if ($rssBody === '') {
    $now = time();
    save_latest_signal($latestFile, 'error', 0, '', $now, $now, $symbol, 'rss_empty', $provider, 0);
    respond_and_exit(['status=error', 'signal=0', 'message=rss_empty', 'signal_id=', 'created_unix=' . $now, 'expire_unix=' . $now], 200);
}

$rssBody = mb_substr($rssBody, 0, 6000, 'UTF-8');
$aiInput = implode("\n", [
    'symbol: ' . $symbol,
    'timeframe: ' . $timeframe,
    'rss_feed_url: ' . $rssFeedUrl,
    '',
    'rss_content:',
    $rssBody,
]);

$ai = call_ai_response($provider, $apiKey, $promptText, $aiInput);
if (!$ai['ok']) {
    $now = time();
    $message = (string)($ai['error'] ?? 'ai_request_failed');
    save_latest_signal($latestFile, 'error', 0, '', $now, $now, $symbol, $message, $provider, strlen($rssBody));
    append_signal_log($signalLogFile, [date('Y-m-d H:i:s'), $profileId, $accountNumber, $symbol, $provider, 'error', 0, '', $message, strlen($rssBody)]);
    respond_and_exit(['status=error', 'signal=0', 'message=' . $message, 'signal_id=', 'created_unix=' . $now, 'expire_unix=' . $now], 200);
}

$createdUnix = time();
$expireUnix  = $createdUnix + default_expire_seconds((int)($settings['trigger_mode'] ?? 2));
$signalId    = date('YmdHis') . '_' . substr(md5($profileId . '|' . $symbol . '|' . $createdUnix), 0, 8);
$message     = 'demo_force_long';

save_latest_signal($latestFile, 'ready', 1, $signalId, $createdUnix, $expireUnix, $symbol, $message, $provider, strlen($rssBody));
append_signal_log($signalLogFile, [date('Y-m-d H:i:s'), $profileId, $accountNumber, $symbol, $provider, 'ready', 1, $signalId, $message, strlen($rssBody)]);

respond_and_exit([
    'status=ready',
    'signal=1',
    'message=demo_force_long',
    'signal_id=' . $signalId,
    'created_unix=' . $createdUnix,
    'expire_unix=' . $expireUnix
], 200);
