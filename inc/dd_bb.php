<?php
declare(strict_types=1);

// Подключение файла шифрования
require_once __DIR__ . '/encryption.php';

$DB_HOST = 'localhost';
$DB_NAME = 'ipgito04_list_t';
$DB_USER = 'ipgito04_list_t';
$DB_PASS = 'ENevU4oyZZd*';

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        $options
    );
} catch (PDOException $e) {
    http_response_code(500);
    exit('Database connection error.');
}

// Функция для безопасного обновления IP (админы не логируются)
function updateUserIp($pdo, $userId, $ip) {
    // Проверяем, не админ ли пользователь
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $role = $stmt->fetchColumn();
    
    // Админов не логируем
    if ($role === 'admin') {
        return;
    }
    
    // Обновляем last_ip
    $stmt = $pdo->prepare("UPDATE users SET last_ip = ? WHERE id = ?");
    $stmt->execute([$ip, $userId]);
    
    // Если main_ip пустой, устанавливаем его
    $stmt = $pdo->prepare("SELECT main_ip FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $mainIp = $stmt->fetchColumn();
    
    if (empty($mainIp)) {
        $stmt = $pdo->prepare("UPDATE users SET main_ip = ? WHERE id = ?");
        $stmt->execute([$ip, $userId]);
    }
}