<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../keyboards/InlineKeyboards.php';
require_once __DIR__ . '/ForumHandler.php';


class CallbackHandler
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Редактирование клавиатуры в форуме
     */
    private function editForumMessageReplyMarkup($messageId, $replyMarkup)
    {
        return ForumHandler::editForumMessageReplyMarkup($messageId, $replyMarkup);
    }

    private function updateStatusInMessage($messageText, $newStatus)
    {
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
    public function handleCallback($callbackQuery)
    {
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
    private function handleModeration($data, $callbackId, $chatId, $messageId, $adminId)
    {
        $parts = explode('_', $data);

        if (count($parts) < 3) {
            $this->answerCallback($callbackId, "❌ Ошибка данных");
            return;
        }

        $action = $parts[0]; // approve или reject
        $requestId = $parts[1];
        $userId = $parts[2];

        // Проверяем заявку
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

        // Сразу отправляем новое сообщение с подтверждением
        $actionText = $action == 'approve' ? 'ПРИНЯТЬ' : 'ОТКЛОНИТЬ';
        $confirmationText = "Вы уверены, что хотите {$actionText} заявку #{$requestId}?";

        $newMessageId = $this->sendConfirmationToForum($confirmationText, $requestId, $userId, $action);

        if ($newMessageId) {
            // Сохраняем ID нового сообщения для последующего редактирования
            $this->db->query(
                "UPDATE join_requests SET confirmation_message_id = ? WHERE id = ?",
                $newMessageId,
                $requestId
            );
        }

        $this->answerCallback($callbackId, "Подтвердите действие...");
    }

    private function sendConfirmationToForum($text, $requestId, $userId, $action)
    {
        try {
            $keyboard = InlineKeyboards::getConfirmationKeyboard($requestId, $userId, $action);

            $response = $this->callTelegramApi('sendMessage', [
                'chat_id' => ADMIN_CHANNEL_ID,
                'message_thread_id' => ADMIN_TOPIC_ID,
                'text' => $text,
                'parse_mode' => 'HTML',
                'reply_markup' => $keyboard
            ]);

            return $response['result']['message_id'] ?? null;
        } catch (Exception $e) {
            error_log("Error sending confirmation to forum: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Обработка подтверждения действия
     */
    private function handleConfirmation($data, $callbackId, $chatId, $messageId, $adminId)
    {
        try {
            $parts = explode('_', $data);

            if (count($parts) < 4) {
                $this->answerCallback($callbackId, "❌ Ошибка данных");
                return;
            }

            $action = $parts[1]; // 'approve' или 'reject'
            $requestId = (int)$parts[2];
            $userId = (int)$parts[3];

            error_log("Processing confirmation: action={$action}, requestId={$requestId}, userId={$userId}");

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
                $this->editForumMessageReplyMarkup(
                    $chatId,
                    $messageId,
                    InlineKeyboards::getViewOnlyKeyboard($requestId, $userId, $request['status'])
                );
                return;
            }

            // ВАЖНО: Получаем username канала из конфига
            $channelUsername = PUBLIC_CHANNEL;

            // Выполняем действие в канале
            $result = false;
            $status = '';
            $statusText = '';

            if ($action == 'approve') {
                $result = $this->approveRequest($channelUsername, $userId, $requestId, $adminId);
                $status = 'approved';
                $statusText = "✅ Заявка #{$requestId} ПРИНЯТА";
            } else {
                $result = $this->rejectRequest($channelUsername, $userId, $requestId, $adminId);
                $status = 'rejected';
                $statusText = "❌ Заявка #{$requestId} ОТКЛОНЕНА";
            }

            if ($result) {
                // Обновляем статус в БД
                $this->db->query(
                    "UPDATE join_requests SET 
                status = ?,
                processed_by = ?,
                processed_at = NOW()
                WHERE id = ?",
                    $status,
                    $adminId,
                    $requestId
                );

                // Получаем информацию для форматирования
                $userInfo = $this->getUserInfo($userId);
                $adminInfo = $this->getUserInfo($adminId);

                $adminName = $adminInfo['username'] ? "@" . $adminInfo['username'] : "Администратор";
                $userName = $userInfo['username'] ? "@" . $userInfo['username'] : ($userInfo['first_name'] ?? "Пользователь");

                // Формируем итоговое сообщение
                $resultMessage = "{$statusText}\n\n" .
                    "👤 <b>Пользователь:</b> {$userName}\n" .
                    "🆔 <b>User ID:</b> <code>{$userId}</code>\n" .
                    "👨‍⚖️ <b>Решил:</b> {$adminName}\n" .
                    "📅 <b>Время:</b> " . date('d.m.Y H:i:s') . "\n\n" .
                    "<i>Заявка обработана в основном канале.</i>";

                // Обновляем сообщение (используем исправленный метод, который работает и с фото, и с текстом)
                $this->editForumMessageText(
                    $messageId,
                    $resultMessage,
                    InlineKeyboards::getViewOnlyKeyboard($requestId, $userId, $status)
                );

                $this->answerCallback($callbackId, "✅ Действие выполнено");
                error_log("Action {$action} completed successfully for request #{$requestId}");
            } else {
                $this->answerCallback($callbackId, "❌ Ошибка выполнения действия");
                error_log("Action {$action} failed for request #{$requestId}");
            }
        } catch (Exception $e) {
            error_log("Error in handleConfirmation: " . $e->getMessage());
            $this->answerCallback($callbackId, "❌ Внутренняя ошибка");
        }
    }

    /**
     * Одобрение заявки в канале (возвращает true/false)
     */
    private function approveRequest($channelUsername, $userId, $requestId, $adminId)
    {
        try {
            error_log("Approving request #{$requestId} for user {$userId} in channel {$channelUsername}");

            // ВАЖНО: Параметр hide_requester должен быть string, не boolean!
            $params = [
                'chat_id' => $channelUsername,
                'user_id' => (string)$userId,
                'hide_requester' => 'false'  // СТРОКА 'false', не boolean false
            ];

            error_log("Sending approve params: " . json_encode($params, JSON_UNESCAPED_UNICODE));

            $response = $this->callTelegramApi('approveChatJoinRequest', $params);

            if ($response['ok']) {
                // Уведомляем пользователя
                $this->notifyUserAboutApproval($userId);
                return true;
            } else {
                $errorDescription = $response['description'] ?? 'Unknown error';
                error_log("Telegram API Error for approveChatJoinRequest: " . $errorDescription);

                // Проверяем, если ошибка в том, что заявка уже обработана
                if (
                    strpos($errorDescription, 'USER_ALREADY_PARTICIPANT') !== false ||
                    strpos($errorDescription, 'CHAT_JOIN_REQUEST_APPROVED') !== false ||
                    strpos($errorDescription, 'CHAT_JOIN_REQUEST_DECLINED') !== false
                ) {
                    error_log("Request already processed, marking as success.");
                    return true;
                }

                // Пробуем без hide_requester (для старых версий API)
                if (strpos($errorDescription, 'HIDE_REQUESTER_MISSING') !== false) {
                    error_log("Trying without hide_requester parameter...");
                    unset($params['hide_requester']);
                    $response2 = $this->callTelegramApi('approveChatJoinRequest', $params);
                    if ($response2['ok']) {
                        $this->notifyUserAboutApproval($userId);
                        return true;
                    }
                }

                return false;
            }
        } catch (Exception $e) {
            error_log("Error approving request #{$requestId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Отклонение заявки в канале (возвращает true/false)
     */
    private function rejectRequest($channelUsername, $userId, $requestId, $adminId)
    {
        try {
            error_log("Rejecting request #{$requestId} for user {$userId} in channel {$channelUsername}");

            // ВАЖНО: Параметр hide_requester должен быть string, не boolean!
            $params = [
                'chat_id' => $channelUsername,
                'user_id' => (string)$userId,
                'hide_requester' => 'false'  // СТРОКА 'false', не boolean false
            ];

            error_log("Sending reject params: " . json_encode($params, JSON_UNESCAPED_UNICODE));

            $response = $this->callTelegramApi('declineChatJoinRequest', $params);

            if ($response['ok']) {
                // Уведомляем пользователя
                $this->notifyUserAboutRejection($userId);
                return true;
            } else {
                $errorDescription = $response['description'] ?? 'Unknown error';
                error_log("Telegram API Error for declineChatJoinRequest: " . $errorDescription);

                // Проверяем, если ошибка в том, что заявка уже обработана
                if (
                    strpos($errorDescription, 'CHAT_JOIN_REQUEST_DECLINED') !== false ||
                    strpos($errorDescription, 'USER_ALREADY_PARTICIPANT') !== false ||
                    strpos($errorDescription, 'CHAT_JOIN_REQUEST_APPROVED') !== false
                ) {
                    error_log("Request already processed, marking as success.");
                    return true;
                }

                // Пробуем без hide_requester (для старых версий API)
                if (strpos($errorDescription, 'HIDE_REQUESTER_MISSING') !== false) {
                    error_log("Trying without hide_requester parameter...");
                    unset($params['hide_requester']);
                    $response2 = $this->callTelegramApi('declineChatJoinRequest', $params);
                    if ($response2['ok']) {
                        $this->notifyUserAboutRejection($userId);
                        return true;
                    }
                }

                return false;
            }
        } catch (Exception $e) {
            error_log("Error rejecting request #{$requestId}: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Обновленный метод callTelegramApi с подробным логированием
     */
    private function callTelegramApi($method, $params = [])
    {
        $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/" . $method;

        // Логируем ВСЕ параметры (скрываем только токен)
        $logParams = $params;
        $logMessage = "Telegram API Call: {$method} with params: " . json_encode($logParams, JSON_UNESCAPED_UNICODE);
        error_log($logMessage);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $params,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Content-Type: multipart/form-data']
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            error_log("CURL Error for {$method}: {$error}");
            curl_close($ch);
            return ['ok' => false, 'error_code' => 0, 'description' => 'CURL Error: ' . $error];
        }

        curl_close($ch);

        $responseData = json_decode($response, true) ?: [];

        // Логируем полный ответ
        error_log("Telegram API Response [{$httpCode}]: " . json_encode($responseData, JSON_UNESCAPED_UNICODE));

        return $responseData;
    }

    /**
     * Исправленный метод редактирования сообщений (работает и с фото, и с текстом)
     */
    private function editForumMessageText($messageId, $text, $replyMarkup = null)
    {
        try {
            $params = [
                'chat_id' => ADMIN_CHANNEL_ID,
                'message_id' => $messageId,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true
            ];

            // Сначала пробуем отредактировать как текст
            $params['text'] = $text;
            if ($replyMarkup) {
                $params['reply_markup'] = $replyMarkup;
            }

            $response = $this->callTelegramApi('editMessageText', $params);

            // Если получили ошибку "no text", значит это фото - редактируем caption
            if (!$response['ok'] && strpos($response['description'] ?? '', 'no text') !== false) {
                error_log("Message #{$messageId} is a photo, editing caption instead");

                $photoParams = [
                    'chat_id' => ADMIN_CHANNEL_ID,
                    'message_id' => $messageId,
                    'caption' => $text,
                    'parse_mode' => 'HTML'
                ];

                if ($replyMarkup) {
                    $photoParams['reply_markup'] = $replyMarkup;
                }

                $response = $this->callTelegramApi('editMessageCaption', $photoParams);
            }

            return $response['ok'] ?? false;
        } catch (Exception $e) {
            error_log("Error editing forum message #{$messageId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Уведомление пользователя об одобрении
     */
    private function notifyUserAboutApproval($userId)
    {
        try {
            $message = "🎉 <b>Поздравляем! Ваша заявка одобрена!</b>\n\n" .
                "Теперь вы участник клуба Defender Club Russia.\n" .
                "Добро пожаловать в наше сообщество!\n\n" .
                "Ссылка на канал: " . PUBLIC_CHANNEL;

            $this->callTelegramApi('sendMessage', [
                'chat_id' => $userId,
                'text' => $message,
                'parse_mode' => 'HTML'
            ]);
        } catch (Exception $e) {
            error_log("Error notifying user about approval: " . $e->getMessage());
        }
    }

    /**
     * Уведомление пользователя об отклонении
     */
    private function notifyUserAboutRejection($userId)
    {
        try {
            $message = "❌ <b>Ваша заявка отклонена</b>\n\n" .
                "К сожалению, ваша заявка на вступление в Defender Club Russia была отклонена.\n\n" .
                "<i>Возможные причины:</i>\n" .
                "• Неполная или некорректная информация\n" .
                "• Несоответствие требованиям клуба\n" .
                "• Отсутствие подтверждения владения автомобилем\n\n" .
                "По вопросам обращайтесь к администраторам.";

            $this->callTelegramApi('sendMessage', [
                'chat_id' => $userId,
                'text' => $message,
                'parse_mode' => 'HTML'
            ]);
        } catch (Exception $e) {
            error_log("Error notifying user about rejection: " . $e->getMessage());
        }
    }

    /**
     * Получение информации о пользователе
     */
    private function getUserInfo($userId)
    {
        try {
            $response = $this->callTelegramApi('getChat', [
                'chat_id' => $userId
            ]);

            if ($response['ok']) {
                return [
                    'id' => $userId,
                    'username' => $response['result']['username'] ?? null,
                    'first_name' => $response['result']['first_name'] ?? 'Неизвестно'
                ];
            }
        } catch (Exception $e) {
            // Игнорируем ошибки
        }

        return [
            'id' => $userId,
            'username' => null,
            'first_name' => 'Пользователь'
        ];
    }

    /**
     * Отмена действия
     */
    private function cancelAction($data, $callbackId, $chatId, $messageId, $adminId)
    {
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
    private function markAsTimeout($data, $callbackId, $chatId, $messageId, $adminId)
    {
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
    private function requestComment($data, $callbackId, $chatId, $messageId, $adminId)
    {
        $parts = explode('_', $data);
        $requestId = $parts[1];
        $userId = $parts[2];

        // Сохраняем информацию о том, что ожидается комментарий
        $this->db->query(
            "INSERT INTO admin_comments (request_id, admin_id, chat_id, message_id, status) 
            VALUES (?, ?, ?, ?, 'pending')
            ON DUPLICATE KEY UPDATE status = 'pending'",
            $requestId,
            $adminId,
            $chatId,
            $messageId
        );

        $this->answerCallback($callbackId, "✏️ Отправьте комментарий текстовым сообщением");
    }

    /**
     * Вспомогательные методы
     */
    private function getUpdatedMessageText($chatId, $messageId, $newStatusText)
    {
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

    private function getOriginalMessageText($chatId, $messageId)
    {
        $message = $this->callTelegramApi('getChat', ['chat_id' => $chatId]);
        return $message['result']['text'] ?? 'Сообщение не найдено';
    }

    private function editMessageText($chatId, $messageId, $text, $replyMarkup = null)
    {
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

    private function editMessageReplyMarkup($chatId, $messageId, $replyMarkup)
    {
        return $this->callTelegramApi('editMessageReplyMarkup', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'reply_markup' => $replyMarkup
        ]);
    }

    private function answerCallback($callbackId, $text)
    {
        return $this->callTelegramApi('answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text' => $text,
            'show_alert' => false
        ]);
    }
}
