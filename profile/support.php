<?php
session_start();
require __DIR__ . '/../inc/dd_bb.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /auth/login.php');
    exit;
}

$userId = $_SESSION['user_id'];

// Создаём таблицы если их нет
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `support_threads` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `subject` varchar(255) DEFAULT NULL,
            `status` enum('open','closed') DEFAULT 'open',
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ");
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `support_messages` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `thread_id` int(11) NOT NULL,
            `sender_type` enum('user','admin') NOT NULL,
            `sender_id` int(11) DEFAULT NULL,
            `message` text NOT NULL,
            `is_read` tinyint(1) DEFAULT 0,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_thread_id` (`thread_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ");
} catch (PDOException $e) {
    // Таблицы уже существуют
}

// Обработка создания нового обращения
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_thread'])) {
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');
    
    if ($message !== '') {
        $stmt = $pdo->prepare("INSERT INTO support_threads (user_id, subject, status, created_at) VALUES (?, ?, 'open', NOW())");
        $stmt->execute([$userId, $subject]);
        $threadId = $pdo->lastInsertId();
        
        $stmt = $pdo->prepare("INSERT INTO support_messages (thread_id, sender_type, sender_id, message, created_at) VALUES (?, 'user', ?, ?, NOW())");
        $stmt->execute([$threadId, $userId, $message]);
        
        header('Location: /profile/support.php?thread_id=' . $threadId);
        exit;
    }
}

// Обработка нового сообщения
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $threadId = (int)$_POST['thread_id'];
    $message = trim($_POST['message'] ?? '');
    
    if ($message !== '' && $threadId > 0) {
        $stmt = $pdo->prepare("INSERT INTO support_messages (thread_id, sender_type, sender_id, message, created_at) VALUES (?, 'user', ?, ?, NOW())");
        $stmt->execute([$threadId, $userId, $message]);
        
        $stmt = $pdo->prepare("UPDATE support_threads SET updated_at = NOW(), status = 'open' WHERE id = ?");
        $stmt->execute([$threadId]);
        
        header('Location: /profile/support.php?thread_id=' . $threadId);
        exit;
    }
}

