<?php
session_start();
require __DIR__ . '/../inc/dd_bb.php';

$userId = $_SESSION['user_id'] ?? 0;

echo "<h2>Диагностика прав администратора</h2>";
echo "Ваш ID в сессии: " . htmlspecialchars($userId) . "<br><br>";

if ($userId) {
    // Проверяем структуру таблицы users
    echo "<h3>1. Структура таблицы users:</h3>";
    $stmt = $pdo->query("DESCRIBE users");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Колонки: " . implode(', ', $columns) . "<br><br>";
    
    // Проверяем наличие полей role и is_admin
    $hasRole = in_array('role', $columns);
    $hasIsAdmin = in_array('is_admin', $columns);
    
    echo "Поле 'role': " . ($hasRole ? '✅ ЕСТЬ' : '❌ НЕТ') . "<br>";
    echo "Поле 'is_admin': " . ($hasIsAdmin ? '✅ ЕСТЬ' : '❌ НЕТ') . "<br><br>";
    
    // Получаем данные пользователя
    echo "<h3>2. Данные пользователя ID={$userId}:</h3>";
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        echo "<pre>";
        print_r($user);
        echo "</pre>";
        
        if ($hasRole) {
            echo "role = '" . ($user['role'] ?? 'NULL') . "'<br>";
            echo "Статус админа по role: " . (($user['role'] ?? '') === 'admin' ? '✅ ДА' : '❌ НЕТ') . "<br>";
        }
        
        if ($hasIsAdmin) {
            echo "is_admin = '" . ($user['is_admin'] ?? 'NULL') . "'<br>";
            echo "Статус админа по is_admin: " . (($user['is_admin'] ?? 0) == 1 ? '✅ ДА' : '❌ НЕТ') . "<br>";
        }
    } else {
        echo "<span style='color:red'>❌ Пользователь с ID {$userId} не найден в таблице users!</span><br>";
    }
} else {
    echo "❌ Вы не авторизованы!";
}
?>