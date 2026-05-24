<?php
session_start();
require __DIR__ . '/inc/dd_bb.php';

$postId = (int)($_GET['id'] ?? 0);
if (!$postId) {
    header('Location: /feed.php');
    exit;
}

// Получаем публикацию
$stmt = $pdo->prepare("
    SELECT cp.*, u.name, u.uid, p.avatar
    FROM content_posts cp
    JOIN users u ON u.id = cp.user_id
    LEFT JOIN profiles p ON p.user_id = u.id
    WHERE cp.id = ?
");
$stmt->execute([$postId]);
$post = $stmt->fetch();

if (!$post) {
    header('Location: /feed.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Публикация — Лист</title>
    <link rel="manifest" href="/manifest.json">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background: #0f172a;
            color: #f1f5f9;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .post-container {
            max-width: 600px;
            width: 100%;
            background: #111827;
            border-radius: 24px;
            overflow: hidden;
            border: 1px solid rgba(255,255,255,0.06);
        }
        .post-header {
            padding: 16px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .post-avatar {
            width: 44px;
            height: 44px;
            border-radius: 22px;
            object-fit: cover;
        }
        .post-author {
            font-weight: 600;
            font-size: 16px;
        }
        .post-time {
            font-size: 12px;
            color: #64748b;
        }
        .post-media {
            width: 100%;
            max-height: 500px;
            object-fit: contain;
            background: #000;
            cursor: pointer;
        }
        .post-caption {
            padding: 16px;
            font-size: 15px;
            line-height: 1.5;
        }
        .back-btn {
            display: inline-block;
            margin: 16px;
            color: #3b82f6;
            text-decoration: none;
        }
        @media (max-width: 600px) {
            body { padding: 0; }
            .post-container { border-radius: 0; }
        }
    </style>
</head>
<body>
    <div class="post-container">
        <div class="post-header">
            <img class="post-avatar" src="<?= htmlspecialchars($post['avatar'] ?? '/pwa_icon/icon-96.png') ?>" onerror="this.src='/pwa_icon/icon-96.png'">
            <div>
                <div class="post-author"><?= htmlspecialchars($post['name']) ?></div>
                <div class="post-time"><?= date('d.m.Y H:i', strtotime($post['created_at'])) ?></div>
            </div>
        </div>
        
        <?php if (!empty($post['media_path'])): ?>
            <?php $ext = strtolower(pathinfo($post['media_path'], PATHINFO_EXTENSION)); ?>
            <?php if (in_array($ext, ['mp4', 'webm', 'mov', 'avi'])): ?>
                <video class="post-media" controls src="<?= htmlspecialchars($post['media_path']) ?>" onclick="this.requestFullscreen?.()"></video>
            <?php else: ?>
                <img class="post-media" src="<?= htmlspecialchars($post['media_path']) ?>" alt="" onclick="openImageModal(this.src)">
            <?php endif; ?>
        <?php endif; ?>
        
        <?php if (!empty($post['caption'])): ?>
            <div class="post-caption"><?= nl2br(htmlspecialchars($post['caption'])) ?></div>
        <?php endif; ?>
        
        <a href="/feed.php" class="back-btn">← Назад к ленте</a>
    </div>
    
    <script>
        function openImageModal(src) {
            const modal = document.createElement('div');
            modal.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.95);z-index:2000;display:flex;align-items:center;justify-content:center;cursor:pointer;';
            modal.innerHTML = `<img src="${escapeHtml(src)}" style="max-width:95%;max-height:95%;object-fit:contain;"><div style="position:absolute;top:20px;right:20px;width:44px;height:44px;border-radius:22px;background:rgba(0,0,0,0.5);color:white;font-size:28px;display:flex;align-items:center;justify-content:center;">×</div>`;
            document.body.appendChild(modal);
            modal.addEventListener('click', () => modal.remove());
        }
        
        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/[&<>]/g, function(m) {
                if (m === '&') return '&amp;';
                if (m === '<') return '&lt;';
                if (m === '>') return '&gt;';
                return m;
            });
        }
    </script>
</body>
</html>