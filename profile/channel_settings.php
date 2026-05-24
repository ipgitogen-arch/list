<?php
session_start();
require __DIR__ . '/../inc/dd_bb.php';

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    header('Location: /auth/login.php');
    exit;
}

$channelId = (int)($_GET['id'] ?? 0);
if (!$channelId) {
    header('Location: /profile/dialogs.php');
    exit;
}

$stmt = $pdo->prepare("SELECT role FROM channel_subscribers WHERE channel_id = ? AND user_id = ?");
$stmt->execute([$channelId, $userId]);
$userRole = $stmt->fetchColumn();

if ($userRole !== 'owner') {
    header('Location: /profile/channel.php?id=' . $channelId);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM channels WHERE id = ?");
$stmt->execute([$channelId]);
$channel = $stmt->fetch();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_info'])) {
        $newName = trim($_POST['channel_name'] ?? '');
        $newDesc = trim($_POST['channel_desc'] ?? '');
        if ($newName !== '') {
            $stmt = $pdo->prepare("UPDATE channels SET name = ?, description = ? WHERE id = ?");
            $stmt->execute([$newName, $newDesc, $channelId]);
            $success = 'Информация обновлена';
            $channel['name'] = $newName;
            $channel['description'] = $newDesc;
        }
    }
    
    if (isset($_POST['change_avatar'])) {
        if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/../uploads/channel_avatars/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            
            $ext = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
$allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'svg', 'jfif', 'jpe', 'jfif', 'tiff', 'ico'];
            
            if (in_array($ext, $allowedExt)) {
                $filename = 'channel_' . $channelId . '_' . time() . '.' . $ext;
                $targetPath = $uploadDir . $filename;
                
                if (move_uploaded_file($_FILES['avatar']['tmp_name'], $targetPath)) {
                    $avatarPath = '/uploads/channel_avatars/' . $filename;
                    $stmt = $pdo->prepare("UPDATE channels SET avatar = ? WHERE id = ?");
                    $stmt->execute([$avatarPath, $channelId]);
                    $success = 'Аватар обновлён';
                    $channel['avatar'] = $avatarPath;
                    header("Location: /profile/channel_settings.php?id=" . $channelId);
                    exit;
                } else {
                    $error = 'Ошибка сохранения файла';
                }
            } else {
                $error = 'Неподдерживаемый формат. Используйте JPG, PNG, GIF, WEBP';
            }
        } else {
            $error = 'Выберите файл';
        }
    }
    
    if (isset($_POST['remove_avatar'])) {
        $stmt = $pdo->prepare("UPDATE channels SET avatar = NULL WHERE id = ?");
        $stmt->execute([$channelId]);
        $success = 'Аватар удалён';
        $channel['avatar'] = null;
        header("Location: /profile/channel_settings.php?id=" . $channelId);
        exit;
    }
    
    if (isset($_POST['toggle_write_as_channel'])) {
        $newWriteAs = isset($_POST['write_as_channel']) ? 1 : 0;
        $stmt = $pdo->prepare("UPDATE channels SET write_as_channel = ? WHERE id = ?");
        $stmt->execute([$newWriteAs, $channelId]);
        $channel['write_as_channel'] = $newWriteAs;
        $success = $newWriteAs ? 'Сообщения будут от имени канала' : 'Сообщения будут от вашего имени';
        header("Location: /profile/channel_settings.php?id=" . $channelId);
        exit;
    }
    
    if (isset($_POST['toggle_public'])) {
        $newPublic = isset($_POST['is_public']) ? 1 : 0;
        $stmt = $pdo->prepare("UPDATE channels SET is_public = ? WHERE id = ?");
        $stmt->execute([$newPublic, $channelId]);
        $channel['is_public'] = $newPublic;
        $success = $newPublic ? 'Канал добавлен в список публичных' : 'Канал скрыт из публичного списка';
        header("Location: /profile/channel_settings.php?id=" . $channelId);
        exit;
    }
    
    if (isset($_POST['toggle_share'])) {
        $newShared = $channel['is_shared'] ? 0 : 1;
        $shareLink = $newShared ? bin2hex(random_bytes(16)) : null;
        $stmt = $pdo->prepare("UPDATE channels SET is_shared = ?, share_link = ? WHERE id = ?");
        $stmt->execute([$newShared, $shareLink, $channelId]);
        $channel['is_shared'] = $newShared;
        $channel['share_link'] = $shareLink;
        $success = $newShared ? 'Ссылка создана' : 'Приглашения отключены';
        header("Location: /profile/channel_settings.php?id=" . $channelId);
        exit;
    }
    
    if (isset($_POST['delete_channel'])) {
        if ($channel['avatar'] && file_exists(__DIR__ . '/..' . $channel['avatar'])) {
            @unlink(__DIR__ . '/..' . $channel['avatar']);
        }
        $stmt = $pdo->prepare("DELETE FROM channel_subscribers WHERE channel_id = ?");
        $stmt->execute([$channelId]);
        $stmt = $pdo->prepare("DELETE FROM channel_messages WHERE channel_id = ?");
        $stmt->execute([$channelId]);
        $stmt = $pdo->prepare("DELETE FROM moments WHERE channel_id = ?");
        $stmt->execute([$channelId]);
        $stmt = $pdo->prepare("DELETE FROM channels WHERE id = ?");
        $stmt->execute([$channelId]);
        header('Location: /profile/dialogs.php');
        exit;
    }
}

