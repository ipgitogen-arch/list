<?php
declare(strict_types=1);

require __DIR__ . '/../inc/dd_bb.php';
require __DIR__ . '/../inc/auth.php';
require __DIR__ . '/../inc/csrf.php';

$userId = current_user_id();
if (!$userId) {
    header('Location: /auth/login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /profile/dialogs.php');
    exit;
}

$token = (string)($_POST['csrf_token'] ?? '');
if (!csrf_check($token)) {
    header('Location: /profile/dialogs.php');
    exit;
}

$receiver = (int)($_POST['receiver_id'] ?? 0);
$text = trim((string)($_POST['content'] ?? ''));

if ($receiver <= 0 || $receiver === $userId) {
    header('Location: /profile/dialogs.php');
    exit;
}

/* receiver exists and not deleted */
$stmt = $pdo->prepare("
    SELECT id, is_deleted
    FROM users
    WHERE id=?
    LIMIT 1
");
$stmt->execute([$receiver]);
$receiverUser = $stmt->fetch();

if (!$receiverUser || (int)$receiverUser['is_deleted'] === 1) {
    header('Location: /profile/dialogs.php');
    exit;
}

/* blocked */
$stmt = $pdo->prepare("
    SELECT 1 FROM blocked_users
    WHERE (user_id=? AND blocked_user_id=?)
       OR (user_id=? AND blocked_user_id=?)
    LIMIT 1
");
$stmt->execute([$userId, $receiver, $receiver, $userId]);
if ($stmt->fetch()) {
    header('Location: /profile/dialogs.php?friend_id=' . $receiver);
    exit;
}

/* MUST be friends */
$stmt = $pdo->prepare("
    SELECT 1
    FROM friends
    WHERE user_id=? AND friend_id=?
    LIMIT 1
");
$stmt->execute([$userId, $receiver]);

if (!$stmt->fetch()) {
    header('Location: /profile/dialogs.php?friend_id=' . $receiver . '&tab=requests');
    exit;
}

$filePath = null;

if (!empty($_FILES['attachment']['name'])) {
    if ((int)($_FILES['attachment']['error'] ?? 1) === UPLOAD_ERR_OK) {
        $tmpName = (string)($_FILES['attachment']['tmp_name'] ?? '');
        $mime = is_file($tmpName) ? mime_content_type($tmpName) : '';

        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
        ];

        if (isset($allowed[$mime]) && @getimagesize($tmpName) !== false) {
            if ((int)($_FILES['attachment']['size'] ?? 0) <= 5 * 1024 * 1024) {
                $uploadDir = __DIR__ . '/message_uploads';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                $ext = $allowed[$mime];
                $name = 'msg_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                $fullPath = $uploadDir . '/' . $name;

                if (move_uploaded_file($tmpName, $fullPath)) {
                    $filePath = '/profile/message_uploads/' . $name;
                }
            }
        }
    }
}

if ($text !== '' || $filePath !== null) {
    $pdo->prepare("
        INSERT INTO messages (sender_id, receiver_id, content, attachment)
        VALUES (?, ?, ?, ?)
    ")->execute([$userId, $receiver, $text, $filePath]);
}

/* ВАЖНО: никаких auto-friends здесь больше нет */

header('Location: /profile/dialogs.php?friend_id=' . $receiver . '&tab=dialogs');
exit;