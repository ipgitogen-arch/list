<?php
session_start();
require __DIR__ . '/../inc/dd_bb.php';

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Проверяем роль
$stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
$stmt->execute([$userId]);
$role = $stmt->fetchColumn();

if ($role !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Permission denied']);
    exit;
}

// Проверяем активную сессию шифрования
$sessionToken = $_SESSION['admin_crypto_token'] ?? null;
if (!$sessionToken) {
    echo json_encode(['error' => 'no_key', 'message' => 'Требуется ввод ключа шифрования']);
    exit;
}

$stmt = $pdo->prepare("SELECT id FROM admin_crypto_sessions WHERE session_token = ? AND user_id = ? AND expires_at > NOW()");
$stmt->execute([$sessionToken, $userId]);
if (!$stmt->fetch()) {
    unset($_SESSION['admin_crypto_token']);
    echo json_encode(['error' => 'no_key', 'message' => 'Сессия истекла, введите ключ заново']);
    exit;
}

// Получаем пользователей, с которыми есть чаты
$stmt = $pdo->prepare("
    SELECT DISTINCT u.id, u.name, u.uid, p.avatar
    FROM users u
    LEFT JOIN profiles p ON p.user_id = u.id
    WHERE u.id IN (
        SELECT sender_id FROM messages WHERE receiver_id = ?
        UNION
        SELECT receiver_id FROM messages WHERE sender_id = ?
    ) AND u.id != ?
    ORDER BY u.name ASC
");
$stmt->execute([$userId, $userId, $userId]);
$users = $stmt->fetchAll();

echo json_encode(['success' => true, 'users' => $users]);