<?php
// bot_core.php
// ============================================
// БОТ ДЛЯ ЗАКРЫТОГО КАНАЛА LandRover Defender
// Версия: 2.0 (Этап 2 - с инлайн-кнопками)
// ============================================

// Включаем вывод ошибок для отладки (отключить на продакшене)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Старт времени выполнения (для отладки производительности)
$startTime = microtime(true);

// Логирование входящих запросов (отладка)
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

$inputLog = $logDir . '/webhook_input.log';
$updateLog = $logDir . '/updates.log';

// Логируем входящий запрос
$inputData = file_get_contents('php://input');
$logEntry = sprintf(
    "[%s] %s %s\nInput: %s\nHeaders: %s\n---\n",
    date('Y-m-d H:i:s'),
    $_SERVER['REQUEST_METHOD'],
    $_SERVER['REQUEST_URI'] ?? '/',
    $inputData,
    json_encode(getallheaders(), JSON_UNESCAPED_UNICODE)
);

file_put_contents($inputLog, $logEntry, FILE_APPEND);

// ============================================
// ПОДКЛЮЧЕНИЕ КОНФИГУРАЦИИ И БИБЛИОТЕК
// ============================================

// Подключаем конфигурацию
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

// Подключаем обработчики
require_once __DIR__ . '/handlers/JoinRequestHandler.php';
require_once __DIR__ . '/handlers/AdminChannelHandler.php';
require_once __DIR__ . '/handlers/CallbackHandler.php';

// Подключаем клавиатуры
require_once __DIR__ . '/keyboards/InlineKeyboards.php';

// ============================================
// ОСНОВНАЯ ЛОГИКА ОБРАБОТКИ
// ============================================

