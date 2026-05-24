<?php
declare(strict_types=1);

require __DIR__ . '/../inc/dd_bb.php';
require __DIR__ . '/../inc/auth.php';

$userId = current_user_id();
if (!$userId) {
    header('Location: /auth/login.php');
    exit;
}

$action = trim((string)($_POST['action'] ?? ''));
$friendId = (int)($_POST['friend_id'] ?? 0);

if ($friendId <= 0 || $friendId === $userId) {
    header('Location: /profile/dialogs.php');
    exit;
}

if ($action === 'clear_chat') {
    /* удалить все сообщения */
    $pdo->prepare("
        DELETE FROM messages
        WHERE (sender_id=? AND receiver_id=?)
           OR (sender_id=? AND receiver_id=?)
    ")->execute([$userId, $friendId, $friendId, $userId]);

    /* убрать из друзей */
    $pdo->prepare("
        DELETE FROM friends
        WHERE (user_id=? AND friend_id=?)
           OR (user_id=? AND friend_id=?)
    ")->execute([$userId, $friendId, $friendId, $userId]);

    /* убрать принятые запросы */
    $pdo->prepare("
        DELETE FROM message_requests
        WHERE (from_user_id=? AND to_user_id=?)
           OR (from_user_id=? AND to_user_id=?)
    ")->execute([$userId, $friendId, $friendId, $userId]);

    header('Location: /profile/dialogs.php');
    exit;
}

if ($action === 'block_user') {
    $pdo->prepare("
        INSERT IGNORE INTO blocked_users (user_id, blocked_user_id)
        VALUES (?, ?)
    ")->execute([$userId, $friendId]);

    /* удалить дружбу */
    $pdo->prepare("
        DELETE FROM friends
        WHERE (user_id=? AND friend_id=?)
           OR (user_id=? AND friend_id=?)
    ")->execute([$userId, $friendId, $friendId, $userId]);

    header('Location: /profile/dialogs.php');
    exit;
}

header('Location: /profile/dialogs.php');
exit;