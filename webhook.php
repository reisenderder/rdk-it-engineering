<?php
/**
 * RDK IT Engineering — Telegram Webhook Handler (Vultr Gateway)
 * Реестр & Changelog, транзакционная безопасность и автономная SQLite БД
 */

declare(strict_types=1);

date_default_timezone_set('Europe/Moscow');

header('Content-Type: application/json; charset=utf-8');

// Подгружаем секретные ключи
$tgBotToken = '';
$tgChatId   = '';

if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

if (empty($tgBotToken) || empty($tgChatId)) {
    http_response_code(500);
    echo json_encode(['error' => 'Config not found']);
    exit;
}

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
        ");
    }
    return $pdo;
}

// Реестр лидов: создать запись при первом взятии, далее обновлять по master_msg_id.
// Ключи полей берутся из белого списка, поэтому интерполяция в SQL безопасна.
function upsertLead(int $masterMsgId, array $fields): void {
    static $allowed = ['active_thread_id', 'client_name', 'service', 'email', 'contact', 'task', 'status'];
    if ($masterMsgId <= 0) {
        return;
    }
    try {
        $db  = getDb();
        $now = date('d.m.Y H:i') . ' (МСК)';

        $data = [];
        foreach ($fields as $k => $v) {
            if (in_array($k, $allowed, true)) {
                $data[$k] = $v;
            }
        }

        $check = $db->prepare("SELECT id FROM leads WHERE master_msg_id = ? LIMIT 1");
        $check->execute([$masterMsgId]);
        $existing = $check->fetchColumn();

        if ($existing) {
            $sets = [];
            $vals = [];
            foreach ($data as $k => $v) {
                $sets[] = "{$k} = ?";
                $vals[] = $v;
            }
            $sets[] = "updated_at = ?";
            $vals[] = $now;
            $vals[] = $masterMsgId;
            $db->prepare("UPDATE leads SET " . implode(', ', $sets) . " WHERE master_msg_id = ?")->execute($vals);
        } else {
            $cols = array_keys($data);
            $cols[] = 'master_msg_id';
            $cols[] = 'created_at';
            $cols[] = 'updated_at';
            $vals = array_values($data);
            $vals[] = $masterMsgId;
            $vals[] = $now;
            $vals[] = $now;
            $ph = implode(', ', array_fill(0, count($cols), '?'));
            $db->prepare("INSERT INTO leads (" . implode(', ', $cols) . ") VALUES ({$ph})")->execute($vals);
        }
    } catch (Throwable $e) {
        whLog('DB.upsertLead', null, ' | ' . $e->getMessage());
    }
}

