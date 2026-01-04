<?php
// Заголовок для логов
echo "=== Defender Bot - Expired Requests Check ===\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n";
echo "----------------------------------------\n";

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../handlers/JoinRequestHandler.php';

try {
    $handler = new JoinRequestHandler($db);
    $expiredCount = $handler->checkExpiredRequests();
    
    echo "✅ Check completed successfully\n";
    echo "📊 Expired requests found: " . $expiredCount . "\n";
    echo "----------------------------------------\n";
    
    // Логируем результат
    file_put_contents('cron_log.txt', 
        date('Y-m-d H:i:s') . " - Checked expired requests: {$expiredCount}\n", 
        FILE_APPEND
    );
    
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    error_log("Cron error: " . $e->getMessage());
}
?>