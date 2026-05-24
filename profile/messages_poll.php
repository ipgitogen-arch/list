<?php
declare(strict_types=1);

require __DIR__ . '/../inc/dd_bb.php';
require __DIR__ . '/../inc/auth.php';

require_auth();
$userId = current_user_id();
$friendId = (int)($_GET['friend_id'] ?? 0);

if ($friendId <= 0 || $friendId === $userId) {
    exit;
}

/* blocked */
$stmt = $pdo->prepare("
    SELECT 1
    FROM blocked_users
    WHERE (user_id=? AND blocked_user_id=?)
       OR (user_id=? AND blocked_user_id=?)
    LIMIT 1
");
$stmt->execute([$userId, $friendId, $friendId, $userId]);
if ($stmt->fetch()) {
    exit;
}

/* must be friends */
$stmt = $pdo->prepare("
    SELECT 1
    FROM friends
    WHERE user_id=? AND friend_id=?
    LIMIT 1
");
$stmt->execute([$userId, $friendId]);
if (!$stmt->fetch()) {
    exit;
}

$stmt = $pdo->prepare("
    SELECT *
    FROM messages
    WHERE (
        (sender_id=? AND receiver_id=?)
        OR
        (sender_id=? AND receiver_id=?)
    )
    AND id NOT IN (
        SELECT message_id FROM message_deletions WHERE user_id=?
    )
    ORDER BY id ASC
");
$stmt->execute([$userId, $friendId, $friendId, $userId, $userId]);
$messages = $stmt->fetchAll();

foreach ($messages as $m):
?>
<div class="msg <?= ((int)$m['sender_id'] === $userId) ? 'me' : '' ?>" data-id="<?= (int)$m['id'] ?>">
    <div class="bubble">
        <?= nl2br(htmlspecialchars((string)$m['content'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?>
        <?php if (!empty($m['attachment'])): ?>
            <img src="<?= htmlspecialchars((string)$m['attachment'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" onclick="openImg(this.src)" alt="attachment">
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>