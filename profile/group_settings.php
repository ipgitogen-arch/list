<?php
session_start();
require __DIR__ . '/../inc/dd_bb.php';

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    header('Location: /auth/login.php');
    exit;
}

$chatId = (int)($_GET['id'] ?? 0);
if (!$chatId) {
    header('Location: /profile/dialogs.php');
    exit;
}

$stmt = $pdo->prepare("SELECT role FROM group_chat_members WHERE chat_id = ? AND user_id = ?");
$stmt->execute([$chatId, $userId]);
$userRole = $stmt->fetchColumn();

if ($userRole !== 'admin') {
    header('Location: /profile/group_chat.php?id=' . $chatId);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM group_chats WHERE id = ?");
$stmt->execute([$chatId]);
$chat = $stmt->fetch();

$error = '';
$success = '';

function isOnline($lastSeen) {
    return $lastSeen && (time() - (int)$lastSeen) < 300;
}

// Получаем список пользователей для добавления
$stmt = $pdo->prepare("
    SELECT DISTINCT u.id, u.name, u.uid, p.avatar, u.last_seen
    FROM users u
    LEFT JOIN profiles p ON p.user_id = u.id
    WHERE u.id IN (
        SELECT friend_id FROM friends WHERE user_id = ?
        UNION
        SELECT user_id FROM friends WHERE friend_id = ?
        UNION
        SELECT receiver_id FROM messages WHERE sender_id = ?
        UNION
        SELECT sender_id FROM messages WHERE receiver_id = ?
    ) AND u.id NOT IN (SELECT user_id FROM group_chat_members WHERE chat_id = ?)
    ORDER BY u.name ASC
");
$stmt->execute([$userId, $userId, $userId, $userId, $chatId]);
$availableUsers = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_name'])) {
        $newName = trim($_POST['chat_name'] ?? '');
        if ($newName !== '') {
            $stmt = $pdo->prepare("UPDATE group_chats SET name = ? WHERE id = ?");
            $stmt->execute([$newName, $chatId]);
            $success = 'Название обновлено';
            $chat['name'] = $newName;
        }
    }
    
    if (isset($_POST['change_avatar'])) {
        if ($_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/../uploads/group_avatars/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $ext = pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION);
            $filename = 'group_' . $chatId . '.' . $ext;
            $targetPath = $uploadDir . $filename;
            if (move_uploaded_file($_FILES['avatar']['tmp_name'], $targetPath)) {
                $avatarPath = '/uploads/group_avatars/' . $filename;
                $stmt = $pdo->prepare("UPDATE group_chats SET avatar = ? WHERE id = ?");
                $stmt->execute([$avatarPath, $chatId]);
                $success = 'Аватар обновлён';
                $chat['avatar'] = $avatarPath;
            } else {
                $error = 'Ошибка сохранения файла';
            }
        } else {
            $error = 'Ошибка загрузки файла';
        }
    }
    
    if (isset($_POST['remove_avatar'])) {
        $stmt = $pdo->prepare("UPDATE group_chats SET avatar = NULL WHERE id = ?");
        $stmt->execute([$chatId]);
        $success = 'Аватар удалён';
        $chat['avatar'] = null;
    }
    
    if (isset($_POST['add_member'])) {
        $memberId = (int)($_POST['member_id'] ?? 0);
        if ($memberId) {
            $stmt = $pdo->prepare("INSERT IGNORE INTO group_chat_members (chat_id, user_id, role, joined_at) VALUES (?, ?, 'member', NOW())");
            $stmt->execute([$chatId, $memberId]);
            $success = 'Участник добавлен';
        }
    }
    
    if (isset($_POST['remove_member'])) {
        $memberId = (int)($_POST['member_id'] ?? 0);
        if ($memberId && $memberId != $userId) {
            $stmt = $pdo->prepare("DELETE FROM group_chat_members WHERE chat_id = ? AND user_id = ?");
            $stmt->execute([$chatId, $memberId]);
            $success = 'Участник удалён';
        }
    }
    
    if (isset($_POST['ban_member'])) {
        $memberId = (int)($_POST['ban_member_id'] ?? 0);
        if ($memberId && $memberId != $userId) {
            $stmt = $pdo->prepare("INSERT INTO banned_users (user_id, banned_by, chat_id, reason, created_at) VALUES (?, ?, ?, 'Нарушение правил', NOW())");
            $stmt->execute([$memberId, $userId, $chatId]);
            $stmt = $pdo->prepare("DELETE FROM group_chat_members WHERE chat_id = ? AND user_id = ?");
            $stmt->execute([$chatId, $memberId]);
            $success = 'Пользователь забанен и удалён из чата';
        }
    }
    
    if (isset($_POST['set_admin'])) {
        $memberId = (int)($_POST['set_admin_id'] ?? 0);
        if ($memberId && $memberId != $userId) {
            $stmt = $pdo->prepare("UPDATE group_chat_members SET role = 'admin' WHERE chat_id = ? AND user_id = ?");
            $stmt->execute([$chatId, $memberId]);
            $success = 'Администратор назначен';
        }
    }
    
    if (isset($_POST['remove_admin'])) {
        $memberId = (int)($_POST['remove_admin_id'] ?? 0);
        if ($memberId && $memberId != $userId) {
            $stmt = $pdo->prepare("UPDATE group_chat_members SET role = 'member' WHERE chat_id = ? AND user_id = ?");
            $stmt->execute([$chatId, $memberId]);
            $success = 'Администратор удалён';
        }
    }
    
    if (isset($_POST['toggle_share'])) {
        $newShared = $chat['is_shared'] ? 0 : 1;
        $shareLink = $newShared ? bin2hex(random_bytes(16)) : null;
        $stmt = $pdo->prepare("UPDATE group_chats SET is_shared = ?, share_link = ? WHERE id = ?");
        $stmt->execute([$newShared, $shareLink, $chatId]);
        $chat['is_shared'] = $newShared;
        $chat['share_link'] = $shareLink;
        $success = $newShared ? 'Ссылка для приглашения создана' : 'Приглашения отключены';
    }
    
    if (isset($_POST['delete_chat'])) {
        $stmt = $pdo->prepare("DELETE FROM group_chat_members WHERE chat_id = ?");
        $stmt->execute([$chatId]);
        $stmt = $pdo->prepare("DELETE FROM group_messages WHERE chat_id = ?");
        $stmt->execute([$chatId]);
        $stmt = $pdo->prepare("DELETE FROM group_chats WHERE id = ?");
        $stmt->execute([$chatId]);
        header('Location: /profile/dialogs.php');
        exit;
    }
}

