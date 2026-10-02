```php
<?php

declare(strict_types=1);

// -----------------------------------------------------------------------------
// Settings
// -----------------------------------------------------------------------------

const TELEGRAM_HOST = 'https://api.telegram.org';
const CONNECT_TIMEOUT = 10;
const DEFAULT_TIMEOUT = 30;
const MAX_TELEGRAM_TIMEOUT = 600;
const TIMEOUT_MARGIN = 10;

// -----------------------------------------------------------------------------
// Request
// -----------------------------------------------------------------------------

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$httpMethod = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if (!in_array($httpMethod, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'], true)) {
    brokerError(405, 'HTTP method not allowed');
}

$parts = parse_url($requestUri);

$path = $parts['path'] ?? '';
$query = $parts['query'] ?? '';

$headerToken = trim($_SERVER['HTTP_X_BOT_TOKEN'] ?? '');

$telegramMethod = '';
$telegramPath = '';

// -----------------------------------------------------------------------------
// Routing
// -----------------------------------------------------------------------------
//
// Supported:
//
// 1. Header token:
//    /getUpdates?timeout=30
//    X-Bot-Token: 123456:ABC...
//
// 2. Token in path:
//    /bot123456:ABC.../getUpdates?timeout=30
//
// -----------------------------------------------------------------------------

if ($headerToken !== '') {

    // Header mode accepts only /<method>.
    if (!preg_match('~^/([A-Za-z0-9_]+)$~', $path, $match)) {
        brokerError(400, 'Invalid Telegram API method');
    }

    if (!isValidToken($headerToken)) {
        brokerError(400, 'Invalid X-Bot-Token');
    }

    $telegramMethod = $match[1];
    $telegramPath = '/bot' . $headerToken . '/' . $telegramMethod;

} else {

    // Legacy/path mode.
    if (!preg_match('~^/bot([^/]+)/([A-Za-z0-9_]+)$~', $path, $match)) {
        brokerError(
            400,
            'Missing X-Bot-Token or invalid Telegram API path'
        );
    }

    $token = $match[1];

    if (!isValidToken($token)) {
        brokerError(400, 'Invalid bot token');
    }

    $telegramMethod = $match[2];
    $telegramPath = $path;
}

$url = TELEGRAM_HOST . $telegramPath;

if ($query !== '') {
    $url .= '?' . $query;
}

// -----------------------------------------------------------------------------
// Timeout
// -----------------------------------------------------------------------------

$curlTimeout = DEFAULT_TIMEOUT;

if ($telegramMethod === 'getUpdates') {
    parse_str($query, $queryParams);

    $telegramTimeout = isset($queryParams['timeout'])
        ? (int) $queryParams['timeout']
        : 0;

    $telegramTimeout = max(
        0,
        min($telegramTimeout, MAX_TELEGRAM_TIMEOUT)
    );

    if ($telegramTimeout > 0) {
        $curlTimeout = $telegramTimeout + TIMEOUT_MARGIN;
    }
}

// -----------------------------------------------------------------------------
// cURL
// -----------------------------------------------------------------------------

$ch = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => CONNECT_TIMEOUT,
    CURLOPT_TIMEOUT        => $curlTimeout,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CUSTOMREQUEST  => $httpMethod,
]);

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$isMultipart = stripos($contentType, 'multipart/form-data') === 0;

// -----------------------------------------------------------------------------
// Request body
// -----------------------------------------------------------------------------

if ($httpMethod !== 'GET' && $httpMethod !== 'HEAD') {
    if ($isMultipart) {
        $postFields = $_POST;

        foreach ($_FILES as $fieldName => $fileData) {
            addUploadedFiles($postFields, $fieldName, $fileData);
        }

        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);

    } else {
        $body = file_get_contents('php://input');

        if ($body !== false && $body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
    }
}

// -----------------------------------------------------------------------------
// Request headers
// -----------------------------------------------------------------------------

$requestHeaders = [];

// Never forward X-Bot-Token to Telegram.
// It is used only to construct Telegram's /bot<TOKEN>/ path.

if (!$isMultipart && $contentType !== '') {
    $requestHeaders[] = 'Content-Type: ' . $contentType;
}

if (!empty($_SERVER['HTTP_ACCEPT'])) {
    $requestHeaders[] = 'Accept: ' . $_SERVER['HTTP_ACCEPT'];
}

if ($requestHeaders) {
    curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);
}

// -----------------------------------------------------------------------------
// Execute
// -----------------------------------------------------------------------------

$response = curl_exec($ch);

if ($response === false) {
    $errno = curl_errno($ch);
    $error = curl_error($ch);

    curl_close($ch);

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

$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$responseContentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

curl_close($ch);

// -----------------------------------------------------------------------------
// Response
// -----------------------------------------------------------------------------

http_response_code($status);

if ($responseContentType) {
    header('Content-Type: ' . $responseContentType);
}

if ($httpMethod !== 'HEAD') {
    echo $response;
}

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

function isValidToken(string $token): bool
{
    return strlen($token) <= 256
        && preg_match('~^[A-Za-z0-9:_-]+$~', $token) === 1;
}

function brokerError(int $status, string $description): never
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

function addUploadedFiles(
    array &$postFields,
    string $fieldName,
    array $fileData
): void {
    if (!isset($fileData['name'])) {
        return;
    }

    if (!is_array($fileData['name'])) {
        if (
            ($fileData['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
            && isset($fileData['tmp_name'])
            && is_uploaded_file($fileData['tmp_name'])
        ) {
            $postFields[$fieldName] = new CURLFile(
                $fileData['tmp_name'],
                $fileData['type'] ?? 'application/octet-stream',
                $fileData['name'] ?? 'file'
            );
        }

        return;
    }

    foreach ($fileData['name'] as $index => $name) {
        $error = $fileData['error'][$index] ?? UPLOAD_ERR_NO_FILE;
        $tmpName = $fileData['tmp_name'][$index] ?? '';

        if (
            $error !== UPLOAD_ERR_OK
            || $tmpName === ''
            || !is_uploaded_file($tmpName)
        ) {
            continue;
        }

        $postFields[$fieldName . '[' . $index . ']'] = new CURLFile(
            $tmpName,
            $fileData['type'][$index] ?? 'application/octet-stream',
            $name ?: 'file'
        );
    }
}
```