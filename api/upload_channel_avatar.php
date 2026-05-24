<?php
session_start();
require __DIR__ . '/../inc/dd_bb.php';

header('Content-Type: application/json');

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$channelId = (int)($_POST['channel_id'] ?? 0);
if (!$channelId) {
    echo json_encode(['success' => false, 'error' => 'Channel ID required']);
    exit;
}

$stmt = $pdo->prepare("SELECT role FROM channel_subscribers WHERE channel_id = ? AND user_id = ?");
$stmt->execute([$channelId, $userId]);
$role = $stmt->fetchColumn();

if ($role !== 'owner') {
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
    $errorCode = $_FILES['avatar']['error'] ?? 'unknown';
    echo json_encode(['success' => false, 'error' => 'Ошибка загрузки файла (код: ' . $errorCode . ')']);
    exit;
}

$allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$fileType = $_FILES['avatar']['type'];

if (!in_array($fileType, $allowed)) {
    echo json_encode(['success' => false, 'error' => 'Разрешены только JPG, PNG, GIF, WEBP']);
    exit;
}

$uploadDir = __DIR__ . '/../uploads/channel_avatars/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

$ext = pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION);
$filename = 'channel_' . $channelId . '_' . time() . '.' . $ext;
$targetPath = $uploadDir . $filename;

if (move_uploaded_file($_FILES['avatar']['tmp_name'], $targetPath)) {
    $avatarPath = '/uploads/channel_avatars/' . $filename;
    
    $stmt = $pdo->prepare("SELECT avatar FROM channels WHERE id = ?");
    $stmt->execute([$channelId]);
    $oldAvatar = $stmt->fetchColumn();
    if ($oldAvatar && file_exists(__DIR__ . '/..' . $oldAvatar)) {
        @unlink(__DIR__ . '/..' . $oldAvatar);
    }
    
    $stmt = $pdo->prepare("UPDATE channels SET avatar = ? WHERE id = ?");
    $stmt->execute([$avatarPath, $channelId]);
    
    echo json_encode(['success' => true, 'avatar' => $avatarPath]);
} else {
    echo json_encode(['success' => false, 'error' => 'Ошибка сохранения файла']);
}