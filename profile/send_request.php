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
    $searchId = trim($_POST['user_id'] ?? '');
    if ($searchId === '') {
        $error = 'Введите ID пользователя';
    } else {
        $stmt = $pdo->prepare("SELECT id, name, uid FROM users WHERE uid = ? AND id != ?");
        $stmt->execute([$searchId, $userId]);
        $targetUser = $stmt->fetch();
        
        if (!$targetUser) {
            $error = 'Пользователь с таким ID не найден';
        } else {
            $stmt = $pdo->prepare("SELECT id FROM messages WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?) LIMIT 1");
            $stmt->execute([$userId, $targetUser['id'], $targetUser['id'], $userId]);
            if ($stmt->fetch()) {
                header('Location: /profile/chat.php?user=' . $targetUser['id']);
                exit;
            }
            
            $stmt = $pdo->prepare("SELECT privacy_requests FROM privacy_settings WHERE user_id = ?");
            $stmt->execute([$targetUser['id']]);
            $privacy = $stmt->fetch();
            
            if ($privacy && $privacy['privacy_requests'] == 1) {
                $stmt = $pdo->prepare("INSERT INTO message_requests (from_user_id, to_user_id, status, created_at) VALUES (?, ?, 'pending', NOW())");
                $stmt->execute([$userId, $targetUser['id']]);
                $_SESSION['request_sent'] = 'Запрос отправлен пользователю ' . htmlspecialchars($targetUser['name']) . '. Ожидайте подтверждения.';
                header('Location: /profile/dialogs.php');
                exit;
            } else {
                $stmt = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, content, created_at) VALUES (?, ?, ?, NOW())");
                $stmt->execute([$userId, $targetUser['id'], '👋 Начинаем общение!']);
                header('Location: /profile/chat.php?user=' . $targetUser['id']);
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Написать пользователю — Лист</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #0f172a, #0a0f1f);
            color: #fff;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        .container {
            max-width: 400px;
            width: 100%;
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
        <h1>✉️ Написать пользователю</h1>
        
        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <input type="text" name="user_id" placeholder="Введите ID пользователя" required>
            <button type="submit">Написать</button>
        </form>
        
        <a href="/profile/dialogs.php" class="back-link">← Вернуться к диалогам</a>
    </div>
</body>
</html>