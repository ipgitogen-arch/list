<?php
session_start();
require_once 'config.php';

if (($_SESSION['role'] ?? '') !== 'admin') {
    die('Нет доступа');
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) die('Пользователь не найден');

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) die('Пользователь не найден');

// Диалоги
$dialogsStmt = $pdo->prepare("SELECT * FROM dialogs WHERE user_id = ? ORDER BY id DESC");
$dialogsStmt->execute([$id]);
$dialogs = $dialogsStmt->fetchAll(PDO::FETCH_ASSOC);

// Сообщения
$messagesStmt = $pdo->prepare("SELECT * FROM messages WHERE user_id = ? ORDER BY id DESC LIMIT 200");
$messagesStmt->execute([$id]);
$messages = $messagesStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<link rel="stylesheet" href="/assets/css/app.css">
    <link rel="stylesheet" href="/assets/css/pwa.css">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<link rel="manifest" href="/manifest.json">
<title>Пользователь #<?= $user['id'] ?></title>
<style>
body { background:#0f172a; color:white; font-family:Arial; margin:0; }
.wrap { max-width:1300px; margin:0 auto; padding:24px; }
.card { background:rgba(255,255,255,.05); border-radius:20px; padding:20px; margin-bottom:20px; }
.grid { display:grid; grid-template-columns:repeat(2,1fr); gap:16px; }
.row { padding:12px 0; border-bottom:1px solid rgba(255,255,255,.08); }
.btn { display:inline-block; padding:12px 16px; border-radius:12px; text-decoration:none; color:white; background:#2563eb; margin-right:10px; }
.btn-danger { background:#dc2626; }
.btn-success { background:#16a34a; }
.btn-gold { background:#f59e0b; color:#111; }
pre { white-space:pre-wrap; word-break:break-word; }
</style>
</head>
<body>
<div class="wrap">
    <a href="admin_users.php" class="btn">← Назад</a>

    <div class="card">
        <h1>Пользователь #<?= $user['id'] ?></h1>
        <div class="grid">
            <div class="row"><strong>Имя:</strong> <?= htmlspecialchars($user['name']) ?></div>
            <div class="row"><strong>Email:</strong> <?= htmlspecialchars($user['email']) ?></div>
            <div class="row"><strong>Роль:</strong> <?= htmlspecialchars($user['role']) ?></div>
            <div class="row"><strong>Подписка:</strong> <?= htmlspecialchars($user['subscription'] ?: 'free') ?></div>
            <div class="row"><strong>IP:</strong> <?= htmlspecialchars($user['last_ip'] ?: '-') ?></div>
            <div class="row"><strong>Статус:</strong> <?= !empty($user['is_deleted']) ? 'Удалён' : 'Активен' ?></div>
            <div class="row"><strong>Дата регистрации:</strong> <?= htmlspecialchars($user['created_at'] ?? '-') ?></div>
        </div>

        <div style="margin-top:20px;">
            <?php if (empty($user['is_deleted'])): ?>
                <a href="admin_user_delete.php?id=<?= $user['id'] ?>" class="btn btn-danger">Удалить</a>
            <?php else: ?>
                <a href="admin_user_restore.php?id=<?= $user['id'] ?>" class="btn btn-success">Восстановить</a>
            <?php endif; ?>

            <a href="admin_user_plus.php?id=<?= $user['id'] ?>" class="btn btn-gold">Выдать PLUS</a>
        </div>
    </div>

    <div class="card">
        <h2>Диалоги пользователя</h2>
        <?php if (!$dialogs): ?>
            <p>Диалогов нет.</p>
        <?php else: ?>
            <?php foreach ($dialogs as $d): ?>
                <div class="row">
                    <strong>#<?= $d['id'] ?></strong> — <?= htmlspecialchars($d['title'] ?? 'Без названия') ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Последние сообщения</h2>
        <?php if (!$messages): ?>
            <p>Сообщений нет.</p>
        <?php else: ?>
            <?php foreach ($messages as $m): ?>
                <div class="row">
                    <strong>#<?= $m['id'] ?></strong><br>
                    <pre><?= htmlspecialchars($m['content'] ?? '') ?></pre>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
</body>
</html>