<?php

declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function badge(bool $ok): string
{
    return $ok
        ? '<span class="ok">OK</span>'
        : '<span class="fail">FAIL</span>';
}

function request(string $url, int $timeout = 20): array
{
    $start = microtime(true);

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    $body = curl_exec($ch);

    $result = [
        'ok' => $body !== false,
        'http' => curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'ip' => curl_getinfo($ch, CURLINFO_PRIMARY_IP),
        'error' => curl_error($ch),
        'time' => microtime(true) - $start,
        'body' => $body === false ? '' : $body,
    ];

    curl_close($ch);

    return $result;
}

$token = trim($_POST['token'] ?? '');
$action = $_POST['action'] ?? '';

$telegramIp = gethostbyname('api.telegram.org');
$telegramDnsOk = $telegramIp !== 'api.telegram.org';

$googleIp = gethostbyname('google.com');
$googleDnsOk = $googleIp !== 'google.com';

$httpsTest = null;

if (function_exists('curl_init')) {
    $httpsTest = request('https://api.telegram.org/');
}

$botResult = null;
$botTitle = '';

if ($token !== '' && in_array($action, ['getme', 'poll'], true)) {
    $base = 'https://api.telegram.org/bot' . rawurlencode($token);

    if ($action === 'getme') {
        $botTitle = 'getMe';
        $botResult = request($base . '/getMe');
    } else {
        $botTitle = 'getUpdates?timeout=30';
        $botResult = request($base . '/getUpdates?timeout=30', 45);
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">

<title>Telegram Broker Check</title>

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    padding: 20px;
    background: #f5f5f5;
    font-family: system-ui, sans-serif;
    font-size: 14px;
    line-height: 1.35;
    color: #222;
}

.wrap {
    max-width: 620px;
    margin: 0 auto;
}

.card {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 14px;
    margin-bottom: 10px;
}

h1 {
    font-size: 20px;
    margin: 0 0 12px;
}

h2 {
    font-size: 15px;
    margin: 0 0 8px;
}

.row {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    padding: 3px 0;
}

.ok {
    color: #16803a;
    font-weight: 700;
}

.fail {
    color: #b00020;
    font-weight: 700;
}

input[type=password] {
    width: 100%;
    padding: 8px 9px;
    border: 1px solid #bbb;
    border-radius: 5px;
    font: inherit;
    margin-bottom: 8px;
}

.buttons {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

button {
    border: 1px solid #aaa;
    background: #fafafa;
    padding: 7px 12px;
    border-radius: 5px;
    cursor: pointer;
    font: inherit;
}

button:hover {
    background: #eee;
}

pre {
    margin: 8px 0 0;
    padding: 9px;
    background: #f4f4f4;
    border-radius: 5px;
    overflow: auto;
    max-height: 220px;
    white-space: pre-wrap;
    word-break: break-word;
    font-size: 12px;
}

.note {
    color: #666;
    font-size: 12px;
    margin: 8px 0 0;
}

.result-head {
    display: flex;
    gap: 12px;
    align-items: center;
}
</style>
</head>

<body>

<div class="wrap">

<h1>Telegram Broker Check</h1>

<div class="card">

<h2>Server</h2>

<div class="row">
    <span>PHP</span>
    <span><?= h(PHP_VERSION) ?></span>
</div>

<div class="row">
    <span>cURL</span>
    <?= badge(function_exists('curl_init')) ?>
</div>

<div class="row">
    <span>max_execution_time</span>
    <span><?= h((string) ini_get('max_execution_time')) ?> sec</span>
</div>

</div>


<div class="card">

<h2>Network</h2>

<div class="row">
    <span>api.telegram.org DNS</span>
    <span>
        <?= badge($telegramDnsOk) ?>
        <?php if ($telegramDnsOk): ?>
            <?= h($telegramIp) ?>
        <?php endif; ?>
    </span>
</div>

<div class="row">
    <span>google.com DNS</span>
    <span>
        <?= badge($googleDnsOk) ?>
        <?php if ($googleDnsOk): ?>
            <?= h($googleIp) ?>
        <?php endif; ?>
    </span>
</div>

<?php if ($httpsTest): ?>

<div class="row">
    <span>HTTPS → Telegram</span>
    <span>
        <?= badge($httpsTest['ok']) ?>
        <?= $httpsTest['ip'] ? h($httpsTest['ip']) : '' ?>
    </span>
</div>

<?php if (!$httpsTest['ok']): ?>
<pre><?= h($httpsTest['error']) ?></pre>
<?php endif; ?>

<?php endif; ?>

</div>


<div class="card">

<h2>Bot API</h2>

<form method="post">

<input
    type="password"
    name="token"
    value="<?= h($token) ?>"
    placeholder="Bot token: 123456789:AA..."
    required
>

<div class="buttons">
    <button type="submit" name="action" value="getme">
        getMe
    </button>

    <button type="submit" name="action" value="poll">
        Long polling 30s
    </button>
</div>

</form>

<p class="note">
Token используется только для текущего запроса и скриптом не сохраняется.
</p>

<?php if ($botResult !== null):

    $json = json_decode($botResult['body'], true);

    $telegramOk =
        $botResult['ok']
        && is_array($json)
        && ($json['ok'] ?? false) === true;
?>

<hr style="border:0;border-top:1px solid #eee;margin:12px 0">

<div class="result-head">

<strong><?= h($botTitle) ?></strong>

<?= badge($telegramOk) ?>

<span>
<?= round($botResult['time'], 2) ?> sec
</span>

<span>
HTTP <?= h((string) $botResult['http']) ?>
</span>

</div>

<?php

if (
    $action === 'poll'
    && $telegramOk
) {
    $updates = is_array($json['result'] ?? null)
        ? $json['result']
        : [];

    if (!$updates && $botResult['time'] >= 28) {
        echo '<p class="ok">Long polling работает.</p>';
    } elseif ($updates) {
        echo '<p class="note">Telegram вернул ожидающий update раньше timeout — это нормально.</p>';
    }
}

?>

<pre><?= h($botResult['body'] ?: $botResult['error']) ?></pre>

<?php endif; ?>

</div>


<p class="note">
После диагностики удалите test.php с публичного сервера.
</p>

</div>

</body>
</html>