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
$ids = $input['ids'] ?? [];
$type = $input['type'] ?? 'channel';

if (empty($ids)) {
    echo json_encode(['error' => 'No messages selected']);
    exit;
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));

if ($type === 'channel') {
    // Проверяем, что пользователь имеет право удалять (админ канала)
    $stmt = $pdo->prepare("SELECT channel_id FROM channel_messages WHERE id = ?");
    $stmt->execute([$ids[0]]);
    $channelId = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT role FROM channel_subscribers WHERE channel_id = ? AND user_id = ?");
    $stmt->execute([$channelId, $userId]);
    $role = $stmt->fetchColumn();
    
    if ($role !== 'owner' && $role !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Permission denied']);
        exit;
    }
    
    $stmt = $pdo->prepare("DELETE FROM channel_messages WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    
} elseif ($type === 'group') {
    // Аналогично для группы
    $stmt = $pdo->prepare("SELECT chat_id FROM group_messages WHERE id = ?");
    $stmt->execute([$ids[0]]);
    $chatId = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT role FROM group_chat_members WHERE chat_id = ? AND user_id = ?");
    $stmt->execute([$chatId, $userId]);
    $role = $stmt->fetchColumn();
    
    if ($role !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Permission denied']);
        exit;
    }
    
    $stmt = $pdo->prepare("DELETE FROM group_messages WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    
} else {
    // Личный чат — можно удалять только свои сообщения
    $stmt = $pdo->prepare("DELETE FROM messages WHERE id IN ($placeholders) AND sender_id = ?");
    $ids[] = $userId;
    $stmt->execute($ids);
}

echo json_encode(['success' => true]);