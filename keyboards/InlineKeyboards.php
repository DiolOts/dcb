<?php
require_once __DIR__ . '/../config/config.php';

class InlineKeyboards {
    
    /**
     * Клавиатура для модерации заявки
     */
    public static function getRequestKeyboard($requestId, $userId) {
        return json_encode([
            'inline_keyboard' => [
                [
                    [
                        'text' => '✅ ПРИНЯТЬ',
                        'callback_data' => 'approve_' . $requestId . '_' . $userId
                    ],
                    [
                        'text' => '❌ ОТКЛОНИТЬ',
                        'callback_data' => 'reject_' . $requestId . '_' . $userId
                    ]
                ],
                [
                    [
                        'text' => '👁 ПРОСМОТРЕТЬ ПРОФИЛЬ',
                        'url' => 'tg://user?id=' . $userId
                    ]
                ],
                [
                    [
                        'text' => '⏰ ПРОСРОЧЕНО',
                        'callback_data' => 'timeout_' . $requestId . '_' . $userId
                    ],
                    [
                        'text' => '📝 КОММЕНТАРИЙ',
                        'callback_data' => 'comment_' . $requestId . '_' . $userId
                    ]
                ]
            ]
        ]);
    }
    
    /**
     * Клавиатура только для просмотра (после принятия решения)
     */
    public static function getViewOnlyKeyboard($requestId, $userId, $status) {
        $statusText = $status == 'approved' ? '✅ ПРИНЯТО' : '❌ ОТКЛОНЕНО';
        
        return json_encode([
            'inline_keyboard' => [
                [
                    [
                        'text' => $statusText,
                        'callback_data' => 'already_processed'
                    ]
                ],
                [
                    [
                        'text' => '👁 ПРОФИЛЬ',
                        'url' => 'tg://user?id=' . $userId
                    ]
                ]
            ]
        ]);
    }
    
    /**
     * Клавиатура для подтверждения действия
     */
    public static function getConfirmationKeyboard($requestId, $userId, $action) {
        return json_encode([
            'inline_keyboard' => [
                [
                    [
                        'text' => 'ДА, ' . ($action == 'approve' ? 'ПРИНЯТЬ' : 'ОТКЛОНИТЬ'),
                        'callback_data' => 'confirm_' . $action . '_' . $requestId . '_' . $userId
                    ],
                    [
                        'text' => '❌ ОТМЕНА',
                        'callback_data' => 'cancel_' . $requestId . '_' . $userId
                    ]
                ]
            ]
        ]);
    }
}
?>