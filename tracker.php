<?php
/**
 * RDK IT Engineering — Автономный микро-трекер посещений
 * Соответствие 152-ФЗ РФ: IP-адреса анонимизируются перед сохранением.
 * Данные хранятся в защищённом от прямого скачивания файле.
 */

declare(strict_types=1);

date_default_timezone_set('Europe/Moscow');

$storageFile = __DIR__ . '/_visits.log.php';

// Проверка секретного ключа для просмотра статистики (из config.php или fallback)
$statsSecret = 'rdk_admin_stats_2026';
if (file_exists(__DIR__ . '/config.php')) {
    include_once __DIR__ . '/config.php';
    if (!empty($intakeSecret)) {
        $statsSecret = $intakeSecret;
    }
}

// -----------------------------------------------------------------------------
// 1. ПРОСМОТР СТАТИСТИКИ (?view=1&key=SECRET)
// -----------------------------------------------------------------------------
if (isset($_GET['view'])) {
    $providedKey = (string)($_GET['key'] ?? '');
    if (!hash_equals($statsSecret, $providedKey)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "403 Доступ запрещён. Укажите правильный ключ в параметре key.";
        exit;
    }

    header('Content-Type: text/html; charset=utf-8');

    $visits = [];
    if (file_exists($storageFile)) {
        $lines = file($storageFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines !== false) {
            foreach ($lines as $line) {
                if (strpos($line, '<?php') === 0) continue;
                $row = json_decode($line, true);
                if (is_array($row)) {
                    $visits[] = $row;
                }
            }
        }
    }

    $total = count($visits);
    $todayCount = 0;
    $todayDate = date('Y-m-d');
    $devices = ['Mobile' => 0, 'Desktop' => 0, 'Tablet' => 0, 'Other' => 0];
    $referrers = [];

    foreach ($visits as $v) {
        if (($v['date'] ?? '') === $todayDate) {
            $todayCount++;
        }
        $dev = $v['device'] ?? 'Other';
        $devices[$dev] = ($devices[$dev] ?? 0) + 1;

        $ref = $v['ref_domain'] ?? 'Прямой заход';
        $referrers[$ref] = ($referrers[$ref] ?? 0) + 1;
    }
    arsort($referrers);

    $recent = array_slice(array_reverse($visits), 0, 50);
    ?>
    <!DOCTYPE html>
    <html lang="ru">
    <head>
      <meta charset="utf-8">
      <title>Статистика посещений — RDK IT</title>
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, monospace; margin: 0; padding: 24px; background: #f5f3ee; color: #272720; }
        .container { max-width: 1000px; margin: 0 auto; }
        h1 { font-size: 24px; color: #014b52; margin-bottom: 20px; }
        .cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .card { background: #fff; border: 1px solid #e2e6e0; border-radius: 8px; padding: 18px; box-shadow: 0 2px 8px rgba(0,0,0,0.03); }
        .card-num { font-size: 32px; font-weight: 700; color: #016a71; }
        .card-label { font-size: 12px; color: #67685f; text-transform: uppercase; margin-top: 4px; font-family: monospace; }
        table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.03); font-size: 13px; }
        th, td { padding: 10px 14px; text-align: left; border-bottom: 1px solid #f0eee8; }
        th { background: #faf9f6; font-family: monospace; color: #67685f; text-transform: uppercase; font-size: 11px; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 999px; background: rgba(1,106,113,0.1); color: #016a71; font-size: 11px; font-weight: 600; font-family: monospace; }
        .law-note { margin-top: 20px; font-size: 12px; color: #71726b; font-family: monospace; }
      </style>
    </head>
    <body>
      <div class="container">
        <h1>RDK IT — Аналитика визитов</h1>
        <div class="cards">
          <div class="card">
            <div class="card-num"><?= $total ?></div>
            <div class="card-label">Всего визитов</div>
          </div>
          <div class="card">
            <div class="card-num"><?= $todayCount ?></div>
            <div class="card-label">Визитов сегодня</div>
          </div>
          <div class="card">
            <div class="card-num"><?= $devices['Mobile'] ?> / <?= $devices['Desktop'] ?></div>
            <div class="card-label">Mobile / Desktop</div>
          </div>
        </div>

        <h2 style="font-size: 18px; margin-top: 32px;">Последние 50 визитов</h2>
        <table>
          <thead>
            <tr>
              <th>Время (МСК)</th>
              <th>Устройство</th>
              <th>Страница</th>
              <th>Источник</th>
              <th>Анонимный IP (152-ФЗ)</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($recent)): ?>
              <tr><td colspan="5" style="text-align: center; padding: 20px; color: #67685f;">Визитов пока не зафиксировано</td></tr>
            <?php else: ?>
              <?php foreach ($recent as $r): ?>
                <tr>
                  <td><?= htmlspecialchars($r['time'] ?? '') ?></td>
                  <td><span class="badge"><?= htmlspecialchars($r['device'] ?? 'Desktop') ?></span></td>
                  <td><?= htmlspecialchars($r['page'] ?? '/') ?></td>
                  <td><?= htmlspecialchars($r['ref_domain'] ?? 'Прямой') ?></td>
                  <td><code style="font-size: 12px;"><?= htmlspecialchars($r['ip_anon'] ?? '—') ?></code></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>

        <div class="law-note">
          🔒 В соответствии со ст. 6 и 9 Федерального закона № 152-ФЗ все IP-адреса посетителей анонимизируются (последний октет маскируется), персональные данные не раскрываются.
        </div>
      </div>
    </body>
    </html>
    <?php
    exit;
}

// -----------------------------------------------------------------------------
// 2. ФИКСАЦИЯ ВИЗИТА (POST или GET)
// -----------------------------------------------------------------------------
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$rawInput = file_get_contents('php://input');
$payload = json_decode($rawInput, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$page = trim((string)($payload['page'] ?? ($_SERVER['HTTP_REFERER'] ?? '/')));
$page = parse_url($page, PHP_URL_PATH) ?: '/';

$referrer = trim((string)($payload['ref'] ?? ($_SERVER['HTTP_REFERER'] ?? '')));
$refDomain = 'Прямой заход';
if (!empty($referrer)) {
    $host = parse_url($referrer, PHP_URL_HOST);
    if ($host && stripos($host, 'rdk-ai.com') === false) {
        $refDomain = $host;
    }
}

// Определение типа устройства по User-Agent
$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
$device = 'Desktop';
if (preg_match('/(ipad|tablet|(android(?!.*mobile))|(windows(?!.*phone)(.*touch))|kindle|playbook|silk|(puffin(?!.*(IP|AP|WP))))/i', $ua)) {
    $device = 'Tablet';
} elseif (preg_match('/(mobi|ipod|phone|blackberry|opera mini|fennec|minimo|symbian|psp|nintendo ds)/i', $ua)) {
    $device = 'Mobile';
}

// Анонимизация IP-адреса в строгом соответствии с 152-ФЗ РФ и GDPR
$rawIp = (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
$anonIp = $rawIp;
if (filter_var($rawIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    // 192.168.1.123 -> 192.168.1.***
    $parts = explode('.', $rawIp);
    $parts[3] = '***';
    $anonIp = implode('.', $parts);
} elseif (filter_var($rawIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
    // Маскирование IPv6
    $anonIp = substr($rawIp, 0, strrpos($rawIp, ':') ?: 10) . ':****';
}

$entry = [
    'date'       => date('Y-m-d'),
    'time'       => date('d.m.Y H:i:s'),
    'page'       => mb_substr($page, 0, 100),
    'device'     => $device,
    'ref_domain' => mb_substr($refDomain, 0, 100),
    'ip_anon'    => $anonIp
];

// Инициализация файла с защитной строкой выхода при необходимости
if (!file_exists($storageFile)) {
    file_put_contents($storageFile, "<?php exit; ?>\n", LOCK_EX);
}

// Добавление строки визита
file_put_contents($storageFile, json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
