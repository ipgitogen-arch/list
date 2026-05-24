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

$targetId = (int)($_POST['user_id'] ?? 0);
if (!$targetId) {
    header('Location: /x9p_admin_7k2/index.php');
    exit;
}

// ПОЛНОЕ УДАЛЕНИЕ — стираем все данные

// 1. Удаляем сообщения
$stmt = $pdo->prepare("DELETE FROM messages WHERE sender_id = ? OR receiver_id = ?");
$stmt->execute([$targetId, $targetId]);

// 2. Удаляем сообщения в групповых чатах
$stmt = $pdo->prepare("DELETE FROM group_messages WHERE user_id = ?");
$stmt->execute([$targetId]);

// 3. Удаляем сообщения в каналах
$stmt = $pdo->prepare("DELETE FROM channel_messages WHERE user_id = ?");
$stmt->execute([$targetId]);

// 4. Удаляем из подписчиков каналов
$stmt = $pdo->prepare("DELETE FROM channel_subscribers WHERE user_id = ?");
$stmt->execute([$targetId]);

// 5. Удаляем из участников групповых чатов
$stmt = $pdo->prepare("DELETE FROM group_chat_members WHERE user_id = ?");
$stmt->execute([$targetId]);

// 6. Удаляем запросы на переписку
$stmt = $pdo->prepare("DELETE FROM message_requests WHERE from_user_id = ? OR to_user_id = ?");
$stmt->execute([$targetId, $targetId]);

// 7. Удаляем уведомления
$stmt = $pdo->prepare("DELETE FROM notifications WHERE user_id = ?");
$stmt->execute([$targetId]);

// 8. Удаляем историю бота
$stmt = $pdo->prepare("DELETE FROM bot_chat_history WHERE user_id = ?");
$stmt->execute([$targetId]);

// 9. Удаляем настройки приватности
$stmt = $pdo->prepare("DELETE FROM privacy_settings WHERE user_id = ?");
$stmt->execute([$targetId]);

// 10. Удаляем профиль
$stmt = $pdo->prepare("DELETE FROM profiles WHERE user_id = ?");
$stmt->execute([$targetId]);

// 11. Удаляем пользователя
$stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
$stmt->execute([$targetId]);

header('Location: /x9p_admin_7k2/index.php');
exit;