<?php
session_start();
require __DIR__ . '/../inc/dd_bb.php';

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$id = (int)($input['id'] ?? 0);
$type = $input['type'] ?? 'post';

if (!$id) {
    echo json_encode(['error' => 'Invalid ID']);
    exit;
}

// Проверяем права
$canDelete = false;

if ($type === 'post') {
    $stmt = $pdo->prepare("SELECT user_id FROM content_posts WHERE id = ?");
    $stmt->execute([$id]);
    $post = $stmt->fetch();
    if ($post && ($post['user_id'] == $userId || $_SESSION['role'] === 'admin')) {
        $canDelete = true;
        // Удаляем файл
        $stmt = $pdo->prepare("SELECT media_path FROM content_posts WHERE id = ?");
        $stmt->execute([$id]);
        $media = $stmt->fetchColumn();
        if ($media && file_exists(__DIR__ . '/..' . $media)) {
            unlink(__DIR__ . '/..' . $media);
        }
        $stmt = $pdo->prepare("DELETE FROM content_posts WHERE id = ?");
        $stmt->execute([$id]);
    }
} elseif ($type === 'channel') {
    $stmt = $pdo->prepare("
        SELECT cm.user_id, cs.role 
        FROM channel_messages cm
        LEFT JOIN channel_subscribers cs ON cs.channel_id = cm.channel_id AND cs.user_id = ?
        WHERE cm.id = ?
    ");
    $stmt->execute([$userId, $id]);
    $msg = $stmt->fetch();
    if ($msg && ($msg['user_id'] == $userId || $msg['role'] === 'admin' || $msg['role'] === 'owner')) {
        $canDelete = true;
        // Удаляем файл
        $stmt = $pdo->prepare("SELECT file_path FROM channel_messages WHERE id = ?");
        $stmt->execute([$id]);
        $media = $stmt->fetchColumn();
        if ($media && file_exists(__DIR__ . '/..' . $media)) {
            unlink(__DIR__ . '/..' . $media);
        }
        $stmt = $pdo->prepare("DELETE FROM channel_messages WHERE id = ?");
        $stmt->execute([$id]);
    }
}

echo json_encode(['success' => $canDelete]);
?>