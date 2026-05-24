<?php
session_start();
require __DIR__ . '/../inc/dd_bb.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $contentId = (int)$_GET['content_id'] ?? 0;
    $contentType = $_GET['content_type'] ?? '';
    $offset = (int)$_GET['offset'] ?? 0;
    $limit = (int)$_GET['limit'] ?? 10;
    
    if (!$contentId || !$contentType) {
        echo json_encode(['success' => false, 'error' => 'Missing parameters']);
        exit;
    }
    
    $stmt = $pdo->prepare("
        SELECT c.*, u.name, u.uid, COALESCE(p.avatar, '') as avatar,
               (SELECT COUNT(*) FROM likes WHERE content_type = 'comment' AND content_id = c.id AND user_id = ?) as user_liked
        FROM comments c
        JOIN users u ON u.id = c.user_id
        LEFT JOIN profiles p ON p.user_id = u.id
        WHERE c.content_type = ? AND c.content_id = ?
        ORDER BY c.created_at ASC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$userId, $contentType, $contentId, $limit, $offset]);
    $comments = $stmt->fetchAll();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM comments WHERE content_type = ? AND content_id = ?");
    $stmt->execute([$contentType, $contentId]);
    $total = $stmt->fetchColumn();
    
    echo json_encode([
        'success' => true,
        'comments' => $comments,
        'has_more' => ($offset + $limit) < $total
    ]);
    exit;
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $contentId = (int)$data['content_id'] ?? 0;
    $contentType = $data['content_type'] ?? '';
    $text = trim($data['text'] ?? '');
    
    if (!$contentId || !$contentType || !$text) {
        echo json_encode(['success' => false, 'error' => 'Missing parameters']);
        exit;
    }
    
    $stmt = $pdo->prepare("INSERT INTO comments (user_id, content_type, content_id, text, created_at) VALUES (?, ?, ?, ?, NOW())");
    $stmt->execute([$userId, $contentType, $contentId, $text]);
    $commentId = $pdo->lastInsertId();
    
    // Обновляем счетчик комментариев в основной таблице
    if ($contentType === 'moment') {
        $pdo->prepare("UPDATE moments SET comments_count = comments_count + 1 WHERE id = ?")->execute([$contentId]);
    } elseif ($contentType === 'post') {
        $pdo->prepare("UPDATE content_posts SET comments_count = comments_count + 1 WHERE id = ?")->execute([$contentId]);
    } elseif ($contentType === 'channel_message') {
        $pdo->prepare("UPDATE channel_messages SET comments_count = comments_count + 1 WHERE id = ?")->execute([$contentId]);
    }
    
    echo json_encode(['success' => true, 'comment_id' => $commentId]);
    exit;
}

if ($method === 'DELETE') {
    $data = json_decode(file_get_contents('php://input'), true);
    $commentId = (int)$data['comment_id'] ?? 0;
    
    // Проверяем права на удаление
    $stmt = $pdo->prepare("SELECT user_id, content_type, content_id FROM comments WHERE id = ?");
    $stmt->execute([$commentId]);
    $comment = $stmt->fetch();
    
    if (!$comment) {
        echo json_encode(['success' => false, 'error' => 'Comment not found']);
        exit;
    }
    
    $isAdmin = ($_SESSION['role'] ?? '') === 'admin';
    $isOwner = $comment['user_id'] == $userId;
    
    if (!$isOwner && !$isAdmin) {
        echo json_encode(['success' => false, 'error' => 'No permission']);
        exit;
    }
    
    $pdo->prepare("DELETE FROM comments WHERE id = ?")->execute([$commentId]);
    
    // Обновляем счетчик комментариев
    if ($comment['content_type'] === 'moment') {
        $pdo->prepare("UPDATE moments SET comments_count = comments_count - 1 WHERE id = ?")->execute([$comment['content_id']]);
    } elseif ($comment['content_type'] === 'post') {
        $pdo->prepare("UPDATE content_posts SET comments_count = comments_count - 1 WHERE id = ?")->execute([$comment['content_id']]);
    } elseif ($comment['content_type'] === 'channel_message') {
        $pdo->prepare("UPDATE channel_messages SET comments_count = comments_count - 1 WHERE id = ?")->execute([$comment['content_id']]);
    }
    
    echo json_encode(['success' => true]);
    exit;
}