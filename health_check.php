<?php
// health_check.php - проверка состояния бота
header('Content-Type: application/json');

$checks = [];
$status = 'healthy';

// 1. Проверка PHP
$checks['php'] = [
    'version' => PHP_VERSION,
    'extensions' => [
        'curl' => extension_loaded('curl') ? 'OK' : 'MISSING',
        'json' => extension_loaded('json') ? 'OK' : 'MISSING',
        'pdo_mysql' => extension_loaded('pdo_mysql') ? 'OK' : 'MISSING',
        'mbstring' => extension_loaded('mbstring') ? 'OK' : 'MISSING'
    ]
];

// 2. Проверка файлов
$requiredFiles = [
    'bot_core.php',
    'config/config.php',
    'config/database.php',
    'handlers/JoinRequestHandler.php',
    'handlers/CallbackHandler.php',
    'keyboards/InlineKeyboards.php'
];

foreach ($requiredFiles as $file) {
    $checks['files'][$file] = file_exists(__DIR__ . '/' . $file) ? 'EXISTS' : 'MISSING';
    if ($checks['files'][$file] == 'MISSING') {
        $status = 'degraded';
    }
}

// 3. Проверка директорий
$requiredDirs = ['logs', 'config', 'handlers', 'keyboards'];
foreach ($requiredDirs as $dir) {
    $path = __DIR__ . '/' . $dir;
    $checks['directories'][$dir] = is_dir($path) ? 'EXISTS' : 'MISSING';
    if ($checks['directories'][$dir] == 'EXISTS') {
        $checks['directories'][$dir . '_writable'] = is_writable($path) ? 'WRITABLE' : 'READONLY';
    }
}

// 4. Проверка вебхука
if (isset($_GET['check_webhook'])) {
    $checks['webhook'] = 'PENDING';
    
    $ch = curl_init('https://defender.r23.ru/bot_core.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $checks['webhook'] = [
        'http_code' => $httpCode,
        'response' => json_decode($response, true) ?: $response
    ];
}

// 5. Проверка конфигурации
$configStatus = 'UNKNOWN';
if (file_exists(__DIR__ . '/config/config.php')) {
    @include_once __DIR__ . '/config/config.php';
    $configStatus = 'LOADED';
    
    $checks['config'] = [
        'BOT_TOKEN' => defined('BOT_TOKEN') ? (BOT_TOKEN ? 'SET' : 'EMPTY') : 'UNDEFINED',
        'PUBLIC_CHANNEL' => defined('PUBLIC_CHANNEL') ? (PUBLIC_CHANNEL ? 'SET' : 'EMPTY') : 'UNDEFINED',
        'ADMIN_CHANNEL_ID' => defined('ADMIN_CHANNEL_ID') ? (ADMIN_CHANNEL_ID ? 'SET' : 'EMPTY') : 'UNDEFINED',
        'TIMEZONE' => defined('TIMEZONE') ? TIMEZONE : 'UNDEFINED'
    ];
    
    foreach ($checks['config'] as $key => $value) {
        if ($value == 'UNDEFINED' || $value == 'EMPTY') {
            $status = 'degraded';
        }
    }
} else {
    $configStatus = 'MISSING';
    $status = 'unhealthy';
}

$checks['config_status'] = $configStatus;

// Результат
$result = [
    'status' => $status,
    'timestamp' => date('Y-m-d H:i:s'),
    'system' => [
        'server' => $_SERVER['SERVER_SOFTWARE'] ?? 'UNKNOWN',
        'hostname' => gethostname() ?: 'UNKNOWN',
        'timezone' => date_default_timezone_get()
    ],
    'checks' => $checks,
    'instructions' => [
        'webhook_test' => 'Add ?check_webhook=1 to URL to test webhook connection',
        'get_status' => 'Access bot_core.php directly for detailed bot status',
        'logs' => 'Check logs/ directory for debugging information'
    ]
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>