$stmt = $pdo->prepare("SELECT COUNT(*) FROM channel_subscribers WHERE channel_id = ?");
$stmt->execute([$channelId]);
$subscribersCount = $stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Настройки канала — Лист</title>
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
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #0a0f1f 100%);
            color: #fff;
            min-height: 100vh;
            padding: 20px;
        }
        .container { max-width: 600px; margin: 0 auto; background: #111827; border-radius: 24px; padding: 24px; }
        h1 { font-size: 24px; margin-bottom: 24px; text-align: center; }
        .section { margin-bottom: 24px; padding-bottom: 24px; border-bottom: 1px solid #334155; }
        .section h2 { font-size: 18px; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
        input, textarea {
            width: 100%;
            padding: 12px;
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 12px;
            color: #fff;
            margin-bottom: 12px;
        }
        textarea { resize: vertical; min-height: 80px; }
        button {
            padding: 10px 16px;
            background: #3b82f6;
            border: none;
            border-radius: 8px;
            color: #fff;
            cursor: pointer;
            transition: 0.2s;
        }
        button:hover { transform: translateY(-1px); opacity: 0.9; }
        .danger-btn { background: #dc2626; }
        .avatar-preview {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 16px;
            margin-bottom: 16px;
            padding: 16px;
            background: #1e293b;
            border-radius: 16px;
        }
        .avatar-preview img {
            width: 100px;
            height: 100px;
            border-radius: 50px;
            object-fit: cover;
            border: 2px solid #3b82f6;
        }
        .avatar-placeholder {
            width: 100px;
            height: 100px;
            border-radius: 50px;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
        }
        .toggle-switch {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }
        .toggle-switch input {
            width: 44px;
            height: 24px;
            margin: 0;
            appearance: none;
            background: #334155;
            border-radius: 24px;
            position: relative;
            cursor: pointer;
        }
        .toggle-switch input:checked {
            background: #3b82f6;
        }
        .toggle-switch input::before {
            content: '';
            position: absolute;
            width: 20px;
            height: 20px;
            background: #fff;
            border-radius: 50%;
            top: 2px;
            left: 2px;
            transition: 0.2s;
        }
        .toggle-switch input:checked::before {
            left: 22px;
        }
        .success { color: #4ade80; margin-bottom: 16px; text-align: center; }
        .error { color: #f87171; margin-bottom: 16px; text-align: center; }
        .back-link {
            display: block;
            text-align: center;
            margin-top: 20px;
            color: #94a3b8;
            text-decoration: none;
        }
        .share-link { display: flex; gap: 8px; margin-top: 12px; flex-wrap: wrap; }
        .share-link input { flex: 1; margin: 0; }
        @media (max-width: 600px) {
            .container { padding: 18px; }
            .share-link { flex-direction: column; }
            .toggle-switch { flex-direction: column; align-items: flex-start; }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>⚙️ Настройки канала</h1>
        
        <?php if ($success): ?>
            <div class="success">✅ <?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="error">❌ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <div class="section">
            <h2>📝 Информация</h2>
            <form method="POST">
                <input type="text" name="channel_name" value="<?= htmlspecialchars($channel['name']) ?>" required>
                <textarea name="channel_desc" placeholder="Описание канала"><?= htmlspecialchars($channel['description'] ?? '') ?></textarea>
                <button type="submit" name="update_info">Сохранить</button>
            </form>
        </div>
        
        <div class="section">
            <h2>🖼 Аватар</h2>
            <div class="avatar-preview">
                <?php if ($channel['avatar'] && file_exists($_SERVER['DOCUMENT_ROOT'] . $channel['avatar'])): ?>
                    <img src="<?= htmlspecialchars($channel['avatar']) ?>" alt="Аватар">
                <?php else: ?>
                    <div class="avatar-placeholder">📢</div>
                <?php endif; ?>
                <div style="display: flex; gap: 12px;">
                    <form method="POST" enctype="multipart/form-data" style="display: inline;">
                        <input type="file" name="avatar" id="avatarFile" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none;" onchange="this.form.submit()">
                        <button type="button" onclick="document.getElementById('avatarFile').click()">📁 Выбрать файл</button>
                        <input type="hidden" name="change_avatar" value="1">
                    </form>
                    <?php if ($channel['avatar']): ?>
                        <form method="POST" style="display:inline;">
                            <button type="submit" name="remove_avatar" class="danger-btn">🗑 Удалить</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="section">
            <h2>🌍 Публичный канал</h2>
            <div class="toggle-switch">
                <span>Показывать канал в списке публичных</span>
                <form method="POST" name="publicForm">
                    <input type="hidden" name="toggle_public" value="1">
                    <input type="checkbox" name="is_public" onchange="this.form.submit()" <?= ($channel['is_public'] ?? 0) ? 'checked' : '' ?>>
                </form>
            </div>
            <p style="font-size:12px; color:#94a3b8; margin-top:8px;">Публичные каналы отображаются у всех пользователей</p>
        </div>
        
        <div class="section">
            <h2>✍️ Отправка сообщений</h2>
            <div class="toggle-switch">
                <span>Писать от имени канала</span>
                <form method="POST" name="writeForm">
                    <input type="hidden" name="toggle_write_as_channel" value="1">
                    <input type="checkbox" name="write_as_channel" onchange="this.form.submit()" <?= ($channel['write_as_channel'] ?? 1) ? 'checked' : '' ?>>
                </form>
            </div>
            <p style="font-size:12px; color:#94a3b8; margin-top:8px;">Если включено, сообщения будут отображаться от имени канала</p>
        </div>
        
        <div class="section">
            <h2>🔗 Приглашение</h2>
            <form method="POST">
                <button type="submit" name="toggle_share">
                    <?= $channel['is_shared'] ? '🔒 Отключить приглашения' : '🔓 Включить приглашения' ?>
                </button>
            </form>
            <?php if ($channel['is_shared'] && $channel['share_link']): ?>
                <div class="share-link">
                    <input type="text" value="https://<?= $_SERVER['HTTP_HOST'] ?>/profile/join_channel.php?link=<?= $channel['share_link'] ?>" readonly>
                    <button onclick="this.previousElementSibling.select(); document.execCommand('copy'); alert('Ссылка скопирована');">📋 Копировать ссылку</button>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="section">
            <h2>🗑 Опасная зона</h2>
            <form method="POST" onsubmit="return confirm('Удалить канал безвозвратно? Все сообщения будут потеряны.');">
                <button type="submit" name="delete_channel" class="danger-btn">Удалить канал</button>
            </form>
        </div>
        
        <a href="/profile/channel.php?id=<?= $channelId ?>" class="back-link">← Вернуться в канал</a>
    </div>
</body>
</html>