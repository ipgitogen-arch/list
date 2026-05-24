<?php
session_start();
require __DIR__ . '/inc/dd_bb.php';

$streamId = (int)($_GET['id'] ?? 0);
$userId = $_SESSION['user_id'] ?? null;

if (!$streamId) {
    header('Location: /feed.php');
    exit;
}

// Автоматическое завершение неактивных стримов (если владелец не обновлял статус 30 секунд)
$stmt = $pdo->prepare("UPDATE streams SET status = 'ended', ended_at = NOW() WHERE status = 'live' AND last_activity < DATE_SUB(NOW(), INTERVAL 30 SECOND)");
$stmt->execute();

// Получаем информацию о стриме
$stmt = $pdo->prepare("
    SELECT s.*, u.name, u.uid, p.avatar 
    FROM streams s
    JOIN users u ON u.id = s.user_id
    LEFT JOIN profiles p ON p.user_id = u.id
    WHERE s.id = ?
");
$stmt->execute([$streamId]);
$stream = $stmt->fetch();

if (!$stream) {
    header('Location: /feed.php');
    exit;
}

$isOwner = ($userId && $stream['user_id'] == $userId);
$isLive = ($stream['status'] === 'live');

// Обновляем активность стрима (только для владельца)
if ($isOwner && $isLive && isset($_GET['heartbeat'])) {
    $stmt = $pdo->prepare("UPDATE streams SET last_activity = NOW(), viewers = (SELECT COUNT(*) FROM stream_viewers WHERE stream_id = ?) WHERE id = ?");
    $stmt->execute([$streamId, $streamId]);
    echo json_encode(['success' => true]);
    exit;
}

// Проверка статуса стрима
if (isset($_GET['check_status'])) {
    header('Content-Type: application/json');
    $stmt = $pdo->prepare("SELECT status FROM streams WHERE id = ?");
    $stmt->execute([$streamId]);
    echo json_encode(['status' => $stmt->fetchColumn()]);
    exit;
}

// Завершение стрима (AJAX)
if (isset($_GET['end_stream_ajax']) && $isOwner) {
    $stmt = $pdo->prepare("UPDATE streams SET status = 'ended', ended_at = NOW() WHERE id = ?");
    $stmt->execute([$streamId]);
    echo json_encode(['success' => true]);
    exit;
}

// Обычное завершение стрима
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['end_stream']) && $isOwner) {
    $stmt = $pdo->prepare("UPDATE streams SET status = 'ended', ended_at = NOW() WHERE id = ?");
    $stmt->execute([$streamId]);
    header('Location: /profile/profile.php');
    exit;
}

