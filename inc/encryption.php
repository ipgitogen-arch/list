<?php
if (!function_exists('generate_encryption_key')) {

    // Генерация ключа шифрования для пользователя
    function generate_encryption_key() {
        return bin2hex(openssl_random_pseudo_bytes(32));
    }

    // Шифрование сообщения для пользователя
    function encrypt_message($message, $user_key) {
        $key = hex2bin($user_key);
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-gcm'));
        $tag = '';
        $encrypted = openssl_encrypt($message, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return base64_encode($iv . $tag . $encrypted);
    }

    // Шифрование сообщения для администратора
    function encrypt_for_admin($message, $admin_key) {
        $key = hex2bin($admin_key);
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-gcm'));
        $tag = '';
        $encrypted = openssl_encrypt($message, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return base64_encode($iv . $tag . $encrypted);
    }

    // Дешифрование сообщения
    function decrypt_message($encrypted_message, $user_key) {
        $key = hex2bin($user_key);
        $data = base64_decode($encrypted_message);
        $iv_length = openssl_cipher_iv_length('aes-256-gcm');
        $iv = substr($data, 0, $iv_length);
        $tag = substr($data, $iv_length, 16);
        $encrypted = substr($data, $iv_length + 16);
        return openssl_decrypt($encrypted, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    }

    // Получение или создание ключа пользователя
    function get_user_encryption_key($pdo, $user_id) {
        $stmt = $pdo->prepare("SELECT encryption_key FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $key = $stmt->fetchColumn();
        
        if (!$key) {
            $key = generate_encryption_key();
            $stmt = $pdo->prepare("UPDATE users SET encryption_key = ? WHERE id = ?");
            $stmt->execute([$key, $user_id]);
        }
        
        return $key;
    }
    
    // Получение ключа администратора
    function get_admin_encryption_key($pdo) {
        $stmt = $pdo->prepare("SELECT encryption_key FROM users WHERE role = 'admin' LIMIT 1");
        $stmt->execute();
        $admin = $stmt->fetch();
        return $admin ? $admin['encryption_key'] : null;
    }
}