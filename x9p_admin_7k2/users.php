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

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    header('Location: /x9p_admin_7k2/index.php');
    exit;
}

// Полное удаление пользователя
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hard_delete'])) {
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([$id]);
    header('Location: /x9p_admin_7k2/index.php?deleted=1');
    exit;
}

$stmt = $pdo->prepare("SELECT id, name, email, uid, role, subscription, subscription_expires, is_plus, is_deleted, created_at, main_ip, last_ip, last_seen FROM users WHERE id = ?");
$stmt->execute([$id]);
$user = $stmt->fetch();

if (!$user) {
    header('Location: /x9p_admin_7k2/index.php');
    exit;
}

$hasPlus = ($user['subscription'] === 'plus' || $user['is_plus'] == 1);

function timeAgo($timestamp) {
    if (!$timestamp) return '';
    $diff = time() - $timestamp;
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
    <title>Пользователь — Админка</title>
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
            max-width: 800px;
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

        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 24px 16px;
        }

        .card {
            background: rgba(30, 41, 59, 0.5);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 24px;
            padding: 32px;
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

        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
        }

        .info-label {
            color: #94a3b8;
        }

        .info-value {
            font-weight: 500;
            word-break: break-all;
        }

        .ip-link {
            color: #60a5fa;
            text-decoration: none;
            cursor: pointer;
        }

        .ip-link:hover {
            text-decoration: underline;
        }

        .actions {
            display: flex;
            gap: 12px;
            margin-top: 24px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 10px 24px;
            border-radius: 40px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
            display: inline-block;
            border: none;
            cursor: pointer;
            font-size: 14px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: white;
            box-shadow: 0 2px 8px rgba(59, 130, 246, 0.2);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(59, 130, 246, 0.3);
        }

        .btn-danger {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            box-shadow: 0 2px 8px rgba(239, 68, 68, 0.2);
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(239, 68, 68, 0.3);
        }

        .btn-success {
            background: linear-gradient(135deg, #22c55e, #16a34a);
            color: white;
        }

        .btn-warning {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
        }

        .btn-secondary {
            background: rgba(51, 65, 85, 0.8);
            color: #cbd5e1;
        }

        .btn-secondary:hover {
            background: #334155;
            color: white;
        }

        .badge-plus {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
            color: white;
        }

        .badge-standard {
            background: rgba(51, 65, 85, 0.8);
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
            color: #94a3b8;
        }

        .badge-admin {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
            color: white;
        }

        .badge-user {
            background: rgba(59, 130, 246, 0.2);
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
            color: #60a5fa;
        }

        .status-deleted {
            color: #f87171;
        }

        .status-active {
            color: #4ade80;
        }

        @media (max-width: 600px) {
            .nav-container {
                flex-direction: column;
                text-align: center;
            }
            
            .info-row {
                flex-direction: column;
                gap: 8px;
            }
            
            .actions {
                flex-direction: column;
            }
            
            .btn {
                text-align: center;
            }
            
            .card {
                padding: 24px;
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
            <a href="/x9p_admin_7k2/index.php" class="nav-btn">Главная</a>
            <a href="/profile/profile.php" class="nav-btn">Профиль</a>
            <a href="/auth/logout.php" class="nav-btn">Выйти</a>
        </div>
    </div>
</nav>

<main class="container">
    <div class="card">
        <h1 class="page-title">Карточка пользователя</h1>
        
        <div class="info-row"><span class="info-label">ID</span><span class="info-value"><?= $user['id'] ?></span></div>
        <div class="info-row"><span class="info-label">UID</span><span class="info-value"><?= htmlspecialchars($user['uid']) ?></span></div>
        <div class="info-row"><span class="info-label">Имя</span><span class="info-value"><?= htmlspecialchars($user['name']) ?></span></div>
        <div class="info-row"><span class="info-label">Email</span><span class="info-value"><?= htmlspecialchars($user['email']) ?></span></div>
        <div class="info-row"><span class="info-label">Роль</span><span class="info-value"><?php if ($user['role'] === 'admin'): ?><span class="badge-admin">Админ</span><?php else: ?><span class="badge-user">Пользователь</span><?php endif; ?></span></div>
        <div class="info-row"><span class="info-label">Подписка</span><span class="info-value"><?= $hasPlus ? '<span class="badge-plus">PLUS</span>' : '<span class="badge-standard">Стандарт</span>' ?></span></div>
        <?php if ($user['subscription_expires']): ?>
            <div class="info-row"><span class="info-label">PLUS до</span><span class="info-value"><?= date('d.m.Y H:i', strtotime($user['subscription_expires'])) ?></span></div>
        <?php endif; ?>
        <div class="info-row"><span class="info-label">Статус</span><span class="info-value <?= $user['is_deleted'] ? 'status-deleted' : 'status-active' ?>"><?= $user['is_deleted'] ? 'Удалён' : 'Активен' ?></span></div>
        <div class="info-row"><span class="info-label">Последний визит</span><span class="info-value"><?= $user['last_seen'] ? date('d.m.Y H:i', $user['last_seen']) . ' (' . timeAgo($user['last_seen']) . ')' : 'никогда' ?></span></div>
        <div class="info-row"><span class="info-label">Постоянный IP</span><span class="info-value"><?php if (!empty($user['main_ip'])): ?><a href="#" class="ip-link" data-ip="<?= htmlspecialchars($user['main_ip']) ?>"><?= htmlspecialchars($user['main_ip']) ?></a><?php else: ?>—<?php endif; ?></span></div>
        <div class="info-row"><span class="info-label">Последний IP</span><span class="info-value"><?php if (!empty($user['last_ip'])): ?><a href="#" class="ip-link" data-ip="<?= htmlspecialchars($user['last_ip']) ?>"><?= htmlspecialchars($user['last_ip']) ?></a><?php else: ?>—<?php endif; ?></span></div>
        <div class="info-row"><span class="info-label">Зарегистрирован</span><span class="info-value"><?= $user['created_at'] ?></span></div>

        <div class="actions">
            <a href="/x9p_admin_7k2/admin_user_plus.php?id=<?= $user['id'] ?>" class="btn btn-primary">Управление PLUS</a>
            
            <?php if (!$user['is_deleted']): ?>
                <a href="/x9p_admin_7k2/admin_user_delete.php?id=<?= $user['id'] ?>" class="btn btn-danger" onclick="return confirm('Мягкое удаление? Пользователь сможет восстановиться.')">Мягкое удаление</a>
            <?php else: ?>
                <a href="/x9p_admin_7k2/restore_user.php?id=<?= $user['id'] ?>" class="btn btn-success" onclick="return confirm('Восстановить пользователя?')">Восстановить</a>
            <?php endif; ?>
            
            <form method="post" style="display: inline-block;" onsubmit="return confirm('Полное удаление! Все данные пользователя будут удалены безвозвратно. Продолжить?');">
                <input type="hidden" name="hard_delete" value="1">
                <button type="submit" class="btn btn-warning">Полное удаление</button>
            </form>
            
            <a href="/x9p_admin_7k2/index.php" class="btn btn-secondary">Назад</a>
        </div>
    </div>
</main>

<script>
    document.querySelectorAll('.ip-link').forEach(link => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            const ip = link.dataset.ip;
            navigator.clipboard.writeText(ip);
            window.open(`https://ipinfo.io/${ip}`, '_blank');
        });
    });

    if (window.navigator.standalone === true || 
        window.matchMedia('(display-mode: standalone)').matches) {
        document.body.style.paddingTop = 'env(safe-area-inset-top)';
    }
</script>

</body>
</html>