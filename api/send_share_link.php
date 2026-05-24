<?php
session_start();
require __DIR__ . '/../inc/dd_bb.php';

$senderId = $_SESSION['user_id'] ?? null;
if (!$senderId) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$userId = (int)($input['user_id'] ?? 0);
$link = $input['link'] ?? '';

if (!$userId || !$link) {
    echo json_encode(['error' => 'Invalid data']);
    exit;
}

// Получаем имя отправителя
$stmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
$stmt->execute([$senderId]);
$sender = $stmt->fetch();

// Отправляем уведомление
$stmt = $pdo->prepare("
    INSERT INTO notifications (user_id, type, title, message, link, created_at) 
    VALUES (?, 'system', 'Приглашение в чат', ?, ?, NOW())
");
$stmt->execute([$userId, 'Пользователь ' . $sender['name'] . ' приглашает вас в чат', $link]);

echo json_encode(['success' => true]);