<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../keyboards/InlineKeyboards.php';
require_once __DIR__ . '/ForumHandler.php';


class CallbackHandler {
    private $db;
    
    public function __construct($db) {
        $this->db = $db;
    }
    


     /**
     * Редактирование сообщения в форуме при обработке callback
     */
    private function editForumMessageText($messageId, $text, $replyMarkup = null) {
        return ForumHandler::editForumMessage($messageId, $text, $replyMarkup);
    }
    
    /**
     * Редактирование клавиатуры в форуме
     */
    private function editForumMessageReplyMarkup($messageId, $replyMarkup) {
        return ForumHandler::editForumMessageReplyMarkup($messageId, $replyMarkup);
    }
    
    /**
     * Обновление текста сообщения в форуме
     */
    private function getUpdatedForumMessageText($messageId, $newStatusText) {
        // В форумах нельзя получить текст сообщения через getChat,
        // поэтому формируем новый текст на основе переданного статуса
        return $this->updateStatusInMessage($this->getOriginalMessageText($messageId), $newStatusText);
    }

    private function updateStatusInMessage($messageText, $newStatus) {
        $lines = explode("\n", $messageText);
        
        foreach ($lines as $i => $line) {
            if (strpos($line, '📋 <b>Статус:</b>') !== false) {
                $lines[$i] = "📋 <b>Статус:</b> " . $newStatus;
                break;
            }
        }
        
        return implode("\n", $lines);
    }


   