try {
    // Получаем и декодируем входящее обновление от Telegram
    $update = json_decode($inputData, true);
    
    // ============================================
    // ОБРАБОТКА ПРОВЕРОЧНЫХ GET-ЗАПРОСОВ
    // ============================================
    
    // Если это GET-запрос для проверки работы (от браузера или мониторинга)
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && empty($inputData)) {
        header('Content-Type: application/json; charset=utf-8');
        
        // Проверяем подключение к базе данных
        $dbStatus = 'connected';
        try {
            $test = $db->selectOne("SELECT 1 as test");
            $dbTest = $test ? 'OK' : 'FAILED';
        } catch (Exception $e) {
            $dbStatus = 'error: ' . $e->getMessage();
            $dbTest = 'FAILED';
        }
        
        // Проверяем существование необходимых таблиц
        $tablesCheck = [];
        $requiredTables = ['join_requests', 'bot_admins'];
        foreach ($requiredTables as $table) {
            $exists = $db->selectOne("SHOW TABLES LIKE ?", $table);
            $tablesCheck[$table] = $exists ? 'EXISTS' : 'MISSING';
        }
        
        // Получаем статистику
        $stats = [];
        try {
            $pending = $db->selectOne("SELECT COUNT(*) as count FROM join_requests WHERE status = 'pending'");
            $answered = $db->selectOne("SELECT COUNT(*) as count FROM join_requests WHERE status = 'answered'");
            $approved = $db->selectOne("SELECT COUNT(*) as count FROM join_requests WHERE status = 'approved'");
            $rejected = $db->selectOne("SELECT COUNT(*) as count FROM join_requests WHERE status = 'rejected'");
            
            $stats = [
                'pending' => $pending['count'] ?? 0,
                'answered' => $answered['count'] ?? 0,
                'approved' => $approved['count'] ?? 0,
                'rejected' => $rejected['count'] ?? 0,
                'total' => ($pending['count'] ?? 0) + ($answered['count'] ?? 0) + ($approved['count'] ?? 0) + ($rejected['count'] ?? 0)
            ];
        } catch (Exception $e) {
            $stats = ['error' => $e->getMessage()];
        }
        
        $response = [
            'status' => 'online',
            'bot' => 'Defender Club Russia Bot',
            'stage' => '2',
            'version' => '2.0',
            'timestamp' => date('Y-m-d H:i:s'),
            'timezone' => TIMEZONE,
            'features' => [
                'join_requests' => true,
                'admin_buttons' => true,
                'callback_handling' => true,
                'timeout_processing' => true
            ],
            'channels' => [
                'public' => PUBLIC_CHANNEL,
                'admin' => ADMIN_CHANNEL_ID
            ],
            'database' => [
                'status' => $dbStatus,
                'test' => $dbTest,
                'tables' => $tablesCheck
            ],
            'statistics' => $stats,
            'performance' => [
                'memory_usage' => round(memory_get_usage() / 1024 / 1024, 2) . ' MB',
                'memory_peak' => round(memory_get_peak_usage() / 1024 / 1024, 2) . ' MB',
                'execution_time' => round((microtime(true) - $startTime) * 1000, 2) . ' ms'
            ],
            'endpoints' => [
                'webhook' => 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $_SERVER['REQUEST_URI'],
                'getWebhookInfo' => 'https://api.telegram.org/bot***/getWebhookInfo',
                'github' => 'https://github.com/dimikot/DbSimple'
            ]
        ];
        
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    
    // ============================================
    // ПРОВЕРКА ВАЛИДНОСТИ ВХОДНЫХ ДАННЫХ
    // ============================================
    
    // Если не удалось декодировать JSON
    if (!$update) {
        // Логируем ошибку
        $errorLog = $logDir . '/errors.log';
        $errorMsg = date('Y-m-d H:i:s') . " - Invalid JSON received:\n" . $inputData . "\n---\n";
        file_put_contents($errorLog, $errorMsg, FILE_APPEND);
        
        // Отвечаем корректно для Telegram
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => false,
            'error' => 'Invalid JSON data',
            'timestamp' => time()
        ]);
        exit;
    }
    
    // Логируем тип обновления (для отладки)
    $updateType = 'unknown';
    if (isset($update['message'])) $updateType = 'message';
    if (isset($update['chat_join_request'])) $updateType = 'chat_join_request';
    if (isset($update['callback_query'])) $updateType = 'callback_query';
    if (isset($update['edited_message'])) $updateType = 'edited_message';
    if (isset($update['channel_post'])) $updateType = 'channel_post';
    
    $updateLogEntry = sprintf(
        "[%s] Type: %-20s | From: %s | Chat: %s\n",
        date('Y-m-d H:i:s'),
        $updateType,
        isset($update['message']['from']['id']) ? $update['message']['from']['id'] : 
            (isset($update['chat_join_request']['from']['id']) ? $update['chat_join_request']['from']['id'] : 
            (isset($update['callback_query']['from']['id']) ? $update['callback_query']['from']['id'] : 'N/A')),
        isset($update['message']['chat']['id']) ? $update['message']['chat']['id'] : 
            (isset($update['chat_join_request']['chat']['id']) ? $update['chat_join_request']['chat']['id'] : 
            (isset($update['callback_query']['message']['chat']['id']) ? $update['callback_query']['message']['chat']['id'] : 'N/A'))
    );
    
    file_put_contents($updateLog, $updateLogEntry, FILE_APPEND);
    
    // ============================================
    // ОБРАБОТКА CALLBACK_QUERY (НОВОЕ ДЛЯ ЭТАПА 2)
    // ============================================
    
    if (isset($update['callback_query'])) {
        error_log("Processing callback_query from admin: " . $update['callback_query']['from']['id']);
        
        // Создаем обработчик callback-запросов
        $callbackHandler = new CallbackHandler($db);
        
        // Обрабатываем callback
        $processed = $callbackHandler->handleCallback($update['callback_query']);
        
        // Логируем результат обработки
        $callbackResult = $processed ? 'SUCCESS' : 'FAILED';
        error_log("Callback processing result: {$callbackResult}");
        
        // Отвечаем Telegram
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => true,
            'processed' => $processed,
            'type' => 'callback_query',
            'action' => $update['callback_query']['data'] ?? 'unknown',
            'timestamp' => time(),
            'performance' => round((microtime(true) - $startTime) * 1000, 2) . ' ms'
        ]);
        exit;
    }
    
    // ============================================
    // ОБРАБОТКА ЗАЯВОК НА ВСТУПЛЕНИЕ И СООБЩЕНИЙ
    // ============================================
    
    // Создаем основной обработчик
    $handler = new JoinRequestHandler($db);
    
    // Передаем обновление на обработку
    $processed = $handler->handleUpdate($update);
    
    // ============================================
    // ФИНАЛЬНЫЙ ОТВЕТ TELEGRAM
    // ============================================
    
    // Всегда отвечаем 200 OK для Telegram, даже если возникли ошибки
    http_response_code(200);
    header('Content-Type: application/json');
    
    $response = [
        'ok' => true,
        'processed' => $processed,
        'type' => $updateType,
        'timestamp' => time(),
        'bot_version' => '2.0',
        'performance' => [
            'execution_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
            'memory_mb' => round(memory_get_usage() / 1024 / 1024, 2)
        ]
    ];
    
    // Добавляем информацию о заявке, если это была заявка
    if ($updateType == 'chat_join_request') {
        $response['join_request'] = [
            'user_id' => $update['chat_join_request']['from']['id'] ?? null,
            'username' => $update['chat_join_request']['from']['username'] ?? null,
            'first_name' => $update['chat_join_request']['from']['first_name'] ?? null
        ];
    }
    
    // Добавляем информацию о сообщении, если это было сообщение
    if ($updateType == 'message') {
        $response['message'] = [
            'chat_type' => $update['message']['chat']['type'] ?? null,
            'has_text' => isset($update['message']['text']),
            'has_photo' => isset($update['message']['photo'])
        ];
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
    // ============================================
    // ЛОГИРОВАНИЕ УСПЕШНОЙ ОБРАБОТКИ
    // ============================================
    
    $successLog = $logDir . '/success.log';
    $successEntry = sprintf(
        "[%s] %-20s | Processed: %s | Time: %s ms\n",
        date('Y-m-d H:i:s'),
        $updateType,
        $processed ? 'YES' : 'NO',
        round((microtime(true) - $startTime) * 1000, 2)
    );
    
    file_put_contents($successLog, $successEntry, FILE_APPEND);
    
} catch (Exception $e) {
    // ============================================
    // ОБРАБОТКА КРИТИЧЕСКИХ ОШИБОК
    // ============================================
    
    $errorLog = $logDir . '/critical_errors.log';
    $errorMsg = sprintf(
        "[%s] CRITICAL ERROR\nMessage: %s\nFile: %s:%d\nTrace:\n%s\nUpdate: %s\n---\n",
        date('Y-m-d H:i:s'),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString(),
        json_encode($update, JSON_UNESCAPED_UNICODE)
    );
    
    file_put_contents($errorLog, $errorMsg, FILE_APPEND);
    
    // Также логируем в системный лог
    error_log("Critical error in bot_core.php: " . $e->getMessage());
    
    // Все равно отвечаем 200 OK для Telegram, чтобы не было повторных отправок
    http_response_code(200);
    header('Content-Type: application/json');
    
    echo json_encode([
        'ok' => false,
        'error' => 'Internal server error',
        'error_code' => 'CRITICAL',
        'timestamp' => time()
    ]);
    
    // ============================================
    // ОТПРАВКА УВЕДОМЛЕНИЯ АДМИНАМ ОБ ОШИБКЕ
    // ============================================
    
    try {
        // Пытаемся отправить уведомление админам об ошибке
        if (defined('ADMIN_CHANNEL_ID') && ADMIN_CHANNEL_ID && defined('BOT_TOKEN') && BOT_TOKEN) {
            $errorNotification = "🚨 <b>КРИТИЧЕСКАЯ ОШИБКА БОТА</b>\n\n" .
                               "Время: " . date('d.m.Y H:i:s') . "\n" .
                               "Ошибка: " . substr($e->getMessage(), 0, 200) . "\n" .
                               "Файл: " . basename($e->getFile()) . ":" . $e->getLine() . "\n\n" .
                               "<i>Бот продолжает работу, но требует внимания разработчика.</i>";
            
            $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendMessage";
            $params = [
                'chat_id' => ADMIN_CHANNEL_ID,
                'text' => $errorNotification,
                'parse_mode' => 'HTML',
                'disable_notification' => false
            ];
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $params,
                CURLOPT_TIMEOUT => 5
            ]);
            
            curl_exec($ch);
            curl_close($ch);
        }
    } catch (Exception $notificationError) {
        // Игнорируем ошибки при отправке уведомлений
        error_log("Failed to send error notification: " . $notificationError->getMessage());
    }
}

// ============================================
// ЗАВЕРШАЮЩАЯ ОБРАБОТКА
// ============================================

// Очистка (если нужно)
unset($db, $handler, $callbackHandler);

// Финализация
$totalTime = round((microtime(true) - $startTime) * 1000, 2);
if ($totalTime > 1000) {
    error_log("Warning: Slow request processing - {$totalTime} ms");
}

exit;
?>