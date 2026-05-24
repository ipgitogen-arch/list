<?php
session_start();
require __DIR__ . '/../inc/dd_bb.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /auth/login.php');
    exit;
}

$userId = $_SESSION['user_id'];

// Получаем ID плейлиста
$playlistId = (int)($_GET['id'] ?? 0);
if (!$playlistId) {
    header('Location: /profile/playlist.php');
    exit;
}

// Проверяем, что плейлист принадлежит пользователю
$stmt = $pdo->prepare("SELECT * FROM playlists WHERE id = ? AND user_id = ?");
$stmt->execute([$playlistId, $userId]);
$playlist = $stmt->fetch();

if (!$playlist) {
    header('Location: /profile/playlist.php');
    exit;
}

// Получаем публикации пользователя и друзей для добавления
$stmt = $pdo->prepare("
    SELECT p.*, u.name, u.uid, pr.avatar
    FROM publications p
    JOIN users u ON u.id = p.user_id
    LEFT JOIN profiles pr ON pr.user_id = u.id
    WHERE p.user_id = ? 
        OR p.user_id IN (SELECT friend_id FROM friends WHERE user_id = ? AND status = 'accepted')
    ORDER BY p.created_at DESC
");
$stmt->execute([$userId, $userId]);
$publications = $stmt->fetchAll();

// Получаем уже добавленные публикации
$stmt = $pdo->prepare("SELECT publication_id FROM playlist_items WHERE playlist_id = ?");
$stmt->execute([$playlistId]);
$existingItems = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Обработка добавления
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_publication'])) {
    $publicationId = (int)($_POST['publication_id'] ?? 0);
    
    // Проверяем, не добавлена ли уже
    if (!in_array($publicationId, $existingItems)) {
        $stmt = $pdo->prepare("INSERT INTO playlist_items (playlist_id, publication_id, added_at) VALUES (?, ?, NOW())");
        $stmt->execute([$playlistId, $publicationId]);
        $success = 'Публикация добавлена в плейлист';
        // Обновляем список
        $existingItems[] = $publicationId;
    } else {
        $error = 'Эта публикация уже есть в плейлисте';
    }
}

// Обработка удаления
if (isset($_GET['remove'])) {
    $removeId = (int)$_GET['remove'];
    $stmt = $pdo->prepare("DELETE FROM playlist_items WHERE playlist_id = ? AND publication_id = ?");
    $stmt->execute([$playlistId, $removeId]);
    header("Location: /profile/edit_playlist.php?id=$playlistId");
    exit;
}

