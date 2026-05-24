<?php
session_start();
require __DIR__ . '/../inc/dd_bb.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /auth/login.php');
    exit;
}

$userId = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT subscription, is_plus FROM users WHERE id = ?");
$stmt->execute([$userId]);
$hasPlus = ($stmt->fetch()['subscription'] === 'plus');

$requestError = $_SESSION['request_error'] ?? null;
$requestSent = $_SESSION['request_sent'] ?? null;
unset($_SESSION['request_error'], $_SESSION['request_sent']);

// Входящие запросы
$stmt = $pdo->prepare("
    SELECT mr.id, mr.from_user_id, mr.created_at, u.name, u.uid
    FROM message_requests mr
    JOIN users u ON u.id = mr.from_user_id
    WHERE mr.to_user_id = ? AND mr.status = 'pending'
    ORDER BY mr.created_at DESC
");
$stmt->execute([$userId]);
$incomingRequests = $stmt->fetchAll();
$incomingCount = count($incomingRequests);

// Исходящие запросы
$stmt = $pdo->prepare("
    SELECT mr.id, mr.to_user_id, mr.created_at, u.name, u.uid
    FROM message_requests mr
    JOIN users u ON u.id = mr.to_user_id
    WHERE mr.from_user_id = ? AND mr.status = 'pending'
    ORDER BY mr.created_at DESC
");
$stmt->execute([$userId]);
$outgoingRequests = $stmt->fetchAll();

// Принять/отклонить запрос
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_action'])) {
    $requestId = (int)$_POST['request_id'];
    $action = $_POST['request_action'];
    
    if ($action === 'accept') {
        $stmt = $pdo->prepare("UPDATE message_requests SET status = 'accepted' WHERE id = ?");
        $stmt->execute([$requestId]);
        
        $stmt = $pdo->prepare("SELECT from_user_id FROM message_requests WHERE id = ?");
        $stmt->execute([$requestId]);
        $fromId = $stmt->fetchColumn();
        
        $stmt = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, content, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$userId, $fromId, 'Запрос принят!']);
        $_SESSION['request_sent'] = 'Запрос принят';
    } else {
        $stmt = $pdo->prepare("DELETE FROM message_requests WHERE id = ?");
        $stmt->execute([$requestId]);
        $_SESSION['request_error'] = 'Запрос отклонён';
    }
    header('Location: /profile/dialogs.php');
    exit;
}

// Отменить запрос
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_request'])) {
    $requestId = (int)$_POST['request_id'];
    $stmt = $pdo->prepare("DELETE FROM message_requests WHERE id = ? AND from_user_id = ?");
    $stmt->execute([$requestId, $userId]);
    $_SESSION['request_sent'] = 'Запрос отменён';
    header('Location: /profile/dialogs.php');
    exit;
}

// Отправить запрос или сообщение
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_request'])) {
    $toUserId = (int)$_POST['to_user_id'];
    $action = $_POST['send_request'];
    
    // Проверяем, есть ли диалог
    $stmt = $pdo->prepare("SELECT id FROM messages WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?) LIMIT 1");
    $stmt->execute([$userId, $toUserId, $toUserId, $userId]);
    
    if ($stmt->fetch()) {
        header('Location: /profile/chat.php?user=' . $toUserId);
        exit;
    }
    
    if ($action === 'write') {
        // Сразу пишем сообщение
        $stmt = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, content, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$userId, $toUserId, 'Здравствуйте!']);
        $_SESSION['request_sent'] = 'Сообщение отправлено';
        header('Location: /profile/chat.php?user=' . $toUserId);
        exit;
    } else {
        // Отправляем запрос
        $stmt = $pdo->prepare("SELECT id FROM message_requests WHERE from_user_id = ? AND to_user_id = ?");
        $stmt->execute([$userId, $toUserId]);
        
        if (!$stmt->fetch()) {
            $stmt = $pdo->prepare("INSERT INTO message_requests (from_user_id, to_user_id, status, created_at) VALUES (?, ?, 'pending', NOW())");
            $stmt->execute([$userId, $toUserId]);
            $_SESSION['request_sent'] = 'Запрос отправлен';
        } else {
            $_SESSION['request_error'] = 'Запрос уже отправлен';
        }
    }
    header('Location: /profile/dialogs.php');
    exit;
}

