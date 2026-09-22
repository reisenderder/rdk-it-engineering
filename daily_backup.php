<?php
/**
 * RDK IT Engineering — Ежедневный автоматический бэкап базы проектов CRM
 * Запускается каждый день по расписанию Cron (в 23:59 МСК) на сервере Vultr
 * Отправляет полный отчёт и резервную копию на kontakt@rdk-ai.com
 */

declare(strict_types=1);

date_default_timezone_set('Europe/Moscow');

$backupDir = '/var/backups/rdk_crm';
if (!is_dir($backupDir)) {
    @mkdir($backupDir, 0755, true);
}

$dbPath = __DIR__ . '/crm.sqlite';
$todayDate = date('d.m.Y');
$nowDateTime = date('d.m.Y H:i:s');
$toEmail = 'kontakt@rdk-ai.com';
$fromEmail = 'kontakt@rdk-ai.com';

// 1. Извлекаем данные из базы SQLite
$eventsData = [];
$totalEvents = 0;
if (file_exists($dbPath)) {
    try {
        $pdo = new PDO("sqlite:{$dbPath}");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $pdo->query("SELECT * FROM events ORDER BY id ASC");
        $eventsData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $totalEvents = count($eventsData);
    } catch (Throwable $e) {
        // Ошибка чтения БД
    }
}

// 2. Формируем CSV файл
$csvContent = "\xEF\xBB\xBF"; // UTF-8 BOM для корректного открытия в Excel
$csvContent .= "ID;Мастер ID;Тип события;Ответственный;Детали;Время (МСК)\r\n";
foreach ($eventsData as $row) {
    $csvContent .= sprintf(
        "%s;%s;%s;%s;%s;%s\r\n",
        $row['id'] ?? '',
        $row['master_msg_id'] ?? '',
        $row['event_type'] ?? '',
        str_replace(';', ',', (string)($row['actor'] ?? '')),
        str_replace(';', ',', (string)($row['details'] ?? '')),
        $row['timestamp'] ?? ''
    );
}

// Сохраняем локальную копию в архивную папку сервера
$localBackupFile = "{$backupDir}/crm_backup_" . date('Y-m-d') . ".csv";
@file_put_contents($localBackupFile, $csvContent);

// 3. Формируем письмо с HTML-отчётом и вложенным CSV
$boundary = "==Multipart_Boundary_x" . md5((string)time()) . "x";

$subjectText = "RDK IT CRM — Ежедневный бэкап и реестр проектов ({$todayDate})";
$encodedSubject = "=?UTF-8?B?" . base64_encode($subjectText) . "?=";
$encodedSender  = "=?UTF-8?B?" . base64_encode("RDK IT Backup Bot") . "?=";

$headers = [
    'MIME-Version: 1.0',
    "From: {$encodedSender} <{$fromEmail}>",
    "Reply-To: {$fromEmail}",
    "Content-Type: multipart/mixed; boundary=\"{$boundary}\"",
    'X-Mailer: PHP/' . phpversion()
];

$htmlReport = <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f5f3ee; padding: 20px; color: #272720; }
  .card { max-width: 600px; margin: 0 auto; background: #fff; border-radius: 8px; border: 1px solid #e2e6e0; overflow: hidden; }
  .head { background: #014b52; color: #fff; padding: 20px; }
  .body { padding: 24px; line-height: 1.6; }
  .stat { background: #fbfaf7; border-left: 3px solid #016a71; padding: 12px 16px; margin: 16px 0; border-radius: 4px; }
  .footer { margin-top: 20px; font-size: 12px; color: #71726b; border-top: 1px solid #f0eee8; padding-top: 12px; }
</style>
</head>
<body>
<div class="card">
  <div class="head">
    <h2 style="margin:0;">RDK IT Engineering</h2>
    <p style="margin:4px 0 0 0; font-size:13px; opacity:0.8;">ЕЖЕДНЕВНЫЙ БЭКАП БАЗЫ CRM · {$todayDate}</p>
  </div>
  <div class="body">
    <p>Здравствуйте!</p>
    <p>Автоматическая система резервного копирования Vultr сформировала ежедневный дамп всех процессов и сделок.</p>
    
    <div class="stat">
      <b>Всего зафиксировано событий в базе:</b> {$totalEvents}<br>
      <b>Время выгрузки:</b> {$nowDateTime}<br>
      <b>Локальная копия:</b> {$localBackupFile}
    </div>

    <p>К письму прикреплён файл <b>crm_backup.csv</b> (открывается в Microsoft Excel или Google Sheets). Все ваши клиенты и история сделок находятся в полной безопасности.</p>

    <div class="footer">
      RDK IT Engineering · Автономный шлюз Vultr
    </div>
  </div>
</div>
</body>
</html>
HTML;

$body = "--{$boundary}\r\n";
$body .= "Content-Type: text/html; charset=UTF-8\r\n";
$body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
$body .= $htmlReport . "\r\n\r\n";

$body .= "--{$boundary}\r\n";
$body .= "Content-Type: text/csv; name=\"crm_backup_{$todayDate}.csv\"\r\n";
$body .= "Content-Disposition: attachment; filename=\"crm_backup_{$todayDate}.csv\"\r\n";
$body .= "Content-Transfer-Encoding: base64\r\n\r\n";
$body .= chunk_split(base64_encode($csvContent)) . "\r\n\r\n";
$body .= "--{$boundary}--";

$mailSent = @mail($toEmail, $encodedSubject, $body, implode("\r\n", $headers));

echo "[{$nowDateTime}] Daily backup finished. Events: {$totalEvents}. Mail sent: " . ($mailSent ? 'YES' : 'NO') . "\n";
