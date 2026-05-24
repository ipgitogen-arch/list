<?php
session_start();
require __DIR__ . '/../inc/dd_bb.php';
require __DIR__ . '/../inc/mail.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Введите корректный email';
    } else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if (!$stmt->fetch()) {
            $error = 'Пользователь с таким email не найден';
        } else {
            $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+15 minutes'));
            
            $stmt = $pdo->prepare("INSERT INTO password_resets (email, token, code, expires_at, created_at) VALUES (?, ?, ?, ?, NOW())");
            $stmt->execute([$email, $token, $code, $expires]);
            
            $subject = "Восстановление пароля - Лист";
            $body = "<html><body>
                <h2>Восстановление пароля</h2>
                <p>Ваш код для восстановления пароля: <strong style='font-size: 24px; color: #3b82f6;'>$code</strong></p>
                <p>Код действителен 15 минут.</p>
                <p>Если вы не запрашивали восстановление пароля, проигнорируйте это письмо.</p>
            </body></html>";
            
            if (sendMail($email, $subject, $body)) {
                $_SESSION['reset_email'] = $email;
                $_SESSION['reset_token'] = $token;
                header('Location: /auth/reset.php');
                exit;
            } else {
                $error = 'Не удалось отправить письмо. Попробуйте позже.';
                $_SESSION['temp_code'] = $code;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Восстановление пароля — Лист</title>
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
            font-size: 14px;
        }
        .debug {
            background: rgba(245, 158, 11, 0.15);
            border: 1px solid #f59e0b;
            color: #fcd34d;
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
        <h1>Восстановление пароля</h1>
        <p>Введите email, на который отправим код</p>
        
        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['temp_code'])): ?>
            <div class="debug">
                ⚠️ Письмо не отправлено. Используйте код: <strong><?= $_SESSION['temp_code'] ?></strong>
                <?php unset($_SESSION['temp_code']); ?>
            </div>
        <?php endif; ?>
        
        <form method="POST">
            <input type="email" name="email" placeholder="Email" required autocomplete="off">
            <button type="submit">Отправить код</button>
        </form>
        
        <a href="/auth/login.php">← Вернуться к входу</a>
    </div>
</body>
</html>