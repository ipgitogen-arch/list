<?php
session_start();
require __DIR__ . '/../inc/dd_bb.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Не авторизован']);
    exit;
}

$userId = $_SESSION['user_id'];

try {
    // Получаем список пользователей для личных чатов
    $stmt = $pdo->prepare("
        SELECT DISTINCT 
            u.id, u.name, u.uid,
            COALESCE(p.avatar, '') as avatar
        FROM users u
        LEFT JOIN profiles p ON p.user_id = u.id
        WHERE u.id IN (
            SELECT DISTINCT receiver_id FROM messages WHERE sender_id = ?
            UNION
            SELECT DISTINCT sender_id FROM messages WHERE receiver_id = ?
        ) AND u.id != ?
        ORDER BY u.name ASC
        LIMIT 50
    ");
    $stmt->execute([$userId, $userId, $userId]);
    $personalChats = $stmt->fetchAll();
    
    // Добавляем каналы, где пользователь является админом (для публикации от имени канала)
    $stmt = $pdo->prepare("
        SELECT 
            c.id, 
            c.name, 
            CONCAT('channel_', c.id) as uid,
            COALESCE(c.avatar, '') as avatar,
            'channel' as type
        FROM channels c
        JOIN channel_subscribers cs ON cs.channel_id = c.id
        WHERE cs.user_id = ? AND cs.role IN ('owner', 'admin')
        ORDER BY c.name ASC
        LIMIT 10
    ");
    $stmt->execute([$userId]);
    $channelChats = $stmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'personal' => $personalChats,
        'channels' => $channelChats
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}