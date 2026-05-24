<?php
// config.php - Конфигурационный файл мессенджера Лист

// Настройки базы данных
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database_name');
define('DB_USER', 'your_database_user');
define('DB_PASS', 'your_database_password');

// Настройки сайта
define('SITE_URL', 'https://мойлист.рф');
define('SITE_NAME', 'Лист');
define('SITE_DESCRIPTION', 'Современный мессенджер с ИИ-помощником');

// Настройки времени
date_default_timezone_set('Europe/Moscow');

// OpenRouter API ключ для ИИ-бота (PLUS подписка)
// Получить ключ можно на https://openrouter.ai/keys
define('OPENROUTER_API_KEY', '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');

// Настройки почты (для восстановления пароля и уведомлений)
define('SMTP_HOST', 'smtp.beget.com');
define('SMTP_PORT', 465);
define('SMTP_USER', 'list@мойлист.рф');
define('SMTP_PASS', 'your_smtp_password');
define('SMTP_FROM', 'list@мойлист.рф');
define('SMTP_FROM_NAME', 'Лист');

// Настройки безопасности
define('CSRF_TOKEN_KEY', 'list_csrf_secret_key_2024');
define('SESSION_LIFETIME', 7200); // 2 часа

// Настройки загрузки файлов
define('MAX_FILE_SIZE', 10485760); // 10MB
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
define('ALLOWED_VIDEO_TYPES', ['video/mp4', 'video/webm', 'video/quicktime']);

// Режим отладки (включить на время разработки, выключить на проде)
define('DEBUG_MODE', false);

if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// Функция для подключения к БД (альтернативный способ, если не используется dd_bb.php)
function getDBConnection() {
    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]
        );
        return $pdo;
    } catch (PDOException $e) {
        if (DEBUG_MODE) {
            die('Ошибка подключения к БД: ' . $e->getMessage());
        } else {
            die('Ошибка подключения к базе данных');
        }
    }
}