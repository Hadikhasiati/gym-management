<?php
// users.php
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

if (($current_user['role'] ?? '') !== 'admin') {
    header("Location: dashboard.php");
    exit;
}

// توابع تاریخ شمسی
if (!function_exists('gregorian_to_jalali')) {
    function gregorian_to_jalali(int $gy, int $gm, int $gd): array {
        $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
        $days = 355666 + (365 * $gy) + (int)(($gy2 + 3) / 4) - (int)(($gy2 + 99) / 100) + (int)(($gy2 + 399) / 400) + $gd + $g_d_m[$gm - 1];
        $jy = -1595 + (33 * (int)($days / 12053));
        $days %= 12053;
        $jy += 4 * (int)($days / 1461);
        $days %= 1461;
        if ($days > 365) {
            $jy += (int)(($days - 1) / 365);
            $days = ($days - 1) % 365;
        }
        if ($days < 186) {
            $jm = 1 + (int)($days / 31);
            $jd = 1 + ($days % 31);
        } else {
            $jm = 7 + (int)(($days - 186) / 30);
            $jd = 1 + (($days - 186) % 30);
        }
        return [$jy, $jm, $jd];
    }
}

if (!function_exists('to_jalali_date')) {
    function to_jalali_date(?string $g_date): string {
        if (empty($g_date)) return 'ثبت نشده';
        $parts = explode('-', substr($g_date, 0, 10));
        if (count($parts) !== 3) return 'نامعتبر';
        list($jy, $jm, $jd) = gregorian_to_jalali((int)$parts[0], (int)$parts[1], (int)$parts[2]);
        return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
    }
}

$today = date('Y-m-d');

// ۱. لاگین تستی
if (isset($_GET['login_as'])) {
    $target_id = (int)$_GET['login_as'];
    $stmtTarget = $pdo->prepare("SELECT * FROM users WHERE id = ? AND club_id = ? LIMIT 1");
    $stmtTarget->execute([$target_id, CURRENT_CLUB_ID]);
    $target = $stmtTarget->fetch(PDO::FETCH_ASSOC);

    if ($target) {
        if (!isset($_SESSION['impersonator_admin_id'])) {
            $_SESSION['impersonator_admin_id'] = $current_user['id'];
        }
        $_SESSION['user_id'] = $target['id'];
        header("Location: dashboard.php");
        exit;
    }
}

// ۲. تنظیم اعتبار شهریه
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'adjust_subscription') {
    $target_id  = (int)($_POST['user_id'] ?? 0);
    $sub_action = $_POST['sub_action'] ?? '';
    $days       = max(1, (int)($_POST['days'] ?? 30));

    $stmtUser = $pdo->prepare("SELECT subscription_expires_at FROM users WHERE id = ? AND club_id = ? AND role = 'student' LIMIT 1");
    $stmtUser->execute([$target_id, CURRENT_CLUB_ID]);
    $user_sub = $stmtUser->fetch(PDO::FETCH_ASSOC);

    if ($user_sub) {
        $current_exp = $user_sub['subscription_expires_at'];
        $new_exp = null;
        $msg_text = '';

        if ($sub_action === 'add') {
            $base = (!empty($current_exp) && $current_exp >= $today) ? $current_exp : $today;
            $new_exp = date('Y-m-d', strtotime("{$base} +{$days} days"));
            $msg_text = "اعتبار به مدت {$days} روز افزایش یافت.";
        } elseif ($sub_action === 'subtract') {
            $base = (!empty($current_exp)) ? $current_exp : $today;
            $new_exp = date('Y-m-d', strtotime("{$base} -{$days} days"));
            $msg_text = "از اعتبار هنرجو {$days} روز کسر شد.";
        } elseif ($sub_action === 'expire') {
            $new_exp = date('Y-m-d', strtotime('-1 day'));
            $msg_text = "اشتراک منقضی شد.";
        }

        if ($new_exp !== null) {
            $stmtUp = $pdo->prepare("UPDATE users SET subscription_expires_at = ? WHERE id = ? AND club_id = ?");
            $stmtUp->execute([$new_exp, $target_id, CURRENT_CLUB_ID]);
            header("Location: users.php?msg=" . urlencode($msg_text));
            exit;
        }
    }
}

// واکشی هنرجویان
$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');

$sql = "SELECT * FROM users WHERE club_id = ? AND role = 'student'";
$params = [CURRENT_CLUB_ID];

