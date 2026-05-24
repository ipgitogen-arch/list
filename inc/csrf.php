<?php
declare(strict_types=1);

function csrf_token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        throw new RuntimeException('Session is not started.');
    }

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_check(string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}