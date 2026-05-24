<?php
session_start();
require __DIR__ . '/../inc/dd_bb.php';

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$mediaType = $_POST['type'] ?? 'photo';
$uploadDir = __DIR__ . '/../uploads/stories/';

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

if ($mediaType === 'photo') {
    if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['error' => 'Ошибка загрузки фото']);
        exit;
    }
    
    $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($ext, $allowed)) {
        echo json_encode(['error' => 'Неверный формат фото']);
        exit;
    }
    
    $filename = 'story_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $filepath = $uploadDir . $filename;
    
    if (move_uploaded_file($_FILES['photo']['tmp_name'], $filepath)) {
        $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));
        $stmt = $pdo->prepare("INSERT INTO stories (user_id, media_type, media_path, expires_at) VALUES (?, 'photo', ?, ?)");
        $stmt->execute([$userId, 'uploads/stories/' . $filename, $expiresAt]);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['error' => 'Не удалось сохранить фото']);
    }
}
?>