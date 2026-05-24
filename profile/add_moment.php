<?php
session_start();
require __DIR__ . '/../inc/dd_bb.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /auth/login.php');
    exit;
}

$userId = $_SESSION['user_id'];
$error = '';
$success = '';

// Получаем каналы пользователя (для публикации от имени канала)
$stmt = $pdo->prepare("
    SELECT c.id, c.name, cs.role 
    FROM channels c
    JOIN channel_subscribers cs ON cs.channel_id = c.id
    WHERE cs.user_id = ? AND cs.role IN ('owner', 'admin')
");
$stmt->execute([$userId]);
$channels = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['video'])) {
    $channelId = !empty($_POST['channel_id']) ? (int)$_POST['channel_id'] : null;
    $title = trim($_POST['title'] ?? '');
    
    if ($_FILES['video']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/../uploads/moments/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        
        $ext = strtolower(pathinfo($_FILES['video']['name'], PATHINFO_EXTENSION));
        $allowedExt = ['mp4', 'webm', 'mov'];
        
        if (!in_array($ext, $allowedExt)) {
            $error = 'Можно загружать только MP4, WebM, MOV';
        } else {
            // Проверяем длительность видео (не более 60 секунд)
            $tempFile = $_FILES['video']['tmp_name'];
            $ffprobe = 'ffprobe'; // путь к ffprobe на сервере
            
            // Если ffprobe доступен, проверяем длительность
            if (function_exists('shell_exec')) {
                $duration = shell_exec("$ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($tempFile));
                $duration = (int)round((float)$duration);
                
                if ($duration > 60) {
                    $error = 'Видео не должно быть длиннее 60 секунд';
                }
            }
            
            if (!$error) {
                $filename = time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                $targetPath = $uploadDir . $filename;
                $videoPath = '/uploads/moments/' . $filename;
                
                if (move_uploaded_file($tempFile, $targetPath)) {
                    // Создаем превью (первый кадр)
                    $thumbnailPath = null;
                    if (function_exists('shell_exec')) {
                        $thumbnailFile = $uploadDir . 'thumb_' . $filename . '.jpg';
                        $thumbnailUrl = '/uploads/moments/thumb_' . $filename . '.jpg';
                        shell_exec("ffmpeg -i " . escapeshellarg($targetPath) . " -ss 00:00:01 -vframes 1 -vf scale=320:-1 " . escapeshellarg($thumbnailFile) . " 2>/dev/null");
                        if (file_exists($thumbnailFile)) {
                            $thumbnailPath = $thumbnailUrl;
                        }
                    }
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO moments (user_id, channel_id, video_path, thumbnail_path, title, duration, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([$userId, $channelId, $videoPath, $thumbnailPath, $title, $duration ?? 0]);
                    $success = 'Видео успешно добавлено!';
                    
                    // Перенаправляем в ленту
                    header('Location: /feed.php?type=moments');
                    exit;
                } else {
                    $error = 'Ошибка при сохранении файла';
                }
            }
        }
    } else {
        $error = 'Ошибка загрузки файла';
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Добавить момент — Лист</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Display', system-ui, sans-serif;
            background: #0f172a;
            color: #fff;
            min-height: 100vh;
            padding: 20px;
        }
        .container { max-width: 500px; margin: 0 auto; background: #111827; border-radius: 24px; padding: 24px; }
        h1 { font-size: 24px; margin-bottom: 24px; text-align: center; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-size: 14px; font-weight: 500; color: #94a3b8; }
        input, select, textarea {
            width: 100%;
            padding: 12px;
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 12px;
            color: #fff;
            font-size: 15px;
        }
        .video-preview {
            margin-top: 16px;
            display: none;
        }
        .video-preview video {
            width: 100%;
            border-radius: 12px;
            max-height: 300px;
        }
        button {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            border: none;
            border-radius: 40px;
            color: #fff;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: 0.2s;
        }
        button:hover { transform: translateY(-1px); opacity: 0.9; }
        .error { color: #f87171; margin-bottom: 16px; text-align: center; }
        .success { color: #4ade80; margin-bottom: 16px; text-align: center; }
        .info { font-size: 12px; color: #64748b; margin-top: 8px; text-align: center; }
        .back-link {
            display: block;
            text-align: center;
            margin-top: 20px;
            color: #94a3b8;
            text-decoration: none;
        }
        .file-label {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 40px;
            background: #1e293b;
            border: 2px dashed #334155;
            border-radius: 16px;
            cursor: pointer;
            transition: 0.2s;
        }
        .file-label:hover { border-color: #3b82f6; background: #1e293b; }
        .file-label span { font-size: 48px; margin-bottom: 12px; }
        #videoFile { display: none; }
        @media (max-width: 600px) {
            .container { padding: 18px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>✨ Добавить момент</h1>
        <p class="info">Короткое видео до 60 секунд</p>
        
        <?php if ($error): ?>
            <div class="error">❌ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="success">✅ <?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        
        <form method="POST" enctype="multipart/form-data" id="uploadForm">
            <div class="form-group">
                <label>📹 Видео (до 60 сек)</label>
                <div class="file-label" onclick="document.getElementById('videoFile').click()">
                    <span>🎬</span>
                    <span>Нажмите для выбора видео</span>
                </div>
                <input type="file" name="video" id="videoFile" accept="video/mp4,video/webm,video/quicktime" required>
                <div class="video-preview" id="videoPreview">
                    <video controls></video>
                </div>
            </div>
            
            <div class="form-group">
                <label>📝 Название (опционально)</label>
                <input type="text" name="title" placeholder="Краткое описание момента...">
            </div>
            
            <?php if (!empty($channels)): ?>
            <div class="form-group">
                <label>📢 Опубликовать от имени канала</label>
                <select name="channel_id">
                    <option value="">От своего имени</option>
                    <?php foreach ($channels as $channel): ?>
                        <option value="<?= $channel['id'] ?>"><?= htmlspecialchars($channel['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            
            <button type="submit">Опубликовать момент</button>
        </form>
        
        <a href="/feed.php" class="back-link">← Вернуться в ленту</a>
    </div>
    
    <script>
        const videoInput = document.getElementById('videoFile');
        const videoPreview = document.getElementById('videoPreview');
        const previewVideo = videoPreview.querySelector('video');
        
        videoInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const url = URL.createObjectURL(file);
                previewVideo.src = url;
                videoPreview.style.display = 'block';
                
                // Проверяем длительность
                previewVideo.addEventListener('loadedmetadata', function() {
                    if (this.duration > 60) {
                        alert('Видео длиннее 60 секунд! Пожалуйста, выберите более короткое видео.');
                        videoInput.value = '';
                        videoPreview.style.display = 'none';
                    }
                });
            }
        });
    </script>
</body>
</html>