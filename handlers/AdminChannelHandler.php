<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../keyboards/InlineKeyboards.php';
require_once __DIR__ . '/ForumHandler.php';

class AdminChannelHandler {
    
    /**
     * Отправка новой заявки в топик форума
     */
    public static function sendNewRequestToForum($userId, $userMessage = null, $photoFileId = null, $requestId = null) {
        try {
            // Получаем информацию о пользователе
            $userInfo = self::getUserInfo($userId);
            
            // Формируем сообщение для форума
            $forumMessage = self::formatForumMessage($userId, $userInfo, $requestId, $userMessage);
            
            // Отправляем в топик форума
            $messageId = ForumHandler::sendToForumTopic(
                $forumMessage,
                InlineKeyboards::getRequestKeyboard($requestId, $userId),
                $photoFileId
            );
            
            // Если есть текстовый ответ пользователя, отправляем его отдельным сообщением
            if ($userMessage && !empty(trim($userMessage)) && 
                !in_array(trim($userMessage), ['/start', '/начать'])) {
                
                $replyText = "📝 <b>Текст ответа пользователя:</b>\n" .
                            "<code>" . htmlspecialchars($userMessage) . "</code>";
                
                ForumHandler::sendToForumTopic(
                    $replyText,
                    null,
                    null,
                    $messageId // Ответ на основное сообщение
                );
            }
            
            return $messageId;
            
        } catch (Exception $e) {
            error_log("Error sending to forum: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Форматирование сообщения для топика форума
     */
    private static function formatForumMessage($userId, $userInfo, $requestId, $userMessage = null) {
        $statusEmoji = "📨";
        $userLink = $userInfo['username'] ? 
                   "<a href='https://t.me/{$userInfo['username']}'>@{$userInfo['username']}</a>" : 
                   "без username";
        
        $lastName = !empty($userInfo['last_name']) ? " " . $userInfo['last_name'] : "";
        
        $message = "{$statusEmoji} <b>НОВАЯ ЗАЯВКА #{$requestId}</b>\n\n" .
                  "👤 <b>Пользователь:</b> {$userInfo['first_name']}{$lastName}\n" .
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
     * Получение информации о пользователе
     */
    private static function getUserInfo($userId) {
        try {
            $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/getChat";
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => ['chat_id' => $userId],
                CURLOPT_TIMEOUT => 5
            ]);
            
            $response = curl_exec($ch);
            curl_close($ch);
            
            $result = json_decode($response, true);
            
            if ($result['ok']) {
                return [
                    'id' => $userId,
                    'username' => $result['result']['username'] ?? null,
                    'first_name' => $result['result']['first_name'] ?? 'Не указано',
                    'last_name' => $result['result']['last_name'] ?? ''
                ];
            }
            
        } catch (Exception $e) {
            error_log("Error getting user info: " . $e->getMessage());
        }
        
        return [
            'id' => $userId,
            'username' => null,
            'first_name' => 'Неизвестно',
            'last_name' => ''
        ];
    }
    
    /**
     * Уведомление о просроченной заявке в топик форума
     */
    public static function notifyExpiredRequestInForum($requestId, $userId, $username, $firstName) {
        try {
            $usernameDisplay = $username ? "@{$username}" : "без username";
            $lastAction = date('H:i', time() + RESPONSE_TIMEOUT);
            
            $message = "❌ <b>ЗАЯВКА ПРОСРОЧЕНА #{$requestId}</b>\n\n" .
                      "👤 <b>Пользователь:</b> {$firstName}\n" .
                      "🔗 <b>Username:</b> {$usernameDisplay}\n" .
                      "🆔 <b>User ID:</b> <code>{$userId}</code>\n\n" .
                      "📋 <b>Статус:</b> Автоматически отклонена\n" .
                      "⏰ <b>Причина:</b> Не ответил в течение 8 часов\n" .
                      "🕐 <b>Последнее действие:</b> {$lastAction}\n" .
                      "📅 <b>Время:</b> " . date('d.m.Y H:i:s') . "\n\n" .
                      "────────────────────\n" .
                      "<i>Тема заявок: <a href='" . ADMIN_CHANNEL_LINK . "'>#" . ADMIN_TOPIC_ID . "</a></i>";
            
            ForumHandler::sendToForumTopic($message);
            
        } catch (Exception $e) {
            error_log("Error notifying expired request: " . $e->getMessage());
        }
    }
    
    /**
     * Уведомление о новой заявке (до получения ответа)
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
            
            ForumHandler::sendToForumTopic($message);
            
        } catch (Exception $e) {
            error_log("Error notifying new request: " . $e->getMessage());
        }
    }
    
    /**
     * Отправка системного уведомления в топик форума
     */
    public static function sendSystemNotification($title, $message, $type = 'info') {
        try {
            $icons = [
                'info' => 'ℹ️',
                'success' => '✅',
                'warning' => '⚠️',
                'error' => '❌',
                'debug' => '🐛'
            ];
            
            $icon = $icons[$type] ?? $icons['info'];
            
            $formattedMessage = "{$icon} <b>{$title}</b>\n\n" .
                               "{$message}\n\n" .
                               "📅 <i>" . date('d.m.Y H:i:s') . "</i>\n" .
                               "────────────────────\n" .
                               "<i>Тема заявок: <a href='" . ADMIN_CHANNEL_LINK . "'>#" . ADMIN_TOPIC_ID . "</a></i>";
            
            ForumHandler::sendToForumTopic($formattedMessage);
            
        } catch (Exception $e) {
            error_log("Error sending system notification: " . $e->getMessage());
        }
    }
}
?>