<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/../inc/dd_bb.php';
require __DIR__ . '/../inc/encryption.php';

// Проверка - открыта ли регистрация (из админки)
$stmt = $pdo->prepare("SELECT registration_enabled FROM settings LIMIT 1");
$stmt->execute();
$regEnabled = $stmt->fetchColumn();

if ($regEnabled === false) {
    $regEnabled = 1;
}

// Секретный доступ для администратора
$secretAccess = false;
if (isset($_GET['admin']) && $_GET['admin'] === '1') {
    $secretAccess = true;
}

// Если регистрация закрыта И нет секретного доступа
if ($regEnabled == 0 && !$secretAccess) {
    ?>
    <!DOCTYPE html>
    <html lang="ru">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Регистрация закрыта — Лист</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
                background: linear-gradient(135deg, #0f172a, #0a0f1f);
                color: #fff;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }
            .message {
                background: #111827;
                border-radius: 28px;
                padding: 40px;
                text-align: center;
                max-width: 450px;
                border: 1px solid rgba(255,255,255,0.08);
            }
            h1 { color: #ef4444; margin-bottom: 16px; font-size: 28px; }
            p { color: #94a3b8; margin-bottom: 24px; line-height: 1.5; }
            a { 
                display: inline-block;
                background: #3b82f6;
                color: #fff;
                text-decoration: none;
                padding: 12px 24px;
                border-radius: 40px;
                font-weight: 500;
                transition: 0.2s;
            }
            a:hover {
                background: #2563eb;
                transform: translateY(-1px);
            }
        </style>
    </head>
    <body>
        <div class="message">
            <div style="font-size: 64px; margin-bottom: 20px;">🔒</div>
            <h1>Регистрация закрыта</h1>
            <p>Администратор временно закрыл регистрацию новых пользователей.</p>
            <a href="/auth/login.php">← Вернуться на страницу входа</a>
        </div>
        <script>
            let keysPressed = {};
            document.addEventListener('keydown', function(e) {
                let key = e.key, code = e.keyCode;
                if (key === 'F3' || code === 114) { keysPressed['F3'] = true; e.preventDefault(); }
                if (key === 'F5' || code === 116) { keysPressed['F5'] = true; e.preventDefault(); }
                if (key === '0' || code === 48) { keysPressed['0'] = true; }
                if (keysPressed['F3'] && keysPressed['F5'] && keysPressed['0']) {
                    window.location.href = '/auth/register.php?admin=1';
                }
            });
            document.addEventListener('keyup', function(e) {
                let key = e.key, code = e.keyCode;
                if (key === 'F3' || code === 114) delete keysPressed['F3'];
                if (key === 'F5' || code === 116) delete keysPressed['F5'];
                if (key === '0' || code === 48) delete keysPressed['0'];
            });
        </script>
    </body>
    </html>
    <?php
    exit;
}

if (isset($_SESSION['user_id'])) {
    header('Location: /profile/profile.php');
    exit;
}

$error = '';

function generate_uid(PDO $pdo): string {
    do {
        $uid = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 11);
        $check = $pdo->prepare("SELECT id FROM users WHERE uid=? LIMIT 1");
        $check->execute([$uid]);
    } while ($check->fetch());
    return $uid;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $agree = isset($_POST['agree']);

    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($password) < 6) {
        $error = 'Проверьте данные. Пароль минимум 6 символов.';
    } elseif (!$agree) {
        $error = 'Необходимо принять правила сайта и политику конфиденциальности';
    } else {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = 'Пользователь с таким email уже существует.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $uid = generate_uid($pdo);
            
            $stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash, uid, subscription, is_plus, created_at) VALUES (?, ?, ?, ?, "standard", 0, NOW())');
            $stmt->execute([$name, $email, $hash, $uid]);
            $userId = (int)$pdo->lastInsertId();
            
            $stmt = $pdo->prepare('INSERT INTO profiles (user_id, avatar, identifier) VALUES (?, ?, ?)');
            $stmt->execute([$userId, null, $uid]);
            
            $encryptionKey = generate_encryption_key();
            $stmt = $pdo->prepare("UPDATE users SET encryption_key = ? WHERE id = ?");
            $stmt->execute([$encryptionKey, $userId]);
            
            $stmt = $pdo->prepare("INSERT INTO privacy_settings (user_id, privacy_requests, delete_inactive_30d) VALUES (?, 0, 1)");
            $stmt->execute([$userId]);
            
            if (function_exists('updateUserIp')) {
                updateUserIp($pdo, $userId, $_SERVER['REMOTE_ADDR']);
            }
            
            $_SESSION['user_id'] = $userId;
            header('Location: /profile/profile.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=yes">
    <title>Регистрация — Лист</title>
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
        .auth-container {
            max-width: 420px;
            width: 100%;
            background: #111827;
            border-radius: 32px;
            padding: 32px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(255,255,255,0.08);
        }
        .logo {
            text-align: center;
            margin-bottom: 24px;
        }
        .logo h1 {
            font-size: 32px;
            background: linear-gradient(135deg, #fff, #94a3b8);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        .logo p {
            font-size: 14px;
            color: #94a3b8;
            margin-top: 4px;
        }
        h2 {
            font-size: 24px;
            text-align: center;
            margin-bottom: 8px;
        }
        .subtitle {
            text-align: center;
            color: #94a3b8;
            margin-bottom: 28px;
            font-size: 14px;
        }
        .error {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #fecaca;
            padding: 12px 16px;
            border-radius: 16px;
            margin-bottom: 24px;
            text-align: center;
            font-size: 14px;
        }
        input {
            width: 100%;
            padding: 14px 16px;
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 16px;
            color: #fff;
            font-size: 16px;
            margin-bottom: 16px;
            transition: 0.2s;
        }
        input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
        }
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 24px;
            font-size: 13px;
        }
        .checkbox-group input {
            width: 18px;
            height: 18px;
            margin: 0;
            cursor: pointer;
        }
        .checkbox-group label {
            color: #94a3b8;
            cursor: pointer;
        }
        .checkbox-group a {
            color: #3b82f6;
            text-decoration: none;
        }
        button {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            border: none;
            border-radius: 16px;
            color: #fff;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: 0.2s;
        }
        button:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 20px rgba(59, 130, 246, 0.3);
        }
        .links {
            margin-top: 24px;
            text-align: center;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .links a {
            color: #94a3b8;
            text-decoration: none;
            font-size: 14px;
            transition: 0.2s;
        }
        .links a:hover {
            color: #3b82f6;
        }
        .login-link {
            display: inline-block;
            background: rgba(59, 130, 246, 0.15);
            border: 1px solid rgba(59, 130, 246, 0.3);
            padding: 10px 20px;
            border-radius: 40px;
            font-weight: 500;
            transition: 0.2s;
        }
        .login-link:hover {
            background: rgba(59, 130, 246, 0.25);
            border-color: #3b82f6;
            transform: translateY(-1px);
            color: #3b82f6;
        }
        @media (max-width: 480px) {
            .auth-container {
                padding: 24px;
            }
            .logo h1 {
                font-size: 28px;
            }
            h2 {
                font-size: 22px;
            }
            input, button {
                padding: 12px;
                font-size: 15px;
            }
        }
    </style>
</head>
<body>
    <div class="auth-container">
        <div class="logo">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="margin: 0 auto 8px; display: block;">
                <defs>
                    <linearGradient id="leafGradient" x1="0%" y1="0%" x2="100%" y2="100%">
                        <stop offset="0%" stop-color="#3b82f6"/>
                        <stop offset="100%" stop-color="#8b5cf6"/>
                    </linearGradient>
                </defs>
                <path d="M12 3C12 3 5 8 5 15C5 19 8 22 12 22C16 22 19 19 19 15C19 8 12 3 12 3Z" fill="url(#leafGradient)" stroke="#3b82f6" stroke-width="1.2"/>
                <path d="M12 3V22" stroke="#fff" stroke-width="0.8" opacity="0.6"/>
                <path d="M9 10L12 13L15 10" stroke="#fff" stroke-width="0.8" fill="none" opacity="0.6"/>
                <path d="M8 15L12 18L16 15" stroke="#fff" stroke-width="0.8" fill="none" opacity="0.6"/>
            </svg>
            <div style="font-size: 24px; font-weight: 700; background: linear-gradient(135deg, #3b82f6, #8b5cf6); -webkit-background-clip: text; background-clip: text; color: transparent;">Лист</div>
            <p style="font-size: 12px; color: #94a3b8; margin-top: 4px;">Мессенджер нового поколения</p>
        </div>
        
        <h2>Регистрация</h2>
        <div class="subtitle">Создайте аккаунт бесплатно</div>
        
        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <input type="text" name="name" placeholder="Имя" required autocomplete="off">
            <input type="email" name="email" placeholder="Email" required autocomplete="off">
            <input type="password" name="password" placeholder="Пароль (мин. 6 символов)" required>
            
            <div class="checkbox-group">
                <input type="checkbox" name="agree" id="agree" required>
                <label for="agree">Я принимаю <a href="/terms.php" target="_blank">правила сайта</a> и <a href="/privacy.php" target="_blank">политику конфиденциальности</a></label>
            </div>
            
            <button type="submit">Создать аккаунт</button>
        </form>
        
        <div class="links">
            <a href="/auth/login.php" class="login-link">Уже есть аккаунт? Войти →</a>
            <a href="/index.php">← На главную</a>
        </div>
    </div>
</body>
</html>