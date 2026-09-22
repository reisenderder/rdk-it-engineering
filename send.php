<?php
/**
 * RDK IT Engineering — Обработчик отправки заявок с сайта
 * Хостинг: Timeweb
 * 
 * Шаг 1: Доставка на Email (kontakt@rdk-ai.com)
 * Шаг 2: Доставка в Telegram через Bot API (зарезервировано)
 */

declare(strict_types=1);

// Устанавливаем часовой пояс для корректного времени в заявке
date_default_timezone_set('Europe/Moscow');

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Разрешаем только POST-запросы
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Метод не разрешён. Допускается только POST.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// =============================================================================
// КОНФИГУРАЦИЯ
// =============================================================================

// Настройки почты
$toEmail   = 'kontakt@rdk-ai.com';          // Куда доставлять заявки
$fromEmail = 'kontakt@rdk-ai.com';          // Почтовый ящик на Timeweb
$siteTitle = 'RDK IT Engineering';

// Настройки Telegram (подгружаются из приватного config.php)
$tgBotToken = '';
$tgChatId   = '';

if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

// =============================================================================
// ПОЛУЧЕНИЕ И ПРОВЕРКА ДАННЫХ
// =============================================================================

// Считываем JSON или FormData
$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody, true);
if (!is_array($data) || empty($data)) {
    $data = $_POST;
}

