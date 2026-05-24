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

// Определяем таблицу
if ($contentType === 'story') {
    $table = 'story_reactions';
    $idColumn = 'story_id';
} elseif ($contentType === 'channel') {
    $table = 'message_reactions';
    $idColumn = 'message_id';
    $typeValue = 'channel';
} else {
    $table = 'content_reactions';
    $idColumn = 'content_id';
}

try {
    // Проверяем, есть ли уже реакция
    if ($contentType === 'channel') {
        $stmt = $pdo->prepare("SELECT id FROM $table WHERE $idColumn = ? AND user_id = ? AND type = 'channel'");
        $stmt->execute([$contentId, $userId]);
    } else {
        $stmt = $pdo->prepare("SELECT id FROM $table WHERE $idColumn = ? AND user_id = ?");
        $stmt->execute([$contentId, $userId]);
    }
    
    if ($stmt->fetch()) {
        // Обновляем реакцию
        if ($contentType === 'channel') {
            $stmt = $pdo->prepare("UPDATE $table SET reaction = ? WHERE $idColumn = ? AND user_id = ? AND type = 'channel'");
            $stmt->execute([$reaction, $contentId, $userId]);
        } else {
            $stmt = $pdo->prepare("UPDATE $table SET reaction = ? WHERE $idColumn = ? AND user_id = ?");
            $stmt->execute([$reaction, $contentId, $userId]);
        }
    } else {
        // Добавляем новую реакцию
        if ($contentType === 'channel') {
            $stmt = $pdo->prepare("INSERT INTO $table ($idColumn, user_id, reaction, type, created_at) VALUES (?, ?, ?, 'channel', NOW())");
            $stmt->execute([$contentId, $userId, $reaction]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO $table ($idColumn, user_id, reaction, created_at) VALUES (?, ?, ?, NOW())");
            $stmt->execute([$contentId, $userId, $reaction]);
        }
    }
    
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>