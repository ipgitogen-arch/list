<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/../inc/dd_bb.php';

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    header('Location: /auth/login.php');
    exit;
}

$success = '';
$error = '';

$stmt = $pdo->prepare("SELECT * FROM privacy_settings WHERE user_id = ?");
$stmt->execute([$userId]);
$settings = $stmt->fetch();

if (!$settings) {
    $stmt = $pdo->prepare("INSERT INTO privacy_settings (user_id) VALUES (?)");
    $stmt->execute([$userId]);
    $settings = [
        'user_id' => $userId,
        'privacy_requests' => 0,
        'delete_inactive_30d' => 1
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $privacy_requests = isset($_POST['privacy_requests']) ? 1 : 0;
    $delete_inactive = isset($_POST['delete_inactive']) ? 1 : 0;

    $stmt = $pdo->prepare("UPDATE privacy_settings SET privacy_requests = ?, delete_inactive_30d = ? WHERE user_id = ?");
    $stmt->execute([$privacy_requests, $delete_inactive, $userId]);
    
    $settings['privacy_requests'] = $privacy_requests;
    $settings['delete_inactive_30d'] = $delete_inactive;
    $success = 'Настройки сохранены';
}

// Обработка очистки PWA кэша
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_pwa_cache'])) {
    $success = 'Кэш PWA будет очищен при следующем обновлении страницы';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Конфиденциальность — Лист</title>
    <link rel="manifest" href="/manifest.json">
    <link rel="apple-touch-icon" href="/pwa_icon/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="96x96" href="/pwa_icon/favicon-96x96.png">
    <link rel="icon" type="image/svg+xml" href="/pwa_icon/favicon.svg">
    <link rel="shortcut icon" href="/pwa_icon/favicon.ico">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#0f172a">
    <link rel="stylesheet" href="/assets/css/pwa-fix.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Inter', 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #0a0f1f 100%);
            color: #f1f5f9;
            min-height: 100vh;
        }
        .navbar {
            background: rgba(17, 24, 39, 0.9);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255,255,255,0.06);
            padding: 16px 24px;
            padding-top: max(16px, env(safe-area-inset-top));
            padding-left: max(24px, env(safe-area-inset-left));
            padding-right: max(24px, env(safe-area-inset-right));
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .nav-container {
            max-width: 800px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }
        .logo {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 20px;
            font-weight: 600;
            text-decoration: none;
            color: #fff;
        }
        .nav-links { display: flex; gap: 24px; }
        .nav-link {
            color: #94a3b8;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: color 0.2s;
        }
        .nav-link:hover { color: #fff; }
        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 32px 24px;
            padding-bottom: 80px;
        }
        .card {
            background: #111827;
            border-radius: 24px;
            border: 1px solid rgba(255,255,255,0.06);
            padding: 28px;
            margin-bottom: 24px;
        }
        .card-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 6px;
            letter-spacing: -0.2px;
        }
        .card-description {
            color: #94a3b8;
            font-size: 13px;
            margin-bottom: 20px;
            line-height: 1.5;
        }
        .setting {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            padding: 16px 0;
            border-top: 1px solid rgba(255,255,255,0.06);
        }
        .setting:first-child {
            border-top: none;
            padding-top: 0;
        }
        .setting-info h3 {
            font-size: 15px;
            font-weight: 500;
            margin-bottom: 4px;
        }
        .setting-info p {
            font-size: 13px;
            color: #64748b;
            margin: 0;
        }
        .toggle {
            position: relative;
            display: inline-block;
            width: 48px;
            height: 24px;
        }
        .toggle input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #334155;
            transition: 0.2s;
            border-radius: 24px;
        }
        .slider:before {
            position: absolute;
            content: "";
            height: 20px;
            width: 20px;
            left: 2px;
            bottom: 2px;
            background-color: white;
            transition: 0.2s;
            border-radius: 50%;
        }
        input:checked + .slider {
            background: #3b82f6;
        }
        input:checked + .slider:before {
            transform: translateX(24px);
        }
        .btn {
            width: 100%;
            padding: 12px 16px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 500;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
            text-decoration: none;
            display: inline-block;
        }
        .btn-primary {
            background: #3b82f6;
            color: #fff;
        }
        .btn-primary:hover {
            background: #2563eb;
            transform: translateY(-1px);
        }
        .btn-secondary {
            background: #1e293b;
            color: #fff;
        }
        .btn-secondary:hover {
            background: #334155;
        }
        .btn-danger {
            background: #7f1a1a;
            color: #fecaca;
        }
        .btn-danger:hover {
            background: #991b1b;
        }
        .success {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.2);
            color: #4ade80;
            padding: 12px 16px;
            border-radius: 12px;
            margin-bottom: 20px;
            text-align: center;
            font-size: 13px;
        }
        .error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: #f87171;
            padding: 12px 16px;
            border-radius: 12px;
            margin-bottom: 20px;
            text-align: center;
            font-size: 13px;
        }
        .back-link {
            display: inline-block;
            margin-top: 16px;
            color: #94a3b8;
            text-decoration: none;
            font-size: 13px;
        }
        .back-link:hover {
            color: #3b82f6;
        }
        hr {
            border-color: #1e293b;
            margin: 16px 0;
        }
        @media (max-width: 600px) {
            .navbar {
                padding: 12px 20px;
                padding-top: max(12px, env(safe-area-inset-top));
                padding-left: max(20px, env(safe-area-inset-left));
                padding-right: max(20px, env(safe-area-inset-right));
            }
            .container {
                padding: 24px 16px;
                padding-bottom: 60px;
            }
            .card {
                padding: 20px;
            }
            .setting {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="nav-container">
            <a href="/" class="logo">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M12 3C12 3 5 8 5 15C5 19 8 22 12 22C16 22 19 19 19 15C19 8 12 3 12 3Z" fill="#3b82f6" stroke="#3b82f6" stroke-width="1.2"/>
                    <path d="M12 3V22" stroke="#fff" stroke-width="0.8" opacity="0.6"/>
                    <path d="M9 10L12 13L15 10" stroke="#fff" stroke-width="0.8" fill="none" opacity="0.6"/>
                    <path d="M8 15L12 18L16 15" stroke="#fff" stroke-width="0.8" fill="none" opacity="0.6"/>
                </svg>
                <span>Лист</span>
            </a>
            <div class="nav-links">
                <a href="/profile/profile.php" class="nav-link">Профиль</a>
                <a href="/profile/dialogs.php" class="nav-link">Диалоги</a>
                <a href="/auth/logout.php" class="nav-link">Выйти</a>
            </div>
        </div>
    </nav>

    <main class="container">
        <!-- Карточка настроек конфиденциальности -->
        <div class="card">
            <div class="card-title">Конфиденциальность</div>
            <div class="card-description">Управление настройками приватности вашего аккаунта</div>

            <?php if ($success): ?>
                <div class="success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST">
                <div class="setting">
                    <div class="setting-info">
                        <h3>Запросы на сообщения</h3>
                        <p>Требовать одобрения перед отправкой сообщения от незнакомцев</p>
                    </div>
                    <label class="toggle">
                        <input type="checkbox" name="privacy_requests" value="1" <?= $settings['privacy_requests'] ? 'checked' : '' ?>>
                        <span class="slider"></span>
                    </label>
                </div>

                <div class="setting">
                    <div class="setting-info">
                        <h3>Автоудаление неактивных аккаунтов</h3>
                        <p>Автоматически удалять аккаунт через 30 дней без активности</p>
                    </div>
                    <label class="toggle">
                        <input type="checkbox" name="delete_inactive" value="1" <?= $settings['delete_inactive_30d'] ? 'checked' : '' ?>>
                        <span class="slider"></span>
                    </label>
                </div>

                <button type="submit" name="save_settings" class="btn btn-primary" style="margin-top: 16px;">Сохранить настройки</button>
            </form>
        </div>

        <!-- Безопасность -->
        <div class="card">
            <div class="card-title">🔐 Безопасность</div>
            <div class="card-description">Изменение пароля аккаунта и настройки безопасности</div>
            <a href="/auth/change_password.php" class="btn btn-primary">Изменить пароль</a>
            <?php if (isset($_SESSION['user_id'])): ?>
                <hr>
                <div class="setting" style="padding-top: 8px;">
                    <div class="setting-info">
                        <h3>Сессии</h3>
                        <p>Завершить все активные сессии на других устройствах</p>
                    </div>
                    <form method="POST" action="/auth/end_sessions.php" onsubmit="return confirm('Завершить все другие сессии?')">
                        <button type="submit" class="btn btn-secondary" style="width: auto; padding: 8px 16px;">Завершить</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <!-- Приложение (PWA) -->
        <div class="card">
            <div class="card-title">📱 Приложение</div>
            <div class="card-description">Очистить кэш прогрессивного веб-приложения. Рекомендуется после обновления сайта или при проблемах с отображением.</div>
            <button id="clearPwaCacheBtn" class="btn btn-secondary">Очистить кэш PWA</button>
        </div>

        <!-- Удаление аккаунта -->
        <div class="card">
            <div class="card-title">⚠️ Удаление аккаунта</div>
            <div class="card-description">Безвозвратное удаление всех данных и сообщений. Это действие нельзя отменить.</div>
            <form action="/profile/delete.php" method="post" onsubmit="return confirm('ВНИМАНИЕ! Вы действительно хотите удалить аккаунт безвозвратно? Все сообщения, диалоги и данные будут потеряны навсегда.');">
                <button type="submit" class="btn btn-danger">Удалить аккаунт</button>
            </form>
        </div>

        <a href="/profile/profile.php" class="back-link">← Вернуться в профиль</a>
    </main>

    <script>
        document.getElementById('clearPwaCacheBtn')?.addEventListener('click', async () => {
            if ('serviceWorker' in navigator && 'caches' in window) {
                try {
                    const btn = document.getElementById('clearPwaCacheBtn');
                    const originalText = btn.textContent;
                    btn.textContent = 'Очистка...';
                    btn.disabled = true;
                    
                    const cacheNames = await caches.keys();
                    await Promise.all(cacheNames.map(name => caches.delete(name)));
                    
                    const registration = await navigator.serviceWorker.getRegistration();
                    if (registration && registration.active) {
                        registration.active.postMessage('clearCache');
                    }
                    
                    btn.textContent = originalText;
                    btn.disabled = false;
                    
                    alert('Кэш PWA очищен. Приложение будет обновлено при следующем запуске.');
                    window.location.reload();
                } catch (error) {
                    console.error('PWA cache clear error:', error);
                    alert('Ошибка при очистке кэша. Попробуйте переустановить приложение.');
                    const btn = document.getElementById('clearPwaCacheBtn');
                    if (btn) {
                        btn.disabled = false;
                        btn.textContent = 'Очистить кэш PWA';
                    }
                }
            } else {
                alert('PWA не поддерживается в этом браузере');
            }
        });
        
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.addEventListener('message', function(event) {
                if (event.data && event.data.type === 'cacheCleared') {
                    console.log('Кэш очищен service worker');
                }
            });
        }
    </script>
</body>
</html>