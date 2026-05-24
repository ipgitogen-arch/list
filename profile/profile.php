<?php
declare(strict_types=1);

require __DIR__ . '/../inc/dd_bb.php';
require __DIR__ . '/../inc/auth.php';
require __DIR__ . '/../inc/access_check.php';
checkPageAccess('profile');

$userId = current_user_id();
if (!$userId) {
    header('Location: /auth/login.php');
    exit;
}

$avatarError = '';

$stmt = $pdo->prepare('SELECT id, name, email, subscription, is_plus, last_seen, role FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$userId]);
$baseUser = $stmt->fetch();

if (!$baseUser) {
    logout_user();
    header('Location: /index.php');
    exit;
}

$stmt = $pdo->prepare('SELECT user_id, avatar, identifier FROM profiles WHERE user_id = ? LIMIT 1');
$stmt->execute([$userId]);
$profile = $stmt->fetch();

if (!$profile) {
    $identifier = 'list' . $userId;
    $stmt = $pdo->prepare('INSERT INTO profiles (user_id, avatar, identifier) VALUES (?, ?, ?)');
    $stmt->execute([$userId, null, $identifier]);
    $profile = ['user_id' => $userId, 'avatar' => null, 'identifier' => $identifier];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['avatar'])) {
    if ((int)($_FILES['avatar']['error'] ?? 1) !== UPLOAD_ERR_OK) {
        $avatarError = 'Ошибка загрузки файла.';
    } else {
        $tmpName = (string)($_FILES['avatar']['tmp_name'] ?? '');
        $mime = is_file($tmpName) ? mime_content_type($tmpName) : '';

        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
        ];

        if (!isset($allowed[$mime])) {
            $avatarError = 'Разрешены только JPG, PNG, GIF и WEBP.';
        } else {
            $uploadDir = __DIR__ . '/uploads';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            foreach (glob($uploadDir . '/avatar_' . $userId . '.*') ?: [] as $oldFile) {
                if (is_file($oldFile)) {
                    @unlink($oldFile);
                }
            }

            $ext = $allowed[$mime];
            $filename = 'avatar_' . $userId . '.' . $ext;
            $fullPath = $uploadDir . '/' . $filename;
            $dbPath = '/profile/uploads/' . $filename;

            if (move_uploaded_file($tmpName, $fullPath)) {
                $stmt = $pdo->prepare('UPDATE profiles SET avatar = ? WHERE user_id = ?');
                $stmt->execute([$dbPath, $userId]);
                $profile['avatar'] = $dbPath;
                header('Location: /profile/profile.php');
                exit;
            } else {
                $avatarError = 'Не удалось сохранить файл.';
            }
        }
    }
}

$stmt = $pdo->prepare("UPDATE users SET last_seen = ? WHERE id = ?");
$stmt->execute([time(), $userId]);

$isOnline = ($baseUser['last_seen'] && (time() - (int)$baseUser['last_seen']) < 300);

$user = [
    'name' => (string)$baseUser['name'],
    'email' => (string)$baseUser['email'],
    'subscription' => (string)($baseUser['subscription'] ?? 'standard'),
    'is_plus' => (int)($baseUser['is_plus'] ?? 0),
    'avatar' => (string)($profile['avatar'] ?? ''),
    'identifier' => (string)($profile['identifier'] ?? ('list' . $userId)),
    'last_seen' => $baseUser['last_seen'],
    'role' => (string)($baseUser['role'] ?? 'user'),
];

$avatarSrc = $user['avatar'] !== '' ? $user['avatar'] : '/profile/default-avatar.svg';
$hasPlus = ($user['subscription'] === 'plus' || $user['is_plus'] === 1);
$isAdmin = ($user['role'] === 'admin');

$lastSeenText = 'неизвестно';
if ($user['last_seen']) {
    $diff = time() - (int)$user['last_seen'];
    if ($diff < 60) $lastSeenText = 'только что';
    elseif ($diff < 3600) $lastSeenText = floor($diff / 60) . ' мин назад';
    elseif ($diff < 86400) $lastSeenText = floor($diff / 3600) . ' ч назад';
    else $lastSeenText = floor($diff / 86400) . ' дн назад';
}

