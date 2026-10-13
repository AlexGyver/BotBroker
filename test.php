<?php

declare(strict_types=1);

// A held HTTP request for measuring hosting capacity independently of Telegram.
// No bot token is involved, and no session lock is used.
if (($_GET['probe'] ?? '') === 'hold') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Accel-Buffering: no');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'POST required']);
        exit;
    }
    $seconds = filter_input(INPUT_POST, 'seconds', FILTER_VALIDATE_INT);
    if ($seconds === false || $seconds === null || $seconds < 1 || $seconds > 120) {
        http_response_code(400);
        echo json_encode(['error' => 'seconds must be 1..120']);
        exit;
    }
    $start = microtime(true);
    // Record actual PHP execution time, not time queued by web server/browser.
    usleep($seconds * 1000000);
    $end = microtime(true);
    echo json_encode([
        'ok' => true,
        'started' => $start,
        'finished' => $end,
        'elapsed' => round($end - $start, 3),
        'pid' => getmypid(),
    ]);
    exit;
}

header('Content-Type: text/html; charset=utf-8');

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function badge(bool $ok): string
{
    return $ok ? '<span class="ok">OK</span>' : '<span class="fail">FAIL</span>';
}

function request(string $url, int $timeout = 20): array
{
    $start = microtime(true);
    $ch = curl_init($url);
    if ($ch === false) return ['ok' => false, 'http' => 0, 'ip' => '', 'error' => 'curl_init failed', 'time' => 0, 'body' => ''];
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

$token = trim((string) ($_POST['token'] ?? ''));
$action = (string) ($_POST['action'] ?? '');
$pollTimeoutRaw = $_POST['poll_timeout'] ?? 30;
$pollTimeout = filter_var($pollTimeoutRaw, FILTER_VALIDATE_INT);
$pollValid = $pollTimeout !== false && $pollTimeout >= 0 && $pollTimeout <= 600;
$pollTimeout = $pollValid ? $pollTimeout : 30;

$telegramIp = gethostbyname('api.telegram.org');
$telegramDnsOk = $telegramIp !== 'api.telegram.org';
$googleIp = gethostbyname('google.com');
$googleDnsOk = $googleIp !== 'google.com';
$httpsTest = function_exists('curl_init') ? request('https://api.telegram.org/') : null;

$botResult = null;
$botTitle = '';
$inputError = '';

if ($action === 'poll' && !$pollValid) $inputError = 'Timeout должен быть целым числом от 0 до 600 секунд.';
if (in_array($action, ['getme', 'poll'], true) && $token === '') $inputError = 'Введите токен бота.';
if (in_array($action, ['getme', 'poll'], true) && !function_exists('curl_init')) $inputError = 'На сервере отсутствует cURL.';

if ($inputError === '' && in_array($action, ['getme', 'poll'], true)) {
    $base = 'https://api.telegram.org/bot' . rawurlencode($token);
    if ($action === 'getme') {
        $botTitle = 'getMe';
        $botResult = request($base . '/getMe');
    } else {
        $botTitle = 'getUpdates?timeout=' . $pollTimeout;
        $botResult = request($base . '/getUpdates?timeout=' . $pollTimeout, max(20, $pollTimeout + 15));
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Telegram Broker Check</title>
<style>
*{box-sizing:border-box}
body{margin:0;padding:20px;background:#f5f5f5;font:14px/1.45 system-ui,sans-serif;color:#222}
.wrap{max-width:780px;margin:auto}
.card{background:white;border:1px solid #ddd;border-radius:9px;padding:16px;margin-bottom:12px}
h1{font-size:21px;margin:0 0 14px}h2{font-size:16px;margin:0 0 10px}
.row{display:flex;justify-content:space-between;gap:12px;padding:3px 0}
.ok{color:#16803a;font-weight:700}.fail{color:#b00020;font-weight:700}
input{width:100%;padding:9px;border:1px solid #bbb;border-radius:5px;font:inherit}
label{display:block;margin:9px 0 5px;font-weight:600}
button{padding:9px 13px;border:1px solid #aaa;background:#fafafa;border-radius:5px;cursor:pointer;font:inherit}
button:hover{background:#eee}button:disabled{opacity:.55;cursor:default}
.buttons{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
pre{margin:8px 0 0;padding:10px;background:#f4f4f4;border-radius:5px;overflow:auto;max-height:250px;white-space:pre-wrap;overflow-wrap:anywhere;font-size:12px}
.note{color:#666;font-size:12px;margin:9px 0 0}
.result-head{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
.report{white-space:pre-line;margin-top:12px;line-height:1.6;font-weight:500}.report:not(:empty){padding:12px;border:1px solid #ddd;border-radius:7px;background:#fafafa}
hr{border:0;border-top:1px solid #eee;margin:13px 0}
table{width:100%;border-collapse:collapse;margin-top:10px}th,td{padding:7px;border-bottom:1px solid #eee;text-align:left;font-size:12px}th{background:#fafafa}
@media(max-width:530px){.fields{grid-template-columns:1fr}.row{flex-wrap:wrap}}
</style>
</head>
<body>
<div class="wrap">
<h1>Telegram Broker Check</h1>
<div class="card">
<h2>Server</h2>
<div class="row"><span>PHP</span><span><?= h(PHP_VERSION) ?></span></div>
<div class="row"><span>cURL</span><?= badge(function_exists('curl_init')) ?></div>
<div class="row"><span>max_execution_time</span><span><?= h((string) ini_get('max_execution_time')) ?> sec (0 = без лимита PHP)</span></div>
</div>
<div class="card">
<h2>Network</h2>
<div class="row"><span>api.telegram.org DNS</span><span><?= badge($telegramDnsOk) ?> <?= $telegramDnsOk ? h($telegramIp) : '' ?></span></div>
<div class="row"><span>google.com DNS</span><span><?= badge($googleDnsOk) ?> <?= $googleDnsOk ? h($googleIp) : '' ?></span></div>
<?php if ($httpsTest !== null): ?>
<div class="row"><span>HTTPS → Telegram</span><span><?= badge($httpsTest['ok']) ?> <?= h((string) $httpsTest['ip']) ?></span></div>
<?php if (!$httpsTest['ok']): ?><pre><?= h($httpsTest['error']) ?></pre><?php endif; ?>
<?php endif; ?>
</div>
<div class="card">
<h2>1. Telegram long polling</h2>
<form method="post">
<label for="token">Bot token</label>
<input id="token" type="password" name="token" value="<?= h($token) ?>" placeholder="123456789:AA..." autocomplete="off" required>
<label for="poll_timeout">Запрошенный timeout, секунд (0–600)</label>
<input id="poll_timeout" type="number" name="poll_timeout" min="0" max="600" step="1" value="<?= $pollTimeout ?>" required>
<div class="buttons">
<button type="submit" name="action" value="getme">Проверить getMe</button>
<button type="submit" name="action" value="poll">Проверить long polling</button>
</div>
</form>
<?php if ($inputError !== ''): ?><p class="fail"><?= h($inputError) ?></p><?php endif; ?>
<?php if ($botResult !== null):
$json = json_decode($botResult['body'], true);
$telegramOk = $botResult['ok'] && $botResult['http'] === 200 && is_array($json) && ($json['ok'] ?? false) === true;
?>
<hr>
<div class="result-head"><strong><?= h($botTitle) ?></strong><?= badge($telegramOk) ?><span><?= round($botResult['time'], 2) ?> sec</span><span>HTTP <?= h((string)$botResult['http']) ?></span></div>
<?php if ($action === 'poll' && $telegramOk):
$updates = is_array($json['result'] ?? null) ? $json['result'] : [];
if ($updates) {
    echo '<p class="note">Telegram вернул накопленные updates раньше времени: это не измерение максимальной длительности соединения.</p>';
} elseif ($pollTimeout > 0 && $botResult['time'] >= $pollTimeout - 2) {
    echo '<p class="ok">Запрос удерживался примерно заданное время. Но предел хостинга этим не определяется — смотрите тест ниже.</p>';
} else {
    echo '<p class="note">Пустой ответ получен раньше запрошенного timeout. Проверьте ограничения Telegram и промежуточных прокси.</p>';
}
endif; ?>
<pre><?= h((string) ($botResult['body'] ?: $botResult['error'])) ?></pre>
<?php endif; ?>
</div>
<div class="card">
<h2>2. Удержание запроса на своём хостинге</h2>
<p>Сервер удерживает обычный HTTP-запрос указанное число секунд, без Telegram. Это позволяет проверить таймауты веб-сервера, PHP-FPM и прокси.</p>
<div class="fields">
<div><label for="holdSeconds">Длительность, секунд (1–120)</label><input id="holdSeconds" type="number" min="1" max="120" value="30"></div>
<div><label for="holdCount">Одновременных запросов (1–60)</label><input id="holdCount" type="number" min="1" max="60" value="10"></div>
</div>
<div class="buttons">
<button id="startTest" type="button">Запустить проверку</button>
<button id="stopTest" type="button" disabled>Остановить</button>
</div>
<p id="progress" class="note" aria-live="polite">Тест ещё не запущен.</p>
<div id="summary" class="report" role="status" aria-live="polite"></div>
<div style="overflow-x:auto"><table id="details" hidden><thead><tr><th>№</th><th>HTTP</th><th>Время клиента</th><th>Время PHP</th><th>Итог</th></tr></thead><tbody></tbody></table></div>
</div>
</div>
<script>
(() => {
  const startButton = document.getElementById('startTest');
  const stopButton = document.getElementById('stopTest');
  const progress = document.getElementById('progress');
  const summary = document.getElementById('summary');
  const details = document.getElementById('details');
  const tbody = details.querySelector('tbody');
  let controllers = [];
  let running = false;
  const fmt = x => Number(x).toFixed(2) + ' с';
  const peakOverlap = intervals => {
    const points = [];
    intervals.forEach(([a,b]) => { points.push([a,1], [b,-1]); });
    points.sort((a,b) => a[0] - b[0] || a[1] - b[1]);
    let now = 0, peak = 0;
    for (const [,delta] of points) { now += delta; peak = Math.max(peak,now); }
    return peak;
  };
  stopButton.addEventListener('click', () => controllers.forEach(c => c.abort()));
  startButton.addEventListener('click', async () => {
    if (running) return;
    const seconds = Number(document.getElementById('holdSeconds').value);
    const count = Number(document.getElementById('holdCount').value);
    if (!Number.isInteger(seconds) || seconds < 1 || seconds > 120 || !Number.isInteger(count) || count < 1 || count > 60) {
      progress.textContent = 'Укажите целые числа: время 1–120, запросы 1–60.'; return;
    }
    running = true;
    startButton.disabled = true;
    stopButton.disabled = false;
    summary.textContent = '';
    tbody.replaceChildren();
    details.hidden = false;
    controllers = Array.from({length:count}, () => new AbortController());
    const results = [];
    const testStarted = performance.now();
    const testUrl = new URL(window.location.href);
    testUrl.search = '';
    testUrl.searchParams.set('probe','hold');
    let done = 0;
    progress.textContent = `Запущено ${count} запросов по ${seconds} с…`;
    const jobs = controllers.map(async (controller, i) => {
      const begin = performance.now();
      let item = {number:i+1, status:'—', elapsed:0, php:null, result:'Ошибка'};
      const watchdog = setTimeout(() => controller.abort(), (seconds + 35) * 1000);
      try {
        const response = await fetch(testUrl.toString(), {
          method:'POST',
          body:new URLSearchParams({seconds:String(seconds)}),
          cache:'no-store',
          signal:controller.signal,
        });
        const payload = await response.json();
        item.status = response.status;
        item.php = payload;
        item.result = response.ok && payload.ok ? 'OK' : (payload.error || 'HTTP/JSON error');
      } catch (e) {
        item.result = controller.signal.aborted ? 'Прерван / таймаут' : String(e.message || e);
      } finally {
        clearTimeout(watchdog);
        item.elapsed = (performance.now() - begin) / 1000;
        results.push(item);
        done++;
        progress.textContent = `Завершено ${done} из ${count}`;
        const tr = document.createElement('tr');
        [item.number, item.status, fmt(item.elapsed), item.php?.elapsed != null ? fmt(item.php.elapsed) : '—', item.result].forEach(v => {
          const td = document.createElement('td'); td.textContent = String(v); tr.appendChild(td);
        });
        tbody.appendChild(tr);
      }
    });
    await Promise.all(jobs);
    const good = results.filter(r => r.result === 'OK' &&
      Number.isFinite(r.php?.started) && Number.isFinite(r.php?.finished) &&
      r.php.finished >= r.php.started);
    const intervals = good.map(r => [r.php.started, r.php.finished]);
    const peak = peakOverlap(intervals);
    const firstStart = good.length ? Math.min(...good.map(r => r.php.started)) : 0;
    const lastFinish = good.length ? Math.max(...good.map(r => r.php.finished)) : 0;
    const span = lastFinish - firstStart;
    const totalWorkerSeconds = good.reduce((sum,r) => sum + (r.php.finished-r.php.started), 0);
    const average = span > 0 ? totalWorkerSeconds / span : 0;
    const completedClose = good.filter(r => r.elapsed <= seconds + 5).length;
    const maxClient = Math.max(0, ...results.map(r => r.elapsed));
    const failed = count - good.length;
    const pids = new Set(good.map(r => r.php.pid).filter(v => Number.isInteger(v)));
    const waves = peak > 0 ? Math.ceil(good.length / peak) : 0;
    let conclusion;
    if (!good.length) conclusion = 'Параллельность определить не удалось: ни один запрос не вернул корректные серверные времена.';
    else if (failed) conclusion = `Подтверждено не менее ${peak} одновременно выполнявшихся PHP-запросов, но ${failed} запрос(ов) завершились с ошибкой. Точную ёмкость определить нельзя.`;
    else if (peak === count) conclusion = `Все ${count} запросов выполнялись одновременно. Сервер подтвердил параллельность не ниже ${count}; верхний предел не достигнут.`;
    else conclusion = `Подтверждено до ${peak} одновременно выполнявшихся PHP-запросов из ${count}. Остальные стартовали позднее. Это не обязательно предел хостинга: запросы могли ждать соединение в браузере, прокси или свободный PHP-воркер.`;
    summary.textContent =
      `ИТОГ: ${conclusion}\n\n` +
      `Пик одновременных PHP-запросов: ${peak} из ${count}\n` +
      `Средняя параллельность во время работы PHP: ${average.toFixed(2)}\n` +
      `Успешно / с ошибкой: ${good.length} / ${failed}\n` +
      `Ориентировочное число волн: ${waves || '—'}\n` +
      `Интервал работы PHP всей группы: ${fmt(span)}\n` +
      `Общее время теста в браузере: ${fmt((performance.now() - testStarted) / 1000)}\n` +
      `Самый долгий ответ браузеру: ${fmt(maxClient)}\n` +
      `Ответы без заметной задержки (≤ ${seconds + 5} с): ${completedClose} из ${count}\n` +
      `Уникальных PHP PID: ${pids.size || 'неизвестно'}`;
    progress.textContent = 'Проверка завершена.';
    controllers = [];
    running = false;
    startButton.disabled = false;
    stopButton.disabled = true;
  });
})();
</script>
</body>
</html>
