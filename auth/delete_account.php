<?php
declare(strict_types=1);
require __DIR__ . '/../inc/dd_bb.php';
require __DIR__ . '/../inc/auth.php';

$userId = current_user_id();
if ($userId) {
    // Удаляем все сообщения
    $pdo->prepare('DELETE FROM messages WHERE sender_id=? OR receiver_id=?')->execute([$userId,$userId]);
    // Удаляем друзей
    $pdo->prepare('DELETE FROM friends WHERE user_id=? OR friend_id=?')->execute([$userId,$userId]);
    // Удаляем профиль
    $pdo->prepare('DELETE FROM profiles WHERE user_id=?')->execute([$userId]);
    // Удаляем пользователя
    $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);
    // Логаут
    logout_user();
}

header('Location: /auth/login.php');
exit;