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
$storyId = (int)($input['story_id'] ?? 0);

if (!$storyId) {
    echo json_encode(['error' => 'Invalid story ID']);
    exit;
}

// Проверяем, не смотрел ли уже
$stmt = $pdo->prepare("SELECT id FROM story_views WHERE story_id = ? AND user_id = ?");
$stmt->execute([$storyId, $userId]);
if (!$stmt->fetch()) {
    // Добавляем просмотр
    $stmt = $pdo->prepare("INSERT INTO story_views (story_id, user_id) VALUES (?, ?)");
    $stmt->execute([$storyId, $userId]);
    
    // Обновляем счетчик просмотров
    $stmt = $pdo->prepare("UPDATE stories SET views = views + 1 WHERE id = ?");
    $stmt->execute([$storyId]);
}

echo json_encode(['success' => true]);
?>