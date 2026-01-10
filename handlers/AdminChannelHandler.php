<?php
require_once __DIR__ . '/../config/config.php';

class AdminChannelHandler {
    
    /**
     * Пересылка сообщения в топик форума
     */
    public static function sendNewRequestToForum($userId, $userMessage = null, $photoFileId = null, $requestId = null) {
        try {
            // Получаем информацию о пользователе
            $userInfo = self::getUserInfo($userId);
            
            // ДЕБАГ: Проверяем, что вернул getUserInfo
            error_log("sendNewRequestToForum: userInfo type = " . gettype($userInfo));
            error_log("sendNewRequestToForum: userInfo = " . print_r($userInfo, true));
            
            // Формируем сообщение для форума
            $forumMessage = self::formatForumMessage($userInfo, $requestId, $userMessage);
            
            // Отправляем в топик форума
            $response = self::callTelegramApi('sendMessage', [
                'chat_id' => ADMIN_CHANNEL_ID,
                'message_thread_id' => ADMIN_TOPIC_ID,
                'text' => $forumMessage,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [
                            ['text' => '✅ ПРИНЯТЬ', 'callback_data' => 'approve_' . $requestId . '_' . $userId],
                            ['text' => '❌ ОТКЛОНИТЬ', 'callback_data' => 'reject_' . $requestId . '_' . $userId]
                        ]
                    ]
                ])
            ]);
            
            $messageId = $response['result']['message_id'] ?? null;
            
            // Если есть фото, отправляем его отдельным сообщением
            if ($photoFileId) {
                self::callTelegramApi('sendPhoto', [
                    'chat_id' => ADMIN_CHANNEL_ID,
                    'message_thread_id' => ADMIN_TOPIC_ID,
                    'photo' => $photoFileId,
                    'caption' => "📸 Фото от пользователя",
                    'reply_to_message_id' => $messageId
                ]);
            }
            
            return $messageId;
            
        } catch (Exception $e) {
            error_log("Error in sendNewRequestToForum: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Форматирование сообщения для топика форума
     */
    private static function formatForumMessage($userInfo, $requestId, $userMessage = null) {
        // ИСПРАВЛЕНО: Проверяем, что $userInfo - массив
        if (!is_array($userInfo)) {
            error_log("formatForumMessage: userInfo is not an array! Type: " . gettype($userInfo));
            // Создаем массив по умолчанию
            $userInfo = [
                'id' => 0,
                'username' => null,
                'first_name' => 'Неизвестный пользователь',
                'last_name' => ''
            ];
        }
        
        // ИСПРАВЛЕНО: Убеждаемся, что все ключи существуют
        $userId = $userInfo['id'] ?? 0;
        $username = $userInfo['username'] ?? null;
        $firstName = $userInfo['first_name'] ?? 'Неизвестно';
        $lastName = $userInfo['last_name'] ?? '';
        
        $statusEmoji = "📨";
        $userLink = $username ? 
                   "<a href='https://t.me/{$username}'>@{$username}</a>" : 
                   "без username";
        
        $lastNameDisplay = $lastName ? " " . $lastName : "";
        
        $message = "{$statusEmoji} <b>НОВАЯ ЗАЯВКА #{$requestId}</b>\n\n" .
                  "👤 <b>Пользователь:</b> {$firstName}{$lastNameDisplay}\n" .
                  "🔗 <b>Username:</b> {$userLink}\n" .
                  "🆔 <b>User ID:</b> <code>{$userId}</code>\n" .
                  "📅 <b>Время заявки:</b> " . date('d.m.Y H:i:s') . "\n\n" .
                  "📋 <b>Статус:</b> Ожидает модерации\n" .
                  "⏰ <b>Таймаут:</b> " . date('H:i', time() + RESPONSE_TIMEOUT) . "\n\n";
        
        if ($userMessage && !in_array(trim($userMessage), ['/start', '/начать'])) {
            $shortMessage = strlen($userMessage) > 100 ? 
                           substr(htmlspecialchars($userMessage), 0, 100) . "..." : 
                           htmlspecialchars($userMessage);
            $message .= "📝 <b>Текст ответа:</b> {$shortMessage}\n\n";
        }
        
        $message .= "────────────────────\n" .
                   "<i>Используйте кнопки ниже для модерации</i>\n" .
                   "<i>Тема заявок: <a href='" . ADMIN_CHANNEL_LINK . "'>#" . ADMIN_TOPIC_ID . "</a></i>";
        
        return $message;
    }
    
    /**
     * Получение информации о пользователе (публичный для тестирования)
     */
    public static function getUserInfo($userId) {
        try {
            error_log("Getting user info for user ID: {$userId}");
            
            $response = self::callTelegramApi('getChat', [
                'chat_id' => $userId
            ]);
            
            if ($response['ok']) {
                $result = [
                    'id' => $userId,
                    'username' => $response['result']['username'] ?? null,
                    'first_name' => $response['result']['first_name'] ?? 'Не указано',
                    'last_name' => $response['result']['last_name'] ?? ''
                ];
                error_log("User info retrieved successfully: " . print_r($result, true));
                return $result;
            } else {
                error_log("getUserInfo API error for user {$userId}: " . ($response['description'] ?? 'Unknown error'));
            }
            
        } catch (Exception $e) {
            error_log("Error in getUserInfo for user {$userId}: " . $e->getMessage());
        }
        
        // Возвращаем массив по умолчанию в случае ошибки
        error_log("Returning default user info for user {$userId}");
        return [
            'id' => $userId,
            'username' => null,
            'first_name' => 'Неизвестно',
            'last_name' => ''
        ];
    }
    
    /**
     * Универсальный метод для вызова API Telegram
     */
    private static function callTelegramApi($method, $params = []) {
        $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/" . $method;
        
        // Логируем запрос (без чувствительных данных)
        $logParams = $params;
        if (isset($logParams['chat_id'])) {
            $logParams['chat_id'] = is_string($logParams['chat_id']) ? 
                substr($logParams['chat_id'], 0, 10) . '...' : $logParams['chat_id'];
        }
        error_log("AdminChannelHandler API Call: {$method}");
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $params,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 10
        ]);
        
        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            error_log("AdminChannelHandler CURL Error for {$method}: {$error}");
            curl_close($ch);
            throw new Exception('CURL Error: ' . $error);
        }
        
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $decoded = json_decode($response, true) ?: [];
        
        if ($httpCode != 200) {
            error_log("AdminChannelHandler HTTP Error {$httpCode} for method {$method}");
            throw new Exception("HTTP Error {$httpCode}");
        }
        
        if (!$decoded['ok']) {
            error_log("AdminChannelHandler Telegram API Error for {$method}: " . 
                     ($decoded['description'] ?? 'Unknown error'));
            // Не бросаем исключение, просто возвращаем результат с ошибкой
        }
        
        return $decoded;
    }
    
    /**
     * Уведомление о новой заявке
     */
    public static function notifyNewPendingRequest($requestId, $userId, $username, $firstName) {
        try {
            $usernameDisplay = $username ? "@{$username}" : "без username";
            
            $message = "🆕 <b>НОВАЯ ЗАЯВКА #{$requestId}</b>\n\n" .
                      "👤 <b>Пользователь:</b> {$firstName}\n" .
                      "🔗 <b>Username:</b> {$usernameDisplay}\n" .
                      "🆔 <b>User ID:</b> <code>{$userId}</code>\n\n" .
                      "📋 <b>Статус:</b> Ожидает ответа пользователя\n" .
                      "⏰ <b>Таймаут:</b> " . date('H:i', time() + RESPONSE_TIMEOUT) . "\n" .
                      "📅 <b>Время:</b> " . date('d.m.Y H:i:s') . "\n\n" .
                      "<i>Пользователю отправлено приветственное сообщение.</i>\n" .
                      "<i>Ожидаем ответа в течение 8 часов.</i>\n\n" .
                      "────────────────────\n" .
                      "<i>Тема заявок: <a href='" . ADMIN_CHANNEL_LINK . "'>#" . ADMIN_TOPIC_ID . "</a></i>";
            
            self::callTelegramApi('sendMessage', [
                'chat_id' => ADMIN_CHANNEL_ID,
                'message_thread_id' => ADMIN_TOPIC_ID,
                'text' => $message,
                'parse_mode' => 'HTML'
            ]);
            
        } catch (Exception $e) {
            error_log("Error notifying new request: " . $e->getMessage());
        }
    }
}
?>