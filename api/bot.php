<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', 1);

require __DIR__ . '/../config.php';
require __DIR__ . '/../inc/dd_bb.php';
require __DIR__ . '/../inc/auth.php';

header('Content-Type: application/json');

$userId = current_user_id();
if (!$userId) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Проверяем PLUS подписку
$stmt = $pdo->prepare("SELECT subscription, is_plus FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

$hasPlus = ($user['subscription'] === 'plus' || !empty($user['is_plus']));
if (!$hasPlus) {
    http_response_code(403);
    echo json_encode(['error' => 'PLUS subscription required']);
    exit;
}

// Проверка лимита запросов (не более 10 в минуту)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM bot_chat_history WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)");
$stmt->execute([$userId]);
$count = $stmt->fetchColumn();
if ($count > 10) {
    echo json_encode(['reply' => 'Слишком много запросов. Подождите минуту.']);
    exit;
}

// Очистка истории
if ($_SERVER['REQUEST_METHOD'] === 'DELETE' && isset($_GET['action']) && $_GET['action'] === 'clear') {
    $stmt = $pdo->prepare("DELETE FROM bot_chat_history WHERE user_id = ?");
    $stmt->execute([$userId]);
    echo json_encode(['success' => true]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$message = trim($input['message'] ?? '');

if (empty($message)) {
    echo json_encode(['reply' => 'Напишите сообщение']);
    exit;
}

function cleanText($text) {
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/\n\s*\n/', "\n\n", $text);
    return trim($text);
}

// Сохраняем сообщение пользователя
$cleanMessage = cleanText($message);
$stmt = $pdo->prepare("INSERT INTO bot_chat_history (user_id, role, content, created_at) VALUES (?, 'user', ?, NOW())");
$stmt->execute([$userId, $cleanMessage]);

// Получаем последние 10 сообщений
$stmt = $pdo->prepare("SELECT role, content FROM bot_chat_history WHERE user_id = ? ORDER BY id DESC LIMIT 10");
$stmt->execute([$userId]);
$history = $stmt->fetchAll();
$history = array_reverse($history);

try {
    $reply = callOpenRouter($message, $history);
} catch (Exception $e) {
    $reply = 'Извините, сервис временно недоступен. Попробуйте позже.';
}

$cleanReply = cleanText($reply);

$stmt = $pdo->prepare("INSERT INTO bot_chat_history (user_id, role, content, created_at) VALUES (?, 'assistant', ?, NOW())");
$stmt->execute([$userId, $cleanReply]);

echo json_encode(['reply' => $cleanReply]);

function callOpenRouter($message, $history) {
    // Ключ должен быть в config.php
    $apiKey = defined('OPENROUTER_API_KEY') ? OPENROUTER_API_KEY : '';
    
    if (empty($apiKey)) {
        throw new Exception('API key not configured');
    }
    
    $messages = [];
    
    $messages[] = [
        'role' => 'system',
        'content' => 'Ты ИИ-помощник. Отвечай понятно и структурированно. Используй обычный текст, без Markdown.'
    ];
    
    foreach ($history as $msg) {
        $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
    }
    $messages[] = ['role' => 'user', 'content' => $message];
    
    $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
        'HTTP-Referer: https://мойлист.рф',
        'X-Title: Лист Бот'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'model' => 'google/gemini-2.0-flash-lite-001',
        'messages' => $messages,
        'temperature' => 0.7,
        'max_tokens' => 2500
    ]));
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        throw new Exception('API error: ' . $httpCode);
    }
    
    $data = json_decode($response, true);
    $reply = $data['choices'][0]['message']['content'] ?? 'Не удалось получить ответ';
    
    $reply = preg_replace('/```html|```css|```javascript|```/', '', $reply);
    $reply = preg_replace('/\*\*([^*]+)\*\*/', '$1', $reply);
    $reply = preg_replace('/\*([^*]+)\*/', '$1', $reply);
    
    return trim($reply);
}