<?php
session_start();
require __DIR__ . '/../inc/dd_bb.php';

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    header('Location: /auth/login.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $chatName = trim($_POST['chat_name'] ?? '');
    $members = $_POST['members'] ?? [];
    
    if ($chatName === '') {
        $error = 'Введите название чата';
    } else {
        $stmt = $pdo->prepare("INSERT INTO group_chats (name, created_by, created_at) VALUES (?, ?, NOW())");
        $stmt->execute([$chatName, $userId]);
        $chatId = $pdo->lastInsertId();
        
        $stmt = $pdo->prepare("INSERT INTO group_chat_members (chat_id, user_id, role, joined_at) VALUES (?, ?, 'admin', NOW())");
        $stmt->execute([$chatId, $userId]);
        
        foreach ($members as $memberId) {
            if ($memberId != $userId) {
                $stmt = $pdo->prepare("INSERT INTO group_chat_members (chat_id, user_id, role, joined_at) VALUES (?, ?, 'member', NOW())");
                $stmt->execute([$chatId, $memberId]);
            }
        }
        
        header('Location: /profile/group_chat.php?id=' . $chatId);
        exit;
    }
}

// Получаем список друзей
$stmt = $pdo->prepare("
    SELECT u.id, u.name, u.uid, p.avatar
    FROM users u
    LEFT JOIN profiles p ON p.user_id = u.id
    WHERE u.id IN (SELECT friend_id FROM friends WHERE user_id = ? UNION SELECT user_id FROM friends WHERE friend_id = ?)
    ORDER BY u.name ASC
");
$stmt->execute([$userId, $userId]);
$friends = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Создать группу — Лист</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #0f172a, #0a0f1f);
            color: #fff;
            min-height: 100vh;
            padding: 16px;
        }
        .container {
            max-width: 500px;
            margin: 0 auto;
            background: #111827;
            border-radius: 24px;
            padding: 24px;
        }
        h1 { font-size: 24px; margin-bottom: 20px; text-align: center; }
        input {
            width: 100%;
            padding: 12px;
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 12px;
            color: #fff;
            margin-bottom: 16px;
        }
        .friends-list {
            max-height: 300px;
            overflow-y: auto;
            margin-bottom: 20px;
        }
        .friend-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px;
            background: #1e293b;
            border-radius: 12px;
            margin-bottom: 8px;
        }
        .friend-item input { width: 20px; margin: 0; }
        .avatar {
            width: 40px;
            height: 40px;
            border-radius: 20px;
            object-fit: cover;
        }
        .avatar-placeholder {
            width: 40px;
            height: 40px;
            border-radius: 20px;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
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
        }
        .error { color: #f87171; margin-bottom: 16px; text-align: center; }
        .back-link {
            display: block;
            text-align: center;
            margin-top: 16px;
            color: #94a3b8;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>👥 Создать групповой чат</h1>
        
        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <input type="text" name="chat_name" placeholder="Название чата" required>
            
            <h3 style="margin: 16px 0 8px;">Добавить участников</h3>
            <div class="friends-list">
                <?php foreach ($friends as $friend): ?>
                    <div class="friend-item">
                        <input type="checkbox" name="members[]" value="<?= $friend['id'] ?>" id="friend_<?= $friend['id'] ?>">
                        <?php if ($friend['avatar']): ?>
                            <img class="avatar" src="<?= htmlspecialchars($friend['avatar']) ?>" alt="">
                        <?php else: ?>
                            <div class="avatar-placeholder"><?= mb_substr($friend['name'], 0, 1) ?></div>
                        <?php endif; ?>
                        <label for="friend_<?= $friend['id'] ?>" style="flex:1;"><?= htmlspecialchars($friend['name']) ?> (<?= htmlspecialchars($friend['uid']) ?>)</label>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($friends)): ?>
                    <p style="color: #64748b; text-align: center;">У вас пока нет друзей</p>
                <?php endif; ?>
            </div>
            
            <button type="submit">Создать группу</button>
        </form>
        
        <a href="/profile/dialogs.php" class="back-link">← Вернуться к диалогам</a>
    </div>
</body>
</html>