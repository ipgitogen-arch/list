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

/* users */
$stmt = $pdo->prepare("SELECT id, role FROM users WHERE id=? LIMIT 1");
$stmt->execute([$userId]);
$me = $stmt->fetch();

$stmt = $pdo->prepare("SELECT id, role FROM users WHERE id=? LIMIT 1");
$stmt->execute([$friendId]);
$other = $stmt->fetch();

if (!$me || !$other) {
    header('Location: /profile/dialogs.php');
    exit;
}

/* blocked */
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM blocked_users
    WHERE (user_id=? AND blocked_user_id=?)
       OR (user_id=? AND blocked_user_id=?)
");
$stmt->execute([$userId, $friendId, $friendId, $userId]);
if ((int)$stmt->fetchColumn() > 0) {
    header('Location: /profile/dialogs.php?friend_id=' . $friendId);
    exit;
}

/* already friends */
$stmt = $pdo->prepare("SELECT COUNT(*) FROM friends WHERE user_id=? AND friend_id=?");
$stmt->execute([$userId, $friendId]);
if ((int)$stmt->fetchColumn() > 0) {
    header('Location: /profile/dialogs.php?friend_id=' . $friendId);
    exit;
}

/* privacy */
$stmt = $pdo->prepare("SELECT privacy_requests FROM privacy_settings WHERE user_id=? LIMIT 1");
$stmt->execute([$friendId]);
$privacy = (int)($stmt->fetchColumn() ?: 0);

/* admin bypass OR privacy disabled => add friend immediately */
if ($me['role'] === 'admin' || $other['role'] === 'admin' || $privacy === 0) {
    $pdo->prepare("INSERT IGNORE INTO friends (user_id, friend_id) VALUES (?, ?)")->execute([$userId, $friendId]);
    $pdo->prepare("INSERT IGNORE INTO friends (user_id, friend_id) VALUES (?, ?)")->execute([$friendId, $userId]);
    header('Location: /profile/dialogs.php?friend_id=' . $friendId);
    exit;
}

/* create request */
$pdo->prepare("
    INSERT INTO message_requests (from_user_id, to_user_id, status)
    VALUES (?, ?, 'pending')
    ON DUPLICATE KEY UPDATE status='pending'
")->execute([$userId, $friendId]);

header('Location: /profile/dialogs.php?friend_id=' . $friendId . '&tab=requests');
exit;