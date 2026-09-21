<?php
/**
 * RDK IT Engineering — Telegram Webhook Handler
 * Обработчик интерактивных кнопок («Взять в работу», «В архив»)
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
    // Штатный ответ на обычные GET/POST
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
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\n",
            'content' => json_encode($params, JSON_UNESCAPED_UNICODE),
            'timeout' => 5
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
// ДЕЙСТВИЕ 2: ЗАВЕРШИТЬ ПРОЕКТ / В АРХИВ
// -----------------------------------------------------------------------------
if ($callbackData === 'archive_lead') {
    $newText = $originalText . "\n\n"
             . "🏁 <b>ПРОЕКТ ЗАВЕРШЁН</b>\n"
             . "👤 <b>Закрыл сделку:</b> {$mention} <i>({$nowTime})</i>\n"
             . "🔒 <i>Тема закрыта и отправлена в архив.</i>";

    // Убираем кнопку архивации, оставляя только контактные кнопки
    $finalKeyboard = [];
    if (!empty($replyMarkup['inline_keyboard'])) {
        foreach ($replyMarkup['inline_keyboard'] as $row) {
            $newRow = [];
            foreach ($row as $btn) {
                if (($btn['callback_data'] ?? '') !== 'archive_lead') {
                    $newRow[] = $btn;
                }
            }
            if (!empty($newRow)) {
                $finalKeyboard[] = $newRow;
            }
        }
    }

    tgApiCall($tgBotToken, 'editMessageText', [
        'chat_id'                  => $chatId,
        'message_id'               => $messageId,
        'text'                     => $newText,
        'parse_mode'               => 'HTML',
        'disable_web_page_preview' => true,
        'reply_markup'             => ['inline_keyboard' => $finalKeyboard]
    ]);

    // Закрываем тему в группе Telegram (появится значок замочка и она уйдёт в закрытые)
    if ($threadId > 0) {
        tgApiCall($tgBotToken, 'closeForumTopic', [
            'chat_id'           => $chatId,
            'message_thread_id' => $threadId
        ]);
    }

    tgApiCall($tgBotToken, 'answerCallbackQuery', [
        'callback_query_id' => $callbackId,
        'text'              => "Проект завершён и отправлен в архив!",
        'show_alert'        => true
    ]);

    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => true]);