// Отправка сообщения в чат
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['chat_message']) && $userId && $isLive) {
    $message = trim($_POST['chat_message']);
    if (!empty($message)) {
        $stmt = $pdo->prepare("INSERT INTO stream_messages (stream_id, user_id, message, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$streamId, $userId, $message]);
        header('Location: /stream.php?id=' . $streamId);
        exit;
    }
}

// AJAX получение новых сообщений
if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
    header('Content-Type: application/json');
    $lastId = (int)($_GET['last_id'] ?? 0);
    
    $stmt = $pdo->prepare("
        SELECT sm.*, u.name, u.uid, p.avatar
        FROM stream_messages sm
        JOIN users u ON u.id = sm.user_id
        LEFT JOIN profiles p ON p.user_id = u.id
        WHERE sm.stream_id = ? AND sm.id > ?
        ORDER BY sm.created_at ASC
    ");
    $stmt->execute([$streamId, $lastId]);
    $messages = $stmt->fetchAll();
    
    echo json_encode(['success' => true, 'messages' => $messages]);
    exit;
}

// Получаем сообщения чата
$stmt = $pdo->prepare("
    SELECT sm.*, u.name, u.uid, p.avatar
    FROM stream_messages sm
    JOIN users u ON u.id = sm.user_id
    LEFT JOIN profiles p ON p.user_id = u.id
    WHERE sm.stream_id = ?
    ORDER BY sm.created_at ASC
");
$stmt->execute([$streamId]);
$messages = $stmt->fetchAll();

// Получаем количество зрителей
$stmt = $pdo->prepare("SELECT COUNT(DISTINCT user_id) FROM stream_viewers WHERE stream_id = ?");
$stmt->execute([$streamId]);
$viewersCount = $stmt->fetchColumn();

// Добавляем текущего зрителя
if ($userId && !$isOwner && $isLive) {
    $stmt = $pdo->prepare("INSERT INTO stream_viewers (stream_id, user_id, joined_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE joined_at = NOW()");
    $stmt->execute([$streamId, $userId]);
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=yes">
    <title><?= htmlspecialchars($stream['title']) ?> — Стрим</title>
    <link rel="manifest" href="/manifest.json">
    <link rel="stylesheet" href="/assets/css/pwa-fix.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background: #000;
            color: #fff;
            height: 100vh;
            overflow: hidden;
        }
        
        .stream-container {
            display: flex;
            flex-direction: row;
            height: 100vh;
            width: 100%;
        }
        
        .video-area {
            flex: 3;
            background: #000;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        #localVideo {
            width: 100%;
            height: 100%;
            object-fit: cover;
            background: #000;
            transform: scaleX(-1);
        }
        .video-placeholder {
            text-align: center;
            color: #64748b;
        }
        .video-placeholder .icon {
            font-size: 80px;
            margin-bottom: 20px;
        }
        .stream-status {
            position: absolute;
            top: 20px;
            left: 20px;
            background: rgba(0,0,0,0.7);
            padding: 6px 12px;
            border-radius: 30px;
            font-size: 12px;
            z-index: 10;
        }
        .live-badge {
            background: #ef4444;
            padding: 4px 8px;
            border-radius: 8px;
            font-size: 10px;
            font-weight: 600;
            margin-right: 8px;
        }
        .viewers-count {
            color: #94a3b8;
        }
        .end-stream-btn {
            position: absolute;
            top: 20px;
            right: 20px;
            background: #ef4444;
            border: none;
            padding: 8px 16px;
            border-radius: 30px;
            color: #fff;
            font-weight: 600;
            cursor: pointer;
            z-index: 10;
        }
        .flip-camera-btn {
            position: absolute;
            bottom: 20px;
            right: 20px;
            background: rgba(0,0,0,0.7);
            border: none;
            padding: 12px;
            border-radius: 40px;
            color: #fff;
            font-size: 24px;
            cursor: pointer;
            z-index: 10;
            width: 48px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .chat-area {
            width: 320px;
            background: #111827;
            border-left: 1px solid #1e293b;
            display: flex;
            flex-direction: column;
            height: 100vh;
        }
        .chat-header {
            padding: 16px;
            border-bottom: 1px solid #1e293b;
            font-weight: 600;
        }
        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .chat-message {
            display: flex;
            flex-direction: column;
        }
        .chat-message-header {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 4px;
        }
        .chat-message-avatar {
            width: 24px;
            height: 24px;
            border-radius: 12px;
            object-fit: cover;
        }
        .chat-message-name {
            font-size: 12px;
            font-weight: 600;
            color: #3b82f6;
        }
        .chat-message-time {
            font-size: 10px;
            color: #64748b;
        }
        .chat-message-text {
            font-size: 13px;
            word-wrap: break-word;
            margin-left: 32px;
        }
        .chat-input-area {
            padding: 16px;
            border-top: 1px solid #1e293b;
            display: flex;
            gap: 12px;
        }
        .chat-input-area input {
            flex: 1;
            padding: 10px 14px;
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 30px;
            color: #fff;
            outline: none;
            font-size: 14px;
        }
        .chat-input-area input:focus {
            border-color: #3b82f6;
        }
        .chat-input-area button {
            padding: 10px 20px;
            background: #3b82f6;
            border: none;
            border-radius: 30px;
            color: #fff;
            font-weight: 600;
            cursor: pointer;
        }
        
        .empty-chat {
            text-align: center;
            color: #64748b;
            padding: 40px;
        }
        
        .back-link {
            position: fixed;
            bottom: 20px;
            left: 20px;
            background: rgba(0,0,0,0.7);
            padding: 8px 16px;
            border-radius: 30px;
            color: #fff;
            text-decoration: none;
            font-size: 14px;
            z-index: 10;
        }
        
        @media (max-width: 768px) {
            .stream-container {
                flex-direction: column;
            }
            .chat-area {
                width: 100%;
                height: 40vh;
            }
            .video-area {
                height: 60vh;
            }
            .back-link {
                bottom: auto;
                top: 20px;
                left: 20px;
            }
            .flip-camera-btn {
                bottom: 10px;
                right: 10px;
                width: 44px;
                height: 44px;
                font-size: 20px;
            }
        }
    </style>
</head>
<body>

<div class="stream-container">
    <div class="video-area">
        <?php if ($isOwner && $isLive): ?>
            <video id="localVideo" autoplay playsinline muted></video>
            <button class="flip-camera-btn" id="flipCameraBtn">🔄</button>
        <?php elseif ($isLive): ?>
            <div class="video-placeholder">
                <div class="icon">🔴</div>
                <div style="font-size: 24px; font-weight: 600; margin-bottom: 8px;"><?= htmlspecialchars($stream['title']) ?></div>
                <div style="color: #64748b;">Ведущий: <?= htmlspecialchars($stream['name']) ?></div>
                <div style="margin-top: 20px; font-size: 14px;">🎥 Стрим идёт</div>
            </div>
        <?php else: ?>
            <div class="video-placeholder">
                <div class="icon">📺</div>
                <div style="font-size: 24px; font-weight: 600; margin-bottom: 8px;"><?= htmlspecialchars($stream['title']) ?></div>
                <div style="color: #64748b;">Стрим закончен</div>
            </div>
        <?php endif; ?>
        
        <div class="stream-status">
            <span class="live-badge"><?= $isLive ? 'LIVE' : 'ENDED' ?></span>
            <span class="viewers-count">👁️ <?= $viewersCount ?> зрителей</span>
        </div>
        
        <?php if ($isOwner && $isLive): ?>
            <button class="end-stream-btn" id="endStreamBtn">Завершить стрим</button>
        <?php endif; ?>
        
        <a href="/feed.php" class="back-link">← Назад</a>
    </div>
    
    <div class="chat-area">
        <div class="chat-header">
            💬 Чат
        </div>
        
        <div class="chat-messages" id="chatMessages">
            <?php if (count($messages) > 0): ?>
                <?php foreach ($messages as $msg): ?>
                    <div class="chat-message">
                        <div class="chat-message-header">
                            <img class="chat-message-avatar" src="<?= htmlspecialchars($msg['avatar'] ?? '/pwa_icon/icon-96.png') ?>" onerror="this.src='/pwa_icon/icon-96.png'">
                            <span class="chat-message-name"><?= htmlspecialchars($msg['name']) ?></span>
                            <span class="chat-message-time"><?= date('H:i', strtotime($msg['created_at'])) ?></span>
                        </div>
                        <div class="chat-message-text"><?= nl2br(htmlspecialchars($msg['message'])) ?></div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-chat">💬 Напишите первое сообщение</div>
            <?php endif; ?>
        </div>
        
        <?php if ($userId && $isLive): ?>
            <form method="POST" class="chat-input-area" id="chatForm">
                <input type="text" name="chat_message" id="chatMessage" placeholder="Напишите сообщение..." autocomplete="off">
                <button type="submit">Отправить</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<script>
<?php if ($isOwner && $isLive): ?>
// WebRTC
let localStream = null;
let currentFacingMode = 'user';
let heartbeatInterval = null;

async function startCamera() {
    try {
        const constraints = {
            video: { facingMode: currentFacingMode },
            audio: true
        };
        localStream = await navigator.mediaDevices.getUserMedia(constraints);
        const videoElement = document.getElementById('localVideo');
        if (videoElement) {
            videoElement.srcObject = localStream;
        }
        // Запускаем heartbeat
        startHeartbeat();
    } catch (error) {
        console.error('Camera error:', error);
        alert('Не удалось получить доступ к камере');
    }
}

function startHeartbeat() {
    heartbeatInterval = setInterval(() => {
        fetch(window.location.href + '?heartbeat=1');
    }, 10000);
}

function flipCamera() {
    if (localStream) {
        localStream.getTracks().forEach(track => track.stop());
    }
    currentFacingMode = currentFacingMode === 'user' ? 'environment' : 'user';
    startCamera();
}

document.getElementById('flipCameraBtn')?.addEventListener('click', flipCamera);
startCamera();

// Завершение стрима при закрытии страницы
window.addEventListener('beforeunload', function() {
    if (heartbeatInterval) clearInterval(heartbeatInterval);
    navigator.sendBeacon(window.location.href + '?end_stream_ajax=1');
});

// Кнопка завершения стрима
document.getElementById('endStreamBtn')?.addEventListener('click', async function() {
    if (confirm('Завершить стрим?')) {
        if (heartbeatInterval) clearInterval(heartbeatInterval);
        const response = await fetch(window.location.href + '?end_stream_ajax=1');
        const data = await response.json();
        if (data.success) {
            window.location.href = '/feed.php';
        }
    }
});
<?php endif; ?>

// Чат
const chatMessages = document.getElementById('chatMessages');
const chatForm = document.getElementById('chatForm');
const chatInput = document.getElementById('chatMessage');
let lastMessageId = <?= !empty($messages) ? end($messages)['id'] : 0 ?>;

if (chatMessages) {
    chatMessages.scrollTop = chatMessages.scrollHeight;
}

if (chatForm) {
    chatForm.addEventListener('submit', async function(e) {
        e.preventDefault();
        const message = chatInput.value.trim();
        if (!message) return;
        
        const formData = new FormData();
        formData.append('chat_message', message);
        
        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData
            });
            if (response.ok) {
                chatInput.value = '';
                loadNewMessages();
            }
        } catch (error) {
            console.error('Send error:', error);
        }
    });
}