    /**
     * Обработка всех callback-запросов
     */
    public function handleCallback($callbackQuery) {
        try {
            $data = $callbackQuery['data'];
            $callbackId = $callbackQuery['id'];
            $message = $callbackQuery['message'];
            $chatId = $message['chat']['id'];
            $messageId = $message['message_id'];
            $from = $callbackQuery['from'];
            $adminId = $from['id'];
            $adminName = $from['first_name'];
            


            
            // Логируем callback
            error_log("Callback received: {$data} from admin {$adminName}");
            
            // Разбираем callback_data
            $parts = explode('_', $data);
            $action = $parts[0];
            
            switch ($action) {
                case 'approve':
                case 'reject':
                    $this->handleModeration($data, $callbackId, $chatId, $messageId, $adminId);
                    break;
                    
                case 'confirm':
                    $this->handleConfirmation($data, $callbackId, $chatId, $messageId, $adminId);
                    break;
                    
                case 'cancel':
                    $this->cancelAction($data, $callbackId, $chatId, $messageId, $adminId);
                    break;
                    
                case 'comment':
                    $this->requestComment($data, $callbackId, $chatId, $messageId, $adminId);
                    break;
                    
                case 'timeout':
                    $this->markAsTimeout($data, $callbackId, $chatId, $messageId, $adminId);
                    break;
                    
                default:
                    $this->answerCallback($callbackId, "❌ Неизвестное действие");
                    break;
            }
            
            return true;
            
        } catch (Exception $e) {
            error_log("Error in handleCallback: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Обработка модерации (принять/отклонить)
     */
    private function handleModeration($data, $callbackId, $chatId, $messageId, $adminId) {
        $parts = explode('_', $data);
        
        if (count($parts) < 3) {
            $this->answerCallback($callbackId, "❌ Ошибка данных");
            return;
        }
        
        $action = $parts[0]; // approve или reject
        $requestId = $parts[1];
        $userId = $parts[2];
        
        // Проверяем, не обработана ли уже заявка
        $request = $this->db->selectOne(
            "SELECT status FROM join_requests WHERE id = ?",
            $requestId
        );
        
        if (!$request) {
            $this->answerCallback($callbackId, "❌ Заявка не найдена");
            return;
        }
        
        if ($request['status'] != 'answered') {
            $this->answerCallback($callbackId, "⚠️ Заявка уже обработана");
            return;
        }
        
        // Отправляем запрос подтверждения
        $actionText = $action == 'approve' ? 'ПРИНЯТЬ' : 'ОТКЛОНИТЬ';
        $confirmationText = "Вы уверены, что хотите {$actionText} заявку #{$requestId}?";
        
        $this->editMessageText(
            $chatId,
            $messageId,
            $confirmationText,
            InlineKeyboards::getConfirmationKeyboard($requestId, $userId, $action)
        );
        
        $this->answerCallback($callbackId, "Подтвердите действие...");
    }
    
    /**
     * Обработка подтверждения действия
     */
    private function handleConfirmation($data, $callbackId, $chatId, $messageId, $adminId) {
        $parts = explode('_', $data);
        
        if (count($parts) < 4) {
            $this->answerCallback($callbackId, "❌ Ошибка данных");
            return;
        }
        
        $action = $parts[1]; // approve или reject
        $requestId = $parts[2];
        $userId = $parts[3];
        
        // Проверяем заявку
        $request = $this->db->selectOne(
            "SELECT * FROM join_requests WHERE id = ?",
            $requestId
        );
        
        if (!$request) {
            $this->answerCallback($callbackId, "❌ Заявка не найдена");
            return;
        }
        
        if ($request['status'] != 'answered') {
            $this->answerCallback($callbackId, "⚠️ Заявка уже обработана");
            
            // Обновляем клавиатуру на view-only
            $this->editMessageReplyMarkup(
                $chatId,
                $messageId,
                InlineKeyboards::getViewOnlyKeyboard($requestId, $userId, $request['status'])
            );
            return;
        }
        
        // Выполняем действие
        if ($action == 'approve') {
            $this->approveRequest($requestId, $userId, $adminId);
            $resultText = "✅ Заявка #{$requestId} ПРИНЯТА администратором";
            $status = 'approved';
        } else {
            $this->rejectRequest($requestId, $userId, $adminId);
            $resultText = "❌ Заявка #{$requestId} ОТКЛОНЕНА администратором";
            $status = 'rejected';
        }
        
        // Обновляем сообщение в админ-канале
        $newText = $this->getUpdatedMessageText($chatId, $messageId, $resultText);
       // Вместо editMessageText используем:
        $newText = "✅ Заявка #{$requestId} ПРИНЯТА администратором\n" .
                  "👤 Пользователь: {$userInfo['first_name']}\n" .
                  "🆔 User ID: <code>{$userId}</code>\n" .
                  "👨‍⚖️ Решил: Администратор\n" .
                  "📅 Время: " . date('d.m.Y H:i:s');
        
        $this->editForumMessageText(
            $messageId,
            $newText,
            InlineKeyboards::getViewOnlyKeyboard($requestId, $userId, 'approved')
        );
        
        $this->answerCallback($callbackId, "✅ Действие выполнено");
    }
    
    /**
     * Одобрение заявки
     */
    private function approveRequest($requestId, $userId, $adminId) {
        try {
            // Обновляем статус в БД
            $this->db->query(
                "UPDATE join_requests SET 
                status = 'approved',
                processed_by = ?,
                processed_at = NOW()
                WHERE id = ?",
                $adminId,
                $requestId
            );
            
            // Одобряем заявку в канале
            $this->callTelegramApi('approveChatJoinRequest', [
                'chat_id' => PUBLIC_CHANNEL,
                'user_id' => $userId
            ]);
            
            // Уведомляем пользователя
            $userMessage = "🎉 <b>Поздравляем! Ваша заявка одобрена!</b>\n\n" .
                          "Теперь вы участник клуба Defender Club Russia.\n" .
                          "Добро пожаловать в наше сообщество!\n\n" .
                          "Ссылка на канал: " . PUBLIC_CHANNEL;
            
            $this->callTelegramApi('sendMessage', [
                'chat_id' => $userId,
                'text' => $userMessage,
                'parse_mode' => 'HTML'
            ]);
            
            error_log("Request #{$requestId} approved by admin {$adminId}");
            
        } catch (Exception $e) {
            error_log("Error approving request #{$requestId}: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Отклонение заявки
     */
    private function rejectRequest($requestId, $userId, $adminId) {
        try {
            // Обновляем статус в БД
            $this->db->query(
                "UPDATE join_requests SET 
                status = 'rejected',
                processed_by = ?,
                processed_at = NOW()
                WHERE id = ?",
                $adminId,
                $requestId
            );
            
            // Отклоняем заявку в канале
            $this->callTelegramApi('declineChatJoinRequest', [
                'chat_id' => PUBLIC_CHANNEL,
                'user_id' => $userId
            ]);
            
            // Уведомляем пользователя
            $userMessage = "❌ <b>Ваша заявка отклонена</b>\n\n" .
                          "К сожалению, ваша заявка на вступление в Defender Club Russia была отклонена.\n\n" .
                          "<i>Возможные причины:</i>\n" .
                          "• Неполная или некорректная информация\n" .
                          "• Несоответствие требованиям клуба\n" .
                          "• Отсутствие подтверждения владения автомобилем\n\n" .
                          "По вопросам обращайтесь к администраторам.";
            
            $this->callTelegramApi('sendMessage', [
                'chat_id' => $userId,
                'text' => $userMessage,
                'parse_mode' => 'HTML'
            ]);
            
            error_log("Request #{$requestId} rejected by admin {$adminId}");
            
        } catch (Exception $e) {
            error_log("Error rejecting request #{$requestId}: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Отмена действия
     */
    private function cancelAction($data, $callbackId, $chatId, $messageId, $adminId) {
        $parts = explode('_', $data);
        $requestId = $parts[1];
        $userId = $parts[2];
        
        // Возвращаем исходную клавиатуру
        $originalText = $this->getOriginalMessageText($chatId, $messageId);
        $this->editMessageText(
            $chatId,
            $messageId,
            $originalText,
            InlineKeyboards::getRequestKeyboard($requestId, $userId)
        );
        
        $this->answerCallback($callbackId, "❌ Действие отменено");
    }
    
    /**
     * Пометить как просроченную
     */
    private function markAsTimeout($data, $callbackId, $chatId, $messageId, $adminId) {
        $parts = explode('_', $data);
        $requestId = $parts[1];
        $userId = $parts[2];
        
        // Обновляем статус в БД
        $this->db->query(
            "UPDATE join_requests SET 
            status = 'timeout',
            processed_by = ?,
            processed_at = NOW()
            WHERE id = ?",
            $adminId,
            $requestId
        );
        
        // Отклоняем заявку в канале
        $this->callTelegramApi('declineChatJoinRequest', [
            'chat_id' => PUBLIC_CHANNEL,
            'user_id' => $userId
        ]);
        
        // Обновляем сообщение
        $newText = $this->getUpdatedMessageText($chatId, $messageId, "⏰ Заявка #{$requestId} ПРОСРОЧЕНА администратором");
        $this->editMessageText(
            $chatId,
            $messageId,
            $newText,
            InlineKeyboards::getViewOnlyKeyboard($requestId, $userId, 'timeout')
        );
        
        $this->answerCallback($callbackId, "✅ Заявка помечена как просроченная");
    }
    
    /**
     * Запрос комментария
     */
    private function requestComment($data, $callbackId, $chatId, $messageId, $adminId) {
        $parts = explode('_', $data);
        $requestId = $parts[1];
        $userId = $parts[2];
        
        // Сохраняем информацию о том, что ожидается комментарий
        $this->db->query(
            "INSERT INTO admin_comments (request_id, admin_id, chat_id, message_id, status) 
            VALUES (?, ?, ?, ?, 'pending')
            ON DUPLICATE KEY UPDATE status = 'pending'",
            $requestId, $adminId, $chatId, $messageId
        );
        
        $this->answerCallback($callbackId, "✏️ Отправьте комментарий текстовым сообщением");
    }
    
    /**
     * Вспомогательные методы
     */
    private function getUpdatedMessageText($chatId, $messageId, $newStatusText) {
        $message = $this->callTelegramApi('getChat', ['chat_id' => $chatId]);
        if (!$message || !isset($message['result'])) {
            return $newStatusText;
        }
        
        $originalText = $message['result']['text'] ?? '';
        $lines = explode("\n", $originalText);
        
        // Заменяем строку со статусом
        foreach ($lines as $i => $line) {
            if (strpos($line, '📋 <b>Статус:</b>') !== false) {
                $lines[$i] = "📋 <b>Статус:</b> " . $newStatusText;
                break;
            }
        }
        
        return implode("\n", $lines);
    }
    
    private function getOriginalMessageText($chatId, $messageId) {
        $message = $this->callTelegramApi('getChat', ['chat_id' => $chatId]);
        return $message['result']['text'] ?? 'Сообщение не найдено';
    }


    
    private function editMessageText($chatId, $messageId, $text, $replyMarkup = null) {
        $params = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'HTML'
        ];
        
        if ($replyMarkup) {
            $params['reply_markup'] = $replyMarkup;
        }
        
        return $this->callTelegramApi('editMessageText', $params);
    }
    
    private function editMessageReplyMarkup($chatId, $messageId, $replyMarkup) {
        return $this->callTelegramApi('editMessageReplyMarkup', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'reply_markup' => $replyMarkup
        ]);
    }
    
    private function answerCallback($callbackId, $text) {
        return $this->callTelegramApi('answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text' => $text,
            'show_alert' => false
        ]);
    }
    
    private function callTelegramApi($method, $params = []) {
        $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/" . $method;
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $params,
            CURLOPT_TIMEOUT => 10
        ]);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        return json_decode($response, true);
    }
}
?>