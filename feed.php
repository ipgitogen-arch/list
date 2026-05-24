<?php
session_start();
require __DIR__ . '/inc/dd_bb.php';
require __DIR__ . '/inc/access_check.php';
checkPageAccess('feed');

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    header('Location: /auth/login.php');
    exit;
}

$type = $_GET['type'] ?? 'moments';
$allowedTypes = ['moments', 'posts'];
if (!in_array($type, $allowedTypes)) {
    $type = 'moments';
}

// Получаем счетчики
$stmt = $pdo->prepare("SELECT COUNT(*) FROM moments");
$stmt->execute();
$momentsCount = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT friend_id FROM friends WHERE user_id = ? UNION SELECT user_id FROM friends WHERE friend_id = ?");
$stmt->execute([$userId, $userId]);
$friendIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
$userIds = array_merge($friendIds, [$userId]);
$userIdsStr = !empty($userIds) ? implode(',', $userIds) : '0';

$stmt = $pdo->prepare("SELECT channel_id FROM channel_subscribers WHERE user_id = ?");
$stmt->execute([$userId]);
$subscribedChannels = $stmt->fetchAll(PDO::FETCH_COLUMN);
$channelsStr = !empty($subscribedChannels) ? implode(',', $subscribedChannels) : '0';

$stmt = $pdo->prepare("SELECT COUNT(*) FROM content_posts WHERE is_public = 1 AND user_id IN ($userIdsStr)");
$stmt->execute();
$postsCount = (int)$stmt->fetchColumn();

if ($channelsStr != '0') {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM channel_messages WHERE channel_id IN ($channelsStr)");
    $stmt->execute();
    $postsCount += (int)$stmt->fetchColumn();
}

