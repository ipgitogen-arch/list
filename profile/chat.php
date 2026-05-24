<?php
session_start();
require __DIR__ . '/../inc/dd_bb.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /auth/login.php');
    exit;
}

$userId = $_SESSION['user_id'];
$receiverId = (int)($_GET['user'] ?? 0);

if (!$receiverId) {
    header('Location: /profile/dialogs.php');
    exit;
}

// Получаем данные о подписке
$stmt = $pdo->prepare("SELECT subscription, is_plus FROM users WHERE id = ?");
$stmt->execute([$userId]);
$userData = $stmt->fetch();
$hasPlus = ($userData['subscription'] === 'plus' || !empty($userData['is_plus']));

// Получаем данные собеседника
$stmt = $pdo->prepare("SELECT id, name, uid, is_plus, last_seen FROM users WHERE id = ?");
$stmt->execute([$receiverId]);
$receiver = $stmt->fetch();

if (!$receiver) {
    header('Location: /profile/dialogs.php');
    exit;
}

// Проверяем, не заблокирован ли пользователь
$stmt = $pdo->prepare("SELECT id FROM blocked_users WHERE user_id = ? AND blocked_user_id = ?");
$stmt->execute([$userId, $receiverId]);
$isBlocked = $stmt->fetch() !== false;

// Проверяем, не заблокировал ли нас собеседник
$stmt = $pdo->prepare("SELECT id FROM blocked_users WHERE user_id = ? AND blocked_user_id = ?");
$stmt->execute([$receiverId, $userId]);
$blockedByReceiver = $stmt->fetch() !== false;

if ($blockedByReceiver) {
    $_SESSION['error'] = 'Вы не можете отправлять сообщения этому пользователю.';
    header('Location: /profile/dialogs.php');
    exit;
}

// Обработка действий меню
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Удалить пользователя (удалить диалог)
    if (isset($_POST['delete_user'])) {
        $stmt = $pdo->prepare("
            DELETE FROM messages 
            WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)
        ");
        $stmt->execute([$userId, $receiverId, $receiverId, $userId]);
        $_SESSION['success'] = 'Пользователь удален из чатов';
        header('Location: /profile/dialogs.php');
        exit;
    }
    
    // Заблокировать пользователя
    if (isset($_POST['block_user'])) {
        // Добавляем в черный список
        $stmt = $pdo->prepare("INSERT INTO blocked_users (user_id, blocked_user_id, created_at) VALUES (?, ?, NOW())");
        $stmt->execute([$userId, $receiverId]);
        // Удаляем все сообщения
        $stmt = $pdo->prepare("
            DELETE FROM messages 
            WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)
        ");
        $stmt->execute([$userId, $receiverId, $receiverId, $userId]);
        $_SESSION['success'] = 'Пользователь заблокирован';
        header('Location: /profile/dialogs.php');
        exit;
    }
    
    // Удалить всю переписку
    if (isset($_POST['delete_all_messages'])) {
        $stmt = $pdo->prepare("
            DELETE FROM messages 
            WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)
        ");
        $stmt->execute([$userId, $receiverId, $receiverId, $userId]);
        $_SESSION['success'] = 'Вся переписка удалена';
        header('Location: /profile/chat.php?user=' . $receiverId);
        exit;
    }
}

// AJAX polling
if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
    header('Content-Type: application/json');
    $lastId = (int)($_GET['last_id'] ?? 0);
    
    $stmt = $pdo->prepare("
        SELECT m.*, u.name as sender_name
        FROM messages m
        JOIN users u ON u.id = m.sender_id
        WHERE ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)) AND m.id > ?
        ORDER BY m.created_at ASC
    ");
    $stmt->execute([$userId, $receiverId, $receiverId, $userId, $lastId]);
    $newMessages = $stmt->fetchAll();
    
    echo json_encode(['success' => true, 'messages' => $newMessages]);
    exit;
}

// Отправка сообщения
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    if ($isBlocked) {
        $_SESSION['error'] = 'Вы заблокировали этого пользователя';
        header('Location: /profile/chat.php?user=' . $receiverId);
        exit;
    }
    
    $message = trim($_POST['message'] ?? '');
    $attachment = null;
    
    if (isset($_FILES['file_attachment']) && $_FILES['file_attachment']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/../uploads/chat/';
        if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);
        
        $file = $_FILES['file_attachment'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $fileName = time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $filePath = 'uploads/chat/' . $fileName;
        
        if (move_uploaded_file($file['tmp_name'], $uploadDir . $fileName)) {
            $attachment = $filePath;
        }
    }
    
    if ($message !== '' || $attachment !== null) {
        $stmt = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, content, attachment, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$userId, $receiverId, $message, $attachment]);
    }
    
    header('Location: /profile/chat.php?user=' . $receiverId);
    exit;
}

