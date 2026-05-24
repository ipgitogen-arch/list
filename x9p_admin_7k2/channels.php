<?php
declare(strict_types=1);
require __DIR__ . '/../inc/dd_bb.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: /auth/login.php');
    exit;
}

$userId = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT role FROM users WHERE id=? LIMIT 1");
$stmt->execute([$userId]);
$me = $stmt->fetch();

if (!$me || $me['role'] !== 'admin') {
    http_response_code(403);
    exit('Доступ запрещён');
}

// Поиск каналов
$search = trim($_GET['search'] ?? '');
if ($search !== '') {
    $stmt = $pdo->prepare("
        SELECT c.*, 
               (SELECT COUNT(*) FROM channel_subscribers WHERE channel_id = c.id) as subscribers
        FROM channels c
        WHERE c.name LIKE ?
        ORDER BY c.id DESC
    ");
    $stmt->execute(["%$search%"]);
} else {
    $stmt = $pdo->prepare("
        SELECT c.*, 
               (SELECT COUNT(*) FROM channel_subscribers WHERE channel_id = c.id) as subscribers
        FROM channels c
        ORDER BY c.id DESC
    ");
    $stmt->execute();
}
$channels = $stmt->fetchAll();

// Подписать всех пользователей на канал
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['subscribe_all'])) {
    $channelId = (int)$_POST['channel_id'];
    
    $stmt = $pdo->prepare("SELECT name FROM channels WHERE id = ?");
    $stmt->execute([$channelId]);
    $channelName = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT id FROM users WHERE is_deleted = 0");
    $allUsers = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($allUsers as $uid) {
        $stmt = $pdo->prepare("INSERT IGNORE INTO channel_subscribers (channel_id, user_id, role, joined_at) VALUES (?, ?, 'member', NOW())");
        $stmt->execute([$channelId, $uid]);
        
        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, link, created_at) VALUES (?, 'channel', 'Новый канал', ?, ?, NOW())");
        $stmt->execute([$uid, 'Вы были подписаны на канал ' . $channelName, '/profile/channel.php?id=' . $channelId]);
    }
    
    $_SESSION['admin_success'] = 'Все пользователи подписаны на канал';
    header('Location: /x9p_admin_7k2/channels.php');
    exit;
}

// Добавить/убрать официальную звезду
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_official'])) {
    $channelId = (int)$_POST['channel_id'];
    $stmt = $pdo->prepare("UPDATE channels SET is_official = NOT is_official WHERE id = ?");
    $stmt->execute([$channelId]);
    $_SESSION['admin_success'] = 'Статус канала изменён';
    header('Location: /x9p_admin_7k2/channels.php');
    exit;
}

// Удалить канал
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_channel'])) {
    $channelId = (int)$_POST['channel_id'];
    
    $stmt = $pdo->prepare("DELETE FROM channel_messages WHERE channel_id = ?");
    $stmt->execute([$channelId]);
    
    $stmt = $pdo->prepare("DELETE FROM channel_subscribers WHERE channel_id = ?");
    $stmt->execute([$channelId]);
    
    $stmt = $pdo->prepare("DELETE FROM channels WHERE id = ?");
    $stmt->execute([$channelId]);
    
    $_SESSION['admin_success'] = 'Канал удалён';
    header('Location: /x9p_admin_7k2/channels.php');
    exit;
}

$success = $_SESSION['admin_success'] ?? null;
unset($_SESSION['admin_success']);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Каналы — Админка</title>
    <link rel="manifest" href="/manifest.json">
    <link rel="apple-touch-icon" href="/pwa_icon/apple-touch-icon.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#0f172a">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Display', 'SF Pro Text', 'Helvetica Neue', system-ui, sans-serif;
            background: #0f172a;
            color: #f1f5f9;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }

        .gradient-bg {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: radial-gradient(ellipse at 20% 30%, #1e293b, #0f172a, #020617);
            z-index: -1;
        }

        .nav {
            position: sticky;
            top: 0;
            background: rgba(15, 23, 42, 0.8);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            z-index: 100;
        }

        .nav-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }

        .logo-icon svg {
            width: 28px;
            height: 28px;
        }

        .logo-text {
            font-size: 22px;
            font-weight: 600;
            background: linear-gradient(135deg, #ef4444, #dc2626);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .nav-links {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .nav-btn {
            padding: 8px 20px;
            border-radius: 40px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.2s ease;
            background: rgba(30, 41, 59, 0.8);
            color: #cbd5e1;
            font-size: 14px;
            border: 1px solid rgba(255, 255, 255, 0.05);
        }

        .nav-btn:hover {
            background: #334155;
            color: #ffffff;
            transform: translateY(-1px);
        }

        .nav-btn.active {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            border-color: transparent;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 24px 16px;
        }

        .page-title {
            font-size: 28px;
            font-weight: 700;
            letter-spacing: -0.5px;
            margin-bottom: 24px;
            background: linear-gradient(135deg, #ef4444, #dc2626);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .search-box {
            margin-bottom: 24px;
        }

        .search-box input {
            width: 100%;
            max-width: 360px;
            padding: 12px 20px;
            border-radius: 40px;
            background: rgba(30, 41, 59, 0.8);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #ffffff;
            font-size: 15px;
            outline: none;
        }

        .search-box input:focus {
            border-color: #ef4444;
        }

        .channel-list {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .channel-card {
            background: rgba(30, 41, 59, 0.5);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 20px;
            padding: 20px;
            transition: all 0.3s ease;
        }

        .channel-card:hover {
            transform: translateY(-2px);
            background: rgba(30, 41, 59, 0.7);
            border-color: rgba(239, 68, 68, 0.3);
        }

        .channel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 12px;
        }

        .channel-name {
            font-size: 18px;
            font-weight: 700;
        }

        .official-star {
            color: #f59e0b;
            font-size: 16px;
            margin-left: 8px;
        }

        .channel-stats {
            font-size: 14px;
            color: #94a3b8;
            margin-bottom: 16px;
        }

        .channel-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 8px 20px;
            border-radius: 40px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s;
            display: inline-block;
            border: none;
            cursor: pointer;
            font-size: 13px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }

        .btn-warning {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
        }

        .btn-danger {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
        }

        .success {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.3);
            color: #4ade80;
            padding: 12px 16px;
            border-radius: 12px;
            margin-bottom: 20px;
            text-align: center;
        }

        .empty-state {
            text-align: center;
            padding: 60px 24px;
            color: #64748b;
        }

        @media (max-width: 600px) {
            .nav-container {
                flex-direction: column;
                text-align: center;
            }
            
            .channel-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .channel-actions {
                flex-direction: column;
                width: 100%;
            }
            
            .channel-actions .btn {
                text-align: center;
                width: 100%;
            }
        }
    </style>
