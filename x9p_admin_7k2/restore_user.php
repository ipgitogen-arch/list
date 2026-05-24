<?php
declare(strict_types=1);

require __DIR__ . '/../inc/dd_bb.php';
require __DIR__ . '/../inc/auth.php';

$adminId = current_user_id();
if (!$adminId) exit;

$stmt = $pdo->prepare("SELECT role FROM users WHERE id=? LIMIT 1");
$stmt->execute([$adminId]);
if (($stmt->fetchColumn() ?: 'user') !== 'admin') {
    exit('Нет доступа');
}

$userId = (int)($_POST['user_id'] ?? 0);
if ($userId <= 0) {
    header('Location: /x9p_admin_7k2/users.php');
    exit;
}

$pdo->prepare("
    UPDATE users
    SET is_deleted=0, delete_at=NULL
    WHERE id=?
")->execute([$userId]);

header('Location: /x9p_admin_7k2/users.php');
exit;