<?php
session_start();
require __DIR__ . '/../inc/dd_bb.php';

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    header('Location: /auth/login.php');
    exit;
}

$playlistId = (int)($_GET['id'] ?? 0);
if (!$playlistId) {
    header('Location: /profile/content.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM playlists WHERE id = ?");
$stmt->execute([$playlistId]);
$playlist = $stmt->fetch();

if (!$playlist || ($playlist['user_id'] != $userId && !$playlist['is_public'])) {
    header('Location: /profile/content.php');
    exit;
}

$isOwner = ($playlist['user_id'] == $userId);

$error = '';
$success = '';

// Обработка добавления контента
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file']) && $isOwner) {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $file = $_FILES['file'];
    
    // Все поддерживаемые форматы изображений
    $allowedImage = [
        'image/jpeg', 'image/jpg', 'image/png', 'image/gif', 
        'image/webp', 'image/avif', 'image/bmp', 'image/tiff', 
        'image/svg+xml', 'image/x-icon', 'image/heic', 'image/heif'
    ];
    
    // Все поддерживаемые форматы видео
    $allowedVideo = [
        'video/mp4', 'video/mpeg', 'video/ogg', 'video/webm', 
        'video/quicktime', 'video/x-msvideo', 'video/x-matroska',
        'video/3gpp', 'video/x-flv', 'video/x-ms-wmv'
    ];
    
    $fileType = null;
    $uploadDir = null;
    
    if (in_array($file['type'], $allowedImage)) {
        $fileType = 'image';
        $uploadDir = __DIR__ . '/../uploads/content/images/';
    } elseif (in_array($file['type'], $allowedVideo)) {
        $fileType = 'video';
        $uploadDir = __DIR__ . '/../uploads/content/videos/';
    } else {
        $error = 'Неподдерживаемый формат файла: ' . $file['type'];
    }
    
    if (!$error && $file['error'] === UPLOAD_ERR_OK) {
        // Создаём папки, если их нет
        if (!is_dir(__DIR__ . '/../uploads/')) mkdir(__DIR__ . '/../uploads/', 0755, true);
        if (!is_dir(__DIR__ . '/../uploads/content/')) mkdir(__DIR__ . '/../uploads/content/', 0755, true);
        if (!is_dir(__DIR__ . '/../uploads/content/images/')) mkdir(__DIR__ . '/../uploads/content/images/', 0755, true);
        if (!is_dir(__DIR__ . '/../uploads/content/videos/')) mkdir(__DIR__ . '/../uploads/content/videos/', 0755, true);
        
        // Безопасное имя файла
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $filename = time() . '_' . uniqid() . '.' . $ext;
        $targetPath = $uploadDir . $filename;
        $dbPath = '/uploads/content/' . ($fileType === 'image' ? 'images/' : 'videos/') . $filename;
        
        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            $stmt = $pdo->prepare("INSERT INTO content_items (playlist_id, user_id, type, file_path, title, description, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$playlistId, $userId, $fileType, $dbPath, $title, $description]);
            $success = 'Контент добавлен';
            header("Location: /profile/playlist.php?id=" . $playlistId);
            exit;
        } else {
            $error = 'Ошибка сохранения файла. Проверьте права на папку uploads/';
        }
    } elseif (!$error) {
        $error = 'Ошибка загрузки файла. Код: ' . $file['error'];
    }
}

// Обработка удаления контента
if (isset($_GET['delete_content']) && $isOwner) {
    $contentId = (int)$_GET['delete_content'];
    $stmt = $pdo->prepare("SELECT file_path FROM content_items WHERE id = ? AND playlist_id = ?");
    $stmt->execute([$contentId, $playlistId]);
    $item = $stmt->fetch();
    if ($item && file_exists(__DIR__ . '/..' . $item['file_path'])) {
        @unlink(__DIR__ . '/..' . $item['file_path']);
    }
    $stmt = $pdo->prepare("DELETE FROM content_items WHERE id = ? AND playlist_id = ?");
    $stmt->execute([$contentId, $playlistId]);
    header("Location: /profile/playlist.php?id=" . $playlistId);
    exit;
}

// Получаем контент
$stmt = $pdo->prepare("SELECT * FROM content_items WHERE playlist_id = ? ORDER BY created_at DESC");
$stmt->execute([$playlistId]);
$items = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= htmlspecialchars($playlist['name']) ?> — Лист</title>
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
            padding: 12px 32px;
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
        .nav-links { display: flex; gap: 24px; }
        .nav-link { color: #94a3b8; text-decoration: none; font-size: 14px; font-weight: 500; }
        .nav-link:hover { color: #fff; }
        .container { max-width: 1000px; margin: 0 auto; padding: 32px 24px; }
        
        .playlist-header {
            margin-bottom: 32px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 16px;
        }
        .playlist-title h1 { font-size: 28px; margin-bottom: 8px; }
        .playlist-desc { color: #94a3b8; font-size: 14px; }
        
        .btn {
            padding: 10px 20px;
            border-radius: 40px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: 0.2s;
            border: none;
            background: #1e293b;
            color: #fff;
            text-decoration: none;
            display: inline-block;
        }
        .btn-primary { background: #3b82f6; }
        .btn-primary:hover { background: #2563eb; transform: translateY(-1px); }
        .btn-secondary { background: #1e293b; }
        .btn-secondary:hover { background: #334155; transform: translateY(-1px); }
        
        .add-card {
            background: #111827;
            border-radius: 24px;
            border: 1px solid rgba(255,255,255,0.06);
            padding: 24px;
            margin-bottom: 32px;
        }
        .add-card h2 { font-size: 18px; margin-bottom: 16px; font-weight: 500; }
        .upload-area {
            border: 2px dashed #334155;
            border-radius: 20px;
            padding: 40px;
            text-align: center;
            cursor: pointer;
            transition: 0.2s;
            background: #1e293b;
        }
        .upload-area:hover {
            border-color: #3b82f6;
            background: #334155;
        }
        .upload-icon { font-size: 48px; margin-bottom: 12px; }
        .upload-text { font-size: 14px; color: #94a3b8; margin-bottom: 8px; }
        .upload-hint { font-size: 12px; color: #64748b; }
        #fileInput { display: none; }
        
        .preview-area {
            display: none;
            margin-top: 20px;
            background: #1e293b;
            border-radius: 16px;
            padding: 16px;
        }
        .preview-media {
            max-width: 100%;
            max-height: 200px;
            border-radius: 12px;
            margin-bottom: 16px;
        }
        .preview-info input, .preview-info textarea {
            width: 100%;
            padding: 10px 12px;
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 12px;
            color: #fff;
            margin-bottom: 12px;
            font-size: 14px;
        }
        .preview-info textarea { resize: vertical; min-height: 60px; }
        .preview-actions { display: flex; gap: 12px; justify-content: flex-end; }
        
        .content-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
        }
        .content-item {
            background: #1e293b;
            border-radius: 16px;
            overflow: hidden;
            transition: 0.2s;
        }
        .content-item:hover { transform: translateY(-2px); }
        .content-media {
            width: 100%;
            height: 200px;
            object-fit: cover;
            background: #0f172a;
        }
        .content-info { padding: 16px; }
        .content-title { font-weight: 600; margin-bottom: 4px; font-size: 15px; }
        .content-desc { font-size: 13px; color: #94a3b8; margin-bottom: 8px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .content-meta { font-size: 11px; color: #64748b; display: flex; justify-content: space-between; align-items: center; }
        .delete-btn { background: #7f1a1a; padding: 4px 12px; border-radius: 20px; font-size: 11px; text-decoration: none; color: #fff; }
        .delete-btn:hover { background: #991b1b; }
        
        .error { background: rgba(239,68,68,0.15); border: 1px solid #ef4444; padding: 12px; border-radius: 12px; margin-bottom: 20px; text-align: center; }
        .success { background: rgba(34,197,94,0.15); border: 1px solid #22c55e; padding: 12px; border-radius: 12px; margin-bottom: 20px; text-align: center; }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #64748b;
        }
        .back-link { display: inline-block; margin-top: 32px; color: #94a3b8; text-decoration: none; font-size: 14px; }
        .back-link:hover { color: #3b82f6; }
        
        @media (max-width: 600px) {
            .header { padding: 12px 20px; }
            .container { padding: 24px 16px; }
            .playlist-title h1 { font-size: 24px; }
            .content-grid { grid-template-columns: 1fr; }
            .upload-area { padding: 24px; }
        }
    </style>
</head>
<body>
    <div class="header">
        <a href="/" class="logo">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
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

    <div class="container">
        <div class="playlist-header">
            <div class="playlist-title">
                <h1><?= htmlspecialchars($playlist['name']) ?></h1>
                <div class="playlist-desc"><?= nl2br(htmlspecialchars($playlist['description'] ?? 'Нет описания')) ?></div>
            </div>
            <?php if ($isOwner): ?>
                <a href="/profile/edit_playlist.php?id=<?= $playlistId ?>" class="btn btn-secondary">Редактировать</a>
            <?php endif; ?>
        </div>

        <?php if ($error): ?>
            <div class="error">❌ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="success">✅ <?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <?php if ($isOwner): ?>
            <div class="add-card">
                <h2>Добавить в плейлист</h2>
                <form method="POST" enctype="multipart/form-data" id="uploadForm">
                    <div class="upload-area" id="uploadArea">
                        <div class="upload-icon">📷</div>
                        <div class="upload-text">Нажмите, чтобы выбрать файл</div>
                        <div class="upload-hint">Поддерживаются: JPG, PNG, GIF, WEBP, AVIF, BMP, TIFF, MP4, WebM, AVI, MOV и другие</div>
                    </div>
                    <input type="file" name="file" id="fileInput" accept="image/*,video/*" style="display:none;">
                    
                    <div class="preview-area" id="previewArea" style="display:none;">
                        <div id="previewContent"></div>
                        <div class="preview-info">
                            <input type="text" name="title" id="previewTitle" placeholder="Заголовок (необязательно)">
                            <textarea name="description" id="previewDesc" placeholder="Описание (необязательно)"></textarea>
                            <div class="preview-actions">
                                <button type="button" class="btn btn-secondary" id="cancelPreviewBtn">Отмена</button>
                                <button type="submit" class="btn btn-primary" id="confirmUploadBtn">Добавить</button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <?php if (empty($items)): ?>
            <div class="empty-state">
                <div style="font-size: 48px; margin-bottom: 12px;">📭</div>
                <p>В этом плейлисте пока нет контента</p>
                <?php if ($isOwner): ?>
                    <p style="font-size: 13px; margin-top: 8px;">Нажмите на область выше, чтобы добавить фото или видео</p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="content-grid">
                <?php foreach ($items as $item): ?>
                    <div class="content-item">
                        <?php if ($item['type'] === 'image'): ?>
                            <img class="content-media" src="<?= htmlspecialchars($item['file_path']) ?>" alt="">
                        <?php else: ?>
                            <video class="content-media" controls>
                                <source src="<?= htmlspecialchars($item['file_path']) ?>" type="video/mp4">
                            </video>
                        <?php endif; ?>
                        <div class="content-info">
                            <div class="content-title"><?= htmlspecialchars($item['title'] ?? 'Без названия') ?></div>
                            <div class="content-desc"><?= htmlspecialchars(mb_substr($item['description'] ?? '', 0, 60)) ?></div>
                            <div class="content-meta">
                                <span>👁️ <?= $item['views'] ?></span>
                                <span>📅 <?= date('d.m.Y', strtotime($item['created_at'])) ?></span>
                                <?php if ($isOwner): ?>
                                    <a href="?delete_content=<?= $item['id'] ?>&id=<?= $playlistId ?>" class="delete-btn" onclick="return confirm('Удалить этот контент?')">Удалить</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <a href="/profile/content.php" class="back-link">← Вернуться к плейлистам</a>
    </div>

    <script>
        const uploadArea = document.getElementById('uploadArea');
        const fileInput = document.getElementById('fileInput');
        const previewArea = document.getElementById('previewArea');
        const previewContent = document.getElementById('previewContent');
        const previewTitle = document.getElementById('previewTitle');
        const previewDesc = document.getElementById('previewDesc');
        const cancelPreviewBtn = document.getElementById('cancelPreviewBtn');
        
        uploadArea.addEventListener('click', () => fileInput.click());
        
        fileInput.addEventListener('change', (e) => {
            const file = e.target.files[0];
            if (!file) return;
            
            if (!file.type.startsWith('image/') && !file.type.startsWith('video/')) {
                alert('Поддерживаются только изображения и видео');
                return;
            }
            
            const reader = new FileReader();
            reader.onload = (e) => {
                if (file.type.startsWith('image/')) {
                    previewContent.innerHTML = `<img class="preview-media" src="${e.target.result}" alt="Preview">`;
                } else {
                    previewContent.innerHTML = `<video class="preview-media" controls><source src="${e.target.result}" type="${file.type}"></video>`;
                }
                previewTitle.value = '';
                previewDesc.value = '';
                previewArea.style.display = 'block';
                uploadArea.style.display = 'none';
            };
            reader.readAsDataURL(file);
        });
        
        cancelPreviewBtn.addEventListener('click', () => {
            previewArea.style.display = 'none';
            uploadArea.style.display = 'block';
            fileInput.value = '';
            previewContent.innerHTML = '';
        });
    </script>
</body>
</html>