<?php
session_start();
require __DIR__ . '/../inc/dd_bb.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /auth/login.php');
    exit;
}

$userId = $_SESSION['user_id'];

// Получаем каналы пользователя (где он владелец или админ)
$stmt = $pdo->prepare("
    SELECT c.id, c.name 
    FROM channels c
    JOIN channel_subscribers cs ON cs.channel_id = c.id
    WHERE cs.user_id = ? AND cs.role IN ('owner', 'admin')
");
$stmt->execute([$userId]);
$channels = $stmt->fetchAll();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $channelId = (int)($_POST['channel_id'] ?? 0);
    
    if (empty($title)) {
        $error = 'Введите название стрима';
    } else {
        $streamKey = bin2hex(random_bytes(16));
        $stmt = $pdo->prepare("INSERT INTO streams (user_id, channel_id, title, stream_key, status, started_at) VALUES (?, ?, ?, ?, 'live', NOW())");
        $stmt->execute([$userId, $channelId ?: null, $title, $streamKey]);
        $streamId = $pdo->lastInsertId();
        header('Location: /stream.php?id=' . $streamId);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Начать стрим — Лист</title>
    <link rel="manifest" href="/manifest.json">
    <link rel="stylesheet" href="/assets/css/pwa-fix.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background: #0f172a;
            color: #f1f5f9;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            max-width: 400px;
            width: 100%;
            background: #111827;
            border-radius: 24px;
            padding: 32px;
            border: 1px solid rgba(255,255,255,0.06);
        }
        h1 {
            font-size: 24px;
            margin-bottom: 8px;
            text-align: center;
        }
        .subtitle {
            text-align: center;
            color: #94a3b8;
            margin-bottom: 24px;
            font-size: 14px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            font-size: 13px;
            color: #94a3b8;
            margin-bottom: 6px;
        }
        input, select {
            width: 100%;
            padding: 12px;
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 12px;
            color: #fff;
            font-size: 14px;
            outline: none;
        }
        input:focus, select:focus {
            border-color: #3b82f6;
        }
        button {
            width: 100%;
            padding: 12px;
            background: #ef4444;
            border: none;
            border-radius: 12px;
            color: #fff;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: 0.2s;
        }
        button:hover {
            background: #dc2626;
        }
        .error {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid #ef4444;
            padding: 10px;
            border-radius: 12px;
            margin-bottom: 20px;
            text-align: center;
            font-size: 13px;
        }
        .back-link {
            display: block;
            text-align: center;
            margin-top: 20px;
            color: #94a3b8;
            text-decoration: none;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Начать стрим</h1>
        <div class="subtitle">Поделитесь моментом в реальном времени</div>
        
        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label>Название стрима</label>
                <input type="text" name="title" placeholder="Например: Утреннее кофе" required>
            </div>
            
            <?php if (count($channels) > 0): ?>
            <div class="form-group">
                <label>Привязать к каналу (необязательно)</label>
                <select name="channel_id">
                    <option value="0">-- Без канала --</option>
                    <?php foreach ($channels as $channel): ?>
                        <option value="<?= $channel['id'] ?>"><?= htmlspecialchars($channel['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            
            <button type="submit">Начать трансляцию</button>
        </form>
        
        <a href="/profile/profile.php" class="back-link">← Вернуться в профиль</a>
    </div>
</body>
</html>