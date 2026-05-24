<?php
declare(strict_types=1);

require __DIR__ . '/../inc/dd_bb.php';
require __DIR__ . '/../inc/auth.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    $stmt = $pdo->prepare("
        SELECT id, password_hash, is_deleted, delete_at
        FROM users
        WHERE email = ?
        LIMIT 1
    ");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        $error = 'Неверный email или пароль.';
    } else {
        if ((int)($user['is_deleted'] ?? 0) === 1 && (int)($user['delete_at'] ?? 0) > time()) {
            $_SESSION['deleted_user_id'] = (int)$user['id'];
            header('Location: /auth/deleted.php');
            exit;
        }

        if (!password_verify($password, (string)$user['password_hash'])) {
            $error = 'Неверный email или пароль.';
        } else {
            login_user((int)$user['id']);
            header('Location: /profile/profile.php');
            exit;
        }
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Лист — Вход</title>
    <link rel="stylesheet" href="/auth/style.css">
</head>
<body>
<div class="auth-page">
    <div class="auth-container">
        <div class="auth-header">
            <h1>Вход</h1>
            <p>Введите данные аккаунта</p>
        </div>

        <?php if ($error !== ''): ?>
            <div class="error"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
        <?php endif; ?>

        <form class="auth-form" method="post">
            <input type="email" name="email" placeholder="Email" required>
            <input type="password" name="password" placeholder="Пароль" required>
            <button type="submit">Войти</button>
        </form>

        <div class="auth-links">
            <a href="/auth/register.php">Регистрация</a>
            <a href="/index.php">На главную</a>
        </div>
    </div>
</div>
</body>
</html>