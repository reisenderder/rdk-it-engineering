<?php
/**
 * RDK IT Engineering — Telegram Webhook Handler (Vultr Gateway)
 * Обработчик интерактивных кнопок («Взять в работу», «В архив»)
 * Транзакционная архивация с гарантией сохранности данных (Zero Data Loss)
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

// Защита: реагируем только на нашу закрытую группу
if ((string)$chatId !== (string)$tgChatId) {
    echo json_encode(['ok' => true]);
    exit;
}

// Формируем имя нажавшего сотрудника
$firstName = trim((string)($fromUser['first_name'] ?? 'Сотрудник'));
$username  = trim((string)($fromUser['username'] ?? ''));
$mention   = !empty($username) ? "@{$username}" : $firstName;
$nowTime   = date('d.m.Y H:i') . ' (МСК)';

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
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\n",
            'content' => $jsonPayload,
            'timeout' => 5
        ],
        'ssl' => [
            'verify_peer'      => false,
            'verify_peer_name' => false
        ]
    ]);
    $res = @file_get_contents($url, false, $ctx);
    return $res ? json_decode($res, true) : null;
}

// -----------------------------------------------------------------------------
// ДЕЙСТВИЕ 1: ВЗЯТЬ В РАБОТУ
// -----------------------------------------------------------------------------
if ($callbackData === 'take_lead') {
    // Обновляем текст сообщения, добавляя статус ответственности
    $newText = $originalText . "\n\n"
             . "━━━━━━━━━━━━━━━━━━━━\n"
             . "🟢 <b>СТАТУС: В РАБОТЕ</b>\n"
             . "👤 <b>Ответственный:</b> {$mention} <i>({$nowTime})</i>";

    // Заменяем кнопку «Взять в работу» на «В архив»
    $updatedKeyboard = [];
    if (!empty($replyMarkup['inline_keyboard'])) {
        foreach ($replyMarkup['inline_keyboard'] as $row) {
            $newRow = [];
            foreach ($row as $btn) {
                if (($btn['callback_data'] ?? '') === 'take_lead') {
                    $newRow[] = [
                        'text'          => '📦 Завершить проект / В архив',
                        'callback_data' => 'archive_lead'
                    ];
                } else {
                    $newRow[] = $btn;
                }
            }
            if (!empty($newRow)) {
                $updatedKeyboard[] = $newRow;
            }
        }
    }

    tgApiCall($tgBotToken, 'editMessageText', [
        'chat_id'                  => $chatId,
        'message_id'               => $messageId,
        'text'                     => $newText,
        'parse_mode'               => 'HTML',
        'disable_web_page_preview' => true,
        'reply_markup'             => ['inline_keyboard' => $updatedKeyboard]
    ]);

    tgApiCall($tgBotToken, 'answerCallbackQuery', [
        'callback_query_id' => $callbackId,
        'text'              => "Вы назначены ответственным за проект!",
        'show_alert'        => false
    ]);

    echo json_encode(['ok' => true]);
    exit;
}

// -----------------------------------------------------------------------------
// ДЕЙСТВИЕ 2: ЗАВЕРШИТЬ ПРОЕКТ / В АРХИВ (С ГАРАНТИЕЙ СОХРАННОСТИ ДАННЫХ)
// -----------------------------------------------------------------------------
if ($callbackData === 'archive_lead') {
    // 1. Формируем финальный текст карточки со статусом завершения сделки
    $archiveCardText = $originalText . "\n\n"
                     . "━━━━━━━━━━━━━━━━━━━━\n"
                     . "🏁 <b>ПРОЕКТ ЗАВЕРШЁН И АРХИВИРОВАН</b>\n"
                     . "👤 <b>Закрыл сделку:</b> {$mention} <i>({$nowTime})</i>\n"
                     . "🔒 <i>Карточка бессрочно перенесена в реестр архива.</i>";

    // Оставляем в архиве только кнопки быстрой связи (WhatsApp, Telegram)
    $archiveKeyboard = [];
    if (!empty($replyMarkup['inline_keyboard'])) {
        foreach ($replyMarkup['inline_keyboard'] as $row) {
            $newRow = [];
            foreach ($row as $btn) {
                if (($btn['callback_data'] ?? '') !== 'archive_lead' && ($btn['callback_data'] ?? '') !== 'take_lead') {
                    $newRow[] = $btn;
                }
            }
            if (!empty($newRow)) {
                $archiveKeyboard[] = $newRow;
            }
        }
    }

    // 2. Определяем постоянную тему «📦 Архив проектов»
    $archiveFile = __DIR__ . '/archive_thread.id';
    $archiveThreadId = 0;
    if (file_exists($archiveFile)) {
        $archiveThreadId = (int)trim((string)@file_get_contents($archiveFile));
    }
    if ($archiveThreadId <= 0) {
        $archiveThreadId = 29; // ID созданной постоянной темы реестра
    }

    // 3. Отправляем карточку в тему «📦 Архив проектов»
    $archivePayload = [
        'chat_id'                  => $chatId,
        'message_thread_id'        => $archiveThreadId,
        'text'                     => $archiveCardText,
        'parse_mode'               => 'HTML',
        'disable_web_page_preview' => true
    ];
    if (!empty($archiveKeyboard)) {
        $archivePayload['reply_markup'] = ['inline_keyboard' => $archiveKeyboard];
    }

    $sendArchiveRes = tgApiCall($tgBotToken, 'sendMessage', $archivePayload);

    // Если темы с таким ID не существует (например, была случайно удалена вручную) — создаём заново и повторяем
    if (empty($sendArchiveRes['ok']) && ($sendArchiveRes['description'] ?? '') === 'Bad Request: message thread not found') {
        $createArchiveTopic = tgApiCall($tgBotToken, 'createForumTopic', [
            'chat_id'    => $chatId,
            'name'       => '📦 Архив проектов',
            'icon_color' => 9367192
        ]);
        if (!empty($createArchiveTopic['ok']) && !empty($createArchiveTopic['result']['message_thread_id'])) {
            $archiveThreadId = (int)$createArchiveTopic['result']['message_thread_id'];
            @file_put_contents($archiveFile, (string)$archiveThreadId);
            $archivePayload['message_thread_id'] = $archiveThreadId;
            $sendArchiveRes = tgApiCall($tgBotToken, 'sendMessage', $archivePayload);
        }
    }

    $savedToArchive = (!empty($sendArchiveRes['ok']) && !empty($sendArchiveRes['result']['message_id']));

    // 4. ТРАНЗАКЦИОННЫЙ ПРЕДОХРАНИТЕЛЬ:
    // Удаляем персональную тему ТОЛЬКО ЕСЛИ сообщение 100% подтверждено и сохранено в Архиве!
    if ($savedToArchive) {
        // Запоминаем ID архивной темы для надёжности
        @file_put_contents($archiveFile, (string)$archiveThreadId);

        // Безопасно удаляем временную клиентскую тему (чтобы не засорять боковую панель)
        if ($threadId > 0 && $threadId !== $archiveThreadId) {
            tgApiCall($tgBotToken, 'deleteForumTopic', [
                'chat_id'           => $chatId,
                'message_thread_id' => $threadId
            ]);
        }

        tgApiCall($tgBotToken, 'answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text'              => "Заказ сохранён в «📦 Архив проектов», временная тема очищена.",
            'show_alert'        => false
        ]);
    } else {
        // ПРЕДОХРАНИТЕЛЬ СРАБОТАЛ: В архив записать не удалось!
        // НИ В КОЕМ СЛУЧАЕ НЕ УДАЛЯЕМ ТЕМУ КЛИЕНТА. Просто закрываем её на месте замком 🔒.
        tgApiCall($tgBotToken, 'editMessageText', [
            'chat_id'                  => $chatId,
            'message_id'               => $messageId,
            'text'                     => $archiveCardText . "\n\n⚠️ <i>Не удалось переместить в общую папку Архива. Тема надёжно сохранена на месте.</i>",
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => true,
            'reply_markup'             => ['inline_keyboard' => $archiveKeyboard]
        ]);

        if ($threadId > 0) {
            tgApiCall($tgBotToken, 'closeForumTopic', [
                'chat_id'           => $chatId,
                'message_thread_id' => $threadId
            ]);
        }

        tgApiCall($tgBotToken, 'answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text'              => "Внимание: не удалось продублировать в архивную папку. Тема закрыта, но НЕ удалена во избежание потери данных!",
            'show_alert'        => true
        ]);
    }

    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => true]);
