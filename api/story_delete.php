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

// Проверяем, что история принадлежит пользователю
$stmt = $pdo->prepare("SELECT media_path FROM stories WHERE id = ? AND user_id = ?");
$stmt->execute([$storyId, $userId]);
$story = $stmt->fetch();

if (!$story) {
    echo json_encode(['error' => 'Story not found']);
    exit;
}

// Удаляем файл
$filePath = __DIR__ . '/../' . $story['media_path'];
if (file_exists($filePath)) {
    @unlink($filePath);
}

// Удаляем из БД
$stmt = $pdo->prepare("DELETE FROM stories WHERE id = ? AND user_id = ?");
$stmt->execute([$storyId, $userId]);

// Удаляем просмотры
$stmt = $pdo->prepare("DELETE FROM story_views WHERE story_id = ?");
$stmt->execute([$storyId]);

echo json_encode(['success' => true]);
?>