$counts = ['moments' => $momentsCount, 'posts' => $postsCount];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=yes">
    <title>Лента — Лист</title>
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
            font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Display', 'SF Pro Text', system-ui, sans-serif;
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
            font-weight: 700;
            text-decoration: none;
            color: #fff;
        }
        
        .logo svg {
            width: 24px;
            height: 24px;
        }
        
        .profile-link {
            color: #fff;
            text-decoration: none;
            font-size: 14px;
            background: rgba(255,255,255,0.2);
            padding: 8px 16px;
            border-radius: 40px;
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
        
        .nav-panel::-webkit-scrollbar {
            display: none;
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
            transition: all 0.2s ease;
            white-space: nowrap;
        }
        
        .nav-item.active {
            background: #3b82f6;
            color: #fff;
        }
        
        .nav-item:hover:not(.active) {
            background: #1e293b;
            color: #fff;
        }
        
        .nav-count {
            background: rgba(255,255,255,0.1);
            padding: 2px 6px;
            border-radius: 20px;
            font-size: 11px;
            margin-left: 6px;
        }
        
        .content-section {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .content-card {
            background: #111827;
            border-radius: 20px;
            margin-bottom: 20px;
            overflow: hidden;
            border: 1px solid rgba(255,255,255,0.06);
        }
        
        .video-container {
            position: relative;
            background: #000;
            width: 100%;
            aspect-ratio: 9 / 16;
            max-height: 70vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .video-container video {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        
        .image-container {
            width: 100%;
            max-height: 500px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #000;
        }
        
        .image-container img {
            width: 100%;
            max-height: 500px;
            object-fit: contain;
        }
        
        .card-header {
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .avatar {
            width: 40px;
            height: 40px;
            border-radius: 20px;
            object-fit: cover;
        }
        
        .author-info {
            flex: 1;
        }
        
        .author-name {
            font-weight: 600;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }
        
        .channel-badge {
            background: #8b5cf6;
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 9px;
        }
        
        .time {
            font-size: 11px;
            color: #64748b;
        }
        
        .caption {
            padding: 12px 16px;
            font-size: 14px;
            line-height: 1.5;
            color: #e2e8f0;
        }
        
        .actions {
            display: flex;
            gap: 16px;
            padding: 8px 16px 16px;
            border-top: 1px solid #1e293b;
        }
        
        .action-btn {
            display: flex;
            align-items: center;
            gap: 6px;
            background: transparent;
            border: none;
            color: #94a3b8;
            font-size: 13px;
            cursor: pointer;
            padding: 6px 12px;
            border-radius: 30px;
            transition: 0.2s;
        }
        
        .action-btn:hover {
            background: #1e293b;
            color: #fff;
        }
        
        .action-btn.liked {
            color: #ef4444;
        }
        
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.8);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        
        .share-modal {
            background: #1e293b;
            border-radius: 28px;
            width: 90%;
            max-width: 450px;
            max-height: 80vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            border: 1px solid rgba(255,255,255,0.08);
        }
        
        .share-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 20px;
            border-bottom: 1px solid #334155;
        }
        
        .share-list {
            flex: 1;
            overflow-y: auto;
            padding: 12px;
        }
        
        .share-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 12px;
            border-radius: 14px;
            cursor: pointer;
            transition: 0.2s;
        }
        
        .share-item:hover {
            background: #334155;
        }
        
        .share-item.selected {
            background: #334155;
            border-left: 3px solid #3b82f6;
        }
        
        .share-item-avatar {
            width: 44px;
            height: 44px;
            border-radius: 22px;
            object-fit: cover;
        }
        
        .share-item-info {
            flex: 1;
        }
        
        .share-item-name {
            font-weight: 500;
            font-size: 14px;
        }
        
        .share-item-checkbox {
            width: 20px;
            height: 20px;
            border-radius: 20px;
            border: 2px solid #64748b;
            background: transparent;
            flex-shrink: 0;
        }
        
        .share-item.selected .share-item-checkbox {
            background: #3b82f6;
            border-color: #3b82f6;
            position: relative;
        }
        
        .share-item.selected .share-item-checkbox::after {
            content: "✓";
            color: white;
            font-size: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .share-footer {
            display: flex;
            gap: 12px;
            padding: 14px 16px;
            border-top: 1px solid #334155;
        }
        
        .share-copy-btn, .share-send-btn {
            flex: 1;
            padding: 10px;
            border-radius: 30px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            border: none;
            transition: 0.2s;
        }
        
        .share-copy-btn {
            background: #334155;
            color: #fff;
        }
        
        .share-copy-btn:hover {
            background: #475569;
        }
        
        .share-send-btn {
            background: #3b82f6;
            color: #fff;
        }
        
        .share-send-btn:hover {
            background: #2563eb;
        }
        
        .share-loading {
            text-align: center;
            padding: 20px;
            color: #94a3b8;
        }
        
        .share-modal-close {
            background: none;
            border: none;
            color: #94a3b8;
            font-size: 24px;
            cursor: pointer;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 16px;
        }
        
        .share-modal-close:hover {
            background: #334155;
            color: #fff;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #64748b;
        }
        
        .loading {
            text-align: center;
            padding: 20px;
            color: #64748b;
        }
        
        @media (max-width: 768px) {
            .header {
                padding: 10px 16px;
                padding-top: max(10px, env(safe-area-inset-top));
            }
            .logo { font-size: 18px; }
            .logo svg { width: 20px; height: 20px; }
            .profile-link { font-size: 12px; padding: 6px 12px; }
            .nav-panel {
                top: 54px;
                padding: 6px 12px;
            }
            .nav-item {
                padding: 6px 14px;
                font-size: 13px;
            }
            .content-section {
                padding: 16px;
            }
            .video-container {
                max-height: 60vh;
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
        <a href="/profile/profile.php" class="profile-link">Профиль</a>
    </div>
    
    <div class="nav-panel">
        <button class="nav-item <?= $type === 'moments' ? 'active' : '' ?>" data-type="moments">
            Моменты <span class="nav-count"><?= $counts['moments'] ?></span>
        </button>
        <button class="nav-item <?= $type === 'posts' ? 'active' : '' ?>" data-type="posts">
            Публикации <span class="nav-count"><?= $counts['posts'] ?></span>
        </button>
    </div>
    
    <div class="content-section" id="contentSection">
        <div id="loading" class="loading" style="display: none;">Загрузка...</div>
        <div id="contentContainer"></div>
    </div>
    
    <div id="shareModal" class="modal-overlay" style="display: none;">
        <div class="share-modal">
            <div class="share-modal-header">
                <h3>Поделиться</h3>
                <button class="share-modal-close" onclick="closeShareModal()">×</button>
            </div>
            <div class="share-list" id="shareList">
                <div class="share-loading">Загрузка...</div>
            </div>
            <div class="share-footer">
                <button class="share-copy-btn" id="copyLinkBtn">Копировать ссылку</button>
                <button class="share-send-btn" id="sendShareBtn">Отправить</button>
            </div>
        </div>
    </div>

    <script>
        let currentType = '<?= $type ?>';
        let offset = 0;
        let isLoading = false;
        let hasMore = true;
        
        let currentShareLink = '';
        let selectedRecipients = new Map();
        
        const contentContainer = document.getElementById('contentContainer');
        const loadingEl = document.getElementById('loading');
        
        function openShareModal(shareLink) {
            currentShareLink = shareLink;
            selectedRecipients.clear();
            document.getElementById('shareModal').style.display = 'flex';
            loadChatsForShare();
        }
        
        function closeShareModal() {
            document.getElementById('shareModal').style.display = 'none';
        }
        
        async function loadChatsForShare() {
            const shareList = document.getElementById('shareList');
            shareList.innerHTML = '<div class="share-loading">Загрузка...</div>';
            
            try {
                const response = await fetch('/api/get_chats_for_share.php');
                const data = await response.json();
                
                if (data.success && data.personal && data.personal.length > 0) {
                    renderShareList(data.personal);
                } else {
                    shareList.innerHTML = '<div class="share-loading">Нет чатов</div>';
                }
            } catch (error) {
                console.error('Error loading chats:', error);
                shareList.innerHTML = '<div class="share-loading">Ошибка загрузки</div>';
            }
        }
        
        function renderShareList(chats) {
            const shareList = document.getElementById('shareList');
            
            let html = '';
            chats.forEach(chat => {
                html += `
                    <div class="share-item" data-id="${chat.id}" data-name="${escapeHtml(chat.name)}" onclick="toggleRecipient(this, ${chat.id})">
                        <img class="share-item-avatar" src="${chat.avatar || '/pwa_icon/icon-96.png'}" onerror="this.src='/pwa_icon/icon-96.png'">
                        <div class="share-item-info">
                            <div class="share-item-name">${escapeHtml(chat.name)}</div>
                            <div class="share-item-desc">${chat.uid || ''}</div>
                        </div>
                        <div class="share-item-checkbox"></div>
                    </div>
                `;
            });
            
            shareList.innerHTML = html;
        }
        
        function toggleRecipient(element, id) {
            if (selectedRecipients.has(id)) {
                selectedRecipients.delete(id);
                element.classList.remove('selected');
            } else {
                selectedRecipients.set(id, element.querySelector('.share-item-name')?.textContent || '');
                element.classList.add('selected');
            }
        }
        
        async function copyShareLink() {
            try {
                await navigator.clipboard.writeText(currentShareLink);
                showToast('Ссылка скопирована');
                closeShareModal();
            } catch (error) {
                prompt('Скопируйте ссылку:', currentShareLink);
            }
        }
        
        async function sendShareToRecipients() {
            const recipients = Array.from(selectedRecipients.keys());
            const sendBtn = document.getElementById('sendShareBtn');
            
            if (recipients.length === 0) {
                showToast('Выберите хотя бы одного получателя');
                return;
            }
            
            sendBtn.textContent = 'Отправка...';
            sendBtn.disabled = true;
            
            try {
                const response = await fetch('/api/share.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        recipients: recipients,
                        share_link: currentShareLink
                    })
                });
                const data = await response.json();
                
                if (data.success) {
                    showToast(`Отправлено ${data.sent_count} получателям`);
                    closeShareModal();
                } else {
                    showToast('Ошибка: ' + (data.error || 'Не удалось отправить'));
                }
            } catch (error) {
                console.error('Send error:', error);
                showToast('Ошибка при отправке');
            } finally {
                sendBtn.textContent = 'Отправить';
                sendBtn.disabled = false;
            }
        }
        
        document.getElementById('copyLinkBtn')?.addEventListener('click', copyShareLink);
        document.getElementById('sendShareBtn')?.addEventListener('click', sendShareToRecipients);
        
        async function loadContent() {
            if (isLoading || !hasMore) return;
            
            isLoading = true;
            loadingEl.style.display = 'block';
            
            try {
                const response = await fetch(`/api/feed.php?type=${currentType}&offset=${offset}`);
                const data = await response.json();
                
                if (data.success && data.items.length > 0) {
                    data.items.forEach(item => {
                        renderItem(item);
                    });
                    offset += data.items.length;
                    hasMore = data.has_more;
                } else {
                    hasMore = false;
                    if (offset === 0 && contentContainer.children.length === 0) {
                        contentContainer.innerHTML = '<div class="empty-state">Нет контента</div>';
                    }
                }
            } catch (error) {
                console.error('Load error:', error);
            } finally {
                isLoading = false;
                loadingEl.style.display = 'none';
            }
        }
        
        async function toggleLike(btn, contentId, contentType) {
            const isLiked = btn.classList.contains('liked');
            const likeCountSpan = btn.querySelector('.like-count');
            let currentCount = parseInt(likeCountSpan.textContent) || 0;
            
            if (isLiked) {
                btn.classList.remove('liked');
                likeCountSpan.textContent = Math.max(0, currentCount - 1);
            } else {
                btn.classList.add('liked');
                likeCountSpan.textContent = currentCount + 1;
            }
            
            try {
                const response = await fetch('/api/like.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ content_id: contentId, type: contentType })
                });
                const data = await response.json();
                
                if (data.success) {
                    likeCountSpan.textContent = data.likes_count;
                    if (data.liked) {
                        btn.classList.add('liked');
                    } else {
                        btn.classList.remove('liked');
                    }
                }
            } catch (error) {
                if (isLiked) {
                    btn.classList.add('liked');
                    likeCountSpan.textContent = currentCount;
                } else {
                    btn.classList.remove('liked');
                    likeCountSpan.textContent = currentCount;
                }
            }
        }
        
        function shareItem(contentId, contentType) {
            let url = '';
            if (contentType === 'channel') {
                url = `/profile/channel.php?id=${contentId}`;
            } else if (contentType === 'moment') {
                url = `/feed.php?type=moments`;
            } else {
                url = `/post.php?id=${contentId}`;
            }
            openShareModal(window.location.origin + url);
        }
        
        function showToast(msg) {
            const toast = document.createElement('div');
            toast.textContent = msg;
            toast.style.cssText = 'position:fixed;bottom:80px;left:50%;transform:translateX(-50%);background:#1e293b;padding:10px 20px;border-radius:40px;z-index:1000;font-size:14px;';
            document.body.appendChild(toast);
            setTimeout(() => toast.remove(), 2000);
        }
        
        function formatDate(timestamp) {
            const date = new Date(timestamp);
            const now = new Date();
            const diff = Math.floor((now - date) / 1000);
            if (diff < 60) return 'только что';
            if (diff < 3600) return Math.floor(diff / 60) + ' мин';
            if (diff < 86400) return Math.floor(diff / 3600) + ' ч';
            return date.toLocaleDateString();
        }
        
        function openFullscreen(mediaPath, isVideo) {
            const modal = document.createElement('div');
            modal.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:#000;z-index:2000;display:flex;align-items:center;justify-content:center;cursor:pointer;';
            if (isVideo) {
                const video = document.createElement('video');
                video.src = mediaPath;
                video.controls = true;
                video.autoplay = true;
                video.style.maxWidth = '95%';
                video.style.maxHeight = '95%';
                modal.appendChild(video);
            } else {
                const img = document.createElement('img');
                img.src = mediaPath;
                img.style.maxWidth = '95%';
                img.style.maxHeight = '95%';
                modal.appendChild(img);
            }
            modal.onclick = () => modal.remove();
            document.body.appendChild(modal);
        }
        
        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/[&<>]/g, m => m === '&' ? '&amp;' : m === '<' ? '&lt;' : '&gt;');
        }
        
        function renderItem(item) {
            const isVideo = item.media_path && (item.media_path.includes('.mp4') || item.media_path.includes('.webm'));
            const channelBadge = item.source_type === 'channel' ? '<span class="channel-badge">канал</span>' : '';
            
            const mediaHtml = isVideo
                ? `<div class="video-container">
                    <video src="${escapeHtml(item.media_path)}" preload="metadata" playsinline loop></video>
                   </div>`
                : `<div class="image-container">
                    <img src="${escapeHtml(item.media_path)}" alt="" loading="lazy">
                   </div>`;
            
            const div = document.createElement('div');
            div.className = 'content-card';
            div.dataset.id = item.id;
            div.innerHTML = `
                <div class="card-header">
                    <img class="avatar" src="${escapeHtml(item.avatar || '/pwa_icon/icon-96.png')}" onerror="this.src='/pwa_icon/icon-96.png'">
                    <div class="author-info">
                        <div class="author-name">
                            ${escapeHtml(item.name)} ${channelBadge}
                        </div>
                        <div class="time">${formatDate(item.created_at)}</div>
                    </div>
                </div>
                ${mediaHtml}
                ${item.content ? `<div class="caption">${escapeHtml(item.content).replace(/\n/g, '<br>')}</div>` : ''}
                <div class="actions">
                    <button class="action-btn like-btn ${item.user_liked ? 'liked' : ''}" onclick="toggleLike(this, ${item.id}, '${item.source_type}')">
                        ${item.user_liked ? '❤️' : '🤍'} <span class="like-count">${item.likes || 0}</span>
                    </button>
                    <button class="action-btn" onclick="shareItem(${item.id}, '${item.source_type}')">
                        🔁 Поделиться
                    </button>
                </div>
            `;
            
            contentContainer.appendChild(div);
            
            const video = div.querySelector('video');
            if (video) {
                const observer = new IntersectionObserver((entries) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            video.play().catch(e => console.log('Autoplay blocked'));
                            recordView(item.id, item.source_type);
                        } else {
                            video.pause();
                        }
                    });
                }, { threshold: 0.5 });
                observer.observe(video);
                
                video.addEventListener('click', (e) => {
                    e.stopPropagation();
                    if (video.paused) {
                        video.play();
                    } else {
                        video.pause();
                    }
                });
                
                video.addEventListener('dblclick', (e) => {
                    e.stopPropagation();
                    if (video.paused) {
                        video.play();
                    } else {
                        video.pause();
                    }
                });
            }
            
            const img = div.querySelector('img:not(.avatar)');
            if (img) {
                img.addEventListener('click', () => {
                    openFullscreen(item.media_path, false);
                });
            }
        }
        
        async function recordView(contentId, contentType) {
            try {
                await fetch('/api/view.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ content_id: contentId, type: contentType })
                });
            } catch (error) {
                console.error('View error:', error);
            }
        }
        
        function setupScrollListener() {
            window.addEventListener('scroll', () => {
                const { scrollTop, scrollHeight, clientHeight } = document.documentElement;
                if (scrollTop + clientHeight >= scrollHeight - 500) {
                    loadContent();
                }
            });
        }
        
        document.querySelectorAll('.nav-item').forEach(btn => {
            btn.addEventListener('click', () => {
                const type = btn.dataset.type;
                if (type === currentType) return;
                
                currentType = type;
                offset = 0;
                hasMore = true;
                contentContainer.innerHTML = '';
                
                document.querySelectorAll('.nav-item').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                
                history.pushState({}, '', `?type=${type}`);
                loadContent();
            });
        });
        
        setupScrollListener();
        loadContent();
        
        if (window.navigator.standalone || window.matchMedia('(display-mode: standalone)').matches) {
            document.body.style.paddingTop = 'env(safe-area-inset-top)';
        }
    </script>
</body>
</html>