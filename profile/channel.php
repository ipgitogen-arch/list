<?php
session_start();
require __DIR__ . '/../inc/dd_bb.php';

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    header('Location: /auth/login.php');
    exit;
}

$channelId = (int)($_GET['id'] ?? 0);

// ===== AJAX ОБРАБОТЧИКИ =====

// AJAX получение списка диалогов для шеринга
if (isset($_GET['get_dialogs']) && $_GET['get_dialogs'] == 1) {
    header('Content-Type: application/json');
    
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT 
                u.id, u.name, u.uid,
                COALESCE(p.avatar, '') as avatar
            FROM users u
            LEFT JOIN profiles p ON p.user_id = u.id
            WHERE u.id IN (
                SELECT DISTINCT receiver_id FROM messages WHERE sender_id = ?
                UNION
                SELECT DISTINCT sender_id FROM messages WHERE receiver_id = ?
            ) AND u.id != ?
            ORDER BY u.name ASC
            LIMIT 50
        ");
        $stmt->execute([$userId, $userId, $userId]);
        $personalChats = $stmt->fetchAll();
        
        $stmt = $pdo->prepare("
            SELECT 
                c.id, 
                c.name, 
                CONCAT('channel_', c.id) as uid,
                COALESCE(c.avatar, '') as avatar
            FROM channels c
            JOIN channel_subscribers cs ON cs.channel_id = c.id
            WHERE cs.user_id = ? AND cs.role IN ('owner', 'admin')
            ORDER BY c.name ASC
            LIMIT 10
        ");
        $stmt->execute([$userId]);
        $channelChats = $stmt->fetchAll();
        
        echo json_encode([
            'success' => true, 
            'personal' => $personalChats,
            'channels' => $channelChats
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// AJAX polling
if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
    header('Content-Type: application/json');
    $lastId = (int)($_GET['last_id'] ?? 0);
    
    $stmt = $pdo->prepare("
        SELECT cm.*, u.name, u.uid,
               CASE WHEN cm.user_id IS NULL THEN 'channel' ELSE 'user' END as sender_type
        FROM channel_messages cm
        LEFT JOIN users u ON u.id = cm.user_id
        WHERE cm.channel_id = ? AND cm.id > ?
        ORDER BY cm.created_at ASC
    ");
    $stmt->execute([$channelId, $lastId]);
    $newMessages = $stmt->fetchAll();
    
    echo json_encode(['success' => true, 'messages' => $newMessages]);
    exit;
}

// Лайк AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['like_message_ajax'])) {
    header('Content-Type: application/json');
    $messageId = (int)$_POST['message_id'];
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM channel_message_likes WHERE message_id = ? AND user_id = ?");
    $stmt->execute([$messageId, $userId]);
    $hasLiked = $stmt->fetchColumn();
    
    if (!$hasLiked) {
        $stmt = $pdo->prepare("INSERT INTO channel_message_likes (message_id, user_id, created_at) VALUES (?, ?, NOW())");
        $stmt->execute([$messageId, $userId]);
        $stmt = $pdo->prepare("UPDATE channel_messages SET likes = likes + 1 WHERE id = ?");
        $stmt->execute([$messageId]);
        $stmt = $pdo->prepare("SELECT likes FROM channel_messages WHERE id = ?");
        $stmt->execute([$messageId]);
        $newLikes = $stmt->fetchColumn();
        echo json_encode(['success' => true, 'liked' => true, 'likes' => $newLikes]);
    } else {
        $stmt = $pdo->prepare("DELETE FROM channel_message_likes WHERE message_id = ? AND user_id = ?");
        $stmt->execute([$messageId, $userId]);
        $stmt = $pdo->prepare("UPDATE channel_messages SET likes = likes - 1 WHERE id = ?");
        $stmt->execute([$messageId]);
        $stmt = $pdo->prepare("SELECT likes FROM channel_messages WHERE id = ?");
        $stmt->execute([$messageId]);
        $newLikes = $stmt->fetchColumn();
        echo json_encode(['success' => true, 'liked' => false, 'likes' => $newLikes]);
    }
    exit;
}

// Просмотры
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['view_message'])) {
    $messageId = (int)$_POST['message_id'];
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM channel_message_views WHERE message_id = ? AND user_id = ?");
    $stmt->execute([$messageId, $userId]);
    $hasViewed = $stmt->fetchColumn();
    
    if (!$hasViewed) {
        $stmt = $pdo->prepare("INSERT INTO channel_message_views (message_id, user_id, created_at) VALUES (?, ?, NOW())");
        $stmt->execute([$messageId, $userId]);
        $stmt = $pdo->prepare("UPDATE channel_messages SET views = views + 1 WHERE id = ?");
        $stmt->execute([$messageId]);
    }
    exit;
}

// ===== ОСНОВНАЯ ЛОГИКА =====

if (!$channelId) {
    header('Location: /profile/dialogs.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM channels WHERE id = ?");
$stmt->execute([$channelId]);
$channel = $stmt->fetch();

if (!$channel) {
    header('Location: /profile/dialogs.php');
    exit;
}

// Получаем данные о подписке PLUS
$stmt = $pdo->prepare("SELECT subscription, is_plus FROM users WHERE id = ?");
$stmt->execute([$userId]);
$userData = $stmt->fetch();
$hasPlus = ($userData['subscription'] === 'plus' || !empty($userData['is_plus']));

// Проверяем права в канале
$stmt = $pdo->prepare("SELECT role FROM channel_subscribers WHERE channel_id = ? AND user_id = ?");
$stmt->execute([$channelId, $userId]);
$subscriber = $stmt->fetch();

$isSubscribed = $subscriber !== false;
$isOwner = ($subscriber && $subscriber['role'] === 'owner');
$isAdmin = ($subscriber && ($subscriber['role'] === 'owner' || $subscriber['role'] === 'admin'));

$canPost = ($isOwner || $isAdmin);
$canDelete = ($isOwner || $isAdmin);
$canManageChannel = $isOwner;
$canSchedule = $hasPlus;

$writeAsChannel = $channel['write_as_channel'] ?? 1;

// Подписка
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['subscribe'])) {
    if (!$isSubscribed) {
        $stmt = $pdo->prepare("INSERT INTO channel_subscribers (channel_id, user_id, role, joined_at) VALUES (?, ?, 'member', NOW())");
        $stmt->execute([$channelId, $userId]);
        header('Location: /profile/channel.php?id=' . $channelId);
        exit;
    }
}

// Отписка
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unsubscribe'])) {
    if ($isSubscribed && !$isOwner && !$isAdmin) {
        $stmt = $pdo->prepare("DELETE FROM channel_subscribers WHERE channel_id = ? AND user_id = ?");
        $stmt->execute([$channelId, $userId]);
        header('Location: /profile/dialogs.php');
        exit;
    }
}

