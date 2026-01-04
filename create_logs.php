<?php
// create_logs.php - создание структуры логов
$logDir = __DIR__ . '/logs';

if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
    echo "Created logs directory: $logDir\n";
}

$logFiles = [
    'webhook_input.log',
    'updates.log',
    'success.log',
    'errors.log',
    'critical_errors.log',
    'callback_debug.log',
    'admin_actions.log'
];

foreach ($logFiles as $file) {
    $path = $logDir . '/' . $file;
    if (!file_exists($path)) {
        file_put_contents($path, "=== Log file created: " . date('Y-m-d H:i:s') . " ===\n\n");
        echo "Created: $file\n";
    }
}

echo "\nLog structure created successfully.\n";
echo "Make sure the web server has write permissions to the logs directory.\n";

// Проверка прав
if (is_writable($logDir)) {
    echo "✓ Directory is writable\n";
} else {
    echo "✗ Directory is NOT writable. Run: chmod 755 $logDir\n";
}
?>