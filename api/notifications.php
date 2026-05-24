<?php
session_start();
require __DIR__ . '/../inc/dd_bb.php';

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Пометить одно уведомление как прочитанное
if (isset($_GET['mark_read'])) {
    $id = (int)$_GET['mark_read'];
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $userId]);
    echo json_encode(['success' => true]);
    exit;
}

// Пометить все как прочитанные
if (isset($_GET['mark_all_read'])) {
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    $stmt->execute([$userId]);
    echo json_encode(['success' => true]);
    exit;
}

// Получить уведомления
$stmt = $pdo->prepare("
    SELECT * FROM notifications 
    WHERE user_id = ? 
    ORDER BY created_at DESC 
    LIMIT 50
");
$stmt->execute([$userId]);
$notifications = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt->execute([$userId]);
$unreadCount = $stmt->fetchColumn();

foreach ($notifications as &$n) {
    $diff = time() - strtotime($n['created_at']);
    if ($diff < 60) $n['time_ago'] = 'только что';
    elseif ($diff < 3600) $n['time_ago'] = floor($diff / 60) . ' мин назад';
    elseif ($diff < 86400) $n['time_ago'] = floor($diff / 3600) . ' ч назад';
    else $n['time_ago'] = floor($diff / 86400) . ' дн назад';
}

header('Content-Type: application/json');
echo json_encode([
    'unread_count' => $unreadCount,
    'notifications' => $notifications
]);