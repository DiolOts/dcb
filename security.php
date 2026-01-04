<?php
// security.php - меры безопасности
header_remove('X-Powered-By');

// Отключить вывод информации о сервере
ini_set('expose_php', 'off');

// Заголовки безопасности
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

// CSP (Content Security Policy)
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self';");

// Rate limiting (базовый)
session_start();
$now = time();
if (!isset($_SESSION['requests'])) {
    $_SESSION['requests'] = [];
}

// Очищаем старые запросы (старше 60 секунд)
foreach ($_SESSION['requests'] as $key => $time) {
    if ($now - $time > 60) {
        unset($_SESSION['requests'][$key]);
    }
}

// Проверяем лимит (60 запросов в минуту)
if (count($_SESSION['requests']) > 60) {
    http_response_code(429);
    die('Too many requests');
}

// Добавляем текущий запрос
$_SESSION['requests'][] = $now;

// Проверка User-Agent (только для API запросов)
if (isset($_SERVER['HTTP_USER_AGENT']) && strpos($_SERVER['HTTP_USER_AGENT'], 'TelegramBot') === false) {
    // Если это не Telegram, ведем логирование
    $suspectLog = __DIR__ . '/logs/suspicious.log';
    $logEntry = sprintf(
        "[%s] Suspicious UA: %s | IP: %s | URI: %s\n",
        date('Y-m-d H:i:s'),
        $_SERVER['HTTP_USER_AGENT'],
        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        $_SERVER['REQUEST_URI'] ?? '/'
    );
    file_put_contents($suspectLog, $logEntry, FILE_APPEND);
}
?>