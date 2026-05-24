<?php
session_start();
require __DIR__ . '/../inc/dd_bb.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /auth/login.php');
    exit;
}

$userId = $_SESSION['user_id'];

// Получаем данные пользователя
$stmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

// Получаем тип вкладки
$tab = $_GET['tab'] ?? 'photos';
$allowedTabs = ['photos', 'videos', 'publications', 'playlists'];
if (!in_array($tab, $allowedTabs)) {
    $tab = 'photos';
}

// Обработка загрузки фото/видео
$uploadSuccess = '';
$uploadError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['media_file'])) {
    $mediaType = $_POST['media_type'] ?? 'photo';
    $caption = trim($_POST['caption'] ?? '');
    $isPublic = isset($_POST['is_public']) ? 1 : 0;
    
    $file = $_FILES['media_file'];
    if ($file['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/../uploads/content/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowedPhoto = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];
        $allowedVideo = ['mp4', 'webm', 'mov', 'avi'];
        
        if ($mediaType === 'photo' && in_array($ext, $allowedPhoto)) {
            $filename = 'photo_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $filepath = $uploadDir . $filename;
            
            if (move_uploaded_file($file['tmp_name'], $filepath)) {
                $stmt = $pdo->prepare("INSERT INTO content_posts (user_id, media_path, media_type, caption, is_public, created_at) VALUES (?, ?, 'image', ?, ?, NOW())");
                $stmt->execute([$userId, '/uploads/content/' . $filename, $caption, $isPublic]);
                $uploadSuccess = 'Фото успешно загружено!';
                header('Location: /profile/content.php?tab=photos&success=1');
                exit;
            } else {
                $uploadError = 'Ошибка при сохранении файла';
            }
        } elseif ($mediaType === 'video' && in_array($ext, $allowedVideo)) {
            // Проверка длительности видео (максимум 30 секунд)
            $videoDuration = 0;
            
            // Попытка получить длительность через FFmpeg
            if (function_exists('shell_exec')) {
                $ffmpeg = shell_exec("ffmpeg -i " . escapeshellarg($file['tmp_name']) . " 2>&1");
                if (preg_match('/Duration: (\d{2}):(\d{2}):(\d{2})\.\d+/', $ffmpeg, $matches)) {
                    $videoDuration = ($matches[1] * 3600) + ($matches[2] * 60) + $matches[3];
                }
            }
            
            if ($videoDuration > 0 && $videoDuration > 30) {
                $uploadError = 'Видео слишком длинное! Максимум 30 секунд.';
            } else {
                $filename = 'video_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $filepath = $uploadDir . $filename;
                
                if (move_uploaded_file($file['tmp_name'], $filepath)) {
                    $stmt = $pdo->prepare("INSERT INTO content_posts (user_id, media_path, media_type, caption, is_public, created_at) VALUES (?, ?, 'video', ?, ?, NOW())");
                    $stmt->execute([$userId, '/uploads/content/' . $filename, $caption, $isPublic]);
                    $uploadSuccess = 'Видео успешно загружено!';
                    header('Location: /profile/content.php?tab=videos&success=1');
                    exit;
                } else {
                    $uploadError = 'Ошибка при сохранении файла';
                }
            }
        } else {
            $uploadError = 'Неподдерживаемый формат файла';
        }
    } else {
        $uploadError = 'Ошибка загрузки файла';
    }
}

// Обработка создания плейлиста
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_playlist'])) {
    $playlistName = trim($_POST['playlist_name']);
    $playlistDesc = trim($_POST['playlist_description']);
    $isPublic = isset($_POST['playlist_is_public']) ? 1 : 0;
    
    if (!empty($playlistName)) {
        $shareLink = bin2hex(random_bytes(16));
        $stmt = $pdo->prepare("INSERT INTO playlists (user_id, name, description, is_public, share_link, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())");
        $stmt->execute([$userId, $playlistName, $playlistDesc, $isPublic, $shareLink]);
        $uploadSuccess = 'Плейлист создан!';
        header('Location: /profile/content.php?tab=playlists');
        exit;
    } else {
        $uploadError = 'Введите название плейлиста';
    }
}

// Обработка добавления в плейлист
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_to_playlist'])) {
    $playlistId = (int)$_POST['playlist_id'];
    $contentId = (int)$_POST['content_id'];
    
    $stmt = $pdo->prepare("SELECT id FROM playlist_items WHERE playlist_id = ? AND publication_id = ?");
    $stmt->execute([$playlistId, $contentId]);
    if (!$stmt->fetch()) {
        $stmt = $pdo->prepare("INSERT INTO playlist_items (playlist_id, publication_id, added_at) VALUES (?, ?, NOW())");
        $stmt->execute([$playlistId, $contentId]);
        $uploadSuccess = 'Добавлено в плейлист!';
    } else {
        $uploadError = 'Уже добавлено в этот плейлист';
    }
}