// Получаем текущие публикации в плейлисте
$stmt = $pdo->prepare("
    SELECT pi.*, p.content, p.media_path, p.media_type, p.created_at,
           u.name, u.uid, pr.avatar
    FROM playlist_items pi
    JOIN publications p ON p.id = pi.publication_id
    JOIN users u ON u.id = p.user_id
    LEFT JOIN profiles pr ON pr.user_id = u.id
    WHERE pi.playlist_id = ?
    ORDER BY pi.added_at DESC
");
$stmt->execute([$playlistId]);
$playlistItems = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Редактировать плейлист — Лист</title>
    <link rel="manifest" href="/manifest.json">
    <link rel="apple-touch-icon" href="/pwa_icon/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="96x96" href="/pwa_icon/favicon-96x96.png">
    <link rel="icon" type="image/svg+xml" href="/pwa_icon/favicon.svg">
    <link rel="shortcut icon" href="/pwa_icon/favicon.ico">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#0f172a">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background: #0f172a;
            color: #fff;
            min-height: 100vh;
        }
        .header {
            background: rgba(17, 24, 39, 0.95);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255,255,255,0.06);
            padding: 12px 20px;
            padding-top: max(12px, env(safe-area-inset-top));
            display: flex;
            align-items: center;
            gap: 16px;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .back-btn {
            background: #1e293b;
            border: none;
            width: 36px;
            height: 36px;
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: #fff;
            font-size: 20px;
            text-decoration: none;
        }
        .header h1 {
            font-size: 20px;
            font-weight: 600;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        .section {
            background: #111827;
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid rgba(255,255,255,0.06);
        }
        .section h2 {
            font-size: 18px;
            margin-bottom: 16px;
        }
        .publication-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .publication-item {
            background: #1e293b;
            border-radius: 16px;
            padding: 12px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .publication-avatar {
            width: 40px;
            height: 40px;
            border-radius: 20px;
            object-fit: cover;
        }
        .publication-content {
            flex: 1;
        }
        .publication-text {
            font-size: 13px;
            color: #94a3b8;
            margin-bottom: 4px;
        }
        .publication-meta {
            font-size: 11px;
            color: #64748b;
        }
        .add-btn, .remove-btn {
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            border: none;
            transition: 0.2s;
        }
        .add-btn {
            background: #3b82f6;
            color: #fff;
        }
        .add-btn.added {
            background: #22c55e;
            cursor: default;
        }
        .remove-btn {
            background: #ef4444;
            color: #fff;
        }
        .success, .error {
            padding: 12px;
            border-radius: 12px;
            margin-bottom: 16px;
            text-align: center;
            font-size: 13px;
        }
        .success {
            background: rgba(34, 197, 94, 0.15);
            color: #4ade80;
        }
        .error {
            background: rgba(239, 68, 68, 0.15);
            color: #f87171;
        }
        .empty {
            text-align: center;
            color: #64748b;
            padding: 40px;
        }
        @media (max-width: 600px) {
            .container {
                padding: 16px;
            }
            .publication-item {
                flex-wrap: wrap;
            }
            .add-btn, .remove-btn {
                width: 100%;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <a href="/profile/playlist.php" class="back-btn">←</a>
        <h1>Редактировать: <?= htmlspecialchars($playlist['name']) ?></h1>
    </div>
    
    <div class="container">
        <?php if (isset($success)): ?>
            <div class="success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if (isset($error)): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <!-- Текущие публикации в плейлисте -->
        <div class="section">
            <h2>📋 В плейлисте (<?= count($playlistItems) ?>)</h2>
            <?php if (count($playlistItems) > 0): ?>
                <div class="publication-list">
                    <?php foreach ($playlistItems as $item): ?>
                        <div class="publication-item">
                            <img class="publication-avatar" src="<?= htmlspecialchars($item['avatar'] ?? '/pwa_icon/icon-96.png') ?>" onerror="this.src='/pwa_icon/icon-96.png'">
                            <div class="publication-content">
                                <div class="publication-text"><?= htmlspecialchars(mb_substr($item['content'] ?? '', 0, 100)) ?></div>
                                <div class="publication-meta"><?= htmlspecialchars($item['name']) ?> • <?= date('d.m.Y H:i', strtotime($item['created_at'])) ?></div>
                            </div>
                            <a href="?id=<?= $playlistId ?>&remove=<?= $item['publication_id'] ?>" class="remove-btn" onclick="return confirm('Удалить из плейлиста?')">Удалить</a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty">Плейлист пуст. Добавьте публикации ниже.</div>
            <?php endif; ?>
        </div>
        
        <!-- Доступные публикации для добавления -->
        <div class="section">
            <h2>➕ Добавить публикацию</h2>
            <?php if (count($publications) > 0): ?>
                <div class="publication-list">
                    <?php foreach ($publications as $pub): ?>
                        <div class="publication-item">
                            <img class="publication-avatar" src="<?= htmlspecialchars($pub['avatar'] ?? '/pwa_icon/icon-96.png') ?>" onerror="this.src='/pwa_icon/icon-96.png'">
                            <div class="publication-content">
                                <div class="publication-text"><?= htmlspecialchars(mb_substr($pub['content'] ?? '', 0, 100)) ?></div>
                                <div class="publication-meta"><?= htmlspecialchars($pub['name']) ?> • <?= date('d.m.Y H:i', strtotime($pub['created_at'])) ?></div>
                            </div>
                            <?php if (in_array($pub['id'], $existingItems)): ?>
                                <button class="add-btn added" disabled>✓ Добавлено</button>
                            <?php else: ?>
                                <form method="POST" style="margin:0;">
                                    <input type="hidden" name="publication_id" value="<?= $pub['id'] ?>">
                                    <button type="submit" name="add_publication" class="add-btn">+ Добавить</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty">Нет доступных публикаций</div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>