<?php
session_start();
require __DIR__ . '/../inc/dd_bb.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /auth/login.php');
    exit;
}

$userId = $_SESSION['user_id'];

// Получаем свои истории
$stmt = $pdo->prepare("
    SELECT id, media_type, media_path, created_at, views, expires_at
    FROM stories 
    WHERE user_id = ? AND expires_at > NOW()
    ORDER BY created_at DESC
");
$stmt->execute([$userId]);
$myStories = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Мои истории — Лист</title>
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
        .back-link {
            color: #94a3b8;
            text-decoration: none;
            font-size: 14px;
            padding: 8px 16px;
            border-radius: 40px;
            background: #1e293b;
        }
        
        .stories-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 16px;
            padding: 20px;
            max-width: 800px;
            margin: 0 auto;
        }
        .story-card {
            background: #111827;
            border-radius: 16px;
            overflow: hidden;
            border: 1px solid rgba(255,255,255,0.06);
            position: relative;
            cursor: pointer;
        }
        .story-card-media {
            width: 100%;
            aspect-ratio: 9 / 16;
            object-fit: cover;
        }
        .story-card-info {
            padding: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .story-card-views {
            font-size: 12px;
            color: #64748b;
        }
        .delete-story-btn {
            background: #ef4444;
            border: none;
            padding: 4px 10px;
            border-radius: 20px;
            color: #fff;
            font-size: 11px;
            cursor: pointer;
        }
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #64748b;
        }
        
        /* Модалка просмотра истории */
        .story-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: #000;
            z-index: 2000;
            justify-content: center;
            align-items: center;
            flex-direction: column;
        }
        .story-modal.active {
            display: flex;
        }
        .story-modal-media {
            max-width: 100%;
            max-height: 80%;
            border-radius: 16px;
        }
        .story-modal-close {
            position: absolute;
            top: 20px;
            right: 20px;
            width: 40px;
            height: 40px;
            background: rgba(0,0,0,0.5);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
            cursor: pointer;
            z-index: 2001;
        }
        .story-delete-btn {
            position: absolute;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%);
            background: #ef4444;
            border: none;
            padding: 12px 24px;
            border-radius: 30px;
            color: #fff;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            z-index: 2001;
        }
        
        @media (max-width: 600px) {
            .stories-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
                padding: 16px;
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
    <a href="/profile/profile.php" class="back-link">← Назад</a>
</div>

<div class="stories-grid" id="storiesGrid">
    <?php if (count($myStories) > 0): ?>
        <?php foreach ($myStories as $story): ?>
            <div class="story-card" data-id="<?= $story['id'] ?>" data-media="/<?= $story['media_path'] ?>" data-type="<?= $story['media_type'] ?>">
                <?php if ($story['media_type'] === 'video'): ?>
                    <video class="story-card-media" src="/<?= $story['media_path'] ?>"></video>
                <?php else: ?>
                    <img class="story-card-media" src="/<?= $story['media_path'] ?>" onerror="this.src='/pwa_icon/icon-96.png'">
                <?php endif; ?>
                <div class="story-card-info">
                    <span class="story-card-views">👁️ <?= $story['views'] ?> просмотров</span>
                    <button class="delete-story-btn" onclick="deleteStory(<?= $story['id'] ?>, event)">Удалить</button>
                </div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="empty-state">📸 У вас пока нет историй<br><a href="/profile/profile.php" style="color:#3b82f6;">Создать историю</a></div>
    <?php endif; ?>
</div>

<!-- Модалка просмотра истории -->
<div id="storyModal" class="story-modal">
    <div class="story-modal-close" onclick="closeStoryModal()">×</div>
    <img id="storyModalImage" class="story-modal-media" style="display: none;">
    <video id="storyModalVideo" class="story-modal-media" style="display: none;" controls autoplay></video>
    <button class="story-delete-btn" id="deleteStoryBtn">Удалить историю</button>
</div>

<script>
let currentStoryId = null;

async function deleteStory(storyId, event) {
    event.stopPropagation();
    
    if (!confirm('Удалить эту историю?')) return;
    
    try {
        const response = await fetch('/api/story_delete.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ story_id: storyId })
        });
        const data = await response.json();
        
        if (data.success) {
            location.reload();
        } else {
            alert('Ошибка удаления');
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Ошибка удаления');
    }
}

function openStoryModal(id, mediaUrl, mediaType) {
    currentStoryId = id;
    const modal = document.getElementById('storyModal');
    const img = document.getElementById('storyModalImage');
    const video = document.getElementById('storyModalVideo');
    
    if (mediaType === 'video') {
        img.style.display = 'none';
        video.style.display = 'block';
        video.src = mediaUrl;
        video.play();
    } else {
        video.style.display = 'none';
        img.style.display = 'block';
        img.src = mediaUrl;
    }
    
    modal.classList.add('active');
}

function closeStoryModal() {
    const modal = document.getElementById('storyModal');
    const video = document.getElementById('storyModalVideo');
    video.pause();
    video.src = '';
    modal.classList.remove('active');
    currentStoryId = null;
}

// Кнопка удаления в модалке
document.getElementById('deleteStoryBtn')?.addEventListener('click', async () => {
    if (currentStoryId && confirm('Удалить эту историю?')) {
        await deleteStory(currentStoryId, { stopPropagation: () => {} });
        closeStoryModal();
        location.reload();
    }
});

// Закрытие по клику на фон
document.getElementById('storyModal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closeStoryModal();
    }
});

// Клик по карточке для просмотра
document.querySelectorAll('.story-card').forEach(card => {
    card.addEventListener('click', function(e) {
        if (e.target.classList.contains('delete-story-btn')) return;
        const id = this.dataset.id;
        const media = this.dataset.media;
        const type = this.dataset.type;
        openStoryModal(id, media, type);
    });
});
</script>

</body>
</html>