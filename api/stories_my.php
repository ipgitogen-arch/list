<?php
session_start();
require __DIR__ . '/../inc/dd_bb.php';

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Получаем свои истории
$stmt = $pdo->prepare("
    SELECT id, media_type, media_path, created_at, views, expires_at
    FROM stories 
    WHERE user_id = ? AND expires_at > NOW()
    ORDER BY created_at DESC
");
$stmt->execute([$userId]);
$stories = $stmt->fetchAll();

echo json_encode(['success' => true, 'stories' => $stories]);
?>