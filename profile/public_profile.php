<?php
session_start();
require __DIR__ . '/../inc/dd_bb.php';

$userId = (int)($_GET['id'] ?? 0);
if (!$userId) {
    header('Location: /index.php');
    exit;
}

// Получаем данные пользователя
$stmt = $pdo->prepare("SELECT id, name, email, is_plus, last_seen FROM users WHERE id = ? AND is_deleted = 0");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    header('Location: /index.php');
    exit;
}

$stmt = $pdo->prepare("SELECT avatar FROM profiles WHERE user_id = ?");
$stmt->execute([$userId]);
$avatar = $stmt->fetchColumn();

// Получаем публичные плейлисты пользователя
$stmt = $pdo->prepare("SELECT * FROM playlists WHERE user_id = ? AND is_public = 1 ORDER BY created_at DESC");
$stmt->execute([$userId]);
$playlists = $stmt->fetchAll();

// Получаем публичный контент (из публичных плейлистов)
$content = [];
foreach ($playlists as $playlist) {
    $stmt = $pdo->prepare("SELECT * FROM content_items WHERE playlist_id = ? ORDER BY created_at DESC LIMIT 10");
    $stmt->execute([$playlist['id']]);
    $items = $stmt->fetchAll();
    foreach ($items as $item) {
        $item['playlist_name'] = $playlist['name'];
        $content[] = $item;
    }
}

function timeAgo($timestamp) {
    if (!$timestamp) return '';
    $diff = time() - strtotime($timestamp);
    if ($diff < 60) return 'только что';
    if ($diff < 3600) return floor($diff / 60) . ' мин назад';
    if ($diff < 86400) return floor($diff / 3600) . ' ч назад';
    return floor($diff / 86400) . ' дн назад';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= htmlspecialchars($user['name']) ?> — Лист</title>
    <link rel="manifest" href="/manifest.json">
    <link rel="apple-touch-icon" href="/pwa_icon/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="96x96" href="/pwa_icon/favicon-96x96.png">
    <link rel="icon" type="image/svg+xml" href="/pwa_icon/favicon.svg">
    <link rel="shortcut icon" href="/pwa_icon/favicon.ico">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#0f172a">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Inter', 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background: #0f172a;
            color: #f1f5f9;
            min-height: 100vh;
        }
        .header {
            background: rgba(17, 24, 39, 0.95);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255,255,255,0.06);
            padding: 12px 32px;
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
        .back-btn {
            background: #1e293b;
            padding: 8px 20px;
            border-radius: 30px;
            text-decoration: none;
            color: #fff;
            font-size: 14px;
        }
        .container { max-width: 800px; margin: 0 auto; padding: 32px 24px; }
        
        .profile-card {
            background: #111827;
            border-radius: 24px;
            border: 1px solid rgba(255,255,255,0.06);
            padding: 32px;
            text-align: center;
            margin-bottom: 32px;
        }
        .avatar {
            width: 100px;
            height: 100px;
            border-radius: 50px;
            object-fit: cover;
            margin-bottom: 16px;
        }
        .avatar-placeholder {
            width: 100px;
            height: 100px;
            border-radius: 50px;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
            font-weight: 600;
            margin: 0 auto 16px;
        }
        .user-name { font-size: 24px; font-weight: 600; margin-bottom: 4px; }
        .user-status { font-size: 13px; color: #94a3b8; }
        .online { color: #22c55e; }
        .offline { color: #64748b; }
        .message-btn {
            display: inline-block;
            margin-top: 16px;
            padding: 8px 24px;
            background: #3b82f6;
            border-radius: 40px;
            text-decoration: none;
            color: #fff;
            font-size: 14px;
        }
        
        .content-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
        }
        .content-item {
            background: #1e293b;
            border-radius: 16px;
            overflow: hidden;
        }
        .content-media {
            width: 100%;
            height: 180px;
            object-fit: cover;
        }
        .content-info { padding: 12px; }
        .content-title { font-weight: 600; font-size: 14px; margin-bottom: 4px; }
        .content-meta { font-size: 11px; color: #64748b; }
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #64748b;
        }
        
        @media (max-width: 600px) {
            .header { padding: 12px 20px; }
            .container { padding: 24px 16px; }
            .avatar, .avatar-placeholder { width: 80px; height: 80px; font-size: 32px; }
            .user-name { font-size: 20px; }
            .content-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="header">
        <a href="/" class="logo">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
                <path d="M12 3C12 3 5 8 5 15C5 19 8 22 12 22C16 22 19 19 19 15C19 8 12 3 12 3Z" fill="#3b82f6" stroke="#3b82f6" stroke-width="1.2"/>
                <path d="M12 3V22" stroke="#fff" stroke-width="0.8" opacity="0.6"/>
                <path d="M9 10L12 13L15 10" stroke="#fff" stroke-width="0.8" fill="none" opacity="0.6"/>
                <path d="M8 15L12 18L16 15" stroke="#fff" stroke-width="0.8" fill="none" opacity="0.6"/>
            </svg>
            <span>Лист</span>
        </a>
        <a href="/profile/dialogs.php" class="back-btn">← Назад</a>
    </div>

    <div class="container">
        <div class="profile-card">
            <?php if ($avatar): ?>
                <img class="avatar" src="<?= htmlspecialchars($avatar) ?>" alt="">
            <?php else: ?>
                <div class="avatar-placeholder"><?= mb_substr($user['name'], 0, 1) ?></div>
            <?php endif; ?>
            <div class="user-name"><?= htmlspecialchars($user['name']) ?></div>
            <div class="user-status">
                <?php if ($user['last_seen'] && time() - $user['last_seen'] < 300): ?>
                    <span class="online">Онлайн</span>
                <?php else: ?>
                    <span class="offline">Был(а) <?= timeAgo($user['last_seen']) ?></span>
                <?php endif; ?>
            </div>
            <?php if (isset($_SESSION['user_id']) && $_SESSION['user_id'] != $userId): ?>
                <a href="/profile/send_request.php?user=<?= $userId ?>" class="message-btn">Написать сообщение</a>
            <?php endif; ?>
        </div>

        <h2 style="margin-bottom: 20px;">Публичный контент</h2>
        
        <?php if (empty($content)): ?>
            <div class="empty-state">
                <div style="font-size: 48px; margin-bottom: 12px;">📭</div>
                <p>У пользователя пока нет публичного контента</p>
            </div>
        <?php else: ?>
            <div class="content-grid">
                <?php foreach ($content as $item): ?>
                    <div class="content-item">
                        <?php if ($item['type'] === 'image'): ?>
                            <img class="content-media" src="<?= htmlspecialchars($item['file_path']) ?>" alt="">
                        <?php else: ?>
                            <video class="content-media" controls>
                                <source src="<?= htmlspecialchars($item['file_path']) ?>" type="video/mp4">
                            </video>
                        <?php endif; ?>
                        <div class="content-info">
                            <div class="content-title"><?= htmlspecialchars($item['title'] ?? 'Без названия') ?></div>
                            <div class="content-meta">📅 <?= date('d.m.Y', strtotime($item['created_at'])) ?> • 👁️ <?= $item['views'] ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>