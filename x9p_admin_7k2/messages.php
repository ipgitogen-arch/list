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

// Поиск по ID пользователя
$searchUserId = (int)($_GET['user_id'] ?? 0);
$search = trim($_GET['search'] ?? '');
$messages = [];

if ($searchUserId > 0) {
    $stmt = $pdo->prepare("
        SELECT m.*, 
               u1.name as sender_name, u1.uid as sender_uid,
               u2.name as receiver_name, u2.uid as receiver_uid
        FROM messages m
        JOIN users u1 ON u1.id = m.sender_id
        JOIN users u2 ON u2.id = m.receiver_id
        WHERE m.sender_id = ? OR m.receiver_id = ?
        ORDER BY m.created_at DESC
        LIMIT 200
    ");
    $stmt->execute([$searchUserId, $searchUserId]);
    $messages = $stmt->fetchAll();
    
    foreach ($messages as &$msg) {
        if (!empty($msg['encrypted_for_admin'])) {
            $msg['decrypted_content'] = '[Зашифровано]';
        } else {
            $msg['decrypted_content'] = $msg['content'] ?? '[Пустое сообщение]';
        }
    }
} elseif ($search !== '') {
    $stmt = $pdo->prepare("
        SELECT id, name, uid FROM users 
        WHERE name LIKE ? OR uid LIKE ?
        LIMIT 20
    ");
    $like = "%$search%";
    $stmt->execute([$like, $like]);
    $users = $stmt->fetchAll();
}

$stmt = $pdo->query("
    SELECT id, name, uid FROM users 
    ORDER BY id DESC 
    LIMIT 100
");
$allUsers = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Сообщения — Админка</title>
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

        .search-box {
            background: rgba(30, 41, 59, 0.5);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 24px;
            padding: 24px;
            margin-bottom: 24px;
        }

        .search-box h2 {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 16px;
        }

        .search-form {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .search-form select,
        .search-form input {
            padding: 12px 20px;
            border-radius: 40px;
            background: rgba(15, 23, 42, 0.8);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #ffffff;
            font-size: 14px;
            outline: none;
            flex: 1;
            min-width: 200px;
        }

        .search-form select:focus,
        .search-form input:focus {
            border-color: #ef4444;
        }

        .search-form button {
            padding: 12px 28px;
            border-radius: 40px;
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            border: none;
            color: white;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }

        .search-form button:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }

        .users-list {
            background: rgba(30, 41, 59, 0.5);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 24px;
            padding: 20px;
            margin-bottom: 24px;
        }

        .users-list h2 {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 16px;
        }

        .users-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .user-tag {
            background: rgba(30, 41, 59, 0.8);
            padding: 8px 20px;
            border-radius: 40px;
            text-decoration: none;
            color: #cbd5e1;
            font-size: 14px;
            transition: all 0.2s;
            border: 1px solid rgba(255, 255, 255, 0.05);
        }

        .user-tag:hover {
            background: #3b82f6;
            color: white;
            transform: translateY(-1px);
        }

        .messages-table {
            background: rgba(30, 41, 59, 0.5);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 24px;
            padding: 24px;
            overflow-x: auto;
        }

        .messages-table h2 {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 700px;
        }

        th, td {
            padding: 14px 12px;
            text-align: left;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
        }

        th {
            color: #94a3b8;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        tr:hover td {
            background: rgba(255, 255, 255, 0.03);
        }

        .message-content {
            max-width: 400px;
            word-wrap: break-word;
            white-space: pre-wrap;
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

        .empty-state {
            text-align: center;
            padding: 60px 24px;
            color: #64748b;
        }

        @media (max-width: 768px) {
            .nav-container {
                flex-direction: column;
                text-align: center;
            }
            
            .search-form {
                flex-direction: column;
            }
            
            .message-content {
                max-width: 200px;
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
            <a href="/x9p_admin_7k2/channels.php" class="nav-btn">Каналы</a>
            <a href="/x9p_admin_7k2/messages.php" class="nav-btn active">Сообщения</a>
            <a href="/x9p_admin_7k2/support.php" class="nav-btn">Поддержка</a>
            <a href="/x9p_admin_7k2/poss.php" class="nav-btn">ПОСС</a>
            <a href="/profile/profile.php" class="nav-btn">Профиль</a>
            <a href="/auth/logout.php" class="nav-btn">Выйти</a>
        </div>
    </div>
</nav>

<main class="container">
    <h1 class="page-title">Просмотр сообщений</h1>
    
    <div class="search-box">
        <h2>Поиск по пользователю</h2>
        <form class="search-form" method="get">
            <select name="user_id">
                <option value="">-- Выберите пользователя --</option>
                <?php foreach ($allUsers as $u): ?>
                    <option value="<?= $u['id'] ?>" <?= $searchUserId == $u['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($u['name']) ?> (<?= htmlspecialchars($u['uid']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Показать сообщения</button>
        </form>
        
        <form class="search-form" method="get" style="margin-top: 16px;">
            <input type="text" name="search" placeholder="Поиск по имени или UID..." value="<?= htmlspecialchars($search) ?>">
            <button type="submit">Найти пользователя</button>
        </form>
    </div>

    <?php if ($search !== '' && isset($users) && !empty($users)): ?>
        <div class="users-list">
            <h2>Найденные пользователи</h2>
            <div class="users-grid">
                <?php foreach ($users as $u): ?>
                    <a href="?user_id=<?= $u['id'] ?>" class="user-tag">
                        <?= htmlspecialchars($u['name']) ?> (<?= htmlspecialchars($u['uid']) ?>)
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($searchUserId > 0): ?>
        <div class="messages-table">
            <h2>Сообщения пользователя</h2>
            <?php if (empty($messages)): ?>
                <div class="empty-state">Нет сообщений</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Отправитель</th>
                            <th>Получатель</th>
                            <th>Сообщение</th>
                            <th>Дата</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($messages as $msg): ?>
                            <tr>
                                <td><?= $msg['id'] ?></td>
                                <td>
                                    <?= htmlspecialchars($msg['sender_name']) ?><br>
                                    <small style="color:#64748b;"><?= htmlspecialchars($msg['sender_uid']) ?></small>
                                </td>
                                <td>
                                    <?= htmlspecialchars($msg['receiver_name']) ?><br>
                                    <small style="color:#64748b;"><?= htmlspecialchars($msg['receiver_uid']) ?></small>
                                </td>
                                <td class="message-content">
                                    <?= nl2br(htmlspecialchars($msg['decrypted_content'])) ?>
                                 </td>
                                <td style="white-space: nowrap;"><?= $msg['created_at'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    
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