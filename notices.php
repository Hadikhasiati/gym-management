<?php
// notices.php
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

$is_admin = (($current_user['role'] ?? '') === 'admin');
$today = date('Y-m-d');

// ساخت خودکار جدول و فیلد تاریخ انقضا
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `notices` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `club_id` INT NOT NULL,
          `title` VARCHAR(191) NOT NULL,
          `content` TEXT NOT NULL,
          `expires_at` DATE NULL,
          `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
          INDEX (`club_id`),
          INDEX (`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // بررسی وجود ستون expires_at در صورت ساخت قبلی جدول
    $checkCol = $pdo->query("SHOW COLUMNS FROM `notices` LIKE 'expires_at'")->fetch();
    if (!$checkCol) {
        $pdo->exec("ALTER TABLE `notices` ADD COLUMN `expires_at` DATE NULL AFTER `content`");
    }
} catch (Exception $e) {}

// ==========================================
// توابع تاریخ شمسی و میلادی
// ==========================================
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

if (!function_exists('jalali_to_gregorian')) {
    function jalali_to_gregorian(int $jy, int $jm, int $jd): array {
        $jy += 1595;
        $days = -355668 + (365 * $jy) + (int)($jy / 33) * 8 + (int)((($jy % 33) + 3) / 4) + $jd + (($jm < 7) ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);
        $gy = 400 * (int)($days / 146097);
        $days %= 146097;
        if ($days > 36524) {
            $days--;
            $gy += 100 * (int)($days / 36524);
            $days %= 36524;
            if ($days >= 365) $days++;
        }
        $gy += 4 * (int)($days / 1461);
        $days %= 1461;
        if ($days > 365) {
            $gy += (int)(($days - 1) / 365);
            $days = ($days - 1) % 365;
        }
        $gd = $days + 1;
        $sal_a = [0, 31, (($gy % 4 == 0 && $gy % 100 != 0) || ($gy % 400 == 0)) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        for ($gm = 0; $gm < 13 && $gd > $sal_a[$gm]; $gm++) $gd -= $sal_a[$gm];
        return [$gy, $gm, $gd];
    }
}

if (!function_exists('to_jalali_date')) {
    function to_jalali_date(?string $g_date): string {
        if (empty($g_date)) return 'همیشگی (بدون انقضا)';
        $parts = explode('-', substr($g_date, 0, 10));
        if (count($parts) !== 3) return '-';
        list($jy, $jm, $jd) = gregorian_to_jalali((int)$parts[0], (int)$parts[1], (int)$parts[2]);
        return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
    }
}

$today_parts = explode('-', $today);
list($current_jy, $current_jm, $current_jd) = gregorian_to_jalali((int)$today_parts[0], (int)$today_parts[1], (int)$today_parts[2]);

$msg = '';
$error = '';

// ==========================================
// ثبت اطلاعیه جدید
// ==========================================
if ($is_admin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_notice') {
    $title         = trim($_POST['title'] ?? '');
    $content       = trim($_POST['content'] ?? '');
    $expire_type   = $_POST['expire_type'] ?? 'preset';
    $expires_at    = null;

    if (empty($title) || empty($content)) {
        $error = 'عنوان و متن اطلاعیه الزامی است.';
    } else {
        if ($expire_type === 'preset') {
            $preset_days = (int)($_POST['preset_days'] ?? 0);
            if ($preset_days > 0) {
                $expires_at = date('Y-m-d', strtotime("+{$preset_days} days"));
            }
        } elseif ($expire_type === 'custom') {
            $jy = (int)($_POST['exp_jy'] ?? $current_jy);
            $jm = (int)($_POST['exp_jm'] ?? $current_jm);
            $jd = (int)($_POST['exp_jd'] ?? $current_jd);
            
            list($gy, $gm, $gd) = jalali_to_gregorian($jy, $jm, $jd);
            $expires_at = sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
        }

        $stmtIns = $pdo->prepare("INSERT INTO notices (club_id, title, content, expires_at, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmtIns->execute([CURRENT_CLUB_ID, $title, $content, $expires_at]);
        $msg = 'اطلاعیه جدید با موفقیت منتشر شد.';
    }
}

// حذف اطلاعیه
if ($is_admin && isset($_GET['delete_notice'])) {
    $del_id = (int)$_GET['delete_notice'];
    $stmtDel = $pdo->prepare("DELETE FROM notices WHERE id = ? AND club_id = ?");
    $stmtDel->execute([$del_id, CURRENT_CLUB_ID]);
    header("Location: notices.php?msg=" . urlencode('اطلاعیه با موفقیت حذف شد.'));
    exit;
}

// واکشی اطلاعیه‌ها
if ($is_admin) {
    // مدیر همه پیام‌ها را با وضعیت می‌بیند
    $stmtList = $pdo->prepare("SELECT * FROM notices WHERE club_id = ? ORDER BY id DESC");
    $stmtList->execute([CURRENT_CLUB_ID]);
    $notices = $stmtList->fetchAll(PDO::FETCH_ASSOC);
} else {
    // هنرجو فقط پیام‌های دارای اعتبار زمانی را می‌بیند
    $stmtList = $pdo->prepare("
        SELECT * FROM notices 
        WHERE club_id = ? AND (expires_at >= ? OR expires_at IS NULL)
        ORDER BY id DESC
    ");
    $stmtList->execute([CURRENT_CLUB_ID, $today]);
    $notices = $stmtList->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تابلو اعلانات و اطلاعیه‌ها | <?= htmlspecialchars(CURRENT_CLUB_NAME) ?></title>
    <style>
        :root { --primary: <?= htmlspecialchars(CURRENT_CLUB_THEME) ?>; }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: system-ui, -apple-system, sans-serif; }
        body { background-color: #0b1120; color: #f8fafc; min-height: 100vh; padding: 1.5rem 1rem; line-height: 1.6; }
        .container { max-width: 1050px; margin: 0 auto; }
        
        .header-bar { display: flex; justify-content: space-between; align-items: center; background: #111827; border: 1px solid rgba(255,255,255,0.08); padding: 1rem 1.25rem; border-radius: 16px; margin-bottom: 1.5rem; }
        .btn-back { background: #1e293b; color: #38bdf8; border: 1px solid #334155; padding: 0.5rem 1rem; border-radius: 8px; text-decoration: none; font-size: 0.85rem; font-weight: 700; }

        .card { background: #111827; border: 1px solid rgba(255,255,255,0.08); border-radius: 16px; padding: 1.5rem; margin-bottom: 1.5rem; box-shadow: 0 4px 20px rgba(0,0,0,0.3); }
        .card-title { font-size: 1.1rem; font-weight: 800; color: #f43f5e; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem; }
        
        .form-group { margin-bottom: 1rem; display: flex; flex-direction: column; gap: 0.4rem; }
        label { font-size: 0.85rem; color: #94a3b8; font-weight: 600; }
        input[type="text"], textarea, select { background: #1e293b; border: 1px solid #334155; border-radius: 8px; padding: 0.75rem; color: #fff; font-size: 0.92rem; outline: none; }
        input:focus, textarea:focus, select:focus { border-color: var(--primary); }

        .expire-settings { background: #182235; border: 1px solid #23314d; border-radius: 12px; padding: 1rem; margin-bottom: 1.25rem; }
        .expire-options { display: flex; gap: 1rem; flex-wrap: wrap; margin-top: 0.5rem; }
        .expire-radio { display: flex; align-items: center; gap: 0.4rem; font-size: 0.85rem; cursor: pointer; color: #cbd5e1; }

        .btn-submit { background: linear-gradient(135deg, #f43f5e, #e11d48); color: #fff; border: none; padding: 0.85rem 1.75rem; border-radius: 8px; font-weight: 700; cursor: pointer; }
        .btn-submit:hover { opacity: 0.9; }

        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; text-align: right; font-size: 0.88rem; min-width: 750px; }
        th, td { padding: 0.9rem 0.75rem; border-bottom: 1px solid rgba(255,255,255,0.05); vertical-align: middle; }
        th { color: #94a3b8; }

        .badge-active { background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid #10b981; padding: 0.25rem 0.6rem; border-radius: 6px; font-size: 0.75rem; font-weight: 700; }
        .badge-expired { background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid #ef4444; padding: 0.25rem 0.6rem; border-radius: 6px; font-size: 0.75rem; font-weight: 700; }
        .btn-del { color: #ef4444; text-decoration: none; font-size: 0.8rem; font-weight: 700; }

        /* کارت‌های سمت هنرجو */
        .notice-card-student { background: #161f30; border: 1px solid #23324c; border-radius: 14px; padding: 1.25rem; margin-bottom: 1rem; border-right: 4px solid #f43f5e; }
        .student-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; }
        .student-title { font-size: 1.05rem; font-weight: 800; color: #fff; }
        .student-date { font-size: 0.78rem; color: #94a3b8; font-family: monospace; }
        .student-content { font-size: 0.9rem; color: #cbd5e1; white-space: pre-line; line-height: 1.7; }

        .alert-success { background: rgba(16, 185, 129, 0.15); border: 1px solid #10b981; color: #34d399; padding: 0.85rem; border-radius: 10px; margin-bottom: 1.25rem; }
        .alert-error { background: rgba(239, 68, 68, 0.15); border: 1px solid #ef4444; color: #f87171; padding: 0.85rem; border-radius: 10px; margin-bottom: 1.25rem; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header-bar">
            <div>
                <h2 style="font-size: 1.2rem; color: #f43f5e;">📢 تابلو اعلانات و پیام‌های باشگاه</h2>
                <div style="font-size: 0.8rem; color: #64748b; margin-top: 3px;"><?= htmlspecialchars(CURRENT_CLUB_NAME) ?></div>
            </div>
            <a href="dashboard.php" class="btn-back">بازگشت به پیشخوان ↵</a>
        </div>

        <?php if ($msg || isset($_GET['msg'])): ?>
            <div class="alert-success">✓ <?= htmlspecialchars($msg ?: $_GET['msg']) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($is_admin): ?>
            <!-- ======================= پنل مدیریت اعلانات ======================= -->
            <div class="card">
                <div class="card-title">➕ انتشار اطلاعیه جدید با تاریخ انقضا</div>
                <form method="POST">
                    <input type="hidden" name="action" value="add_notice">
                    
                    <div class="form-group">
                        <label>موضوع یا عنوان اطلاعیه *</label>
                        <input type="text" name="title" placeholder="مثال: تغییر ساعت کلاس‌های روز پنجشنبه" required>
                    </div>

                    <div class="form-group">
                        <label>متن پیام برای هنرجویان *</label>
                        <textarea name="content" rows="3" placeholder="متن کامل پیام خود را بنویسید..." required></textarea>
                    </div>

                    <!-- بخش تنظیم مهلت نمایش و انقضا -->
                    <div class="expire-settings">
                        <label style="color:#38bdf8; font-weight:700;">⏱️ مهلت نمایش در پنل هنرجویان (تاریخ انقضا):</label>
                        <div class="expire-options">
                            <label class="expire-radio">
                                <input type="radio" name="expire_type" value="preset" checked onclick="toggleCustomDate(false)">
                                مهلت زمانی مشخص:
                            </label>
                            <select name="preset_days" id="presetSelect" style="padding:0.4rem 0.6rem; font-size:0.85rem;">
                                <option value="3">۳ روز آینده</option>
                                <option value="7" selected>۱ هفته آینده</option>
                                <option value="14">۲ هفته آینده</option>
                                <option value="30">۱ ماه آینده</option>
                                <option value="0">همیشگی (بدون انقضا)</option>
                            </select>

                            <label class="expire-radio" style="margin-right:1rem;">
                                <input type="radio" name="expire_type" value="custom" onclick="toggleCustomDate(true)">
                                تعیین دقیق تاریخ شمسی:
                            </label>

                            <div id="customDateBox" style="display:none; align-items:center; gap:0.4rem;">
                                <select name="exp_jd" style="padding:0.4rem;">
                                    <?php for ($d = 1; $d <= 31; $d++): ?>
                                        <option value="<?= $d ?>" <?= $d === $current_jd ? 'selected' : '' ?>><?= $d ?></option>
                                    <?php endfor; ?>
                                </select>
                                <select name="exp_jm" style="padding:0.4rem;">
                                    <?php 
                                    $months = [1=>'فروردین',2=>'اردیبهشت',3=>'خرداد',4=>'تیر',5=>'مرداد',6=>'شهریور',7=>'مهر',8=>'آبان',9=>'آذر',10=>'دی',11=>'بهمن',12=>'اسفند'];
                                    foreach ($months as $m_num => $m_name): ?>
                                        <option value="<?= $m_num ?>" <?= $m_num === $current_jm ? 'selected' : '' ?>><?= $m_name ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="number" name="exp_jy" value="<?= $current_jy ?>" style="width:80px; padding:0.4rem; text-align:center;">
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn-submit">🚀 انتشار اطلاعیه</button>
                </form>
            </div>

            <!-- جدول لیست پیام‌های ثبت‌شده -->
            <div class="card">
                <div class="card-title" style="color:#38bdf8;">📋 لیست پیام‌های ثبت‌شده باشگاه</div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>عنوان اطلاعیه</th>
                                <th>متن پیام</th>
                                <th>تاریخ انتشار (شمسی)</th>
                                <th>تاریخ انقضا (شمسی)</th>
                                <th>وضعیت نمایش</th>
                                <th>عملیات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($notices)): ?>
                                <tr><td colspan="7" style="text-align:center; color:#64748b; padding:2rem;">هیچ اطلاعیه‌ای ثبت نشده است.</td></tr>
                            <?php else: ?>
                                <?php $i = 1; foreach ($notices as $n): 
                                    $is_active = (empty($n['expires_at']) || $n['expires_at'] >= $today);
                                ?>
                                    <tr>
                                        <td><?= $i++ ?></td>
                                        <td><strong style="color:#fff;"><?= htmlspecialchars($n['title']) ?></strong></td>
                                        <td style="color:#cbd5e1; max-width:280px;"><?= nl2br(htmlspecialchars($n['content'])) ?></td>
                                        <td style="font-family:monospace; color:#94a3b8;"><?= to_jalali_date($n['created_at']) ?></td>
                                        <td style="font-family:monospace; color:<?= !empty($n['expires_at']) ? '#38bdf8' : '#cbd5e1' ?>;">
                                            <?= to_jalali_date($n['expires_at']) ?>
                                        </td>
                                        <td>
                                            <?= $is_active ? '<span class="badge-active">✓ در حال نمایش</span>' : '<span class="badge-expired">✕ منقضی شده</span>' ?>
                                        </td>
                                        <td>
                                            <a href="notices.php?delete_notice=<?= $n['id'] ?>" class="btn-del" onclick="return confirm('آیا از حذف این اطلاعیه مطمئن هستید؟')">حذف</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php else: ?>
            <!-- ======================= نمای هنرجو ======================= -->
            <div class="card">
                <div class="card-title">📌 اطلاعیه‌ها و پیام‌های مهم مربی</div>
                <?php if (empty($notices)): ?>
                    <div style="text-align:center; color:#64748b; padding:3rem;">در حال حاضر اطلاعیه جدیدی وجود ندارد.</div>
                <?php else: ?>
                    <?php foreach ($notices as $n): ?>
                        <div class="notice-card-student">
                            <div class="student-head">
                                <span class="student-title">📢 <?= htmlspecialchars($n['title']) ?></span>
                                <span class="student-date">انتشار: <?= to_jalali_date($n['created_at']) ?></span>
                            </div>
                            <div class="student-content"><?= nl2br(htmlspecialchars($n['content'])) ?></div>
                            <?php if (!empty($n['expires_at'])): ?>
                                <div style="font-size:0.75rem; color:#64748b; margin-top:0.6rem; font-family:monospace;">
                                    مهلت اعتبار پیام: <?= to_jalali_date($n['expires_at']) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function toggleCustomDate(showCustom) {
            document.getElementById('customDateBox').style.display = showCustom ? 'flex' : 'none';
            document.getElementById('presetSelect').disabled = showCustom;
        }
    </script>
</body>
</html>