<?php
session_start();
require __DIR__ . '/../inc/dd_bb.php';

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$stmt = $pdo->prepare("
    SELECT c.id, c.name, c.description, c.avatar, 
           (SELECT COUNT(*) FROM channel_subscribers WHERE channel_id = c.id) as subscribers
    FROM channels c
    WHERE c.is_public = 1
    ORDER BY subscribers DESC
");
$stmt->execute();
$channels = $stmt->fetchAll();

header('Content-Type: application/json');
echo json_encode($channels);