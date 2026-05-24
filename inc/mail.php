<?php
/**
 * Отправка письма через SMTP сервер Beget.
 * @param string $to Email получателя.
 * @param string $subject Тема письма.
 * @param string $message HTML-содержимое письма.
 * @return bool true в случае успеха, false в случае ошибки.
 */
function sendMail($to, $subject, $message) {
    // --- НАСТРОЙКИ (ЗАМЕНИТЕ НА СВОИ!) ---
    $smtp_host = 'smtp.beget.com';
    $smtp_port = 465;
    // ИСПОЛЬЗУЙТЕ ПОЛНЫЙ АДРЕС ВАШЕГО ПОЧТОВОГО ЯЩИКА НА ДОМЕНЕ
    $username = 'list@мойлист.рф';
    $password = 'uiluW%M49qCI';
    $from = 'list@мойлист.рф';
    // ------------------------------------

    $to = filter_var($to, FILTER_SANITIZE_EMAIL);
    $subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $body = "From: $from\r\nTo: $to\r\nSubject: $subject\r\n";
    $body .= "MIME-Version: 1.0\r\nContent-Type: text/html; charset=utf-8\r\n\r\n$message";

    // Подключение к SMTP серверу
    $socket = fsockopen('ssl://' . $smtp_host, $smtp_port, $errno, $errstr, 30);
    if (!$socket) {
        error_log("SMTP connection failed: $errstr ($errno)");
        return false;
    }

    // SMTP-диалог для отправки письма
    $smtp_dialog = [
        "EHLO beget.com\r\n",
        "AUTH LOGIN\r\n",
        base64_encode($username) . "\r\n",
        base64_encode($password) . "\r\n",
        "MAIL FROM: <$from>\r\n",
        "RCPT TO: <$to>\r\n",
        "DATA\r\n",
        "$body\r\n.\r\n",
        "QUIT\r\n"
    ];

    foreach ($smtp_dialog as $command) {
        fputs($socket, $command);
        fgets($socket, 515); // Читаем ответ сервера, чтобы не зависнуть
    }

    fclose($socket);
    return true;
}