<?php
session_start();
require_once 'config.php';

if (($_SESSION['role'] ?? '') !== 'admin') {
    die('Нет доступа');
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) die('Неверный ID');

$stmt = $pdo->prepare("UPDATE users SET is_deleted = 0 WHERE id = ?");
$stmt->execute([$id]);

header("Location: admin_user_view.php?id=" . $id);
exit;