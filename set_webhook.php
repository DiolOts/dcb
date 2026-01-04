<?php
require_once __DIR__ . '/config/config.php';

// URL вашего бота (должен быть HTTPS!)
$webhookUrl = DOMAIN_PATH.'bot_core.php';

// Устанавливаем вебхук
$url = "https://api.telegram.org/bot" . BOT_TOKEN . "/setWebhook";
$params = [
    'url' => $webhookUrl,
    'drop_pending_updates' => true, // Удалить ожидающие обновления
    'allowed_updates' => json_encode([
        'message',           // Личные сообщения
        'chat_join_request', // Заявки на вступление
        'callback_query'     // Для будущих кнопок
    ])
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

echo "<h1>Настройка вебхука</h1>";
echo "<p>URL вебхука: " . htmlspecialchars($webhookUrl) . "</p>";
echo "<p>HTTP Code: " . $httpCode . "</p>";
echo "<pre>" . htmlspecialchars(print_r(json_decode($response, true), true)) . "</pre>";

if (curl_errno($ch)) {
    echo "<p>CURL Error: " . curl_error($ch) . "</p>";
}

curl_close($ch);
?>