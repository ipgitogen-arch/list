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

$stmt = $pdo->prepare("SELECT id FROM channels WHERE share_link = ?");
$stmt->execute([$link]);
$channel = $stmt->fetch();

if (!$channel) {
    header('Location: /profile/dialogs.php');
    exit;
}

// Проверяем, не подписан ли уже
$stmt = $pdo->prepare("SELECT id FROM channel_subscribers WHERE channel_id = ? AND user_id = ?");
$stmt->execute([$channel['id'], $userId]);
if (!$stmt->fetch()) {
    $stmt = $pdo->prepare("INSERT INTO channel_subscribers (channel_id, user_id, role, joined_at) VALUES (?, ?, 'member', NOW())");
    $stmt->execute([$channel['id'], $userId]);
}

header('Location: /profile/channel.php?id=' . $channel['id']);
exit;