// Получаем обращения пользователя
$threads = [];
$stmt = $pdo->prepare("
    SELECT st.*, 
           (SELECT COUNT(*) FROM support_messages WHERE thread_id = st.id AND is_read = 0 AND sender_type = 'admin') as unread_count
    FROM support_threads st
    WHERE st.user_id = ?
    ORDER BY st.id DESC
");
$stmt->execute([$userId]);
$threads = $stmt->fetchAll();

$threadId = (int)($_GET['thread_id'] ?? 0);
$messages = [];

if ($threadId > 0) {
    $stmt = $pdo->prepare("
        SELECT sm.*, 
               CASE WHEN sm.sender_type = 'admin' THEN 'Администратор' ELSE u.name END as sender_name
        FROM support_messages sm
        LEFT JOIN users u ON u.id = sm.sender_id
        WHERE sm.thread_id = ?
        ORDER BY sm.id ASC
    ");
    $stmt->execute([$threadId]);
    $messages = $stmt->fetchAll();
    
    // Отмечаем сообщения как прочитанные
    $stmt = $pdo->prepare("UPDATE support_messages SET is_read = 1 WHERE thread_id = ? AND sender_type = 'admin'");
    $stmt->execute([$threadId]);
}

$success = $_GET['success'] ?? '';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Поддержка — Лист</title>
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
            max-width: 900px;
            margin: 0 auto;
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }

        .logo-icon svg { width: 28px; height: 28px; }
        .logo-text {
            font-size: 22px;
            font-weight: 600;
            background: linear-gradient(135deg, #ffffff, #94a3b8);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .nav-link {
            color: #cbd5e1;
            text-decoration: none;
            font-size: 15px;
            font-weight: 500;
            padding: 8px 16px;
            border-radius: 40px;
            background: #1e293b;
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
            padding: 24px 16px;
        }

        .page-title {
            font-size: 28px;
            font-weight: 700;
            letter-spacing: -0.5px;
            margin-bottom: 24px;
            background: linear-gradient(135deg, #ffffff, #94a3b8);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .card {
            background: rgba(30, 41, 59, 0.5);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 24px;
            padding: 24px;
            margin-bottom: 24px;
        }

        .card-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 16px;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #cbd5e1;
            font-size: 14px;
            font-weight: 500;
        }

        .form-group input, .form-group textarea {
            width: 100%;
            padding: 12px 16px;
            border-radius: 16px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            background: rgba(15, 23, 42, 0.8);
            color: #ffffff;
            font-size: 15px;
            font-family: inherit;
            outline: none;
        }

        .form-group input:focus, .form-group textarea:focus {
            border-color: #3b82f6;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: white;
            padding: 12px 24px;
            border-radius: 40px;
            font-size: 14px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(59, 130, 246, 0.3);
        }

        .btn-secondary {
            background: rgba(51, 65, 85, 0.8);
            color: #cbd5e1;
            padding: 10px 20px;
            border-radius: 40px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s;
            display: inline-block;
        }

        .btn-secondary:hover {
            background: #334155;
            color: white;
        }

        .thread-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .thread-item {
            background: rgba(15, 23, 42, 0.5);
            border-radius: 16px;
            padding: 16px;
            text-decoration: none;
            color: inherit;
            display: block;
            transition: all 0.2s;
            border: 1px solid rgba(255, 255, 255, 0.05);
        }

        .thread-item:hover {
            background: rgba(59, 130, 246, 0.1);
            border-color: rgba(59, 130, 246, 0.3);
        }

        .thread-subject {
            font-weight: 600;
            margin-bottom: 4px;
        }

        .thread-date {
            font-size: 12px;
            color: #94a3b8;
        }

        .thread-status {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
            margin-top: 8px;
        }

        .status-open {
            background: #22c55e;
            color: white;
        }

        .status-closed {
            background: #64748b;
            color: white;
        }

        .unread-badge {
            background: #ef4444;
            color: white;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 11px;
            margin-left: 8px;
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
        }

        .empty-state {
            text-align: center;
            padding: 60px 24px;
            color: #64748b;
        }

        .back-link {
            display: inline-block;
            margin-top: 16px;
            color: #94a3b8;
            text-decoration: none;
        }

        .back-link:hover {
            color: #3b82f6;
        }

        @media (max-width: 768px) {
            .nav-container {
                flex-direction: column;
                gap: 12px;
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
        <a href="/" class="logo">
            <div class="logo-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="28" height="28" fill="none">
                    <path d="M12 3C12 3 5 8 5 15C5 19 8 22 12 22C16 22 19 19 19 15C19 8 12 3 12 3Z" fill="#3b82f6" stroke="#3b82f6" stroke-width="1.2"/>
                    <path d="M12 3V22" stroke="#ffffff" stroke-width="0.8" opacity="0.6"/>
                    <path d="M9 10L12 13L15 10" stroke="#ffffff" stroke-width="0.8" fill="none" opacity="0.6"/>
                    <path d="M8 15L12 18L16 15" stroke="#ffffff" stroke-width="0.8" fill="none" opacity="0.6"/>
                </svg>
            </div>
            <span class="logo-text">Лист</span>
        </a>
        <a href="/profile/profile.php" class="nav-link">Профиль</a>
    </div>
</nav>

<main class="container">
    <h1 class="page-title">Поддержка</h1>

    <?php if ($success === 'sent'): ?>
        <div class="success-message" style="background:rgba(34,197,94,0.1); border:1px solid #22c55e; color:#4ade80; padding:12px; border-radius:12px; margin-bottom:20px; text-align:center;">
            Сообщение отправлено
        </div>
    <?php endif; ?>

    <?php if ($threadId > 0): ?>
        <!-- Просмотр чата -->
        <div class="card">
            <h2 class="card-title">Чат с поддержкой</h2>
            <div class="messages">
                <?php if (empty($messages)): ?>
                    <div class="empty-state">Нет сообщений</div>
                <?php else: ?>
                    <?php foreach ($messages as $m): ?>
                        <div class="message <?= $m['sender_type'] ?>">
                            <div class="message-name"><?= htmlspecialchars($m['sender_name']) ?></div>
                            <div class="message-text"><?= nl2br(htmlspecialchars($m['message'])) ?></div>
                            <div class="message-time"><?= date('d.m.Y H:i', strtotime($m['created_at'])) ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <form method="post">
                <input type="hidden" name="thread_id" value="<?= $threadId ?>">
                <div class="input-area">
                    <input type="text" name="message" placeholder="Напишите сообщение..." required autocomplete="off">
                    <button type="submit" name="send_message">Отправить</button>
                </div>
            </form>
            <a href="/profile/support.php" class="back-link">← Назад к обращениям</a>
        </div>
    <?php else: ?>
        <!-- Создание нового обращения -->
        <div class="card">
            <h2 class="card-title">Новое обращение</h2>
            <form method="post">
                <div class="form-group">
                    <label>Тема (необязательно)</label>
                    <input type="text" name="subject" placeholder="Кратко опишите проблему">
                </div>
                <div class="form-group">
                    <label>Сообщение</label>
                    <textarea name="message" placeholder="Опишите вашу проблему подробно..." required></textarea>
                </div>
                <button type="submit" name="create_thread" class="btn-primary">Отправить</button>
            </form>
        </div>

        <!-- Список обращений -->
        <?php if (!empty($threads)): ?>
            <div class="card">
                <h2 class="card-title">Мои обращения</h2>
                <div class="thread-list">
                    <?php foreach ($threads as $t): ?>
                        <a href="/profile/support.php?thread_id=<?= $t['id'] ?>" class="thread-item">
                            <div class="thread-subject">
                                <?= htmlspecialchars($t['subject'] ?? 'Обращение #' . $t['id']) ?>
                                <?php if ($t['unread_count'] > 0): ?>
                                    <span class="unread-badge"><?= $t['unread_count'] ?> новых</span>
                                <?php endif; ?>
                            </div>
                            <div class="thread-date"><?= date('d.m.Y H:i', strtotime($t['created_at'])) ?></div>
                            <div class="thread-status status-<?= $t['status'] ?>"><?= $t['status'] === 'open' ? 'Открыто' : 'Закрыто' ?></div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
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