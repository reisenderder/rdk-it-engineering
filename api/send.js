export default async function handler(req, res) {
  // Разрешаем только POST-запросы
  if (req.method !== 'POST') {
    return res.status(405).json({
      success: false,
      error: 'Метод не разрешён. Допускается только POST.'
    });
  }

  try {
    const body = typeof req.body === 'string' ? JSON.parse(req.body) : (req.body || {});

    // Защита от спам-ботов (Honeypot)
    if (body._hp_company || body.website_url) {
      return res.status(200).json({ success: true, message: 'Заявка принята' });
    }

    const service = (body.service || 'Индивидуальный проект').trim();
    const name    = (body.name || '').trim();
    const email   = (body.email || '').trim();
    const contact = (body.contact || 'Не указан').trim();
    const task    = (body.task || '').trim();

    // Валидация обязательных полей
    if (!name || !email || !task) {
      return res.status(422).json({
        success: false,
        error: 'Пожалуйста, заполните обязательные поля (имя, email, суть задачи).'
      });
    }

    // Резерв под Telegram Bot API (активируется на Шаге 2 при добавлении токена)
    const tgToken  = process.env.TELEGRAM_BOT_TOKEN || '';
    const tgChatId = process.env.TELEGRAM_CHAT_ID || '';

    if (tgToken && tgChatId) {
      const tgText = `🔔 <b>Новая заявка с сайта (Vercel Preview)</b>\n\n` +
        `📂 <b>Направление:</b> ${service}\n` +
        `👤 <b>Имя:</b> ${name}\n` +
        `✉️ <b>Email:</b> ${email}\n` +
        `📱 <b>Контакт:</b> ${contact}\n\n` +
        `📝 <b>Суть задачи:</b>\n${task}`;

      await fetch(`https://api.telegram.org/bot${tgToken}/sendMessage`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          chat_id: tgChatId,
          text: tgText,
          parse_mode: 'HTML',
          disable_web_page_preview: true
        })
      }).catch(err => console.error('Telegram dispatch error:', err));
    }

    // Успешный ответ фронтенду для отображения экрана благодарности
    return res.status(200).json({
      success: true,
      message: 'Заявка успешно принята (Vercel Preview)'
    });
  } catch (error) {
    console.error('Server error:', error);
    return res.status(500).json({
      success: false,
      error: 'Ошибка при обработке заявки'
    });
  }
}