</head>
<body>

<div class="gradient-bg"></div>

<nav class="nav">
    <div class="nav-container">
        <a href="/x9p_admin_7k2/index.php" class="logo">
            <div class="logo-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="28" height="28" fill="none">
                    <path d="M12 3C12 3 5 8 5 15C5 19 8 22 12 22C16 22 19 19 19 15C19 8 12 3 12 3Z" fill="#ef4444" stroke="#ef4444" stroke-width="1.2"/>
                    <path d="M12 3V22" stroke="#ffffff" stroke-width="0.8" opacity="0.6"/>
                    <path d="M9 10L12 13L15 10" stroke="#ffffff" stroke-width="0.8" fill="none" opacity="0.6"/>
                    <path d="M8 15L12 18L16 15" stroke="#ffffff" stroke-width="0.8" fill="none" opacity="0.6"/>
                </svg>
            </div>
            <span class="logo-text">Админ-панель</span>
        </a>
        <div class="nav-links">
            <a href="/x9p_admin_7k2/index.php" class="nav-btn">Пользователи</a>
            <a href="/x9p_admin_7k2/channels.php" class="nav-btn active">Каналы</a>
            <a href="/x9p_admin_7k2/messages.php" class="nav-btn">Сообщения</a>
            <a href="/x9p_admin_7k2/support.php" class="nav-btn">Поддержка</a>
            <a href="/x9p_admin_7k2/poss.php" class="nav-btn">ПОСС</a>
            <a href="/profile/profile.php" class="nav-btn">Профиль</a>
            <a href="/auth/logout.php" class="nav-btn">Выйти</a>
        </div>
    </div>
</nav>

<main class="container">
    <h1 class="page-title">Управление каналами</h1>
    
    <?php if ($success): ?>
        <div class="success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    
    <div class="search-box">
        <form method="get">
            <input type="text" name="search" placeholder="Поиск каналов по названию..." value="<?= htmlspecialchars($search) ?>">
        </form>
    </div>
    
    <div class="channel-list">
        <?php foreach ($channels as $ch): ?>
            <div class="channel-card">
                <div class="channel-header">
                    <div>
                        <span class="channel-name"><?= htmlspecialchars($ch['name']) ?></span>
                        <?php if ($ch['is_official']): ?>
                            <span class="official-star">⭐</span>
                        <?php endif; ?>
                    </div>
                    <div class="channel-stats">
                        Подписчиков: <?= $ch['subscribers'] ?>
                    </div>
                </div>
                <div class="channel-actions">
                    <a href="/profile/channel.php?id=<?= $ch['id'] ?>" class="btn btn-primary">Открыть</a>
                    
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="channel_id" value="<?= $ch['id'] ?>">
                        <button type="submit" name="toggle_official" class="btn btn-warning">
                            <?= $ch['is_official'] ? 'Убрать звезду' : 'Сделать официальным' ?>
                        </button>
                    </form>
                    
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="channel_id" value="<?= $ch['id'] ?>">
                        <button type="submit" name="subscribe_all" class="btn btn-primary" onclick="return confirm('Подписать всех пользователей на этот канал?')">
                            Подписать всех
                        </button>
                    </form>
                    
                    <form method="post" style="display:inline;" onsubmit="return confirm('Удалить канал безвозвратно? Все сообщения и подписчики будут удалены.');">
                        <input type="hidden" name="channel_id" value="<?= $ch['id'] ?>">
                        <button type="submit" name="delete_channel" class="btn btn-danger">Удалить канал</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (empty($channels)): ?>
            <div class="empty-state">Каналы не найдены</div>
        <?php endif; ?>
    </div>
</main>

<script>
    if (window.navigator.standalone === true || 
        window.matchMedia('(display-mode: standalone)').matches) {
        document.body.style.paddingTop = 'env(safe-area-inset-top)';
    }
</script>

</body>
</html>