// Отправка сообщения
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    if (!$canPost) {
        $_SESSION['error'] = 'Вы не можете отправлять сообщения в этот канал';
        header('Location: /profile/channel.php?id=' . $channelId);
        exit;
    }
    
    $content = trim($_POST['message'] ?? '');
    $attachment = null;
    $fileType = null;
    
    if (isset($_FILES['file_attachment']) && $_FILES['file_attachment']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/../uploads/channel_files/';
        if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);
        
        $file = $_FILES['file_attachment'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $fileName = time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $filePath = 'uploads/channel_files/' . $fileName;
        
        $allowedTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'mp4', 'webm', 'mov', 'avi', 'ts', 'mp3', 'wav', 'ogg', 'm4a', 'pdf', 'doc', 'docx'];
        $maxSize = 50 * 1024 * 1024;
        
        if (in_array($ext, $allowedTypes) && $file['size'] <= $maxSize && move_uploaded_file($file['tmp_name'], $uploadDir . $fileName)) {
            $attachment = $filePath;
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'])) {
                $fileType = 'image';
            } elseif (in_array($ext, ['mp4', 'webm', 'mov', 'avi', 'ts'])) {
                $fileType = 'video';
            } elseif (in_array($ext, ['mp3', 'wav', 'ogg', 'm4a'])) {
                $fileType = 'audio';
            } else {
                $fileType = 'file';
            }
        }
    }
    
    if ($content !== '' || $attachment !== null) {
        $senderId = ($writeAsChannel && ($isOwner || $isAdmin)) ? null : $userId;
        
        $stmt = $pdo->prepare("INSERT INTO channel_messages (channel_id, user_id, content, file_path, file_type, views, likes, created_at) VALUES (?, ?, ?, ?, ?, 0, 0, NOW())");
        $stmt->execute([$channelId, $senderId, $content, $attachment, $fileType]);
    }
    
    header('Location: /profile/channel.php?id=' . $channelId);
    exit;
}

// Удаление сообщения
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_message'])) {
    $messageId = (int)$_POST['message_id'];
    if ($canDelete) {
        $stmt = $pdo->prepare("DELETE FROM channel_messages WHERE id = ?");
        $stmt->execute([$messageId]);
    } else {
        $stmt = $pdo->prepare("DELETE FROM channel_messages WHERE id = ? AND user_id = ?");
        $stmt->execute([$messageId, $userId]);
    }
    header('Location: /profile/channel.php?id=' . $channelId);
    exit;
}

// Отложенная отправка
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['schedule_message'])) {
    if (!$hasPlus) {
        $_SESSION['error'] = 'Функция отложенной отправки доступна только с подпиской PLUS';
        header('Location: /profile/channel.php?id=' . $channelId);
        exit;
    }
    
    $message = trim($_POST['message'] ?? '');
    $scheduledDate = $_POST['scheduled_date'] ?? '';
    $scheduledTime = $_POST['scheduled_time'] ?? '';
    $attachment = null;
    $fileType = null;
    
    $localDateTime = $scheduledDate . ' ' . $scheduledTime . ':00';
    $scheduledAt = (new DateTime($localDateTime, new DateTimeZone('Europe/Moscow')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    
    if (isset($_FILES['schedule_file']) && $_FILES['schedule_file']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/../uploads/channel_files/';
        if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);
        
        $file = $_FILES['schedule_file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $fileName = time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $filePath = 'uploads/channel_files/' . $fileName;
        
        $allowedTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'mp4', 'webm', 'mov', 'avi', 'ts', 'mp3', 'wav', 'ogg', 'm4a'];
        $maxSize = 50 * 1024 * 1024;
        
        if (in_array($ext, $allowedTypes) && $file['size'] <= $maxSize && move_uploaded_file($file['tmp_name'], $uploadDir . $fileName)) {
            $attachment = $filePath;
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'])) {
                $fileType = 'image';
            } elseif (in_array($ext, ['mp4', 'webm', 'mov', 'avi', 'ts'])) {
                $fileType = 'video';
            } elseif (in_array($ext, ['mp3', 'wav', 'ogg', 'm4a'])) {
                $fileType = 'audio';
            } else {
                $fileType = 'file';
            }
        }
    }
    
    if ($message !== '' || $attachment !== null) {
        $stmt = $pdo->prepare("INSERT INTO scheduled_channel_messages (user_id, channel_id, message, attachment, file_type, scheduled_at, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
        $stmt->execute([$userId, $channelId, $message, $attachment, $fileType, $scheduledAt]);
        $_SESSION['success'] = 'Сообщение запланировано';
    }
    header('Location: /profile/channel.php?id=' . $channelId);
    exit;
}

// Получение отложенных
$scheduledMessages = [];
if ($hasPlus) {
    $stmt = $pdo->prepare("
        SELECT *, CONVERT_TZ(scheduled_at, '+00:00', '+03:00') as local_scheduled_at
        FROM scheduled_channel_messages 
        WHERE user_id = ? AND channel_id = ? AND status = 'pending' AND scheduled_at > UTC_TIMESTAMP()
        ORDER BY scheduled_at ASC
    ");
    $stmt->execute([$userId, $channelId]);
    $scheduledMessages = $stmt->fetchAll();
}

// Удаление отложенного
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_scheduled'])) {
    $scheduledId = (int)$_POST['scheduled_id'];
    $stmt = $pdo->prepare("DELETE FROM scheduled_channel_messages WHERE id = ? AND user_id = ?");
    $stmt->execute([$scheduledId, $userId]);
    $_SESSION['success'] = 'Отложенное сообщение удалено';
    header('Location: /profile/channel.php?id=' . $channelId);
    exit;
}

// Отправить отложенное сейчас
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_scheduled_now'])) {
    $scheduledId = (int)$_POST['scheduled_id'];
    
    $stmt = $pdo->prepare("SELECT * FROM scheduled_channel_messages WHERE id = ? AND user_id = ? AND status = 'pending'");
    $stmt->execute([$scheduledId, $userId]);
    $msg = $stmt->fetch();
    
    if ($msg) {
        $stmt2 = $pdo->prepare("INSERT INTO channel_messages (channel_id, user_id, content, file_path, file_type, views, likes, created_at) VALUES (?, ?, ?, ?, ?, 0, 0, NOW())");
        $stmt2->execute([$channelId, $userId, $msg['message'], $msg['attachment'], $msg['file_type']]);
        $stmt2 = $pdo->prepare("DELETE FROM scheduled_channel_messages WHERE id = ?");
        $stmt2->execute([$scheduledId]);
        $_SESSION['success'] = 'Сообщение отправлено';
    }
    header('Location: /profile/channel.php?id=' . $channelId);
    exit;
}