// Удаление сообщения
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_message'])) {
    $messageId = (int)$_POST['message_id'];
    $stmt = $pdo->prepare("DELETE FROM messages WHERE id = ? AND sender_id = ?");
    $stmt->execute([$messageId, $userId]);
    header('Location: /profile/chat.php?user=' . $receiverId);
    exit;
}

// Отложенная отправка (PLUS)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['schedule_message'])) {
    if (!$hasPlus) {
        $_SESSION['error'] = 'Функция доступна только с подпиской PLUS';
        header('Location: /profile/chat.php?user=' . $receiverId);
        exit;
    }
    
    $message = trim($_POST['message'] ?? '');
    $scheduledDate = $_POST['scheduled_date'] ?? '';
    $scheduledTime = $_POST['scheduled_time'] ?? '';
    $attachment = null;
    
    $localDateTime = $scheduledDate . ' ' . $scheduledTime . ':00';
    $scheduledAt = (new DateTime($localDateTime, new DateTimeZone('Europe/Moscow')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    
    if (isset($_FILES['schedule_file']) && $_FILES['schedule_file']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/../uploads/chat/';
        if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);
        
        $file = $_FILES['schedule_file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $fileName = time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $filePath = 'uploads/chat/' . $fileName;
        
        if (move_uploaded_file($file['tmp_name'], $uploadDir . $fileName)) {
            $attachment = $filePath;
        }
    }
    
    if ($message && $scheduledDate && $scheduledTime) {
        $stmt = $pdo->prepare("INSERT INTO scheduled_messages (user_id, receiver_id, message, attachment, scheduled_at, status) VALUES (?, ?, ?, ?, ?, 'pending')");
        $stmt->execute([$userId, $receiverId, $message, $attachment, $scheduledAt]);
        $_SESSION['success'] = 'Сообщение запланировано';
    }
    header('Location: /profile/chat.php?user=' . $receiverId);
    exit;
}

// Удаление отложенного
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_scheduled'])) {
    $scheduledId = (int)$_POST['scheduled_id'];
    $stmt = $pdo->prepare("DELETE FROM scheduled_messages WHERE id = ? AND user_id = ?");
    $stmt->execute([$scheduledId, $userId]);
    header('Location: /profile/chat.php?user=' . $receiverId);
    exit;
}

// Отправить отложенное сейчас
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_scheduled_now'])) {
    $scheduledId = (int)$_POST['scheduled_id'];
    
    $stmt = $pdo->prepare("SELECT * FROM scheduled_messages WHERE id = ? AND user_id = ? AND status = 'pending'");
    $stmt->execute([$scheduledId, $userId]);
    $msg = $stmt->fetch();
    
    if ($msg) {
        $stmt2 = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, content, attachment, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt2->execute([$userId, $receiverId, $msg['message'], $msg['attachment']]);
        $stmt2 = $pdo->prepare("DELETE FROM scheduled_messages WHERE id = ?");
        $stmt2->execute([$scheduledId]);
        $_SESSION['success'] = 'Сообщение отправлено';
    }
    header('Location: /profile/chat.php?user=' . $receiverId);
    exit;
}

// Получаем историю сообщений
$stmt = $pdo->prepare("
    SELECT m.*, u.name as sender_name
    FROM messages m
    JOIN users u ON u.id = m.sender_id
    WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)
    ORDER BY m.created_at ASC
");
$stmt->execute([$userId, $receiverId, $receiverId, $userId]);
$messages = $stmt->fetchAll();

