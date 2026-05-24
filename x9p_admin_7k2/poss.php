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

// Добавление слова
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_word'])) {
    $word = trim($_POST['word'] ?? '');
    if ($word !== '') {
        $stmt = $pdo->prepare("INSERT INTO poss_words (word) VALUES (?) ON DUPLICATE KEY UPDATE word=word");
        $stmt->execute([$word]);
    }
    header('Location: /x9p_admin_7k2/poss.php');
    exit;
}

// Удаление слова
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM poss_words WHERE id = ?");
    $stmt->execute([$id]);
    header('Location: /x9p_admin_7k2/poss.php');
    exit;
}

// Получаем список запрещённых слов
$words = $pdo->query("SELECT id, word, created_at FROM poss_words ORDER BY id DESC")->fetchAll();

// Получаем логи срабатываний
$logs = $pdo->query("
    SELECT pl.*, 
           u1.name as sender_name, 
           u1.uid as sender_uid,
           u2.name as receiver_name,
           u2.uid as receiver_uid
    FROM poss_logs pl
    LEFT JOIN users u1 ON u1.id = pl.sender_id
    LEFT JOIN users u2 ON u2.id = pl.receiver_id
    ORDER BY pl.id DESC 
    LIMIT 200
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>ПОСС — Админка</title>
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
            display: grid;
            grid-template-columns: 1fr 1.5fr;
            gap: 24px;
        }

        .card {
            background: rgba(30, 41, 59, 0.5);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 24px;
            padding: 24px;
        }

        .card-title {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .word-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-top: 20px;
            max-height: 400px;
            overflow-y: auto;
        }

        .word-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 16px;
            background: rgba(30, 41, 59, 0.8);
            border-radius: 16px;
            transition: all 0.2s;
        }

        .word-item:hover {
            background: #334155;
        }

        .word-text {
            font-weight: 600;
            font-size: 16px;
            color: #f87171;
        }

        .word-date {
            font-size: 11px;
            color: #64748b;
            margin-top: 4px;
        }

        .delete-word-btn {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            padding: 6px 16px;
            border-radius: 20px;
            text-decoration: none;
            color: #fff;
            font-size: 12px;
            font-weight: 500;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
        }

        .delete-word-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
        }

        .input-group {
            display: flex;
            gap: 12px;
            margin-top: 20px;
        }

        .input-group input {
            flex: 1;
            padding: 12px 16px;
            border-radius: 40px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            background: rgba(15, 23, 42, 0.8);
            color: #ffffff;
            font-size: 14px;
            outline: none;
        }

        .input-group input:focus {
            border-color: #ef4444;
        }

        .input-group button {
            padding: 12px 24px;
            border-radius: 40px;
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            border: none;
            color: #fff;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }

        .input-group button:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }

        .logs-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
            max-height: 600px;
            overflow-y: auto;
        }

        .log-item {
            background: rgba(30, 41, 59, 0.8);
            border-radius: 16px;
            padding: 16px;
            border-left: 3px solid #ef4444;
            transition: all 0.2s;
        }

        .log-item:hover {
            background: #334155;
        }

        .log-word {
            display: inline-block;
            background: linear-gradient(135deg, #ef4444, #dc2626);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .log-message {
            font-size: 14px;
            margin-bottom: 8px;
            word-break: break-word;
        }

        .log-meta {
            font-size: 12px;
            color: #94a3b8;
            margin-top: 8px;
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
        }

        .user-uid {
            background: rgba(15, 23, 42, 0.8);
            padding: 4px 10px;
            border-radius: 20px;
            font-family: monospace;
        }

        .empty-state {
            text-align: center;
            padding: 60px 24px;
            color: #64748b;
        }

        @media (max-width: 900px) {
            .container {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 600px) {
            .nav-container {
                flex-direction: column;
                text-align: center;
            }
            
            .word-item {
                flex-direction: column;
                gap: 8px;
                text-align: center;
            }
            
            .log-meta {
                flex-direction: column;
                gap: 8px;
            }
            
            .input-group {
                flex-direction: column;
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
            <a href="/x9p_admin_7k2/support.php" class="nav-btn">Поддержка</a>
            <a href="/x9p_admin_7k2/poss.php" class="nav-btn active">ПОСС</a>
            <a href="/profile/profile.php" class="nav-btn">Профиль</a>
            <a href="/auth/logout.php" class="nav-btn">Выйти</a>
        </div>
    </div>
</nav>

<main class="container">
    <!-- Левая колонка: список запрещённых слов -->
    <div class="card">
        <h2 class="card-title">
            Запрещённые слова (ПОСС)
        </h2>
        <form method="post">
            <div class="input-group">
                <input type="text" name="word" placeholder="Новое слово..." required autocomplete="off">
                <button type="submit" name="add_word">Добавить</button>
            </div>
        </form>
        
        <div class="word-list">
            <?php if (empty($words)): ?>
                <div class="empty-state">
                    <p>Список запрещённых слов пуст</p>
                    <p style="font-size: 12px; margin-top: 8px;">Добавьте слова, которые нужно фильтровать</p>
                </div>
            <?php else: ?>
                <?php foreach ($words as $w): ?>
                    <div class="word-item">
                        <div>
                            <div class="word-text"><?= htmlspecialchars($w['word']) ?></div>
                            <div class="word-date">Добавлено: <?= date('d.m.Y H:i', strtotime($w['created_at'])) ?></div>
                        </div>
                        <a href="?delete=<?= $w['id'] ?>" class="delete-word-btn" onclick="return confirm('Удалить слово &quot;<?= htmlspecialchars($w['word']) ?>&quot;?')">Удалить</a>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Правая колонка: журнал срабатываний -->
    <div class="card">
        <h2 class="card-title">
            Журнал срабатываний
        </h2>
        
        <div class="logs-list">
            <?php if (empty($logs)): ?>
                <div class="empty-state">
                    <p>Нарушений не зафиксировано</p>
                    <p style="font-size: 12px; margin-top: 8px;">При отправке запрещённых слов здесь появятся записи</p>
                </div>
            <?php else: ?>
                <?php foreach ($logs as $log): ?>
                    <div class="log-item">
                        <div class="log-word"><?= htmlspecialchars($log['matched_word']) ?></div>
                        <div class="log-message">
                            <strong>Сообщение:</strong><br>
                            <?= nl2br(htmlspecialchars(mb_substr($log['message_text'], 0, 200))) ?>
                            <?php if (mb_strlen($log['message_text']) > 200): ?>...<?php endif; ?>
                        </div>
                        <div class="log-meta">
                            <span class="user-uid">Отправитель: <?= htmlspecialchars($log['sender_uid'] ?? '?') ?> (<?= htmlspecialchars($log['sender_name'] ?? 'неизвестно') ?>)</span>
                            <span class="user-uid">Получатель: <?= htmlspecialchars($log['receiver_uid'] ?? '?') ?> (<?= htmlspecialchars($log['receiver_name'] ?? 'неизвестно') ?>)</span>
                            <span><?= date('d.m.Y H:i:s', strtotime($log['created_at'])) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
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