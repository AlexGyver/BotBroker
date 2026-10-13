<?php

declare(strict_types=1);

const TELEGRAM_HOST = 'https://api.telegram.org';
const CONNECT_TIMEOUT = 10;
const DEFAULT_TIMEOUT = 30;
const FILE_TIMEOUT = 300;
const MAX_TELEGRAM_TIMEOUT = 600;
const TIMEOUT_MARGIN = 10;

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$httpMethod = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if (!in_array($httpMethod, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'], true)) {
    brokerError(405, 'HTTP method not allowed');
}

$parts = parse_url($requestUri);
if ($parts === false) brokerError(400, 'Invalid request URI');

$path = $parts['path'] ?? '';
$query = $parts['query'] ?? '';
$headerToken = trim($_SERVER['HTTP_X_BOT_TOKEN'] ?? '');
$telegramMethod = '';
$telegramPath = '';
$isFile = false;

// Commands:
//   /bot<TOKEN>/<method>
//   /<method> + X-Bot-Token
// Files:
//   /file/bot<TOKEN>/<file_path>
//   /file/<file_path> + X-Bot-Token
if ($headerToken !== '') {
    if (!isValidToken($headerToken)) brokerError(400, 'Invalid X-Bot-Token');

    if (preg_match('~^/file/(.+)$~', $path, $match)) {
        $filePath = validateFilePath($match[1]);
        $telegramPath = '/file/bot' . $headerToken . '/' . $filePath;
        $isFile = true;
    } elseif (preg_match('~^/([A-Za-z0-9_]+)$~', $path, $match)) {
        $telegramMethod = $match[1];
        $telegramPath = '/bot' . $headerToken . '/' . $telegramMethod;
    } else {
        brokerError(400, 'Invalid Telegram API path');
    }
} elseif (preg_match('~^/file/bot([^/]+)/(.+)$~', $path, $match)) {
    $token = $match[1];
    if (!isValidToken($token)) brokerError(400, 'Invalid bot token');

    $filePath = validateFilePath($match[2]);
    $telegramPath = '/file/bot' . $token . '/' . $filePath;
    $isFile = true;
} elseif (preg_match('~^/bot([^/]+)/([A-Za-z0-9_]+)$~', $path, $match)) {
    $token = $match[1];
    if (!isValidToken($token)) brokerError(400, 'Invalid bot token');

    $telegramMethod = $match[2];
    $telegramPath = '/bot' . $token . '/' . $telegramMethod;
} else {
    brokerError(400, 'Missing bot token or invalid Telegram API path');
}

if ($isFile && $httpMethod !== 'GET' && $httpMethod !== 'HEAD') {
    brokerError(405, 'Telegram files support only GET and HEAD');
}

$url = TELEGRAM_HOST . $telegramPath;
if ($query !== '') $url .= '?' . $query;

$curlTimeout = $isFile ? FILE_TIMEOUT : DEFAULT_TIMEOUT;
if (!$isFile && $telegramMethod === 'getUpdates') {
    parse_str($query, $queryParams);
    $telegramTimeout = isset($queryParams['timeout']) ? (int) $queryParams['timeout'] : 0;
    $telegramTimeout = max(0, min($telegramTimeout, MAX_TELEGRAM_TIMEOUT));
    if ($telegramTimeout > 0) $curlTimeout = $telegramTimeout + TIMEOUT_MARGIN;
}

$ch = curl_init($url);
if ($ch === false) brokerError(500, 'Could not initialize cURL');

curl_setopt_array($ch, [
    CURLOPT_CONNECTTIMEOUT => CONNECT_TIMEOUT,
    CURLOPT_TIMEOUT => $curlTimeout,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CUSTOMREQUEST => $httpMethod,
]);

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$isMultipart = !$isFile && stripos($contentType, 'multipart/form-data') === 0;

// PHP leaves both arrays empty when post_max_size is exceeded. Without this
// check Telegram would receive a valid-looking request with no fields/file.
if (
    $isMultipart
    && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0
    && empty($_POST)
    && empty($_FILES)
) {
    brokerError(413, 'Multipart body was rejected by PHP limits');
}

if (!$isFile && $httpMethod !== 'GET' && $httpMethod !== 'HEAD') {
    if ($isMultipart) {
        $postFields = $_POST;
        foreach ($_FILES as $fieldName => $fileData) {
            addUploadedFiles($postFields, $fieldName, $fileData);
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    } else {
        $body = file_get_contents('php://input');
        if ($body !== false && $body !== '') curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
}

$requestHeaders = [];
if (!$isMultipart && $contentType !== '') $requestHeaders[] = 'Content-Type: ' . $contentType;
if (!empty($_SERVER['HTTP_ACCEPT'])) $requestHeaders[] = 'Accept: ' . $_SERVER['HTTP_ACCEPT'];
if ($isFile) {
    if (!empty($_SERVER['HTTP_RANGE'])) $requestHeaders[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
    if (!empty($_SERVER['HTTP_IF_NONE_MATCH'])) $requestHeaders[] = 'If-None-Match: ' . $_SERVER['HTTP_IF_NONE_MATCH'];
    if (!empty($_SERVER['HTTP_IF_MODIFIED_SINCE'])) $requestHeaders[] = 'If-Modified-Since: ' . $_SERVER['HTTP_IF_MODIFIED_SINCE'];
}
if ($requestHeaders) curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);

if ($isFile) {
    streamFileResponse($ch, $httpMethod);
    exit;
}

curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);

if ($response === false) {
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    curl_close($ch);
    brokerCurlError($errno, $error);
}

$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$responseContentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

http_response_code($status ?: 502);
if ($responseContentType) header('Content-Type: ' . $responseContentType);
if ($httpMethod !== 'HEAD') echo $response;

function isValidToken(string $token): bool
{
    return strlen($token) <= 256
        && preg_match('~^[A-Za-z0-9:_-]+$~', $token) === 1;
}

function validateFilePath(string $path): string
{
    $decoded = rawurldecode($path);
    if (
        $path === ''
        || strlen($path) > 4096
        || strpos($decoded, "\0") !== false
        || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $decoded)
    ) {
        brokerError(400, 'Invalid Telegram file path');
    }
    return $path;
}

function streamFileResponse($ch, string $httpMethod): void
{
    $status = 502;
    $headers = [];
    $headersSent = false;

    curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($curl, string $line) use (&$status, &$headers): int {
        $length = strlen($line);
        $trimmed = trim($line);

        if (preg_match('~^HTTP/\S+\s+(\d{3})~i', $trimmed, $match)) {
            $status = (int) $match[1];
            $headers = [];
            return $length;
        }

        $separator = strpos($line, ':');
        if ($separator !== false) {
            $name = strtolower(trim(substr($line, 0, $separator)));
            $value = trim(substr($line, $separator + 1));
            $allowed = [
                'content-type', 'content-length', 'content-disposition',
                'content-range', 'last-modified', 'etag', 'accept-ranges',
                'cache-control', 'expires', 'location',
            ];
            if (in_array($name, $allowed, true)) $headers[$name] = $value;
        }
        return $length;
    });

    $sendHeaders = static function () use (&$status, &$headers, &$headersSent): void {
        if ($headersSent) return;
        http_response_code($status ?: 502);
        foreach ($headers as $name => $value) header($name . ': ' . $value);
        header('X-Accel-Buffering: no');
        $headersSent = true;
    };

    if ($httpMethod !== 'HEAD') {
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($curl, string $data) use ($sendHeaders): int {
            $sendHeaders();
            echo $data;
            if (ob_get_level() > 0) ob_flush();
            flush();
            return strlen($data);
        });
    } else {
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    }

    $result = curl_exec($ch);
    if ($result === false) {
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);
        if ($headersSent) {
            error_log('BotBroker file stream error ' . $errno . ': ' . $error);
            return;
        }
        brokerCurlError($errno, $error);
    }

    $sendHeaders();
    curl_close($ch);
}