if (!empty($search)) {
    $sql .= " AND (full_name LIKE ? OR phone LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}
if ($status === 'active') {
    $sql .= " AND subscription_expires_at >= ?";
    $params[] = $today;
} elseif ($status === 'expired') {
    $sql .= " AND (subscription_expires_at < ? OR subscription_expires_at IS NULL)";
    $params[] = $today;
}

$sql .= " ORDER BY id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>مدیریت هنرجویان | <?= htmlspecialchars(CURRENT_CLUB_NAME) ?></title>
    <style>
        :root {
            --primary: <?= htmlspecialchars(CURRENT_CLUB_THEME) ?>;
            --bg-dark: #0b1120;
            --card-bg: rgba(17, 24, 39, 0.85);
            --border-color: rgba(255, 255, 255, 0.08);
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; font-family: system-ui, -apple-system, sans-serif; -webkit-tap-highlight-color: transparent; }
        body { background-color: var(--bg-dark); color: var(--text-main); min-height: 100vh; padding: 1rem 0.85rem calc(80px + env(safe-area-inset-bottom)) 0.85rem; }
        .container { max-width: 900px; margin: 0 auto; }
        
        .header-bar {
            display: flex; justify-content: space-between; align-items: center; background: rgba(30, 41, 59, 0.7);
            border: 1px solid var(--border-color); backdrop-filter: blur(12px); border-radius: 16px;
            padding: 0.85rem 1.1rem; margin-bottom: 1rem;
        }
        .btn-back { background: #1e293b; color: #38bdf8; border: 1px solid #334155; padding: 0.45rem 0.9rem; border-radius: 8px; text-decoration: none; font-size: 0.82rem; font-weight: 700; }

        .search-box { display: flex; gap: 0.5rem; margin-bottom: 1rem; }
        .form-ctrl { height: 42px; background: #1e293b; border: 1px solid #334155; border-radius: 8px; color: #fff; padding: 0 0.75rem; font-size: 0.88rem; outline: none; }
        
        /* کارت موبایلی هنرجو */
        .user-card-mob {
            background: linear-gradient(135deg, rgba(30, 41, 59, 0.6), rgba(15, 23, 42, 0.85));
            border: 1px solid var(--border-color); border-radius: 14px; padding: 1rem; margin-bottom: 0.75rem;
        }
        .user-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.4rem; }
        .user-name { font-size: 0.95rem; font-weight: 800; color: #fff; }
        .user-phone { font-family: monospace; font-size: 0.85rem; color: #94a3b8; }
        
        .user-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px solid rgba(255,255,255,0.06); }
        .btn-mob-act { height: 36px; border-radius: 8px; font-size: 0.8rem; font-weight: 700; display: flex; align-items: center; justify-content: center; text-decoration: none; border: none; cursor: pointer; }
        .btn-mob-credit { background: rgba(139, 92, 246, 0.15); color: #c4b5fd; border: 1px solid #8b5cf6; }
        .btn-mob-login { background: rgba(2, 132, 199, 0.15); color: #38bdf8; border: 1px solid #0284c7; }

        /* مودال تنظیم اعتبار */
        .modal-overlay { position: fixed; inset: 0; background: rgba(0, 0, 0, 0.85); backdrop-filter: blur(5px); display: none; align-items: center; justify-content: center; z-index: 999999; padding: 1rem; }
        .modal-box { background: #111827; border: 1px solid #334155; border-radius: 18px; max-width: 400px; width: 100%; padding: 1.25rem; }
    </style>
</head>
<body>
    <div class="container">
        
        <div class="header-bar">
            <div>
                <h2 style="font-size: 1.05rem; color: #38bdf8;">👥 مدیریت هنرجویان</h2>
                <div style="font-size: 0.75rem; color: #64748b;"><?= htmlspecialchars(CURRENT_CLUB_NAME) ?></div>
            </div>
            <a href="dashboard.php" class="btn-back">بازگشت ↵</a>
        </div>

        <?php if (isset($_GET['msg'])): ?>
            <div style="background:rgba(16,185,129,0.15); color:#34d399; border:1px solid #10b981; padding:0.75rem; border-radius:8px; margin-bottom:1rem; font-size:0.85rem;">
                ✓ <?= htmlspecialchars($_GET['msg']) ?>
            </div>
        <?php endif; ?>

        <form method="GET" class="search-box">
            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" class="form-ctrl" style="flex:1;" placeholder="جستجوی نام یا موبایل...">
            <button type="submit" style="background:var(--primary); color:#fff; border:none; padding:0 1.1rem; border-radius:8px; font-weight:700; height:42px;">جستجو</button>
        </form>

        <?php if (empty($users)): ?>
            <div style="text-align:center; color:#64748b; padding:3rem;">هنرجویی یافت نشد.</div>
        <?php else: ?>
            <?php foreach ($users as $u): 
                $is_act = (!empty($u['subscription_expires_at']) && $u['subscription_expires_at'] >= $today);
                $jalali_exp = to_jalali_date($u['subscription_expires_at']);
            ?>
                <div class="user-card-mob">
                    <div class="user-top">
                        <span class="user-name"><?= htmlspecialchars($u['full_name'] ?: 'بدون نام') ?></span>
                        <span style="font-size:0.75rem; padding:2px 6px; border-radius:4px; <?= $is_act ? 'background:rgba(16,185,129,0.15); color:#34d399;' : 'background:rgba(239,68,68,0.15); color:#f87171;' ?>">
                            <?= $is_act ? 'معتبر' : 'منقضی' ?>
                        </span>
                    </div>

                    <div style="display:flex; justify-content:space-between; font-size:0.8rem; color:#94a3b8; margin-top:4px;">
                        <span>شماره: <span style="font-family:monospace; color:#cbd5e1;"><?= htmlspecialchars($u['phone']) ?></span></span>
                        <span>سطح: <strong style="color:#38bdf8;"><?= htmlspecialchars($u['skill_level'] ?? 'مبتدی') ?></strong></span>
                    </div>

                    <div style="font-size:0.8rem; color:#94a3b8; margin-top:4px;">
                        انقضای شهریه: <strong style="font-family:monospace; color:<?= $is_act ? '#38bdf8' : '#f87171' ?>;"><?= $jalali_exp ?></strong>
                    </div>

                    <div class="user-actions">
                        <button type="button" class="btn-mob-act btn-mob-credit" onclick="openCreditModal(<?= $u['id'] ?>, '<?= htmlspecialchars(addslashes($u['full_name'] ?: $u['phone'])) ?>', '<?= htmlspecialchars($jalali_exp) ?>')">
                            ⏱️ تمدید اعتبار
                        </button>
                        <a href="users.php?login_as=<?= $u['id'] ?>" class="btn-mob-act btn-mob-login">ورود به پنل ↗</a>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

    </div>

    <!-- مودال تنظیم اعتبار -->
    <div class="modal-overlay" id="creditModal">
        <div class="modal-box">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
                <strong style="color:#38bdf8; font-size:0.95rem;">⏱️ تنظیم اعتبار شهریه</strong>
                <button onclick="closeCreditModal()" style="background:none; border:none; color:#94a3b8; font-size:1.2rem; cursor:pointer;">✕</button>
            </div>

            <div style="background:#1e293b; padding:0.75rem; border-radius:10px; margin-bottom:1rem; font-size:0.85rem;">
                <div>هنرجو: <strong id="modalUserName" style="color:#38bdf8;">-</strong></div>
                <div style="margin-top:3px;">اعتبار فعلی: <span id="modalCurrentExp" style="color:#4ade80; font-family:monospace;">-</span></div>
            </div>

            <form method="POST" action="users.php" style="margin-bottom:0.5rem;">
                <input type="hidden" name="action" value="adjust_subscription">
                <input type="hidden" name="sub_action" value="add">
                <input type="hidden" name="days" value="30">
                <input type="hidden" name="user_id" id="quickAddId">
                <button type="submit" style="width:100%; height:40px; background:#059669; color:#fff; border:none; border-radius:8px; font-weight:700; font-size:0.85rem; cursor:pointer;">
                    +۳۰ روز (۱ ماه)
                </button>
            </form>

            <form method="POST" action="users.php" onsubmit="return confirm('اشتراک منقضی شود؟')">
                <input type="hidden" name="action" value="adjust_subscription">
                <input type="hidden" name="sub_action" value="expire">
                <input type="hidden" name="user_id" id="quickExpId">
                <button type="submit" style="width:100%; height:40px; background:#ef4444; color:#fff; border:none; border-radius:8px; font-weight:700; font-size:0.85rem; cursor:pointer;">
                    ✕ منقضی‌سازی فوری
                </button>
            </form>
        </div>
    </div>

    <script>
        function openCreditModal(userId, userName, currentExp) {
            document.getElementById('modalUserName').innerText = userName;
            document.getElementById('modalCurrentExp').innerText = currentExp;
            document.getElementById('quickAddId').value = userId;
            document.getElementById('quickExpId').value = userId;
            document.getElementById('creditModal').style.display = 'flex';
        }
        function closeCreditModal() { document.getElementById('creditModal').style.display = 'none'; }
    </script>

    <!-- نوار ناوبری پایین -->
    <?php require_once __DIR__ . '/mobile_nav.php'; ?>
</body>
</html>