<?php
session_start();
require __DIR__ . '/../inc/dd_bb.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /auth/login.php');
    exit;
}

$userId = $_SESSION['user_id'];
$receiverId = (int)($_POST['receiver_id'] ?? 0);

if (!$receiverId) {
    header('Location: /profile/dialogs.php');
    exit;
}

if (isset($_FILES['file'])) {
    $uploadDir = __DIR__ . '/../uploads/chat/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    $file = $_FILES['file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $fileName = time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $filePath = 'uploads/chat/' . $fileName;
    
    if (move_uploaded_file($file['tmp_name'], $uploadDir . $fileName)) {
        $message = '[Файл: ' . htmlspecialchars($file['name']) . ']';
        $stmt = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, content, attachment, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$userId, $receiverId, $message, $filePath]);
    }
}

header('Location: /profile/chat.php?user=' . $receiverId);
?>