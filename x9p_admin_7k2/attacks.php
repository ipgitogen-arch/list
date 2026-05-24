<?php
session_start();
require __DIR__ . '/../inc/dd_bb.php';
require __DIR__ . '/../inc/attack_detector.php';

// Проверка прав администратора
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: /auth/login.php');
    exit;
}

$action = $_GET['action'] ?? '';
$attackId = (int)($_GET['id'] ?? 0);

// Блокировка IP
if ($action === 'block_ip' && $attackId) {
    $stmt = $pdo->prepare("SELECT ip FROM attack_logs WHERE id = ?");
    $stmt->execute([$attackId]);
    $ip = $stmt->fetchColumn();
    
    if ($ip) {
        // Добавляем в .htaccess
        $htaccessPath = __DIR__ . '/../.htaccess';
        $blockRule = "\n# Блокировка IP: " . $ip . " - " . date('Y-m-d H:i:s') . "\n";
        $blockRule .= "Require ip not " . $ip . "\n";
        file_put_contents($htaccessPath, $blockRule, FILE_APPEND);
        
        // Отмечаем в БД
        $stmt = $pdo->prepare("UPDATE attack_logs SET blocked = 1 WHERE ip = ?");
        $stmt->execute([$ip]);
        
        $_SESSION['message'] = "IP {$ip} заблокирован";
    }
    header('Location: /admin/attacks.php');
    exit;
}

// Очистка логов
if ($action === 'clear_old') {
    $stmt = $pdo->prepare("DELETE FROM attack_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $stmt->execute();
    $_SESSION['message'] = "Старые логи очищены (старше 30 дней)";
    header('Location: /admin/attacks.php');
    exit;
}

// Получаем данные
$attacks = AttackDetector::getActiveAttacks($pdo, 200);
$stats = AttackDetector::getAttackStats($pdo);
$message = $_SESSION['message'] ?? null;
unset($_SESSION['message']);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Мониторинг атак — Админ панель</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0f172a;
            color: #f1f5f9;
            padding: 20px;
        }
        .container { max-width: 1400px; margin: 0 auto; }
        h1 { margin-bottom: 24px; font-size: 24px; }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: #1e293b;
            border-radius: 16px;
            padding: 20px;
            border: 1px solid #334155;
        }
        .stat-number {
            font-size: 32px;
            font-weight: bold;
            color: #3b82f6;
        }
        .stat-label {
            font-size: 14px;
            color: #94a3b8;
            margin-top: 8px;
        }
        .section {
            background: #1e293b;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 24px;
            border: 1px solid #334155;
        }
        .section h2 {
            font-size: 18px;
            margin-bottom: 16px;
            color: #f1f5f9;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            text-align: left;
            padding: 12px;
            border-bottom: 1px solid #334155;
        }
        th {
            color: #94a3b8;
            font-weight: 500;
            font-size: 12px;
        }
        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
        }
        .badge-danger { background: #ef4444; color: #fff; }
        .badge-warning { background: #f59e0b; color: #000; }
        .badge-info { background: #3b82f6; color: #fff; }
        .btn {
            padding: 6px 12px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-size: 12px;
            transition: 0.2s;
        }
        .btn-danger { background: #ef4444; color: #fff; }
        .btn-danger:hover { background: #dc2626; }
        .btn-secondary { background: #334155; color: #fff; }
        .btn-secondary:hover { background: #475569; }
        .ip {
            font-family: monospace;
            background: #0f172a;
            padding: 2px 6px;
            border-radius: 4px;
        }
        .message {
            background: #22c55e;
            color: #fff;
            padding: 12px;
            border-radius: 12px;
            margin-bottom: 20px;
        }
        .refresh-btn {
            background: #3b82f6;
            color: #fff;
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            display: inline-block;
            margin-bottom: 20px;
        }
        @media (max-width: 768px) {
            table { display: block; overflow-x: auto; }
            th, td { padding: 8px; font-size: 12px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🛡️ Мониторинг злоумышленников</h1>
        
        <a href="/admin/" class="refresh-btn">← Назад в админку</a>
        <a href="?clear_old=1" class="btn btn-secondary" style="margin-left: 10px;" onclick="return confirm('Очистить логи старше 30 дней?')">🗑️ Очистить старые логи</a>
        <a href="?refresh=1" class="btn btn-secondary" style="margin-left: 10px;">🔄 Обновить</a>
        
        <?php if ($message): ?>
            <div class="message"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?= number_format($stats['total'] ?? 0) ?></div>
                <div class="stat-label">Всего атак</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= number_format($stats['unique_ips'] ?? 0) ?></div>
                <div class="stat-label">Уникальных IP</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= number_format($stats['last_24h'] ?? 0) ?></div>
                <div class="stat-label">За последние 24 часа</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= date('H:i') ?></div>
                <div class="stat-label">Текущее время</div>
            </div>
        </div>
        
        <?php if (!empty($stats['top_countries'])): ?>
        <div class="section">
            <h2>🌍 Топ стран атакующих</h2>
            <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                <?php foreach ($stats['top_countries'] as $c): ?>
                    <div class="badge badge-info"><?= htmlspecialchars($c['country']) ?>: <?= $c['count'] ?></div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($stats['top_attacks'])): ?>
        <div class="section">
            <h2>🎯 Типы атак</h2>
            <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                <?php foreach ($stats['top_attacks'] as $a): ?>
                    <div class="badge badge-warning"><?= htmlspecialchars($a['attack_type']) ?>: <?= $a['count'] ?></div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="section">
            <h2>📋 Журнал атак (последние 200)</h2>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>IP</th>
                            <th>Страна/Город</th>
                            <th>Тип атаки</th>
                            <th>Запрошенный файл</th>
                            <th>User-Agent</th>
                            <th>Время</th>
                            <th>Действие</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($attacks)): ?>
                            <tr><td colspan="7" style="text-align: center;">Нет записей</td></tr>
                        <?php else: ?>
                            <?php foreach ($attacks as $attack): ?>
                                <tr>
                                    <td><code class="ip"><?= htmlspecialchars($attack['ip']) ?></code></td>
                                    <td>
                                        <?php if ($attack['country']): ?>
                                            <?= htmlspecialchars($attack['country']) ?>
                                            <?php if ($attack['city']): ?>/<?= htmlspecialchars($attack['city']) ?><?php endif; ?>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge badge-danger"><?= htmlspecialchars($attack['attack_type'] ?? 'Неизвестно') ?></span>
                                    </td>
                                    <td>
                                        <?php if ($attack['file_attempted']): ?>
                                            <code><?= htmlspecialchars($attack['file_attempted']) ?></code>
                                        <?php else: ?>
                                            <?= htmlspecialchars(mb_substr($attack['request_uri'] ?? '', 0, 50)) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td style="max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                        <?= htmlspecialchars(mb_substr($attack['user_agent'] ?? '', 0, 50)) ?>
                                    </td>
                                    <td><?= date('d.m.Y H:i:s', strtotime($attack['created_at'])) ?></td>
                                    <td>
                                        <?php if (!$attack['blocked']): ?>
                                            <a href="?action=block_ip&id=<?= $attack['id'] ?>" class="btn btn-danger" onclick="return confirm('Заблокировать этот IP?')">🚫 Блок</a>
                                        <?php else: ?>
                                            <span class="badge">🔒 Заблокирован</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>