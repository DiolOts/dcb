<?php

// Основные настройки бота
define('DOMAIN_PATH', 'DOMAIN_PATH');
define('BOT_TOKEN', 'BOT_TOKEN');
define('PUBLIC_CHANNEL', 'PUBLIC_CHANNEL'); // Публичный канал для заявок
define('ADMIN_CHANNEL_ID', 'ADMIN_CHANNEL_ID'); // ID закрытого админ-канала
define('ADMIN_TOPIC_ID', 'ADMIN_TOPIC_ID'); // ID топика внутри форума
define('ADMIN_CHANNEL_LINK', 'ADMIN_CHANNEL_LINK'); // Ссылка для отладки
define('TIMEZONE', 'Europe/Moscow');

// Настройки базы данных
define('DB_HOST', 'DB_HOST');
define('DB_NAME', 'DB_NAME');
define('DB_USER', 'DB_USER');
define('DB_PASS', 'DB_PASS');

// Текст приветственного сообщения
define('WELCOME_MESSAGE', "WELCOME_MESSAGE");

// Время ожидания ответа
define('RESPONSE_TIMEOUT', 8 * 60 * 60); // 8 часов

// Включить отладку (false на продакшене)
define('DEBUG_MODE', true);

date_default_timezone_set(TIMEZONE);
?>