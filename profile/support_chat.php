<?php

declare(strict_types=1);
session_start();
require __DIR__ . '/../inc/dd_bb.php';

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    header('Location: /auth/login.php');
    exit;
}
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
// Получаем или создаем чат поддержки
$stmt = $pdo->prepare("SELECT id FROM support_chats WHERE user_id = ?");
$stmt->execute([$userId]);
$chat = $stmt->fetch();

if (!$chat) {
    $stmt = $pdo->prepare("INSERT INTO support_chats (user_id, created_at) VALUES (?, NOW())");
    $stmt->execute([$userId]);
    $chatId = $pdo->lastInsertId();
} else {
    $chatId = $chat['id'];
}

// Получаем сообщения
$stmt = $pdo->prepare("
    SELECT m.*, u.name as sender_name, 
           CASE WHEN m.sender_id = ? THEN 1 ELSE 0 END as is_my
    FROM support_messages m
    JOIN users u ON u.id = m.sender_id
    WHERE m.chat_id = ?
    ORDER BY m.created_at ASC
");
$stmt->execute([$userId, $chatId]);
$messages = $stmt->fetchAll();

// Отправка сообщения
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message'])) {
    $message = trim($_POST['message']);
    if (!empty($message)) {
        $stmt = $pdo->prepare("INSERT INTO support_messages (chat_id, sender_id, message, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$chatId, $userId, $message]);
        $success = 'Сообщение отправлено';
        // Обновляем сообщения
        $stmt = $pdo->prepare("
            SELECT m.*, u.name as sender_name, 
                   CASE WHEN m.sender_id = ? THEN 1 ELSE 0 END as is_my
            FROM support_messages m
            JOIN users u ON u.id = m.sender_id
            WHERE m.chat_id = ?
            ORDER BY m.created_at ASC
        ");
        $stmt->execute([$userId, $chatId]);
        $messages = $stmt->fetchAll();
    } else {
        $error = 'Введите сообщение';
    }
}

// AJAX получение новых сообщений
if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
    header('Content-Type: application/json');
    $lastId = (int)($_GET['last_id'] ?? 0);
    
    $stmt = $pdo->prepare("
        SELECT m.*, u.name as sender_name, 
               CASE WHEN m.sender_id = ? THEN 1 ELSE 0 END as is_my
        FROM support_messages m
        JOIN users u ON u.id = m.sender_id
        WHERE m.chat_id = ? AND m.id > ?
        ORDER BY m.created_at ASC
    ");
    $stmt->execute([$userId, $chatId, $lastId]);
    $newMessages = $stmt->fetchAll();
    
    echo json_encode(['success' => true, 'messages' => $newMessages]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Поддержка — Лист</title>
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
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Inter', 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background: #0f172a;
            color: #fff;
            height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .header {
            background: rgba(17, 24, 39, 0.95);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255,255,255,0.06);
            padding: 12px 20px;
            padding-top: max(12px, env(safe-area-inset-top));
            display: flex;
            align-items: center;
            gap: 16px;
            flex-shrink: 0;
        }
        .back-btn {
            background: #1e293b;
            border: none;
            width: 36px;
            height: 36px;
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: #fff;
            font-size: 20px;
            text-decoration: none;
        }
        .header h1 {
            font-size: 18px;
            font-weight: 600;
        }
        .admin-badge {
            background: #3b82f6;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 10px;
            margin-left: auto;
        }
        
        /* Контейнер сообщений - растягивается */
        .messages-container {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .message {
            display: flex;
            flex-direction: column;
            max-width: 80%;
        }
        .message.my {
            align-self: flex-end;
        }
        .message.admin {
            align-self: flex-start;
        }
        .message-bubble {
            padding: 10px 14px;
            border-radius: 18px;
            font-size: 14px;
            word-wrap: break-word;
        }
        .message.my .message-bubble {
            background: #3b82f6;
            color: #fff;
            border-bottom-right-radius: 4px;
        }
        .message.admin .message-bubble {
            background: #1e293b;
            color: #fff;
            border-bottom-left-radius: 4px;
        }
        .message-time {
            font-size: 10px;
            color: #64748b;
            margin-top: 4px;
            margin-left: 8px;
        }
        .message.my .message-time {
            text-align: right;
        }
        
        /* Форма ввода - прижата к низу */
        .message-form {
            background: #111827;
            border-top: 1px solid #1e293b;
            padding: 12px 20px;
            padding-bottom: max(12px, env(safe-area-inset-bottom));
            display: flex;
            gap: 12px;
            flex-shrink: 0;
        }
        .message-form input {
            flex: 1;
            padding: 12px 16px;
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 24px;
            color: #fff;
            font-size: 15px;
            outline: none;
        }
        .message-form input:focus {
            border-color: #3b82f6;
        }
        .message-form button {
            width: 44px;
            height: 44px;
            border-radius: 22px;
            background: #3b82f6;
            border: none;
            color: #fff;
            font-size: 18px;
            cursor: pointer;
            transition: 0.2s;
        }
        .message-form button:hover {
            background: #2563eb;
        }
        
        .empty {
            text-align: center;
            color: #64748b;
            padding: 40px;
        }
        
        .success, .error {
            padding: 10px 16px;
            border-radius: 12px;
            margin: 10px 20px;
            text-align: center;
            font-size: 13px;
        }
        .success {
            background: rgba(34, 197, 94, 0.1);
            color: #4ade80;
        }
        .error {
            background: rgba(239, 68, 68, 0.1);
            color: #f87171;
        }
        
        @media (max-width: 600px) {
            .messages-container {
                padding: 16px;
            }
            .message {
                max-width: 90%;
            }
            .success, .error {
                margin: 10px 16px;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <a href="/profile/profile.php" class="back-btn">←</a>
        <h1>Поддержка</h1>
        <div class="admin-badge">Ответ в течение 24 часов</div>
    </div>
    
    <?php if ($success): ?>
        <div class="success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <div class="messages-container" id="messagesContainer">
        <?php if (count($messages) > 0): ?>
            <?php foreach ($messages as $msg): ?>
                <div class="message <?= $msg['is_my'] ? 'my' : 'admin' ?>">
                    <div class="message-bubble">
                        <?= nl2br(htmlspecialchars($msg['message'])) ?>
                    </div>
                    <div class="message-time">
                        <?= date('H:i', strtotime($msg['created_at'])) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty">
                💬 Нет сообщений<br>
                Напишите свой вопрос, и мы ответим в ближайшее время.
            </div>
        <?php endif; ?>
    </div>
    
    <form method="POST" class="message-form" id="messageForm">
        <input type="text" name="message" id="messageInput" placeholder="Напишите сообщение..." required autocomplete="off">
        <button type="submit">➤</button>
    </form>
    
    <script>
        const messagesContainer = document.getElementById('messagesContainer');
        const messageForm = document.getElementById('messageForm');
        const messageInput = document.getElementById('messageInput');
        let lastMessageId = <?= !empty($messages) ? end($messages)['id'] : 0 ?>;
        
        // Скролл вниз при загрузке
        if (messagesContainer) {
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }
        
        // Отправка через AJAX без перезагрузки
        if (messageForm) {
            messageForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                
                const message = messageInput.value.trim();
                if (!message) return;
                
                const formData = new FormData();
                formData.append('message', message);
                
                try {
                    const response = await fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    });
                    
                    const text = await response.text();
                    if (response.ok) {
                        messageInput.value = '';
                        // Перезагружаем сообщения
                        loadNewMessages();
                    }
                } catch (error) {
                    console.error('Send error:', error);
                }
            });
        }
        
        // Long polling для новых сообщений
        async function loadNewMessages() {
            try {
                const response = await fetch(window.location.href + '?ajax=1&last_id=' + lastMessageId);
                const data = await response.json();
                
                if (data.success && data.messages && data.messages.length > 0) {
                    const wasAtBottom = messagesContainer.scrollHeight - messagesContainer.scrollTop - messagesContainer.clientHeight < 100;
                    
                    data.messages.forEach(msg => {
                        const messageDiv = document.createElement('div');
                        messageDiv.className = `message ${msg.is_my ? 'my' : 'admin'}`;
                        messageDiv.innerHTML = `
                            <div class="message-bubble">${escapeHtml(msg.message).replace(/\n/g, '<br>')}</div>
                            <div class="message-time">${new Date(msg.created_at).toLocaleTimeString().slice(0,5)}</div>
                        `;
                        messagesContainer.appendChild(messageDiv);
                        lastMessageId = msg.id;
                    });
                    
                    if (wasAtBottom) {
                        messagesContainer.scrollTop = messagesContainer.scrollHeight;
                    }
                    
                    // Убираем empty если был
                    const emptyDiv = messagesContainer.querySelector('.empty');
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
        
        // Запускаем polling
        loadNewMessages();
    </script>
</body>
</html>