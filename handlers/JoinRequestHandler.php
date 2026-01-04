<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/AdminChannelHandler.php';
require_once __DIR__ . '/ForumHandler.php';

class JoinRequestHandler {
    private $db;
    
    public function __construct($db) {
        $this->db = $db;
    }
    
    /**
     * Главный обработчик входящих обновлений
     */
    public function handleUpdate($update) {
        // Логируем тип обновления для отладки
        $updateType = 'unknown';
        if (isset($update['message'])) $updateType = 'message';
        if (isset($update['chat_join_request'])) $updateType = 'chat_join_request';
        if (isset($update['callback_query'])) $updateType = 'callback_query';
        
        error_log("JoinRequestHandler: Received update type: {$updateType}");
        
        // 1. Обработка заявок на вступление
        if (isset($update['chat_join_request'])) {
            return $this->processJoinRequest($update['chat_join_request']);
        }
        
        // 2. Обработка личных сообщений от пользователей
        if (isset($update['message']) && isset($update['message']['chat']['type']) && 
            $update['message']['chat']['type'] == 'private') {
            return $this->processPrivateMessage($update['message']);
        }
        
        return false;
    }
    
    /**
     * Обработка заявки на вступление в канал
     */
    private function processJoinRequest($joinRequest) {
        try {
            $user = $joinRequest['from'];
            $userId = $user['id'];
            $username = $user['username'] ?? null;
            $firstName = $user['first_name'] ?? null;
            $lastName = $user['last_name'] ?? null;
            
            error_log("Processing join request from user {$userId} ({$username})");
            
            // Проверяем, для нашего ли канала заявка
            if (isset($joinRequest['chat']['username']) && 
                $joinRequest['chat']['username'] != str_replace('@', '', PUBLIC_CHANNEL)) {
                error_log("Join request for wrong channel: " . $joinRequest['chat']['username']);
                return false;
            }
            
            // Проверяем дубликаты активных заявок
            $existing = $this->db->selectOne(
                "SELECT id FROM join_requests WHERE user_id = ? AND status = 'pending'",
                $userId
            );
            
            if ($existing) {
                error_log("User {$userId} already has pending request");
                return true;
            }
            
            // Рассчитываем время истечения
            $expiresAt = date('Y-m-d H:i:s', time() + RESPONSE_TIMEOUT);
            
            // Сохраняем в базу
            $this->db->query(
                "INSERT INTO join_requests 
                (user_id, username, first_name, last_name, expires_at, dialog_step, status) 
                VALUES (?, ?, ?, ?, ?, 'welcome_sent', 'pending')",
                $userId,
                $username,
                $firstName,
                $lastName,
                $expiresAt
            );
            
            $requestId = $this->db->selectOne("SELECT LAST_INSERT_ID() as id");
            $requestId = $requestId['id'];
            
            error_log("Created join request #{$requestId} for user {$userId}");
            
            // Пытаемся отправить приветственное сообщение
            $messageSent = $this->sendWelcomeMessage($userId, $firstName);
            
            if ($messageSent) {
                // Обновляем статус
                $this->db->query(
                    "UPDATE join_requests SET welcome_sent = TRUE, welcome_sent_at = NOW() WHERE id = ?",
                    $requestId
                );
                
                // Уведомляем админ-форум о НОВОЙ заявке (еще без ответа)
                AdminChannelHandler::notifyNewPendingRequest(
                    $requestId,
                    $userId,
                    $username,
                    $firstName
                );
                
                error_log("Join request #{$requestId} processed for user {$userId}");
                return true;
                
            } else {
                // Если сообщение не отправилось, отмечаем ошибку
                error_log("Failed to send welcome message to user {$userId}");
                // Не отклоняем заявку сразу - пользователь может написать /start
                return false;
            }
            
        } catch (Exception $e) {
            error_log("Error in processJoinRequest: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Обработка личных сообщений от пользователей
     */
    private function processPrivateMessage($message) {
        try {
            $userId = $message['from']['id'];
            $text = $message['text'] ?? '';
            $messageId = $message['message_id'] ?? null;
            
            error_log("Processing private message from user {$userId}: " . substr($text, 0, 100));
            
            // ============================================
            // 1. ПОИСК АКТИВНОЙ ЗАЯВКИ ПОЛЬЗОВАТЕЛЯ
            // ============================================
            $request = $this->db->selectOne(
                "SELECT id, status, dialog_step, first_name 
                FROM join_requests 
                WHERE user_id = ? AND status = 'pending'",
                $userId
            );
            
            // ============================================
            // 2. ЕСЛИ ЗАЯВКИ НЕТ - ОТПРАВЛЯЕМ ИНСТРУКЦИЮ
            // ============================================
            if (!$request) {
                error_log("No pending request found for user {$userId}");
                
                // Проверяем, есть ли вообще какая-то заявка у пользователя
                $anyRequest = $this->db->selectOne(
                    "SELECT id, status FROM join_requests WHERE user_id = ?",
                    $userId
                );
                
                if ($anyRequest) {
                    // Заявка есть, но не в статусе pending
                    $this->sendRequestStatusMessage($userId, $anyRequest['status']);
                    return false;
                }
                
                // Полностью новой заявки нет
                if ($text === '/start' || $text === '/начать') {
                    $this->sendNoActiveRequestMessage($userId);
                } else {
                    // Пользователь пишет что-то без активной заявки
                    $this->sendNoActiveRequestMessage($userId);
                }
                return false;
            }
            
            $requestId = $request['id'];
            error_log("Found pending request #{$requestId} for user {$userId}, dialog_step: {$request['dialog_step']}");
            
            // Обновляем время последнего взаимодействия
            $this->db->query(
                "UPDATE join_requests SET last_interaction = NOW() WHERE id = ?",
                $requestId
            );
            
            // ============================================
            // 3. ОБРАБОТКА КОМАНДЫ /start
            // ============================================
            if ($text === '/start' || $text === '/начать') {
                $this->handleStartCommand($userId, $request);
                return true;
            }
            
            // ============================================
            // 4. ПРОВЕРКА СТАТУСА ЗАЯВКИ (на случай, если статус изменился)
            // ============================================
            if ($request['status'] !== 'pending') {
                $this->sendRequestStatusMessage($userId, $request['status']);
                return false;
            }
            
            // ============================================
            // 5. ОБРАБОТКА ОТВЕТА ПОЛЬЗОВАТЕЛЯ
            // ============================================
            return $this->handleUserResponse($userId, $requestId, $message);
            
        } catch (Exception $e) {
            error_log("Error in processPrivateMessage: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Обработка команды /start
     */
    private function handleStartCommand($userId, $request) {
        $requestId = $request['id'];
        $dialogStep = $request['dialog_step'] ?? 'welcome_sent';
        $firstName = $request['first_name'] ?? null;
        
        error_log("Handling /start command for request #{$requestId}, dialog_step: {$dialogStep}");
        
        // Определяем, какое сообщение отправить в зависимости от этапа диалога
        switch ($dialogStep) {
            case 'welcome_sent':
                // Отправляем приветствие еще раз
                $this->sendWelcomeMessage($userId, $firstName);
                break;
                
            case 'waiting_photo':
                // Напоминаем, что нужно фото
                $this->sendPhotoReminder($userId);
                break;
                
            case 'waiting_text':
                // Напоминаем, что нужен текст
                $this->sendTextReminder($userId);
                break;
                
            case 'completed':
                // Заявка уже завершена
                $this->sendAlreadyCompletedMessage($userId);
                break;
                
            default:
                // Стандартное приветствие
                $this->sendWelcomeMessage($userId, $firstName);
                break;
        }
        
        // Обновляем этап диалога на welcome_sent при команде /start
        $this->db->query(
            "UPDATE join_requests SET dialog_step = 'welcome_sent' WHERE id = ?",
            $requestId
        );
        
        error_log("Updated dialog_step to 'welcome_sent' for request #{$requestId}");
    }
    
    /**
     * Обработка ответа пользователя (текст + фото)
     */
    private function handleUserResponse($userId, $requestId, $message) {
        $text = $message['text'] ?? '';
        $hasPhoto = isset($message['photo']) && is_array($message['photo']);
        
        error_log("Handling user response for request #{$requestId}: has_text=" . (!empty($text)) . ", has_photo=" . $hasPhoto);
        
        // Получаем текущий этап диалога
        $currentStep = $this->db->selectOne(
            "SELECT dialog_step, answer_text, answer_photo_id FROM join_requests WHERE id = ?",
            $requestId
        );
        
        if (!$currentStep) {
            error_log("Request #{$requestId} not found in database");
            return false;
        }
        
        $dialogStep = $currentStep['dialog_step'] ?? 'welcome_sent';
        $existingText = $currentStep['answer_text'] ?? '';
        $existingPhoto = $currentStep['answer_photo_id'] ?? null;
        
        error_log("Current dialog_step for request #{$requestId}: {$dialogStep}");
        
        // ============================================
        // ЛОГИКА ПОШАГОВОГО ДИАЛОГА
        // ============================================
        
        if ($dialogStep === 'welcome_sent') {
            // Первое сообщение после приветствия
            
            if ($hasPhoto) {
                // Пользователь прислал фото первым
                $photoFileId = $this->extractPhotoFileId($message);
                
                error_log("User sent photo first, file_id: {$photoFileId}");
                
                $this->db->query(
                    "UPDATE join_requests SET 
                    answer_photo_id = ?,
                    dialog_step = 'waiting_text'
                    WHERE id = ?",
                    $photoFileId,
                    $requestId
                );
                
                $this->sendTextRequest($userId);
                return true;
                
            } elseif (!empty(trim($text))) {
                // Пользователь прислал текст первым
                error_log("User sent text first: " . substr($text, 0, 50));
                
                $this->db->query(
                    "UPDATE join_requests SET 
                    answer_text = ?,
                    dialog_step = 'waiting_photo'
                    WHERE id = ?",
                    $text,
                    $requestId
                );
                
                $this->sendPhotoRequest($userId);
                return true;
                
            } else {
                // Пользователь прислал что-то непонятное (стикер, голосовое и т.д.)
                error_log("User sent invalid response type");
                $this->sendInvalidResponseMessage($userId);
                return false;
            }
            
        } elseif ($dialogStep === 'waiting_photo') {
            // Ждем фото от пользователя (уже есть текст)
            
            if ($hasPhoto) {
                $photoFileId = $this->extractPhotoFileId($message);
                
                error_log("User sent photo while waiting_photo, file_id: {$photoFileId}");
                
                // Обновляем запись - добавляем фото
                $this->db->query(
                    "UPDATE join_requests SET 
                    answer_photo_id = ?,
                    dialog_step = 'completed',
                    status = 'answered',
                    answer_date = NOW()
                    WHERE id = ?",
                    $photoFileId,
                    $requestId
                );
                
                // Отправляем подтверждение и пересылаем в админ-форум
                $this->finalizeRequest($userId, $requestId);
                return true;
                
            } elseif (!empty(trim($text))) {
                // Пользователь снова прислал текст вместо фото
                error_log("User sent text instead of photo");
                $this->sendPhotoReminder($userId);
                return false;
                
            } else {
                error_log("User sent invalid response while waiting_photo");
                $this->sendInvalidResponseMessage($userId);
                return false;
            }
            
        } elseif ($dialogStep === 'waiting_text') {
            // Ждем текст от пользователя (уже есть фото)
            
            if (!empty(trim($text))) {
                error_log("User sent text while waiting_text: " . substr($text, 0, 50));
                
                // Обновляем запись - добавляем текст
                $this->db->query(
                    "UPDATE join_requests SET 
                    answer_text = ?,
                    dialog_step = 'completed',
                    status = 'answered',
                    answer_date = NOW()
                    WHERE id = ?",
                    $text,
                    $requestId
                );
                
                // Отправляем подтверждение и пересылаем в админ-форум
                $this->finalizeRequest($userId, $requestId);
                return true;
                
            } elseif ($hasPhoto) {
                // Пользователь снова прислал фото вместо текста
                error_log("User sent photo instead of text");
                $this->sendTextReminder($userId);
                return false;
                
            } else {
                error_log("User sent invalid response while waiting_text");
                $this->sendInvalidResponseMessage($userId);
                return false;
            }
            
        } elseif ($dialogStep === 'completed') {
            // Заявка уже обработана
            error_log("Request #{$requestId} already completed");
            $this->sendAlreadyCompletedMessage($userId);
            return false;
        }
        
        error_log("Unknown dialog_step: {$dialogStep}");
        return false;
    }
    
    /**
     * Завершение обработки заявки
     */
    private function finalizeRequest($userId, $requestId) {
        try {
            // Получаем данные заявки
            $requestData = $this->db->selectOne(
                "SELECT answer_text, answer_photo_id, first_name, username 
                 FROM join_requests WHERE id = ?",
                $requestId
            );
            
            if (!$requestData) {
                error_log("Cannot finalize request #{$requestId}: data not found");
                return;
            }
            
            $answerText = $requestData['answer_text'] ?? '';
            $photoFileId = $requestData['answer_photo_id'] ?? null;
            
            error_log("Finalizing request #{$requestId}, text length: " . strlen($answerText) . ", has photo: " . ($photoFileId ? 'yes' : 'no'));
            
            // Отправляем в админ-форум
            $forumMessageId = AdminChannelHandler::sendNewRequestToForum(
                $userId,
                $answerText,
                $photoFileId,
                $requestId
            );
            
            // Сохраняем ID сообщения в форуме
            if ($forumMessageId) {
                $this->db->query(
                    "UPDATE join_requests SET admin_message_id = ? WHERE id = ?",
                    $forumMessageId,
                    $requestId
                );
                error_log("Saved forum message ID: {$forumMessageId} for request #{$requestId}");
            } else {
                error_log("Failed to get forum message ID for request #{$requestId}");
            }
            
            // Отправляем подтверждение пользователю
            $this->sendConfirmationToUser($userId);
            
            error_log("Request #{$requestId} completed for user {$userId}");
            
        } catch (Exception $e) {
            error_log("Error finalizing request #{$requestId}: " . $e->getMessage());
        }
    }
    
    /**
     * Извлечение file_id фото
     */
    private function extractPhotoFileId($message) {
        if (isset($message['photo']) && is_array($message['photo'])) {
            $lastPhoto = end($message['photo']);
            $fileId = $lastPhoto['file_id'];
            error_log("Extracted photo file_id: {$fileId}");
            return $fileId;
        }
        return null;
    }
    
    // ============================================
    // СООБЩЕНИЯ ДЛЯ ПОЛЬЗОВАТЕЛЯ
    // ============================================
    
    /**
     * Приветственное сообщение (с инструкцией)
     */
    private function sendWelcomeMessage($userId, $firstName = null) {
        try {
            $greeting = $firstName ? "👋 Здравствуйте, {$firstName}!\n\n" : "👋 Здравствуйте!\n\n";
            
            $message = $greeting . 
                      "Вы подали заявку на вступление в группу внедорожного клуба <b>Defender Club Russia</b>.\n\n" .
                      "📋 <b>Для подтверждения заявки необходимо:</b>\n" .
                      "1️⃣ Подтвердить, что вы русскоязычный\n" .
                      "2️⃣ Подтвердить, что владеете классическим внедорожником LR Defender\n" .
                      "3️⃣ Прислать фото вашего Defender\n\n" .
                      "💬 <b>Отправьте мне текстовое сообщение</b> с подтверждением (пункты 1 и 2)\n" .
                      "📸 <b>Затем отправьте фото</b> вашего автомобиля\n\n" .
                      "<i>Вы можете отправить текст и фото в любом порядке.</i>\n\n" .
                      "⏰ <b>Внимание!</b> Если вы не отправите ответ в течение 8 часов, заявка будет отклонена.\n\n" .
                      "📜 Также вам необходимо ознакомиться с правилами нашей группы:\n" .
                      "https://t.me/defenderchat/71944\n\n" .
                      "➡️ <b>Что делаем?</b> Напишите мне текстовое подтверждение или отправьте фото.";
            
            $result = $this->sendMessageToUser($userId, $message);
            
            if ($result) {
                error_log("Welcome message sent to user {$userId}");
            } else {
                error_log("Failed to send welcome message to user {$userId}");
            }
            
            return $result;
            
        } catch (Exception $e) {
            error_log("Error in sendWelcomeMessage: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Запрос текста (когда прислали фото первым)
     */
    private function sendTextRequest($userId) {
        $message = "✅ <b>Фото получено!</b>\n\n" .
                  "Теперь отправьте <b>текстовое подтверждение</b>:\n" .
                  "1. Подтвердите, что вы русскоязычный\n" .
                  "2. Подтвердите, что владеете классическим LR Defender\n\n" .
                  "💬 <b>Напишите сообщение</b> с этим подтверждением.";
        
        return $this->sendMessageToUser($userId, $message);
    }
    
    /**
     * Запрос фото (когда прислали текст первым)
     */
    private function sendPhotoRequest($userId) {
        $message = "✅ <b>Текст получен!</b>\n\n" .
                  "Теперь отправьте <b>фото вашего Defender</b>.\n\n" .
                  "📸 <b>Пришлите фото</b> вашего автомобиля.\n\n" .
                  "<i>Пожалуйста, отправьте именно фото (не документ и не ссылку).</i>";
        
        return $this->sendMessageToUser($userId, $message);
    }
    
    /**
     * Напоминание о фото
     */
    private function sendPhotoReminder($userId) {
        $message = "📸 <b>Ожидаю фото вашего Defender</b>\n\n" .
                  "Пожалуйста, отправьте фото вашего автомобиля для завершения заявки.\n\n" .
                  "<i>Если у вас возникли проблемы с отправкой фото, обратитесь к администраторам.</i>";
        
        return $this->sendMessageToUser($userId, $message);
    }
    
    /**
     * Напоминание о тексте
     */
    private function sendTextReminder($userId) {
        $message = "💬 <b>Ожидаю текстовое подтверждение</b>\n\n" .
                  "Пожалуйста, отправьте текстовое сообщение с подтверждением:\n" .
                  "1. Вы русскоязычный\n" .
                  "2. Владеете классическим LR Defender";
        
        return $this->sendMessageToUser($userId, $message);
    }
    
    /**
     * Неверный ответ
     */
    private function sendInvalidResponseMessage($userId) {
        $message = "❌ <b>Не понимаю ваш ответ</b>\n\n" .
                  "Для подтверждения заявки нужно:\n" .
                  "1️⃣ <b>Текстовое сообщение</b> с подтверждением (вы русскоязычный и владеете Defender)\n" .
                  "2️⃣ <b>Фото</b> вашего автомобиля\n\n" .
                  "Вы можете отправить их в любом порядке.\n\n" .
                  "Напишите /start чтобы увидеть полную инструкцию.";
        
        return $this->sendMessageToUser($userId, $message);
    }
    
    /**
     * Заявка уже завершена
     */
    private function sendAlreadyCompletedMessage($userId) {
        $message = "✅ <b>Ваша заявка уже отправлена!</b>\n\n" .
                  "Вы уже предоставили всю необходимую информацию.\n\n" .
                  "Ваша заявка передана администраторам и находится на рассмотрении.\n" .
                  "Обычно это занимает до 24 часов.\n\n" .
                  "<i>Это автоматическое сообщение, пожалуйста, не отвечайте на него.</i>";
        
        return $this->sendMessageToUser($userId, $message);
    }
    
    /**
     * Статус заявки
     */
    private function sendRequestStatusMessage($userId, $status) {
        $messages = [
            'answered' => "✅ Ваша заявка получена и находится на рассмотрении администраторов.",
            'approved' => "🎉 Ваша заявка одобрена! Добро пожаловать в клуб!",
            'rejected' => "❌ Ваша заявка отклонена администраторами.",
            'timeout' => "⏰ Время на ответ истекло. Заявка автоматически отклонена."
        ];
        
        $message = $messages[$status] ?? "ℹ️ Статус вашей заявки неизвестен.";
        
        return $this->sendMessageToUser($userId, $message);
    }
    
    /**
     * Сообщение при отсутствии активной заявки
     */
    private function sendNoActiveRequestMessage($userId) {
        $message = "ℹ️ <b>У вас нет активной заявки на вступление</b>\n\n" .
                  "Чтобы подать заявку, пожалуйста:\n" .
                  "1. Перейдите в канал @" . str_replace('@', '', PUBLIC_CHANNEL) . "\n" .
                  "2. Нажмите кнопку \"Вступить\" или \"Join\"\n" .
                  "3. Дождитесь приветственного сообщения от этого бота\n\n" .
                  "Если вы уже подали заявку, но не получили сообщение, напишите /start";
        
        return $this->sendMessageToUser($userId, $message);
    }
    
    /**
     * Подтверждение получения ответа пользователю
     */
    private function sendConfirmationToUser($userId) {
        $message = "✅ <b>Спасибо за ответ!</b>\n\n" .
                  "Ваше сообщение и фото (если вы его приложили) были переданы администраторам клуба.\n\n" .
                  "Ожидайте рассмотрения вашей заявки. Обычно это занимает до 24 часов.\n\n" .
                  "<i>Это автоматическое сообщение, пожалуйста, не отвечайте на него.</i>";
        
        return $this->sendMessageToUser($userId, $message);
    }
    
    /**
     * Универсальный метод отправки сообщения пользователю
     */
    private function sendMessageToUser($userId, $text) {
        try {
            $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendMessage";
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => [
                    'chat_id' => $userId,
                    'text' => $text,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true
                ],
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            if (curl_errno($ch)) {
                error_log("CURL error for user {$userId}: " . curl_error($ch));
            }
            
            curl_close($ch);
            
            if ($httpCode === 200) {
                $result = json_decode($response, true);
                $success = $result['ok'] ?? false;
                
                if ($success) {
                    error_log("Message sent to user {$userId}, message_id: " . ($result['result']['message_id'] ?? 'unknown'));
                } else {
                    error_log("Failed to send message to user {$userId}, response: " . $response);
                }
                
                return $success;
            }
            
            error_log("Failed to send message to user {$userId}. HTTP Code: {$httpCode}, Response: " . $response);
            return false;
            
        } catch (Exception $e) {
            error_log("Error sending message to user {$userId}: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Проверка просроченных заявок (для крона)
     */
    public function checkExpiredRequests() {
        try {
            $expired = $this->db->select("
                SELECT id, user_id, username, first_name 
                FROM join_requests 
                WHERE status = 'pending' 
                AND expires_at <= NOW()
                AND dialog_step != 'completed'
            ");
            
            error_log("Found " . count($expired) . " expired requests");
            
            foreach ($expired as $request) {
                // Обновляем статус
                $this->db->query(
                    "UPDATE join_requests SET status = 'timeout' WHERE id = ?",
                    $request['id']
                );
                
                // Отклоняем заявку в канале
                $this->declineJoinRequest($request['user_id']);
                
                // Уведомляем админ-форум
                AdminChannelHandler::notifyExpiredRequestInForum(
                    $request['id'],
                    $request['user_id'],
                    $request['username'],
                    $request['first_name']
                );
                
                error_log("Request #{$request['id']} expired for user {$request['user_id']}");
            }
            
            return count($expired);
            
        } catch (Exception $e) {
            error_log("Error in checkExpiredRequests: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Отклонение заявки в канале
     */
    private function declineJoinRequest($userId) {
        try {
            error_log("Declining join request for user {$userId}");
            
            $result = $this->callTelegramApi('declineChatJoinRequest', [
                'chat_id' => PUBLIC_CHANNEL,
                'user_id' => $userId
            ]);
            
            if (isset($result['ok']) && $result['ok']) {
                error_log("Successfully declined join request for user {$userId}");
            } else {
                error_log("Failed to decline join request for user {$userId}: " . json_encode($result));
            }
            
        } catch (Exception $e) {
            error_log("Error declining request for user {$userId}: " . $e->getMessage());
        }
    }
    
    /**
     * Универсальный метод вызова API
     */
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