// Получаем контент
$photos = [];
$videos = [];
$publications = [];
$playlists = [];

if ($tab === 'photos') {
    $stmt = $pdo->prepare("
        SELECT cp.*, u.name 
        FROM content_posts cp
        JOIN users u ON u.id = cp.user_id
        WHERE cp.user_id = ? AND (cp.media_type = 'image' OR cp.media_path NOT LIKE '%.mp4%')
        ORDER BY cp.created_at DESC
    ");
    $stmt->execute([$userId]);
    $photos = $stmt->fetchAll();
}

if ($tab === 'videos') {
    $stmt = $pdo->prepare("
        SELECT cp.*, u.name 
        FROM content_posts cp
        JOIN users u ON u.id = cp.user_id
        WHERE cp.user_id = ? AND (cp.media_type = 'video' OR cp.media_path LIKE '%.mp4%')
        ORDER BY cp.created_at DESC
    ");
    $stmt->execute([$userId]);
    $videos = $stmt->fetchAll();
}

if ($tab === 'publications') {
    $stmt = $pdo->prepare("
        SELECT cp.*, u.name 
        FROM content_posts cp
        JOIN users u ON u.id = cp.user_id
        WHERE cp.user_id = ?
        ORDER BY cp.created_at DESC
    ");
    $stmt->execute([$userId]);
    $publications = $stmt->fetchAll();
}

if ($tab === 'playlists') {
    $stmt = $pdo->prepare("
        SELECT p.*, 
            (SELECT COUNT(*) FROM playlist_items WHERE playlist_id = p.id) as items_count
        FROM playlists p
        WHERE p.user_id = ?
        ORDER BY p.created_at DESC
    ");
    $stmt->execute([$userId]);
    $playlists = $stmt->fetchAll();
}

// Для модалки добавления в плейлист
$allPlaylists = [];
$stmt = $pdo->prepare("SELECT id, name FROM playlists WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$userId]);
$allPlaylists = $stmt->fetchAll();

$userContent = [];
$stmt = $pdo->prepare("
    SELECT id, media_path, media_type, caption 
    FROM content_posts 
    WHERE user_id = ? 
    ORDER BY created_at DESC
");
$stmt->execute([$userId]);
$userContent = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Контент — Лист</title>
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
        .profile-link {
            color: #94a3b8;
            text-decoration: none;
            font-size: 14px;
            padding: 8px 16px;
            border-radius: 40px;
            background: #1e293b;
        }
        
        .nav-panel {
            background: #111827;
            border-bottom: 1px solid #1e293b;
            padding: 8px 20px;
            display: flex;
            gap: 4px;
            overflow-x: auto;
            position: sticky;
            top: 60px;
            z-index: 99;
        }
        .nav-item {
            padding: 8px 20px;
            border-radius: 30px;
            font-size: 14px;
            font-weight: 500;
            background: transparent;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            white-space: nowrap;
            text-decoration: none;
            display: inline-block;
        }
        .nav-item.active {
            background: #3b82f6;
            color: #fff;
        }
        
        .content-tabs {
            background: #111827;
            border-bottom: 1px solid #1e293b;
            padding: 8px 20px;
            display: flex;
            gap: 8px;
            overflow-x: auto;
            position: sticky;
            top: 108px;
            z-index: 98;
        }
        .content-tab {
            padding: 8px 20px;
            border-radius: 30px;
            font-size: 14px;
            font-weight: 500;
            background: transparent;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            white-space: nowrap;
            text-decoration: none;
            display: inline-block;
        }
        .content-tab.active {
            background: #3b82f6;
            color: #fff;
        }
        
        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 24px 20px;
        }
        
        /* Сетка как на iPhone */
        .gallery-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 2px;
            background: #0f172a;
        }
        .gallery-item {
            aspect-ratio: 1 / 1;
            background: #1e293b;
            position: relative;
            cursor: pointer;
            overflow: hidden;
        }
        .gallery-item img, .gallery-item video {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .add-button {
            background: #1e293b;
            border: 2px dashed #334155;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }
        .add-button span {
            font-size: 48px;
            color: #3b82f6;
        }
        .gallery-play {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 32px;
            color: white;
            text-shadow: 0 0 10px rgba(0,0,0,0.5);
        }
        .gallery-overlay {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: linear-gradient(transparent, rgba(0,0,0,0.7));
            padding: 8px;
            opacity: 0;
            transition: 0.2s;
        }
        .gallery-item:hover .gallery-overlay {
            opacity: 1;
        }
        
        .playlist-card {
            background: #111827;
            border-radius: 16px;
            padding: 16px;
            border: 1px solid rgba(255,255,255,0.06);
            margin-bottom: 12px;
        }
        .playlist-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }
        .playlist-name {
            font-weight: 600;
            font-size: 16px;
        }
        .playlist-stats {
            font-size: 11px;
            color: #64748b;
        }
        .playlist-desc {
            font-size: 13px;
            color: #94a3b8;
            margin-bottom: 12px;
        }
        .playlist-actions {
            display: flex;
            gap: 12px;
        }
        .btn-small {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            background: #1e293b;
            color: #fff;
            text-decoration: none;
            cursor: pointer;
            border: none;
        }
        .btn-small:hover {
            background: #334155;
        }
        
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.9);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        .modal {
            background: #111827;
            border-radius: 20px;
            padding: 24px;
            max-width: 400px;
            width: 90%;
        }
        .modal h3 {
            margin-bottom: 16px;
        }
        .modal input, .modal textarea, .modal select {
            width: 100%;
            padding: 12px;
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 12px;
            color: #fff;
            margin-bottom: 12px;
        }
        .modal-buttons {
            display: flex;
            gap: 12px;
            margin-top: 16px;
        }
        .modal-buttons button {
            flex: 1;
            padding: 10px;
            border-radius: 12px;
            cursor: pointer;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #64748b;
        }
        .success {
            background: rgba(34, 197, 94, 0.15);
            border: 1px solid #22c55e;
            padding: 12px;
            border-radius: 12px;
            margin-bottom: 20px;
            color: #4ade80;
        }
        .error {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid #ef4444;
            padding: 12px;
            border-radius: 12px;
            margin-bottom: 20px;
            color: #f87171;
        }
        
        @media (max-width: 600px) {
            .nav-panel { top: 56px; }
            .content-tabs { top: 104px; }
            .container { padding: 16px; }
            .gallery-grid { gap: 1px; }
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
    <a href="/profile/profile.php" class="profile-link">Профиль</a>
</div>

<div class="nav-panel">
    <a href="/feed.php" class="nav-item">Лента</a>
    <a href="/profile/dialogs.php" class="nav-item">Диалоги</a>
    <a href="/profile/content.php" class="nav-item active">Контент</a>
    <a href="/profile/playlist.php" class="nav-item">Плейлисты</a>
    <a href="/profile/privacy.php" class="nav-item">Конфиденциальность</a>
</div>

<div class="content-tabs">
    <a href="?tab=photos" class="content-tab <?= $tab === 'photos' ? 'active' : '' ?>">Фото</a>
    <a href="?tab=videos" class="content-tab <?= $tab === 'videos' ? 'active' : '' ?>">Видео</a>
    <a href="?tab=publications" class="content-tab <?= $tab === 'publications' ? 'active' : '' ?>">Мои публикации</a>
    <a href="?tab=playlists" class="content-tab <?= $tab === 'playlists' ? 'active' : '' ?>">Плейлисты</a>
</div>

<div class="container">
    <?php if ($uploadSuccess): ?>
        <div class="success"><?= htmlspecialchars($uploadSuccess) ?></div>
    <?php endif; ?>
    <?php if ($uploadError): ?>
        <div class="error"><?= htmlspecialchars($uploadError) ?></div>
    <?php endif; ?>
    
    <?php if ($tab === 'photos'): ?>
        <div class="gallery-grid">
            <div class="gallery-item add-button" onclick="openPhotoModal()"><span>+</span></div>
            <?php foreach ($photos as $photo): ?>
                <div class="gallery-item" onclick="viewPhoto('<?= htmlspecialchars($photo['media_path']) ?>', '<?= htmlspecialchars($photo['caption'] ?? '') ?>')">
                    <img src="<?= htmlspecialchars($photo['media_path']) ?>" onerror="this.src='/pwa_icon/icon-96.png'">
                    <div class="gallery-overlay">
                        <div style="font-size: 10px;">❤️ <?= $photo['likes'] ?? 0 ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
    <?php elseif ($tab === 'videos'): ?>
        <div class="gallery-grid">
            <div class="gallery-item add-button" onclick="openVideoModal()"><span>+</span></div>
            <?php foreach ($videos as $video): ?>
                <div class="gallery-item" onclick="viewVideo('<?= htmlspecialchars($video['media_path']) ?>', '<?= htmlspecialchars($video['caption'] ?? '') ?>')">
                    <video src="<?= htmlspecialchars($video['media_path']) ?>" style="width:100%;height:100%;object-fit:cover;"></video>
                    <div class="gallery-play">▶️</div>
                    <div class="gallery-overlay">
                        <div style="font-size: 10px;">❤️ <?= $video['likes'] ?? 0 ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
    <?php elseif ($tab === 'publications'): ?>
        <div class="gallery-grid">
            <div class="gallery-item add-button" onclick="openPublicationModal()"><span>+</span></div>
            <?php foreach ($publications as $pub): ?>
                <div class="gallery-item" onclick="viewPublication(<?= $pub['id'] ?>)">
                    <?php if (strpos($pub['media_path'], '.mp4') !== false || $pub['media_type'] === 'video'): ?>
                        <video src="<?= htmlspecialchars($pub['media_path']) ?>" style="width:100%;height:100%;object-fit:cover;"></video>
                        <div class="gallery-play">▶️</div>
                    <?php elseif ($pub['media_path']): ?>
                        <img src="<?= htmlspecialchars($pub['media_path']) ?>" onerror="this.src='/pwa_icon/icon-96.png'">
                    <?php else: ?>
                        <div style="display:flex;align-items:center;justify-content:center;height:100%;background:#1e293b;">📝</div>
                    <?php endif; ?>
                    <div class="gallery-overlay">
                        <div style="font-size: 10px;">❤️ <?= $pub['likes'] ?? 0 ?> 💬 <?= $pub['comments'] ?? 0 ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
    <?php elseif ($tab === 'playlists'): ?>
        <div style="margin-bottom: 20px;">
            <div class="playlist-card" style="border: 2px dashed #334155; cursor: pointer;" onclick="openPlaylistModal()">
                <div style="display: flex; align-items: center; justify-content: center; gap: 8px; padding: 8px;">
                    <span style="font-size: 24px; color: #3b82f6;">+</span>
                    <span>Создать плейлист</span>
                </div>
            </div>
        </div>
        
        <?php if (count($playlists) > 0): ?>
            <?php foreach ($playlists as $playlist): ?>
                <div class="playlist-card">
                    <div class="playlist-header">
                        <span class="playlist-name"><?= htmlspecialchars($playlist['name']) ?></span>
                        <span class="playlist-stats">📄 <?= $playlist['items_count'] ?? 0 ?> элементов</span>
                    </div>
                    <?php if ($playlist['description']): ?>
                        <div class="playlist-desc"><?= htmlspecialchars($playlist['description']) ?></div>
                    <?php endif; ?>
                    <div class="playlist-actions">
                        <a href="/profile/edit_playlist.php?id=<?= $playlist['id'] ?>" class="btn-small">✏️ Редактировать</a>
                        <a href="/profile/playlist.php?id=<?= $playlist['id'] ?>" class="btn-small">👁️ Просмотр</a>
                        <?php if ($playlist['is_public']): ?>
                            <span class="btn-small" style="background:#334155;">🌍 Публичный</span>
                        <?php else: ?>
                            <span class="btn-small" style="background:#334155;">🔒 Приватный</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state">У вас пока нет плейлистов</div>
        <?php endif; ?>
        
        <!-- Кнопка добавления контента в плейлист -->
        <?php if (count($userContent) > 0 && count($playlists) > 0): ?>
            <div class="playlist-card" style="margin-top: 20px; border-color: #3b82f6;">
                <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
                    <span>➕ Добавить контент в плейлист</span>
                    <button class="btn-small" onclick="openAddContentModal()" style="background:#3b82f6;">Добавить</button>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Модалка добавления фото -->
<div id="photoModal" class="modal-overlay" style="display: none;">
    <div class="modal">
        <h3>📷 Добавить фото</h3>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="media_type" value="photo">
            <input type="file" name="media_file" accept="image/*" required>
            <textarea name="caption" rows="2" placeholder="Описание..."></textarea>
            <label style="display: flex; align-items: center; gap: 8px;">
                <input type="checkbox" name="is_public" checked> Публичный пост
            </label>
            <div class="modal-buttons">
                <button type="submit" style="background:#3b82f6; color:#fff; border:none;">Загрузить</button>
                <button type="button" onclick="closeModal('photoModal')" style="background:#1e293b; color:#fff; border:none;">Отмена</button>
            </div>
        </form>
    </div>
</div>

<!-- Модалка добавления видео -->
<div id="videoModal" class="modal-overlay" style="display: none;">
    <div class="modal">
        <h3>🎬 Добавить видео (макс. 30 сек)</h3>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="media_type" value="video">
            <input type="file" name="media_file" accept="video/*" required>
            <textarea name="caption" rows="2" placeholder="Описание..."></textarea>
            <label style="display: flex; align-items: center; gap: 8px;">
                <input type="checkbox" name="is_public" checked> Публичный пост
            </label>
            <div class="modal-buttons">
                <button type="submit" style="background:#3b82f6; color:#fff; border:none;">Загрузить</button>
                <button type="button" onclick="closeModal('videoModal')" style="background:#1e293b; color:#fff; border:none;">Отмена</button>
            </div>
        </form>
    </div>
</div>

<!-- Модалка добавления публикации -->
<div id="publicationModal" class="modal-overlay" style="display: none;">
    <div class="modal">
        <h3>📝 Новая публикация</h3>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="media_type" value="photo">
            <input type="file" name="media_file" accept="image/*,video/*" required>
            <textarea name="caption" rows="3" placeholder="Текст публикации..."></textarea>
            <label style="display: flex; align-items: center; gap: 8px;">
                <input type="checkbox" name="is_public" checked> Публичный пост
            </label>
            <div class="modal-buttons">
                <button type="submit" style="background:#3b82f6; color:#fff; border:none;">Опубликовать</button>
                <button type="button" onclick="closeModal('publicationModal')" style="background:#1e293b; color:#fff; border:none;">Отмена</button>
            </div>
        </form>
    </div>
</div>

<!-- Модалка создания плейлиста -->
<div id="playlistModal" class="modal-overlay" style="display: none;">
    <div class="modal">
        <h3>➕ Создать плейлист</h3>
        <form method="POST">
            <input type="hidden" name="create_playlist" value="1">
            <input type="text" name="playlist_name" placeholder="Название плейлиста" required>
            <textarea name="playlist_description" rows="2" placeholder="Описание..."></textarea>
            <label style="display: flex; align-items: center; gap: 8px;">
                <input type="checkbox" name="playlist_is_public" checked> Публичный плейлист
            </label>
            <div class="modal-buttons">
                <button type="submit" style="background:#3b82f6; color:#fff; border:none;">Создать</button>
                <button type="button" onclick="closeModal('playlistModal')" style="background:#1e293b; color:#fff; border:none;">Отмена</button>
            </div>
        </form>
    </div>
</div>

<!-- Модалка добавления контента в плейлист -->
<div id="addContentModal" class="modal-overlay" style="display: none;">
    <div class="modal">
        <h3>➕ Добавить в плейлист</h3>
        <form method="POST">
            <input type="hidden" name="add_to_playlist" value="1">
            <select name="playlist_id" required>
                <option value="">-- Выберите плейлист --</option>
                <?php foreach ($playlists as $playlist): ?>
                    <option value="<?= $playlist['id'] ?>"><?= htmlspecialchars($playlist['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="content_id" required>
                <option value="">-- Выберите контент --</option>
                <?php foreach ($userContent as $content): ?>
                    <option value="<?= $content['id'] ?>">
                        <?= $content['media_type'] === 'video' ? '🎬' : '📷' ?> 
                        <?= htmlspecialchars(mb_substr($content['caption'] ?? 'Без описания', 0, 50)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="modal-buttons">
                <button type="submit" style="background:#3b82f6; color:#fff; border:none;">Добавить</button>
                <button type="button" onclick="closeModal('addContentModal')" style="background:#1e293b; color:#fff; border:none;">Отмена</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openPhotoModal() { document.getElementById('photoModal').style.display = 'flex'; }
    function openVideoModal() { document.getElementById('videoModal').style.display = 'flex'; }
    function openPublicationModal() { document.getElementById('publicationModal').style.display = 'flex'; }
    function openPlaylistModal() { document.getElementById('playlistModal').style.display = 'flex'; }
    function openAddContentModal() { document.getElementById('addContentModal').style.display = 'flex'; }
    
    function closeModal(modalId) {
        document.getElementById(modalId).style.display = 'none';
    }
    
    function viewPhoto(path, caption) {
        window.open(path);
    }
    
    function viewVideo(path, caption) {
        window.open(path);
    }
    
    function viewPublication(id) {
        window.location.href = '/post.php?id=' + id;
    }
    
    // Закрытие по клику вне модалки
    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', function(e) {
            if (e.target === this) {
                this.style.display = 'none';
            }
        });
    });
</script>

</body>
</html>