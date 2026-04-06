<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
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

function append_access_log(string $path, array $row): void {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $isNew = !file_exists($path);
    $fp = fopen($path, 'a');
    if ($fp === false) {
        return;
    }
    if ($isNew) {
        fputcsv($fp, ['accessed_at', 'profile_id', 'account_number', 'symbol', 'status', 'signal', 'message'], ',', '"', '\\');
    }
    fputcsv($fp, $row, ',', '"', '\\');
    fclose($fp);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_and_exit(['status=error', 'signal=0', 'message=invalid_method', 'signal_id=', 'created_unix=0', 'expire_unix=0'], 405);
}

$profileId     = post_value('profile_id');
$accountNumber = post_value('account_number');
$symbol        = post_value('symbol');

if ($profileId === '' || !safe_profile_id($profileId)) {
    respond_and_exit(['status=error', 'signal=0', 'message=invalid_profile_id', 'signal_id=', 'created_unix=0', 'expire_unix=0'], 400);
}
if ($accountNumber === '' || !preg_match('/^\d{4,12}$/', $accountNumber)) {
    respond_and_exit(['status=error', 'signal=0', 'message=invalid_account_number', 'signal_id=', 'created_unix=0', 'expire_unix=0'], 400);
}
if ($symbol === '') {
    respond_and_exit(['status=error', 'signal=0', 'message=missing_symbol', 'signal_id=', 'created_unix=0', 'expire_unix=0'], 400);
}

$baseDir        = __DIR__;
$settingsFile   = $baseDir . '/settings/' . $profileId . '.json';
$latestFile     = $baseDir . '/signals/latest_' . $profileId . '.json';
$processingFile = $baseDir . '/signals/processing_' . $profileId . '.lock';
$accessLogFile  = $baseDir . '/logs/signal_access_' . date('Ym') . '.csv';

$settings = read_json_file($settingsFile);
if ($settings === null) {
    append_access_log($accessLogFile, [date('Y-m-d H:i:s'), $profileId, $accountNumber, $symbol, 'error', 0, 'settings_not_found']);
    respond_and_exit(['status=error', 'signal=0', 'message=settings_not_found', 'signal_id=', 'created_unix=0', 'expire_unix=0'], 404);
}

if (file_exists($processingFile)) {
    $latest = read_json_file($latestFile);
    $signal = (int)($latest['signal'] ?? 0);
    $signalId = (string)($latest['signal_id'] ?? '');
    $createdUnix = (int)($latest['created_unix'] ?? 0);
    $expireUnix = (int)($latest['expire_unix'] ?? 0);
    append_access_log($accessLogFile, [date('Y-m-d H:i:s'), $profileId, $accountNumber, $symbol, 'processing', $signal, 'processing_now']);
    respond_and_exit([
        'status=processing',
        'signal=' . $signal,
        'message=processing_now',
        'signal_id=' . $signalId,
        'created_unix=' . $createdUnix,
        'expire_unix=' . $expireUnix
    ], 200);
}

$latest = read_json_file($latestFile);
if ($latest === null) {
    append_access_log($accessLogFile, [date('Y-m-d H:i:s'), $profileId, $accountNumber, $symbol, 'stale', 0, 'no_signal_yet']);
    respond_and_exit(['status=stale', 'signal=0', 'message=no_signal_yet', 'signal_id=', 'created_unix=0', 'expire_unix=0'], 200);
}

$status = (string)($latest['status'] ?? 'ready');
$signal = (int)($latest['signal'] ?? 0);
$signalId = (string)($latest['signal_id'] ?? '');
$createdUnix = (int)($latest['created_unix'] ?? 0);
$expireUnix = (int)($latest['expire_unix'] ?? 0);
$message = (string)($latest['message'] ?? 'ok');

append_access_log($accessLogFile, [date('Y-m-d H:i:s'), $profileId, $accountNumber, $symbol, $status, $signal, $message]);
respond_and_exit([
    'status=' . $status,
    'signal=' . $signal,
    'message=' . $message,
    'signal_id=' . $signalId,
    'created_unix=' . $createdUnix,
    'expire_unix=' . $expireUnix
], 200);
