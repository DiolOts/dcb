<?php
// stats.php - статистика бота (защищенный доступ)
$adminToken = 'YOUR_SECURE_TOKEN'; // Замените на реальный токен

if (!isset($_GET['token']) || $_GET['token'] !== $adminToken) {
    http_response_code(403);
    die('Access denied');
}

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Статистика бота Defender Club</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 10px; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin: 20px 0; }
        .stat-card { background: #fff; border: 1px solid #ddd; padding: 20px; border-radius: 8px; text-align: center; }
        .stat-card h3 { margin: 0 0 10px 0; color: #333; }
        .stat-value { font-size: 2em; font-weight: bold; margin: 10px 0; }
        .pending { color: #f39c12; }
        .answered { color: #3498db; }
        .approved { color: #27ae60; }
        .rejected { color: #e74c3c; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        th { background: #f8f9fa; }
        .last-requests { max-height: 400px; overflow-y: auto; }
    </style>
</head>
<body>
    <div class="container">
        <h1>📊 Статистика бота Defender Club Russia</h1>
        <p>Обновлено: <?= date('d.m.Y H:i:s') ?></p>
        
        <div class="stats-grid">
            <?php
            $stats = [
                'pending' => $db->selectOne("SELECT COUNT(*) as count FROM join_requests WHERE status = 'pending'")['count'] ?? 0,
                'answered' => $db->selectOne("SELECT COUNT(*) as count FROM join_requests WHERE status = 'answered'")['count'] ?? 0,
                'approved' => $db->selectOne("SELECT COUNT(*) as count FROM join_requests WHERE status = 'approved'")['count'] ?? 0,
                'rejected' => $db->selectOne("SELECT COUNT(*) as count FROM join_requests WHERE status = 'rejected'")['count'] ?? 0,
                'timeout' => $db->selectOne("SELECT COUNT(*) as count FROM join_requests WHERE status = 'timeout'")['count'] ?? 0,
                'total' => $db->selectOne("SELECT COUNT(*) as count FROM join_requests")['count'] ?? 0
            ];
            
            foreach (['pending', 'answered', 'approved', 'rejected', 'timeout', 'total'] as $type) {
                $class = $type;
                $title = [
                    'pending' => 'Ожидают ответа',
                    'answered' => 'Ответили',
                    'approved' => 'Приняты',
                    'rejected' => 'Отклонены',
                    'timeout' => 'Просрочены',
                    'total' => 'Всего заявок'
                ][$type];
                ?>
                <div class="stat-card">
                    <h3><?= $title ?></h3>
                    <div class="stat-value <?= $class ?>"><?= $stats[$type] ?></div>
                </div>
                <?php
            }
            ?>
        </div>
        
        <h2>Последние заявки (24 часа)</h2>
        <div class="last-requests">
            <?php
            $recent = $db->select("
                SELECT id, user_id, username, first_name, status, request_date, answer_date 
                FROM join_requests 
                WHERE request_date >= DATE_SUB(NOW(), INTERVAL 1 DAY)
                ORDER BY request_date DESC 
                LIMIT 50
            ");
            
            if ($recent) {
                ?>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Пользователь</th>
                            <th>Имя</th>
                            <th>Статус</th>
                            <th>Заявка</th>
                            <th>Ответ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent as $row): ?>
                        <tr>
                            <td>#<?= $row['id'] ?></td>
                            <td><?= $row['username'] ? '@' . $row['username'] : 'ID:' . $row['user_id'] ?></td>
                            <td><?= htmlspecialchars($row['first_name']) ?></td>
                            <td>
                                <?php 
                                $statusIcons = [
                                    'pending' => '⏳',
                                    'answered' => '📨',
                                    'approved' => '✅',
                                    'rejected' => '❌',
                                    'timeout' => '⏰'
                                ];
                                echo ($statusIcons[$row['status']] ?? '❓') . ' ' . $row['status'];
                                ?>
                            </td>
                            <td><?= date('H:i', strtotime($row['request_date'])) ?></td>
                            <td><?= $row['answer_date'] ? date('H:i', strtotime($row['answer_date'])) : '—' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php
            } else {
                echo '<p>Нет заявок за последние 24 часа</p>';
            }
            ?>
        </div>
        
        <h2>Системная информация</h2>
        <table>
            <tr><th>Параметр</th><th>Значение</th></tr>
            <tr><td>Версия PHP</td><td><?= PHP_VERSION ?></td></tr>
            <tr><td>Версия бота</td><td>2.0 (Этап 2)</td></tr>
            <tr><td>Канал</td><td><?= PUBLIC_CHANNEL ?></td></tr>
            <tr><td>Админ-канал</td><td><?= ADMIN_CHANNEL_ID ?></td></tr>
            <tr><td>Время сервера</td><td><?= date('d.m.Y H:i:s') ?></td></tr>
            <tr><td>Логи ошибок</td><td><?= file_exists('logs/errors.log') ? filesize('logs/errors.log') . ' байт' : 'нет' ?></td></tr>
        </table>
    </div>
</body>
</html>