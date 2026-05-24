<?php
session_start();
require __DIR__ . '/../inc/dd_bb.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /auth/login.php');
    exit;
}

$userId = $_SESSION['user_id'];

// Помечаем все как прочитанные
if (isset($_GET['mark_all'])) {
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    $stmt->execute([$userId]);
    header('Location: /profile/notifications.php');
    exit;
}

// Получаем уведомления
$stmt = $pdo->prepare("
    SELECT * FROM notifications 
    WHERE user_id = ? 
    ORDER BY created_at DESC 
    LIMIT 100
");
$stmt->execute([$userId]);
$notifications = $stmt->fetchAll();

foreach ($notifications as &$n) {
    $diff = time() - strtotime($n['created_at']);
    if ($diff < 60) $n['time_ago'] = 'только что';
    elseif ($diff < 3600) $n['time_ago'] = floor($diff / 60) . ' мин назад';
    elseif ($diff < 86400) $n['time_ago'] = floor($diff / 3600) . ' ч назад';
    else $n['time_ago'] = floor($diff / 86400) . ' дн назад';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Уведомления — Лист</title>
    <link rel="manifest" href="/manifest.json">
    <link rel="apple-touch-icon" href="/pwa_icon/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="96x96" href="/pwa_icon/favicon-96x96.png">
    <link rel="icon" type="image/svg+xml" href="/pwa_icon/favicon.svg">
    <link rel="shortcut icon" href="/pwa_icon/favicon.ico">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#0f172a">
    <link rel="stylesheet" href="/assets/css/pwa-fix.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background: #0f172a;
            color: #f1f5f9;
            min-height: 100vh;
        }
        .header {
            background: rgba(17, 24, 39, 0.95);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255,255,255,0.06);
            padding: 12px 20px;
            padding-top: max(12px, env(safe-area-inset-top));
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .logo {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 20px;
            font-weight: 600;
            text-decoration: none;
            color: #fff;
        }
        .back-link {
            color: #94a3b8;
            text-decoration: none;
            font-size: 14px;
            padding: 8px 16px;
            border-radius: 40px;
            background: #1e293b;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .notification-item {
            background: #111827;
            border-radius: 16px;
            padding: 16px;
            margin-bottom: 12px;
            border: 1px solid rgba(255,255,255,0.06);
            cursor: pointer;
            transition: 0.2s;
        }
        .notification-item:hover {
            background: #1e293b;
        }
        .notification-item.unread {
            background: rgba(59, 130, 246, 0.08);
            border-left: 3px solid #3b82f6;
        }
        .notification-title {
            font-weight: 600;
            font-size: 15px;
            margin-bottom: 6px;
        }
        .notification-message {
            font-size: 13px;
            color: #94a3b8;
            margin-bottom: 8px;
        }
        .notification-time {
            font-size: 11px;
            color: #64748b;
        }
        .mark-all-btn {
            background: #1e293b;
            border: none;
            padding: 8px 16px;
            border-radius: 30px;
            color: #fff;
            font-size: 13px;
            cursor: pointer;
            margin-bottom: 20px;
        }
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #64748b;
        }
        @media (max-width: 600px) {
            .container { padding: 16px; }
            .notification-item { padding: 12px; }
        }
    </style>
</head>
<body>

<div class="header">
    <a href="/index.php" class="logo">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M12 3C12 3 5 8 5 15C5 19 8 22 12 22C16 22 19 19 19 15C19 8 12 3 12 3Z" fill="#3b82f6" stroke="#3b82f6" stroke-width="1.2"/>
            <path d="M12 3V22" stroke="#fff" stroke-width="0.8" opacity="0.6"/>
            <path d="M9 10L12 13L15 10" stroke="#fff" stroke-width="0.8" fill="none" opacity="0.6"/>
            <path d="M8 15L12 18L16 15" stroke="#fff" stroke-width="0.8" fill="none" opacity="0.6"/>
        </svg>
        <span>Лист</span>
    </a>
    <a href="/profile/profile.php" class="back-link">Профиль</a>
</div>

<div class="container">
    <div style="display: flex; justify-content: flex-end; margin-bottom: 16px;">
        <button class="mark-all-btn" onclick="markAllRead()">Прочитать всё</button>
    </div>
    
    <?php if (count($notifications) > 0): ?>
        <?php foreach ($notifications as $notif): ?>
            <div class="notification-item <?= !$notif['is_read'] ? 'unread' : '' ?>" onclick="markRead(<?= $notif['id'] ?>, '<?= htmlspecialchars($notif['link']) ?>')">
                <div class="notification-title"><?= htmlspecialchars($notif['title']) ?></div>
                <div class="notification-message"><?= htmlspecialchars($notif['message']) ?></div>
                <div class="notification-time"><?= $notif['time_ago'] ?></div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="empty-state">
            <div style="font-size: 48px; margin-bottom: 12px;">🔔</div>
            <p>У вас нет уведомлений</p>
        </div>
    <?php endif; ?>
</div>

<script>
    function markRead(id, link) {
        fetch('/api/notifications.php?mark_read=' + id)
            .then(() => {
                if (link) window.location.href = link;
                else location.reload();
            });
    }
    
    function markAllRead() {
        fetch('/api/notifications.php?mark_all_read=1')
            .then(() => location.reload());
    }
</script>

</body>
</html>