<?php
session_start();
require __DIR__ . '/../inc/dd_bb.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Не авторизован']);
    exit;
}

$userId = $_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);

$recipients = $data['recipients'] ?? [];
$shareLink = $data['share_link'] ?? '';

if (empty($recipients)) {
    echo json_encode(['success' => false, 'error' => 'Нет получателей']);
    exit;
}

$sentCount = 0;

foreach ($recipients as $recipientId) {
    // Проверяем, является ли получатель каналом
    if (strpos($recipientId, 'channel_') === 0) {
        $channelId = (int)str_replace('channel_', '', $recipientId);
        
        // Проверяем, имеет ли пользователь право писать в этот канал
        $stmt = $pdo->prepare("
            SELECT role FROM channel_subscribers 
            WHERE channel_id = ? AND user_id = ? AND role IN ('owner', 'admin')
        ");
        $stmt->execute([$channelId, $userId]);
        $canPostToChannel = $stmt->fetch();
        
        if ($canPostToChannel) {
            $stmt = $pdo->prepare("
                INSERT INTO channel_messages (channel_id, user_id, content, file_path, file_type, views, likes, created_at) 
                VALUES (?, ?, ?, NULL, NULL, 0, 0, NOW())
            ");
            $stmt->execute([$channelId, $userId, $shareLink]);
            $sentCount++;
        }
    } else {
        // Личное сообщение
        $message = "Поделился ссылкой:\n\n" . $shareLink;
        $stmt = $pdo->prepare("
            INSERT INTO messages (sender_id, receiver_id, content, attachment, created_at) 
            VALUES (?, ?, ?, NULL, NOW())
        ");
        $stmt->execute([$userId, $recipientId, $message]);
        $sentCount++;
    }
}

echo json_encode(['success' => true, 'sent_count' => $sentCount]);