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

// Получение статуса регистрации и входа
$stmt = $pdo->prepare("SELECT login_enabled, registration_enabled FROM settings LIMIT 1");
$stmt->execute();
$access = $stmt->fetch();
if (!$access) {
    $access = ['login_enabled' => 1, 'registration_enabled' => 1];
}

// Обработка изменения статуса доступа
$accessSuccess = '';
$accessError = '';
if (isset($_POST['save_access'])) {
    $loginEnabled = (int)$_POST['login_enabled'];
    $regEnabled = (int)$_POST['registration_enabled'];
    
    $stmt = $pdo->prepare("UPDATE settings SET login_enabled = ?, registration_enabled = ?");
    if ($stmt->execute([$loginEnabled, $regEnabled])) {
        $access['login_enabled'] = $loginEnabled;
        $access['registration_enabled'] = $regEnabled;
        $accessSuccess = 'Настройки доступа сохранены';
    } else {
        $accessError = 'Ошибка сохранения настроек';
    }
}

// Статистика
$totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$onlineUsers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE last_seen IS NOT NULL AND last_seen > " . (time() - 300))->fetchColumn();
$offlineUsers = $totalUsers - $onlineUsers;
$deletedUsers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_deleted=1")->fetchColumn();

// Поиск по всем критериям
$search = trim($_GET['search'] ?? '');
if ($search !== '') {
    $stmt = $pdo->prepare("
        SELECT id, name, email, uid, role, subscription, is_plus, is_deleted, created_at 
        FROM users 
        WHERE name LIKE ? OR email LIKE ? OR uid LIKE ? OR id = ?
        ORDER BY id DESC
    ");
    $like = "%$search%";
    $idSearch = is_numeric($search) ? (int)$search : 0;
    $stmt->execute([$like, $like, $like, $idSearch]);
} else {
    $stmt = $pdo->query("
        SELECT id, name, email, uid, role, subscription, is_plus, is_deleted, created_at 
        FROM users 
        ORDER BY id DESC 
        LIMIT 200
    ");
}
$users = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Админ-панель — Лист</title>
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
            max-width: 1400px;
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
            box-shadow: 0 2px 8px rgba(239, 68, 68, 0.3);
        }

        .container {
            max-width: 1400px;
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

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 32px;
        }

        .stat-card {
            background: rgba(30, 41, 59, 0.5);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 20px;
            padding: 24px;
            text-align: center;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            background: rgba(30, 41, 59, 0.7);
            border-color: rgba(239, 68, 68, 0.3);
        }

        .stat-number {
            font-size: 36px;
            font-weight: 700;
            margin-bottom: 8px;
            background: linear-gradient(135deg, #ffffff, #94a3b8);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .stat-label {
            color: #94a3b8;
            font-size: 14px;
            font-weight: 500;
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
            transition: all 0.3s;
        }

        .search-box input:focus {
            border-color: #ef4444;
            background: rgba(30, 41, 59, 1);
        }

        .panel {
            background: rgba(30, 41, 59, 0.5);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 24px;
            padding: 24px;
            overflow-x: auto;
        }

        .panel-title {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 20px;
            color: #f1f5f9;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }

        th, td {
            padding: 14px 12px;
            text-align: left;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
        }

        th {
            color: #94a3b8;
            font-weight: 600;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        tr:hover td {
            background: rgba(255, 255, 255, 0.03);
        }

        .small-btn {
            display: inline-block;
            padding: 6px 16px;
            background: rgba(59, 130, 246, 0.2);
            border: 1px solid rgba(59, 130, 246, 0.3);
            border-radius: 20px;
            text-decoration: none;
            color: #60a5fa;
            font-size: 12px;
            font-weight: 500;
            transition: all 0.2s;
        }

        .small-btn:hover {
            background: #3b82f6;
            color: white;
            border-color: #3b82f6;
            transform: translateY(-1px);
        }

        .status-deleted {
            color: #f87171;
        }

        .status-active {
            color: #4ade80;
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

        .empty-state {
            text-align: center;
            padding: 60px 24px;
            color: #64748b;
        }

        /* Стили для блока управления доступом */
        .card {
            background: rgba(30, 41, 59, 0.5);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 24px;
            margin-bottom: 24px;
            overflow: hidden;
        }

        .card-header {
            padding: 20px 24px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
        }

        .card-header h2 {
            font-size: 20px;
            font-weight: 600;
            margin: 0;
        }

        .card-content {
            padding: 20px 24px;
        }

        .radio-group {
            display: flex;
            gap: 24px;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .radio-group label {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            color: #cbd5e1;
        }

        .radio-group input[type="radio"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: #3b82f6;
        }

        .btn-primary {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: white;
            padding: 10px 24px;
            border-radius: 40px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(59, 130, 246, 0.2);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(59, 130, 246, 0.3);
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

        .error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #f87171;
            padding: 12px 16px;
            border-radius: 12px;
            margin-bottom: 20px;
            text-align: center;
        }

        @media (max-width: 768px) {
            .nav-container {
                flex-direction: column;
                text-align: center;
            }
            
            .stats {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .page-title {
                font-size: 24px;
            }
        }

        @media (max-width: 600px) {
            .stats {
                grid-template-columns: 1fr;
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
            <a href="/x9p_admin_7k2/index.php" class="nav-btn active">Пользователи</a>
            <a href="/x9p_admin_7k2/channels.php" class="nav-btn">Каналы</a>
            <a href="/x9p_admin_7k2/messages.php" class="nav-btn">Сообщения</a>
            <a href="/x9p_admin_7k2/support.php" class="nav-btn">Поддержка</a>
            <a href="/x9p_admin_7k2/poss.php" class="nav-btn">ПОСС</a>
            <a href="/profile/profile.php" class="nav-btn">Профиль</a>
            <a href="/auth/logout.php" class="nav-btn">Выйти</a>
        </div>
    </div>
</nav>

<main class="container">
    <h1 class="page-title">Панель администратора</h1>

    <div class="stats">
        <div class="stat-card">
            <div class="stat-number"><?= $totalUsers ?></div>
            <div class="stat-label">Пользователей</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?= $onlineUsers ?></div>
            <div class="stat-label">Онлайн</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?= $offlineUsers ?></div>
            <div class="stat-label">Оффлайн</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?= $deletedUsers ?></div>
            <div class="stat-label">Удалено</div>
        </div>
    </div>

    <!-- Управление доступом -->
    <div class="card">
        <div class="card-header">
            <h2>Управление доступом</h2>
        </div>
        <div class="card-content">
            <?php if ($accessSuccess): ?>
                <div class="success"><?= htmlspecialchars($accessSuccess) ?></div>
            <?php endif; ?>
            <?php if ($accessError): ?>
                <div class="error"><?= htmlspecialchars($accessError) ?></div>
            <?php endif; ?>
            
            <form method="post">
                <div class="radio-group">
                    <label>
                        <input type="radio" name="login_enabled" value="1" <?= $access['login_enabled'] == 1 ? 'checked' : '' ?>> 
                        Вход открыт
                    </label>
                    <label>
                        <input type="radio" name="login_enabled" value="0" <?= $access['login_enabled'] == 0 ? 'checked' : '' ?>> 
                        Вход закрыт
                    </label>
                </div>
                <div class="radio-group">
                    <label>
                        <input type="radio" name="registration_enabled" value="1" <?= $access['registration_enabled'] == 1 ? 'checked' : '' ?>> 
                        Регистрация открыта
                    </label>
                    <label>
                        <input type="radio" name="registration_enabled" value="0" <?= $access['registration_enabled'] == 0 ? 'checked' : '' ?>> 
                        Регистрация закрыта
                    </label>
                </div>
                <button type="submit" name="save_access" class="btn-primary">Сохранить настройки</button>
            </form>
        </div>
    </div>

    <div class="search-box">
        <form method="get">
            <input type="text" name="search" placeholder="Поиск по имени, email, ID, UID..." value="<?= htmlspecialchars($search) ?>">
        </form>
    </div>

    <div class="panel">
        <h2 class="panel-title">Список пользователей</h2>
        <?php if (!$users): ?>
            <div class="empty-state">
                <p>Пользователи не найдены</p>
            </div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>UID</th>
                            <th>Имя</th>
                            <th>Email</th>
                            <th>Подписка</th>
                            <th>Роль</th>
                            <th>Статус</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                            <tr>
                                <td><?= (int)$u['id'] ?></td>
                                <td><code style="color: #60a5fa;"><?= htmlspecialchars((string)$u['uid']) ?></code></td>
                                <td><strong><?= htmlspecialchars((string)$u['name']) ?></strong></td>
                                <td><?= htmlspecialchars((string)$u['email']) ?></td>
                                <td>
                                    <?php 
                                    $hasPlus = ($u['subscription'] === 'plus' || !empty($u['is_plus']));
                                    if ($hasPlus): ?>
                                        <span class="badge-plus">PLUS</span>
                                    <?php else: ?>
                                        <span class="badge-standard">Стандарт</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($u['role'] === 'admin'): ?>
                                        <span class="badge-admin">Админ</span>
                                    <?php else: ?>
                                        <span class="badge-user">Пользователь</span>
                                    <?php endif; ?>
                                </td>
                                <td class="<?= (int)$u['is_deleted'] === 1 ? 'status-deleted' : 'status-active' ?>">
                                    <?= (int)$u['is_deleted'] === 1 ? 'Удалён' : 'Активен' ?>
                                </td>
                                <td><a class="small-btn" href="/x9p_admin_7k2/users.php?id=<?= (int)$u['id'] ?>">Открыть</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
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