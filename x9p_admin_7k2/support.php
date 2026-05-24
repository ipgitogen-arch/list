<?php
session_start();
require __DIR__ . '/../inc/dd_bb.php';

// Проверка авторизации
if (!isset($_SESSION['user_id'])) {
    header('Location: /auth/login.php');
    exit;
}

$userId = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
$stmt->execute([$userId]);
$me = $stmt->fetch();

if (!$me || $me['role'] !== 'admin') {
    http_response_code(403);
    exit('Доступ запрещён');
}

// Получаем обращения
$threads = [];
try {
    $stmt = $pdo->query("
        SELECT st.id, st.user_id, st.subject, st.status, st.created_at, u.name, u.uid
        FROM support_threads st
        JOIN users u ON u.id = st.user_id
        ORDER BY st.id DESC
    ");
    $threads = $stmt->fetchAll();
} catch (PDOException $e) {
    $threads = [];
}

$threadId = (int)($_GET['thread_id'] ?? 0);
$messages = [];

if ($threadId > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT sm.*, u.name
            FROM support_messages sm
            LEFT JOIN users u ON u.id = sm.sender_id
            WHERE sm.thread_id = ?
            ORDER BY sm.id ASC
        ");
        $stmt->execute([$threadId]);
        $messages = $stmt->fetchAll();
    } catch (PDOException $e) {
        $messages = [];
    }
}

// Отправка сообщения
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $threadId > 0 && isset($_POST['message'])) {
    $message = trim($_POST['message']);
    if ($message !== '') {
        try {
            $stmt = $pdo->prepare("INSERT INTO support_messages (thread_id, sender_type, sender_id, message, created_at) VALUES (?, 'admin', ?, ?, NOW())");
            $stmt->execute([$threadId, $userId, $message]);
        } catch (PDOException $e) {
            // Ошибка
        }
    }
    header('Location: /x9p_admin_7k2/support.php?thread_id=' . $threadId);
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Поддержка — Админка</title>
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
            display: grid;
            grid-template-columns: 320px 1fr;
            gap: 24px;
        }

        .threads-panel, .chat-panel {
            background: rgba(30, 41, 59, 0.5);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 24px;
            padding: 20px;
        }

        .panel-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 20px;
        }

        .thread-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .thread-item {
            padding: 14px;
            border-radius: 16px;
            transition: all 0.2s;
            text-decoration: none;
            display: block;
            color: #f1f5f9;
            background: rgba(15, 23, 42, 0.5);
        }

        .thread-item:hover {
            background: rgba(59, 130, 246, 0.2);
            transform: translateX(4px);
        }

        .thread-name {
            font-weight: 600;
            margin-bottom: 4px;
        }

        .thread-date {
            font-size: 11px;
            color: #64748b;
        }

        .messages {
            max-height: 500px;
            overflow-y: auto;
            margin-bottom: 20px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .message {
            padding: 12px 16px;
            border-radius: 20px;
            max-width: 80%;
        }

        .message.user {
            background: rgba(51, 65, 85, 0.8);
            align-self: flex-start;
            border-bottom-left-radius: 4px;
        }

        .message.admin {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            align-self: flex-end;
            border-bottom-right-radius: 4px;
        }

        .message-name {
            font-size: 11px;
            color: #94a3b8;
            margin-bottom: 4px;
        }

        .message-text {
            word-wrap: break-word;
            font-size: 14px;
        }

        .message-time {
            font-size: 10px;
            color: #94a3b8;
            margin-top: 4px;
            text-align: right;
        }

        .input-area {
            display: flex;
            gap: 12px;
            margin-top: 16px;
        }

        .input-area input {
            flex: 1;
            padding: 12px 20px;
            border-radius: 40px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            background: rgba(15, 23, 42, 0.8);
            color: #ffffff;
            font-size: 14px;
            outline: none;
        }

        .input-area input:focus {
            border-color: #3b82f6;
        }

        .input-area button {
            padding: 12px 28px;
            border-radius: 40px;
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            border: none;
            color: white;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }

        .input-area button:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
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
            
            .container {
                grid-template-columns: 1fr;
            }
            
            .message {
                max-width: 90%;
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
            <a href="/x9p_admin_7k2/messages.php" class="nav-btn">Сообщения</a>
            <a href="/x9p_admin_7k2/support.php" class="nav-btn active">Поддержка</a>
            <a href="/x9p_admin_7k2/poss.php" class="nav-btn">ПОСС</a>
            <a href="/profile/profile.php" class="nav-btn">Профиль</a>
            <a href="/auth/logout.php" class="nav-btn">Выйти</a>
        </div>
    </div>
</nav>

<main class="container">
    <div class="threads-panel">
        <h2 class="panel-title">Обращения в поддержку</h2>
        <div class="thread-list">
            <?php if (empty($threads)): ?>
                <div class="empty-state">
                    <p>Нет обращений</p>
                </div>
            <?php else: ?>
                <?php foreach ($threads as $t): ?>
                    <a href="/x9p_admin_7k2/support.php?thread_id=<?= (int)$t['id'] ?>" class="thread-item">
                        <div class="thread-name">
                            <?= htmlspecialchars($t['name']) ?>
                        </div>
                        <div class="thread-date">ID: <?= htmlspecialchars($t['uid']) ?> | <?= date('d.m.Y H:i', strtotime($t['created_at'])) ?></div>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="chat-panel">
        <?php if ($threadId > 0): ?>
            <h2 class="panel-title">Чат с пользователем</h2>
            <div class="messages">
                <?php if (empty($messages)): ?>
                    <div class="empty-state">Нет сообщений</div>
                <?php else: ?>
                    <?php foreach ($messages as $m): ?>
                        <div class="message <?= $m['sender_type'] === 'user' ? 'user' : 'admin' ?>">
                            <div class="message-name"><?= $m['sender_type'] === 'user' ? htmlspecialchars($m['name']) : 'Администратор' ?></div>
                            <div class="message-text"><?= nl2br(htmlspecialchars($m['message'])) ?></div>
                            <div class="message-time"><?= date('d.m.Y H:i', strtotime($m['created_at'])) ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <form method="post">
                <div class="input-area">
                    <input type="text" name="message" placeholder="Напишите ответ..." required autocomplete="off">
                    <button type="submit">Отправить</button>
                </div>
            </form>
        <?php else: ?>
            <div class="empty-state">
                <p>Выберите обращение из списка слева</p>
            </div>
        <?php endif; ?>
    </div>
</main>

<script>
    if (window.navigator.standalone === true || 
        window.matchMedia('(display-mode: standalone)').matches) {
        document.body.style.paddingTop = 'env(safe-area-inset-top)';
    }
    
    const messagesDiv = document.querySelector('.messages');
    if (messagesDiv) {
        messagesDiv.scrollTop = messagesDiv.scrollHeight;
    }
</script>

</body>
</html>