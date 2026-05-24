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
    $oldPassword = $_POST['old_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    
    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        $error = 'Пользователь не найден';
    } elseif (!password_verify($oldPassword, $user['password_hash'])) {
        $error = 'Неверный старый пароль';
    } elseif (strlen($newPassword) < 6) {
        $error = 'Новый пароль должен быть не менее 6 символов';
    } else {
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $stmt->execute([$hash, $userId]);
        
        $success = 'Пароль успешно изменён!';
        header('refresh:2;url=/profile/profile.php');
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Смена пароля — Лист</title>
    <link rel="manifest" href="/manifest.json">
    <link rel="apple-touch-icon" href="/pwa_icon/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="96x96" href="/pwa_icon/favicon-96x96.png">
    <link rel="icon" type="image/svg+xml" href="/pwa_icon/favicon.svg">
    <link rel="shortcut icon" href="/pwa_icon/favicon.ico">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#0f172a">
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
        .card {
            max-width: 400px;
            width: 100%;
            background: #111827;
            border-radius: 24px;
            padding: 32px;
            border: 1px solid rgba(255,255,255,0.08);
        }
        h1 { font-size: 24px; margin-bottom: 24px; text-align: center; }
        input {
            width: 100%;
            padding: 12px;
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 12px;
            color: #fff;
            margin-bottom: 16px;
            font-size: 15px;
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
            font-size: 15px;
        }
        button:hover {
            background: #2563eb;
            transform: translateY(-1px);
        }
        .error {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #fecaca;
            padding: 12px;
            border-radius: 12px;
            margin-bottom: 16px;
            text-align: center;
            font-size: 14px;
        }
        .success {
            background: rgba(34, 197, 94, 0.15);
            border: 1px solid rgba(34, 197, 94, 0.3);
            color: #86efac;
            padding: 12px;
            border-radius: 12px;
            margin-bottom: 16px;
            text-align: center;
            font-size: 14px;
        }
        a {
            display: block;
            text-align: center;
            margin-top: 20px;
            color: #94a3b8;
            text-decoration: none;
            font-size: 14px;
        }
        a:hover {
            color: #3b82f6;
        }
        @media (max-width: 480px) {
            .card { padding: 24px; }
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>Смена пароля</h1>
        
        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <input type="password" name="old_password" placeholder="Старый пароль" required autocomplete="off">
            <input type="password" name="new_password" placeholder="Новый пароль (мин. 6 символов)" required autocomplete="off">
            <button type="submit">Изменить пароль</button>
        </form>
        
        <a href="/profile/privacy.php">← Вернуться в профиль</a>
    </div>
</body>
</html>