function brokerCurlError(int $errno, string $error): void
{
    http_response_code(502);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'broker_error' => true,
        'curl_errno' => $errno,
        'description' => $error,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function brokerError(int $status, string $description): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'broker_error' => true,
        'description' => $description,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function addUploadedFiles(array &$postFields, string $fieldName, array $fileData): void
{
    if (!isset($fileData['name'])) return;

    if (!is_array($fileData['name'])) {
        $error = $fileData['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) return;
        if ($error !== UPLOAD_ERR_OK) brokerError(400, 'File upload failed with code ' . $error);

        $tmpName = $fileData['tmp_name'] ?? '';
        if ($tmpName === '' || !is_uploaded_file($tmpName)) brokerError(400, 'Invalid uploaded file');

        $postFields[$fieldName] = new CURLFile(
            $tmpName,
            $fileData['type'] ?? 'application/octet-stream',
            $fileData['name'] ?: 'file'
        );
        return;
    }

    foreach ($fileData['name'] as $index => $name) {
        $error = $fileData['error'][$index] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) continue;
        if ($error !== UPLOAD_ERR_OK) brokerError(400, 'File upload failed with code ' . $error);

        $tmpName = $fileData['tmp_name'][$index] ?? '';
        if ($tmpName === '' || !is_uploaded_file($tmpName)) brokerError(400, 'Invalid uploaded file');

        $postFields[$fieldName . '[' . $index . ']'] = new CURLFile(
            $tmpName,
            $fileData['type'][$index] ?? 'application/octet-stream',
            $name ?: 'file'
        );
    }
}
