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
$contentId = (int)($input['content_id'] ?? 0);
$contentType = $input['content_type'] ?? 'post';
$reaction = $input['reaction'] ?? '';

if (!$contentId || !$reaction) {
    echo json_encode(['error' => 'Invalid data']);
    exit;
}

// Определяем таблицы
if ($contentType === 'channel') {
    $reactionTable = 'channel_reactions';
    $idColumn = 'message_id';
    $contentTable = 'channel_messages';
    $contentIdColumn = 'id';
} else {
    $reactionTable = 'content_reactions';
    $idColumn = 'content_id';
    $contentTable = 'content_posts';
    $contentIdColumn = 'id';
}

try {
    // Проверяем, есть ли уже реакция
    $stmt = $pdo->prepare("SELECT id, reaction FROM $reactionTable WHERE $idColumn = ? AND user_id = ?");
    $stmt->execute([$contentId, $userId]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        if ($existing['reaction'] === $reaction) {
            // Та же реакция - удаляем
            $stmt = $pdo->prepare("DELETE FROM $reactionTable WHERE $idColumn = ? AND user_id = ?");
            $stmt->execute([$contentId, $userId]);
            $action = 'removed';
        } else {
            // Другая реакция - обновляем
            $stmt = $pdo->prepare("UPDATE $reactionTable SET reaction = ?, created_at = NOW() WHERE $idColumn = ? AND user_id = ?");
            $stmt->execute([$reaction, $contentId, $userId]);
            $action = 'updated';
        }
    } else {
        // Новая реакция
        $stmt = $pdo->prepare("INSERT INTO $reactionTable ($idColumn, user_id, reaction, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$contentId, $userId, $reaction]);
        $action = 'added';
    }
    
    // Получаем все реакции для этого контента
    $stmt = $pdo->prepare("SELECT reaction, COUNT(*) as count FROM $reactionTable WHERE $idColumn = ? GROUP BY reaction");
    $stmt->execute([$contentId]);
    $reactions = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // Обновляем общее количество реакций в контенте
    $totalReactions = array_sum($reactions);
    $stmt = $pdo->prepare("UPDATE $contentTable SET reactions_count = ? WHERE $contentIdColumn = ?");
    $stmt->execute([$totalReactions, $contentId]);
    
    echo json_encode([
        'success' => true,
        'action' => $action,
        'reactions' => $reactions,
        'total' => $totalReactions
    ]);
} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>