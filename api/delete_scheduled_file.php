<?php
session_start();
require __DIR__ . '/../inc/dd_bb.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit;
}

$userId = $_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);
$id = (int)($data['id'] ?? 0);

if ($id) {
    $stmt = $pdo->prepare("UPDATE scheduled_messages SET attachment = NULL WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $userId]);
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false]);
}
?>