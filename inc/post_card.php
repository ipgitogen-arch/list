<?php
// inc/post_card.php - универсальная карточка для постов, видео, историй
function renderPostCard($item, $userId, $pdo) {
    $isOwner = ($item['user_id'] == $userId);
    $isAdmin = ($_SESSION['role'] ?? '') === 'admin';
    $canDelete = ($isOwner || $isAdmin);
    
    // Обрезаем текст для превью
    $fullText = $item['content'] ?? $item['caption'] ?? '';
    $previewText = mb_substr($fullText, 0, 150);
    $hasMore = mb_strlen($fullText) > 150;
    $isExpanded = false;
    ?>
    <div class="content-card" data-id="<?= $item['id'] ?>" data-type="<?= $item['content_type'] ?? 'post' ?>">
        <div class="content-header">
            <img class="content-avatar" src="<?= htmlspecialchars($item['avatar'] ?? '/pwa_icon/icon-96.png') ?>" onerror="this.src='/pwa_icon/icon-96.png'">
            <div>
                <div class="content-author">
                    <?= htmlspecialchars($item['name'] ?? $item['user_name'] ?? 'Пользователь') ?>
                    <?php if (($item['source_type'] ?? '') === 'channel'): ?>
                        <span class="channel-badge">канал</span>
                    <?php endif; ?>
                </div>
                <div class="content-time">
                    <?= date('d.m.Y H:i', strtotime($item['created_at'])) ?>
                </div>
            </div>
            <?php if ($canDelete): ?>
                <button class="delete-post-btn" onclick="deletePost(<?= $item['id'] ?>, '<?= $item['content_type'] ?? 'post' ?>')">🗑️</button>
            <?php endif; ?>
        </div>
        
        <?php if (!empty($item['media_path'])): ?>
            <div class="content-media-wrapper">
                <?php if (strpos($item['media_path'], '.mp4') !== false || strpos($item['media_path'], '.webm') !== false): ?>
                    <video class="content-media" controls src="<?= htmlspecialchars($item['media_path']) ?>" preload="metadata" onclick="openMediaModal('<?= htmlspecialchars($item['media_path']) ?>', true)"></video>
                <?php else: ?>
                    <img class="content-media" src="<?= htmlspecialchars($item['media_path']) ?>" alt="" onclick="openMediaModal('<?= htmlspecialchars($item['media_path']) ?>', false)">
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <div class="content-caption <?= $isExpanded ? 'expanded' : '' ?>" id="caption-<?= $item['id'] ?>">
            <?= nl2br(htmlspecialchars($fullText)) ?>
        </div>
        
        <?php if ($hasMore): ?>
            <button class="read-more-btn" onclick="toggleCaption(<?= $item['id'] ?>, this)">Читать далее</button>
        <?php endif; ?>
        
        <div class="content-actions">
            <button class="action-btn like-btn <?= ($item['user_liked'] ?? false) ? 'liked' : '' ?>" onclick="toggleLike(this, <?= $item['id'] ?>, '<?= $item['content_type'] ?? 'post' ?>')">
                <span class="like-icon"><?= ($item['user_liked'] ?? false) ? '❤️' : '🤍' ?></span>
                <span class="like-count"><?= $item['likes'] ?? 0 ?></span>
            </button>
            <button class="action-btn" onclick="openReactionModal(<?= $item['id'] ?>, '<?= $item['content_type'] ?? 'post' ?>')">
                😊 <span>Реакция</span>
            </button>
            <button class="action-btn" onclick="openShareModal(<?= $item['id'] ?>, '<?= $item['content_type'] ?? 'post' ?>', '<?= addslashes($fullText) ?>')">
                🔁 <span>Поделиться</span>
            </button>
        </div>
    </div>
    
    <style>
    .read-more-btn {
        background: transparent;
        border: none;
        color: #3b82f6;
        font-size: 13px;
        cursor: pointer;
        padding: 8px 16px;
        text-align: left;
    }
    .content-caption {
        max-height: 80px;
        overflow: hidden;
        transition: max-height 0.3s ease;
    }
    .content-caption.expanded {
        max-height: none;
    }
    .delete-post-btn {
        background: transparent;
        border: none;
        color: #ef4444;
        font-size: 18px;
        cursor: pointer;
        margin-left: auto;
        padding: 4px 8px;
        border-radius: 20px;
    }
    .delete-post-btn:hover {
        background: #1e293b;
    }
    </style>
    
    <script>
    function toggleCaption(id, btn) {
        const caption = document.getElementById('caption-' + id);
        caption.classList.toggle('expanded');
        btn.textContent = caption.classList.contains('expanded') ? 'Свернуть' : 'Читать далее';
    }
    
    async function deletePost(id, type) {
        if (!confirm('Удалить этот пост?')) return;
        
        const response = await fetch('/api/delete_post.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id, type: type })
        });
        const data = await response.json();
        if (data.success) {
            location.reload();
        } else {
            alert('Ошибка удаления');
        }
    }
    
    function openReactionModal(id, type) {
        currentReactContentId = id;
        currentReactContentType = type;
        document.getElementById('reactionModal').style.display = 'flex';
    }
    
    async function sendReaction(reaction) {
        if (!currentReactContentId) return;
        
        const response = await fetch('/api/add_reaction.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                content_id: currentReactContentId, 
                content_type: currentReactContentType,
                reaction: reaction 
            })
        });
        const data = await response.json();
        if (data.success) {
            location.reload();
        }
        closeModal('reactionModal');
    }
    </script>
<?php
}
?>