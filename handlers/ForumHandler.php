<?php
require_once __DIR__ . '/../config/config.php';

class ForumHandler {
    
    /**
     * Отправка сообщения в топик форума
     */
    public static function sendToForumTopic($text, $replyMarkup = null, $photoFileId = null, $replyToMessageId = null) {
        try {
            $params = [
                'chat_id' => ADMIN_CHANNEL_ID,
                'message_thread_id' => ADMIN_TOPIC_ID,
                'parse_mode' => 'HTML',
                'disable_notification' => false
            ];
            
            if ($photoFileId) {
                // Отправка фото с подписью
                $params['photo'] = $photoFileId;
                $params['caption'] = $text;
                if ($replyMarkup) $params['reply_markup'] = $replyMarkup;
                
                $response = self::callTelegramApi('sendPhoto', $params);
            } else {
                // Отправка текстового сообщения
                $params['text'] = $text;
                $params['disable_web_page_preview'] = true;
                if ($replyMarkup) $params['reply_markup'] = $replyMarkup;
                if ($replyToMessageId) $params['reply_to_message_id'] = $replyToMessageId;
                
                $response = self::callTelegramApi('sendMessage', $params);
            }
            
            return $response['result']['message_id'] ?? null;
            
        } catch (Exception $e) {
            error_log("ForumHandler error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Пересылка сообщения пользователя в топик форума
     */
    public static function forwardToForumTopic($fromChatId, $messageId) {
        try {
            $response = self::callTelegramApi('forwardMessage', [
                'chat_id' => ADMIN_CHANNEL_ID,
                'message_thread_id' => ADMIN_TOPIC_ID,
                'from_chat_id' => $fromChatId,
                'message_id' => $messageId,
                'disable_notification' => true
            ]);
            
            return $response['result']['message_id'] ?? null;
            
        } catch (Exception $e) {
            error_log("Error forwarding to forum: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Редактирование сообщения в топике форума
     */
    public static function editForumMessage($messageId, $text, $replyMarkup = null) {
        try {
            $params = [
                'chat_id' => ADMIN_CHANNEL_ID,
                'message_id' => $messageId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true
            ];
            
            if ($replyMarkup) {
                $params['reply_markup'] = $replyMarkup;
            }
            
            $response = self::callTelegramApi('editMessageText', $params);
            
            return $response['ok'] ?? false;
            
        } catch (Exception $e) {
            error_log("Error editing forum message: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Редактирование только клавиатуры в топике форума
     */
    public static function editForumMessageReplyMarkup($messageId, $replyMarkup) {
        try {
            $response = self::callTelegramApi('editMessageReplyMarkup', [
                'chat_id' => ADMIN_CHANNEL_ID,
                'message_id' => $messageId,
                'reply_markup' => $replyMarkup
            ]);
            
            return $response['ok'] ?? false;
            
        } catch (Exception $e) {
            error_log("Error editing forum reply markup: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Удаление сообщения из топика форума
     */
    public static function deleteForumMessage($messageId) {
        try {
            $response = self::callTelegramApi('deleteMessage', [
                'chat_id' => ADMIN_CHANNEL_ID,
                'message_id' => $messageId
            ]);
            
            return $response['ok'] ?? false;
            
        } catch (Exception $e) {
            error_log("Error deleting forum message: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Универсальный метод для вызова API Telegram
     */
    private static function callTelegramApi($method, $params = []) {
        $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/" . $method;
        
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
            throw new Exception('CURL Error: ' . curl_error($ch));
        }
        
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode != 200) {
            error_log("HTTP Error {$httpCode} for method {$method}");
            throw new Exception("HTTP Error {$httpCode}");
        }
        
        $decoded = json_decode($response, true);
        
        if (!$decoded['ok']) {
            error_log("Telegram API Error for {$method}: " . 
                     ($decoded['description'] ?? 'Unknown error'));
            throw new Exception("Telegram API Error: " . 
                              ($decoded['description'] ?? 'Unknown error'));
        }
        
        return $decoded;
    }
}
?>