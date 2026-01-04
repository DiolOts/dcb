<?php
require_once __DIR__ . '/../libs/DbSimple/Generic.php';

try {
    $dsn = "mysqli://" . DB_USER . ":" . DB_PASS . "@" . DB_HOST . "/" . DB_NAME;
    $db = DbSimple_Generic::connect($dsn);
    $db->setErrorHandler('databaseErrorHandler');
    $db->query("SET NAMES utf8mb4");
    
   // initDatabase($db);
    
} catch (Exception $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("Database connection error.");
}

function databaseErrorHandler($message, $info) {
    if (DEBUG_MODE) {
        error_log("DB Error: $message - " . print_r($info, true));
    }
    return true;
}

function initDatabase($db) {
    // Таблица заявок на вступление (упрощенная для Этапа 1)
    $db->query("CREATE TABLE IF NOT EXISTS join_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT NOT NULL,
        username VARCHAR(255) DEFAULT NULL,
        first_name VARCHAR(255) DEFAULT NULL,
        last_name VARCHAR(255) DEFAULT NULL,
        status ENUM('pending', 'answered', 'approved', 'rejected', 'timeout') DEFAULT 'pending',
        processed_by BIGINT DEFAULT NULL, 
        processed_at TIMESTAMP NULL,
        request_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        welcome_sent BOOLEAN DEFAULT FALSE,
		welcome_sent_at TIMESTAMP NULL,
        expires_at TIMESTAMP NULL,
        answer_text TEXT DEFAULT NULL,
        answer_photo_id VARCHAR(255) DEFAULT NULL,
        answer_date TIMESTAMP NULL,
        admin_message_id INT DEFAULT NULL,
        INDEX idx_user_status (user_id, status),
        INDEX idx_expires (expires_at),
        INDEX idx_request_date (request_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Таблица для комментариев администраторов
    $db->query("CREATE TABLE IF NOT EXISTS admin_comments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        request_id INT NOT NULL,
        admin_id BIGINT NOT NULL,
        chat_id BIGINT NOT NULL,
        message_id INT NOT NULL,
        comment_text TEXT DEFAULT NULL,
        status ENUM('pending', 'completed') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (request_id) REFERENCES join_requests(id) ON DELETE CASCADE,
        INDEX idx_request_id (request_id),
        INDEX idx_admin_id (admin_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
      
   // error_log("Database initialized successfully");
}

global $db;
?>