async function loadNewMessages() {
    try {
        const response = await fetch(window.location.href + '?ajax=1&last_id=' + lastMessageId);
        const data = await response.json();
        
        if (data.success && data.messages && data.messages.length > 0) {
            const wasAtBottom = chatMessages.scrollHeight - chatMessages.scrollTop - chatMessages.clientHeight < 100;
            
            data.messages.forEach(msg => {
                const messageDiv = document.createElement('div');
                messageDiv.className = 'chat-message';
                messageDiv.innerHTML = `
                    <div class="chat-message-header">
                        <img class="chat-message-avatar" src="${escapeHtml(msg.avatar || '/pwa_icon/icon-96.png')}" onerror="this.src='/pwa_icon/icon-96.png'">
                        <span class="chat-message-name">${escapeHtml(msg.name)}</span>
                        <span class="chat-message-time">${new Date(msg.created_at).toLocaleTimeString().slice(0,5)}</span>
                    </div>
                    <div class="chat-message-text">${escapeHtml(msg.message)}</div>
                `;
                chatMessages.appendChild(messageDiv);
                lastMessageId = msg.id;
            });
            
            if (wasAtBottom) {
                chatMessages.scrollTop = chatMessages.scrollHeight;
            }
            
            const emptyDiv = chatMessages.querySelector('.empty-chat');
            if (emptyDiv && data.messages.length > 0) {
                emptyDiv.remove();
            }
        }
    } catch (error) {
        console.error('Polling error:', error);
    }
    
    setTimeout(loadNewMessages, 3000);
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

loadNewMessages();

// Проверка статуса стрима (если закончился - редирект)
setInterval(async function() {
    const response = await fetch(window.location.href + '?check_status=1');
    const data = await response.json();
    if (data.status !== 'live') {
        location.reload();
    }
}, 15000);
</script>

</body>
</html>