// Извлекаем данные клиента из ПЛОСКОГО текста карточки (Telegram отдаёт callback
// message.text уже без HTML-тегов — теги живут отдельно в entities).
function extractLeadFields(string $text): array {
    $out = ['client_name' => 'Клиент', 'service' => 'Проект', 'email' => '', 'contact' => '', 'task' => ''];
    if (preg_match('/👤\s*Клиент:\s*([^\n\r]+)/u', $text, $m)) {
        $out['client_name'] = trim($m[1]);
    }
    if (preg_match('/📂\s*Направление:\s*([^\n\r]+)/u', $text, $m)) {
        $out['service'] = trim($m[1]);
    }
    if (preg_match('/✉️\s*Email:\s*([^\n\r]+)/u', $text, $m)) {
        $out['email'] = trim($m[1]);
    }
    if (preg_match('/📱\s*Контакт:\s*([^\n\r]+)/u', $text, $m)) {
        $out['contact'] = trim($m[1]);
    }
    if (preg_match('/📝\s*Суть задачи:\s*([\s\S]+?)(?:\n━|\n📜|$)/u', $text, $m)) {
        $out['task'] = trim($m[1]);
    }
    return $out;
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

// Считываем входящий запрос от Telegram
$rawInput = file_get_contents('php://input');
$update = json_decode($rawInput, true);

if (!is_array($update) || empty($update['callback_query'])) {
    echo json_encode(['ok' => true]);
    exit;
}

$callbackQuery = $update['callback_query'];
$callbackId    = (string)($callbackQuery['id'] ?? '');
$callbackData  = (string)($callbackQuery['data'] ?? '');
$fromUser      = $callbackQuery['from'] ?? [];
$message       = $callbackQuery['message'] ?? [];

$messageId     = (int)($message['message_id'] ?? 0);
$chatId        = (int)($message['chat']['id'] ?? 0);
$threadId      = (int)($message['message_thread_id'] ?? 0);
$originalText  = (string)($message['text'] ?? '');
$replyMarkup   = $message['reply_markup'] ?? [];

// Защита: только наша группа
if ((string)$chatId !== (string)$tgChatId) {
    echo json_encode(['ok' => true]);
    exit;
}

$firstName = trim((string)($fromUser['first_name'] ?? 'Сотрудник'));
$username  = trim((string)($fromUser['username'] ?? ''));
$mention   = !empty($username) ? "@{$username}" : $firstName;
$nowTime   = date('d.m.Y H:i') . ' (МСК)';
$changelogThreadId = 91; // Постоянная тема «📋 Реестр & Changelog»

// Парсим префикс и аргументы callbackData (action:arg1:arg2)
$parts = explode(':', $callbackData);
$action = $parts[0];
$arg1   = isset($parts[1]) ? (int)$parts[1] : 0;
$arg2   = isset($parts[2]) ? (int)$parts[2] : 0;

whLog('INCOMING', ['ok' => true], " | action={$action} data={$callbackData} from={$mention} thread={$threadId}");

// Извлекаем только контактные кнопки (WhatsApp, Telegram)
function extractContactButtons(array $replyMarkup): array {
    $rows = [];
    if (!empty($replyMarkup['inline_keyboard'])) {
        foreach ($replyMarkup['inline_keyboard'] as $row) {
            $newRow = [];
            foreach ($row as $btn) {
                if (empty($btn['callback_data'])) {
                    $newRow[] = $btn;
                }
            }
            if (!empty($newRow)) {
                $rows[] = $newRow;
            }
        }
    }
    return $rows;
}

$contactButtons = extractContactButtons($replyMarkup);

// -----------------------------------------------------------------------------
// ДЕЙСТВИЕ 1: ВЗЯТЬ В ПРОЕКТ (take_lead)
// -----------------------------------------------------------------------------
if ($action === 'take_lead') {
    $masterMsgId = ($arg1 > 0) ? $arg1 : $messageId;

    // 1. Извлекаем данные клиента из плоского текста карточки
    $lead        = extractLeadFields($originalText);
    $clientName  = $lead['client_name'];
    $serviceName = $lead['service'];

    // 2. Создаём отдельную тему (Forum Topic) в Telegram под этот проект
    $topicName = "📁 " . mb_substr($clientName, 0, 26) . " · " . mb_substr($serviceName, 0, 32);
    $newTopic = tgApiCall($tgBotToken, 'createForumTopic', [
        'chat_id' => $chatId,
        'name'    => $topicName
    ]);

    $sprintThreadId = 0;
    if (!empty($newTopic['ok']) && !empty($newTopic['result']['message_thread_id'])) {
        $sprintThreadId = (int)$newTopic['result']['message_thread_id'];
    }

    // 3. Формируем текст со строкой Changelog
    $cleanText = preg_replace('/\n\n🚀 <b>Рабочий спринт:<\/b>[^\n\r]*/u', '', $originalText);
    $takenText = $cleanText . "\n• {$nowTime} — 🟢 Взят в работу ({$mention})";

    // 4. Обновляем мастер-карточку в теме «📋 Реестр & Changelog»
    $masterNotice = $sprintThreadId > 0
        ? "\n\n🚀 <b>Рабочий спринт:</b> открыта тема <i>«{$topicName}»</i>"
        : "";
    $masterKeyboard = $contactButtons;
    $masterKeyboard[] = [
        ['text' => '🟢 В активной работе в боковой теме', 'callback_data' => 'noop']
    ];

    tgApiCall($tgBotToken, 'editMessageText', [
        'chat_id'                  => $chatId,
        'message_id'               => $masterMsgId,
        'text'                     => $takenText . $masterNotice,
        'parse_mode'               => 'HTML',
        'disable_web_page_preview' => true,
        'reply_markup'             => ['inline_keyboard' => $masterKeyboard]
    ]);

    // 5. Отправляем рабочую карточку проекта в созданную тему спринта.
    // ВАЖНО: в только что созданную тему пишем через reply_to_message_id (id темы =
    // id её якорного сообщения). message_thread_id для свежих тем Telegram отклоняет.
    if ($sprintThreadId > 0) {
        $workKeyboard = $contactButtons;
        $workKeyboard[] = [
            ['text' => '📦 Завершить проект / В архив', 'callback_data' => "archive_lead:{$masterMsgId}:{$sprintThreadId}"]
        ];

        tgApiCall($tgBotToken, 'sendMessage', [
            'chat_id'                  => $chatId,
            'reply_to_message_id'      => $sprintThreadId,
            'text'                     => $takenText,
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => true,
            'reply_markup'             => ['inline_keyboard' => $workKeyboard]
        ]);
    }

    // 6. Фиксация в автономной базе данных SQLite: событие + запись в реестре лидов
    try {
        $db = getDb();
        $stmt = $db->prepare("INSERT INTO events (master_msg_id, event_type, actor, details, timestamp) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$masterMsgId, 'take_lead', $mention, "Взят в работу, создана тема: {$topicName}", $nowTime]);
    } catch (Throwable $e) {
        whLog('DB.events.take_lead', null, ' | ' . $e->getMessage());
    }
    upsertLead($masterMsgId, [
        'active_thread_id' => $sprintThreadId,
        'client_name'      => $clientName,
        'service'          => $serviceName,
        'email'            => $lead['email'],
        'contact'          => $lead['contact'],
        'task'             => $lead['task'],
        'status'           => '🟢 В работе (' . $mention . ')'
    ]);

    tgApiCall($tgBotToken, 'answerCallbackQuery', [
        'callback_query_id' => $callbackId,
        'text'              => "Вы назначены ответственным! В боковой ленте создана тема «{$topicName}».",
        'show_alert'        => false
    ]);

    echo json_encode(['ok' => true]);
    exit;
}

// -----------------------------------------------------------------------------
// ДЕЙСТВИЕ 2: ЗАВЕРШИТЬ ПРОЕКТ (archive_lead)
// -----------------------------------------------------------------------------
if ($action === 'archive_lead') {
    $masterMsgId    = ($arg1 > 0) ? $arg1 : 0;
    $sprintThreadId = ($arg2 > 0) ? $arg2 : $threadId;

    if ($masterMsgId === 0 && ($threadId === $changelogThreadId || $threadId === 0)) {
        $masterMsgId = $messageId;
    }

    // 1. Формируем финальный текст с обновлением Changelog (убираем временные технические пометки)
    $cleanText = preg_replace('/\n\n🚀 <b>Рабочий спринт:<\/b>[^\n\r]*/u', '', $originalText);
    $finalChangelogText = $cleanText . "\n• {$nowTime} — 🏁 Проект сдан в архив ({$mention})";

    $reopenTargetId = $masterMsgId > 0 ? $masterMsgId : $messageId;
    $masterArchiveKeyboard = $contactButtons;
    $masterArchiveKeyboard[] = [
        ['text' => '🔄 Возобновить проект / В работу', 'callback_data' => "reopen_lead:{$reopenTargetId}"]
    ];

    $savedToRegistry = false;

    // 2. Обновляем мастер-карточку в теме «📋 Реестр & Changelog»
    if ($masterMsgId > 0) {
        $editRes = tgApiCall($tgBotToken, 'editMessageText', [
            'chat_id'                  => $chatId,
            'message_id'               => $masterMsgId,
            'text'                     => $finalChangelogText,
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => true,
            'reply_markup'             => ['inline_keyboard' => $masterArchiveKeyboard]
        ]);
        $savedToRegistry = !empty($editRes['ok']);
    }

    // Если мастер-сообщение не было найдено, отправляем итоговую карточку в тему Реестра.
    // В тему пишем через reply_to_message_id (устойчиво и для свежих тем).
    if (!$savedToRegistry) {
        $postRes = tgApiCall($tgBotToken, 'sendMessage', [
            'chat_id'                  => $chatId,
            'reply_to_message_id'      => $changelogThreadId,
            'text'                     => $finalChangelogText,
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => true,
            'reply_markup'             => ['inline_keyboard' => $masterArchiveKeyboard]
        ]);
        if (!empty($postRes['ok']) && !empty($postRes['result']['message_id'])) {
            $savedToRegistry = true;
            $masterMsgId = (int)$postRes['result']['message_id'];
        }
    }

    // 3. Фиксация в автономной базе данных SQLite: событие + статус лида
    try {
        $db = getDb();
        $stmt = $db->prepare("INSERT INTO events (master_msg_id, event_type, actor, details, timestamp) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$masterMsgId, 'archive_lead', $mention, 'Проект завершён и сдан в архив', $nowTime]);
    } catch (Throwable $e) {
        whLog('DB.events.archive_lead', null, ' | ' . $e->getMessage());
    }
    upsertLead($masterMsgId, [
        'status'           => '🏁 Архив (' . $mention . ')',
        'active_thread_id' => 0
    ]);

    // 4. ТРАНЗАКЦИОННАЯ БЕЗОПАСНОСТЬ:
    // Удаляем рабочую тему ТОЛЬКО если статус 100% зафиксирован в Реестре!
    if ($savedToRegistry) {
        if ($sprintThreadId > 0 && $sprintThreadId !== $changelogThreadId) {
            tgApiCall($tgBotToken, 'deleteForumTopic', [
                'chat_id'           => $chatId,
                'message_thread_id' => $sprintThreadId
            ]);
        }

        tgApiCall($tgBotToken, 'answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text'              => "Проект завершён и зафиксирован в «📋 Реестр & Changelog». Рабочая лента очищена!",
            'show_alert'        => false
        ]);
    } else {
        // ПРЕДОХРАНИТЕЛЬ: если в реестр не записалось, тему НЕ удаляем, а просто закрываем замком!
        if ($sprintThreadId > 0 && $sprintThreadId !== $changelogThreadId) {
            tgApiCall($tgBotToken, 'closeForumTopic', [
                'chat_id'           => $chatId,
                'message_thread_id' => $sprintThreadId
            ]);
        }

        tgApiCall($tgBotToken, 'answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text'              => "Внимание: тема закрыта, но оставлена на месте во избежание потери данных.",
            'show_alert'        => true
        ]);
    }

    echo json_encode(['ok' => true]);
    exit;
}

// -----------------------------------------------------------------------------
// ДЕЙСТВИЕ 3: ВОЗОБНОВИТЬ ПРОЕКТ (reopen_lead) — из «📋 Реестр & Changelog»
// -----------------------------------------------------------------------------
if ($action === 'reopen_lead') {
    $targetMasterId = ($arg1 > 0) ? $arg1 : $messageId;

    // 1. Дописываем шаг возобновления в Changelog мастер-карточки
    $cleanText = preg_replace('/\n\n🚀 <b>Рабочий спринт:<\/b>[^\n\r]*/u', '', $originalText);
    $reopenedText = $cleanText . "\n• {$nowTime} — 🔁 Возобновлён в работу ({$mention})";

    // 2. Извлекаем данные клиента из плоского текста карточки
    $lead         = extractLeadFields($originalText);
    $clientName   = $lead['client_name'];
    $serviceName  = $lead['service'];

    // 3. Создаём новую тему в боковой панели (Рабочий спринт)
    $newTopicName = "📁 " . mb_substr($clientName, 0, 26) . " · " . mb_substr($serviceName, 0, 32);
    $newTopic = tgApiCall($tgBotToken, 'createForumTopic', [
        'chat_id' => $chatId,
        'name'    => $newTopicName
    ]);

    $newThreadId = 0;
    if (!empty($newTopic['ok']) && !empty($newTopic['result']['message_thread_id'])) {
        $newThreadId = (int)$newTopic['result']['message_thread_id'];
    }

    // 4. Обновляем мастер-карточку в Реестре
    $activeMasterKeyboard = $contactButtons;
    $activeMasterKeyboard[] = [
        ['text' => '🟢 В активной работе в боковой теме', 'callback_data' => 'noop']
    ];

    $reopenNotice = $newThreadId > 0
        ? "\n\n🚀 <b>Рабочий спринт:</b> открыта тема <i>«{$newTopicName}»</i>"
        : "";

    tgApiCall($tgBotToken, 'editMessageText', [
        'chat_id'                  => $chatId,
        'message_id'               => $targetMasterId,
        'text'                     => $reopenedText . $reopenNotice,
        'parse_mode'               => 'HTML',
        'disable_web_page_preview' => true,
        'reply_markup'             => ['inline_keyboard' => $activeMasterKeyboard]
    ]);

    // 5. Отправляем рабочую карточку в новую тему с кнопкой «В архив» (через reply_to_message_id)
    if ($newThreadId > 0) {
        $workKeyboard = $contactButtons;
        $workKeyboard[] = [
            ['text' => '📦 Завершить проект / В архив', 'callback_data' => "archive_lead:{$targetMasterId}:{$newThreadId}"]
        ];

        tgApiCall($tgBotToken, 'sendMessage', [
            'chat_id'                  => $chatId,
            'reply_to_message_id'      => $newThreadId,
            'text'                     => $reopenedText,
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => true,
            'reply_markup'             => ['inline_keyboard' => $workKeyboard]
        ]);
    }

    // 6. Логируем возобновление: событие + статус лида
    try {
        $db = getDb();
        $stmt = $db->prepare("INSERT INTO events (master_msg_id, event_type, actor, details, timestamp) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$targetMasterId, 'reopen_lead', $mention, "Проект возобновлён, открыта тема: {$newTopicName}", $nowTime]);
    } catch (Throwable $e) {
        whLog('DB.events.reopen_lead', null, ' | ' . $e->getMessage());
    }
    upsertLead($targetMasterId, [
        'status'           => '🟢 В работе (' . $mention . ')',
        'active_thread_id' => $newThreadId
    ]);

    tgApiCall($tgBotToken, 'answerCallbackQuery', [
        'callback_query_id' => $callbackId,
        'text'              => "Проект возобновлён! В боковой ленте создана тема «{$newTopicName}».",
        'show_alert'        => false
    ]);

    echo json_encode(['ok' => true]);
    exit;
}

// Заглушка для информационных кнопок
if ($action === 'noop') {
    tgApiCall($tgBotToken, 'answerCallbackQuery', [
        'callback_query_id' => $callbackId,
        'text'              => "Этот проект сейчас находится в активной разработке в боковой ленте.",
        'show_alert'        => true
    ]);
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => true]);
