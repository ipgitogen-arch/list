<?php
session_start();
require __DIR__ . '/inc/dd_bb.php';
require __DIR__ . '/inc/attack_detector.php'; // ДОБАВИТЬ

// Детектор атак - логирует подозрительные запросы
$detector = new AttackDetector($pdo, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'] ?? '');
$detector->detect($_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD'], http_response_code());

$isLoggedIn = isset($_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Лист — Современный мессенджер</title>
    <link rel="manifest" href="/manifest.json">
    <link rel="apple-touch-icon" href="/pwa_icon/apple-touch-icon.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#0f172a">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Display', 'SF Pro Text', 'Helvetica Neue', system-ui, sans-serif;
            background: #0f172a;
            color: #f1f5f9;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }

        .gradient-bg {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: radial-gradient(ellipse at 20% 30%, #1e293b, #0f172a, #020617);
            z-index: -1;
        }

        .nav {
            position: sticky;
            top: 0;
            background: rgba(15, 23, 42, 0.8);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            z-index: 100;
        }

        /* PWA режим — отступ под Dynamic Island */
        body.pwa-mode .nav {
            padding-top: constant(safe-area-inset-top);
            padding-top: env(safe-area-inset-top);
            background: rgba(15, 23, 42, 0.95);
        }

        /* Фикс для iPhone 14 Pro / 15 Pro (Dynamic Island) */
        @media only screen and (device-width: 393px) and (device-height: 852px) and (-webkit-device-pixel-ratio: 3) {
            body.pwa-mode .nav {
                padding-top: 54px;
            }
        }

        /* Фикс для iPhone 15 Pro Max */
        @media only screen and (device-width: 430px) and (device-height: 932px) and (-webkit-device-pixel-ratio: 3) {
            body.pwa-mode .nav {
                padding-top: 54px;
            }
        }

        /* Фикс для iPhone X, XS, 11 Pro */
        @media only screen and (device-width: 375px) and (device-height: 812px) and (-webkit-device-pixel-ratio: 3) {
            body.pwa-mode .nav {
                padding-top: 44px;
            }
        }

        /* Фикс для iPhone 12, 13, 14 (обычные) */
        @media only screen and (device-width: 390px) and (device-height: 844px) and (-webkit-device-pixel-ratio: 3) {
            body.pwa-mode .nav {
                padding-top: 47px;
            }
        }

        /* Фикс для iPhone 12-14 Pro Max */
        @media only screen and (device-width: 428px) and (device-height: 926px) and (-webkit-device-pixel-ratio: 3) {
            body.pwa-mode .nav {
                padding-top: 47px;
            }
        }

        /* Если safe-area не работает — универсальный отступ */
        @supports not (padding-top: env(safe-area-inset-top)) {
            body.pwa-mode .nav {
                padding-top: 47px;
            }
        }

        .nav-container {
            max-width: 1280px;
            margin: 0 auto;
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        /* Компактная шапка для PWA на телефонах */
        @media (max-width: 768px) {
            body.pwa-mode .nav-container {
                padding-top: 8px;
                padding-bottom: 8px;
            }
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }

        .logo-icon svg {
            width: 32px;
            height: 32px;
        }

        .logo-text {
            font-size: 22px;
            font-weight: 600;
            letter-spacing: -0.3px;
            background: linear-gradient(135deg, #ffffff, #94a3b8);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        @media (min-width: 1024px) {
            .logo-icon svg {
                width: 36px;
                height: 36px;
            }
            .logo-text {
                font-size: 26px;
            }
            .nav-container {
                padding: 20px 32px;
            }
        }

        @media (max-width: 768px) {
            body.pwa-mode .logo-icon svg {
                width: 28px;
                height: 28px;
            }
            body.pwa-mode .logo-text {
                font-size: 18px;
            }
        }

        .nav-links {
            display: flex;
            gap: 32px;
            align-items: center;
        }

        @media (max-width: 768px) {
            .nav-links {
                gap: 16px;
            }
        }

        .nav-link {
            color: #cbd5e1;
            text-decoration: none;
            font-size: 15px;
            font-weight: 500;
            transition: all 0.2s ease;
        }

        .nav-link:hover {
            color: #ffffff;
        }

        .btn-primary {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: white;
            padding: 8px 24px;
            border-radius: 40px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(59, 130, 246, 0.2);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(59, 130, 246, 0.3);
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
        }

        .btn-outline {
            background: transparent;
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: white;
            padding: 8px 24px;
            border-radius: 40px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .btn-outline:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: rgba(255, 255, 255, 0.4);
        }

        @media (max-width: 768px) {
            .btn-primary, .btn-outline {
                padding: 6px 16px;
                font-size: 13px;
            }
        }

        .hero {
            max-width: 1280px;
            margin: 0 auto;
            padding: 120px 24px;
            text-align: center;
        }

        /* Отступ для героя в PWA */
        body.pwa-mode .hero {
            padding-top: calc(100px + constant(safe-area-inset-top));
            padding-top: calc(100px + env(safe-area-inset-top));
        }

        @media (max-width: 768px) {
            .hero {
                padding: 80px 20px;
            }
            body.pwa-mode .hero {
                padding-top: calc(60px + constant(safe-area-inset-top));
                padding-top: calc(60px + env(safe-area-inset-top));
            }
        }

        .hero-badge {
            display: inline-block;
            background: rgba(59, 130, 246, 0.15);
            border: 1px solid rgba(59, 130, 246, 0.3);
            border-radius: 100px;
            padding: 6px 16px;
            font-size: 13px;
            font-weight: 500;
            color: #60a5fa;
            margin-bottom: 24px;
            backdrop-filter: blur(4px);
        }

        .hero h1 {
            font-size: 64px;
            font-weight: 700;
            letter-spacing: -1.5px;
            background: linear-gradient(135deg, #ffffff, #94a3b8);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            margin-bottom: 24px;
        }

        @media (max-width: 768px) {
            .hero h1 {
                font-size: 36px;
                letter-spacing: -0.5px;
            }
        }

        .hero p {
            font-size: 20px;
            color: #94a3b8;
            max-width: 600px;
            margin: 0 auto 40px;
            line-height: 1.6;
        }

        @media (max-width: 768px) {
            .hero p {
                font-size: 16px;
            }
        }

        .hero-buttons {
            display: flex;
            gap: 16px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .features {
            max-width: 1280px;
            margin: 0 auto;
            padding: 80px 24px;
        }

        @media (max-width: 768px) {
            .features {
                padding: 50px 20px;
            }
        }

        .section-title {
            text-align: center;
            font-size: 36px;
            font-weight: 600;
            letter-spacing: -0.5px;
            margin-bottom: 16px;
        }

        @media (max-width: 768px) {
            .section-title {
                font-size: 28px;
            }
        }

        .section-subtitle {
            text-align: center;
            color: #94a3b8;
            margin-bottom: 64px;
            font-size: 18px;
        }

        @media (max-width: 768px) {
            .section-subtitle {
                font-size: 15px;
                margin-bottom: 40px;
            }
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 32px;
        }

        @media (max-width: 768px) {
            .features-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }
        }

        .feature-card {
            background: rgba(30, 41, 59, 0.5);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 24px;
            padding: 32px;
            transition: all 0.3s ease;
        }

        .feature-card:hover {
            transform: translateY(-8px);
            background: rgba(30, 41, 59, 0.7);
            border-color: rgba(59, 130, 246, 0.3);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
        }

        .feature-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 24px;
        }

        .feature-card h3 {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 12px;
        }

        .feature-card p {
            color: #94a3b8;
            line-height: 1.6;
        }

        .install-section {
            max-width: 900px;
            margin: 40px auto 80px;
            padding: 64px;
            background: linear-gradient(135deg, #1e293b, #0f172a);
            border: 1px solid rgba(59, 130, 246, 0.2);
            border-radius: 32px;
            text-align: center;
        }

        @media (max-width: 768px) {
            .install-section {
                padding: 40px 24px;
                margin: 20px 24px;
            }
        }

        .install-section h2 {
            font-size: 32px;
            margin-bottom: 24px;
        }

        .install-grid {
            display: flex;
            gap: 32px;
            justify-content: center;
            flex-wrap: wrap;
            margin-top: 40px;
        }

        .install-item {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 20px;
            padding: 24px;
            text-align: center;
            flex: 1;
            min-width: 200px;
        }

        .install-item h4 {
            font-size: 18px;
            margin-bottom: 12px;
        }

        .install-item p {
            color: #94a3b8;
            font-size: 14px;
            margin-bottom: 16px;
        }

        .install-step {
            background: rgba(59, 130, 246, 0.2);
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 13px;
            color: #60a5fa;
            margin-top: 8px;
        }

        .cta {
            max-width: 900px;
            margin: 40px auto 80px;
            padding: 64px;
            background: linear-gradient(135deg, #1e293b, #0f172a);
            border: 1px solid rgba(59, 130, 246, 0.2);
            border-radius: 32px;
            text-align: center;
        }

        @media (max-width: 768px) {
            .cta {
                padding: 40px 24px;
                margin: 20px 24px;
            }
        }

        .cta h2 {
            font-size: 32px;
            margin-bottom: 16px;
        }

        .cta p {
            color: #94a3b8;
            margin-bottom: 32px;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .animate {
            animation: fadeInUp 0.6s ease-out forwards;
        }

        .delay-1 { animation-delay: 0.1s; }
        .delay-2 { animation-delay: 0.2s; }
        .delay-3 { animation-delay: 0.3s; }
    </style>
</head>
<body>

<div class="gradient-bg"></div>

<nav class="nav">
    <div class="nav-container">
        <a href="/" class="logo">
            <div class="logo-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="32" height="32" fill="none">
                    <path d="M12 3C12 3 5 8 5 15C5 19 8 22 12 22C16 22 19 19 19 15C19 8 12 3 12 3Z" fill="#3b82f6" stroke="#3b82f6" stroke-width="1.2"/>
                    <path d="M12 3V22" stroke="#ffffff" stroke-width="0.8" opacity="0.6"/>
                    <path d="M9 10L12 13L15 10" stroke="#ffffff" stroke-width="0.8" fill="none" opacity="0.6"/>
                    <path d="M8 15L12 18L16 15" stroke="#ffffff" stroke-width="0.8" fill="none" opacity="0.6"/>
                </svg>
            </div>
            <span class="logo-text">Лист</span>
        </a>
        <div class="nav-links">
            <?php if ($isLoggedIn): ?>
                <a href="/profile/profile.php" class="nav-link">Профиль</a>
                <a href="/auth/logout.php" class="btn-outline">Выйти</a>
            <?php else: ?>
                <a href="/auth/login.php" class="nav-link">Войти</a>
                <a href="/auth/register.php" class="btn-primary">Начать</a>
            <?php endif; ?>
        </div>
    </div>
</nav>

<section class="hero animate">
    <div class="hero-badge">Современный мессенджер</div>
    <h1>Общайтесь свободно<br>без границ</h1>
    <p>Современный мессенджер с end-to-end шифрованием, видеозвонками и ИИ-помощником. Ваши данные под надёжной защитой.</p>
    <div class="hero-buttons">
        <?php if (!$isLoggedIn): ?>
            <a href="/auth/register.php" class="btn-primary" style="padding: 12px 32px; font-size: 16px;">Создать аккаунт</a>
            <a href="/auth/login.php" class="btn-outline" style="padding: 12px 32px; font-size: 16px;">Уже есть аккаунт</a>
        <?php else: ?>
            <a href="/profile/profile.php" class="btn-primary" style="padding: 12px 32px; font-size: 16px;">Перейти в профиль</a>
        <?php endif; ?>
    </div>
</section>

<section class="features">
    <h2 class="section-title animate delay-1">Всё для общения</h2>
    <p class="section-subtitle animate delay-1">Мощные инструменты для комфортной коммуникации</p>
    
    <div class="features-grid">
        <div class="feature-card animate delay-2">
            <div class="feature-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2">
                    <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>
                </svg>
            </div>
            <h3>Мгновенные сообщения</h3>
            <p>Моментальная доставка сообщений с end-to-end шифрованием. Поддержка голосовых сообщений и файлов.</p>
        </div>
        
        <div class="feature-card animate delay-2">
            <div class="feature-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2">
                    <rect x="2" y="6" width="20" height="12" rx="2"/>
                    <path d="M8 12h8"/>
                </svg>
            </div>
            <h3>Видеозвонки и стримы</h3>
            <p>Качественная видеосвязь и живые трансляции. Общайтесь лицом к лицу с любого устройства.</p>
        </div>
        
        <div class="feature-card animate delay-3">
            <div class="feature-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <path d="M12 16v-4"/>
                    <path d="M12 8h.01"/>
                </svg>
            </div>
            <h3>Искусственный интеллект</h3>
            <p>Умный ИИ-помощник помогает отвечать на сообщения и автоматизирует рутинные задачи.</p>
        </div>
    </div>
</section>

<section class="install-section animate delay-2">
    <h2>Установка на устройство</h2>
    <p>Используйте Лист как нативное приложение с иконкой на рабочем столе</p>
    
    <div class="install-grid">
        <div class="install-item">
            <h4>iPhone / iPad</h4>
            <p>Safari → Поделиться → На экран «Домой»</p>
            <div class="install-step">Нажмите кнопку «Поделиться»</div>
            <div class="install-step">Выберите «На экран «Домой»</div>
            <div class="install-step">Нажмите «Добавить»</div>
        </div>
        
        <div class="install-item">
            <h4>Android</h4>
            <p>Chrome → Меню → Установить приложение</p>
            <div class="install-step">Нажмите на три точки в Chrome</div>
            <div class="install-step">Выберите «Установить приложение»</div>
            <div class="install-step">Нажмите «Установить»</div>
        </div>
        
        <div class="install-item">
            <h4>Браузер</h4>
            <p>Добавьте в избранное для быстрого доступа</p>
            <div class="install-step">Нажмите Ctrl+D (Cmd+D на Mac)</div>
            <div class="install-step">Выберите папку «Закладки»</div>
            <div class="install-step">Нажмите «Сохранить»</div>
        </div>
    </div>
</section>

<?php if (!$isLoggedIn): ?>
<section class="cta animate delay-3">
    <h2>Начните общение уже сегодня</h2>
    <p>Присоединяйтесь к сообществу пользователей Лист</p>
    <a href="/auth/register.php" class="btn-primary" style="padding: 12px 32px; font-size: 16px;">Создать аккаунт</a>
</section>
<?php endif; ?>

<script>
    // Определяем PWA режим и добавляем класс на body
    if (window.navigator.standalone === true || 
        window.matchMedia('(display-mode: standalone)').matches ||
        window.matchMedia('(display-mode: fullscreen)').matches) {
        document.body.classList.add('pwa-mode');
    }
    
    const observerOptions = {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
    };
    
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
            }
        });
    }, observerOptions);
    
    document.querySelectorAll('.feature-card, .install-section, .cta').forEach(el => {
        el.style.opacity = '0';
        el.style.transform = 'translateY(30px)';
        el.style.transition = 'all 0.6s ease-out';
        observer.observe(el);
    });
</script>

</body>
</html>