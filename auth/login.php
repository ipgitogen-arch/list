<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/../inc/dd_bb.php';

// Проверка - открыт ли вход (из админки)
$stmt = $pdo->prepare("SELECT login_enabled FROM settings LIMIT 1");
$stmt->execute();
$loginEnabled = $stmt->fetchColumn();

if ($loginEnabled === false) {
    $loginEnabled = 1;
}

// Секретный доступ для администратора (F3+F5+0)
$secretAccess = false;
if (isset($_GET['admin']) && $_GET['admin'] === '1') {
    $secretAccess = true;
}

// Если вход закрыт И нет секретного доступа
if ($loginEnabled == 0 && !$secretAccess) {
    ?>
    <!DOCTYPE html>
    <html lang="ru">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Доступ ограничен — Лист</title>
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
        </style>
    </head>
    <body>
        <div class="message">
            <div style="font-size: 64px; margin-bottom: 20px;">🔒</div>
            <h1>Доступ ограничен</h1>
            <p>Вход в систему временно закрыт администратором.</p>
        </div>
        <script>
            let keysPressed = {};
            document.addEventListener('keydown', function(e) {
                let key = e.key, code = e.keyCode;
                if (key === 'F3' || code === 114) { keysPressed['F3'] = true; e.preventDefault(); }
                if (key === 'F5' || code === 116) { keysPressed['F5'] = true; e.preventDefault(); }
                if (key === '0' || code === 48) { keysPressed['0'] = true; }
                if (keysPressed['F3'] && keysPressed['F5'] && keysPressed['0']) {
                    window.location.href = '/auth/login.php?admin=1';
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

// Если уже авторизован
if (isset($_SESSION['user_id'])) {
    header('Location: /profile/profile.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if ($email === '' || $password === '') {
        $error = 'Заполните все поля';
    } else {
        $stmt = $pdo->prepare('SELECT id, name, email, password_hash, role FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            header('Location: /profile/profile.php');
            exit;
        } else {
            $error = 'Неверный email или пароль';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=yes">
    <title>Вход — Лист</title>
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
        .register-link {
            display: inline-block;
            background: rgba(59, 130, 246, 0.15);
            border: 1px solid rgba(59, 130, 246, 0.3);
            padding: 10px 20px;
            border-radius: 40px;
            font-weight: 500;
            transition: 0.2s;
        }
        .register-link:hover {
            background: rgba(59, 130, 246, 0.25);
            border-color: #3b82f6;
            transform: translateY(-1px);
            color: #3b82f6;
        }
        .forgot-link {
            margin-top: 8px;
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
        
        <h2>Вход</h2>
        <div class="subtitle">Добро пожаловать обратно</div>
        
        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <input type="email" name="email" placeholder="Email" required autocomplete="off">
            <input type="password" name="password" placeholder="Пароль" required>
            <button type="submit">Войти</button>
        </form>
        
        <div class="links">
            <a href="/auth/register.php" class="register-link">Нет аккаунта? Зарегистрироваться →</a>
            <a href="/auth/forgot.php" class="forgot-link">Забыли пароль?</a>
            <a href="/index.php">← На главную</a>
        </div>
    </div>
    
    <script>
        let keysPressed = {};
        document.addEventListener('keydown', function(e) {
            let key = e.key, code = e.keyCode;
            if (key === 'F3' || code === 114) { keysPressed['F3'] = true; e.preventDefault(); }
            if (key === 'F5' || code === 116) { keysPressed['F5'] = true; e.preventDefault(); }
            if (key === '0' || code === 48) { keysPressed['0'] = true; }
            if (keysPressed['F3'] && keysPressed['F5'] && keysPressed['0']) {
                window.location.href = '/auth/login.php?admin=1';
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