// Получаем сообщения
$stmt = $pdo->prepare("
    SELECT cm.*, u.name, u.uid,
           CASE WHEN cm.user_id IS NULL THEN 'channel' ELSE 'user' END as sender_type
    FROM channel_messages cm
    LEFT JOIN users u ON u.id = cm.user_id
    WHERE cm.channel_id = ?
    ORDER BY cm.created_at ASC
");
$stmt->execute([$channelId]);
$messages = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM channel_subscribers WHERE channel_id = ?");
$stmt->execute([$channelId]);
$subscribersCount = $stmt->fetchColumn();

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

function formatFileLink($filePath, $fileType) {
    if (!$filePath) return '';
    
    if (empty($fileType) || $fileType === 'file') {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'])) {
            $fileType = 'image';
        } elseif (in_array($ext, ['mp4', 'webm', 'mov', 'avi', 'ts'])) {
            $fileType = 'video';
        } elseif (in_array($ext, ['mp3', 'wav', 'ogg', 'm4a'])) {
            $fileType = 'audio';
        }
    }
    
    if ($fileType === 'image') {
        return '<div class="message-file"><img src="/' . $filePath . '" class="channel-image" data-full="/' . $filePath . '" style="max-width:100%; max-height:300px; border-radius:12px; cursor:pointer;"></div>';
    } elseif ($fileType === 'video') {
        return '<div class="message-file"><video src="/' . $filePath . '" controls style="width:100%; border-radius:12px;"></video></div>';
    } elseif ($fileType === 'audio') {
        return '<div class="message-file"><audio src="/' . $filePath . '" controls style="width:100%; border-radius:12px;"></audio></div>';
    } else {
        return '<div class="message-file"><a href="/' . $filePath . '" download class="file-link" style="color:#60a5fa;">Файл: ' . basename($filePath) . '</a></div>';
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=yes">
    <title><?= htmlspecialchars($channel['name']) ?> — Лист</title>
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
            gap: 12px;
            flex-shrink: 0;
        }
        .back-btn { color: #3b82f6; text-decoration: none; font-size: 24px; }
        .channel-avatar {
            width: 44px;
            height: 44px;
            border-radius: 22px;
            object-fit: cover;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            font-weight: 600;
            color: white;
        }
        .channel-info { flex: 1; }
        .channel-name { font-size: 17px; font-weight: 600; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .channel-status { font-size: 12px; color: #94a3b8; }
        .official-badge {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
        }
        .subscribe-btn {
            padding: 6px 16px;
            background: #3b82f6;
            border: none;
            border-radius: 20px;
            color: white;
            cursor: pointer;
            font-size: 13px;
        }
        .unsubscribe-btn {
            padding: 6px 16px;
            background: #ef4444;
            border: none;
            border-radius: 20px;
            color: white;
            cursor: pointer;
            font-size: 13px;
        }
        .settings-btn {
            padding: 6px 16px;
            background: #1e293b;
            border: none;
            border-radius: 20px;
            color: white;
            text-decoration: none;
            font-size: 13px;
        }
        
        .messages-area {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 20px;
            align-items: center;
        }
        .messages-area::-webkit-scrollbar { width: 0; background: transparent; }
        .message {
            display: flex;
            flex-direction: column;
            width: 100%;
            max-width: 600px;
            position: relative;
            background: rgba(30, 41, 59, 0.5);
            border-radius: 20px;
            border: 1px solid rgba(255,255,255,0.05);
        }
        .message-bubble {
            padding: 16px 20px;
            word-wrap: break-word;
            font-size: 15px;
        }
        .message-file {
            margin-bottom: 0;
        }
        .message-text {
            margin-top: 12px;
        }
        .message-time {
            font-size: 11px;
            color: #64748b;
            margin-top: 8px;
            padding: 0 16px 8px 16px;
            border-top: 1px solid rgba(255,255,255,0.05);
        }
        
        .message-stats {
            display: flex;
            gap: 16px;
            padding: 0 16px 8px 16px;
            font-size: 12px;
            color: #64748b;
        }
        .stat-item {
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        .message-actions {
            display: flex;
            gap: 16px;
            padding: 0 16px 16px 16px;
        }
        .message-action-btn {
            background: transparent;
            border: none;
            color: #94a3b8;
            font-size: 13px;
            cursor: pointer;
            padding: 6px 12px;
            border-radius: 20px;
            transition: 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .message-action-btn:hover {
            background: rgba(255,255,255,0.1);
            color: #fff;
        }
        .message-action-btn.liked {
            color: #ef4444;
        }
        
        .delete-message {
            position: absolute;
            top: 8px;
            right: 8px;
            width: 28px;
            height: 28px;
            border-radius: 14px;
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
            max-width: 500px;
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
        .modal-buttons { display: flex; gap: 12px; margin-top: 20px; }
        .modal-buttons button { flex: 1; padding: 10px; border-radius: 40px; cursor: pointer; border: none; font-weight: 500; }
        .btn-secondary { background: #334155; color: #fff; }
        .btn-primary { background: #3b82f6; color: #fff; }
        .btn-danger { background: #dc2626; color: #fff; }
        
        .scheduled-list {
            background: rgba(30, 41, 59, 0.5);
            border-radius: 12px;
            padding: 12px;
            margin-bottom: 12px;
        }
        .scheduled-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }
        .scheduled-item:last-child { border-bottom: none; }
        
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #64748b;
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
        
        /* Модалка репоста */
        .share-modal {
            background: #111827;
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
            border-bottom: 1px solid #1e293b;
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
            background: #1e293b;
        }
        .share-item.selected {
            background: #1e293b;
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
            border: 2px solid #334155;
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
            border-top: 1px solid #1e293b;
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
            background: #1e293b;
            color: #fff;
        }
        .share-copy-btn:hover {
            background: #334155;
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
            color: #64748b;
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
            background: #1e293b;
            color: #fff;
        }
        
        /* Стили для модалки момента */
        .file-dropzone {
            transition: all 0.2s;
        }
        .file-dropzone:hover {
            border-color: #3b82f6;
            background: rgba(59, 130, 246, 0.1);
        }
        .trim-controls {
            background: #0f172a;
            border-radius: 16px;
            padding: 16px;
        }
        #trimSlider {
            -webkit-appearance: none;
            height: 4px;
            background: #334155;
            border-radius: 2px;
            outline: none;
        }
        #trimSlider::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 16px;
            height: 16px;
            border-radius: 8px;
            background: #3b82f6;
            cursor: pointer;
        }
        
        @media (max-width: 768px) {
            .plus-btn { width: 40px; height: 40px; font-size: 20px; }
            .send-btn { padding: 0 16px; }
            .channel-avatar { width: 36px; height: 36px; font-size: 16px; }
            .message-actions { gap: 8px; flex-wrap: wrap; }
            .message { max-width: 100%; }
        }
        
        @media (min-width: 769px) {
            .message { width: 560px; }
        }
    </style>
</head>
<body>

<div class="gradient-bg"></div>

<div id="imageModal" class="image-modal" onclick="closeImageModal()">
    <span class="image-modal-close">&times;</span>
    <img id="modalImage" src="">
</div>

<!-- Модалка репоста -->
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

<!-- Модалка добавления момента -->
<div id="momentModal" class="modal-overlay" style="display: none;">
    <div class="modal" style="max-width: 500px;">
        <h3>Добавить момент</h3>
        <p style="color: #94a3b8; font-size: 13px; margin-bottom: 16px;">Короткое видео до 60 секунд</p>
        
        <div class="form-group">
            <label>Выберите видео</label>
            <div id="momentDropzone" class="file-dropzone" style="border: 2px dashed #334155; border-radius: 16px; padding: 40px; text-align: center; cursor: pointer;">
                <span style="font-size: 48px;">🎬</span>
                <div style="margin-top: 12px;">Нажмите или перетащите видео</div>
                <div style="font-size: 12px; color: #64748b; margin-top: 8px;">MP4, WebM, MOV до 60 сек</div>
            </div>
            <input type="file" id="momentVideoInput" accept="video/mp4,video/webm,video/quicktime" style="display: none;">
        </div>
        
        <div class="form-group">
            <label>Название (опционально)</label>
            <input type="text" id="momentTitle" class="form-input" placeholder="Краткое описание...">
        </div>
        
        <div id="trimSection" style="display: none;">
            <div class="trim-controls">
                <video id="trimVideo" controls style="width: 100%; border-radius: 12px; max-height: 300px;"></video>
                <div style="margin-top: 12px;">
                    <label style="font-size: 13px;">Обрезка (если видео длиннее 60 сек)</label>
                    <input type="range" id="trimSlider" style="width: 100%; margin: 8px 0;" min="0" max="100" value="0" step="0.1">
                    <div style="display: flex; justify-content: space-between; font-size: 12px; color: #94a3b8;">
                        <span>Начало: <span id="startTimeLabel">0:00</span></span>
                        <span>Конец: <span id="endTimeLabel">0:00</span></span>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="modal-buttons" style="margin-top: 20px;">
            <button type="button" class="btn-secondary" onclick="closeMomentModal()">Отмена</button>
            <button type="button" id="uploadMomentBtn" class="btn-primary">Опубликовать</button>
        </div>
    </div>
</div>

<div class="chat-header">
    <a href="/profile/dialogs.php" class="back-btn">←</a>
    <div class="channel-avatar">
        <?php if (!empty($channel['avatar']) && file_exists($_SERVER['DOCUMENT_ROOT'] . $channel['avatar'])): ?>
            <img src="<?= htmlspecialchars($channel['avatar']) ?>" style="width:100%;height:100%;border-radius:22px;object-fit:cover;">
        <?php else: ?>
            <div style="width:100%;height:100%;border-radius:22px;background:linear-gradient(135deg, #3b82f6, #8b5cf6);display:flex;align-items:center;justify-content:center;font-size:20px;">📢</div>
        <?php endif; ?>
    </div>
    <div class="channel-info">
        <div class="channel-name">
            <?= htmlspecialchars($channel['name']) ?>
            <?php if ($channel['is_official']): ?>
                <span class="official-badge">Официальный</span>
            <?php endif; ?>
        </div>
        <div class="channel-status">
            Подписчиков: <?= $subscribersCount ?>
        </div>
    </div>
    <div class="channel-actions">
        <?php if (!$isSubscribed): ?>
            <form method="POST" style="margin:0;">
                <button type="submit" name="subscribe" class="subscribe-btn">Подписаться</button>
            </form>
        <?php elseif ($isSubscribed && !$isOwner && !$isAdmin): ?>
            <form method="POST" style="margin:0;">
                <button type="submit" name="unsubscribe" class="unsubscribe-btn">Отписаться</button>
            </form>
        <?php endif; ?>
        <?php if ($isOwner || $isAdmin): ?>
            <a href="/profile/channel_settings.php?id=<?= $channelId ?>" class="settings-btn">Настройки</a>
        <?php endif; ?>
    </div>
</div>

<div class="messages-area" id="messagesArea">
    <?php if (empty($messages)): ?>
        <div class="empty-state">
            <div style="font-size: 48px; margin-bottom: 12px;">📢</div>
            <p>В этом канале пока нет сообщений</p>
            <?php if ($canPost): ?>
                <p style="font-size: 13px; margin-top: 8px;">Напишите первое сообщение</p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <?php foreach ($messages as $msg): ?>
            <div class="message" data-message-id="<?= $msg['id'] ?>">
                <?php if ($canDelete): ?>
                <form method="post" style="position: absolute; top: 8px; right: 8px; z-index: 10;">
                    <input type="hidden" name="message_id" value="<?= $msg['id'] ?>">
                    <button type="submit" name="delete_message" class="delete-message" onclick="return confirm('Удалить сообщение?')">✕</button>
                </form>
                <?php endif; ?>
                <div class="message-bubble">
                    <div class="message-author-name" style="font-weight: 600; margin-bottom: 8px; color: #3b82f6;">
                        <?php if ($msg['sender_type'] === 'channel'): ?>
                            <?= htmlspecialchars($channel['name']) ?>
                        <?php else: ?>
                            <?= htmlspecialchars($msg['name'] ?? 'Пользователь') ?>
                        <?php endif; ?>
                    </div>
                    
                    <?php 
                    $displayFileType = $msg['file_type'];
                    if ($msg['file_path'] && (empty($displayFileType) || $displayFileType === 'file')) {
                        $ext = strtolower(pathinfo($msg['file_path'], PATHINFO_EXTENSION));
                        if (in_array($ext, ['mp3', 'wav', 'ogg', 'm4a'])) $displayFileType = 'audio';
                        elseif (in_array($ext, ['mp4', 'webm', 'mov', 'avi', 'ts'])) $displayFileType = 'video';
                        elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'])) $displayFileType = 'image';
                    }
                    echo formatFileLink($msg['file_path'], $displayFileType); 
                    ?>
                    
                    <?php if ($msg['content']): ?>
                        <div class="message-text"><?= nl2br(htmlspecialchars($msg['content'])) ?></div>
                    <?php endif; ?>
                </div>
                <div class="message-time">
                    <?= date('H:i', strtotime($msg['created_at'])) ?> <?= timeAgo($msg['created_at']) ?>
                </div>
                <div class="message-stats">
                    <div class="stat-item">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                        <span class="views-count-<?= $msg['id'] ?>"><?= $msg['views'] ?? 0 ?></span>
                    </div>
                    <div class="stat-item">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
                        </svg>
                        <span class="likes-count-<?= $msg['id'] ?>"><?= $msg['likes'] ?? 0 ?></span>
                    </div>
                </div>
                <div class="message-actions">
                    <button class="message-action-btn like-btn" data-message-id="<?= $msg['id'] ?>">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
                        </svg>
                        Нравится
                    </button>
                    <button class="message-action-btn" onclick="openShareModal('<?= 'https://' . $_SERVER['HTTP_HOST'] . '/profile/channel.php?id=' . $channelId ?>', '<?= htmlspecialchars($channel['name']) ?>')">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/>
                            <polyline points="16 6 12 2 8 6"/>
                            <line x1="12" y1="2" x2="12" y2="15"/>
                        </svg>
                        Поделиться
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="input-container">
    <?php if ($error): ?><div class="error-message"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="success-message"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    
    <?php if ($hasPlus && !empty($scheduledMessages)): ?>
    <div class="scheduled-list">
        <div style="color:#fbbf24; font-size:12px; margin-bottom:8px;">Отложенные:</div>
        <?php foreach ($scheduledMessages as $sm): ?>
        <div class="scheduled-item">
            <span style="font-size:12px;"><?= date('d.m H:i', strtotime($sm['local_scheduled_at'])) ?>: <?= htmlspecialchars(mb_substr($sm['message'], 0, 30)) ?></span>
            <div style="display:flex; gap:8px;">
                <form method="post" style="margin:0;">
                    <input type="hidden" name="scheduled_id" value="<?= $sm['id'] ?>">
                    <button type="submit" name="send_scheduled_now" style="background:#22c55e; border:none; color:white; border-radius:20px; padding:2px 8px; font-size:10px; cursor:pointer;">Отправить</button>
                </form>
                <form method="post" style="margin:0;">
                    <input type="hidden" name="scheduled_id" value="<?= $sm['id'] ?>">
                    <button type="submit" name="delete_scheduled" style="background:#ef4444; border:none; color:white; border-radius:20px; padding:2px 8px; font-size:10px; cursor:pointer;" onclick="return confirm('Удалить отложенное сообщение?')">Удалить</button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    
    <?php if ($canPost): ?>
    <form method="post" id="chatForm" enctype="multipart/form-data">
        <div class="preview-area" id="previewArea"></div>
        <div class="input-row">
            <div class="plus-menu">
                <button type="button" class="plus-btn" id="plusBtn">+</button>
                <div class="plus-dropdown" id="plusDropdown">
                    <button type="button" class="dropdown-item" id="attachFileBtn">Файл</button>
                    <button type="button" class="dropdown-item" id="addMomentBtn">Момент</button>
                    <?php if ($hasPlus): ?>
                    <button type="button" class="dropdown-item" id="scheduleBtn">Таймер</button>
                    <?php endif; ?>
                </div>
            </div>
            <textarea class="message-input" id="messageInput" name="message" placeholder="Сообщение в канал..." rows="1"></textarea>
            <button type="submit" name="send_message" class="send-btn">→</button>
        </div>
        <input type="file" name="file_attachment" id="fileInput" class="file-input" accept="*/*">
    </form>
    <?php endif; ?>
</div>

<?php if ($hasPlus): ?>
<div id="scheduleModal" class="modal-overlay" style="display:none;">
    <div class="modal">
        <h3>Запланировать отправку</h3>
        <form method="post" id="scheduleForm" enctype="multipart/form-data">
            <div class="form-group">
                <label>Дата</label>
                <input type="date" name="scheduled_date" class="form-input" required min="<?= date('Y-m-d') ?>">
            </div>
            <div class="form-group">
                <label>Время</label>
                <input type="time" name="scheduled_time" class="form-input" required>
            </div>
            <div class="form-group">
                <label>Сообщение</label>
                <textarea name="message" class="modal-textarea" rows="3" required></textarea>
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
    const addMomentBtn = document.getElementById('addMomentBtn');
    
    let lastMessageId = <?= $lastMessageId ?>;
    let selectedFile = null;
    
    function scrollToBottom() {
        messagesArea.scrollTop = messagesArea.scrollHeight;
    }
    scrollToBottom();
    
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
        });
    }
    
    fileInput.addEventListener('change', (e) => {
        if (e.target.files.length > 0) {
            selectedFile = e.target.files[0];
            previewArea.innerHTML = `<div class="preview-file">
                Файл: ${selectedFile.name} 
                <button type="button" class="remove" onclick="clearFile()">✕</button>
            </div>`;
        }
        plusDropdown.classList.remove('show');
    });
    
    window.clearFile = function() {
        selectedFile = null;
        fileInput.value = '';
        previewArea.innerHTML = '';
    };
    
    <?php if ($hasPlus): ?>
    const scheduleBtn = document.getElementById('scheduleBtn');
    const scheduleModal = document.getElementById('scheduleModal');
    
    if (scheduleBtn) {
        scheduleBtn.addEventListener('click', () => {
            const msg = messageInput.value.trim();
            if (msg) {
                scheduleModal.querySelector('textarea').value = msg;
            }
            scheduleModal.style.display = 'flex';
            plusDropdown.classList.remove('show');
        });
    }
    
    window.closeScheduleModal = function() {
        scheduleModal.style.display = 'none';
    };
    <?php endif; ?>
    
    // ===== ФУНКЦИИ ДЛЯ МОДАЛКИ МОМЕНТА =====
    const momentModal = document.getElementById('momentModal');
    const momentVideoInput = document.getElementById('momentVideoInput');
    const momentDropzone = document.getElementById('momentDropzone');
    const momentTitle = document.getElementById('momentTitle');
    const trimSection = document.getElementById('trimSection');
    const trimVideo = document.getElementById('trimVideo');
    const trimSlider = document.getElementById('trimSlider');
    const startTimeLabel = document.getElementById('startTimeLabel');
    const endTimeLabel = document.getElementById('endTimeLabel');
    const uploadMomentBtn = document.getElementById('uploadMomentBtn');
    
    let selectedVideoFile = null;
    let videoDuration = 0;
    let trimStart = 0;
    let trimEnd = 0;
    
    function openMomentModal() {
        momentModal.style.display = 'flex';
        resetMomentModal();
    }
    
    function closeMomentModal() {
        momentModal.style.display = 'none';
        resetMomentModal();
    }
    
    function resetMomentModal() {
        selectedVideoFile = null;
        momentVideoInput.value = '';
        momentTitle.value = '';
        trimSection.style.display = 'none';
        trimVideo.src = '';
        trimStart = 0;
        trimEnd = 0;
    }
    
    // Кнопка момент
    if (addMomentBtn) {
        addMomentBtn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            plusDropdown.classList.remove('show');
            openMomentModal();
        });
    }
    
    // Drag & drop для загрузки видео
    if (momentDropzone) {
        momentDropzone.addEventListener('click', () => momentVideoInput.click());
        momentDropzone.addEventListener('dragover', (e) => {
            e.preventDefault();
            momentDropzone.style.borderColor = '#3b82f6';
            momentDropzone.style.background = 'rgba(59, 130, 246, 0.1)';
        });
        momentDropzone.addEventListener('dragleave', (e) => {
            e.preventDefault();
            momentDropzone.style.borderColor = '#334155';
            momentDropzone.style.background = 'transparent';
        });
        momentDropzone.addEventListener('drop', (e) => {
            e.preventDefault();
            momentDropzone.style.borderColor = '#334155';
            momentDropzone.style.background = 'transparent';
            const file = e.dataTransfer.files[0];
            if (file && file.type.startsWith('video/')) {
                handleVideoFile(file);
            }
        });
    }
    
    if (momentVideoInput) {
        momentVideoInput.addEventListener('change', (e) => {
            if (e.target.files[0]) handleVideoFile(e.target.files[0]);
        });
    }
    
    function handleVideoFile(file) {
        selectedVideoFile = file;
        const url = URL.createObjectURL(file);
        trimVideo.src = url;
        
        trimVideo.onloadedmetadata = () => {
            videoDuration = trimVideo.duration;
            trimEnd = videoDuration;
            endTimeLabel.textContent = formatTime(trimEnd);
            
            if (videoDuration > 60) {
                trimEnd = 60;
                endTimeLabel.textContent = '1:00';
                trimSection.style.display = 'block';
            }
            updateTrimSlider();
        };
    }
    
    function formatTime(seconds) {
        const mins = Math.floor(seconds / 60);
        const secs = Math.floor(seconds % 60);
        return `${mins}:${secs.toString().padStart(2, '0')}`;
    }
    
    function updateTrimSlider() {
        if (videoDuration <= 0) return;
        const percent = (trimStart / videoDuration) * 100;
        trimSlider.value = percent;
        startTimeLabel.textContent = formatTime(trimStart);
        endTimeLabel.textContent = formatTime(trimEnd);
        trimVideo.currentTime = trimStart;
    }
    
    if (trimSlider) {
        trimSlider.addEventListener('input', (e) => {
            const percent = parseFloat(e.target.value);
            let time = (percent / 100) * videoDuration;
            if (time > trimEnd - 0.5) time = Math.max(0, trimEnd - 0.5);
            trimStart = time;
            startTimeLabel.textContent = formatTime(trimStart);
            trimVideo.currentTime = trimStart;
        });
    }
    
    async function uploadMoment() {
        if (!selectedVideoFile) {
            showToast('Выберите видео');
            return;
        }
        
        const formData = new FormData();
        formData.append('video', selectedVideoFile);
        formData.append('title', momentTitle.value);
        formData.append('channel_id', <?= $channelId ?>);
        formData.append('trim_start', trimStart);
        formData.append('trim_end', trimEnd);
        
        uploadMomentBtn.textContent = 'Загрузка...';
        uploadMomentBtn.disabled = true;
        
        try {
            const response = await fetch('/api/add_moment.php', {
                method: 'POST',
                body: formData
            });
            const data = await response.json();
            
            if (data.success) {
                showToast('Момент добавлен!');
                closeMomentModal();
                location.reload();
            } else {
                showToast('Ошибка: ' + (data.error || 'Не удалось загрузить'));
            }
        } catch (error) {
            console.error('Upload error:', error);
            showToast('Ошибка при загрузке');
        } finally {
            uploadMomentBtn.textContent = 'Опубликовать';
            uploadMomentBtn.disabled = false;
        }
    }
    
    if (uploadMomentBtn) {
        uploadMomentBtn.addEventListener('click', uploadMoment);
    }
    
    // ===== ФУНКЦИИ ДЛЯ РЕПОСТА =====
    let currentShareData = { link: '', name: '' };
    let selectedRecipients = new Map();
    
    window.openShareModal = function(shareLink, channelName) {
        currentShareData = { link: shareLink, name: channelName };
        selectedRecipients.clear();
        
        const modal = document.getElementById('shareModal');
        if (modal) {
            modal.style.display = 'flex';
            loadChatsForShare();
        }
    };
    
    window.closeShareModal = function() {
        const modal = document.getElementById('shareModal');
        if (modal) modal.style.display = 'none';
    };
    
    async function loadChatsForShare() {
        const shareList = document.getElementById('shareList');
        if (!shareList) return;
        shareList.innerHTML = '<div class="share-loading">Загрузка...</div>';
        
        try {
            const response = await fetch(window.location.pathname + '?get_dialogs=1&t=' + Date.now());
            const data = await response.json();
            
            if (data.success) {
                renderShareList(data.personal, data.channels);
            } else {
                shareList.innerHTML = '<div class="share-loading">Ошибка загрузки</div>';
            }
        } catch (error) {
            shareList.innerHTML = '<div class="share-loading">Ошибка загрузки</div>';
        }
    }
    
    function renderShareList(personalChats, channelChats) {
        const shareList = document.getElementById('shareList');
        if (!shareList) return;
        
        let html = '';
        
        if (channelChats && channelChats.length > 0) {
            html += '<div style="padding: 8px 12px; color: #fbbf24; font-size: 12px;">Мои каналы</div>';
            channelChats.forEach(chat => {
                html += `
                    <div class="share-item" data-id="channel_${chat.id}" data-name="${escapeHtml(chat.name)}" onclick="toggleRecipient(this, 'channel_${chat.id}')">
                        <div class="share-item-avatar" style="background: #8b5cf6; display: flex; align-items: center; justify-content: center;">📢</div>
                        <div class="share-item-info">
                            <div class="share-item-name">${escapeHtml(chat.name)}</div>
                            <div class="share-item-desc">канал</div>
                        </div>
                        <div class="share-item-checkbox"></div>
                    </div>
                `;
            });
        }
        
        if (personalChats && personalChats.length > 0) {
            html += '<div style="padding: 8px 12px; color: #3b82f6; font-size: 12px; margin-top: 8px;">Личные чаты</div>';
            personalChats.forEach(chat => {
                html += `
                    <div class="share-item" data-id="${chat.id}" data-name="${escapeHtml(chat.name)}" onclick="toggleRecipient(this, ${chat.id})">
                        <img class="share-item-avatar" src="${chat.avatar || '/pwa_icon/icon-96.png'}" onerror="this.src='/pwa_icon/icon-96.png'">
                        <div class="share-item-info">
                            <div class="share-item-name">${escapeHtml(chat.name)}</div>
                            <div class="share-item-desc">${escapeHtml(chat.uid || '')}</div>
                        </div>
                        <div class="share-item-checkbox"></div>
                    </div>
                `;
            });
        }
        
        if ((!personalChats || personalChats.length === 0) && (!channelChats || channelChats.length === 0)) {
            html = '<div class="share-loading">Нет чатов и каналов</div>';
        }
        
        shareList.innerHTML = html;
    }
    
    window.toggleRecipient = function(element, id) {
        if (selectedRecipients.has(id.toString())) {
            selectedRecipients.delete(id.toString());
            element.classList.remove('selected');
        } else {
            selectedRecipients.set(id.toString(), element.querySelector('.share-item-name')?.textContent || '');
            element.classList.add('selected');
        }
    };
    
    async function copyShareLink() {
        try {
            await navigator.clipboard.writeText(currentShareData.link);
            showToast('Ссылка скопирована');
            closeShareModal();
        } catch (error) {
            prompt('Скопируйте ссылку:', currentShareData.link);
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
                    content_id: <?= $channelId ?>,
                    content_type: 'channel',
                    recipients: recipients,
                    share_link: currentShareData.link
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
            showToast('Ошибка при отправке');
        } finally {
            sendBtn.textContent = 'Отправить';
            sendBtn.disabled = false;
        }
    }
    
    function showToast(message) {
        const toast = document.createElement('div');
        toast.textContent = message;
        toast.style.cssText = `
            position: fixed;
            bottom: 80px;
            left: 50%;
            transform: translateX(-50%);
            background: #1e293b;
            color: white;
            padding: 10px 20px;
            border-radius: 40px;
            font-size: 14px;
            z-index: 3000;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        `;
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 2000);
    }
    
    document.getElementById('copyLinkBtn')?.addEventListener('click', copyShareLink);
    document.getElementById('sendShareBtn')?.addEventListener('click', sendShareToRecipients);
    
    // ===== ОСТАЛЬНЫЕ ФУНКЦИИ =====
    
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
        if (e.target.classList && e.target.classList.contains('channel-image')) {
            openImageModal(e.target.dataset.full || e.target.src);
        }
    });
    
    window.onclick = (e) => {
        if (e.target.classList.contains('modal-overlay')) {
            e.target.style.display = 'none';
        }
        if (e.target.classList.contains('image-modal')) {
            closeImageModal();
        }
    };
    
    async function handleLike(button, messageId) {
        try {
            const formData = new URLSearchParams();
            formData.append('like_message_ajax', '1');
            formData.append('message_id', messageId);
            
            const response = await fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData.toString()
            });
            const data = await response.json();
            
            if (data.success) {
                const likesSpan = document.querySelector(`.likes-count-${messageId}`);
                if (likesSpan) {
                    likesSpan.textContent = data.likes;
                }
                if (data.liked) {
                    button.classList.add('liked');
                } else {
                    button.classList.remove('liked');
                }
            }
        } catch (error) {
            console.error('Like error:', error);
        }
    }
    
    document.querySelectorAll('.like-btn').forEach(btn => {
        const messageId = btn.dataset.messageId;
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            handleLike(btn, messageId);
        });
    });
    
    function loadNewMessages() {
        fetch(window.location.href + '?ajax=1&last_id=' + lastMessageId)
            .then(res => res.json())
            .then(data => {
                if (data.success && data.messages.length) {
                    const wasAtBottom = messagesArea.scrollHeight - messagesArea.scrollTop - messagesArea.clientHeight < 100;
                    data.messages.forEach(msg => {
                        const div = document.createElement('div');
                        div.className = 'message';
                        div.dataset.messageId = msg.id;
                        
                        let deleteBtn = '';
                        if (<?= $canDelete ? 'true' : 'false' ?>) {
                            deleteBtn = `<form method="post" style="position: absolute; top: 8px; right: 8px; z-index: 10;">
                                            <input type="hidden" name="message_id" value="${msg.id}">
                                            <button type="submit" name="delete_message" class="delete-message" onclick="return confirm('Удалить сообщение?')">✕</button>
                                        </form>`;
                        }
                        
                        let fileHtml = '';
                        if (msg.file_path) {
                            const ext = msg.file_path.split('.').pop().toLowerCase();
                            if (['jpg','jpeg','png','gif','webp','avif'].includes(ext)) {
                                fileHtml = `<div class="message-file"><img src="/${msg.file_path}" class="channel-image" data-full="/${msg.file_path}" style="max-width:100%; max-height:300px; border-radius:12px; cursor:pointer;"></div>`;
                            } else if (['mp4','webm','mov','avi','ts'].includes(ext)) {
                                fileHtml = `<div class="message-file"><video src="/${msg.file_path}" controls style="width:100%; border-radius:12px;"></video></div>`;
                            } else if (['mp3','wav','ogg','m4a'].includes(ext)) {
                                fileHtml = `<div class="message-file"><audio src="/${msg.file_path}" controls style="width:100%; border-radius:12px;"></audio></div>`;
                            } else {
                                fileHtml = `<div class="message-file"><a href="/${msg.file_path}" download class="file-link" style="color:#60a5fa;">Файл: ${msg.file_path.split('/').pop()}</a></div>`;
                            }
                        }
                        
                        let textHtml = msg.content ? `<div class="message-text" style="margin-top: 12px;">${escapeHtml(msg.content).replace(/\n/g,'<br>')}</div>` : '';
                        let authorName = (msg.sender_type === 'channel') ? '<?= addslashes($channel['name']) ?>' : escapeHtml(msg.name);
                        let channelLink = '<?= 'https://' . $_SERVER['HTTP_HOST'] . '/profile/channel.php?id=' . $channelId ?>';
                        
                        div.innerHTML = deleteBtn + `<div class="message-bubble">
                            <div class="message-author-name" style="font-weight: 600; margin-bottom: 8px; color: #3b82f6;">
                                ${authorName}
                            </div>
                            ${fileHtml}${textHtml}
                        </div>
                        <div class="message-time">
                            ${new Date(msg.created_at).toLocaleTimeString().slice(0,5)} ${timeAgo(msg.created_at)}
                        </div>
                        <div class="message-stats">
                            <div class="stat-item">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                    <circle cx="12" cy="12" r="3"/>
                                </svg>
                                <span class="views-count-${msg.id}">${msg.views || 0}</span>
                            </div>
                            <div class="stat-item">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
                                </svg>
                                <span class="likes-count-${msg.id}">${msg.likes || 0}</span>
                            </div>
                        </div>
                        <div class="message-actions">
                            <button class="message-action-btn like-btn" data-message-id="${msg.id}">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
                                </svg>
                                Нравится
                            </button>
                            <button class="message-action-btn" onclick="openShareModal('${channelLink}', '${authorName}')">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/>
                                    <polyline points="16 6 12 2 8 6"/>
                                    <line x1="12" y1="2" x2="12" y2="15"/>
                                </svg>
                                Поделиться
                            </button>
                        </div>`;
                        messagesArea.appendChild(div);
                        lastMessageId = msg.id;
                    });
                    if (wasAtBottom) scrollToBottom();
                    
                    document.querySelectorAll('.like-btn').forEach(btn => {
                        if (!btn.hasListener) {
                            btn.hasListener = true;
                            const messageId = btn.dataset.messageId;
                            btn.addEventListener('click', (e) => {
                                e.preventDefault();
                                handleLike(btn, messageId);
                            });
                        }
                    });
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
    
    setInterval(loadNewMessages, 3000);
    
    if (window.navigator.standalone === true || window.matchMedia('(display-mode: standalone)').matches) {
        document.body.style.paddingTop = 'env(safe-area-inset-top)';
    }
</script>

</body>
</html>