// Получаем участников
$stmt = $pdo->prepare("
    SELECT u.id, u.name, u.last_seen, gcm.role
    FROM group_chat_members gcm
    JOIN users u ON u.id = gcm.user_id
    WHERE gcm.chat_id = ?
    ORDER BY gcm.role DESC, u.name ASC
");
$stmt->execute([$chatId]);
$members = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Настройки чата — Лист</title>
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
        .section h2 { font-size: 18px; margin-bottom: 16px; }
        input, select {
            width: 100%;
            padding: 12px;
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 12px;
            color: #fff;
            margin-bottom: 12px;
        }
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
            align-items: center;
            gap: 16px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }
        .avatar-preview img {
            width: 80px;
            height: 80px;
            border-radius: 40px;
            object-fit: cover;
            background: #334155;
        }
        .avatar-placeholder {
            width: 80px;
            height: 80px;
            border-radius: 40px;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
        }
        .file-input-wrapper {
            position: relative;
            display: inline-block;
        }
        .file-input-wrapper input {
            position: absolute;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }
        .file-input-label {
            display: inline-block;
            padding: 10px 16px;
            background: #334155;
            border-radius: 8px;
            cursor: pointer;
        }
        .member-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 16px;
            max-height: 400px;
            overflow-y: auto;
        }
        .member-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 12px;
            background: #1e293b;
            border-radius: 12px;
            flex-wrap: wrap;
            gap: 8px;
        }
        .member-name { font-weight: 500; }
        .member-status { margin-left: 6px; font-size: 12px; }
        .online { color: #22c55e; }
        .offline { color: #64748b; }
        .role-badge {
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 12px;
            margin-left: 6px;
        }
        .role-badge.admin { background: #f59e0b; }
        .member-actions { display: flex; gap: 6px; flex-wrap: wrap; }
        .small { padding: 4px 8px; font-size: 12px; }
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
        .share-with-friends { background: #f59e0b; }
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
        .modal {
            background: #111827;
            border-radius: 24px;
            padding: 24px;
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
            border: 1px solid rgba(255,255,255,0.1);
        }
        .modal h3 {
            font-size: 20px;
            margin-bottom: 8px;
            text-align: center;
        }
        .modal p {
            text-align: center;
            color: #94a3b8;
            margin-bottom: 16px;
        }
        .modal-search {
            margin-bottom: 16px;
        }
        .modal-search input {
            width: 100%;
            padding: 10px;
            border-radius: 40px;
            background: #1e293b;
            border: 1px solid #334155;
            color: #fff;
        }
        .modal-list {
            max-height: 400px;
            overflow-y: auto;
        }
        .modal-user-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px;
            background: #1e293b;
            border-radius: 12px;
            margin-bottom: 8px;
        }
        .modal-user-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .modal-user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 20px;
            object-fit: cover;
            background: #334155;
        }
        .modal-user-name {
            font-weight: 500;
        }
        .share-btn {
            background: #3b82f6;
            border: none;
            padding: 6px 12px;
            border-radius: 20px;
            color: #fff;
            cursor: pointer;
        }
        .modal-close {
            width: 100%;
            padding: 10px;
            background: #334155;
            border: none;
            border-radius: 12px;
            color: #fff;
            cursor: pointer;
            margin-top: 16px;
        }
        .loading {
            text-align: center;
            padding: 20px;
            color: #94a3b8;
        }
        @media (max-width: 600px) {
            .container { padding: 18px; }
            .member-item { flex-direction: column; align-items: stretch; text-align: center; }
            .member-actions { justify-content: center; }
            .avatar-preview { justify-content: center; }
            .share-link { flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>⚙️ Настройки чата</h1>
        
        <?php if ($success): ?>
            <div class="success">✅ <?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="error">❌ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <div class="section">
            <h2>📝 Название чата</h2>
            <form method="POST">
                <input type="text" name="chat_name" value="<?= htmlspecialchars($chat['name']) ?>" required>
                <button type="submit" name="update_name">Сохранить</button>
            </form>
        </div>
        
        <div class="section">
            <h2>🖼 Аватар</h2>
            <div class="avatar-preview">
                <?php if ($chat['avatar']): ?>
                    <img src="<?= htmlspecialchars($chat['avatar']) ?>" alt="Аватар">
                <?php else: ?>
                    <div class="avatar-placeholder">👥</div>
                <?php endif; ?>
                <div class="file-input-wrapper">
                    <span class="file-input-label">📁 Выбрать файл</span>
                    <form method="POST" enctype="multipart/form-data" style="display:inline;">
                        <input type="file" name="avatar" accept="image/jpeg,image/png,image/gif,image/webp" onchange="this.form.submit()">
                    </form>
                </div>
                <?php if ($chat['avatar']): ?>
                    <form method="POST" style="display:inline;">
                        <button type="submit" name="remove_avatar" class="danger-btn">🗑 Удалить</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="section">
            <h2>👥 Участники</h2>
            <div class="member-list">
                <?php foreach ($members as $member): ?>
                    <div class="member-item">
                        <div>
                            <span class="member-name"><?= htmlspecialchars($member['name']) ?></span>
                            <span class="member-status <?= isOnline($member['last_seen']) ? 'online' : 'offline' ?>">
                                <?= isOnline($member['last_seen']) ? '🟢' : '⚫' ?>
                            </span>
                            <?php if ($member['role'] === 'admin'): ?>
                                <span class="role-badge admin">Админ</span>
                            <?php endif; ?>
                        </div>
                        <?php if ($member['id'] != $userId): ?>
                            <div class="member-actions">
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="member_id" value="<?= $member['id'] ?>">
                                    <button type="submit" name="remove_member" class="danger-btn small" onclick="return confirm('Удалить участника?')">❌ Удалить</button>
                                </form>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="ban_member_id" value="<?= $member['id'] ?>">
                                    <button type="submit" name="ban_member" class="danger-btn small" onclick="return confirm('Забанить участника?')">🔨 Забанить</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <form method="POST">
                <select name="member_id" required>
                    <option value="">Выберите пользователя</option>
                    <?php foreach ($availableUsers as $user): ?>
                        <option value="<?= $user['id'] ?>"><?= htmlspecialchars($user['name']) ?> (<?= htmlspecialchars($user['uid']) ?>)</option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" name="add_member">➕ Добавить участника</button>
            </form>
        </div>
        
        <div class="section">
            <h2>👑 Администраторы</h2>
            <div class="member-list">
                <?php foreach ($members as $member): ?>
                    <?php if ($member['id'] != $userId): ?>
                        <div class="member-item">
                            <div>
                                <span class="member-name"><?= htmlspecialchars($member['name']) ?></span>
                                <?php if ($member['role'] === 'admin'): ?>
                                    <span class="role-badge admin">Админ</span>
                                <?php endif; ?>
                            </div>
                            <div class="member-actions">
                                <?php if ($member['role'] !== 'admin'): ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="set_admin_id" value="<?= $member['id'] ?>">
                                        <button type="submit" name="set_admin" class="small">👑 Назначить админом</button>
                                    </form>
                                <?php else: ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="remove_admin_id" value="<?= $member['id'] ?>">
                                        <button type="submit" name="remove_admin" class="danger-btn small">🗑 Убрать админа</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
        
        <div class="section">
            <h2>🔗 Приглашение</h2>
            <form method="POST">
                <button type="submit" name="toggle_share">
                    <?= $chat['is_shared'] ? '🔒 Отключить приглашения' : '🔓 Включить приглашения' ?>
                </button>
            </form>
            <?php if ($chat['is_shared'] && $chat['share_link']): ?>
                <div class="share-link">
                    <input type="text" value="https://мойлист.рф/profile/join_group.php?link=<?= $chat['share_link'] ?>" readonly>
                    <button onclick="this.previousElementSibling.select(); document.execCommand('copy'); alert('Ссылка скопирована');">📋 Копировать ссылку</button>
                    <button class="share-with-friends" data-link="https://мойлист.рф/profile/join_group.php?link=<?= $chat['share_link'] ?>" data-name="<?= htmlspecialchars($chat['name']) ?>">👥 Поделиться с друзьями</button>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="section">
            <h2>🗑 Опасная зона</h2>
            <form method="POST" onsubmit="return confirm('Удалить чат безвозвратно? Все сообщения будут потеряны.');">
                <button type="submit" name="delete_chat" class="danger-btn">Удалить чат</button>
            </form>
        </div>
        
        <a href="/profile/group_chat.php?id=<?= $chatId ?>" class="back-link">← Вернуться в чат</a>
    </div>

    <div id="shareModal" class="modal-overlay" style="display:none;">
        <div class="modal">
            <h3>📤 Поделиться чатом</h3>
            <p id="shareChatName"></p>
            <div class="modal-search">
                <input type="text" id="shareSearch" placeholder="🔍 Поиск по имени...">
            </div>
            <div class="modal-list" id="shareUsersList">
                <div class="loading">Загрузка...</div>
            </div>
            <button class="modal-close" onclick="closeShareModal()">Закрыть</button>
        </div>
    </div>

    <script>
        function openShareModal(link, name) {
            const modal = document.getElementById('shareModal');
            const chatName = document.getElementById('shareChatName');
            chatName.textContent = `Чат: ${name}`;
            modal.style.display = 'flex';
            loadUsersForShare(link);
        }

        function closeShareModal() {
            document.getElementById('shareModal').style.display = 'none';
        }

        async function loadUsersForShare(link) {
            const list = document.getElementById('shareUsersList');
            list.innerHTML = '<div class="loading">Загрузка...</div>';
            
            try {
                const response = await fetch('/api/get_users_for_share.php');
                const users = await response.json();
                
                if (users.length === 0) {
                    list.innerHTML = '<div class="loading">Нет пользователей для отправки</div>';
                    return;
                }
                
                list.innerHTML = users.map(user => `
                    <div class="modal-user-item" data-name="${escapeHtml(user.name)}">
                        <div class="modal-user-info">
                            ${user.avatar ? `<img class="modal-user-avatar" src="${escapeHtml(user.avatar)}">` : `<div class="modal-user-avatar" style="background:linear-gradient(135deg,#3b82f6,#8b5cf6); display:flex;align-items:center;justify-content:center;">${user.name.charAt(0)}</div>`}
                            <span class="modal-user-name">${escapeHtml(user.name)}</span>
                        </div>
                        <button class="share-btn" onclick="sendShareLink(${user.id}, '${link}', '${escapeHtml(user.name)}')">📤 Отправить</button>
                    </div>
                `).join('');
                
                const searchInput = document.getElementById('shareSearch');
                searchInput.oninput = () => {
                    const term = searchInput.value.toLowerCase();
                    document.querySelectorAll('.modal-user-item').forEach(item => {
                        const name = item.dataset.name.toLowerCase();
                        item.style.display = name.includes(term) ? 'flex' : 'none';
                    });
                };
            } catch (error) {
                list.innerHTML = '<div class="loading">Ошибка загрузки</div>';
            }
        }

        async function sendShareLink(userId, link, userName) {
            try {
                const response = await fetch('/api/send_share_link.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({user_id: userId, link: link})
                });
                const data = await response.json();
                if (data.success) {
                    alert(`✅ Ссылка отправлена пользователю ${userName}`);
                } else {
                    alert('❌ Ошибка отправки');
                }
            } catch (error) {
                alert('❌ Ошибка отправки');
            }
        }

        function escapeHtml(str) {
            return str.replace(/[&<>]/g, function(m) {
                if (m === '&') return '&amp;';
                if (m === '<') return '&lt;';
                if (m === '>') return '&gt;';
                return m;
            });
        }

        document.querySelectorAll('.share-with-friends').forEach(btn => {
            btn.addEventListener('click', () => {
                const link = btn.dataset.link;
                const name = btn.dataset.name;
                openShareModal(link, name);
            });
        });
    </script>
</body>
</html>