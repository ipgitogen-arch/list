<?php
session_start();
require __DIR__ . '/../inc/dd_bb.php';

// Проверка прав администратора
$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    header('Location: /auth/login.php');
    exit;
}

$stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user || $user['role'] !== 'admin') {
    header('Location: /index.php');
    exit;
}

// Обработка сохранения
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_access'])) {
    foreach ($_POST['access'] as $pageName => $isClosed) {
        $stmt = $pdo->prepare("UPDATE admin_page_access SET is_closed = ? WHERE page_name = ?");
        $stmt->execute([$isClosed, $pageName]);
    }
    $success = 'Настройки сохранены';
}

// Получаем все страницы
$stmt = $pdo->query("SELECT * FROM admin_page_access ORDER BY page_name");
$pages = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Управление доступом — Админка</title>
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
            max-width: 1000px;
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
            max-width: 1000px;
            margin: 0 auto;
            padding: 24px 16px;
        }

        .card {
            background: rgba(30, 41, 59, 0.5);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 24px;
            overflow: hidden;
        }

        .card-header {
            padding: 20px 24px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
            background: rgba(15, 23, 42, 0.5);
        }

        .card-header h1 {
            font-size: 24px;
            font-weight: 700;
            letter-spacing: -0.5px;
            background: linear-gradient(135deg, #ef4444, #dc2626);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .card-header p {
            font-size: 12px;
            color: #64748b;
            margin-top: 4px;
        }

        .page-list {
            padding: 0;
        }

        .page-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 24px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
            transition: background 0.2s;
        }

        .page-item:hover {
            background: rgba(255, 255, 255, 0.03);
        }

        .page-name {
            font-weight: 600;
            font-size: 15px;
        }

        .page-title {
            font-size: 12px;
            color: #64748b;
            margin-left: 8px;
        }

        .toggle {
            position: relative;
            display: inline-block;
            width: 52px;
            height: 28px;
        }

        .toggle input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #334155;
            transition: 0.2s;
            border-radius: 28px;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 24px;
            width: 24px;
            left: 2px;
            bottom: 2px;
            background-color: white;
            transition: 0.2s;
            border-radius: 50%;
        }

        input:checked + .slider {
            background-color: #ef4444;
        }

        input:checked + .slider:before {
            transform: translateX(24px);
        }

        .btn-save {
            width: calc(100% - 48px);
            margin: 20px 24px;
            padding: 14px;
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            border: none;
            border-radius: 40px;
            color: #fff;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-save:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(59, 130, 246, 0.3);
        }

        .success {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.3);
            color: #4ade80;
            padding: 12px 16px;
            border-radius: 12px;
            margin: 20px 24px;
            text-align: center;
        }

        .back-link {
            display: inline-block;
            margin-top: 20px;
            color: #94a3b8;
            text-decoration: none;
            transition: color 0.2s;
        }

        .back-link:hover {
            color: #3b82f6;
        }

        @media (max-width: 600px) {
            .nav-container {
                flex-direction: column;
                text-align: center;
            }
            
            .page-item {
                flex-direction: column;
                gap: 12px;
                text-align: center;
            }
            
            .btn-save {
                width: calc(100% - 32px);
                margin: 16px;
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
        <div class="card-header">
            <h1>Управление доступом к страницам</h1>
            <p>Администраторы всегда имеют полный доступ</p>
        </div>
        
        <?php if (isset($success)): ?>
            <div class="success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="page-list">
                <?php foreach ($pages as $page): ?>
                    <div class="page-item">
                        <div>
                            <span class="page-name"><?= htmlspecialchars($page['page_name']) ?></span>
                            <?php if ($page['page_title']): ?>
                                <span class="page-title">(<?= htmlspecialchars($page['page_title']) ?>)</span>
                            <?php endif; ?>
                        </div>
                        <label class="toggle">
                            <input type="checkbox" name="access[<?= $page['page_name'] ?>]" value="1" <?= $page['is_closed'] ? 'checked' : '' ?>>
                            <span class="slider"></span>
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="submit" name="save_access" class="btn-save">Сохранить настройки</button>
        </form>
    </div>
    
    <a href="/x9p_admin_7k2/index.php" class="back-link">← Назад в админ-панель</a>
</main>

<script>
    if (window.navigator.standalone === true || 
        window.matchMedia('(display-mode: standalone)').matches) {
        document.body.style.paddingTop = 'env(safe-area-inset-top)';
    }
</script>

</body>
</html>