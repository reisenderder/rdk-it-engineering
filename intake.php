<?php
/**
 * RDK IT Engineering — Intake relay (Vultr Gateway)
 * Принимает заявку с сайта от send.php (Timeweb) и публикует мастер-карточку
 * в тему «📋 Реестр & Changelog». Timeweb не имеет доступа к api.telegram.org,
 * поэтому отправка в Telegram выполняется здесь, на Vultr.
 * Доступ защищён общим секретом ($intakeSecret из config.php).
 *
 * Устойчивость к временным сбоям (флуд-лимит Telegram и т.п.):
 * 1) 3 быстрые попытки отправить в тему Реестра (91) с короткими паузами;
 * 2) если не удалось — карточка публикуется в General с явной пометкой
 *    «не удалось поставить в Реестр» и ставится в очередь pending_posts;
 * 3) retry_intake.php (запускается по крону) в течение ~10 минут пытается
 *    перенести её в Реестр автоматически; если не получилось — помечает
 *    карточку в General как требующую ручной обработки.
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
require_once __DIR__ . '/tg_common.php';

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

$masterCardText = buildLeadCardText($service, $name, $email, $contact, $task, $requestTime);
$contactRow     = buildContactRow($contact, $name, $service);
$keyboard       = [];
if (!empty($contactRow)) {
    $keyboard[] = $contactRow;
}
$keyboard[] = [['text' => '✋ Взять в проект', 'callback_data' => 'take_lead']];

// -----------------------------------------------------------------------------
// БЫСТРЫЕ ПОВТОРЫ: 3 попытки поставить карточку в тему Реестра.
// Большинство сбоев (флуд-лимит Telegram) снимаются за 1-3 секунды.
// -----------------------------------------------------------------------------
$attemptDelaysSec = [0, 2, 3]; // немедленно, затем +2с, затем +3с
$res = null;
$attemptsMade = 0;

foreach ($attemptDelaysSec as $i => $delay) {
    if ($delay > 0) {
        sleep($delay);
    }
    $attemptsMade++;
    $res = tgApiCall($tgBotToken, 'sendMessage', [
        'chat_id'                  => $tgChatId,
        'reply_to_message_id'      => $registryThreadId,
        'text'                     => $masterCardText,
        'parse_mode'               => 'HTML',
        'disable_web_page_preview' => true,
        'reply_markup'             => ['inline_keyboard' => $keyboard]
    ]);
    whLog('INTAKE.attempt', $res, " | попытка {$attemptsMade}/3 в Реестр | {$name} · {$service}");
    if (!empty($res['ok'])) {
        break;
    }
}

$ok = !empty($res['ok']);

if ($ok) {
    whLog('INTAKE', $res, " | встало в Реестр с {$attemptsMade}-й попытки | {$name} · {$service}");
    echo json_encode(['ok' => true, 'message_id' => $res['result']['message_id'] ?? null], JSON_UNESCAPED_UNICODE);
    exit;
}

// -----------------------------------------------------------------------------
// ВСЕ БЫСТРЫЕ ПОПЫТКИ ПРОВАЛИЛИСЬ: публикуем в General с явной пометкой
// и ставим в очередь для автоматического переноса (retry_intake.php).
// -----------------------------------------------------------------------------
$fallbackText = "⚠️ <b>НЕ УДАЛОСЬ АВТОМАТИЧЕСКИ ПОСТАВИТЬ В «РЕЕСТР & CHANGELOG»</b>\n"
              . "Идёт автоматический перенос (до ~10 минут). Если карточка всё ещё здесь —"
              . " перенесите вручную в тему «📋 Реестр & Changelog».\n"
              . "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
              . $masterCardText;

$fallbackRes = tgApiCall($tgBotToken, 'sendMessage', [
    'chat_id'                  => $tgChatId,
    'text'                     => $fallbackText,
    'parse_mode'               => 'HTML',
    'disable_web_page_preview' => true,
    'reply_markup'             => ['inline_keyboard' => $keyboard]
]);
whLog('INTAKE.fallback_general', $fallbackRes, " | {$name} · {$service}");

$generalMsgId = (int)($fallbackRes['result']['message_id'] ?? 0);

if ($generalMsgId > 0) {
    try {
        $db  = getDb();
        $now = date('d.m.Y H:i') . ' (МСК)';
        $stmt = $db->prepare("INSERT INTO pending_posts (client_name, service, email, contact, task, general_msg_id, attempts, status, created_ts, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?)");
        $stmt->execute([$name, $service, $email, $contact, $task, $generalMsgId, $attemptsMade, time(), $now, $now]);
    } catch (Throwable $e) {
        whLog('DB.pending_posts.insert', null, ' | ' . $e->getMessage());
    }
}

echo json_encode([
    'ok'         => $generalMsgId > 0,
    'message_id' => $generalMsgId > 0 ? $generalMsgId : null,
    'note'       => 'posted to General, queued for auto-retry'
], JSON_UNESCAPED_UNICODE);
