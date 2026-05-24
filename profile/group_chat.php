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

$stmt = $pdo->prepare("SELECT * FROM group_chats WHERE id = ?");
$stmt->execute([$chatId]);
$chat = $stmt->fetch();

if (!$chat) {
    header('Location: /profile/dialogs.php');
    exit;
}

$stmt = $pdo->prepare("SELECT role FROM group_chat_members WHERE chat_id = ? AND user_id = ?");
$stmt->execute([$chatId, $userId]);
$userRole = $stmt->fetchColumn();

$isMember = $userRole !== false;
$isAdmin = ($userRole === 'admin');

if (!$isMember) {
    header('Location: /profile/dialogs.php');
    exit;
}

$chatError = $_SESSION['chat_error'] ?? null;
unset($_SESSION['chat_error']);

// Отправка сообщения с файлом
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['message']) || isset($_FILES['file']))) {
    $message = trim($_POST['message'] ?? '');
    $file = $_FILES['file'] ?? null;
    
    $filePath = null;
    $fileType = null;
    
    if ($file && $file['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/../uploads/group/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = time() . '_' . uniqid() . '.' . $ext;
        $targetPath = $uploadDir . $filename;
        
        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            $filePath = '/uploads/group/' . $filename;
            if (strpos($file['type'], 'image/') === 0) {
                $fileType = 'image';
            } elseif (strpos($file['type'], 'audio/') === 0) {
                $fileType = 'voice';
            } else {
                $fileType = 'file';
            }
        }
    }
    
    if ($message !== '' || $filePath) {
        $stmt = $pdo->prepare("INSERT INTO group_messages (chat_id, user_id, content, file_path, file_type, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$chatId, $userId, $message, $filePath, $fileType]);
    }
    header("Location: /profile/group_chat.php?id=" . $chatId);
    exit;
}

// AJAX получение новых сообщений
if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
    header('Content-Type: application/json');
    $lastId = (int)($_GET['last_id'] ?? 0);
    
    $stmt = $pdo->prepare("
        SELECT gm.*, u.name, u.uid, p.avatar
        FROM group_messages gm
        JOIN users u ON u.id = gm.user_id
        LEFT JOIN profiles p ON p.user_id = u.id
        WHERE gm.chat_id = ? AND gm.id > ?
        ORDER BY gm.id ASC
    ");
    $stmt->execute([$chatId, $lastId]);
    $newMessages = $stmt->fetchAll();
    
    echo json_encode(['success' => true, 'messages' => $newMessages]);
    exit;
}

$stmt = $pdo->prepare("
    SELECT gm.*, u.name, u.uid, p.avatar
    FROM group_messages gm
    JOIN users u ON u.id = gm.user_id
    LEFT JOIN profiles p ON p.user_id = u.id
    WHERE gm.chat_id = ?
    ORDER BY gm.id ASC
");
$stmt->execute([$chatId]);
$messages = $stmt->fetchAll();

$lastMessageId = !empty($messages) ? end($messages)['id'] : 0;

$stmt = $pdo->prepare("SELECT COUNT(*) FROM group_chat_members WHERE chat_id = ?");
$stmt->execute([$chatId]);
$membersCount = $stmt->fetchColumn();

function timeAgo($timestamp) {
    if (!$timestamp) return '';
    $diff = time() - strtotime($timestamp);
    if ($diff < 60) return 'только что';
    if ($diff < 3600) return floor($diff / 60) . ' мин назад';
    if ($diff < 86400) return floor($diff / 3600) . ' ч назад';
    return floor($diff / 86400) . ' дн назад';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=no">
    <title><?= htmlspecialchars($chat['name']) ?> — Лист</title>
    <link rel="manifest" href="/manifest.json">
    <link rel="apple-touch-icon" href="/pwa_icon/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="96x96" href="/pwa_icon/favicon-96x96.png">
    <link rel="icon" type="image/svg+xml" href="/pwa_icon/favicon.svg">
    <link rel="shortcut icon" href="/pwa_icon/favicon.ico">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#0f172a">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { 
            height: 100%; 
            width: 100%;
            overflow: hidden;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #0a0f1f 100%);
            color: #fff;
            display: flex;
            flex-direction: column;
        }
        .chat-container { 
            width: 100%; 
            height: 100%;
            display: flex; 
            flex-direction: column; 
        }
        .chat-header {
            background: rgba(17, 24, 39, 0.95);
            backdrop-filter: blur(10px);
            padding: 12px 20px;
            padding-top: max(12px, env(safe-area-inset-top));
            display: flex;
            align-items: center;
            gap: 12px;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            flex-shrink: 0;
            z-index: 100;
        }
        .back-btn {
            background: #1e293b;
            width: 40px;
            height: 40px;
            border-radius: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            color: #fff;
            font-size: 20px;
            flex-shrink: 0;
        }
        .chat-avatar { flex-shrink: 0; }
        .avatar {
            width: 44px;
            height: 44px;
            border-radius: 22px;
            object-fit: cover;
            background: #334155;
        }
        .avatar-placeholder {
            width: 44px;
            height: 44px;
            border-radius: 22px;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        .chat-info { flex: 1; min-width: 0; }
        .chat-name { font-weight: 700; font-size: 16px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .chat-stats { font-size: 11px; color: #94a3b8; }
        .settings-btn {
            background: #1e293b;
            width: 40px;
            height: 40px;
            border-radius: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            color: #fff;
            font-size: 20px;
            flex-shrink: 0;
        }
        .messages {
            flex: 1;
            overflow-y: auto;
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .message {
            display: flex;
            flex-direction: column;
            max-width: 70%;
        }
        .message.outgoing { align-self: flex-end; }
        .message.incoming { align-self: flex-start; }
        .message-bubble {
            padding: 10px 14px;
            border-radius: 18px;
            word-wrap: break-word;
            line-height: 1.4;
            font-size: 15px;
        }
        .message.outgoing .message-bubble {
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            border-bottom-right-radius: 4px;
        }
        .message.incoming .message-bubble {
            background: #1e293b;
            border-bottom-left-radius: 4px;
        }
        .message-bubble img {
            max-width: 200px;
            max-height: 200px;
            border-radius: 12px;
            cursor: pointer;
        }
        .message-bubble audio {
            max-width: 200px;
            border-radius: 20px;
        }
        .message-bubble a {
            color: #3b82f6;
            text-decoration: none;
        }
        .message-sender {
            font-size: 11px;
            color: #94a3b8;
            margin-bottom: 2px;
            margin-left: 8px;
        }
        .message-time {
            font-size: 10px;
            color: #64748b;
            margin-top: 4px;
        }
        .message.outgoing .message-time { text-align: right; }
        
        .input-area {
            background: rgba(17, 24, 39, 0.95);
            border-top: 1px solid rgba(255,255,255,0.08);
            display: flex;
            flex-direction: column;
            gap: 8px;
            flex-shrink: 0;
            padding: 12px 16px;
            padding-bottom: max(12px, env(safe-area-inset-bottom));
        }
        .input-row {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        .plus-btn {
            width: 44px;
            height: 44px;
            border-radius: 22px;
            background: #334155;
            border: none;
            color: #fff;
            font-size: 24px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: 0.2s;
            flex-shrink: 0;
        }
        .plus-btn:hover {
            background: #475569;
        }
        .input-row input {
            flex: 1;
            padding: 12px 16px;
            border: 1px solid #334155;
            border-radius: 30px;
            background: #1e293b;
            color: #fff;
            outline: none;
            font-size: 15px;
        }
        .send-btn {
            padding: 12px 24px;
            background: #3b82f6;
            border: none;
            border-radius: 30px;
            color: #fff;
            font-weight: 600;
            cursor: pointer;
            flex-shrink: 0;
        }
        
        .plus-dropdown {
            position: absolute;
            bottom: 70px;
            left: 16px;
            background: #1e293b;
            border-radius: 16px;
            overflow: hidden;
            display: none;
            flex-direction: column;
            z-index: 200;
            border: 1px solid rgba(255,255,255,0.1);
        }
        .plus-dropdown.show {
            display: flex;
        }
        .dropdown-item {
            padding: 12px 20px;
            background: transparent;
            border: none;
            color: #fff;
            text-align: left;
            cursor: pointer;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
            width: 100%;
        }
        .dropdown-item:hover {
            background: #334155;
        }
        
        .file-preview {
            background: #1e293b;
            border-radius: 16px;
            padding: 8px 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .file-preview-info {
            display: flex;
            align-items: center;
            gap: 10px;
            flex: 1;
        }
        .file-preview-icon {
            font-size: 24px;
        }
        .file-preview-name {
            font-size: 13px;
            word-break: break-all;
            color: #94a3b8;
        }
        .file-preview-remove {
            background: #ef4444;
            border: none;
            color: #fff;
            width: 28px;
            height: 28px;
            border-radius: 14px;
            cursor: pointer;
            font-size: 16px;
        }
        .file-preview-img {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            object-fit: cover;
        }
        
        .error-message {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid #ef4444;
            padding: 10px;
            border-radius: 12px;
            margin: 8px 16px;
            text-align: center;
        }
        .empty-messages {
            text-align: center;
            padding: 40px;
            color: #64748b;
        }
        
        .file-input { display: none; }
        
        @media (max-width: 600px) {
            .message { max-width: 85%; }
            .message-bubble { font-size: 14px; padding: 8px 12px; }
            .input-row input { padding: 10px 14px; font-size: 14px; }
            .send-btn { padding: 10px 18px; }
            .plus-btn { width: 40px; height: 40px; font-size: 20px; }
            .avatar, .avatar-placeholder { width: 36px; height: 36px; font-size: 18px; }
            .back-btn, .settings-btn { width: 36px; height: 36px; font-size: 18px; }
        }
    </style>
</head>
<body>
    <div class="chat-container">
        <div class="chat-header">
            <a href="/profile/dialogs.php" class="back-btn">←</a>
            <div class="chat-avatar">
                <?php if ($chat['avatar']): ?>
                    <img class="avatar" src="<?= htmlspecialchars($chat['avatar']) ?>" alt="">
                <?php else: ?>
                    <div class="avatar-placeholder">👥</div>
                <?php endif; ?>
            </div>
            <div class="chat-info">
                <div class="chat-name"><?= htmlspecialchars($chat['name']) ?></div>
                <div class="chat-stats"><?= $membersCount ?> участников</div>
            </div>
            <?php if ($isAdmin): ?>
                <a href="/profile/group_settings.php?id=<?= $chatId ?>" class="settings-btn">⚙️</a>
            <?php endif; ?>
        </div>

        <?php if ($chatError): ?>
            <div class="error-message">⚠️ <?= htmlspecialchars($chatError) ?></div>
        <?php endif; ?>

        <div class="messages" id="messages">
            <?php if (empty($messages)): ?>
                <div class="empty-messages">
                    <div style="font-size: 48px; margin-bottom: 12px;">💬</div>
                    <p>Напишите первое сообщение</p>
                </div>
            <?php else: ?>
                <?php foreach ($messages as $msg): ?>
                    <div class="message <?= $msg['user_id'] == $userId ? 'outgoing' : 'incoming' ?>" data-message-id="<?= $msg['id'] ?>">
                        <?php if ($msg['user_id'] != $userId): ?>
                            <div class="message-sender"><?= htmlspecialchars($msg['name']) ?></div>
                        <?php endif; ?>
                        <div class="message-bubble">
                            <?php if (!empty($msg['file_path'])): ?>
                                <?php if ($msg['file_type'] === 'image'): ?>
                                    <img src="<?= htmlspecialchars($msg['file_path']) ?>" alt="" onclick="window.open(this.src)">
                                <?php elseif ($msg['file_type'] === 'voice'): ?>
                                    <audio controls src="<?= htmlspecialchars($msg['file_path']) ?>"></audio>
                                <?php else: ?>
                                    <a href="<?= htmlspecialchars($msg['file_path']) ?>" download>📎 <?= basename($msg['file_path']) ?></a>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?= nl2br(htmlspecialchars($msg['content'] ?? '')) ?>
                        </div>
                        <div class="message-time"><?= timeAgo($msg['created_at']) ?></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <form class="input-area" method="POST" enctype="multipart/form-data" id="chatForm">
            <div id="filePreviewContainer" style="display: none;"></div>
            <div class="input-row">
                <div class="plus-menu" style="position: relative;">
                    <button type="button" class="plus-btn" id="plusBtn">+</button>
                    <div class="plus-dropdown" id="plusDropdown">
                        <button type="button" class="dropdown-item" id="attachFileBtn">📎 Файл</button>
                    </div>
                </div>
                <input type="text" name="message" id="messageInput" placeholder="Сообщение..." autocomplete="off">
                <button type="submit" class="send-btn">→</button>
            </div>
            <input type="file" name="file" id="fileInput" class="file-input" accept="image/*,audio/*,.pdf,.doc,.docx,.txt">
        </form>
    </div>

    <script>
        const messagesDiv = document.getElementById('messages');
        const messageInput = document.getElementById('messageInput');
        const fileInput = document.getElementById('fileInput');
        const filePreviewContainer = document.getElementById('filePreviewContainer');
        const chatForm = document.getElementById('chatForm');
        const plusBtn = document.getElementById('plusBtn');
        const plusDropdown = document.getElementById('plusDropdown');
        const attachFileBtn = document.getElementById('attachFileBtn');
        
        let selectedFile = null;
        let lastMessageId = <?= $lastMessageId ?>;
        
        function scrollToBottom() {
            if (messagesDiv) {
                messagesDiv.scrollTop = messagesDiv.scrollHeight;
            }
        }
        
        setTimeout(scrollToBottom, 100);
        
        // Плюс меню
        plusBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            plusDropdown.classList.toggle('show');
        });
        
        document.addEventListener('click', () => {
            plusDropdown.classList.remove('show');
        });
        
        if (attachFileBtn) {
            attachFileBtn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                fileInput.click();
                plusDropdown.classList.remove('show');
            });
        }
        
        // Превью файла
        fileInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (!file) return;
            
            selectedFile = file;
            
            let icon = '📄';
            let isImage = false;
            let previewUrl = null;
            
            if (file.type.startsWith('image/')) {
                icon = '🖼️';
                isImage = true;
                previewUrl = URL.createObjectURL(file);
            } else if (file.type.startsWith('audio/')) {
                icon = '🎵';
            } else if (file.type === 'application/pdf') {
                icon = '📑';
            } else if (file.type.includes('word')) {
                icon = '📝';
            } else {
                icon = '📎';
            }
            
            let previewHtml = `
                <div class="file-preview" id="filePreview">
                    <div class="file-preview-info">
                        <span class="file-preview-icon">${icon}</span>
                        ${isImage ? `<img class="file-preview-img" src="${previewUrl}" alt="preview">` : ''}
                        <span class="file-preview-name">${file.name} (${(file.size / 1024).toFixed(1)} KB)</span>
                    </div>
                    <button type="button" class="file-preview-remove" id="removeFileBtn">✕</button>
                </div>
            `;
            
            filePreviewContainer.innerHTML = previewHtml;
            filePreviewContainer.style.display = 'block';
            
            if (isImage && previewUrl) {
                filePreviewContainer.dataset.previewUrl = previewUrl;
            }
            
            document.getElementById('removeFileBtn').addEventListener('click', function() {
                selectedFile = null;
                fileInput.value = '';
                filePreviewContainer.style.display = 'none';
                filePreviewContainer.innerHTML = '';
                if (filePreviewContainer.dataset.previewUrl) {
                    URL.revokeObjectURL(filePreviewContainer.dataset.previewUrl);
                }
            });
        });
        
        // Отправка формы
        chatForm.addEventListener('submit', function(e) {
            const message = messageInput.value.trim();
            if (!message && !selectedFile) {
                e.preventDefault();
                return false;
            }
        });
        
        // Загрузка новых сообщений каждую секунду
        function loadNewMessages() {
            fetch(window.location.href + '?ajax=1&last_id=' + lastMessageId)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.messages && data.messages.length > 0) {
                        const wasAtBottom = messagesDiv.scrollHeight - messagesDiv.scrollTop - messagesDiv.clientHeight < 150;
                        
                        data.messages.forEach(msg => {
                            const div = document.createElement('div');
                            div.className = 'message ' + (msg.user_id == <?= $userId ?> ? 'outgoing' : 'incoming');
                            div.dataset.messageId = msg.id;
                            
                            let senderHtml = '';
                            if (msg.user_id != <?= $userId ?>) {
                                senderHtml = `<div class="message-sender">${escapeHtml(msg.name)}</div>`;
                            }
                            
                            let fileHtml = '';
                            if (msg.file_path) {
                                if (msg.file_type === 'image') {
                                    fileHtml = `<img src="${escapeHtml(msg.file_path)}" alt="" onclick="window.open(this.src)">`;
                                } else if (msg.file_type === 'voice') {
                                    fileHtml = `<audio controls src="${escapeHtml(msg.file_path)}"></audio>`;
                                } else if (msg.file_path) {
                                    fileHtml = `<a href="${escapeHtml(msg.file_path)}" download>📎 ${escapeHtml(msg.file_path.split('/').pop())}</a>`;
                                }
                            }
                            
                            let contentHtml = msg.content ? `<div>${escapeHtml(msg.content).replace(/\n/g,'<br>')}</div>` : '';
                            
                            div.innerHTML = `
                                ${senderHtml}
                                <div class="message-bubble">
                                    ${fileHtml}
                                    ${contentHtml}
                                </div>
                                <div class="message-time">${timeAgo(msg.created_at)}</div>
                            `;
                            messagesDiv.appendChild(div);
                            lastMessageId = msg.id;
                        });
                        
                        if (wasAtBottom) {
                            scrollToBottom();
                        }
                    }
                })
                .catch(error => console.error('Polling error:', error));
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
        
        function timeAgo(t) {
            let d = Math.floor((new Date() - new Date(t)) / 1000);
            if (d < 60) return 'только что';
            if (d < 3600) return Math.floor(d / 60) + ' мин назад';
            if (d < 86400) return Math.floor(d / 3600) + ' ч назад';
            return Math.floor(d / 86400) + ' дн назад';
        }
        
        setInterval(loadNewMessages, 1000);
    </script>
</body>
</html>