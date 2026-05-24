<?php
declare(strict_types=1);
require __DIR__ . '/../inc/dd_bb.php';
require __DIR__ . '/../inc/auth.php';

$userId = current_user_id();
if (!$userId) {
    header('Location: /auth/login.php');
    exit;
}

$stmt = $pdo->prepare("SELECT role FROM users WHERE id=? LIMIT 1");
$stmt->execute([$userId]);
$me = $stmt->fetch();

if (!$me || $me['role'] !== 'admin') {
    http_response_code(403);
    exit('Доступ запрещён');
}

$targetId = (int)($_GET['id'] ?? 0);
if (!$targetId) {
    header('Location: /x9p_admin_7k2/index.php');
    exit;
}

// Мягкое удаление — только помечаем аккаунт
$stmt = $pdo->prepare("UPDATE users SET is_deleted = 1, delete_at = NOW() WHERE id = ?");
$stmt->execute([$targetId]);

header("Location: /x9p_admin_7k2/users.php?id=" . $targetId);
exit;