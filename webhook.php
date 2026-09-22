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

// Функция вызовов Telegram Bot API
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
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT        => 6,
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
    return $res ? json_decode($res, true) : null;
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
$changelogThreadId = 29; // Постоянная тема «📋 Реестр & Changelog»

// Парсим префикс и ID мастер-сообщения
$parts = explode(':', $callbackData, 2);
$action = $parts[0];
$masterMsgId = isset($parts[1]) ? (int)$parts[1] : 0;

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
// ДЕЙСТВИЕ 1: ВЗЯТЬ В РАБОТУ (take_lead)
// -----------------------------------------------------------------------------
if ($action === 'take_lead') {
    $archiveCallback = $masterMsgId > 0 ? "archive_lead:{$masterMsgId}" : "archive_lead";

    // Обновляем карточку в рабочей теме клиента
    $newWorkText = $originalText . "\n• {$nowTime} — 🟢 Взят в работу ({$mention})";
    $workKeyboard = $contactButtons;
    $workKeyboard[] = [
        ['text' => '📦 Завершить проект / В архив', 'callback_data' => $archiveCallback]
    ];

    tgApiCall($tgBotToken, 'editMessageText', [
        'chat_id'                  => $chatId,
        'message_id'               => $messageId,
        'text'                     => $newWorkText,
        'parse_mode'               => 'HTML',
        'disable_web_page_preview' => true,
        'reply_markup'             => ['inline_keyboard' => $workKeyboard]
    ]);

    // Синхронизируем мастер-карточку в теме «📋 Реестр & Changelog»
    if ($masterMsgId > 0) {
        // Дописываем строчку в мастер-карточку
        $masterText = $originalText . "\n• {$nowTime} — 🟢 Взят в работу ({$mention})";
        tgApiCall($tgBotToken, 'editMessageText', [
            'chat_id'                  => $chatId,
            'message_id'               => $masterMsgId,
            'text'                     => $masterText,
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => true,
            'reply_markup'             => ['inline_keyboard' => $contactButtons]
        ]);
    }

    // Фиксируем в автономной базе данных SQLite
    try {
        $db = getDb();
        $stmt = $db->prepare("INSERT INTO events (master_msg_id, event_type, actor, details, timestamp) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$masterMsgId, 'take_lead', $mention, 'Взят в работу инженером', $nowTime]);
    } catch (Throwable $e) {
        // Безопасный игнор для вебхука
    }

    tgApiCall($tgBotToken, 'answerCallbackQuery', [
        'callback_query_id' => $callbackId,
        'text'              => "Вы назначены ответственным! Статус обновлён в Реестре.",
        'show_alert'        => false
    ]);

    echo json_encode(['ok' => true]);
    exit;
}

// -----------------------------------------------------------------------------
// ДЕЙСТВИЕ 2: ЗАВЕРШИТЬ ПРОЕКТ (archive_lead)
// -----------------------------------------------------------------------------
if ($action === 'archive_lead') {
    $reopenCallback = $masterMsgId > 0 ? "reopen_lead:{$masterMsgId}" : "reopen_lead";

    // 1. Формируем финальный текст с обновлением Changelog
    $finalChangelogText = $originalText . "\n• {$nowTime} — 🏁 Проект сдан в архив ({$mention})";

    $masterArchiveKeyboard = $contactButtons;
    $masterArchiveKeyboard[] = [
        ['text' => '🔄 Возобновить проект / В работу', 'callback_data' => $reopenCallback]
    ];

    $savedToRegistry = false;

    // Если у нас уже есть мастер-карточка в «📋 Реестр & Changelog»
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
    } else {
        // Если мастер-карточка не была создана ранее, публикуем её в тему Реестра
        $postRes = tgApiCall($tgBotToken, 'sendMessage', [
            'chat_id'                  => $chatId,
            'message_thread_id'        => $changelogThreadId,
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

    // 2. Фиксация в автономной базе данных SQLite
    try {
        $db = getDb();
        $stmt = $db->prepare("INSERT INTO events (master_msg_id, event_type, actor, details, timestamp) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$masterMsgId, 'archive_lead', $mention, 'Проект завершён и сдан в архив', $nowTime]);
    } catch (Throwable $e) {}

    // 3. ТРАНЗАКЦИОННАЯ БЕЗОПАСНОСТЬ:
    // Удаляем рабочую тему ТОЛЬКО если статус 100% зафиксирован в Реестре!
    if ($savedToRegistry) {
        if ($threadId > 0 && $threadId !== $changelogThreadId) {
            tgApiCall($tgBotToken, 'deleteForumTopic', [
                'chat_id'           => $chatId,
                'message_thread_id' => $threadId
            ]);
        }

        tgApiCall($tgBotToken, 'answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text'              => "Проект завершён и зафиксирован в «📋 Реестр & Changelog». Рабочая лента очищена!",
            'show_alert'        => false
        ]);
    } else {
        // ПРЕДОХРАНИТЕЛЬ: если в реестр не записалось, тему НЕ удаляем, а просто закрываем замком!
        tgApiCall($tgBotToken, 'closeForumTopic', [
            'chat_id'           => $chatId,
            'message_thread_id' => $threadId
        ]);

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
    // 1. Дописываем шаг возобновления в Changelog мастер-карточки
    $reopenedText = $originalText . "\n• {$nowTime} — 🔁 Возобновлён в работу ({$mention})";

    $activeMasterKeyboard = $contactButtons;
    $activeMasterKeyboard[] = [
        ['text' => '🟢 Проект в активной работе', 'callback_data' => 'noop']
    ];

    tgApiCall($tgBotToken, 'editMessageText', [
        'chat_id'                  => $chatId,
        'message_id'               => $messageId,
        'text'                     => $reopenedText,
        'parse_mode'               => 'HTML',
        'disable_web_page_preview' => true,
        'reply_markup'             => ['inline_keyboard' => $activeMasterKeyboard]
    ]);

    // 2. Извлекаем имя клиента и услугу из текста для заголовка новой рабочей темы
    $clientName = 'Клиент';
    $serviceName = 'Проект';
    if (preg_match('/👤 <b>Клиент:<\/b>\s*([^\n\r<]+)/u', $originalText, $m)) {
        $clientName = trim($m[1]);
    }
    if (preg_match('/📂 <b>Направление:<\/b>\s*([^\n\r<]+)/u', $originalText, $m)) {
        $serviceName = trim($m[1]);
    }

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

    // 4. Отправляем рабочую карточку в новую тему с кнопкой «В архив»
    $workArchiveCallback = "archive_lead:{$messageId}";
    $workKeyboard = $contactButtons;
    $workKeyboard[] = [
        ['text' => '📦 Завершить проект / В архив', 'callback_data' => $workArchiveCallback]
    ];

    if ($newThreadId > 0) {
        tgApiCall($tgBotToken, 'sendMessage', [
            'chat_id'                  => $chatId,
            'message_thread_id'        => $newThreadId,
            'text'                     => $reopenedText,
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => true,
            'reply_markup'             => ['inline_keyboard' => $workKeyboard]
        ]);
    }

    // 5. Логируем возобновление в базу SQLite
    try {
        $db = getDb();
        $stmt = $db->prepare("INSERT INTO events (master_msg_id, event_type, actor, details, timestamp) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$messageId, 'reopen_lead', $mention, 'Проект возобновлён и открыта тема в боковой панели', $nowTime]);
    } catch (Throwable $e) {}

    tgApiCall($tgBotToken, 'answerCallbackQuery', [
        'callback_query_id' => $callbackId,
        'text'              => "Проект возобновлён! В боковой ленте создана рабочая тема.",
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