$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt->execute([$userId]);
$unreadNotifications = $stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=yes">
    <title>Профиль — Лист</title>
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
            background: #0f172a;
            color: #f1f5f9;
            min-height: 100vh;
        }
        
        .header {
            background: rgba(17, 24, 39, 0.95);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255,255,255,0.06);
            padding: 12px 20px;
            padding-top: max(12px, env(safe-area-inset-top));
            padding-left: max(20px, env(safe-area-inset-left));
            padding-right: max(20px, env(safe-area-inset-right));
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
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
        .logo svg {
            width: 24px;
            height: 24px;
        }
        .header-right {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .notifications-container {
            position: relative;
        }
        .notifications-btn {
            background: rgba(59, 130, 246, 0.15);
            border: none;
            border-radius: 40px;
            padding: 8px 16px;
            cursor: pointer;
            position: relative;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
        }
        .notifications-btn svg {
            stroke: #3b82f6;
            width: 18px;
            height: 18px;
        }
        .notifications-btn span {
            font-size: 13px;
            font-weight: 500;
            color: #3b82f6;
        }
        .notifications-btn:hover {
            background: rgba(59, 130, 246, 0.3);
            transform: translateY(-1px);
        }
        .notifications-badge {
            position: absolute;
            top: -4px;
            right: -2px;
            background: #ef4444;
            color: #fff;
            font-size: 10px;
            font-weight: 600;
            padding: 2px 6px;
            border-radius: 20px;
            min-width: 16px;
            text-align: center;
        }
        
        /* Истории друзей */
        .stories-section {
            padding: 16px 20px;
            border-bottom: 1px solid rgba(255,255,255,0.06);
            background: #0f172a;
        }
        .stories-container {
            display: flex;
            gap: 16px;
            overflow-x: auto;
            padding: 4px 0;
            scrollbar-width: thin;
            -webkit-overflow-scrolling: touch;
        }
        .stories-container::-webkit-scrollbar {
            height: 3px;
        }
        .story-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            flex-shrink: 0;
        }
        .story-avatar-wrapper {
            position: relative;
            width: 74px;
            height: 74px;
        }
        .story-avatar {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            object-fit: cover;
            position: absolute;
            top: 2px;
            left: 2px;
        }
        .story-ring {
            position: absolute;
            top: 0;
            left: 0;
            width: 74px;
            height: 74px;
            border-radius: 50%;
            background: linear-gradient(45deg, #f09433, #d62976, #962fbf, #4f5bd5);
        }
        .story-ring.viewed {
            background: #374151;
        }
        .story-name {
            font-size: 11px;
            color: #94a3b8;
            max-width: 70px;
            text-align: center;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        .container {
            max-width: 560px;
            margin: 0 auto;
            padding: 32px 24px;
        }
        .profile-card {
            background: #111827;
            border-radius: 28px;
            border: 1px solid rgba(255,255,255,0.06);
            overflow: hidden;
        }
        .avatar-section {
            padding: 32px 32px 0 32px;
            display: flex;
            justify-content: center;
            position: relative;
        }
        .avatar-wrapper {
            position: relative;
            cursor: pointer;
        }
        .avatar-img {
            width: 104px;
            height: 104px;
            border-radius: 52px;
            object-fit: cover;
            border: 3px solid #3b82f6;
        }
        .avatar-placeholder {
            width: 104px;
            height: 104px;
            border-radius: 52px;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
            font-weight: 500;
            color: #fff;
            border: 3px solid #3b82f6;
        }
        .avatar-upload {
            position: absolute;
            bottom: 4px;
            right: 4px;
            background: #1e293b;
            border-radius: 50%;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            border: 2px solid #111827;
            transition: 0.2s;
        }
        .avatar-upload:hover {
            background: #334155;
        }
        .user-info {
            text-align: center;
            padding: 20px 32px 0 32px;
        }
        .user-name {
            font-size: 22px;
            font-weight: 600;
            margin-bottom: 4px;
        }
        .user-email {
            color: #94a3b8;
            font-size: 13px;
            margin-bottom: 12px;
        }
        .badges {
            display: flex;
            justify-content: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        .badge-plus {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: #fff;
        }
        .badge-admin {
            background: #ef4444;
            color: #fff;
        }
        .badge-standard {
            background: #1e293b;
            color: #94a3b8;
        }
        .details {
            padding: 20px 32px;
            border-top: 1px solid rgba(255,255,255,0.06);
            border-bottom: 1px solid rgba(255,255,255,0.06);
        }
        .detail-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
        }
        .detail-label {
            color: #94a3b8;
            font-size: 13px;
        }
        .detail-value {
            font-weight: 500;
            font-size: 13px;
        }
        .copy-id {
            cursor: pointer;
            padding: 4px 10px;
            background: #1e293b;
            border-radius: 8px;
            transition: 0.2s;
            font-family: monospace;
        }
        .copy-id:hover {
            background: #3b82f6;
            color: #fff;
        }
        .status-online {
            color: #22c55e;
        }
        .status-offline {
            color: #64748b;
        }
        .actions {
            padding: 24px 32px 32px 32px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 16px;
            border-radius: 14px;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.2s;
            cursor: pointer;
            border: none;
            width: 100%;
        }
        .btn-primary {
            background: #3b82f6;
            color: #fff;
        }
        .btn-primary:hover {
            background: #2563eb;
        }
        .btn-secondary {
            background: #1e293b;
            color: #fff;
        }
        .btn-secondary:hover {
            background: #334155;
        }
        .btn-disabled {
            background: #1e293b;
            color: #64748b;
            cursor: not-allowed;
        }
        .error {
            color: #f87171;
            font-size: 12px;
            text-align: center;
            margin-top: 8px;
        }
        #avatarInput {
            display: none;
        }
        
        /* Модалка меню аватара */
        .avatar-menu-modal {
            display: none;
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: #111827;
            border-radius: 28px 28px 0 0;
            z-index: 1001;
            animation: slideUp 0.3s ease;
            border-top: 1px solid rgba(255,255,255,0.08);
            box-shadow: 0 -10px 30px rgba(0,0,0,0.5);
        }
        .avatar-menu-modal.active {
            display: block;
        }
        .avatar-menu-option {
            padding: 16px 24px;
            display: flex;
            align-items: center;
            gap: 14px;
            cursor: pointer;
            border-bottom: 1px solid #1e293b;
            transition: 0.2s;
            font-size: 16px;
            font-weight: 500;
        }
        .avatar-menu-option:last-child {
            border-bottom: none;
        }
        .avatar-menu-option:hover {
            background: #1e293b;
        }
        .avatar-menu-option span:first-child {
            font-size: 22px;
            width: 32px;
        }
        .avatar-menu-close {
            padding: 16px;
            text-align: center;
            color: #ef4444;
            cursor: pointer;
            font-weight: 600;
            border-top: 1px solid #1e293b;
            margin-top: 8px;
        }
        .avatar-menu-close:hover {
            background: #1e293b;
        }
        @keyframes slideUp {
            from {
                transform: translateY(100%);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        @media (max-width: 600px) {
            .header {
                padding: 12px 16px;
            }
            .container {
                padding: 24px 16px;
            }
            .avatar-section {
                padding: 24px 24px 0 24px;
            }
            .user-info {
                padding: 16px 24px 0 24px;
            }
            .details {
                padding: 16px 24px;
            }
            .actions {
                padding: 20px 24px 24px 24px;
            }
            .avatar-img, .avatar-placeholder {
                width: 80px;
                height: 80px;
                font-size: 32px;
            }
            .user-name {
                font-size: 20px;
            }
            .story-avatar-wrapper {
                width: 64px;
                height: 64px;
            }
            .story-avatar {
                width: 60px;
                height: 60px;
            }
            .story-ring {
                width: 64px;
                height: 64px;
            }
            .notifications-btn span {
                display: none;
            }
            .notifications-btn {
                padding: 8px 12px;
            }
        }
    </style>
</head>
<body>

<div class="header">
    <a href="/index.php" class="logo">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M12 3C12 3 5 8 5 15C5 19 8 22 12 22C16 22 19 19 19 15C19 8 12 3 12 3Z" fill="#3b82f6" stroke="#3b82f6" stroke-width="1.2"/>
            <path d="M12 3V22" stroke="#fff" stroke-width="0.8" opacity="0.6"/>
            <path d="M9 10L12 13L15 10" stroke="#fff" stroke-width="0.8" fill="none" opacity="0.6"/>
            <path d="M8 15L12 18L16 15" stroke="#fff" stroke-width="0.8" fill="none" opacity="0.6"/>
        </svg>
        <span>Лист</span>
    </a>
    <div class="header-right">
        <div class="notifications-container">
            <button class="notifications-btn" id="notificationsBtn">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" />
                    <path d="M13.73 21a2 2 0 0 1-3.46 0" />
                </svg>
                <span>Уведомления</span>
                <?php if ($unreadNotifications > 0): ?>
                    <span class="notifications-badge" id="notificationsBadge"><?= $unreadNotifications ?></span>
                <?php endif; ?>
            </button>
        </div>
    </div>
</div>

<!-- Блок историй друзей -->
<div class="stories-section">
    <div class="stories-container" id="storiesContainer">
        <div class="story-item">Загрузка...</div>
    </div>
</div>

<div class="container">
    <div class="profile-card">
        <div class="avatar-section">
            <div class="avatar-wrapper" id="avatarWrapper">
                <?php if ($avatarSrc && $avatarSrc != '/profile/default-avatar.svg'): ?>
                    <img class="avatar-img" src="<?= htmlspecialchars($avatarSrc) ?>" alt="Аватар" id="avatarImg">
                <?php else: ?>
                    <div class="avatar-placeholder" id="avatarPlaceholder"><?= mb_substr($user['name'], 0, 1) ?></div>
                <?php endif; ?>
                <div class="avatar-upload" id="avatarUploadBtn">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/>
                        <circle cx="12" cy="13" r="4"/>
                    </svg>
                </div>
                <form method="post" enctype="multipart/form-data" id="avatarForm" style="display:none;">
                    <input id="avatarInput" type="file" name="avatar" accept="image/jpeg,image/png,image/gif,image/webp">
                </form>
            </div>
        </div>

        <div class="user-info">
            <div class="user-name"><?= htmlspecialchars($user['name']) ?></div>
            <div class="user-email"><?= htmlspecialchars($user['email']) ?></div>
            <div class="badges">
                <span class="badge <?= $hasPlus ? 'badge-plus' : 'badge-standard' ?>">
                    <?= $hasPlus ? 'PLUS' : 'Стандарт' ?>
                </span>
                <?php if (!$hasPlus): ?>
                    <button class="badge" id="buyPlusBtn" style="background:transparent; border:1px solid #3b82f6; color:#3b82f6; cursor:pointer;">Купить PLUS</button>
                <?php endif; ?>
                <?php if ($isAdmin): ?>
                    <span class="badge badge-admin">Админ</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="details">
            <div class="detail-row">
                <span class="detail-label">ID пользователя</span>
                <span class="detail-value copy-id" id="userIdentifier"><?= htmlspecialchars($user['identifier']) ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Статус</span>
                <span class="detail-value <?= $isOnline ? 'status-online' : 'status-offline' ?>">
                    <?= $isOnline ? 'Онлайн' : 'Офлайн (' . $lastSeenText . ')' ?>
                </span>
            </div>
        </div>

        <div class="actions">
            <a href="/feed.php" class="btn btn-primary">Лента</a>
            <a href="/profile/dialogs.php" class="btn btn-primary">Сообщения</a>
            <a href="/profile/content.php" class="btn btn-secondary">Контент</a>
            
            <?php if ($hasPlus): ?>
                <a href="/profile/bot.php" class="btn btn-primary">ИИ Бот</a>
            <?php else: ?>
                <button class="btn btn-disabled" id="botPlusBtn">ИИ Бот (доступен в PLUS)</button>
            <?php endif; ?>
            
            <a href="/profile/privacy.php" class="btn btn-secondary">Конфиденциальность</a>
            <?php if ($isAdmin): ?>
    <a href="/x9p_admin_7k2/index.php" class="btn btn-secondary">Админ-панель</a>
<?php endif; ?>
            <a href="/profile/support_chat.php" class="btn btn-secondary">Поддержка</a>
            <a href="/auth/logout.php" class="btn btn-secondary">Выйти</a>
        </div>
        
        <?php if ($avatarError): ?>
            <div class="error"><?= htmlspecialchars($avatarError) ?></div>
        <?php endif; ?>
    </div>
</div>

<!-- Модалка меню аватара -->
<div id="avatarMenuModal" class="avatar-menu-modal">
    <div class="avatar-menu-option" id="changeAvatarOption">
        <span>📷</span> <span>Загрузить фото профиля</span>
    </div>
    <div class="avatar-menu-option" id="createStoryOption">
        <span>✨</span> <span>Создать историю</span>
    </div>
    <div class="avatar-menu-option" id="startStreamOption">
        <span>🔴</span> <span>Начать стрим</span>
    </div>
    <div class="avatar-menu-close" id="closeAvatarMenu">Отмена</div>
</div>

<script>
    // Уведомления
    const notificationsBtn = document.getElementById('notificationsBtn');
    
    if (notificationsBtn) {
        notificationsBtn.addEventListener('click', () => {
            window.location.href = '/profile/notifications.php';
        });
    }
    
    // Аватар - открываем меню при нажатии на фотоаппарат
    const avatarUploadBtn = document.getElementById('avatarUploadBtn');
    const avatarMenuModal = document.getElementById('avatarMenuModal');
    const avatarInput = document.getElementById('avatarInput');
    const avatarForm = document.getElementById('avatarForm');
    
    if (avatarUploadBtn) {
        avatarUploadBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            avatarMenuModal.classList.add('active');
        });
    }
    
    // Загрузить фото профиля
    document.getElementById('changeAvatarOption')?.addEventListener('click', () => {
        avatarInput.click();
        avatarMenuModal.classList.remove('active');
    });
    
    if (avatarInput) {
        avatarInput.addEventListener('change', () => {
            if (avatarInput.files.length) {
                avatarForm.submit();
            }
        });
    }
    
    // Создать историю
    document.getElementById('createStoryOption')?.addEventListener('click', () => {
        avatarMenuModal.classList.remove('active');
        
        const input = document.createElement('input');
        input.type = 'file';
        input.accept = 'image/*,video/*';
        input.onchange = (e) => {
            const file = e.target.files[0];
            if (!file) return;
            
            const formData = new FormData();
            const isVideo = file.type.startsWith('video/');
            formData.append('type', isVideo ? 'video' : 'photo');
            formData.append(isVideo ? 'video' : 'photo', file);
            
            fetch('/api/story_upload.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert('История добавлена!');
                    loadStories();
                } else {
                    alert(data.error || 'Ошибка загрузки');
                }
            })
            .catch(err => {
                alert('Ошибка загрузки');
            });
        };
        input.click();
    });
    
    // Начать стрим
    document.getElementById('startStreamOption')?.addEventListener('click', () => {
        window.location.href = '/profile/create_stream.php';
    });
    
    // Закрыть меню
    document.getElementById('closeAvatarMenu')?.addEventListener('click', () => {
        avatarMenuModal.classList.remove('active');
    });
    
    // Закрытие по клику вне
    document.addEventListener('click', (e) => {
        if (avatarMenuModal.classList.contains('active') && 
            !avatarUploadBtn.contains(e.target) && 
            !avatarMenuModal.contains(e.target)) {
            avatarMenuModal.classList.remove('active');
        }
    });
    
    // Копирование ID
    const idElem = document.getElementById('userIdentifier');
    if (idElem) {
        idElem.addEventListener('click', () => {
            const text = idElem.textContent.trim();
            navigator.clipboard.writeText(text).then(() => alert('ID скопирован'));
        });
    }
    
    // PLUS
    document.getElementById('buyPlusBtn')?.addEventListener('click', () => {
        window.location.href = '/profile/subscribe.php';
    });
    
    document.getElementById('botPlusBtn')?.addEventListener('click', () => {
        window.location.href = '/profile/subscribe.php';
    });
    
    // Загрузка историй друзей
    let friendsStories = [];
    
    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/[&<>]/g, function(m) {
            if (m === '&') return '&amp;';
            if (m === '<') return '&lt;';
            if (m === '>') return '&gt;';
            return m;
        });
    }
    
    function loadStories() {
        fetch('/api/stories_get.php')
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    friendsStories = data.friends_stories || [];
                    renderStories();
                }
            })
            .catch(err => console.error('Stories error:', err));
    }
    
    function renderStories() {
        const container = document.getElementById('storiesContainer');
        if (!container) return;
        
        const defaultAvatar = '/pwa_icon/icon-96.png';
        let html = '';
        
        if (friendsStories.length === 0) {
            html = '<div class="story-item" style="opacity:0.5;">Нет историй друзей</div>';
        } else {
            friendsStories.forEach(story => {
                const hasUnviewed = story.stories_list && story.stories_list.some(s => !s.is_viewed);
                html += `
                    <div class="story-item" onclick="window.location.href='/feed.php?type=stories'">
                        <div class="story-avatar-wrapper">
                            <div class="story-ring ${!hasUnviewed ? 'viewed' : ''}"></div>
                            <img class="story-avatar" src="${story.avatar || defaultAvatar}" onerror="this.src='${defaultAvatar}'">
                        </div>
                        <span class="story-name">${escapeHtml(story.name)}</span>
                    </div>
                `;
            });
        }
        
        container.innerHTML = html;
    }
    
    loadStories();
</script>

</body>
</html>