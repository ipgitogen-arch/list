<?php
declare(strict_types=1);

require __DIR__ . '/../inc/dd_bb.php';
require __DIR__ . '/../inc/auth.php';
require __DIR__ . '/../inc/csrf.php';

$userId = current_user_id();
if (!$userId) {
    header('Location: /auth/login.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT 
        mr.id,
        mr.from_user_id,
        mr.created_at,
        u.name,
        u.uid,
        p.avatar
    FROM message_requests mr
    JOIN users u ON u.id = mr.from_user_id
    LEFT JOIN profiles p ON p.user_id = u.id
    WHERE mr.to_user_id=? AND mr.status='pending' AND u.is_deleted=0
    ORDER BY mr.id DESC
");
$stmt->execute([$userId]);
$requests = $stmt->fetchAll();

function avatar_path(?string $a): string {
    return $a && trim($a) !== '' ? $a : '/profile/default-avatar.svg';
}
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Лист — Запросы</title>
<link rel="stylesheet" href="/profile/style.css?v=1000">
<style>
.requests-wrap{max-width:780px;margin:30px auto;padding:0 16px;}
.req-card{
    background:#2b2b2b;
    border-radius:18px;
    padding:18px;
    margin-bottom:16px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:16px;
    border:1px solid rgba(255,255,255,.05);
}
.req-left{display:flex;align-items:center;gap:14px;}
.req-left img{width:58px;height:58px;border-radius:50%;object-fit:cover;}
.req-name{font-size:18px;font-weight:800;}
.req-id{font-size:13px;color:#aeb6bd;margin-top:4px;}
.req-actions{display:flex;flex-wrap:wrap;gap:10px;}
.req-actions form{margin:0;}
.req-actions button{
    border:none;
    border-radius:12px;
    padding:12px 16px;
    font-weight:800;
    cursor:pointer;
    color:#fff;
}
.btn-yes{background:linear-gradient(90deg,#00aaff,#0088cc);}
.btn-no{background:#444;}
.btn-block{background:#ff4d4d;}
.empty-box-req{
    background:#2b2b2b;
    border-radius:18px;
    padding:28px;
    text-align:center;
    color:#cfcfcf;
}
.top-nav{
    max-width:780px;
    margin:20px auto 0;
    padding:0 16px;
}
.top-nav a{
    display:inline-block;
    background:#00aaff;
    color:#fff;
    padding:12px 16px;
    border-radius:12px;
    text-decoration:none;
    font-weight:800;
}
@media(max-width:700px){
    .req-card{flex-direction:column;align-items:flex-start;}
    .req-actions{width:100%;}
    .req-actions form{flex:1;}
    .req-actions button{width:100%;}
}
</style>
</head>
<body>

<div class="top-nav">
    <a href="/profile/dialogs.php">← Назад к диалогам</a>
</div>

<div class="requests-wrap">
    <div class="profile-container" style="max-width:100%; margin-bottom:18px;">
        <h1>Запросы на переписку</h1>
    </div>

    <?php if (!$requests): ?>
        <div class="empty-box-req">У вас пока нет новых запросов.</div>
    <?php endif; ?>

    <?php foreach ($requests as $r): ?>
        <div class="req-card">
            <div class="req-left">
                <img src="<?= htmlspecialchars(avatar_path($r['avatar'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" alt="">
                <div>
                    <div class="req-name"><?= htmlspecialchars((string)$r['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                    <div class="req-id">ID: <?= htmlspecialchars((string)($r['uid'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                </div>
            </div>

            <div class="req-actions">
                <form method="post" action="/profile/request_action.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                    <input type="hidden" name="action" value="accept">
                    <button type="submit" class="btn-yes">Разрешить</button>
                </form>

                <form method="post" action="/profile/request_action.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                    <input type="hidden" name="action" value="decline">
                    <button type="submit" class="btn-no">Отклонить</button>
                </form>

                <form method="post" action="/profile/request_action.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                    <input type="hidden" name="action" value="block">
                    <button type="submit" class="btn-block">В блок</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
</div>

</body>
</html>