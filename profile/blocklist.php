<?php
declare(strict_types=1);

require __DIR__ . '/../inc/dd_bb.php';
require __DIR__ . '/../inc/auth.php';

require_auth();
$userId = current_user_id();

if (isset($_GET['unblock'])) {
    $unblockId = (int)$_GET['unblock'];
    $pdo->prepare("DELETE FROM blocked_users WHERE user_id=? AND blocked_user_id=?")->execute([$userId, $unblockId]);
    header('Location: /profile/blocklist.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT u.id, u.name, u.uid
    FROM blocked_users b
    JOIN users u ON u.id = b.blocked_user_id
    WHERE b.user_id=?
    ORDER BY u.name ASC
");
$stmt->execute([$userId]);
$blocked = $stmt->fetchAll();
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<link rel="manifest" href="/manifest.json">
<title>Блок-лист — Лист</title>
<link rel="stylesheet" href="/profile/style.css?v=9">
</head>
<body>
<div class="profile-wrapper">
    <div class="profile-container">
        <h1>Блок-лист</h1>

        <div class="profile-info">
            <?php if (!$blocked): ?>
                <p>Список пуст.</p>
            <?php else: ?>
                <?php foreach ($blocked as $u): ?>
                    <p>
                        <strong><?= htmlspecialchars($u['name']) ?></strong>
                        (@<?= htmlspecialchars($u['uid']) ?>)
                        — <a href="?unblock=<?= (int)$u['id'] ?>" style="color:#00aaff;">Убрать из блока</a>
                    </p>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="profile-buttons" style="margin-top:18px;">
            <a class="main-btn secondary-btn" href="/profile/privacy.php">Назад</a>
        </div>
    </div>
</div>
</body>
</html>