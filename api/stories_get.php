<?php
session_start();
require __DIR__ . '/../inc/dd_bb.php';

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Удаляем просроченные
$pdo->prepare("DELETE FROM stories WHERE expires_at < NOW()")->execute();

// Получаем настройки приватности пользователя
$stmt = $pdo->prepare("SELECT story_privacy FROM privacy_settings WHERE user_id = ?");
$stmt->execute([$userId]);
$privacy = $stmt->fetch();
$storyPrivacy = $privacy['story_privacy'] ?? 'all';

// Свои истории
$stmt = $pdo->prepare("SELECT id, media_type, media_path, created_at FROM stories WHERE user_id = ? AND expires_at > NOW() ORDER BY created_at ASC");
$stmt->execute([$userId]);
$myStories = $stmt->fetchAll();

// Получаем истории друзей с учетом приватности
if ($storyPrivacy === 'all') {
    // Видны всем
    $stmt = $pdo->prepare("
        SELECT DISTINCT s.user_id, u.name, p.avatar
        FROM stories s
        JOIN users u ON u.id = s.user_id
        LEFT JOIN profiles p ON p.user_id = u.id
        WHERE s.user_id != ? AND s.expires_at > NOW()
        GROUP BY s.user_id
    ");
    $stmt->execute([$userId]);
} else {
    // Видны только тем, с кем есть чаты (друзья)
    $stmt = $pdo->prepare("
        SELECT DISTINCT s.user_id, u.name, p.avatar
        FROM stories s
        JOIN users u ON u.id = s.user_id
        LEFT JOIN profiles p ON p.user_id = u.id
        WHERE s.user_id != ? AND s.expires_at > NOW()
        AND (
            s.user_id IN (SELECT friend_id FROM friends WHERE user_id = ? AND status = 'accepted')
            OR s.user_id IN (SELECT user_id FROM friends WHERE friend_id = ? AND status = 'accepted')
        )
        GROUP BY s.user_id
    ");
    $stmt->execute([$userId, $userId, $userId]);
}
$friendsStories = $stmt->fetchAll();

foreach ($friendsStories as &$story) {
    $stmt2 = $pdo->prepare("
        SELECT id, media_type, media_path, created_at,
            (SELECT COUNT(*) FROM story_views WHERE story_id = s.id AND user_id = ?) as is_viewed
        FROM stories s
        WHERE user_id = ? AND expires_at > NOW()
        ORDER BY created_at ASC
    ");
    $stmt2->execute([$userId, $story['user_id']]);
    $story['stories_list'] = $stmt2->fetchAll();
}

header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'my_stories' => $myStories,
    'friends_stories' => $friendsStories
]);
?>