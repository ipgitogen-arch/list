<?php
session_start();
require __DIR__ . '/../inc/dd_bb.php';

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    header('Location: /auth/login.php');
    exit;
}

$link = $_GET['link'] ?? '';
if (!$link) {
    header('Location: /profile/dialogs.php');
    exit;
}

$stmt = $pdo->prepare("SELECT id FROM group_chats WHERE share_link = ?");
$stmt->execute([$link]);
$chat = $stmt->fetch();

if (!$chat) {
    header('Location: /profile/dialogs.php');
    exit;
}

$stmt = $pdo->prepare("SELECT id FROM group_chat_members WHERE chat_id = ? AND user_id = ?");
$stmt->execute([$chat['id'], $userId]);
if (!$stmt->fetch()) {
    $stmt = $pdo->prepare("INSERT INTO group_chat_members (chat_id, user_id, role, joined_at) VALUES (?, ?, 'member', NOW())");
    $stmt->execute([$chat['id'], $userId]);
}

header('Location: /profile/group_chat.php?id=' . $chat['id']);
exit;