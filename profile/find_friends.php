<?php
session_start();
require __DIR__ . '/../inc/dd_bb.php';

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    header('Location: /auth/login.php');
    exit;
}

$search = trim($_GET['search'] ?? '');
$users = [];

if ($search) {
    $stmt = $pdo->prepare("
        SELECT u.id, u.name, u.uid, p.avatar,
            (SELECT COUNT(*) FROM user_subscribers WHERE following_id = u.id) as followers,
            (SELECT COUNT(*) FROM user_subscribers WHERE user_id = ? AND following_id = u.id) as is_following
        FROM users u
        LEFT JOIN profiles p ON p.user_id = u.id
        WHERE u.name LIKE ? OR u.uid LIKE ?
        AND u.id != ?
        LIMIT 30
    ");
    $stmt->execute([$userId, "%$search%", "%$search%", $userId]);
    $users = $stmt->fetchAll();
}

// Подписка/отписка
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['follow'])) {
    $followId = (int)$_POST['follow_id'];
    $action = $_POST['action'];
    
    if ($action === 'follow') {
        $stmt = $pdo->prepare("INSERT INTO user_subscribers (user_id, following_id) VALUES (?, ?)");
        $stmt->execute([$userId, $followId]);
    } else {
        $stmt = $pdo->prepare("DELETE FROM user_subscribers WHERE user_id = ? AND following_id = ?");
        $stmt->execute([$userId, $followId]);
    }
    header('Location: /profile/find_friends.php?search=' . urlencode($search));
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Найти друзей — Лист</title>
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
        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .search-box {
            margin-bottom: 20px;
        }
        .search-box input {
            width: 100%;
            padding: 12px 16px;
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 30px;
            color: #fff;
            font-size: 15px;
            outline: none;
        }
        .user-card {
            background: #111827;
            border-radius: 16px;
            padding: 12px;
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
            border: 1px solid rgba(255,255,255,0.06);
        }
        .user-avatar {
            width: 48px;
            height: 48px;
            border-radius: 24px;
            object-fit: cover;
        }
        .user-info {
            flex: 1;
        }
        .user-name {
            font-weight: 600;
            font-size: 16px;
        }
        .user-id {
            font-size: 11px;
            color: #64748b;
        }
        .follow-btn {
            padding: 8px 20px;
            border-radius: 30px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            border: none;
        }
        .follow-btn.follow {
            background: #3b82f6;
            color: #fff;
        }
        .follow-btn.unfollow {
            background: #1e293b;
            color: #ef4444;
            border: 1px solid #ef4444;
        }
        .empty {
            text-align: center;
            padding: 40px;
            color: #64748b;
        }
        .back-link {
            display: inline-block;
            margin-top: 20px;
            color: #3b82f6;
            text-decoration: none;
        }
    </style>
</head>
<body>

<div class="header">
    <a href="/" class="logo">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M12 3C12 3 5 8 5 15C5 19 8 22 12 22C16 22 19 19 19 15C19 8 12 3 12 3Z" fill="#3b82f6" stroke="#3b82f6" stroke-width="1.2"/>
            <path d="M12 3V22" stroke="#fff" stroke-width="0.8" opacity="0.6"/>
            <path d="M9 10L12 13L15 10" stroke="#fff" stroke-width="0.8" fill="none" opacity="0.6"/>
            <path d="M8 15L12 18L16 15" stroke="#fff" stroke-width="0.8" fill="none" opacity="0.6"/>
        </svg>
        <span>Лист</span>
    </a>
    <a href="/profile/profile.php" class="profile-link" style="color:#94a3b8; text-decoration:none;">Профиль</a>
</div>

<div class="container">
    <div class="search-box">
        <form method="GET">
            <input type="text" name="search" placeholder="Поиск по имени или ID..." value="<?= htmlspecialchars($search) ?>" autocomplete="off">
        </form>
    </div>
    
    <?php if ($search && empty($users)): ?>
        <div class="empty">😕 Пользователи не найдены</div>
    <?php endif; ?>
    
    <?php foreach ($users as $user): ?>
        <div class="user-card">
            <img class="user-avatar" src="<?= htmlspecialchars($user['avatar'] ?? '/pwa_icon/icon-96.png') ?>" onerror="this.src='/pwa_icon/icon-96.png'">
            <div class="user-info">
                <div class="user-name"><?= htmlspecialchars($user['name']) ?></div>
                <div class="user-id">ID: <?= htmlspecialchars($user['uid']) ?> • Подписчиков: <?= $user['followers'] ?></div>
            </div>
            <form method="POST">
                <input type="hidden" name="follow_id" value="<?= $user['id'] ?>">
                <?php if ($user['is_following']): ?>
                    <button type="submit" name="action" value="unfollow" class="follow-btn unfollow">Отписаться</button>
                <?php else: ?>
                    <button type="submit" name="action" value="follow" class="follow-btn follow">Подписаться</button>
                <?php endif; ?>
            </form>
        </div>
    <?php endforeach; ?>
    
    <a href="/feed.php" class="back-link">← Вернуться в ленту</a>
</div>

</body>
</html>