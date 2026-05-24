<?php
session_start();
require __DIR__ . '/../inc/dd_bb.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['user_id'];
$channelId = isset($_POST['channel_id']) ? (int)$_POST['channel_id'] : null;
$title = trim($_POST['title'] ?? '');

// Проверяем права на публикацию в канале
if ($channelId) {
    $stmt = $pdo->prepare("SELECT role FROM channel_subscribers WHERE channel_id = ? AND user_id = ? AND role IN ('owner', 'admin')");
    $stmt->execute([$channelId, $userId]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Нет прав для публикации в этом канале']);
        exit;
    }
}

if (!isset($_FILES['video']) || $_FILES['video']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'Файл не загружен']);
    exit;
}

$uploadDir = __DIR__ . '/../uploads/moments/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

$ext = strtolower(pathinfo($_FILES['video']['name'], PATHINFO_EXTENSION));
$allowedExt = ['mp4', 'webm', 'mov'];

if (!in_array($ext, $allowedExt)) {
    echo json_encode(['success' => false, 'error' => 'Неподдерживаемый формат. Используйте MP4, WebM, MOV']);
    exit;
}

$filename = time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
$targetPath = $uploadDir . $filename;
$videoPath = '/uploads/moments/' . $filename;

if (move_uploaded_file($_FILES['video']['tmp_name'], $targetPath)) {
    // Получаем длительность видео
    $duration = 0;
    if (function_exists('shell_exec')) {
        $dur = shell_exec("ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($targetPath) . " 2>/dev/null");
        $duration = (int)round((float)$dur);
    }
    
    // Обрезка видео если нужно
    $trimStart = isset($_POST['trim_start']) ? (float)$_POST['trim_start'] : 0;
    $trimEnd = isset($_POST['trim_end']) ? (float)$_POST['trim_end'] : $duration;
    
    if ($trimStart > 0 || $trimEnd < $duration) {
        $trimmedFile = $uploadDir . 'trimmed_' . $filename;
        shell_exec("ffmpeg -i " . escapeshellarg($targetPath) . " -ss $trimStart -to $trimEnd -c copy " . escapeshellarg($trimmedFile) . " 2>/dev/null");
        if (file_exists($trimmedFile)) {
            unlink($targetPath);
            rename($trimmedFile, $targetPath);
            $duration = $trimEnd - $trimStart;
        }
    }
    
    if ($duration > 60) {
        unlink($targetPath);
        echo json_encode(['success' => false, 'error' => 'Видео не должно быть длиннее 60 секунд']);
        exit;
    }
    
    $stmt = $pdo->prepare("INSERT INTO moments (user_id, channel_id, video_path, title, duration, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
    $stmt->execute([$userId, $channelId, $videoPath, $title, $duration]);
    
    echo json_encode(['success' => true, 'message' => 'Момент добавлен']);
} else {
    echo json_encode(['success' => false, 'error' => 'Ошибка сохранения файла']);
}