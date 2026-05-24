<?php
session_start();
require __DIR__ . '/../inc/dd_bb.php';

$email = $_SESSION['change_email'] ?? null;
$token = $_SESSION['change_token'] ?? null;
$newPassword = $_SESSION['new_password'] ?? null;

if (!$email || !$token || !$newPassword) {
    header('Location: /profile/privacy.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim($_POST['code'] ?? '');
    
    $stmt = $pdo->prepare("SELECT * FROM password_resets WHERE email = ? AND token = ? AND code = ? AND expires_at > NOW()");
    $stmt->execute([$email, $token, $code]);
    $reset = $stmt->fetch();
    
    if (!$reset) {
        $error = 'Неверный или истёкший код';
    } else {
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE email = ?");
        $stmt->execute([$hash, $email]);
        
        $stmt = $pdo->prepare("DELETE FROM password_resets WHERE email = ?");
        $stmt->execute([$email]);
        
        unset($_SESSION['change_email'], $_SESSION['change_token'], $_SESSION['new_password']);
        $success = 'Пароль успешно изменён!';
        header('refresh:2;url=/profile/profile.php');
    }
}

$showCode = isset($_GET['show_code']) && isset($_SESSION['temp_code']);
$tempCode = $showCode ? $_SESSION['temp_code'] : '';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Подтверждение смены пароля — Лист</title>
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
        h1 { font-size: 24px; margin-bottom: 8px; text-align: center; }
        p { color: #94a3b8; margin-bottom: 24px; text-align: center; }
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
        }
        .success {
            background: rgba(34, 197, 94, 0.15);
            border: 1px solid rgba(34, 197, 94, 0.3);
            color: #86efac;
            padding: 12px;
            border-radius: 12px;
            margin-bottom: 16px;
            text-align: center;
        }
        .debug {
            background: rgba(245, 158, 11, 0.15);
            border: 1px solid #f59e0b;
            color: #fcd34d;
            padding: 12px;
            border-radius: 12px;
            margin-bottom: 16px;
            text-align: center;
            font-weight: bold;
        }
        a {
            display: block;
            text-align: center;
            margin-top: 20px;
            color: #94a3b8;
            text-decoration: none;
        }
        a:hover {
            color: #3b82f6;
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>Подтверждение</h1>
        <p>Введите код, отправленный на <?= htmlspecialchars($email) ?></p>
        
        <?php if ($showCode): ?>
            <div class="debug">
                🔑 Ваш код: <strong><?= htmlspecialchars($tempCode) ?></strong>
                <div style="font-size: 12px; margin-top: 8px;">(режим отладки)</div>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <input type="text" name="code" placeholder="Код из письма" required autocomplete="off">
            <button type="submit">Подтвердить</button>
        </form>
        
        <a href="/profile/privacy.php">← Вернуться в профиль</a>
    </div>
</body>
</html>