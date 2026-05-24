<?php
session_start();
require __DIR__ . '/../inc/dd_bb.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['user_id'];
$type = $_GET['type'] ?? 'moments';
$offset = (int)($_GET['offset'] ?? 0);
$limit = 10;

// Получаем ID друзей
$stmt = $pdo->prepare("SELECT friend_id FROM friends WHERE user_id = ? UNION SELECT user_id FROM friends WHERE friend_id = ?");
$stmt->execute([$userId, $userId]);
$friendIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
$userIds = array_merge($friendIds, [$userId]);
$userIdsStr = !empty($userIds) ? implode(',', $userIds) : '0';

// Получаем подписанные каналы
$stmt = $pdo->prepare("SELECT channel_id FROM channel_subscribers WHERE user_id = ?");
$stmt->execute([$userId]);
$subscribedChannels = $stmt->fetchAll(PDO::FETCH_COLUMN);
$subscribedChannelsStr = !empty($subscribedChannels) ? implode(',', $subscribedChannels) : '0';

// Получаем публичные каналы (is_public = 1)
$stmt = $pdo->prepare("SELECT id FROM channels WHERE is_public = 1");
$stmt->execute();
$publicChannels = $stmt->fetchAll(PDO::FETCH_COLUMN);
$publicChannelsStr = !empty($publicChannels) ? implode(',', $publicChannels) : '0';

// Объединяем: показываем контент из подписанных каналов + из публичных каналов
$allChannelsStr = '';
if ($subscribedChannelsStr != '0' && $publicChannelsStr != '0') {
    $allChannelsStr = $subscribedChannelsStr . ',' . $publicChannelsStr;
} elseif ($subscribedChannelsStr != '0') {
    $allChannelsStr = $subscribedChannelsStr;
} elseif ($publicChannelsStr != '0') {
    $allChannelsStr = $publicChannelsStr;
} else {
    $allChannelsStr = '0';
}

$items = [];

if ($type === 'moments') {
    // Моменты от пользователей (друзья и свои)
    $sql = "
        SELECT 
            m.id, m.user_id, m.video_path as media_path, 'video' as media_type,
            COALESCE(m.title, '') as content, COALESCE(m.likes, 0) as likes, COALESCE(m.views, 0) as views, COALESCE(m.comments_count, 0) as comments_count, m.created_at,
            u.name, u.uid, COALESCE(p.avatar, '') as avatar,
            'moment' as source_type, 'user' as source_subtype,
            (SELECT COUNT(*) FROM likes WHERE content_type = 'moment' AND content_id = m.id AND user_id = $userId) as user_liked
        FROM moments m
        JOIN users u ON u.id = m.user_id
        LEFT JOIN profiles p ON p.user_id = u.id
        WHERE m.channel_id IS NULL AND u.id IN ($userIdsStr)
    ";
    
    // Моменты от каналов (подписанные + публичные)
    if ($allChannelsStr != '0') {
        $sql .= "
            UNION ALL
            SELECT 
                m.id, m.channel_id as user_id, m.video_path as media_path, 'video' as media_type,
                COALESCE(m.title, '') as content, COALESCE(m.likes, 0) as likes, COALESCE(m.views, 0) as views, COALESCE(m.comments_count, 0) as comments_count, m.created_at,
                c.name as name, c.id as uid, COALESCE(c.avatar, '') as avatar,
                'moment' as source_type, 'channel' as source_subtype,
                0 as user_liked
            FROM moments m
            JOIN channels c ON c.id = m.channel_id
            WHERE m.channel_id IN ($allChannelsStr)
        ";
    }
    
    $sql .= " ORDER BY created_at DESC LIMIT $limit OFFSET $offset";
    
} else {
    // Публикации от пользователей
    $sql = "
        SELECT 
            cp.id, cp.user_id, cp.media_path, cp.media_type, cp.caption as content,
            COALESCE(cp.likes, 0) as likes, COALESCE(cp.views, 0) as views, COALESCE(cp.comments_count, 0) as comments_count, cp.created_at,
            u.name, u.uid, COALESCE(p.avatar, '') as avatar,
            'post' as source_type, 'user' as source_subtype,
            (SELECT COUNT(*) FROM likes WHERE content_type = 'post' AND content_id = cp.id AND user_id = $userId) as user_liked
        FROM content_posts cp
        JOIN users u ON u.id = cp.user_id
        LEFT JOIN profiles p ON p.user_id = u.id
        WHERE cp.is_public = 1 AND u.id IN ($userIdsStr)
    ";
    
    // Публикации от каналов (подписанные + публичные)
    if ($allChannelsStr != '0') {
        $sql .= "
            UNION ALL
            SELECT 
                cm.id, cm.user_id, cm.file_path as media_path,
                CASE 
                    WHEN cm.file_path LIKE '%.mp4%' OR cm.file_path LIKE '%.webm%' THEN 'video'
                    WHEN cm.file_path LIKE '%.jpg%' OR cm.file_path LIKE '%.png%' OR cm.file_path LIKE '%.jpeg%' OR cm.file_path LIKE '%.gif%' OR cm.file_path LIKE '%.webp%' THEN 'image'
                    ELSE 'file'
                END as media_type,
                cm.content, COALESCE(cm.likes, 0) as likes, COALESCE(cm.views, 0) as views, COALESCE(cm.comments_count, 0) as comments_count, cm.created_at,
                c.name as name, c.id as uid, COALESCE(c.avatar, '') as avatar,
                'post' as source_type, 'channel' as source_subtype,
                0 as user_liked
            FROM channel_messages cm
            JOIN channels c ON c.id = cm.channel_id
            WHERE cm.channel_id IN ($allChannelsStr)
        ";
    }
    
    $sql .= " ORDER BY created_at DESC LIMIT $limit OFFSET $offset";
}

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $items = $stmt->fetchAll();
    $hasMore = count($items) == $limit;
    
    echo json_encode(['success' => true, 'items' => $items, 'has_more' => $hasMore]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}