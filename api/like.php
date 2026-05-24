<?php
session_start();
require __DIR__ . '/../inc/dd_bb.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);

$contentId = (int)($data['content_id'] ?? 0);
$type = $data['type'] ?? 'post';

if (!$contentId) {
    echo json_encode(['success' => false, 'error' => 'No content id']);
    exit;
}

try {
    // Определяем таблицу для лайков
    if ($type === 'moment') {
        $table = 'moments';
        $likeTable = 'moment_likes';
        $idField = 'moment_id';
        $likeField = 'likes';
    } elseif ($type === 'channel') {
        $table = 'channel_messages';
        $likeTable = 'channel_message_likes';
        $idField = 'message_id';
        $likeField = 'likes';
    } elseif ($type === 'post') {
        $table = 'content_posts';
        $likeTable = 'content_likes';
        $idField = 'content_id';
        $likeField = 'likes';
    } else {
        echo json_encode(['success' => false, 'error' => 'Unknown content type']);
        exit;
    }
    
    // Проверяем существующий лайк
    $stmt = $pdo->prepare("SELECT id FROM $likeTable WHERE $idField = ? AND user_id = ?");
    $stmt->execute([$contentId, $userId]);
    $hasLiked = $stmt->fetch();
    
    if ($hasLiked) {
        $pdo->prepare("DELETE FROM $likeTable WHERE $idField = ? AND user_id = ?")->execute([$contentId, $userId]);
        $pdo->prepare("UPDATE $table SET $likeField = $likeField - 1 WHERE id = ?")->execute([$contentId]);
        $liked = false;
    } else {
        $pdo->prepare("INSERT INTO $likeTable ($idField, user_id, created_at) VALUES (?, ?, NOW())")->execute([$contentId, $userId]);
        $pdo->prepare("UPDATE $table SET $likeField = $likeField + 1 WHERE id = ?")->execute([$contentId]);
        $liked = true;
    }
    
    $stmt = $pdo->prepare("SELECT $likeField FROM $table WHERE id = ?");
    $stmt->execute([$contentId]);
    $likesCount = (int)$stmt->fetchColumn();
    
    echo json_encode(['success' => true, 'liked' => $liked, 'likes_count' => $likesCount]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}