// Поиск пользователя
$search = trim($_GET['search'] ?? '');
$searchResult = null;
$needRequest = false;

if ($search) {
    $stmt = $pdo->prepare("SELECT id, name, uid FROM users WHERE BINARY uid = ? AND id != ?");
    $stmt->execute([$search, $userId]);
    $searchResult = $stmt->fetch();
    
    if ($searchResult) {
        $stmt = $pdo->prepare("SELECT privacy_requests FROM privacy_settings WHERE user_id = ?");
        $stmt->execute([$searchResult['id']]);
        $needRequest = ($stmt->fetchColumn() == 1);
    }
}

// Получаем диалоги
$dialogs = [];
$stmt = $pdo->prepare("
    SELECT u.id, u.name, u.uid, u.last_seen,
        (SELECT content FROM messages WHERE (sender_id = ? AND receiver_id = u.id) OR (sender_id = u.id AND receiver_id = ?) ORDER BY id DESC LIMIT 1) as last_message,
        (SELECT created_at FROM messages WHERE (sender_id = ? AND receiver_id = u.id) OR (sender_id = u.id AND receiver_id = ?) ORDER BY id DESC LIMIT 1) as last_time,
        (SELECT COUNT(*) FROM messages WHERE sender_id = u.id AND receiver_id = ? AND is_read = 0) as unread
    FROM users u
    WHERE u.id != ? AND EXISTS (SELECT 1 FROM messages WHERE (sender_id = ? AND receiver_id = u.id) OR (sender_id = u.id AND receiver_id = ?))
    ORDER BY last_time DESC
");
$stmt->execute([$userId, $userId, $userId, $userId, $userId, $userId, $userId, $userId]);
$dialogs = $stmt->fetchAll();

// Групповые чаты
$groupChats = [];
try {
    $stmt = $pdo->prepare("SELECT gc.*, gcm.role FROM group_chats gc JOIN group_chat_members gcm ON gcm.chat_id = gc.id WHERE gcm.user_id = ?");
    $stmt->execute([$userId]);
    $groupChats = $stmt->fetchAll();
} catch (Exception $e) {}

// Каналы
$channels = [];
try {
    $stmt = $pdo->prepare("SELECT c.*, cs.role FROM channels c JOIN channel_subscribers cs ON cs.channel_id = c.id WHERE cs.user_id = ?");
    $stmt->execute([$userId]);
    $channels = $stmt->fetchAll();
} catch (Exception $e) {}

function maskUid($uid) {
    if (strlen($uid) <= 4) return '****';
    return str_repeat('*', strlen($uid) - 4) . substr($uid, -4);
}

function timeAgo($t) {
    if (!$t) return '';
    $d = time() - strtotime($t);
    if ($d < 60) return 'только что';
    if ($d < 3600) return floor($d / 60) . ' мин';
    if ($d < 86400) return floor($d / 3600) . ' ч';
    return floor($d / 86400) . ' дн';
}

function isOnline($t) {
    return $t && (time() - $t) < 300;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Диалоги — Лист</title>
    <link rel="manifest" href="/manifest.json">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Text', system-ui, sans-serif;
            background: #0f172a;
            color: #f1f5f9;
            min-height: 100vh;
        }
        .gradient-bg {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: radial-gradient(ellipse at 20% 30%, #1e293b, #0f172a, #020617);
            z-index: -1;
        }
        .header {
            background: rgba(17,24,39,0.95);
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
        .logo { display: flex; align-items: center; gap: 8px; font-size: 20px; font-weight: 600; text-decoration: none; color: #fff; }
        .profile-link { color: #94a3b8; text-decoration: none; font-size: 14px; padding: 8px 16px; border-radius: 40px; background: #1e293b; }
        .container { max-width: 800px; margin: 0 auto; padding: 24px 16px; }
        .header-actions { display: flex; justify-content: space-between; margin-bottom: 20px; gap: 12px; flex-wrap: wrap; }
        .tabs { display: flex; gap: 8px; background: rgba(30,41,59,0.5); padding: 8px; border-radius: 60px; flex: 1; flex-wrap: wrap; position: relative; }
        .tab { flex: 1; padding: 10px 12px; text-align: center; background: transparent; border: none; border-radius: 40px; color: #94a3b8; font-weight: 600; cursor: pointer; position: relative; }
        .tab.active { background: linear-gradient(135deg, #3b82f6, #8b5cf6); color: #fff; }
        .tab-badge { position: absolute; top: -6px; right: -6px; background: #ef4444; color: white; font-size: 10px; font-weight: bold; padding: 2px 6px; border-radius: 20px; min-width: 18px; }
        .create-btn { width: 48px; height: 48px; border-radius: 24px; background: linear-gradient(135deg, #3b82f6, #8b5cf6); border: none; color: #fff; font-size: 28px; cursor: pointer; }
        .create-menu { position: absolute; top: 55px; right: 0; background: #1e293b; border-radius: 16px; overflow: hidden; display: none; flex-direction: column; z-index: 100; }
        .create-menu.show { display: flex; }
        .menu-item { padding: 12px 20px; background: transparent; border: none; color: #fff; text-align: left; cursor: pointer; }
        .menu-item:hover { background: #334155; }
        .menu-item:disabled { opacity: 0.5; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .search-box { margin-bottom: 20px; }
        .search-box input { width: 100%; padding: 12px 20px; border-radius: 40px; background: rgba(30,41,59,0.8); border: 1px solid rgba(255,255,255,0.1); color: #fff; outline: none; }
        .error-message, .success-message { padding: 12px; border-radius: 16px; margin-bottom: 20px; text-align: center; }
        .error-message { background: rgba(239,68,68,0.15); border: 1px solid #ef4444; color: #f87171; }
        .success-message { background: rgba(34,197,94,0.15); border: 1px solid #22c55e; color: #4ade80; }
        .search-result-card { display: flex; align-items: center; justify-content: space-between; background: rgba(30,41,59,0.5); border-radius: 20px; padding: 16px; margin-bottom: 20px; flex-wrap: wrap; gap: 12px; }
        .result-avatar { width: 48px; height: 48px; border-radius: 24px; background: linear-gradient(135deg, #3b82f6, #8b5cf6); display: flex; align-items: center; justify-content: center; font-size: 20px; }
        .send-request-btn { background: linear-gradient(135deg, #3b82f6, #2563eb); color: white; padding: 8px 20px; border-radius: 40px; border: none; cursor: pointer; }
        .request-btn { background: #f59e0b; }
        .requests-panel { background: rgba(30,41,59,0.5); border-radius: 20px; padding: 16px; margin-bottom: 24px; }
        .request-item { display: flex; justify-content: space-between; align-items: center; padding: 12px; background: rgba(15,23,42,0.5); border-radius: 16px; margin-bottom: 8px; flex-wrap: wrap; gap: 12px; }
        .request-avatar { width: 40px; height: 40px; border-radius: 20px; background: linear-gradient(135deg, #3b82f6, #8b5cf6); display: flex; align-items: center; justify-content: center; }
        .accept-btn { background: #22c55e; color: #fff; padding: 6px 12px; border-radius: 20px; border: none; cursor: pointer; }
        .decline-btn { background: #ef4444; color: #fff; padding: 6px 12px; border-radius: 20px; border: none; cursor: pointer; }
        .cancel-btn { background: #64748b; color: #fff; padding: 6px 12px; border-radius: 20px; border: none; cursor: pointer; }
        .masked-uid { font-family: monospace; font-size: 12px; color: #94a3b8; }
        .dialog-list { display: flex; flex-direction: column; gap: 12px; }
        .dialog-item { display: flex; align-items: center; gap: 16px; background: rgba(30,41,59,0.5); border-radius: 20px; padding: 16px; text-decoration: none; transition: 0.2s; }
        .dialog-item:hover { background: rgba(30,41,59,0.7); }
        .avatar-placeholder { width: 56px; height: 56px; border-radius: 28px; background: linear-gradient(135deg, #3b82f6, #8b5cf6); display: flex; align-items: center; justify-content: center; font-size: 24px; }
        .dialog-info { flex: 1; }
        .dialog-name { font-weight: 700; }
        .dialog-time { font-size: 12px; color: #64748b; }
        .dialog-message { font-size: 14px; color: #94a3b8; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .unread-badge { background: #3b82f6; padding: 4px 8px; border-radius: 20px; font-size: 12px; }
        .empty { text-align: center; padding: 48px 24px; color: #64748b; }
        .public-btn { background: rgba(30,41,59,0.8); border: 1px solid rgba(255,255,255,0.1); border-radius: 40px; padding: 8px 20px; color: #cbd5e1; cursor: pointer; margin-bottom: 16px; float: right; }
        .modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.8); display: flex; align-items: center; justify-content: center; z-index: 1000; }
        .modal { background: #1e293b; border-radius: 24px; padding: 24px; max-width: 500px; width: 90%; max-height: 80vh; overflow-y: auto; }
        .plus-menu { position: relative; }
        @media (max-width: 600px) { .container { padding: 16px; } .tab { font-size: 12px; padding: 8px; } .create-btn { width: 44px; height: 44px; font-size: 24px; } }
    </style>
</head>
<body>
<div class="gradient-bg"></div>
<div class="header">
    <a href="/index.php" class="logo"><span>Лист</span></a>
    <a href="/profile/profile.php" class="profile-link">Профиль</a>
</div>

<main class="container">
    <div class="header-actions">
        <div class="tabs">
            <button class="tab active" data-tab="dialogs">Личные</button>
            <button class="tab" data-tab="groups">Чаты</button>
            <button class="tab" data-tab="channels">Каналы</button>
            <button class="tab" data-tab="requests">Запросы</button>
        </div>
        <div class="plus-menu">
            <button class="create-btn" id="createBtn">+</button>
            <div class="create-menu" id="createMenu">
                <button class="menu-item" id="createChatBtn">Написать пользователю</button>
                <button class="menu-item" id="createGroupBtn">Создать группу</button>
                <button class="menu-item" id="createChannelBtn" <?= $hasPlus ? '' : 'disabled' ?>><?= $hasPlus ? 'Создать канал' : 'Создать канал (PLUS)' ?></button>
            </div>
        </div>
    </div>

    <div class="search-box">
        <form method="get">
            <input type="text" name="search" placeholder="Поиск по точному UID..." value="<?= htmlspecialchars($search) ?>">
        </form>
    </div>

    <?php if ($requestError): ?>
        <div class="error-message"><?= htmlspecialchars($requestError) ?></div>
    <?php endif; ?>
    <?php if ($requestSent): ?>
        <div class="success-message"><?= htmlspecialchars($requestSent) ?></div>
    <?php endif; ?>

    <?php if ($searchResult): ?>
        <div class="search-result-card">
            <div class="result-info" style="display:flex; gap:12px;">
                <div class="result-avatar"><?= mb_substr($searchResult['name'], 0, 1) ?></div>
                <div>
                    <div><strong><?= htmlspecialchars($searchResult['name']) ?></strong></div>
                    <div class="masked-uid">UID: <?= maskUid($searchResult['uid']) ?></div>
                </div>
            </div>
            <form method="post">
                <input type="hidden" name="to_user_id" value="<?= $searchResult['id'] ?>">
                <?php if ($needRequest): ?>
                    <button type="submit" name="send_request" value="request" class="send-request-btn request-btn">Запросить</button>
                <?php else: ?>
                    <button type="submit" name="send_request" value="write" class="send-request-btn">Написать</button>
                <?php endif; ?>
            </form>
        </div>
    <?php endif; ?>

    <!-- Диалоги -->
    <div id="dialogs" class="tab-content active">
        <?php if (empty($dialogs)): ?>
            <div class="empty">У вас пока нет личных диалогов</div>
        <?php else: ?>
            <div class="dialog-list">
                <?php foreach ($dialogs as $d): ?>
                    <a href="/profile/chat.php?user=<?= $d['id'] ?>" class="dialog-item">
                        <div class="avatar-placeholder"><?= mb_substr($d['name'], 0, 1) ?></div>
                        <div class="dialog-info">
                            <div class="dialog-header" style="display:flex; justify-content:space-between;">
                                <span class="dialog-name"><?= htmlspecialchars($d['name']) ?></span>
                                <span class="dialog-time"><?= timeAgo($d['last_time']) ?></span>
                            </div>
                            <div class="dialog-message"><?= htmlspecialchars(mb_substr($d['last_message'] ?? 'Новое сообщение', 0, 50)) ?></div>
                        </div>
                        <?php if ($d['unread'] > 0): ?>
                            <div class="unread-badge"><?= $d['unread'] ?></div>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Группы -->
    <div id="groups" class="tab-content">
        <?php if (empty($groupChats)): ?>
            <div class="empty">Вы не участвуете в групповых чатах</div>
        <?php else: ?>
            <div class="dialog-list">
                <?php foreach ($groupChats as $g): ?>
                    <a href="/profile/group_chat.php?id=<?= $g['id'] ?>" class="dialog-item">
                        <div class="avatar-placeholder">Г</div>
                        <div class="dialog-info">
                            <div class="dialog-header" style="display:flex; justify-content:space-between;">
                                <span class="dialog-name"><?= htmlspecialchars($g['name']) ?></span>
                                <?php if ($g['role'] === 'admin'): ?><span class="unread-badge">Админ</span><?php endif; ?>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Каналы -->
    <div id="channels" class="tab-content">
        <button class="public-btn" id="publicChannelsBtn">Публичные каналы</button>
        <div style="clear:both"></div>
        <?php if (empty($channels)): ?>
            <div class="empty">Вы не подписаны на каналы</div>
        <?php else: ?>
            <div class="dialog-list">
                <?php foreach ($channels as $c): ?>
                    <a href="/profile/channel.php?id=<?= $c['id'] ?>" class="dialog-item">
                        <div class="avatar-placeholder">📢</div>
                        <div class="dialog-info">
                            <div class="dialog-header" style="display:flex; justify-content:space-between;">
                                <span class="dialog-name"><?= htmlspecialchars($c['name']) ?></span>
                                <?php if ($c['role'] === 'owner'): ?><span class="unread-badge">Владелец</span><?php endif; ?>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Запросы -->
    <div id="requests" class="tab-content">
        <div class="requests-panel">
            <h3>Входящие запросы <?= $incomingCount > 0 ? '<span class="unread-badge">' . $incomingCount . '</span>' : '' ?></h3>
            <?php if (empty($incomingRequests)): ?>
                <div class="empty">Нет входящих запросов</div>
            <?php else: ?>
                <?php foreach ($incomingRequests as $r): ?>
                    <div class="request-item">
                        <div class="request-info" style="display:flex; gap:12px;">
                            <div class="request-avatar"><?= mb_substr($r['name'], 0, 1) ?></div>
                            <div>
                                <div><strong><?= htmlspecialchars($r['name']) ?></strong></div>
                                <div class="masked-uid">UID: <?= maskUid($r['uid']) ?></div>
                            </div>
                        </div>
                        <form method="post" style="display:flex; gap:8px;">
                            <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                            <button type="submit" name="request_action" value="accept" class="accept-btn">Принять</button>
                            <button type="submit" name="request_action" value="decline" class="decline-btn">Отклонить</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="requests-panel">
            <h3>Исходящие запросы</h3>
            <?php if (empty($outgoingRequests)): ?>
                <div class="empty">Нет исходящих запросов</div>
            <?php else: ?>
                <?php foreach ($outgoingRequests as $r): ?>
                    <div class="request-item">
                        <div class="request-info" style="display:flex; gap:12px;">
                            <div class="request-avatar"><?= mb_substr($r['name'], 0, 1) ?></div>
                            <div>
                                <div><strong><?= htmlspecialchars($r['name']) ?></strong></div>
                                <div class="masked-uid">UID: <?= maskUid($r['uid']) ?></div>
                            </div>
                        </div>
                        <form method="post">
                            <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                            <button type="submit" name="cancel_request" class="cancel-btn">Отменить</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</main>

<script>
    document.querySelectorAll('.tab').forEach(tab => {
        tab.addEventListener('click', () => {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            tab.classList.add('active');
            document.getElementById(tab.dataset.tab).classList.add('active');
        });
    });

    const createBtn = document.getElementById('createBtn');
    const createMenu = document.getElementById('createMenu');
    if (createBtn) {
        createBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            createMenu.classList.toggle('show');
        });
    }
    document.addEventListener('click', () => {
        if (createMenu) createMenu.classList.remove('show');
    });

    document.getElementById('createChatBtn')?.addEventListener('click', () => window.location.href = '/profile/send_request.php');
    document.getElementById('createGroupBtn')?.addEventListener('click', () => window.location.href = '/profile/create_group.php');
    document.getElementById('createChannelBtn')?.addEventListener('click', () => {
        <?php if ($hasPlus): ?>
            window.location.href = '/profile/create_channel.php';
        <?php else: ?>
            alert('Создание канала доступно только с подпиской PLUS');
        <?php endif; ?>
    });

    document.getElementById('publicChannelsBtn')?.addEventListener('click', async () => {
        try {
            const res = await fetch('/api/public_channels.php');
            const channels = await res.json();
            let html = '<div class="modal-overlay" id="publicModal"><div class="modal"><h3>Публичные каналы</h3><div class="modal-list">';
            channels.forEach(ch => {
                html += `<div class="request-item" style="justify-content:space-between;">
                            <div><strong>${escapeHtml(ch.name)}</strong><div style="font-size:12px">${ch.subscribers} подписчиков</div></div>
                            <button class="accept-btn" data-id="${ch.id}">Подписаться</button>
                        </div>`;
            });
            html += '</div><button class="decline-btn" style="margin-top:16px; width:100%" onclick="this.closest(\'.modal-overlay\').remove()">Закрыть</button></div></div>';
            document.body.insertAdjacentHTML('beforeend', html);
            document.querySelectorAll('.accept-btn[data-id]').forEach(btn => {
                btn.onclick = async () => {
                    await fetch('/api/subscribe_channel.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ channel_id: btn.dataset.id }) });
                    alert('Подписка оформлена');
                    document.getElementById('publicModal')?.remove();
                    location.reload();
                };
            });
        } catch(e) { alert('Ошибка'); }
    });

    function escapeHtml(s) { return s ? s.replace(/[&<>]/g, function(m) { return { '&':'&amp;', '<':'&lt;', '>':'&gt;' }[m]; }) : ''; }
    if (window.navigator.standalone === true || window.matchMedia('(display-mode: standalone)').matches) {
        document.body.style.paddingTop = 'env(safe-area-inset-top)';
    }
</script>
</body>
</html>