<?php
session_start();
require __DIR__ . '/../inc/dd_bb.php';

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Получаем ID друзей
$stmt = $pdo->prepare("
    SELECT friend_id FROM friends WHERE user_id = ?
    UNION
    SELECT user_id FROM friends WHERE friend_id = ?
");
$stmt->execute([$userId, $userId]);
$friendIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

$userIds = array_merge($friendIds, [$userId]);
$userIdsStr = !empty($userIds) ? implode(',', $userIds) : '0';

$stmt = $pdo->prepare("SELECT channel_id FROM channel_subscribers WHERE user_id = ?");
$stmt->execute([$userId]);
$subscribedChannels = $stmt->fetchAll(PDO::FETCH_COLUMN);
$channelsStr = !empty($subscribedChannels) ? implode(',', $subscribedChannels) : '0';

$counts = ['video' => 0, 'stories' => 0, 'streams' => 0, 'posts' => 0];

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM content_posts WHERE is_public = 1 AND user_id IN ($userIdsStr) AND (media_type = 'video' OR media_path LIKE '%.mp4%')");
    $stmt->execute();
    $counts['video'] = (int)$stmt->fetchColumn();
    
    if ($channelsStr != '0') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM channel_messages WHERE channel_id IN ($channelsStr) AND (file_path LIKE '%.mp4%' OR file_path LIKE '%.webm%')");
        $stmt->execute();
        $counts['video'] += (int)$stmt->fetchColumn();
    }
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM stories WHERE expires_at > NOW() AND user_id IN ($userIdsStr)");
    $stmt->execute();
    $counts['stories'] = (int)$stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM streams WHERE status = 'live'");
    $counts['streams'] = (int)$stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM content_posts WHERE is_public = 1 AND user_id IN ($userIdsStr)");
    $stmt->execute();
    $counts['posts'] = (int)$stmt->fetchColumn();
    
    if ($channelsStr != '0') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM channel_messages WHERE channel_id IN ($channelsStr)");
        $stmt->execute();
        $counts['posts'] += (int)$stmt->fetchColumn();
    }
} catch (Exception $e) {}

echo json_encode($counts);
?>