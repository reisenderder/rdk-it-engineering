<?php
/**
 * RDK IT Engineering — общие функции Telegram/БД для webhook.php и intake.php.
 * Единая точка логирования и вызовов Bot API, чтобы поведение двух обработчиков
 * (кнопки в группе и приём заявок с сайта) не расходилось.
 */

declare(strict_types=1);

// -----------------------------------------------------------------------------
// ДИАГНОСТИЧЕСКИЙ ЛОГ (файл-точка → закрыт от веба правилом nginx `location ~ /\.`)
// -----------------------------------------------------------------------------
function whLog(string $method, $resp, string $extra = ''): void {
    $ok   = (is_array($resp) && !empty($resp['ok'])) ? 'OK' : 'FAIL';
    $desc = (is_array($resp) && isset($resp['description'])) ? $resp['description'] : '';
    $line = date('Y-m-d H:i:s') . " | {$method} | {$ok} | {$desc}{$extra}\n";
    @file_put_contents(__DIR__ . '/.wh.log', $line, FILE_APPEND | LOCK_EX);
}

// -----------------------------------------------------------------------------
// ИНИЦИАЛИЗАЦИЯ АВТОНОМНОЙ БАЗЫ ДАННЫХ (SQLite на Vultr)
// -----------------------------------------------------------------------------
function getDb(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dbPath = __DIR__ . '/crm.sqlite';
        $pdo = new PDO("sqlite:{$dbPath}");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS leads (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                master_msg_id INTEGER,
                active_thread_id INTEGER,
                client_name TEXT,
                service TEXT,
                email TEXT,
                contact TEXT,
                task TEXT,
                status TEXT,
                created_at TEXT,
                updated_at TEXT
            );
            CREATE TABLE IF NOT EXISTS events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                master_msg_id INTEGER,
                event_type TEXT,
                actor TEXT,
                details TEXT,
                timestamp TEXT
            );
            CREATE TABLE IF NOT EXISTS pending_posts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                client_name TEXT,
                service TEXT,
                email TEXT,
                contact TEXT,
                task TEXT,
                general_msg_id INTEGER,
                attempts INTEGER DEFAULT 0,
                status TEXT DEFAULT 'pending',
                created_ts INTEGER,
                created_at TEXT,
                updated_at TEXT
            );
        ");
    }
    return $pdo;
}

// Функция вызовов Telegram Bot API (cURL + резерв на stream, с логированием ответа)
function tgApiCall(string $botToken, string $method, array $params): ?array {
    $url = "https://api.telegram.org/bot{$botToken}/{$method}";
    $jsonPayload = json_encode($params, JSON_UNESCAPED_UNICODE);
    $decoded = null;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonPayload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        $res     = curl_exec($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);
        if ($res !== false && $res !== '') {
            $tmp = json_decode($res, true);
            if (is_array($tmp)) {
                $decoded = $tmp;
            }
        }
        if ($decoded === null && $curlErr !== '') {
            whLog($method, null, " | curl_error: {$curlErr}");
        }
    }

    if ($decoded === null) {
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\n",
                'content'       => $jsonPayload,
                'timeout'       => 5,
                'ignore_errors' => true
            ],
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false
            ]
        ]);
        $res = @file_get_contents($url, false, $ctx);
        if ($res) {
            $tmp = json_decode($res, true);
            if (is_array($tmp)) {
                $decoded = $tmp;
            }
        }
    }

    whLog($method, $decoded);
    return $decoded;
}

// Формирует текст мастер-карточки заявки (используется intake.php и retry_intake.php)
function buildLeadCardText(string $service, string $name, string $email, string $contact, string $task, string $requestTime, string $prefixNote = ''): string {
    $esc = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $note = $prefixNote !== '' ? $prefixNote . "\n" : '';
    return $note
         . "🔔 <b>ЗАЯВКА С САЙТА: RDK IT ENGINEERING</b>\n"
         . "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
         . "📂 <b>Направление:</b> " . $esc($service) . "\n"
         . "👤 <b>Клиент:</b> " . $esc($name) . "\n"
         . "✉️ <b>Email:</b> " . $esc($email) . "\n"
         . "📱 <b>Контакт:</b> " . $esc($contact) . "\n"
         . "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
         . "📝 <b>Суть задачи:</b>\n" . $esc($task) . "\n"
         . "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
         . "📜 <b>ЖУРНАЛ ПРОЕКТА (Changelog):</b>\n"
         . "• {$requestTime} — 📥 Заявка поступила с сайта";
}

// Кнопки связи (WhatsApp / Telegram) по строке контакта клиента
function buildContactRow(string $contact, string $name, string $service): array {
    $row = [];
    if (preg_match('/@([a-zA-Z0-9_]{4,32})/', $contact, $m)) {
        $row[] = ['text' => '💬 Написать в Telegram', 'url' => "https://t.me/{$m[1]}"];
    } elseif (preg_match('#t\.me/([a-zA-Z0-9_]{4,32})#', $contact, $m)) {
        $row[] = ['text' => '💬 Написать в Telegram', 'url' => "https://t.me/{$m[1]}"];
    }
    $cleanPhone = preg_replace('/\D+/', '', $contact);
    if (strlen($cleanPhone) >= 10 && strlen($cleanPhone) <= 15) {
        if (strlen($cleanPhone) === 11 && $cleanPhone[0] === '8') {
            $cleanPhone = '7' . substr($cleanPhone, 1);
        }
        $waText = rawurlencode("Здравствуйте, {$name}! Вы оставили заявку на сайте RDK IT Engineering по направлению «{$service}».");
        $row[] = ['text' => '🟢 Написать в WhatsApp', 'url' => "https://wa.me/{$cleanPhone}?text={$waText}"];
    }
    return $row;
}
