<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Tokyo');

header('Content-Type: text/plain; charset=UTF-8');

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
    if ($provider === '1' || $provider === 'openai') {
        return 'chatgpt';
    }
    return ($provider === 'gemini') ? 'gemini' : 'chatgpt';
}

function normalize_url(string $url): string {
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
}

function ensure_dir(string $dir): void {
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        respond_and_exit(['status=error', 'message=failed_to_create_directory'], 500);
    }
}

function save_json_file(string $path, array $data): void {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        respond_and_exit(['status=error', 'message=json_encode_failed'], 500);
    }

    $fp = fopen($path, 'c+');
    if ($fp === false) {
        respond_and_exit(['status=error', 'message=failed_to_open_file'], 500);
    }

    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        respond_and_exit(['status=error', 'message=failed_to_lock_file'], 500);
    }

    ftruncate($fp, 0);
    rewind($fp);
    $written = fwrite($fp, $json);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    if ($written === false) {
        respond_and_exit(['status=error', 'message=failed_to_write_file'], 500);
    }
}

function compute_settings_hash(array $data): string {
    $hashSource = [
        'profile_id'     => (string)($data['profile_id'] ?? ''),
        'account_number' => (string)($data['account_number'] ?? ''),
        'account_type'   => (string)($data['account_type'] ?? ''),
        'symbol'         => (string)($data['symbol'] ?? ''),
        'timeframe'      => (int)($data['timeframe'] ?? 0),
        'provider'       => (string)($data['provider'] ?? ''),
        'rss_feed_url'   => (string)($data['rss_feed_url'] ?? ''),
        'prompt_text'    => (string)($data['prompt_text'] ?? ''),
        'trigger_mode'   => (int)($data['trigger_mode'] ?? 0),
        'minute_offset'  => (int)($data['minute_offset'] ?? 0),
        'hour_offset'    => (int)($data['hour_offset'] ?? 0),
        'magic'          => (int)($data['magic'] ?? 0),
    ];

    return md5(json_encode($hashSource, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_and_exit(['status=error', 'message=invalid_method'], 405);
}

$profileId     = post_value('profile_id');
$accountNumber = post_value('account_number');
$accountType   = post_value('account_type');
$symbol        = post_value('symbol');
$timeframe     = post_value('timeframe');
$provider      = normalize_provider(post_value('provider'));
$apiKey        = post_value('api_key');
$rssFeedUrl    = normalize_url(post_value('rss_feed_url'));
$promptText    = post_value('prompt_text');
$triggerMode   = post_value('trigger_mode');
$minuteOffset  = post_value('minute_offset');
$hourOffset    = post_value('hour_offset');
$magic         = post_value('magic');

if ($profileId === '' || !safe_profile_id($profileId)) {
    respond_and_exit(['status=error', 'message=invalid_profile_id'], 400);
}
if ($accountNumber === '' || !preg_match('/^\d{4,12}$/', $accountNumber)) {
    respond_and_exit(['status=error', 'message=invalid_account_number'], 400);
}
if ($accountType === '' || !preg_match('/^[012]$/', $accountType)) {
    respond_and_exit(['status=error', 'message=invalid_account_type'], 400);
}
if ($symbol === '') {
    respond_and_exit(['status=error', 'message=missing_symbol'], 400);
}
if ($timeframe === '' || !preg_match('/^\d+$/', $timeframe)) {
    respond_and_exit(['status=error', 'message=invalid_timeframe'], 400);
}
if ($rssFeedUrl === '') {
    respond_and_exit(['status=error', 'message=invalid_rss_feed_url'], 400);
}
if ($promptText === '') {
    respond_and_exit(['status=error', 'message=missing_prompt_text'], 400);
}
if ($triggerMode === '' || !preg_match('/^[1-5]$/', $triggerMode)) {
    respond_and_exit(['status=error', 'message=invalid_trigger_mode'], 400);
}
if ($minuteOffset === '' || !preg_match('/^\d{1,2}$/', $minuteOffset)) {
    respond_and_exit(['status=error', 'message=invalid_minute_offset'], 400);
}
if ($hourOffset === '' || !preg_match('/^\d{1,2}$/', $hourOffset)) {
    respond_and_exit(['status=error', 'message=invalid_hour_offset'], 400);
}

$minuteOffsetInt = (int)$minuteOffset;
$hourOffsetInt   = (int)$hourOffset;
if ($minuteOffsetInt < 0 || $minuteOffsetInt > 59) {
    respond_and_exit(['status=error', 'message=minute_offset_out_of_range'], 400);
}
if ($hourOffsetInt < 0 || $hourOffsetInt > 23) {
    respond_and_exit(['status=error', 'message=hour_offset_out_of_range'], 400);
}

$aiEnabled = ($apiKey !== '' && $rssFeedUrl !== '' && $promptText !== '');

$baseDir     = __DIR__;
$settingsDir = $baseDir . '/settings';
$logsDir     = $baseDir . '/logs';
$signalsDir  = $baseDir . '/signals';
ensure_dir($settingsDir);
ensure_dir($logsDir);
ensure_dir($signalsDir);

$now = date('Y-m-d H:i:s');
$data = [
    'profile_id'      => $profileId,
    'account_number'  => $accountNumber,
    'account_type'    => $accountType,
    'symbol'          => $symbol,
    'timeframe'       => (int)$timeframe,
    'provider'        => $provider,
    'api_key'         => $apiKey,
    'rss_feed_url'    => $rssFeedUrl,
    'prompt_text'     => $promptText,
    'ai_enabled'      => $aiEnabled,
    'trigger_mode'    => (int)$triggerMode,
    'minute_offset'   => $minuteOffsetInt,
    'hour_offset'     => $hourOffsetInt,
    'magic'           => ($magic !== '' && preg_match('/^\d+$/', $magic)) ? (int)$magic : 0,
    'updated_at'      => $now,
    'updated_unix'    => time(),
];
$data['settings_hash'] = compute_settings_hash($data);

$settingsFile = $settingsDir . '/' . $profileId . '.json';
save_json_file($settingsFile, $data);

$logFile = $logsDir . '/settings_' . date('Ym') . '.csv';
$isNew = !file_exists($logFile);
$fp = fopen($logFile, 'a');
if ($fp !== false) {
    if ($isNew) {
        fputcsv($fp, ['saved_at', 'profile_id', 'account_number', 'symbol', 'provider', 'ai_enabled', 'settings_hash'], ',', '"', '\\');
    }
    fputcsv($fp, [$now, $profileId, $accountNumber, $symbol, $provider, $aiEnabled ? 1 : 0, $data['settings_hash']], ',', '"', '\\');
    fclose($fp);
}

respond_and_exit([
    'status=ok',
    'message=settings_saved',
    'profile_id=' . $profileId,
    'provider=' . $provider,
    'ai_enabled=' . ($aiEnabled ? '1' : '0'),
    'settings_hash=' . $data['settings_hash'],
    'saved_at=' . $now
], 200);
