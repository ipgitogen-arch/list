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
$rawIds = trim((string)($_POST['message_ids'] ?? ''));

if ($friendId <= 0 || $rawIds === '') {
    header('Location: /profile/dialogs.php?friend_id=' . $friendId);
    exit;
}

$ids = array_filter(array_map('intval', explode(',', $rawIds)));
$ids = array_values(array_unique(array_filter($ids, fn($v) => $v > 0)));

if (!$ids) {
    header('Location: /profile/dialogs.php?friend_id=' . $friendId);
    exit;
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$params = $ids;
$params[] = $userId;
$params[] = $friendId;
$params[] = $friendId;
$params[] = $userId;

$stmt = $pdo->prepare("
    SELECT id FROM messages
    WHERE id IN ($placeholders)
      AND (
            (sender_id=? AND receiver_id=?)
            OR
            (sender_id=? AND receiver_id=?)
          )
");
$stmt->execute($params);
$allowed = $stmt->fetchAll(PDO::FETCH_COLUMN);

if ($allowed) {
    $stmt = $pdo->prepare("INSERT IGNORE INTO message_deletions (message_id, user_id) VALUES (?, ?)");
    foreach ($allowed as $msgId) {
        $stmt->execute([(int)$msgId, $userId]);
    }
}

header('Location: /profile/dialogs.php?friend_id=' . $friendId);
exit;