<?php
declare(strict_types=1);

require __DIR__ . '/../inc/dd_bb.php';
require __DIR__ . '/../inc/auth.php';
require __DIR__ . '/../inc/csrf.php';

require_auth();
$userId = current_user_id();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /profile/privacy.php');
    exit;
}

$token = (string)($_POST['csrf_token'] ?? '');
if (!csrf_check($token)) {
    header('Location: /profile/privacy.php');
    exit;
}

$blockedId = (int)($_POST['blocked_user_id'] ?? 0);

if ($blockedId > 0) {
    $pdo->prepare("DELETE FROM blocked_users WHERE user_id=? AND blocked_user_id=?")
        ->execute([$userId, $blockedId]);
}

header('Location: /profile/privacy.php');
exit;