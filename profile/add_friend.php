<?php
declare(strict_types=1);

require __DIR__ . '/../inc/dd_bb.php';
require __DIR__ . '/../inc/auth.php';

$userId = current_user_id();
if (!$userId) {
    header('Location: /auth/login.php');
    exit;
}

$friendId = (int)($_POST['friend_id'] ?? 0);
if ($friendId <= 0 || $friendId === $userId) {
    header('Location: /profile/dialogs.php');
    exit;
}

$stmt = $pdo->prepare("SELECT id, is_deleted FROM users WHERE id=? LIMIT 1");
$stmt->execute([$friendId]);
$target = $stmt->fetch();

if (!$target || (int)($target['is_deleted'] ?? 0) === 1) {
    header('Location: /profile/dialogs.php');
    exit;
}

$pdo->prepare("INSERT IGNORE INTO friends (user_id, friend_id) VALUES (?, ?)")->execute([$userId, $friendId]);
$pdo->prepare("INSERT IGNORE INTO friends (user_id, friend_id) VALUES (?, ?)")->execute([$friendId, $userId]);

header('Location: /profile/dialogs.php?friend_id=' . $friendId);
exit;