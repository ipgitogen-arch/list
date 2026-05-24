<?php
declare(strict_types=1);

require __DIR__ . '/../../inc/dd_bb.php';

$time = time();

$stmt = $pdo->prepare("
    SELECT id FROM users
    WHERE is_deleted = 1
      AND delete_at IS NOT NULL
      AND delete_at < ?
");
$stmt->execute([$time]);
$users = $stmt->fetchAll();

foreach ($users as $u) {
    $id = (int)$u['id'];

    $pdo->prepare("DELETE FROM messages WHERE sender_id=? OR receiver_id=?")->execute([$id, $id]);
    $pdo->prepare("DELETE FROM friends WHERE user_id=? OR friend_id=?")->execute([$id, $id]);
    $pdo->prepare("DELETE FROM blocked_users WHERE user_id=? OR blocked_user_id=?")->execute([$id, $id]);
    $pdo->prepare("DELETE FROM message_deletions WHERE user_id=?")->execute([$id]);
    $pdo->prepare("DELETE FROM profiles WHERE user_id=?")->execute([$id]);
    $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
}