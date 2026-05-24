<?php
session_start();
require __DIR__ . '/../inc/dd_bb.php';

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    header('Location: /auth/login.php');
    exit;
}

// Проверяем PLUS подписку
$stmt = $pdo->prepare("SELECT subscription, is_plus FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

$hasPlus = ($user['subscription'] === 'plus' || !empty($user['is_plus']));
if (!$hasPlus) {
    header('Location: /profile/subscribe.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $channelName = trim($_POST['channel_name'] ?? '');
    $channelDesc = trim($_POST['channel_desc'] ?? '');
    
    if ($channelName === '') {
        $error = 'Введите название канала';
    } else {
        try {
            // Проверяем существование таблицы channels
            $stmt = $pdo->query("SHOW TABLES LIKE 'channels'");
            if ($stmt->rowCount() == 0) {
                $error = 'Таблица channels не существует. Обратитесь к администратору.';
            } else {
                $stmt = $pdo->prepare("INSERT INTO channels (name, description, owner_id, created_at, is_shared, is_official) VALUES (?, ?, ?, NOW(), 0, 0)");
                $stmt->execute([$channelName, $channelDesc, $userId]);
                $channelId = $pdo->lastInsertId();
                
                $stmt = $pdo->prepare("INSERT INTO channel_subscribers (channel_id, user_id, role, joined_at) VALUES (?, ?, 'owner', NOW())");
                $stmt->execute([$channelId, $userId]);
                
                // Добавляем уведомление владельцу
                $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, link, created_at) VALUES (?, 'channel', 'Канал создан', ?, ?, NOW())");
                $stmt->execute([$userId, 'Ваш канал "' . $channelName . '" успешно создан', '/profile/channel.php?id=' . $channelId]);
                
                header('Location: /profile/channel.php?id=' . $channelId);
                exit;
            }
        } catch (PDOException $e) {
            $error = 'Ошибка базы данных: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Создать канал — Лист PLUS</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #0a0f1f 100%);
            color: #fff;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        .container {
            max-width: 500px;
            width: 100%;
            background: #111827;
            border-radius: 24px;
            padding: 24px;
            border: 1px solid rgba(255,255,255,0.08);
        }
        h1 { font-size: 24px; margin-bottom: 8px; text-align: center; }
        .plus-badge {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            display: inline-block;
            width: auto;
            margin: 0 auto 20px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            text-align: center;
        }
        .error {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid #ef4444;
            color: #fecaca;
            padding: 12px;
            border-radius: 12px;
            margin-bottom: 20px;
            text-align: center;
        }
        input, textarea {
            width: 100%;
            padding: 12px;
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 12px;
            color: #fff;
            margin-bottom: 16px;
            font-family: inherit;
        }
        textarea {
            resize: vertical;
            min-height: 80px;
        }
        button {
            width: 100%;
            padding: 12px;
            background: #3b82f6;
            border: none;
            border-radius: 12px;
            color: #fff;
            font-weight: 600;
            cursor: pointer;
            transition: 0.2s;
        }
        button:hover {
            transform: translateY(-1px);
            background: #2563eb;
        }
        .back-link {
            display: block;
            text-align: center;
            margin-top: 20px;
            color: #94a3b8;
            text-decoration: none;
        }
        .back-link:hover {
            color: #3b82f6;
        }
        @media (max-width: 480px) {
            .container { padding: 20px; }
            h1 { font-size: 22px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📢 Создать канал</h1>
        <div style="text-align: center;"><span class="plus-badge">⭐ PLUS функция</span></div>
        
        <?php if ($error): ?>
            <div class="error">❌ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <input type="text" name="channel_name" placeholder="Название канала" required autocomplete="off">
            <textarea name="channel_desc" placeholder="Описание канала (необязательно)"></textarea>
            <button type="submit">Создать канал</button>
        </form>
        
        <a href="/profile/dialogs.php" class="back-link">← Вернуться к диалогам</a>
    </div>
</body>
</html>