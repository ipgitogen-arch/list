<?php
// inc/access_check.php - проверка доступа к страницам

function checkPageAccess($pageName) {
    global $pdo;
    
    // Если пользователь не авторизован - пропускаем (он не увидит страницу из-за другой проверки)
    if (!isset($_SESSION['user_id'])) {
        return true;
    }
    
    // Проверяем, админ ли пользователь
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    
    // Админам всегда разрешено (они видят всё)
    if ($user && $user['role'] === 'admin') {
        return true;
    }
    
    // Для обычных пользователей проверяем, не закрыта ли страница
    $stmt = $pdo->prepare("SELECT is_closed FROM admin_page_access WHERE page_name = ?");
    $stmt->execute([$pageName]);
    $setting = $stmt->fetch();
    
    // Если страница не закрыта (is_closed = 0 или нет записи) - доступ разрешён
    if (!$setting || $setting['is_closed'] != 1) {
        return true;
    }
    
    // Страница закрыта для обычных пользователей
    http_response_code(403);
    die('<!DOCTYPE html>
    <html lang="ru">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Доступ ограничен</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                background: #0f172a;
                color: #fff;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }
            .message {
                background: #111827;
                border-radius: 24px;
                padding: 40px;
                text-align: center;
                max-width: 400px;
                border: 1px solid rgba(255,255,255,0.08);
            }
            h1 { color: #ef4444; margin-bottom: 16px; font-size: 28px; }
            p { color: #94a3b8; margin-bottom: 24px; line-height: 1.5; }
            a { 
                display: inline-block;
                background: #3b82f6;
                color: #fff;
                text-decoration: none;
                padding: 10px 20px;
                border-radius: 30px;
                font-weight: 500;
            }
            a:hover { background: #2563eb; }
        </style>
    </head>
    <body>
        <div class="message">
            <div style="font-size: 64px; margin-bottom: 20px;">🔒</div>
            <h1>Доступ ограничен</h1>
            <p>Доступ к этой странице временно закрыт администратором.</p>
            <a href="/profile/profile.php">Вернуться в профиль</a>
        </div>
    </body>
    </html>');
    exit;
}

// Функция для проверки, открыта ли регистрация
function isRegistrationEnabled($pdo) {
    $stmt = $pdo->prepare("SELECT registration_enabled FROM settings LIMIT 1");
    $stmt->execute();
    $regEnabled = $stmt->fetchColumn();
    
    // По умолчанию, если нет записи - регистрация открыта
    if ($regEnabled === false) {
        return true;
    }
    
    return $regEnabled == 1;
}
?>