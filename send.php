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

// Настройки Telegram (Шаг 2 — заполняются при подключении бота)
$tgBotToken = ''; // Пример: '1234567890:ABCdefGhIJKlmNoPQRsTUVwxyZ'
$tgChatId   = ''; // Пример: '123456789'

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

// Заголовки для отправки письма
$headers = [
    'MIME-Version: 1.0',
    'Content-Type: text/html; charset=UTF-8',
    "From: {$siteTitle} <{$fromEmail}>",
    "Reply-To: {$name} <{$email}>",
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
// TELEGRAM BOT DISPATCH (Шаг 2 — активируется при указании токена)
// =============================================================================
$tgSent = false;
if (!empty($tgBotToken) && !empty($tgChatId)) {
    $tgText = "🔔 <b>Новая заявка с сайта</b>\n\n"
            . "📂 <b>Направление:</b> " . htmlspecialchars($service, ENT_QUOTES) . "\n"
            . "👤 <b>Имя:</b> " . htmlspecialchars($name, ENT_QUOTES) . "\n"
            . "✉️ <b>Email:</b> " . htmlspecialchars($email, ENT_QUOTES) . "\n"
            . "📱 <b>Контакт:</b> " . htmlspecialchars($contact, ENT_QUOTES) . "\n"
            . "🕒 <b>Время:</b> " . $requestTime . "\n\n"
            . "📝 <b>Суть задачи:</b>\n" . htmlspecialchars($task, ENT_QUOTES);

    $tgUrl = "https://api.telegram.org/bot{$tgBotToken}/sendMessage";
    $tgPayload = http_build_query([
        'chat_id'                  => $tgChatId,
        'text'                     => $tgText,
        'parse_mode'               => 'HTML',
        'disable_web_page_preview' => 'true'
    ]);

    $tgContext = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $tgPayload,
            'timeout' => 5
        ]
    ]);

    $tgResult = @file_get_contents($tgUrl, false, $tgContext);
    $tgSent = ($tgResult !== false);
}

// =============================================================================
// ИТОГОВЫЙ ОТВЕТ ФРОНТЕНДУ
// =============================================================================

if ($mailSent) {
    echo json_encode([
        'success' => true,
        'message' => 'Заявка успешно принята и отправлена на kontakt@rdk-ai.com'
    ], JSON_UNESCAPED_UNICODE);
} else {
    // Если mail() вернул false (например, заблокирован сервис или нет прав на хостинге)
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Не удалось отправить письмо через почтовый сервер хостинга. Пожалуйста, напишите нам напрямую в Telegram @rdk_it или на kontakt@rdk-ai.com.'
    ], JSON_UNESCAPED_UNICODE);
}
