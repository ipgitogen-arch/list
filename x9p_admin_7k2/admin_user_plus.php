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

// Обработка формы
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $months = (int)($_POST['months'] ?? 0);
    
    if ($action === 'add_plus' && $months > 0) {
        $expiresAt = date('Y-m-d H:i:s', strtotime("+$months months"));
        $stmt = $pdo->prepare("UPDATE users SET subscription = 'plus', subscription_expires = ?, is_plus = 1 WHERE id = ?");
        $stmt->execute([$expiresAt, $id]);
        $message = "PLUS подписка выдана на $months месяцев";
        
    } elseif ($action === 'remove_plus') {
        $stmt = $pdo->prepare("UPDATE users SET subscription = 'standard', subscription_expires = NULL, is_plus = 0 WHERE id = ?");
        $stmt->execute([$id]);
        $message = "PLUS подписка удалена";
    }
}

// Получаем данные пользователя
$stmt = $pdo->prepare("SELECT id, name, email, role, subscription, subscription_expires, is_plus FROM users WHERE id = ?");
$stmt->execute([$id]);
$user = $stmt->fetch();

if (!$user) {
    header('Location: /x9p_admin_7k2/index.php');
    exit;
}

$hasPlus = ($user['subscription'] === 'plus' || $user['is_plus'] == 1);
$expiresDate = $user['subscription_expires'] ? date('d.m.Y H:i', strtotime($user['subscription_expires'])) : 'не указано';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Управление PLUS — Админка</title>
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
        }

        .plus-card {
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.3);
            border-radius: 20px;
            padding: 24px;
            margin-top: 24px;
        }

        .plus-status {
            font-size: 18px;
            margin-bottom: 20px;
            padding: 12px;
            background: rgba(0, 0, 0, 0.2);
            border-radius: 16px;
            text-align: center;
        }

        .plus-active {
            color: #fbbf24;
            font-weight: 600;
        }

        .plus-inactive {
            color: #94a3b8;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #cbd5e1;
        }

        .form-group select {
            width: 100%;
            padding: 12px 16px;
            border-radius: 40px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            background: rgba(15, 23, 42, 0.8);
            color: #ffffff;
            font-size: 15px;
            outline: none;
            transition: all 0.3s;
        }

        .form-group select:focus {
            border-color: #ef4444;
            background: rgba(15, 23, 42, 1);
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

        .btn-secondary {
            background: rgba(51, 65, 85, 0.8);
            color: #cbd5e1;
        }

        .btn-secondary:hover {
            background: #334155;
            color: white;
        }

        .info-text {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.3);
            padding: 12px 16px;
            border-radius: 12px;
            margin: 15px 0;
            text-align: center;
            color: #4ade80;
        }

        .actions {
            display: flex;
            gap: 12px;
            margin-top: 24px;
            flex-wrap: wrap;
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
            
            .page-title {
                font-size: 24px;
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
            <a href="/x9p_admin_7k2/support.php" class="nav-btn">Поддержка</a>
            <a href="/x9p_admin_7k2/poss.php" class="nav-btn">ПОСС</a>
            <a href="/profile/profile.php" class="nav-btn">Профиль</a>
            <a href="/auth/logout.php" class="nav-btn">Выйти</a>
        </div>
    </div>
</nav>

<main class="container">
    <div class="card">
        <h1 class="page-title">Управление PLUS подпиской</h1>
        
        <div class="info-row">
            <span class="info-label">Пользователь</span>
            <span class="info-value"><?= htmlspecialchars($user['name']) ?> (ID: <?= $user['id'] ?>)</span>
        </div>
        <div class="info-row">
            <span class="info-label">Email</span>
            <span class="info-value"><?= htmlspecialchars($user['email']) ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">Роль</span>
            <span class="info-value"><?= htmlspecialchars($user['role']) ?></span>
        </div>
        
        <div class="plus-card">
            <div class="plus-status">
                Текущий статус: 
                <?php if ($hasPlus): ?>
                    <span class="plus-active">PLUS активна</span>
                    <br><small style="color:#94a3b8;">Действует до: <?= $expiresDate ?></small>
                <?php else: ?>
                    <span class="plus-inactive">Нет PLUS подписки</span>
                <?php endif; ?>
            </div>
            
            <?php if ($message): ?>
                <div class="info-text"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            
            <form method="post">
                <div class="form-group">
                    <label>Выдать PLUS на срок</label>
                    <select name="months">
                        <option value="1">1 месяц</option>
                        <option value="3">3 месяца</option>
                        <option value="6">6 месяцев</option>
                        <option value="12">12 месяцев</option>
                    </select>
                </div>
                <button type="submit" name="action" value="add_plus" class="btn btn-primary" style="width: 100%;">Выдать PLUS</button>
            </form>
            
            <?php if ($hasPlus): ?>
                <form method="post" style="margin-top: 20px;">
                    <button type="submit" name="action" value="remove_plus" class="btn btn-danger" style="width: 100%;" onclick="return confirm('Удалить PLUS подписку у этого пользователя?')">Удалить PLUS</button>
                </form>
            <?php endif; ?>
        </div>
        
        <div class="actions">
            <a href="/x9p_admin_7k2/admin_user_view.php?id=<?= $id ?>" class="btn btn-secondary">← Назад к пользователю</a>
            <a href="/x9p_admin_7k2/index.php" class="btn btn-secondary">← В список пользователей</a>
        </div>
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