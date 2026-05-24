<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: /auth/login.php');
    exit;
}

require __DIR__ . '/../inc/dd_bb.php';

$userId = $_SESSION['user_id'];

// Получаем текущую подписку
$stmt = $pdo->prepare("SELECT subscription, is_plus FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();
$hasPlus = ($user['subscription'] === 'plus' || $user['is_plus'] == 1);

if ($hasPlus) {
    header('Location: /profile/profile.php');
    exit;
}

// Цены
$plans = [
    1 => ['months' => 1, 'price' => 259, 'label' => '1 месяц'],
    3 => ['months' => 3, 'price' => 749, 'label' => '3 месяца'],
    6 => ['months' => 6, 'price' => 1449, 'label' => '6 месяцев']
];

$selectedMonths = isset($_POST['months']) ? (int)$_POST['months'] : 1;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($plans[$selectedMonths])) {
    // Здесь будет интеграция с платежной системой
    // Пока просто имитация успешной оплаты
    
    $months = $selectedMonths;
    $expiresAt = date('Y-m-d H:i:s', strtotime("+$months months"));
    
    $stmt = $pdo->prepare("UPDATE users SET subscription = 'plus', subscription_expires = ?, is_plus = 1 WHERE id = ?");
    $stmt->execute([$expiresAt, $userId]);
    
    header('Location: /profile/profile.php?plus_activated=1');
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <!-- PWA и иконки -->
    <link rel="manifest" href="/pwa_icon/site.webmanifest">
    <link rel="apple-touch-icon" href="/pwa_icon/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="96x96" href="/pwa_icon/favicon-96x96.png">
    <link rel="icon" type="image/svg+xml" href="/pwa_icon/favicon.svg">
    <link rel="shortcut icon" href="/pwa_icon/favicon.ico">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#0f172a">
    <title>Оформление PLUS — Лист</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background: linear-gradient(135deg, #0f172a, #0a0f1f);
            color: #fff;
            min-height: 100vh;
            padding: 16px;
            /* Отступ для iPhone с Dynamic Island */
            padding-top: max(16px, env(safe-area-inset-top));
        }
        .container {
            max-width: 500px;
            margin: 0 auto;
            background: #111827;
            border-radius: 24px;
            padding: 24px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4);
        }
        h1 {
            font-size: 28px;
            margin-bottom: 8px;
            text-align: center;
            background: linear-gradient(135deg, #f59e0b, #d97706);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        .subtitle {
            text-align: center;
            color: #94a3b8;
            margin-bottom: 24px;
            font-size: 14px;
        }
        .plans {
            display: flex;
            flex-direction: column;
            gap: 16px;
            margin-bottom: 24px;
        }
        .plan {
            background: #1e293b;
            border-radius: 20px;
            padding: 20px;
            cursor: pointer;
            transition: 0.2s;
            border: 2px solid transparent;
        }
        .plan.selected {
            border-color: #f59e0b;
            background: #2d3a4e;
        }
        .plan-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }
        .plan-name {
            font-size: 20px;
            font-weight: 700;
        }
        .plan-price {
            font-size: 24px;
            font-weight: 800;
            color: #f59e0b;
        }
        .plan-price small {
            font-size: 14px;
            font-weight: 400;
            color: #94a3b8;
        }
        .plan-desc {
            color: #94a3b8;
            font-size: 14px;
        }
        button {
            width: 100%;
            padding: 16px;
            border: none;
            border-radius: 16px;
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: #fff;
            font-size: 18px;
            font-weight: 700;
            cursor: pointer;
            transition: 0.2s;
        }
        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(245, 158, 11, 0.3);
        }
        .back-link {
            display: block;
            text-align: center;
            margin-top: 20px;
            color: #94a3b8;
            text-decoration: none;
            font-size: 14px;
        }
        .error {
            background: #7f1a1a;
            padding: 12px;
            border-radius: 12px;
            margin-bottom: 20px;
            text-align: center;
        }
        
        /* Дополнительные отступы для PWA на iPhone */
        @supports (padding-top: env(safe-area-inset-top)) {
            body {
                padding-top: calc(16px + env(safe-area-inset-top));
                padding-bottom: env(safe-area-inset-bottom);
            }
        }
        
        /* Для PWA в режиме standalone */
        @media (display-mode: standalone), (display-mode: fullscreen) {
            body {
                padding-top: max(20px, env(safe-area-inset-top));
            }
            .container {
                margin-top: 10px;
            }
        }
        
        @media (max-width: 480px) {
            body {
                padding: 12px;
                padding-top: max(12px, env(safe-area-inset-top));
            }
            .container {
                padding: 18px;
            }
            h1 {
                font-size: 24px;
            }
            .plan {
                padding: 16px;
            }
            .plan-price {
                font-size: 20px;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <h1>⭐ PLUS подписка</h1>
    <div class="subtitle">Расширенные возможности и ИИ-бот</div>

    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post">
        <div class="plans">
            <?php foreach ($plans as $months => $plan): ?>
                <div class="plan <?= $selectedMonths == $months ? 'selected' : '' ?>" onclick="selectPlan(<?= $months ?>)">
                    <div class="plan-header">
                        <span class="plan-name"><?= $plan['label'] ?></span>
                        <span class="plan-price"><?= $plan['price'] ?> <small>₽</small></span>
                    </div>
                    <div class="plan-desc">
                        <?php if ($months == 1): ?>
                            ✨ Доступ к ИИ-боту<br>🎨 Расширенные функции
                        <?php elseif ($months == 3): ?>
                            💰 Экономия 28₽<br>✨ ИИ-бот + все бонусы
                        <?php else: ?>
                            🎁 Максимальная выгода<br>✨ ИИ-бот + приоритетная поддержка
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <input type="hidden" name="months" id="monthsInput" value="<?= $selectedMonths ?>">
        <button type="submit">Оформить PLUS</button>
    </form>

    <a href="/profile/profile.php" class="back-link">← Вернуться в профиль</a>
</div>

<script>
    function selectPlan(months) {
        document.getElementById('monthsInput').value = months;
        document.querySelectorAll('.plan').forEach((plan, index) => {
            if (index === (months === 1 ? 0 : months === 3 ? 1 : 2)) {
                plan.classList.add('selected');
            } else {
                plan.classList.remove('selected');
            }
        });
    }
</script>
</body>
</html>