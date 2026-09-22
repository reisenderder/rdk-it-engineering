<?php
/**
 * RDK IT Engineering — Intake relay (Vultr Gateway)
 * Принимает заявку с сайта от send.php (Timeweb) и публикует мастер-карточку
 * в тему «📋 Реестр & Changelog». Timeweb не имеет доступа к api.telegram.org,
 * поэтому отправка в Telegram выполняется здесь, на Vultr.
 * Доступ защищён общим секретом ($intakeSecret из config.php).
 */

declare(strict_types=1);

date_default_timezone_set('Europe/Moscow');

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$tgBotToken   = '';
$tgChatId     = '';
$intakeSecret = '';
if (file_exists(__DIR__ . '/config.php')) {
    require __DIR__ . '/config.php';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST only'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (empty($tgBotToken) || empty($tgChatId) || empty($intakeSecret)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Config missing'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST;
}

// Аутентификация по общему секрету (заголовок или поле)
$provided = (string)($data['secret'] ?? ($_SERVER['HTTP_X_INTAKE_SECRET'] ?? ''));
if ($provided === '' || !hash_equals($intakeSecret, $provided)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$service = trim((string)($data['service'] ?? 'Индивидуальный проект'));
$name    = trim((string)($data['name'] ?? ''));
$email   = trim((string)($data['email'] ?? ''));
$contact = trim((string)($data['contact'] ?? 'Не указан'));
$task    = trim((string)($data['task'] ?? ''));

if ($name === '' || $task === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'name and task required'], JSON_UNESCAPED_UNICODE);
    exit;
}

$registryThreadId = 91; // Постоянная тема «📋 Реестр & Changelog»
$requestTime = date('d.m.Y H:i:s') . ' (МСК)';

function tgApiCall(string $botToken, string $method, array $params): ?array {
    $url = "https://api.telegram.org/bot{$botToken}/{$method}";
    $jsonPayload = json_encode($params, JSON_UNESCAPED_UNICODE);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonPayload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        if ($res !== false && $res !== '') {
            $decoded = json_decode($res, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
    }
    $ctx = stream_context_create([
        'http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $jsonPayload, 'timeout' => 10, 'ignore_errors' => true],
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false]
    ]);
    $res = @file_get_contents($url, false, $ctx);
    return $res ? json_decode($res, true) : null;
}

$esc = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

// Кнопки связи (WhatsApp / Telegram) по контакту клиента
$contactRow = [];
if (preg_match('/@([a-zA-Z0-9_]{4,32})/', $contact, $m)) {
    $contactRow[] = ['text' => '💬 Написать в Telegram', 'url' => "https://t.me/{$m[1]}"];
} elseif (preg_match('#t\.me/([a-zA-Z0-9_]{4,32})#', $contact, $m)) {
    $contactRow[] = ['text' => '💬 Написать в Telegram', 'url' => "https://t.me/{$m[1]}"];
}
$cleanPhone = preg_replace('/\D+/', '', $contact);
if (strlen($cleanPhone) >= 10 && strlen($cleanPhone) <= 15) {
    if (strlen($cleanPhone) === 11 && $cleanPhone[0] === '8') {
        $cleanPhone = '7' . substr($cleanPhone, 1);
    }
    $waText = rawurlencode("Здравствуйте, {$name}! Вы оставили заявку на сайте RDK IT Engineering по направлению «{$service}».");
    $contactRow[] = ['text' => '🟢 Написать в WhatsApp', 'url' => "https://wa.me/{$cleanPhone}?text={$waText}"];
}

$keyboard = [];
if (!empty($contactRow)) {
    $keyboard[] = $contactRow;
}
$keyboard[] = [['text' => '✋ Взять в проект', 'callback_data' => 'take_lead']];

$masterCardText = "🔔 <b>ЗАЯВКА С САЙТА: RDK IT ENGINEERING</b>\n"
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

$payload = [
    'chat_id'                  => $tgChatId,
    'reply_to_message_id'      => $registryThreadId,
    'text'                     => $masterCardText,
    'parse_mode'               => 'HTML',
    'disable_web_page_preview' => true,
    'reply_markup'             => ['inline_keyboard' => $keyboard]
];

$res = tgApiCall($tgBotToken, 'sendMessage', $payload);
if (empty($res['ok'])) {
    // Резерв: если тема Реестра недоступна — в общий чат
    unset($payload['reply_to_message_id']);
    $res = tgApiCall($tgBotToken, 'sendMessage', $payload);
}

$ok = !empty($res['ok']);
$desc = is_array($res) && isset($res['description']) ? $res['description'] : '';
@file_put_contents(__DIR__ . '/.wh.log', date('Y-m-d H:i:s') . " | INTAKE | " . ($ok ? 'OK' : 'FAIL') . " | {$desc} | {$name} · {$service}\n", FILE_APPEND | LOCK_EX);

echo json_encode([
    'ok'         => $ok,
    'message_id' => $res['result']['message_id'] ?? null
], JSON_UNESCAPED_UNICODE);
