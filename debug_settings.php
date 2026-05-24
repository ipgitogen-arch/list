<?php
session_start();
require __DIR__ . '/inc/dd_bb.php';

// Проверяем права (только для админа)
$userId = $_SESSION['user_id'] ?? 0;
$stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user || $user['role'] !== 'admin') {
    die('Доступ только для админа');
}

// Получаем значение
$stmt = $pdo->prepare("SELECT registration_enabled FROM settings LIMIT 1");
$stmt->execute();
$regEnabled = $stmt->fetchColumn();

echo "<h1>Отладка настроек</h1>";
echo "<p>registration_enabled = <strong>" . ($regEnabled === false ? 'NULL (нет записи)' : $regEnabled) . "</strong></p>";

// Если нет записи, создаём
if ($regEnabled === false) {
    $pdo->prepare("INSERT INTO settings (registration_enabled) VALUES (1)")->execute();
    echo "<p style='color:green'>✅ Запись создана (значение 1)</p>";
} elseif ($regEnabled == 0) {
    echo "<p style='color:red'>⚠️ Регистрация ЗАКРЫТА. Нажмите кнопку чтобы открыть.</p>";
    echo '<form method="post"><button type="submit" name="open" style="padding:10px 20px; background:#3b82f6; color:white; border:none; border-radius:8px; cursor:pointer;">Открыть регистрацию</button></form>';
    
    if (isset($_POST['open'])) {
        $pdo->prepare("UPDATE settings SET registration_enabled = 1")->execute();
        echo "<p style='color:green'>✅ Регистрация открыта! <a href='/auth/register.php'>Проверить</a></p>";
    }
} else {
    echo "<p style='color:green'>✅ Регистрация ОТКРЫТА</p>";
}

// Показываем все настройки
$stmt = $pdo->query("SELECT * FROM settings");
$settings = $stmt->fetchAll();
echo "<h2>Все настройки:</h2>";
echo "<pre>";
print_r($settings);
echo "</pre>";
?>