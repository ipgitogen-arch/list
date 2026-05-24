<?php
session_start();
require __DIR__ . '/../inc/dd_bb.php';

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$channelId = (int)($input['channel_id'] ?? 0);

if (!$channelId) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid channel']);
    exit;
}

// Проверяем, существует ли канал
$stmt = $pdo->prepare("SELECT id, name, is_official FROM channels WHERE id = ?");
$stmt->execute([$channelId]);
$channel = $stmt->fetch();

if (!$channel) {
    http_response_code(404);
    echo json_encode(['error' => 'Channel not found']);
    exit;
}

// Проверяем, не подписан ли уже
$stmt = $pdo->prepare("SELECT id FROM channel_subscribers WHERE channel_id = ? AND user_id = ?");
$stmt->execute([$channelId, $userId]);
if ($stmt->fetch()) {
    echo json_encode(['success' => true, 'already' => true]);
    exit;
}

// Подписываем
$stmt = $pdo->prepare("INSERT INTO channel_subscribers (channel_id, user_id, role, joined_at) VALUES (?, ?, 'member', NOW())");
$stmt->execute([$channelId, $userId]);

// Добавляем уведомление о подписке
$title = $channel['is_official'] ? '⭐ Официальный канал' : 'Новый канал';
$stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, link, created_at) VALUES (?, 'channel', ?, ?, ?, NOW())");
$stmt->execute([$userId, $title, 'Вы подписались на канал ' . $channel['name'], '/profile/channel.php?id=' . $channelId]);

echo json_encode(['success' => true]);