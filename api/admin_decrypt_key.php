<?php
session_start();
require __DIR__ . '/../inc/dd_bb.php';
require __DIR__ . '/../inc/encryption.php';

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Проверяем, что пользователь админ
$stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
$stmt->execute([$userId]);
$role = $stmt->fetchColumn();

if ($role !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Permission denied']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$masterKey = trim($input['master_key'] ?? '');

if (empty($masterKey)) {
    echo json_encode(['error' => 'Введите ключ шифрования']);
    exit;
}

// Проверяем мастер-ключ
$stmt = $pdo->prepare("SELECT encryption_key FROM users WHERE id = ?");
$stmt->execute([$userId]);
$storedKey = $stmt->fetchColumn();

if ($masterKey !== $storedKey) {
    echo json_encode(['error' => 'Неверный ключ шифрования']);
    exit;
}

// Генерируем токен сессии
$sessionToken = bin2hex(random_bytes(32));
$expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

// Сохраняем сессию
$stmt = $pdo->prepare("INSERT INTO admin_crypto_sessions (user_id, session_token, master_key, created_at, expires_at) VALUES (?, ?, ?, NOW(), ?)");
$stmt->execute([$userId, $sessionToken, $masterKey, $expiresAt]);

$_SESSION['admin_crypto_token'] = $sessionToken;

echo json_encode(['success' => true, 'token' => $sessionToken]);