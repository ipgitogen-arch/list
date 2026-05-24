<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function current_user_id(): ?int
{
    return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

function login_user(int $userId): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
}

function logout_user(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'] ?? '/',
            $params['domain'] ?? '',
            (bool)($params['secure'] ?? false),
            (bool)($params['httponly'] ?? true)
        );
    }

    session_destroy();
}

function require_auth(): void
{
    if (!current_user_id()) {
        header('Location: /auth/login.php');
        exit;
    }
}

function current_user_role(PDO $pdo): string
{
    $userId = current_user_id();
    if (!$userId) return 'guest';

    $stmt = $pdo->prepare("SELECT role FROM users WHERE id=? LIMIT 1");
    $stmt->execute([$userId]);
    $role = $stmt->fetchColumn();

    return $role ?: 'user';
}

function require_admin(PDO $pdo): void
{
    require_auth();
    if (current_user_role($pdo) !== 'admin') {
        http_response_code(403);
        exit('Доступ запрещён.');
    }
}