<?php
// attendance.php
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

// بررسی قفل دسترسی هنرجو در صورت نداشتن اشتراک فعال
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
            .lock-card { background: rgba(17, 24, 39, 0.95); border: 1px solid rgba(239, 68, 68, 0.3); border-radius: 20px; padding: 2rem 1.5rem; max-width: 420px; width: 100%; text-align: center; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
            .lock-icon { font-size: 3.5rem; margin-bottom: 1rem; }
            .lock-title { font-size: 1.15rem; font-weight: 900; color: #f87171; margin-bottom: 0.5rem; }
            .lock-desc { font-size: 0.85rem; color: #94a3b8; line-height: 1.6; margin-bottom: 1.5rem; }
            .btn-pay-now { background: linear-gradient(135deg, #0284c7, #0369a1); color: #fff; text-decoration: none; padding: 0.8rem 1.5rem; border-radius: 12px; font-weight: 800; display: block; box-shadow: 0 4px 15px rgba(2, 132, 199, 0.4); margin-bottom: 0.75rem; }
        </style>
    </head>
    <body>
        <div class="lock-card">
            <div class="lock-icon">🔒</div>
            <div class="lock-title">اشتراک و شهریه شما منقضی شده است</div>
            <div class="lock-desc">برای دسترسی به بخش حضور و غیاب و ثبت جلسات، لطفاً شهریه دوره ماهانه خود را پرداخت و تمدید کنید.</div>
            <a href="payments.php" class="btn-pay-now">💳 پرداخت و تمدید آنلاین شهریه</a>
            <a href="dashboard.php" style="color: #94a3b8; font-size: 0.8rem; text-decoration: none; font-weight: 700;">بازگشت به پیشخوان کاربری</a>
        </div>
        <?php require_once __DIR__ . '/mobile_nav.php'; ?>
    </body>
    </html>
    <?php
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

$today_parts = explode('-', $today);
list($current_jy, $current_jm, $current_jd) = gregorian_to_jalali((int)$today_parts[0], (int)$today_parts[1], (int)$today_parts[2]);

$jalali_month_names = [
    1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد', 4 => 'تیر', 
    5 => 'مرداد', 6 => 'شهریور', 7 => 'مهر', 8 => 'آبان', 
    9 => 'آذر', 10 => 'دی', 11 => 'بهمن', 12 => 'اسفند'
];

$msg = '';

if ($is_admin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_attendance') {
    $session_date = trim($_POST['session_date'] ?? $today);
    $status_data  = $_POST['status'] ?? [];

    if (!empty($session_date) && is_array($status_data)) {
        $stmtSave = $pdo->prepare("
            INSERT INTO attendance (club_id, user_id, session_date, status, created_at)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE status = VALUES(status)
        ");
        foreach ($status_data as $student_id => $st) {
            $student_id = (int)$student_id;
            $st = in_array($st, ['present', 'absent', 'excused']) ? $st : 'absent';
            $stmtSave->execute([CURRENT_CLUB_ID, $student_id, $session_date, $st]);
        }
        $msg = 'حضور و غیاب ذخیره شد.';
    }
}

if ($is_admin) {
    $selected_date  = trim($_GET['date'] ?? $today);
    $selected_level = trim($_GET['level'] ?? '');

    $query = "SELECT id, full_name, phone, skill_level, subscription_expires_at FROM users WHERE club_id = ? AND role = 'student'";
    $params = [CURRENT_CLUB_ID];

    if ($user_role === 'coach') {
        $query .= " AND coach_id = ?";
        $params[] = $current_user['id'];
    }
    if (!empty($selected_level)) {
        $query .= " AND skill_level = ?";
        $params[] = $selected_level;
    }
    $query .= " ORDER BY full_name ASC";

    $stmtStudents = $pdo->prepare($query);
    $stmtStudents->execute($params);
    $students = $stmtStudents->fetchAll(PDO::FETCH_ASSOC);

    $stmtAtt = $pdo->prepare("SELECT user_id, status FROM attendance WHERE club_id = ? AND session_date = ?");
    $stmtAtt->execute([CURRENT_CLUB_ID, $selected_date]);
    $saved_attendance = $stmtAtt->fetchAll(PDO::FETCH_KEY_PAIR);
} else {
    $view_jy = (int)($_GET['j_year'] ?? $current_jy);
    $view_jm = (int)($_GET['j_month'] ?? $current_jm);

    if ($view_jm < 1) { $view_jm = 12; $view_jy--; }
    if ($view_jm > 12) { $view_jm = 1; $view_jy++; }

    if ($view_jm <= 6) $days_in_month = 31;
    elseif ($view_jm <= 11) $days_in_month = 30;
    else {
        list($t_gy, $t_gm, $t_gd) = jalali_to_gregorian($view_jy, 12, 30);
        list($c_jy, $c_jm, $c_jd) = gregorian_to_jalali($t_gy, $t_gm, $t_gd);
        $days_in_month = ($c_jm === 12 && $c_jd === 30) ? 30 : 29;
    }

    list($start_gy, $start_gm, $start_gd) = jalali_to_gregorian($view_jy, $view_jm, 1);
    list($end_gy, $end_gm, $end_gd) = jalali_to_gregorian($view_jy, $view_jm, $days_in_month);
    
    $start_date_g = sprintf('%04d-%02d-%02d', $start_gy, $start_gm, $start_gd);
    $end_date_g   = sprintf('%04d-%02d-%02d', $end_gy, $end_gm, $end_gd);

    $stmtMyMonth = $pdo->prepare("SELECT session_date, status FROM attendance WHERE user_id = ? AND club_id = ? AND session_date BETWEEN ? AND ?");
    $stmtMyMonth->execute([$current_user['id'], CURRENT_CLUB_ID, $start_date_g, $end_date_g]);
    $month_records = $stmtMyMonth->fetchAll(PDO::FETCH_KEY_PAIR);

    $w = (int)date('w', strtotime($start_date_g));
    $first_day_of_week = ($w + 1) % 7; 

    $prev_jm = $view_jm - 1; $prev_jy = $view_jy;
    if ($prev_jm < 1) { $prev_jm = 12; $prev_jy--; }
    $next_jm = $view_jm + 1; $next_jy = $view_jy;
    if ($next_jm > 12) { $next_jm = 1; $next_jy++; }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>حضور و غیاب | <?= htmlspecialchars(CURRENT_CLUB_NAME) ?></title>
    <style>
        :root { --primary: <?= htmlspecialchars(CURRENT_CLUB_THEME) ?>; --bg-dark: #0b1120; --card-bg: rgba(17, 24, 39, 0.85); --border-color: rgba(255, 255, 255, 0.08); --text-main: #f8fafc; --text-muted: #94a3b8; }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: system-ui, -apple-system, sans-serif; -webkit-tap-highlight-color: transparent; }
        body { background-color: var(--bg-dark); color: var(--text-main); min-height: 100vh; padding: 1rem 0.85rem calc(80px + env(safe-area-inset-bottom)) 0.85rem; }
        .container { max-width: 900px; margin: 0 auto; }
        .header-bar { display: flex; justify-content: space-between; align-items: center; background: rgba(30, 41, 59, 0.7); border: 1px solid var(--border-color); backdrop-filter: blur(12px); border-radius: 16px; padding: 0.85rem 1.1rem; margin-bottom: 1.25rem; }
        .btn-back { background: #1e293b; color: #38bdf8; border: 1px solid #334155; padding: 0.45rem 0.9rem; border-radius: 8px; text-decoration: none; font-size: 0.82rem; font-weight: 700; }
        .card { background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 18px; padding: 1.25rem; margin-bottom: 1.25rem; }
        .student-att-card { background: rgba(30, 41, 59, 0.5); border: 1px solid rgba(255, 255, 255, 0.06); border-radius: 14px; padding: 0.9rem; margin-bottom: 0.75rem; display: flex; flex-direction: column; gap: 0.6rem; }
        .student-info-row { display: flex; justify-content: space-between; align-items: center; }
        .status-options-mobile { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0.4rem; }
        .status-btn-mob { display: flex; align-items: center; justify-content: center; height: 38px; border-radius: 8px; border: 1px solid #334155; background: #1e293b; color: #94a3b8; font-size: 0.8rem; font-weight: 700; cursor: pointer; }
        .status-options-mobile input[type="radio"] { display: none; }
        .status-options-mobile input[type="radio"][value="present"]:checked + .status-btn-mob { background: #10b981; color: #fff; border-color: #10b981; }
        .status-options-mobile input[type="radio"][value="absent"]:checked + .status-btn-mob { background: #ef4444; color: #fff; border-color: #ef4444; }
        .status-options-mobile input[type="radio"][value="excused"]:checked + .status-btn-mob { background: #f59e0b; color: #fff; border-color: #f59e0b; }
        .btn-submit-sticky { position: sticky; bottom: 75px; background: linear-gradient(135deg, #0284c7, #0369a1); color: #fff; border: none; height: 48px; border-radius: 12px; font-weight: 800; font-size: 0.95rem; width: 100%; cursor: pointer; box-shadow: 0 6px 20px rgba(2, 132, 199, 0.4); z-index: 99; margin-top: 1rem; }
        .calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; }
        .cal-header-cell { text-align: center; font-size: 0.72rem; font-weight: 700; color: #94a3b8; padding: 0.4rem 0; background: #151f30; border-radius: 6px; }
        .cal-day-cell { min-height: 54px; background: #1a2234; border: 1px solid #243048; border-radius: 8px; padding: 4px 2px; display: flex; flex-direction: column; justify-content: space-between; align-items: center; }
        .cal-day-cell.empty { background: transparent; border-color: transparent; }
        .cal-day-cell.is-today { border: 2px solid #38bdf8; }
        .day-num { font-size: 0.8rem; font-weight: 800; color: #cbd5e1; }
        .cal-day-cell.present { background: rgba(16, 185, 129, 0.2); border-color: #10b981; }
        .cal-day-cell.present .day-status { background: #10b981; color: #fff; border-radius: 4px; font-size: 0.65rem; padding: 1px 3px; font-weight: 800; }
        .cal-day-cell.absent { background: rgba(239, 68, 68, 0.2); border-color: #ef4444; }
        .cal-day-cell.absent .day-status { background: #ef4444; color: #fff; border-radius: 4px; font-size: 0.65rem; padding: 1px 3px; }
        .alert-success { background: rgba(16, 185, 129, 0.15); border: 1px solid #10b981; color: #34d399; padding: 0.75rem; border-radius: 10px; margin-bottom: 1rem; font-size: 0.85rem; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header-bar">
            <div>
                <h2 style="font-size: 1.05rem; color: #38bdf8;">📋 <?= $is_admin ? 'حضور و غیاب کلاسی' : 'تقویم حضور و غیاب من' ?></h2>
                <div style="font-size: 0.75rem; color: #64748b;"><?= htmlspecialchars(CURRENT_CLUB_NAME) ?></div>
            </div>
            <a href="dashboard.php" class="btn-back">بازگشت ↵</a>
        </div>

        <?php if (!empty($msg)): ?><div class="alert-success">✓ <?= htmlspecialchars($msg) ?></div><?php endif; ?>

        <?php if ($is_admin): ?>
            <form method="GET" style="display:flex; gap:0.5rem; margin-bottom:1rem; flex-wrap:wrap;">
                <input type="date" name="date" value="<?= htmlspecialchars($selected_date) ?>" style="flex:1; height:42px; background:#1e293b; border:1px solid #334155; border-radius:8px; color:#fff; padding:0 0.5rem;" dir="ltr">
                <select name="level" style="flex:1; height:42px; background:#1e293b; border:1px solid #334155; border-radius:8px; color:#fff; padding:0 0.5rem;">
                    <option value="">همه سطوح</option>
                    <option value="مبتدی" <?= $selected_level === 'مبتدی' ? 'selected' : '' ?>>مبتدی</option>
                    <option value="پیشرفته" <?= $selected_level === 'پیشرفته' ? 'selected' : '' ?>>پیشرفته</option>
                    <option value="فری استایل" <?= $selected_level === 'فری استایل' ? 'selected' : '' ?>>فری استایل</option>
                    <option value="سرعت" <?= $selected_level === 'سرعت' ? 'selected' : '' ?>>سرعت</option>
                </select>
                <button type="submit" style="background:var(--primary); color:#fff; border:none; padding:0 1rem; border-radius:8px; font-weight:700; height:42px;">نمایش</button>
            </form>

            <form method="POST">
                <input type="hidden" name="action" value="save_attendance">
                <input type="hidden" name="session_date" value="<?= htmlspecialchars($selected_date) ?>">
                <?php if (empty($students)): ?>
                    <div class="card" style="text-align:center; color:#64748b; padding:2rem;">هنرجویی یافت نشد.</div>
                <?php else: ?>
                    <?php foreach ($students as $st): $cur_st = $saved_attendance[$st['id']] ?? 'absent'; ?>
                        <div class="student-att-card">
                            <div class="student-info-row">
                                <strong style="color:#fff; font-size:0.95rem;"><?= htmlspecialchars($st['full_name'] ?: 'بدون نام') ?></strong>
                                <span style="font-size:0.75rem; color:#38bdf8; background:#1e293b; padding:2px 6px; border-radius:4px;"><?= htmlspecialchars($st['skill_level'] ?? 'مبتدی') ?></span>
                            </div>
                            <div class="status-options-mobile">
                                <label><input type="radio" name="status[<?= $st['id'] ?>]" value="present" <?= $cur_st === 'present' ? 'checked' : '' ?>><span class="status-btn-mob">✓ حاضر</span></label>
                                <label><input type="radio" name="status[<?= $st['id'] ?>]" value="absent" <?= $cur_st === 'absent' ? 'checked' : '' ?>><span class="status-btn-mob">✕ غایب</span></label>
                                <label><input type="radio" name="status[<?= $st['id'] ?>]" value="excused" <?= $cur_st === 'excused' ? 'checked' : '' ?>><span class="status-btn-mob">⏱️ موجه</span></label>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <button type="submit" class="btn-submit-sticky">💾 ذخیره لیست حضور و غیاب</button>
                <?php endif; ?>
            </form>
        <?php else: ?>
            <div class="card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
                    <a href="?j_year=<?= $prev_jy ?>&j_month=<?= $prev_jm ?>" style="color:#38bdf8; text-decoration:none; font-size:0.85rem;">← ماه قبل</a>
                    <strong style="color:#38bdf8; font-size:1rem;">📅 <?= $jalali_month_names[$view_jm] ?> <?= $view_jy ?></strong>
                    <a href="?j_year=<?= $next_jy ?>&j_month=<?= $next_jm ?>" style="color:#38bdf8; text-decoration:none; font-size:0.85rem;">ماه بعد →</a>
                </div>
                <div class="calendar-grid">
                    <div class="cal-header-cell">ش</div><div class="cal-header-cell">۱ش</div><div class="cal-header-cell">۲ش</div><div class="cal-header-cell">۳ش</div><div class="cal-header-cell">۴ش</div><div class="cal-header-cell">۵ش</div><div class="cal-header-cell" style="color:#f87171;">ج</div>
                    <?php for ($k = 0; $k < $first_day_of_week; $k++): ?><div class="cal-day-cell empty"></div><?php endfor; ?>
                    <?php for ($d = 1; $d <= $days_in_month; $d++): 
                        list($g_y, $g_m, $g_d) = jalali_to_gregorian($view_jy, $view_jm, $d);
                        $date_str = sprintf('%04d-%02d-%02d', $g_y, $g_m, $g_d);
                        $is_today_cell = ($view_jy === $current_jy && $view_jm === $current_jm && $d === $current_jd);
                        $status = $month_records[$date_str] ?? null;
                    ?>
                        <div class="cal-day-cell <?= $status ? $status : '' ?> <?= $is_today_cell ? 'is-today' : '' ?>">
                            <span class="day-num"><?= $d ?></span>
                            <?php if ($status === 'present'): ?><span class="day-status">✓ حاضر</span><?php elseif ($status === 'absent'): ?><span class="day-status">✕ غایب</span><?php endif; ?>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php require_once __DIR__ . '/mobile_nav.php'; ?>
</body>
</html>