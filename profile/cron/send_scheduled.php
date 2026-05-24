<?php
require __DIR__ . '/../inc/dd_bb.php';

$stmt = $pdo->prepare("SELECT * FROM scheduled_messages WHERE status = 'pending' AND scheduled_at <= NOW()");
$stmt->execute();
$messages = $stmt->fetchAll();

foreach ($messages as $msg) {
    $stmt2 = $pdo->prepare("INSERT INTO chat_messages (from_user, to_user, message, created_at) VALUES (?, ?, ?, NOW())");
    $stmt2->execute([$msg['user_id'], $msg['receiver_id'], $msg['message']]);
    
    $stmt2 = $pdo->prepare("UPDATE scheduled_messages SET status = 'sent' WHERE id = ?");
    $stmt2->execute([$msg['id']]);
}

echo "Отправлено: " . count($messages);