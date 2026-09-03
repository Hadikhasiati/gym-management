<?php
// exercises.php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();
}

date_default_timezone_set('Asia/Tehran');

require_once __DIR__ . '/db.php';
if (file_exists(__DIR__ . '/tenant.php')) {
    require_once __DIR__ . '/tenant.php';
}
require_once __DIR__ . '/auth.php';

$current_user = check_auth();

if (!defined('CURRENT_CLUB_ID')) define('CURRENT_CLUB_ID', (int)($current_user['club_id'] ?? 1));
if (!defined('CURRENT_CLUB_NAME')) define('CURRENT_CLUB_NAME', 'باشگاه رادین اسکیت');
if (!defined('CURRENT_CLUB_THEME')) define('CURRENT_CLUB_THEME', '#0284c7');

$user_role = $current_user['role'] ?? 'student';
$is_admin = ($user_role === 'admin' || $user_role === 'coach');
$today = date('Y-m-d');

// بررسی قفل دسترسی هنرجو به حرکات تمرینی در صورت نداشتن اشتراک فعال
$is_sub_valid = (!empty($current_user['subscription_expires_at']) && $current_user['subscription_expires_at'] >= $today);
if (!$is_admin && !$is_sub_valid) {
    ?>
    <!DOCTYPE html>
    <html lang="fa" dir="rtl">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
        <title>قفل دسترسی | <?= htmlspecialchars(CURRENT_CLUB_NAME) ?></title>
        <style>
            body { background: #0b1120; color: #fff; font-family: system-ui, -apple-system, sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 1rem; margin: 0; }
            .lock-card { background: rgba(17, 24, 39, 0.95); border: 1px solid rgba(245, 158, 11, 0.3); border-radius: 20px; padding: 2rem 1.5rem; max-width: 420px; width: 100%; text-align: center; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
            .lock-icon { font-size: 3.5rem; margin-bottom: 1rem; }
            .lock-title { font-size: 1.15rem; font-weight: 900; color: #fbbf24; margin-bottom: 0.5rem; }
            .lock-desc { font-size: 0.85rem; color: #94a3b8; line-height: 1.6; margin-bottom: 1.5rem; }
            .btn-pay-now { background: linear-gradient(135deg, #0284c7, #0369a1); color: #fff; text-decoration: none; padding: 0.8rem 1.5rem; border-radius: 12px; font-weight: 800; display: block; box-shadow: 0 4px 15px rgba(2, 132, 199, 0.4); margin-bottom: 0.75rem; }
        </style>
    </head>
    <body>
        <div class="lock-card">
            <div class="lock-icon">🔒</div>
            <div class="lock-title">بانک حرکات تمرینی قفل است</div>
            <div class="lock-desc">مشاهده ویدیوهای آموزشی و نکات مربی نیازمند داشتن اشتراک فعال شهریه در باشگاه است.</div>
            <a href="payments.php" class="btn-pay-now">💳 پرداخت و تمدید فوری شهریه</a>
            <a href="dashboard.php" style="color: #94a3b8; font-size: 0.8rem; text-decoration: none; font-weight: 700;">بازگشت به پیشخوان کاربری</a>
        </div>
        <?php require_once __DIR__ . '/mobile_nav.php'; ?>
    </body>
    </html>
    <?php
    exit;
}

$stmtLevels = $pdo->prepare("SELECT * FROM club_levels WHERE club_id = ? ORDER BY id ASC");
$stmtLevels->execute([CURRENT_CLUB_ID]);
$club_levels = $stmtLevels->fetchAll(PDO::FETCH_ASSOC);

if ($is_admin) {
    $filter_level = trim($_GET['level'] ?? '');
    $sqlEx = "SELECT * FROM exercises WHERE club_id = ?";
    $paramsEx = [CURRENT_CLUB_ID];
    if (!empty($filter_level)) {
        $sqlEx .= " AND level_title = ?";
        $paramsEx[] = $filter_level;
    }
    $sqlEx .= " ORDER BY id ASC";
    $stmtEx = $pdo->prepare($sqlEx);
    $stmtEx->execute($paramsEx);
    $exercises = $stmtEx->fetchAll(PDO::FETCH_ASSOC);
} else {
    $my_level = trim($current_user['skill_level'] ?? 'مبتدی');
    $stmtMyEx = $pdo->prepare("SELECT * FROM exercises WHERE club_id = ? AND level_title = ? ORDER BY id ASC");
    $stmtMyEx->execute([CURRENT_CLUB_ID, $my_level]);
    $exercises = $stmtMyEx->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>حرکات تمرینی | <?= htmlspecialchars(CURRENT_CLUB_NAME) ?></title>
    <style>
        :root { --primary: <?= htmlspecialchars(CURRENT_CLUB_THEME) ?>; --bg-dark: #0b1120; --card-bg: rgba(17, 24, 39, 0.85); --border-color: rgba(255, 255, 255, 0.08); --text-main: #f8fafc; --text-muted: #94a3b8; }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: system-ui, -apple-system, sans-serif; -webkit-tap-highlight-color: transparent; }
        body { background-color: var(--bg-dark); color: var(--text-main); min-height: 100vh; padding: 1rem 0.85rem calc(80px + env(safe-area-inset-bottom)) 0.85rem; line-height: 1.5; }
        .container { max-width: 900px; margin: 0 auto; }
        .header-bar { display: flex; justify-content: space-between; align-items: center; background: rgba(30, 41, 59, 0.7); border: 1px solid var(--border-color); backdrop-filter: blur(12px); border-radius: 16px; padding: 0.85rem 1.1rem; margin-bottom: 1rem; }
        .btn-back { background: #1e293b; color: #38bdf8; border: 1px solid #334155; padding: 0.45rem 0.9rem; border-radius: 8px; text-decoration: none; font-size: 0.82rem; font-weight: 700; }
        .mobile-tabs { display: flex; gap: 0.5rem; overflow-x: auto; padding-bottom: 0.5rem; margin-bottom: 1rem; scrollbar-width: none; }
        .mobile-tabs::-webkit-scrollbar { display: none; }
        .tab-btn { white-space: nowrap; background: #1e293b; color: #94a3b8; border: 1px solid #334155; padding: 0.5rem 1rem; border-radius: 10px; text-decoration: none; font-size: 0.82rem; font-weight: 700; }
        .tab-btn.active { background: var(--primary); color: #fff; border-color: var(--primary); }
        .exercise-card { background: linear-gradient(135deg, rgba(22, 31, 48, 0.9), rgba(15, 23, 42, 0.95)); border: 1px solid var(--border-color); border-radius: 16px; padding: 1.15rem; margin-bottom: 1rem; }
        .exercise-title { font-size: 1.05rem; font-weight: 800; color: #fff; margin-bottom: 0.6rem; display: flex; justify-content: space-between; align-items: center; }
        .exercise-desc { font-size: 0.85rem; color: #cbd5e1; line-height: 1.6; white-space: pre-line; margin-bottom: 0.75rem; }
        .coach-tips-box { background: rgba(245, 158, 11, 0.08); border: 1px solid rgba(245, 158, 11, 0.25); border-radius: 10px; padding: 0.75rem; margin-top: 0.6rem; font-size: 0.82rem; color: #fde68a; line-height: 1.6; }
        .coach-tips-title { font-weight: 800; color: #fbbf24; margin-bottom: 0.3rem; font-size: 0.82rem; }
        .mastered-label { background: #1e293b; border: 1px solid #334155; padding: 4px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: 700; color: #94a3b8; display: inline-flex; align-items: center; gap: 4px; cursor: pointer; }
        .mastered-label input { display: none; }
        .mastered-label input:checked + span { color: #34d399; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header-bar">
            <div>
                <h2 style="font-size: 1.05rem; color: #38bdf8;">🛹 <?= $is_admin ? 'مدیریت حرکات تمرینی' : 'حرکات تمرینی سطح من' ?></h2>
                <div style="font-size: 0.75rem; color: #64748b;"><?= htmlspecialchars(CURRENT_CLUB_NAME) ?></div>
            </div>
            <a href="dashboard.php" class="btn-back">بازگشت ↵</a>
        </div>

        <?php if ($is_admin): ?>
            <div class="mobile-tabs">
                <a href="exercises.php" class="tab-btn <?= empty($_GET['level']) ? 'active' : '' ?>">همه</a>
                <?php foreach ($club_levels as $lvl): ?>
                    <a href="exercises.php?level=<?= urlencode($lvl['title']) ?>" class="tab-btn <?= (isset($_GET['level']) && $_GET['level'] === $lvl['title']) ? 'active' : '' ?>">
                        <?= htmlspecialchars($lvl['title']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (empty($exercises)): ?>
            <div class="exercise-card" style="text-align:center; color:#64748b; padding:2.5rem;">حرکت تمرینی یافت نشد.</div>
        <?php else: ?>
            <?php foreach ($exercises as $ex): 
                $desc = trim($ex['description'] ?? '');
                $tips = trim($ex['coach_tips'] ?? '');
                if (empty($tips) && strpos($desc, 'نکات کلیدی:') !== false) {
                    $parts = explode('نکات کلیدی:', $desc, 2);
                    $desc = trim($parts[0]);
                    $tips = trim($parts[1] ?? '');
                }
            ?>
                <div class="exercise-card">
                    <div class="exercise-title">
                        <span>🛹 <?= htmlspecialchars($ex['title']) ?></span>
                        <label class="mastered-label">
                            <input type="checkbox" onchange="toggleMastered(<?= $ex['id'] ?>)" id="chk-<?= $ex['id'] ?>">
                            <span>✓ تسلط</span>
                        </label>
                    </div>
                    <?php if (!empty($desc)): ?><div class="exercise-desc"><?= nl2br(htmlspecialchars($desc)) ?></div><?php endif; ?>
                    <?php if (!empty($tips)): ?>
                        <div class="coach-tips-box">
                            <div class="coach-tips-title">💡 نکات مربی:</div>
                            <div><?= nl2br(htmlspecialchars($tips)) ?></div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const saved = JSON.parse(localStorage.getItem('mastered_exercises_club_<?= CURRENT_CLUB_ID ?>') || '[]');
            saved.forEach(id => {
                const chk = document.getElementById('chk-' + id);
                if (chk) { chk.checked = true; chk.nextElementSibling.style.color = '#34d399'; }
            });
        });
        function toggleMastered(id) {
            let saved = JSON.parse(localStorage.getItem('mastered_exercises_club_<?= CURRENT_CLUB_ID ?>') || '[]');
            const chk = document.getElementById('chk-' + id);
            if (chk.checked) {
                if (!saved.includes(id)) saved.push(id);
                chk.nextElementSibling.style.color = '#34d399';
            } else {
                saved = saved.filter(item => item !== id);
                chk.nextElementSibling.style.color = '#94a3b8';
            }
            localStorage.setItem('mastered_exercises_club_<?= CURRENT_CLUB_ID ?>', JSON.stringify(saved));
        }
    </script>
    <?php require_once __DIR__ . '/mobile_nav.php'; ?>
</body>
</html>