// Защита от спам-ботов: Honeypot (поле-ловушка)
// Если скрытое поле заполнено роботом — симулируем успех и завершаем работу
if (!empty($data['_hp_company']) || !empty($data['website_url'])) {
    echo json_encode(['success' => true, 'message' => 'Заявка принята'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Извлечение и первичная очистка
$service = trim((string)($data['service'] ?? 'Индивидуальный проект'));
$name    = trim((string)($data['name'] ?? ''));
$email   = trim((string)($data['email'] ?? ''));
$contact = trim((string)($data['contact'] ?? 'Не указан'));
$task    = trim((string)($data['task'] ?? ''));

// Защита от CRLF Header Injection в заголовках писем
$name  = str_replace(["\r", "\n"], ' ', $name);
$email = str_replace(["\r", "\n"], '', $email);

// Валидация обязательных полей
$errors = [];
if ($name === '') {
    $errors[] = 'Укажите ваше имя.';
}
if ($email === '') {
    $errors[] = 'Укажите email для связи.';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Указан некорректный адрес email.';
}
if ($task === '') {
    $errors[] = 'Опишите суть задачи.';
}

if (!empty($errors)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error'   => implode(' ', $errors)
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Технические метаданные
$requestTime = date('d.m.Y H:i:s') . ' (МСК)';
$userIp      = $_SERVER['REMOTE_ADDR'] ?? 'Не определён';
$userAgent   = $_SERVER['HTTP_USER_AGENT'] ?? 'Не определён';

// =============================================================================
// ФОРМИРОВАНИЕ ПИСЬМА (HTML & Plain Text)
// =============================================================================

$safeName    = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
$safeEmail   = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
$safeContact = htmlspecialchars($contact, ENT_QUOTES, 'UTF-8');
$safeService = htmlspecialchars($service, ENT_QUOTES, 'UTF-8');
$safeTask    = nl2br(htmlspecialchars($task, ENT_QUOTES, 'UTF-8'));

$emailSubject = "=?UTF-8?B?" . base64_encode("Новая заявка: {$service} — {$name}") . "?=";

$htmlBody = <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Новая заявка с сайта RDK IT</title>
  <style>
    body { margin: 0; padding: 24px; background-color: #f5f3ee; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; color: #272720; -webkit-font-smoothing: antialiased; }
    .card { max-width: 600px; margin: 0 auto; background: #ffffff; border: 1px solid #e2e6e0; border-radius: 10px; overflow: hidden; box-shadow: 0 4px 16px rgba(0,0,0,0.04); }
    .card-header { background: #014b52; color: #ffffff; padding: 24px 28px; }
    .card-header h1 { margin: 0 0 6px 0; font-size: 20px; font-weight: 600; letter-spacing: -0.02em; }
    .card-header p { margin: 0; font-size: 13px; color: rgba(255,255,255,0.8); font-family: Consolas, "Liberation Mono", Menlo, monospace; }
    .card-body { padding: 28px; }
    .badge { display: inline-block; padding: 4px 12px; background: rgba(1,106,113,0.1); color: #016a71; border-radius: 999px; font-size: 13px; font-weight: 600; margin-bottom: 20px; font-family: Consolas, "Liberation Mono", Menlo, monospace; }
    .data-table { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
    .data-table td { padding: 10px 0; border-bottom: 1px solid #f0eee8; font-size: 15px; vertical-align: top; }
    .data-table td.label { width: 140px; color: #67685f; font-size: 13px; text-transform: uppercase; font-family: Consolas, "Liberation Mono", Menlo, monospace; letter-spacing: 0.04em; }
    .data-table td.val { color: #272720; font-weight: 500; }
    .data-table td.val a { color: #016a71; text-decoration: none; font-weight: 600; }
    .task-box { background: #fbfaf7; border: 1px solid #ece9e1; border-left: 3px solid #016a71; border-radius: 6px; padding: 18px 20px; margin-bottom: 24px; }
    .task-title { font-size: 12px; text-transform: uppercase; letter-spacing: 0.06em; color: #67685f; font-family: Consolas, "Liberation Mono", Menlo, monospace; margin-bottom: 8px; font-weight: 600; }
    .task-content { font-size: 15px; line-height: 1.6; color: #272720; }
    .actions { padding-top: 8px; }
    .reply-btn { display: inline-block; padding: 11px 22px; background: #016a71; color: #ffffff !important; text-decoration: none; border-radius: 6px; font-size: 14px; font-weight: 600; }
    .footer-meta { margin-top: 24px; padding-top: 16px; border-top: 1px solid #f0eee8; font-size: 12px; color: #71726b; font-family: Consolas, "Liberation Mono", Menlo, monospace; line-height: 1.5; }
  </style>
</head>
<body>
  <div class="card">
    <div class="card-header">
      <h1>RDK IT Engineering</h1>
      <p>НОВАЯ ЗАЯВКА С САЙТА · rdk-ai.com</p>
    </div>
    <div class="card-body">
      <div class="badge">{$safeService}</div>
      
      <table class="data-table">
        <tr>
          <td class="label">Клиент:</td>
          <td class="val"><strong>{$safeName}</strong></td>
        </tr>
        <tr>
          <td class="label">Email:</td>
          <td class="val"><a href="mailto:{$safeEmail}">{$safeEmail}</a></td>
        </tr>
        <tr>
          <td class="label">Телефон / TG:</td>
          <td class="val">{$safeContact}</td>
        </tr>
        <tr>
          <td class="label">Время:</td>
          <td class="val">{$requestTime}</td>
        </tr>
      </table>

      <div class="task-box">
        <div class="task-title">Суть задачи:</div>
        <div class="task-content">{$safeTask}</div>
      </div>

      <div class="actions">
        <a class="reply-btn" href="mailto:{$safeEmail}?subject=Re:%20Заявка%20на%20разработку%20RDK%20IT">Ответить клиенту</a>
      </div>

      <div class="footer-meta">
        IP: {$userIp} · Устройство: {$userAgent}
      </div>
    </div>
  </div>
</body>
</html>
HTML;

// Заголовки для отправки письма (с RFC 2047 MIME кодированием имён)
$encodedSiteTitle = "=?UTF-8?B?" . base64_encode($siteTitle) . "?=";
$encodedClientName = "=?UTF-8?B?" . base64_encode($name) . "?=";

$headers = [
    'MIME-Version: 1.0',
    'Content-Type: text/html; charset=UTF-8',
    "From: {$encodedSiteTitle} <{$fromEmail}>",
    "Reply-To: {$encodedClientName} <{$email}>",
    'X-Mailer: PHP/' . phpversion()
];
$headersString = implode("\r\n", $headers);

// Отправка через встроенный почтовый агент Timeweb
$mailSent = @mail($toEmail, $emailSubject, $htmlBody, $headersString, "-f {$fromEmail}");
if (!$mailSent) {
    // Резервная попытка без явного ключа -f (если на хостинге действуют ограничения безопасности)
    $mailSent = @mail($toEmail, $emailSubject, $htmlBody, $headersString);
}

// =============================================================================
// АВТООТВЕТ КЛИЕНТУ (Подтверждение получения заявки)
// =============================================================================
if ($mailSent && filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $clientSubject = "=?UTF-8?B?" . base64_encode("RDK IT Engineering — Ваша заявка принята") . "?=";

    $clientHtmlBody = <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Ваша заявка принята — RDK IT Engineering</title>
  <style>
    body { margin: 0; padding: 24px; background-color: #f5f3ee; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; color: #272720; -webkit-font-smoothing: antialiased; }
    .card { max-width: 600px; margin: 0 auto; background: #ffffff; border: 1px solid #e2e6e0; border-radius: 10px; overflow: hidden; box-shadow: 0 4px 16px rgba(0,0,0,0.04); }
    .card-header { background: #014b52; color: #ffffff; padding: 24px 28px; }
    .card-header h1 { margin: 0 0 6px 0; font-size: 20px; font-weight: 600; letter-spacing: -0.02em; }
    .card-header p { margin: 0; font-size: 13px; color: rgba(255,255,255,0.8); font-family: Consolas, "Liberation Mono", Menlo, monospace; }
    .card-body { padding: 28px; line-height: 1.6; }
    .greeting { font-size: 20px; font-weight: 700; color: #014b52; margin-bottom: 14px; }
    .badge { display: inline-block; padding: 4px 12px; background: rgba(1,106,113,0.1); color: #016a71; border-radius: 999px; font-size: 13px; font-weight: 600; margin-bottom: 16px; font-family: Consolas, "Liberation Mono", Menlo, monospace; }
    .text-p { font-size: 15px; color: #272720; margin: 0 0 14px 0; }
    .task-box { background: #fbfaf7; border: 1px solid #ece9e1; border-left: 3px solid #016a71; border-radius: 6px; padding: 16px 18px; margin: 20px 0; }
    .task-title { font-size: 11px; text-transform: uppercase; letter-spacing: 0.06em; color: #67685f; font-family: Consolas, "Liberation Mono", Menlo, monospace; margin-bottom: 6px; font-weight: 600; }
    .task-content { font-size: 14px; line-height: 1.5; color: #272720; }
    .messenger-box { margin: 24px 0; padding: 18px; background: #faf9f6; border-radius: 8px; border: 1px dashed #d5d9d2; }
    .messenger-title { font-size: 13px; font-weight: 600; color: #272720; margin-bottom: 12px; }
    .btn { display: inline-block; padding: 10px 18px; border-radius: 6px; font-size: 13px; font-weight: 600; text-decoration: none; margin-right: 8px; margin-bottom: 8px; }
    .btn-wa { background: #25D366; color: #ffffff !important; }
    .btn-tg { background: #229ED9; color: #ffffff !important; }
    .signature { margin-top: 28px; padding-top: 18px; border-top: 1px solid #f0eee8; font-size: 14px; color: #272720; line-height: 1.5; }
    .signature strong { color: #014b52; }
    .footer-meta { margin-top: 20px; font-size: 12px; color: #71726b; font-family: Consolas, "Liberation Mono", Menlo, monospace; }
  </style>
</head>
<body>
  <div class="card">
    <div class="card-header">
      <h1>RDK IT Engineering</h1>
      <p>ПОДТВЕРЖДЕНИЕ ЗАЯВКИ · rdk-ai.com</p>
    </div>
    <div class="card-body">
      <div class="greeting">Добро пожаловать!</div>
      
      <p class="text-p">Мы получили вашу заявку по направлению:</p>
      <div class="badge">{$safeService}</div>

      <p class="text-p">
        Команда разработки уже изучает детали задачи. Свяжемся в течение следующего рабочего дня.
      </p>

      <div class="task-box">
        <div class="task-title">Суть вашей задачи:</div>
        <div class="task-content">{$safeTask}</div>
      </div>

      <div class="messenger-box">
        <div class="messenger-title">Если вопрос срочный или удобнее продолжить диалог в мессенджере:</div>
        <div>
          <a class="btn btn-wa" href="https://wa.me/qr/XE4E7DVEDIZYM1" target="_blank" rel="noopener noreferrer">Написать в WhatsApp</a>
          <a class="btn btn-tg" href="https://t.me/rdk_it" target="_blank" rel="noopener noreferrer">Написать в Telegram</a>
        </div>
      </div>

      <div class="signature">
        С уважением,<br>
        <strong>Команда RDK_ITEngineering</strong>
      </div>

      <div class="footer-meta">
        rdk-ai.com · kontakt@rdk-ai.com
      </div>
    </div>
  </div>
</body>
</html>
HTML;

    $clientHeaders = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        "From: {$encodedSiteTitle} <{$fromEmail}>",
        "Reply-To: {$encodedSiteTitle} <{$fromEmail}>",
        'X-Mailer: PHP/' . phpversion()
    ];
    $clientHeadersString = implode("\r\n", $clientHeaders);

    $clientSent = @mail($email, $clientSubject, $clientHtmlBody, $clientHeadersString, "-f {$fromEmail}");
    if (!$clientSent) {
        @mail($email, $clientSubject, $clientHtmlBody, $clientHeadersString);
    }
}

// =============================================================================
// TELEGRAM BOT DISPATCH (Уведомления в закрытую группу команды)
// =============================================================================
$tgSent = false;
if (!empty($tgBotToken) && !empty($tgChatId)) {
    $safeTgService = htmlspecialchars($service, ENT_QUOTES, 'UTF-8');
    $safeTgName    = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $safeTgEmail   = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
    $safeTgContact = htmlspecialchars($contact, ENT_QUOTES, 'UTF-8');
    $safeTgTask    = htmlspecialchars($task, ENT_QUOTES, 'UTF-8');

    $tgText = "🔔 <b>НОВАЯ ЗАЯВКА С САЙТА</b>\n"
            . "━━━━━━━━━━━━━━━━━━━━\n"
            . "📂 <b>Направление:</b> {$safeTgService}\n"
            . "👤 <b>Клиент:</b> {$safeTgName}\n"
            . "✉️ <b>Email:</b> {$safeTgEmail}\n"
            . "📱 <b>Контакт:</b> {$safeTgContact}\n"
            . "🕒 <b>Время:</b> {$requestTime}\n"
            . "━━━━━━━━━━━━━━━━━━━━\n"
            . "📝 <b>Суть задачи:</b>\n{$safeTgTask}";

    // Функция выполнения запросов к Telegram Bot API (cURL + fallback на stream)
    $tgApi = static function (string $botToken, string $method, array $params): ?array {
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
            $raw = curl_exec($ch);
            $err = curl_error($ch);
            curl_close($ch);

            if ($raw !== false && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
            if ($err) {
                @error_log("[Telegram API Error] {$method}: {$err}\n", 3, __DIR__ . '/tg_error.log');
            }
        }

        // Резерв через stream_context
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
    };

    // 1. Формируем интерактивные кнопки связи (WhatsApp, Telegram)
    $contactButtonsRow = [];
    if (preg_match('/@([a-zA-Z0-9_]{4,32})/', $contact, $matches)) {
        $contactButtonsRow[] = ['text' => '💬 Написать в Telegram', 'url' => "https://t.me/{$matches[1]}"];
    } elseif (preg_match('/t\.me\/([a-zA-Z0-9_]{4,32})/', $contact, $matches)) {
        $contactButtonsRow[] = ['text' => '💬 Написать в Telegram', 'url' => "https://t.me/{$matches[1]}"];
    }

    $cleanPhone = preg_replace('/\D+/', '', $contact);
    if (strlen($cleanPhone) >= 10 && strlen($cleanPhone) <= 15) {
        if (strlen($cleanPhone) === 11 && $cleanPhone[0] === '8') {
            $cleanPhone = '7' . substr($cleanPhone, 1);
        }
        $waText = rawurlencode("Здравствуйте, {$name}! Вы оставили заявку на сайте RDK IT Engineering по направлению «{$service}».");
        $contactButtonsRow[] = ['text' => '🟢 Написать в WhatsApp', 'url' => "https://wa.me/{$cleanPhone}?text={$waText}"];
    }

    $baseKeyboard = [];
    if (!empty($contactButtonsRow)) {
        $baseKeyboard[] = $contactButtonsRow;
    }

    // Текст мастер-карточки с живым Changelog
    $changelogThreadId = 29; // Постоянная тема «📋 Реестр & Changelog»
    $masterCardText = "🔔 <b>ЗАЯВКА С САЙТА: RDK IT ENGINEERING</b>\n"
                    . "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
                    . "📂 <b>Направление:</b> {$safeTgService}\n"
                    . "👤 <b>Клиент:</b> {$safeTgName}\n"
                    . "✉️ <b>Email:</b> {$safeTgEmail}\n"
                    . "📱 <b>Контакт:</b> {$safeTgContact}\n"
                    . "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
                    . "📝 <b>Суть задачи:</b>\n{$safeTgTask}\n"
                    . "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
                    . "📜 <b>ЖУРНАЛ ПРОЕКТА (Changelog):</b>\n"
                    . "• {$requestTime} — 📥 Заявка поступила с сайта";

    // 2. Отправляем мастер-карточку в тему «📋 Реестр & Changelog»
    $masterPayload = [
        'chat_id'                  => $tgChatId,
        'message_thread_id'        => $changelogThreadId,
        'text'                     => $masterCardText,
        'parse_mode'               => 'HTML',
        'disable_web_page_preview' => true
    ];
    if (!empty($baseKeyboard)) {
        $masterPayload['reply_markup'] = ['inline_keyboard' => $baseKeyboard];
    }
    $masterRes = $tgApi($tgBotToken, 'sendMessage', $masterPayload);
    $masterMsgId = (!empty($masterRes['ok']) && !empty($masterRes['result']['message_id']))
        ? (int)$masterRes['result']['message_id']
        : 0;

    // 3. Создаём рабочую тему клиента в боковой панели (Рабочий спринт)
    $topicName = "📁 " . mb_substr($name, 0, 28) . " · " . mb_substr($service, 0, 36);
    $topicData = $tgApi($tgBotToken, 'createForumTopic', [
        'chat_id' => $tgChatId,
        'name'    => $topicName
    ]);

    $threadId = 0;
    if (!empty($topicData['ok']) && !empty($topicData['result']['message_thread_id'])) {
        $threadId = (int)$topicData['result']['message_thread_id'];
    }

    // 4. Отправляем рабочую карточку в тему клиента с кнопкой «Взять в работу»
    $takeLeadCallback = $masterMsgId > 0 ? "take_lead:{$masterMsgId}" : "take_lead";
    $workKeyboard = $baseKeyboard;
    $workKeyboard[] = [
        ['text' => '✋ Взять в работу', 'callback_data' => $takeLeadCallback]
    ];

    $workPayload = [
        'chat_id'                  => $tgChatId,
        'text'                     => $masterCardText,
        'parse_mode'               => 'HTML',
        'disable_web_page_preview' => true,
        'reply_markup'             => ['inline_keyboard' => $workKeyboard]
    ];
    if ($threadId > 0) {
        $workPayload['message_thread_id'] = $threadId;
    }

    $msgData = $tgApi($tgBotToken, 'sendMessage', $workPayload);
    $tgSent = !empty($msgData['ok']) || !empty($masterRes['ok']);
}

// =============================================================================
// ИТОГОВЫЙ ОТВЕТ ФРОНТЕНДУ
// =============================================================================

if ($mailSent || $tgSent) {
    echo json_encode([
        'success' => true,
        'message' => 'Заявка успешно принята'
    ], JSON_UNESCAPED_UNICODE);
} else {
    $errInfo = error_get_last();
    $details = isset($errInfo['message']) ? ' (' . $errInfo['message'] . ')' : '';
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Не удалось отправить заявку' . $details . '. Пожалуйста, напишите нам в Telegram @rdk_it.'
    ], JSON_UNESCAPED_UNICODE);
}
