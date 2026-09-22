<?php
/**
 * RDK IT Engineering — фоновый добиватель отложенных заявок (Vultr Gateway)
 * Запускается по крону каждые 5 минут. Для каждой заявки, которую intake.php
 * не смог поставить в тему «📋 Реестр & Changelog» и временно опубликовал в
 * General (таблица pending_posts, статус 'pending'):
 *  - пробует снова отправить карточку в Реестр;
 *  - при успехе — публикует корректную карточку в Реестре, помечает
 *    сообщение в General как перенесённое, статус -> 'resolved';
 *  - если с момента создания прошло больше 10 минут — сдаётся, помечает
 *    сообщение в General как требующее ручной обработки, статус -> 'failed'.
 *
 * ЗАПУСКАТЬ ТОЛЬКО ИЗ КРОНА (CLI), не публиковать в веб-корне доступным.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

date_default_timezone_set('Europe/Moscow');

$tgBotToken   = '';
$tgChatId     = '';
$intakeSecret = '';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/tg_common.php';

if (empty($tgBotToken) || empty($tgChatId)) {
    fwrite(STDERR, "Config missing\n");
    exit(1);
}

// Простая блокировка от параллельного запуска (на случай долгого предыдущего запуска)
$lockFile = __DIR__ . '/.retry_intake.lock';
$lockHandle = fopen($lockFile, 'c');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    exit(0); // предыдущий запуск ещё не завершился — тихо выходим
}

$registryThreadId = 91;
$maxAgeSeconds     = 600; // 10 минут

try {
    $db = getDb();
    $rows = $db->query("SELECT * FROM pending_posts WHERE status = 'pending'")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $id         = (int)$row['id'];
        $ageSeconds = time() - (int)$row['created_ts'];
        $requestTime = date('d.m.Y H:i:s', (int)$row['created_ts']) . ' (МСК)';

        $cardText = buildLeadCardText(
            (string)$row['service'],
            (string)$row['client_name'],
            (string)$row['email'],
            (string)$row['contact'],
            (string)$row['task'],
            $requestTime
        );
        $contactRow = buildContactRow((string)$row['contact'], (string)$row['client_name'], (string)$row['service']);
        $keyboard = [];
        if (!empty($contactRow)) {
            $keyboard[] = $contactRow;
        }
        $keyboard[] = [['text' => '✋ Взять в проект', 'callback_data' => 'take_lead']];

        $res = tgApiCall($tgBotToken, 'sendMessage', [
            'chat_id'                  => $tgChatId,
            'reply_to_message_id'      => $registryThreadId,
            'text'                     => $cardText,
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => true,
            'reply_markup'             => ['inline_keyboard' => $keyboard]
        ]);
        whLog('RETRY.attempt', $res, " | pending_posts#{$id} | возраст {$ageSeconds}с | {$row['client_name']} · {$row['service']}");

        $now = date('d.m.Y H:i') . ' (МСК)';

        if (!empty($res['ok'])) {
            // Успех: карточка встала в Реестр. Помечаем General-заглушку как перенесённую.
            $newMsgId = $res['result']['message_id'] ?? null;
            if (!empty($row['general_msg_id'])) {
                $editRes = tgApiCall($tgBotToken, 'editMessageText', [
                    'chat_id'    => $tgChatId,
                    'message_id' => (int)$row['general_msg_id'],
                    'text'       => "✅ <b>Перенесено в «📋 Реестр & Changelog»</b> (автоматически, сообщение #{$newMsgId}).\nЭту карточку можно скрыть/удалить вручную.",
                    'parse_mode' => 'HTML'
                ]);
                whLog('RETRY.mark_moved', $editRes, " | pending_posts#{$id}");
            }
            $db->prepare("UPDATE pending_posts SET status='resolved', attempts=attempts+1, updated_at=? WHERE id=?")
               ->execute([$now, $id]);
            continue;
        }

        // Не удалось. Если истекли отведённые 10 минут — сдаёмся и просим обработать вручную.
        if ($ageSeconds >= $maxAgeSeconds) {
            if (!empty($row['general_msg_id'])) {
                $editRes = tgApiCall($tgBotToken, 'editMessageText', [
                    'chat_id'    => $tgChatId,
                    'message_id' => (int)$row['general_msg_id'],
                    'text'       => "❌ <b>Не удалось автоматически перенести в «📋 Реестр & Changelog»</b> за 10 минут.\nОбработайте эту заявку вручную: перенесите карточку в тему Реестра или откройте проект напрямую.\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n" . $cardText,
                    'parse_mode' => 'HTML',
                    'reply_markup' => ['inline_keyboard' => $keyboard]
                ]);
                whLog('RETRY.give_up', $editRes, " | pending_posts#{$id}");
            }
            $db->prepare("UPDATE pending_posts SET status='failed', attempts=attempts+1, updated_at=? WHERE id=?")
               ->execute([$now, $id]);
            continue;
        }

        // Ещё не истекли 10 минут — оставляем pending, попробуем на следующем тике крона.
        $db->prepare("UPDATE pending_posts SET attempts=attempts+1, updated_at=? WHERE id=?")
           ->execute([$now, $id]);
    }
} finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}
