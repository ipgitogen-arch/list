<?php
session_start();
require __DIR__ . '/../inc/dd_bb.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /auth/login.php');
    exit;
}

$userId = $_SESSION['user_id'];

// Проверяем PLUS подписку
$stmt = $pdo->prepare("SELECT subscription, is_plus FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();
$hasPlus = ($user['subscription'] === 'plus' || $user['is_plus'] == 1);

if (!$hasPlus) {
    header('Location: /profile/subscribe.php');
    exit;
}

// Получаем историю чата
$stmt = $pdo->prepare("SELECT * FROM bot_chat_history WHERE user_id = ? ORDER BY created_at ASC LIMIT 50");
$stmt->execute([$userId]);
$history = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=yes">
    <title>ИИ Бот — Лист</title>
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
            height: 100vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        
        .header {
            background: rgba(17, 24, 39, 0.95);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255,255,255,0.06);
            padding: 12px 20px;
            padding-top: max(12px, env(safe-area-inset-top));
            padding-left: max(20px, env(safe-area-inset-left));
            padding-right: max(20px, env(safe-area-inset-right));
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
        .clear-btn {
            background: #1e293b;
            border: none;
            padding: 8px 16px;
            border-radius: 40px;
            color: #94a3b8;
            font-size: 13px;
            cursor: pointer;
            transition: 0.2s;
        }
        .clear-btn:hover {
            background: #334155;
            color: #fff;
        }
        
        .chat-container {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .messages {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 16px;
            -webkit-overflow-scrolling: touch;
        }
        .message {
            display: flex;
            max-width: 80%;
        }
        .message.user {
            align-self: flex-end;
        }
        .message.bot {
            align-self: flex-start;
        }
        .message-bubble {
            padding: 12px 16px;
            border-radius: 20px;
            font-size: 14px;
            line-height: 1.5;
            word-wrap: break-word;
        }
        .message.user .message-bubble {
            background: #3b82f6;
            color: #fff;
            border-bottom-right-radius: 4px;
        }
        .message.bot .message-bubble {
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
        .message.user .message-time {
            text-align: right;
            margin-right: 8px;
        }
        
        .input-area {
            background: rgba(17, 24, 39, 0.95);
            backdrop-filter: blur(10px);
            border-top: 1px solid rgba(255,255,255,0.08);
            padding: 12px 20px;
            padding-bottom: max(12px, env(safe-area-inset-bottom));
            flex-shrink: 0;
        }
        .input-row {
            display: flex;
            gap: 12px;
            align-items: center;
        }
        .input-row input {
            flex: 1;
            padding: 12px 16px;
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 30px;
            color: #fff;
            font-size: 15px;
            outline: none;
        }
        .input-row input:focus {
            border-color: #3b82f6;
        }
        .send-btn {
            padding: 12px 24px;
            background: #3b82f6;
            border: none;
            border-radius: 30px;
            color: #fff;
            font-weight: 600;
            cursor: pointer;
            transition: 0.2s;
        }
        .send-btn:active {
            transform: scale(0.95);
        }
        
        .typing {
            color: #64748b;
            font-size: 12px;
            padding: 8px 16px;
            display: none;
        }
        
        .empty-messages {
            text-align: center;
            padding: 40px;
            color: #64748b;
        }
        
        @media (max-width: 600px) {
            .message { max-width: 90%; }
            .message-bubble { font-size: 13px; padding: 10px 14px; }
            .input-row input { padding: 10px 14px; font-size: 14px; }
            .send-btn { padding: 10px 18px; font-size: 13px; }
            .clear-btn { padding: 6px 12px; font-size: 12px; }
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
    <div style="display: flex; gap: 12px;">
        <button class="clear-btn" id="clearBtn">Очистить историю</button>
        <a href="/profile/profile.php" class="back-link">Профиль</a>
    </div>
</div>

<div class="chat-container">
    <div class="messages" id="messages">
        <?php if (count($history) > 0): ?>
            <?php foreach ($history as $msg): ?>
                <div class="message <?= $msg['role'] ?>">
                    <div class="message-bubble"><?= nl2br(htmlspecialchars($msg['content'])) ?></div>
                    <div class="message-time"><?= date('H:i', strtotime($msg['created_at'])) ?></div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-messages">
                <div style="font-size: 48px; margin-bottom: 12px;">🤖</div>
                <p>Задайте любой вопрос ИИ-помощнику</p>
                <p style="font-size: 12px; margin-top: 8px;">Бот отвечает на русском языке</p>
            </div>
        <?php endif; ?>
    </div>
    
    <div class="typing" id="typingIndicator">🤖 Бот печатает...</div>
    
    <div class="input-area">
        <form method="POST" id="chatForm" style="display: none;">
            <!-- Обычная форма для fallback -->
        </form>
        <div class="input-row">
            <input type="text" id="messageInput" placeholder="Напишите сообщение..." autocomplete="off">
            <button class="send-btn" id="sendBtn">Отправить</button>
        </div>
    </div>
</div>

<script>
    const messagesDiv = document.getElementById('messages');
    const messageInput = document.getElementById('messageInput');
    const sendBtn = document.getElementById('sendBtn');
    const typingIndicator = document.getElementById('typingIndicator');
    
    if (messagesDiv) {
        messagesDiv.scrollTop = messagesDiv.scrollHeight;
    }
    
    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/[&<>]/g, function(m) {
            if (m === '&') return '&amp;';
            if (m === '<') return '&lt;';
            if (m === '>') return '&gt;';
            return m;
        }).replace(/\n/g, '<br>');
    }
    
    async function sendMessage() {
        const message = messageInput.value.trim();
        if (!message) return;
        
        // Добавляем сообщение пользователя
        const userMessageDiv = document.createElement('div');
        userMessageDiv.className = 'message user';
        userMessageDiv.innerHTML = `
            <div class="message-bubble">${escapeHtml(message)}</div>
            <div class="message-time">только что</div>
        `;
        messagesDiv.appendChild(userMessageDiv);
        messagesDiv.scrollTop = messagesDiv.scrollHeight;
        
        messageInput.value = '';
        sendBtn.disabled = true;
        sendBtn.textContent = '⏳';
        typingIndicator.style.display = 'block';
        
        try {
            const response = await fetch('/api/bot.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ message: message })
            });
            const data = await response.json();
            
            typingIndicator.style.display = 'none';
            
            if (data.reply) {
                const botMessageDiv = document.createElement('div');
                botMessageDiv.className = 'message bot';
                botMessageDiv.innerHTML = `
                    <div class="message-bubble">${escapeHtml(data.reply)}</div>
                    <div class="message-time">только что</div>
                `;
                messagesDiv.appendChild(botMessageDiv);
                messagesDiv.scrollTop = messagesDiv.scrollHeight;
            } else if (data.error) {
                alert(data.error);
            }
        } catch (error) {
            console.error('Error:', error);
            typingIndicator.style.display = 'none';
            alert('Ошибка связи с сервером');
        } finally {
            sendBtn.disabled = false;
            sendBtn.textContent = 'Отправить';
            messageInput.focus();
        }
    }
    
    sendBtn.addEventListener('click', sendMessage);
    messageInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            sendMessage();
        }
    });
    
    // Очистка истории
    document.getElementById('clearBtn')?.addEventListener('click', async () => {
        if (confirm('Очистить всю историю чата с ботом?')) {
            try {
                const response = await fetch('/api/bot.php?action=clear', { method: 'DELETE' });
                const data = await response.json();
                if (data.success) {
                    messagesDiv.innerHTML = `
                        <div class="empty-messages">
                            <div style="font-size: 48px; margin-bottom: 12px;">🤖</div>
                            <p>История очищена. Задайте новый вопрос</p>
                        </div>
                    `;
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Ошибка очистки');
            }
        }
    });
</script>

</body>
</html>