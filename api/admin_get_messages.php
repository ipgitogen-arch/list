<?php
session_start();
require __DIR__ . '/../inc/dd_bb.php';
require __DIR__ . '/../inc/encryption.php';

$adminId = $_SESSION['user_id'] ?? null;
if (!$adminId) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
$stmt->execute([$adminId]);
$role = $stmt->fetchColumn();

if ($role !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Permission denied']);
    exit;
}

$sessionToken = $_SESSION['admin_crypto_token'] ?? null;
if (!$sessionToken) {
    echo json_encode(['error' => 'no_key', 'message' => 'Требуется ввод ключа шифрования']);
    exit;
}

$stmt = $pdo->prepare("SELECT master_key FROM admin_crypto_sessions WHERE session_token = ? AND user_id = ? AND expires_at > NOW()");
$stmt->execute([$sessionToken, $adminId]);
$session = $stmt->fetch();

if (!$session) {
    unset($_SESSION['admin_crypto_token']);
    echo json_encode(['error' => 'no_key', 'message' => 'Сессия истекла, введите ключ заново']);
    exit;
}

$adminKey = $session['master_key'];
$targetUserId = (int)($_GET['user_id'] ?? 0);
if (!$targetUserId) {
    echo json_encode(['error' => 'Invalid user']);
    exit;
}

// Получаем сообщения с правильными полями
$stmt = $pdo->prepare("
    SELECT m.id, m.sender_id, m.receiver_id, m.content, m.encrypted_for_admin, m.created_at,
           u1.name as sender_name, u1.uid as sender_uid,
           u2.name as receiver_name, u2.uid as receiver_uid
    FROM messages m
    JOIN users u1 ON u1.id = m.sender_id
    JOIN users u2 ON u2.id = m.receiver_id
    WHERE m.sender_id = ? OR m.receiver_id = ?
    ORDER BY m.created_at ASC
");
$stmt->execute([$targetUserId, $targetUserId]);
$messages = $stmt->fetchAll();

// Расшифровываем сообщения
foreach ($messages as &$msg) {
    // Пробуем расшифровать через encrypted_for_admin (если есть)
    if (!empty($msg['encrypted_for_admin'])) {
        $decrypted = decrypt_message($msg['encrypted_for_admin'], $adminKey);
        $msg['decrypted_content'] = $decrypted !== false ? $decrypted : '[Зашифровано]';
    } 
    // Если нет encrypted_for_admin, но есть обычный content
    elseif (!empty($msg['content'])) {
        $msg['decrypted_content'] = $msg['content'];
    } 
    else {
        $msg['decrypted_content'] = '[Пустое сообщение]';
    }
    
    $msg['time_ago'] = timeAgo($msg['created_at']);
}

echo json_encode(['success' => true, 'messages' => $messages]);

function timeAgo($timestamp) {
    if (!$timestamp) return '';
    $diff = time() - strtotime($timestamp);
    if ($diff < 60) return 'только что';
    if ($diff < 3600) return floor($diff / 60) . ' мин назад';
    if ($diff < 86400) return floor($diff / 3600) . ' ч назад';
    return floor($diff / 86400) . ' дн назад';
}