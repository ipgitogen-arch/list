<?php
session_start();
require __DIR__ . '/../inc/dd_bb.php';

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    header('Location: /auth/login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Мягкое удаление — только помечаем аккаунт как удалённый
    // Вся информация сохраняется, но пользователь не может войти
    $stmt = $pdo->prepare("UPDATE users SET is_deleted = 1, delete_at = NOW() WHERE id = ?");
    $stmt->execute([$userId]);
    
    session_destroy();
    header('Location: /index.php');
    exit;
}

header('Location: /profile/profile.php');
exit;