// Получаем отложенные сообщения
$scheduledMessages = [];
if ($hasPlus) {
    $stmt = $pdo->prepare("
        SELECT *, CONVERT_TZ(scheduled_at, '+00:00', '+03:00') as local_scheduled_at
        FROM scheduled_messages 
        WHERE user_id = ? AND receiver_id = ? AND status = 'pending' AND scheduled_at > UTC_TIMESTAMP()
        ORDER BY scheduled_at ASC
    ");
    $stmt->execute([$userId, $receiverId]);
    $scheduledMessages = $stmt->fetchAll();
}

$stmt = $pdo->prepare("UPDATE messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ?");
$stmt->execute([$receiverId, $userId]);

$lastMessageId = !empty($messages) ? end($messages)['id'] : 0;
$error = $_SESSION['error'] ?? null;
$success = $_SESSION['success'] ?? null;
unset($_SESSION['error'], $_SESSION['success']);

function timeAgo($timestamp) {
    $diff = time() - strtotime($timestamp);
    if ($diff < 60) return 'только что';
    if ($diff < 3600) return floor($diff / 60) . ' мин';
    if ($diff < 86400) return floor($diff / 3600) . ' ч';
    return floor($diff / 86400) . ' дн';
}

function isOnline($lastSeen) {
    return $lastSeen && (time() - (int)$lastSeen) < 300;
}

function formatFileLink($content, $attachment) {
    $html = '';
    if ($attachment) {
        $ext = strtolower(pathinfo($attachment, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'svg'])) {
            $html .= '<div class="message-file"><img src="/' . $attachment . '" class="chat-image" data-full="/' . $attachment . '" style="max-width:200px; max-height:200px; border-radius:12px; cursor:pointer;"></div>';
        } elseif (in_array($ext, ['mp4', 'webm', 'mov', 'avi'])) {
            $html .= '<div class="message-file"><video src="/' . $attachment . '" controls style="max-width:250px; border-radius:12px;"></video></div>';
        } elseif (in_array($ext, ['mp3', 'ogg', 'wav', 'm4a'])) {
            $html .= '<div class="message-file custom-audio-player" data-src="/' . $attachment . '">
                        <div class="audio-controls">
                            <button class="play-pause-btn">▶</button>
                            <div class="progress-container">
                                <div class="progress-bar">
                                    <div class="progress-bar-fill"></div>
                                </div>
                                <div class="progress-time">0:00 / 0:00</div>
                            </div>
                        </div>
                        <a href="/' . $attachment . '" download class="download-file">⬇</a>
                    </div>';
        } else {
            $html .= '<div class="message-file"><a href="/' . $attachment . '" download class="file-link" style="color:#60a5fa;">📎 ' . htmlspecialchars(basename($attachment)) . '</a></div>';
        }
    }
    if ($content) {
        $html .= '<div class="message-text">' . nl2br(htmlspecialchars($content)) . '</div>';
    }
    return $html;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Чат с <?= htmlspecialchars($receiver['name']) ?> — Лист</title>
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
            font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Text', 'Helvetica Neue', system-ui, sans-serif;
            background: #0f172a;
            color: #f1f5f9;
            height: 100vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .gradient-bg {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: radial-gradient(ellipse at 20% 30%, #1e293b, #0f172a, #020617);
            z-index: -1;
        }
        .chat-header {
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
        .back-btn { color: #3b82f6; text-decoration: none; font-size: 24px; }
        .chat-user { flex: 1; }
        .chat-user-name { font-size: 17px; font-weight: 600; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .chat-user-status { font-size: 12px; color: #94a3b8; }
        .online-dot { width: 8px; height: 8px; border-radius: 4px; display: inline-block; margin-right: 4px; }
        .online { background: #22c55e; }
        .offline { background: #64748b; }
        .plus-badge { background: linear-gradient(135deg, #f59e0b, #d97706); padding: 2px 8px; border-radius: 20px; font-size: 10px; margin-left: 6px; }
        
        /* Кнопка с тремя точками */
        .menu-btn {
            background: #1e293b;
            width: 40px;
            height: 40px;
            border-radius: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: none;
            color: #fff;
            font-size: 20px;
            cursor: pointer;
            transition: 0.2s;
            flex-shrink: 0;
        }
        .menu-btn:hover {
            background: #334155;
        }
        .menu-container {
            position: relative;
        }
        .chat-menu {
            position: absolute;
            top: 50px;
            right: 0;
            background: #1e293b;
            border-radius: 16px;
            overflow: hidden;
            display: none;
            flex-direction: column;
            z-index: 200;
            border: 1px solid rgba(255,255,255,0.1);
            min-width: 220px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.3);
        }
        .chat-menu.show {
            display: flex;
        }
        .menu-item {
            padding: 12px 16px;
            background: transparent;
            border: none;
            color: #fff;
            text-align: left;
            cursor: pointer;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            width: 100%;
            transition: 0.2s;
        }
        .menu-item:hover {
            background: #334155;
        }
        .menu-item.danger {
            color: #f87171;
        }
        .menu-item.danger:hover {
            background: #7f1a1a;
            color: #fff;
        }
        hr {
            border-color: #334155;
            margin: 4px 0;
        }
        
        .messages-area {
            flex: 1;
            overflow-y: auto;
            padding: 20px 16px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .messages-area::-webkit-scrollbar { width: 0; background: transparent; }
        .message {
            display: flex;
            flex-direction: column;
            max-width: 80%;
            position: relative;
        }
        .message.outgoing { align-self: flex-end; }
        .message.incoming { align-self: flex-start; }
        .message-bubble {
            padding: 10px 14px;
            border-radius: 18px;
            word-wrap: break-word;
        }
        .outgoing .message-bubble {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            border-bottom-right-radius: 4px;
        }
        .incoming .message-bubble {
            background: rgba(51, 65, 85, 0.8);
            border-bottom-left-radius: 4px;
        }
        .message-time {
            font-size: 10px;
            color: #64748b;
            margin-top: 4px;
            margin-left: 8px;
            margin-right: 8px;
        }
        .outgoing .message-time { text-align: right; }
        
        .delete-message {
            position: absolute;
            top: -8px;
            right: -8px;
            width: 24px;
            height: 24px;
            border-radius: 12px;
            background: #ef4444;
            border: none;
            color: white;
            font-size: 12px;
            cursor: pointer;
            opacity: 0;
            transition: 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10;
        }
        .message:hover .delete-message { opacity: 1; }
        .delete-message:hover { transform: scale(1.1); background: #dc2626; }
        
        /* Кастомный аудиоплеер */
        .custom-audio-player {
            background: rgba(0, 0, 0, 0.3);
            border-radius: 20px;
            padding: 8px 12px;
            min-width: 200px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .audio-controls {
            display: flex;
            align-items: center;
            gap: 8px;
            flex: 1;
        }
        .play-pause-btn {
            width: 28px;
            height: 28px;
            border-radius: 14px;
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: #fff;
            font-size: 12px;
            cursor: pointer;
            transition: 0.2s;
        }
        .play-pause-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: scale(1.05);
        }
        .progress-container {
            flex: 1;
            cursor: pointer;
        }
        .progress-bar {
            height: 3px;
            background: rgba(255, 255, 255, 0.3);
            border-radius: 2px;
            position: relative;
            margin-bottom: 2px;
        }
        .progress-bar-fill {
            width: 0%;
            height: 100%;
            background: #3b82f6;
            border-radius: 2px;
            transition: width 0.1s linear;
        }
        .progress-time {
            font-size: 9px;
            color: #94a3b8;
        }
        .download-file {
            margin-left: 4px;
            font-size: 14px;
        }
        
        .image-modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.95);
            z-index: 2000;
            display: none;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }
        .image-modal img {
            max-width: 90%;
            max-height: 90%;
            object-fit: contain;
        }
        .image-modal-close {
            position: absolute;
            top: 20px;
            right: 30px;
            font-size: 40px;
            color: white;
            cursor: pointer;
        }
        
        .input-container {
            background: rgba(17, 24, 39, 0.95);
            backdrop-filter: blur(10px);
            border-top: 1px solid rgba(255,255,255,0.06);
            padding: 12px 20px;
            padding-bottom: max(12px, env(safe-area-inset-bottom));
            flex-shrink: 0;
        }
        .preview-area {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 8px;
        }
        .preview-file {
            background: rgba(30, 41, 59, 0.8);
            border-radius: 12px;
            padding: 8px 12px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            position: relative;
        }
        .preview-file .remove {
            background: none;
            border: none;
            color: #ef4444;
            cursor: pointer;
            font-size: 16px;
        }
        .upload-progress {
            position: absolute;
            bottom: -2px;
            left: 0;
            height: 2px;
            background: #3b82f6;
            width: 0%;
            transition: width 0.3s;
            border-radius: 2px;
        }
        .input-row {
            display: flex;
            gap: 12px;
            align-items: center;
        }
        .plus-menu { position: relative; }
        .plus-btn {
            width: 44px;
            height: 44px;
            border-radius: 22px;
            background: rgba(30, 41, 59, 0.8);
            border: 1px solid rgba(255,255,255,0.1);
            color: #fff;
            font-size: 24px;
            cursor: pointer;
            transition: 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .plus-btn:hover { background: #3b82f6; }
        .plus-dropdown {
            position: absolute;
            bottom: 55px;
            left: 0;
            background: #1e293b;
            border-radius: 16px;
            overflow: hidden;
            display: none;
            flex-direction: column;
            z-index: 200;
            border: 1px solid rgba(255,255,255,0.1);
        }
        .plus-dropdown.show { display: flex; }
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
        .dropdown-item:hover { background: #334155; }
        .message-input {
            flex: 1;
            padding: 12px 16px;
            border-radius: 24px;
            background: rgba(30, 41, 59, 0.8);
            border: 1px solid rgba(255,255,255,0.1);
            color: #fff;
            font-size: 15px;
            font-family: inherit;
            resize: none;
            outline: none;
            height: 44px;
            overflow-y: hidden;
        }
        .send-btn {
            height: 44px;
            padding: 0 24px;
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            border: none;
            border-radius: 22px;
            color: #fff;
            font-weight: 600;
            cursor: pointer;
        }
        .file-input { display: none; }
        
        .scheduled-btn {
            background: rgba(245, 158, 11, 0.2);
            border-color: rgba(245, 158, 11, 0.3);
            color: #fbbf24;
        }
        
        .error-message, .success-message {
            padding: 10px;
            border-radius: 12px;
            margin-bottom: 10px;
            text-align: center;
            font-size: 13px;
        }
        .error-message { background: rgba(239,68,68,0.15); border: 1px solid #ef4444; color: #f87171; }
        .success-message { background: rgba(34,197,94,0.15); border: 1px solid #22c55e; color: #4ade80; }
        
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
            background: #1e293b;
            border-radius: 24px;
            padding: 24px;
            max-width: 450px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
        }
        .modal h3 { margin-bottom: 20px; text-align: center; }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; margin-bottom: 8px; font-size: 14px; }
        .form-input {
            width: 100%;
            padding: 10px;
            border-radius: 12px;
            background: #0f172a;
            border: 1px solid #334155;
            color: #fff;
        }
        .modal-textarea {
            width: 100%;
            padding: 10px;
            border-radius: 12px;
            background: #0f172a;
            border: 1px solid #334155;
            color: #fff;
            font-family: inherit;
            resize: vertical;
        }
        .scheduled-item-card {
            background: #0f172a;
            border-radius: 16px;
            padding: 12px;
            margin-bottom: 12px;
        }
        .modal-buttons { display: flex; gap: 12px; margin-top: 20px; flex-wrap: wrap; }
        .modal-buttons button { flex: 1; padding: 10px; border-radius: 40px; cursor: pointer; border: none; font-weight: 500; }
        .btn-danger { background: #ef4444; color: #fff; }
        .btn-primary { background: #3b82f6; color: #fff; }
        .btn-secondary { background: #334155; color: #fff; }
        .btn-success { background: #22c55e; color: #fff; }
        
        .message-file {
            margin-bottom: 8px;
        }
        .message-text {
            word-break: break-word;
        }
        .chat-image {
            transition: transform 0.2s;
        }
        .chat-image:hover {
            transform: scale(1.02);
        }
        
        @media (max-width: 768px) {
            .message { max-width: 90%; }
            .plus-btn { width: 40px; height: 40px; font-size: 20px; }
            .send-btn { padding: 0 16px; }
            .custom-audio-player { min-width: 160px; }
            .menu-btn { width: 36px; height: 36px; font-size: 18px; }
            .chat-menu { min-width: 180px; }
        }
    </style>
</head>
<body>

<div class="gradient-bg"></div>

<!-- Модальное окно для просмотра фото -->
<div id="imageModal" class="image-modal" onclick="closeImageModal()">
    <span class="image-modal-close">&times;</span>
    <img id="modalImage" src="">
</div>

<div class="chat-header">
    <a href="/profile/dialogs.php" class="back-btn">←</a>
    <div class="chat-user">
        <div class="chat-user-name">
            <?= htmlspecialchars($receiver['name']) ?>
            <?php if ($receiver['is_plus']): ?><span class="plus-badge">PLUS</span><?php endif; ?>
        </div>
        <div class="chat-user-status">
            <span class="online-dot <?= isOnline($receiver['last_seen']) ? 'online' : 'offline' ?>"></span>
            <?= isOnline($receiver['last_seen']) ? 'онлайн' : 'офлайн' ?>
        </div>
    </div>
    <div class="menu-container">
        <button class="menu-btn" id="menuBtn">⋮</button>
        <div class="chat-menu" id="chatMenu">
            <form method="post" id="deleteUserForm">
                <input type="hidden" name="delete_user" value="1">
                <button type="submit" name="delete_user" class="menu-item danger" onclick="return confirm('Удалить пользователя из чатов? Вся переписка будет удалена.')">
                    🗑️ Удалить пользователя
                </button>
            </form>
            <form method="post" id="blockUserForm">
                <input type="hidden" name="block_user" value="1">
                <button type="submit" name="block_user" class="menu-item danger" onclick="return confirm('Заблокировать пользователя? Вы не сможете отправлять ему сообщения.')">
                    🚫 Заблокировать пользователя
                </button>
            </form>
            <hr>
            <form method="post" id="deleteMessagesForm">
                <input type="hidden" name="delete_all_messages" value="1">
                <button type="submit" name="delete_all_messages" class="menu-item danger" onclick="return confirm('Удалить всю переписку? Это действие нельзя отменить.')">
                    📋 Удалить всю переписку
                </button>
            </form>
        </div>
    </div>
</div>

<div class="messages-area" id="messagesArea">
    <?php foreach ($messages as $msg): ?>
        <div class="message <?= $msg['sender_id'] == $userId ? 'outgoing' : 'incoming' ?>">
            <?php if ($msg['sender_id'] == $userId): ?>
            <form method="post" style="position: absolute; top: -8px; right: -8px; z-index: 10;">
                <input type="hidden" name="message_id" value="<?= $msg['id'] ?>">
                <button type="submit" name="delete_message" class="delete-message" onclick="return confirm('Удалить сообщение?')">✕</button>
            </form>
            <?php endif; ?>
            <div class="message-bubble">
                <?= formatFileLink($msg['content'], $msg['attachment']) ?>
            </div>
            <div class="message-time"><?= date('H:i', strtotime($msg['created_at'])) ?> <?= timeAgo($msg['created_at']) ?></div>
        </div>
    <?php endforeach; ?>
</div>

<div class="input-container">
    <?php if ($error): ?><div class="error-message"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="success-message"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    
    <form method="post" id="chatForm" enctype="multipart/form-data">
        <div class="preview-area" id="previewArea"></div>
        <div class="input-row">
            <div class="plus-menu">
                <button type="button" class="plus-btn" id="plusBtn">+</button>
                <div class="plus-dropdown" id="plusDropdown">
                    <button type="button" class="dropdown-item" id="attachFileBtn">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2L3 14h5v8h8v-8h5L13 2z"/></svg>
                        Файл
                    </button>
                    <?php if ($hasPlus): ?>
                    <button type="button" class="dropdown-item" id="createScheduledBtn">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        Таймер
                    </button>
                    <button type="button" class="dropdown-item scheduled-btn" id="scheduledListBtn">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4v16h16"/><path d="M8 8h8"/><path d="M8 12h6"/><path d="M8 16h4"/></svg>
                        Отложенные (<?= count($scheduledMessages) ?>)
                    </button>
                    <?php else: ?>
                    <button type="button" class="dropdown-item" id="buyPlusBtn">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        Таймер (PLUS)
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <textarea class="message-input" id="messageInput" name="message" placeholder="Сообщение..." rows="1"></textarea>
            <button type="submit" name="send_message" class="send-btn">Отправить</button>
        </div>
        <input type="file" name="file_attachment" id="fileInput" class="file-input" accept="*/*">
    </form>
</div>

<?php if ($hasPlus): ?>
<div id="scheduledListModal" class="modal-overlay" style="display:none;">
    <div class="modal">
        <h3>Отложенные сообщения</h3>
        <div id="scheduledListContainer">
            <?php if (empty($scheduledMessages)): ?>
                <div style="text-align:center; padding:20px; color:#64748b;">Нет отложенных сообщений</div>
            <?php else: ?>
                <?php foreach ($scheduledMessages as $sm): ?>
                <div class="scheduled-item-card">
                    <div style="margin-bottom:8px; color:#fbbf24; font-size:13px;">📅 <?= date('d.m.Y H:i', strtotime($sm['local_scheduled_at'])) ?></div>
                    <div style="margin-bottom:8px; word-break:break-word;"><?= nl2br(htmlspecialchars($sm['message'])) ?></div>
                    <?php if ($sm['attachment']): ?>
                    <div style="background:#1e293b; border-radius:12px; padding:8px; margin-bottom:8px; display:flex; justify-content:space-between;">
                        <span>📎 <?= basename($sm['attachment']) ?></span>
                        <form method="post" style="margin:0;">
                            <input type="hidden" name="scheduled_id" value="<?= $sm['id'] ?>">
                            <button type="submit" name="delete_scheduled_file" class="btn-danger" style="padding:4px 8px; font-size:11px;">✕</button>
                        </form>
                    </div>
                    <?php endif; ?>
                    <div style="display:flex; gap:8px; margin-top:8px;">
                        <form method="post" style="flex:1;">
                            <input type="hidden" name="scheduled_id" value="<?= $sm['id'] ?>">
                            <button type="submit" name="send_scheduled_now" class="btn-success" style="width:100%; padding:8px; border-radius:20px;">Отправить сейчас</button>
                        </form>
                        <form method="post" style="flex:1;">
                            <input type="hidden" name="scheduled_id" value="<?= $sm['id'] ?>">
                            <button type="submit" name="delete_scheduled" class="btn-danger" style="width:100%; padding:8px; border-radius:20px;">Удалить</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div class="modal-buttons" style="margin-top:16px;">
            <button type="button" class="btn-secondary" onclick="closeScheduledListModal()">Закрыть</button>
        </div>
    </div>
</div>

<div id="scheduleModal" class="modal-overlay" style="display:none;">
    <div class="modal">
        <h3>Запланировать отправку</h3>
        <form method="post" id="scheduleForm" enctype="multipart/form-data">
            <div class="form-group">
                <label>Дата</label>
                <input type="date" name="scheduled_date" id="scheduledDate" class="form-input" required min="<?= date('Y-m-d') ?>">
            </div>
            <div class="form-group">
                <label>Время</label>
                <input type="time" name="scheduled_time" id="scheduledTime" class="form-input" required>
            </div>
            <div class="form-group">
                <label>Сообщение</label>
                <textarea name="message" id="scheduleMessageText" class="modal-textarea" rows="3" required></textarea>
            </div>
            <div class="form-group">
                <label>Файл (опционально)</label>
                <input type="file" name="schedule_file" class="form-input" accept="*/*">
            </div>
            <div class="modal-buttons">
                <button type="button" class="btn-secondary" onclick="closeScheduleModal()">Отмена</button>
                <button type="submit" name="schedule_message" class="btn-primary">Запланировать</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
    const messagesArea = document.getElementById('messagesArea');
    const messageInput = document.getElementById('messageInput');
    const chatForm = document.getElementById('chatForm');
    const fileInput = document.getElementById('fileInput');
    const previewArea = document.getElementById('previewArea');
    const plusBtn = document.getElementById('plusBtn');
    const plusDropdown = document.getElementById('plusDropdown');
    const attachFileBtn = document.getElementById('attachFileBtn');
    const menuBtn = document.getElementById('menuBtn');
    const chatMenu = document.getElementById('chatMenu');
    
    let lastMessageId = <?= $lastMessageId ?>;
    let selectedFile = null;
    
    function scrollToBottom() {
        messagesArea.scrollTop = messagesArea.scrollHeight;
    }
    scrollToBottom();
    
    // Меню с тремя точками
    if (menuBtn) {
        menuBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            chatMenu.classList.toggle('show');
        });
    }
    
    document.addEventListener('click', () => {
        if (chatMenu) chatMenu.classList.remove('show');
        if (plusDropdown) plusDropdown.classList.remove('show');
    });
    
    messageInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            chatForm.submit();
        }
    });
    
    messageInput.addEventListener('input', function() {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 120) + 'px';
    });
    
    if (plusBtn) {
        plusBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            plusDropdown.classList.toggle('show');
        });
    }
    
    if (attachFileBtn) {
        attachFileBtn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            fileInput.click();
        });
    }
    
    fileInput.addEventListener('change', (e) => {
        if (e.target.files.length > 0) {
            selectedFile = e.target.files[0];
            previewArea.innerHTML = `<div class="preview-file">
                📎 ${selectedFile.name} 
                <button type="button" class="remove" onclick="clearFile()">✕</button>
                <div class="upload-progress" style="width: 100%;"></div>
            </div>`;
        }
        if (plusDropdown) plusDropdown.classList.remove('show');
    });
    
    window.clearFile = function() {
        selectedFile = null;
        fileInput.value = '';
        previewArea.innerHTML = '';
    };
    
    // Кастомный аудиоплеер
    function initAudioPlayers() {
        document.querySelectorAll('.custom-audio-player').forEach(container => {
            if (container.dataset.initialized) return;
            container.dataset.initialized = 'true';
            
            const src = container.dataset.src;
            const audio = new Audio(src);
            
            const playBtn = container.querySelector('.play-pause-btn');
            const progressBar = container.querySelector('.progress-bar');
            const timeDisplay = container.querySelector('.progress-time');
            
            let progressFill = null;
            if (progressBar) {
                progressFill = document.createElement('div');
                progressFill.className = 'progress-bar-fill';
                progressBar.appendChild(progressFill);
            }
            
            let isPlaying = false;
            
            playBtn.addEventListener('click', () => {
                if (isPlaying) {
                    audio.pause();
                    playBtn.textContent = '▶';
                } else {
                    audio.play();
                    playBtn.textContent = '⏸';
                }
                isPlaying = !isPlaying;
            });
            
            audio.addEventListener('timeupdate', () => {
                if (progressFill && audio.duration) {
                    const percent = (audio.currentTime / audio.duration) * 100;
                    progressFill.style.width = percent + '%';
                }
                if (timeDisplay && audio.duration) {
                    const current = formatTime(audio.currentTime);
                    const total = formatTime(audio.duration);
                    timeDisplay.textContent = `${current} / ${total}`;
                }
            });
            
            audio.addEventListener('ended', () => {
                playBtn.textContent = '▶';
                isPlaying = false;
                if (progressFill) progressFill.style.width = '0%';
            });
            
            if (progressBar) {
                progressBar.addEventListener('click', (e) => {
                    const rect = progressBar.getBoundingClientRect();
                    const percent = (e.clientX - rect.left) / rect.width;
                    if (audio.duration) {
                        audio.currentTime = percent * audio.duration;
                    }
                });
            }
            
            function formatTime(seconds) {
                if (isNaN(seconds)) return '0:00';
                const mins = Math.floor(seconds / 60);
                const secs = Math.floor(seconds % 60);
                return `${mins}:${secs.toString().padStart(2, '0')}`;
            }
        });
    }
    
    function openImageModal(src) {
        const modal = document.getElementById('imageModal');
        const modalImg = document.getElementById('modalImage');
        modal.style.display = 'flex';
        modalImg.src = src;
    }
    
    function closeImageModal() {
        document.getElementById('imageModal').style.display = 'none';
    }
    
    document.addEventListener('click', (e) => {
        if (e.target.classList && e.target.classList.contains('chat-image')) {
            openImageModal(e.target.dataset.full || e.target.src);
        }
    });
    
    <?php if ($hasPlus): ?>
    const scheduledListBtn = document.getElementById('scheduledListBtn');
    const scheduledListModal = document.getElementById('scheduledListModal');
    const scheduleModal = document.getElementById('scheduleModal');
    const createScheduledBtn = document.getElementById('createScheduledBtn');
    
    if (scheduledListBtn) {
        scheduledListBtn.addEventListener('click', () => {
            scheduledListModal.style.display = 'flex';
            if (plusDropdown) plusDropdown.classList.remove('show');
        });
    }
    
    if (createScheduledBtn) {
        createScheduledBtn.addEventListener('click', () => {
            const msg = messageInput.value.trim();
            if (msg) {
                document.getElementById('scheduleMessageText').value = msg;
            }
            scheduleModal.style.display = 'flex';
            if (plusDropdown) plusDropdown.classList.remove('show');
        });
    }
    
    window.closeScheduledListModal = function() {
        scheduledListModal.style.display = 'none';
    };
    
    window.closeScheduleModal = function() {
        scheduleModal.style.display = 'none';
    };
    <?php endif; ?>
    
    const buyPlusBtn = document.getElementById('buyPlusBtn');
    if (buyPlusBtn) {
        buyPlusBtn.addEventListener('click', () => {
            window.location.href = '/profile/subscribe.php';
        });
    }
    
    window.onclick = (e) => {
        if (e.target.classList.contains('modal-overlay')) {
            e.target.style.display = 'none';
        }
        if (e.target.classList.contains('image-modal')) {
            closeImageModal();
        }
    };
    
    function loadNewMessages() {
        fetch(window.location.href + '?ajax=1&last_id=' + lastMessageId)
            .then(res => res.json())
            .then(data => {
                if (data.success && data.messages.length) {
                    const wasAtBottom = messagesArea.scrollHeight - messagesArea.scrollTop - messagesArea.clientHeight < 100;
                    data.messages.forEach(msg => {
                        const div = document.createElement('div');
                        div.className = 'message ' + (msg.sender_id == <?= $userId ?> ? 'outgoing' : 'incoming');
                        
                        let deleteBtn = '';
                        if (msg.sender_id == <?= $userId ?>) {
                            deleteBtn = `<form method="post" style="position: absolute; top: -8px; right: -8px; z-index: 10;">
                                            <input type="hidden" name="message_id" value="${msg.id}">
                                            <button type="submit" name="delete_message" class="delete-message" onclick="return confirm('Удалить сообщение?')">✕</button>
                                        </form>`;
                        }
                        
                        let fileHtml = '';
                        if (msg.attachment) {
                            const ext = msg.attachment.split('.').pop().toLowerCase();
                            if (['jpg','jpeg','png','gif','webp','avif','bmp','svg'].includes(ext))
                                fileHtml = `<div class="message-file"><img src="/${msg.attachment}" class="chat-image" data-full="/${msg.attachment}" style="max-width:200px; max-height:200px; border-radius:12px; cursor:pointer;"></div>`;
                            else if (['mp4','webm','mov','avi'].includes(ext))
                                fileHtml = `<div class="message-file"><video src="/${msg.attachment}" controls style="max-width:250px; border-radius:12px;"></video></div>`;
                            else if (['mp3','ogg','wav','m4a'].includes(ext))
                                fileHtml = `<div class="message-file custom-audio-player" data-src="/${msg.attachment}">
                                                <div class="audio-controls">
                                                    <button class="play-pause-btn">▶</button>
                                                    <div class="progress-container">
                                                        <div class="progress-bar"></div>
                                                        <div class="progress-time">0:00 / 0:00</div>
                                                    </div>
                                                    <a href="/${msg.attachment}" download class="download-file">⬇</a>
                                                </div>
                                            </div>`;
                            else
                                fileHtml = `<div class="message-file"><a href="/${msg.attachment}" download class="file-link" style="color:#60a5fa;">📎 ${msg.attachment.split('/').pop()}</a></div>`;
                        }
                        let textHtml = msg.content ? `<div class="message-text">${escapeHtml(msg.content).replace(/\n/g,'<br>')}</div>` : '';
                        
                        div.innerHTML = deleteBtn + `<div class="message-bubble">${fileHtml}${textHtml}</div><div class="message-time">${new Date(msg.created_at).toLocaleTimeString().slice(0,5)} ${timeAgo(msg.created_at)}</div>`;
                        messagesArea.appendChild(div);
                        lastMessageId = msg.id;
                    });
                    if (wasAtBottom) scrollToBottom();
                    initAudioPlayers();
                }
            });
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
        if (d < 3600) return Math.floor(d / 60) + ' мин';
        if (d < 86400) return Math.floor(d / 3600) + ' ч';
        return Math.floor(d / 86400) + ' дн';
    }
    
    setInterval(loadNewMessages, 2000);
    initAudioPlayers();
    
    if (window.navigator.standalone === true || window.matchMedia('(display-mode: standalone)').matches) {
        document.body.style.paddingTop = 'env(safe-area-inset-top)';
    }
</script>
</body>
</html>