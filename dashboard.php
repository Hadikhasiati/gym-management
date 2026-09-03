<?php
// dashboard.php
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

// بازگشت ادمین از اکانت آزمایشی به مدیریت
if (isset($_GET['revert_admin']) && isset($_SESSION['impersonator_admin_id'])) {
    $_SESSION['user_id'] = $_SESSION['impersonator_admin_id'];
    unset($_SESSION['impersonator_admin_id']);
    header("Location: dashboard.php");
    exit;
}

$current_user_id = (int)($_SESSION['user_id'] ?? 0);
if ($current_user_id <= 0) {
    header("Location: login.php");
    exit;
}

$stmtUser = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$stmtUser->execute([$current_user_id]);
$current_user = $stmtUser->fetch(PDO::FETCH_ASSOC);

if (!$current_user) {
    session_destroy();
    header("Location: login.php");
    exit;
}

if (!defined('CURRENT_CLUB_ID')) define('CURRENT_CLUB_ID', (int)($current_user['club_id'] ?? 1));
if (!defined('CURRENT_CLUB_NAME')) define('CURRENT_CLUB_NAME', 'باشگاه رادین اسکیت');
if (!defined('CURRENT_CLUB_THEME')) define('CURRENT_CLUB_THEME', '#0284c7');

$user_role = $current_user['role'] ?? 'student';
$is_admin = ($user_role === 'admin');
$is_coach = ($user_role === 'coach');
$is_student = ($user_role === 'student');
$is_superadmin = ($is_admin && CURRENT_CLUB_ID === 1);
$today = date('Y-m-d');

// ==========================================
// توابع تاریخ شمسی و محاسبه دقیق سن شمسی
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

$today_parts = explode('-', $today);
list($current_jy, $current_jm, $current_jd) = gregorian_to_jalali((int)$today_parts[0], (int)$today_parts[1], (int)$today_parts[2]);

if (!function_exists('to_jalali_date')) {
    function to_jalali_date(?string $g_date): string {
        if (empty($g_date)) return 'ثبت نشده';
        $parts = explode('-', substr($g_date, 0, 10));
        if (count($parts) !== 3) return 'نامعتبر';
        list($jy, $jm, $jd) = gregorian_to_jalali((int)$parts[0], (int)$parts[1], (int)$parts[2]);
        return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
    }
}

// محاسبه دقیق سن بر اساس تقویم خورشیدی / شمسی
if (!function_exists('calculate_user_age_jalali')) {
    function calculate_user_age_jalali(?string $birth_input, int $cur_jy, int $cur_jm, int $cur_jd): string {
        if (empty($birth_input) || $birth_input === '-' || $birth_input === '0000-00-00') {
            return 'ثبت نشده';
        }
        
        $birth_input = trim($birth_input);
        $b_jy = 0;
        $b_jm = 1;
        $b_jd = 1;
        $has_exact_date = false;

        // حالت ۱: ذخیره به صورت تاریخ میلادی در دیتابیس (YYYY-MM-DD)
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $birth_input, $m)) {
            $gy = (int)$m[1];
            $gm = (int)$m[2];
            $gd = (int)$m[3];
            if ($gy >= 1900) {
                list($b_jy, $b_jm, $b_jd) = gregorian_to_jalali($gy, $gm, $gd);
                $has_exact_date = true;
            } else {
                // اگر با خط تیره سال شمسی ثبت شده باشد (مثلاً 1393-04-12)
                $b_jy = $gy;
                $b_jm = $gm;
                $b_jd = $gd;
                $has_exact_date = true;
            }
        }
        // حالت ۲: ذخیره به صورت تاریخ کامل شمسی (13xx/mm/dd یا 14xx/mm/dd)
        elseif (preg_match('/^(13\d{2}|14\d{2})[\/\-](\d{1,2})[\/\-](\d{1,2})$/', $birth_input, $m)) {
            $b_jy = (int)$m[1];
            $b_jm = (int)$m[2];
            $b_jd = (int)$m[3];
            $has_exact_date = true;
        }
        // حالت ۳: فقط سال تولد شمسی وارد شده باشد (مثلاً 1391)
        elseif (preg_match('/^(13\d{2}|14\d{2})$/', $birth_input, $m)) {
            $b_jy = (int)$m[1];
            $age = $cur_jy - $b_jy;
            return max(0, $age) . ' سال';
        }
        // حالت ۴: سن مستقیم عددی وارد شده باشد (مثلاً 9)
        elseif (is_numeric($birth_input) && (int)$birth_input > 0 && (int)$birth_input < 110) {
            return (int)$birth_input . ' سال';
        }

        // محاسبه دقیق با در نظر گرفتن ماه و روز تولد شمسی
        if ($has_exact_date && $b_jy > 1300 && $b_jy <= $cur_jy) {
            $age = $cur_jy - $b_jy;
            // اگر هنوز روز تولد در سال جاری شمسی نرسیده باشد
            if ($cur_jm < $b_jm || ($cur_jm === $b_jm && $cur_jd < $b_jd)) {
                $age--;
            }
            return max(0, $age) . ' سال';
        }

        return 'ثبت نشده';
    }
}

// واکشی اطلاعیه فعال
$notices = [];
try {
    $stmtN = $pdo->prepare("
        SELECT title, content FROM notices 
        WHERE club_id = ? AND (expires_at >= ? OR expires_at IS NULL) 
        ORDER BY id DESC LIMIT 1
    ");
    $stmtN->execute([CURRENT_CLUB_ID, $today]);
    $notices = $stmtN->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ۱. آماده‌سازی آمار مدیریت
if ($is_admin) {
    $total_students = 0; $active_students = 0; $total_coaches = 0; $monthly_income = 0; $recent_users = [];
    try {
        $stmtUsers = $pdo->prepare("SELECT subscription_expires_at FROM users WHERE club_id = ? AND role = 'student'");
        $stmtUsers->execute([CURRENT_CLUB_ID]);
        $all_students = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);
        $total_students = count($all_students);
        foreach ($all_students as $st) {
            if (!empty($st['subscription_expires_at']) && $st['subscription_expires_at'] >= $today) $active_students++;
        }

        $stmtCoaches = $pdo->prepare("SELECT COUNT(*) FROM users WHERE club_id = ? AND role = 'coach'");
        $stmtCoaches->execute([CURRENT_CLUB_ID]);
        $total_coaches = (int)$stmtCoaches->fetchColumn();

        $month_start = date('Y-m-01');
        $stmtPay = $pdo->prepare("SELECT SUM(amount) FROM payments WHERE club_id = ? AND status = 'success' AND created_at >= ?");
        $stmtPay->execute([CURRENT_CLUB_ID, $month_start]);
        $monthly_income = (int)($stmtPay->fetchColumn() ?: 0);

        $stmtRecent = $pdo->prepare("SELECT full_name, phone, skill_level, subscription_expires_at FROM users WHERE club_id = ? AND role = 'student' ORDER BY id DESC LIMIT 5");
        $stmtRecent->execute([CURRENT_CLUB_ID]);
        $recent_users = $stmtRecent->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

// ۲. آماده‌سازی آمار مربی
} elseif ($is_coach) {
    $my_students_count = 0; $my_classes_count = 0; $my_classes = [];
    try {
        $stmtMyStudents = $pdo->prepare("SELECT COUNT(*) FROM users WHERE club_id = ? AND coach_id = ? AND role = 'student'");
        $stmtMyStudents->execute([CURRENT_CLUB_ID, $current_user_id]);
        $my_students_count = (int)$stmtMyStudents->fetchColumn();

        $stmtMyClasses = $pdo->prepare("
            SELECT c.*, (SELECT COUNT(*) FROM users u WHERE u.class_id = c.id) as student_count 
            FROM classes c 
            WHERE c.club_id = ? AND c.coach_id = ? 
            ORDER BY c.id DESC
        ");
        $stmtMyClasses->execute([CURRENT_CLUB_ID, $current_user_id]);
        $my_classes = $stmtMyClasses->fetchAll(PDO::FETCH_ASSOC);
        $my_classes_count = count($my_classes);
    } catch (Exception $e) {}

// ۳. آماده‌سازی آمار هنرجو
} else {
    $my_id = (int)$current_user['id'];
    $my_sub_exp = $current_user['subscription_expires_at'] ?? null;
    $is_sub_valid = (!empty($my_sub_exp) && $my_sub_exp >= $today);

    $days_left = 0;
    if ($is_sub_valid && $my_sub_exp) {
        $diff = strtotime($my_sub_exp) - strtotime($today);
        $days_left = max(0, (int)round($diff / 86400));
    }

    $my_month_presents = 0;
    $my_total_sessions = 0;
    $coach_name = 'تعیین نشده';
    $class_title = 'عمومی';
    
    // سن شمسی کاربر
    $student_age_jalali = calculate_user_age_jalali($current_user['birth_date'] ?? null, $current_jy, $current_jm, $current_jd);

    try {
        if (!empty($current_user['coach_id'])) {
            $stmtCName = $pdo->prepare("SELECT full_name FROM users WHERE id = ? LIMIT 1");
            $stmtCName->execute([$current_user['coach_id']]);
            $coach_name = $stmtCName->fetchColumn() ?: 'تعیین نشده';
        }

        if (!empty($current_user['class_id'])) {
            $stmtClsName = $pdo->prepare("SELECT title FROM classes WHERE id = ? LIMIT 1");
            $stmtClsName->execute([$current_user['class_id']]);
            $class_title = $stmtClsName->fetchColumn() ?: 'عمومی';
        }

        $month_start = date('Y-m-01');
        $stmtMyAtt = $pdo->prepare("SELECT status FROM attendance WHERE club_id = ? AND user_id = ? AND session_date >= ?");
        $stmtMyAtt->execute([CURRENT_CLUB_ID, $my_id, $month_start]);
        $my_att_records = $stmtMyAtt->fetchAll(PDO::FETCH_ASSOC);
        $my_total_sessions = count($my_att_records);

        foreach ($my_att_records as $rec) {
            if ($rec['status'] === 'present') $my_month_presents++;
        }
    } catch (Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>پیشخوان سامانه | <?= htmlspecialchars(CURRENT_CLUB_NAME, ENT_QUOTES, 'UTF-8') ?></title>
    
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#0b1120">

    <style>
        :root {
            --primary: <?= htmlspecialchars(CURRENT_CLUB_THEME, ENT_QUOTES, 'UTF-8') ?>;
            --primary-glow: <?= htmlspecialchars(CURRENT_CLUB_THEME, ENT_QUOTES, 'UTF-8') ?>40;
            --bg-dark: #0b1120;
            --card-bg: rgba(17, 24, 39, 0.85);
            --border-color: rgba(255, 255, 255, 0.08);
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; font-family: system-ui, -apple-system, sans-serif; -webkit-tap-highlight-color: transparent; }
        body { background-color: var(--bg-dark); color: var(--text-main); min-height: 100vh; padding: 1.2rem 0.9rem calc(85px + env(safe-area-inset-bottom)) 0.9rem; line-height: 1.5; }
        .container { max-width: 1050px; margin: 0 auto; }

        .impersonate-bar {
            background: linear-gradient(135deg, #f59e0b, #d97706); color: #000; padding: 0.75rem 1.25rem;
            border-radius: 14px; margin-bottom: 1.25rem; display: flex; justify-content: space-between;
            align-items: center; font-weight: 800; font-size: 0.85rem;
        }
        .btn-revert { background: #000; color: #fff; text-decoration: none; padding: 0.35rem 0.85rem; border-radius: 8px; font-size: 0.8rem; }

        /* هدر بالای صفحه */
        .header-card {
            background: linear-gradient(135deg, rgba(30, 41, 59, 0.7), rgba(15, 23, 42, 0.9));
            border: 1px solid var(--border-color); backdrop-filter: blur(12px); border-radius: 18px;
            padding: 1rem 1.25rem; display: flex; justify-content: space-between; align-items: center;
            gap: 1rem; margin-bottom: 1.25rem; box-shadow: 0 8px 25px rgba(0, 0, 0, 0.35);
        }
        .club-branding { display: flex; align-items: center; gap: 0.85rem; }
        .club-badge-icon {
            width: 44px; height: 44px; border-radius: 12px;
            background: linear-gradient(135deg, var(--primary), #0369a1);
            display: flex; align-items: center; justify-content: center;
            font-size: 1.4rem; box-shadow: 0 4px 15px rgba(2, 132, 199, 0.3);
        }
        .club-name { font-size: 1.1rem; font-weight: 800; color: #fff; }
        .user-welcome { font-size: 0.8rem; color: var(--text-muted); margin-top: 2px; }

        .header-actions { display: flex; align-items: center; gap: 0.5rem; }
        .btn-header { padding: 0.45rem 0.85rem; border-radius: 8px; font-size: 0.8rem; font-weight: 700; text-decoration: none; }
        .btn-super { background: rgba(99, 102, 241, 0.15); color: #a5b4fc; border: 1px solid #6366f1; }
        .btn-logout { background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.3); }

        .notice-box {
            background: linear-gradient(135deg, rgba(244, 63, 94, 0.12), rgba(15, 23, 42, 0.6));
            border: 1px solid rgba(244, 63, 94, 0.3); border-radius: 14px; padding: 0.9rem 1.1rem;
            margin-bottom: 1.25rem; display: flex; align-items: flex-start; gap: 0.75rem;
        }
        .notice-title { font-size: 0.92rem; font-weight: 800; color: #fb7185; margin-bottom: 0.2rem; }
        .notice-desc { font-size: 0.82rem; color: #cbd5e1; }

        /* ======================================================== */
        /* کادر سفید رنگ اختصاصی مشخصات هنرجو                      */
        /* ======================================================== */
        .white-profile-card {
            background: #ffffff;
            color: #0f172a;
            border-radius: 20px;
            padding: 1.35rem 1.4rem;
            margin-bottom: 1.25rem;
            box-shadow: 0 12px 35px rgba(0, 0, 0, 0.3), 0 0 0 1px rgba(255, 255, 255, 0.1);
            position: relative;
            overflow: hidden;
        }
        .white-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1.5px dashed #e2e8f0;
            padding-bottom: 0.85rem;
            margin-bottom: 1rem;
        }
        .white-card-title {
            font-size: 0.85rem;
            font-weight: 800;
            color: #64748b;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }
        .white-card-badge {
            background: #f1f5f9;
            color: #334155;
            font-size: 0.75rem;
            font-weight: 800;
            padding: 0.25rem 0.65rem;
            border-radius: 6px;
        }
        
        .white-card-grid {
            display: grid;
            grid-template-columns: 1.2fr 0.8fr 1fr;
            gap: 0.75rem;
        }
        .white-item {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }
        .white-item-label {
            font-size: 0.75rem;
            font-weight: 700;
            color: #64748b;
        }
        .white-item-val {
            font-size: 1.05rem;
            font-weight: 900;
            color: #0f172a;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .white-level-badge {
            background: linear-gradient(135deg, #0284c7, #0369a1);
            color: #ffffff;
            padding: 0.3rem 0.75rem;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 800;
            display: inline-block;
            width: fit-content;
            box-shadow: 0 4px 10px rgba(2, 132, 199, 0.25);
        }

        /* کارت وضعیت اشتراک هنرجو */
        .student-sub-card {
            background: linear-gradient(135deg, #1e293b, #0f172a);
            border: 1px solid var(--border-color);
            border-radius: 18px;
            padding: 1.15rem 1.3rem;
            margin-bottom: 1.25rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.85rem;
        }
        .badge-sub { padding: 0.35rem 0.75rem; border-radius: 8px; font-size: 0.78rem; font-weight: 800; display: inline-block; }
        .badge-sub-active { background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid #10b981; }
        .badge-sub-expired { background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid #ef4444; }

        .student-stats-row { display: grid; grid-template-columns: 1fr 1fr; gap: 0.85rem; margin-bottom: 1.25rem; }
        .s-stat-box {
            background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 16px;
            padding: 1.1rem; text-align: center;
        }
        .s-stat-val { font-size: 1.6rem; font-weight: 800; color: #fff; margin-top: 0.2rem; }

        /* استایل‌های بخش ادمین */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0.85rem; margin-bottom: 1.5rem; }
        .stat-card {
            background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 14px; padding: 1.1rem;
            display: flex; align-items: center; justify-content: space-between; position: relative; overflow: hidden;
        }
        .stat-card::before { content: ''; position: absolute; top: 0; right: 0; width: 4px; height: 100%; background: var(--primary); }
        .stat-info .stat-label { font-size: 0.8rem; color: var(--text-muted); font-weight: 600; }
        .stat-info .stat-value { font-size: 1.45rem; font-weight: 800; color: #fff; margin-top: 0.2rem; }
        .section-title { font-size: 1rem; font-weight: 800; color: #38bdf8; margin-bottom: 0.85rem; display: flex; align-items: center; gap: 0.4rem; }
        
        .menu-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 1rem; margin-bottom: 1.75rem; }
        .menu-card {
            background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 16px; padding: 1.1rem;
            text-decoration: none; color: #fff; display: flex; align-items: center; gap: 1rem; transition: 0.2s;
        }
        .menu-card:hover { transform: translateY(-3px); border-color: rgba(255,255,255,0.2); }
        .menu-icon-box { width: 50px; height: 50px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 1.6rem; flex-shrink: 0; }
        .icon-users { background: linear-gradient(135deg, #6366f1, #3b82f6); }
        .icon-coaches { background: linear-gradient(135deg, #8b5cf6, #6d28d9); }
        .icon-attendance { background: linear-gradient(135deg, #10b981, #059669); }
        .icon-exercises { background: linear-gradient(135deg, #f59e0b, #d97706); }
        .icon-shop { background: linear-gradient(135deg, #d946ef, #8b5cf6); }
        .icon-notices { background: linear-gradient(135deg, #f43f5e, #e11d48); }
        .icon-settings { background: linear-gradient(135deg, #06b6d4, #0284c7); }

        .card-table { background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 16px; padding: 1.25rem; }
        table { width: 100%; border-collapse: collapse; text-align: right; font-size: 0.85rem; }
        th, td { padding: 0.8rem 0.65rem; border-bottom: 1px solid rgba(255, 255, 255, 0.05); }
        th { color: var(--text-muted); }

        /* نوار ناوبری پایین ۵ گزینه‌ای */
        .app-bottom-nav {
            position: fixed; bottom: 0; left: 0; right: 0; height: 65px;
            background: rgba(15, 23, 42, 0.95); backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px); border-top: 1px solid rgba(255, 255, 255, 0.08);
            display: flex; justify-content: space-around; align-items: center;
            z-index: 99999; padding: 0 4px; padding-bottom: env(safe-area-inset-bottom);
            box-shadow: 0 -10px 25px rgba(0, 0, 0, 0.5);
        }
        .app-nav-item {
            flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center;
            text-decoration: none; color: #94a3b8; font-size: 0.7rem; font-weight: 700; gap: 3px; padding: 6px 0;
            transition: all 0.2s ease;
        }
        .app-nav-item .nav-icon { font-size: 1.3rem; line-height: 1; }
        .app-nav-item.active { color: #38bdf8; }

        .app-nav-center {
            position: relative; top: -14px; flex: 1; display: flex; flex-direction: column;
            align-items: center; justify-content: center; text-decoration: none;
        }
        .app-center-btn {
            width: 50px; height: 50px; border-radius: 50%;
            background: linear-gradient(135deg, #0284c7, #0369a1);
            border: 4px solid #0b1120; display: flex; align-items: center; justify-content: center;
            font-size: 1.45rem; color: #fff; box-shadow: 0 6px 20px rgba(2, 132, 199, 0.5);
        }
        .app-center-label { font-size: 0.7rem; font-weight: 800; color: #38bdf8; margin-top: 2px; }

        @media (max-width: 480px) {
            .white-card-grid { grid-template-columns: 1fr 1fr; gap: 1rem; }
            .white-item:nth-child(3) { grid-column: span 2; }
        }
    </style>
</head>
<body>
    <div class="container">
        
        <?php if (isset($_SESSION['impersonator_admin_id'])): ?>
            <div class="impersonate-bar">
                <span>⚠️ مشاهده به عنوان کاربر آزمایشی</span>
                <a href="dashboard.php?revert_admin=1" class="btn-revert">↩ بازگشت به مدیریت</a>
            </div>
        <?php endif; ?>

        <!-- هدر -->
        <header class="header-card">
            <div class="club-branding">
                <div class="club-badge-icon">🛹</div>
                <div>
                    <h1 class="club-name"><?= htmlspecialchars(CURRENT_CLUB_NAME, ENT_QUOTES, 'UTF-8') ?></h1>
                    <div class="user-welcome">
                        سلام، <strong><?= htmlspecialchars($current_user['full_name'] ?: $current_user['phone'], ENT_QUOTES, 'UTF-8') ?></strong>
                        <?php if ($is_admin): ?>
                            (<span style="color:#38bdf8;">مدیر</span>)
                        <?php elseif ($is_coach): ?>
                            (<span style="color:#a5b4fc;">مربی</span>)
                        <?php else: ?>
                            (<span style="color:#34d399;">هنرجو</span>)
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="header-actions">
                <?php if ($is_superadmin): ?>
                    <a href="superadmin.php" class="btn-header btn-super">⚡ سوپرادمین</a>
                <?php endif; ?>
                <a href="logout.php" class="btn-header btn-logout">خروج ✕</a>
            </div>
        </header>

        <!-- پیام عمومی فعال -->
        <?php if (!empty($notices)): ?>
            <div class="notice-box">
                <div style="font-size:1.3rem;">📢</div>
                <div>
                    <div class="notice-title"><?= htmlspecialchars($notices[0]['title'], ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="notice-desc"><?= nl2br(htmlspecialchars($notices[0]['content'], ENT_QUOTES, 'UTF-8')) ?></div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($is_admin): ?>
            <!-- ======================= پیشخوان مدیریت ======================= -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-info">
                        <div class="stat-label">👥 کل هنرجویان</div>
                        <div class="stat-value"><?= number_format($total_students) ?> <span style="font-size:0.8rem; color:var(--text-muted);">نفر</span></div>
                    </div>
                    <div style="font-size:1.8rem; opacity:0.8;">👥</div>
                </div>
                <div class="stat-card">
                    <div class="stat-info">
                        <div class="stat-label">✓ شهریه معتبر</div>
                        <div class="stat-value" style="color:#34d399;"><?= number_format($active_students) ?></div>
                    </div>
                    <div style="font-size:1.8rem; opacity:0.8;">✨</div>
                </div>
                <div class="stat-card">
                    <div class="stat-info">
                        <div class="stat-label">🥋 مربیان فعال</div>
                        <div class="stat-value" style="color:#a5b4fc;"><?= number_format($total_coaches) ?></div>
                    </div>
                    <div style="font-size:1.8rem; opacity:0.8;">🥋</div>
                </div>
                <div class="stat-card">
                    <div class="stat-info">
                        <div class="stat-label">💳 درآمد این ماه</div>
                        <div class="stat-value" style="color:#fbbf24; font-size:1.25rem;"><?= number_format($monthly_income) ?> <span style="font-size:0.75rem;">تومان</span></div>
                    </div>
                    <div style="font-size:1.8rem; opacity:0.8;">💰</div>
                </div>
            </div>

            <div class="section-title">⚡ منوهای مدیریت</div>
            <div class="menu-grid">
                <a href="users.php" class="menu-card">
                    <div class="menu-icon-box icon-users">👥</div>
                    <div>
                        <div style="font-size:0.95rem; font-weight:800;">مدیریت هنرجویان و شهریه</div>
                        <div style="font-size:0.78rem; color:var(--text-muted);">تمدید اعتبار، لیست اعضا و اکسل</div>
                    </div>
                </a>
                <a href="coaches.php" class="menu-card">
                    <div class="menu-icon-box icon-coaches">🥋</div>
                    <div>
                        <div style="font-size:0.95rem; font-weight:800;">مدیریت مربیان و سانس‌ها</div>
                        <div style="font-size:0.78rem; color:var(--text-muted);">تعریف مربی، سانس کلاسی و شاگردان</div>
                    </div>
                </a>
                <a href="attendance.php" class="menu-card">
                    <div class="menu-icon-box icon-attendance">📋</div>
                    <div>
                        <div style="font-size:0.95rem; font-weight:800;">حضور و غیاب کلاسی</div>
                        <div style="font-size:0.78rem; color:var(--text-muted);">ثبت سریع جلسات و گزارش غایبین</div>
                    </div>
                </a>
                <a href="exercises.php" class="menu-card">
                    <div class="menu-icon-box icon-exercises">🛹</div>
                    <div>
                        <div style="font-size:0.95rem; font-weight:800;">حرکات و سطوح تمرینی</div>
                        <div style="font-size:0.78rem; color:var(--text-muted);">آموزش‌های تخصصی و نکات مربی</div>
                    </div>
                </a>
                <a href="shop.php" class="menu-card">
                    <div class="menu-icon-box icon-shop">🛒</div>
                    <div>
                        <div style="font-size:0.95rem; font-weight:800;">فروشگاه تجهیزات</div>
                        <div style="font-size:0.78rem; color:var(--text-muted);">مدیریت محصولات و سفارشات</div>
                    </div>
                </a>
                <a href="notices.php" class="menu-card">
                    <div class="menu-icon-box icon-notices">📢</div>
                    <div>
                        <div style="font-size:0.95rem; font-weight:800;">تابلو اعلانات و پیام‌ها</div>
                        <div style="font-size:0.78rem; color:var(--text-muted);">ارسال اطلاعیه با تاریخ انقضا</div>
                    </div>
                </a>
                <a href="settings.php" class="menu-card">
                    <div class="menu-icon-box icon-settings">⚙️</div>
                    <div>
                        <div style="font-size:0.95rem; font-weight:800;">تنظیمات و درگاه پرداخت</div>
                        <div style="font-size:0.78rem; color:var(--text-muted);">زرین‌پال، پترن پیامک و تم</div>
                    </div>
                </a>
            </div>

            <div class="card-table">
                <div class="section-title">🆕 آخرین هنرجویان ثبت‌نام شده</div>
                <div style="overflow-x:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>نام</th>
                                <th>تماس</th>
                                <th>سطح</th>
                                <th>انقضای شهریه</th>
                                <th>وضعیت</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_users as $ru): 
                                $is_act = (!empty($ru['subscription_expires_at']) && $ru['subscription_expires_at'] >= $today);
                            ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($ru['full_name'] ?: 'بدون نام') ?></strong></td>
                                    <td><span style="font-family:monospace; color:var(--text-muted);"><?= htmlspecialchars($ru['phone']) ?></span></td>
                                    <td><span style="background:#1e293b; padding:2px 6px; border-radius:4px; font-size:0.75rem;"><?= htmlspecialchars($ru['skill_level'] ?? 'مبتدی') ?></span></td>
                                    <td><strong style="color:#38bdf8; font-family:monospace;"><?= to_jalali_date($ru['subscription_expires_at']) ?></strong></td>
                                    <td><?= $is_act ? '<span style="color:#34d399;">معتبر</span>' : '<span style="color:#f87171;">منقضی</span>' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php elseif ($is_coach): ?>
            <!-- ======================= پیشخوان مربی ======================= -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-info">
                        <div class="stat-label">👥 شاگردان شما</div>
                        <div class="stat-value" style="color:#a5b4fc;"><?= number_format($my_students_count) ?> <span style="font-size:0.8rem;">هنرجو</span></div>
                    </div>
                    <div style="font-size:1.8rem;">👥</div>
                </div>
                <div class="stat-card">
                    <div class="stat-info">
                        <div class="stat-label">⏰ سانس‌های کلاسی</div>
                        <div class="stat-value" style="color:#38bdf8;"><?= number_format($my_classes_count) ?> <span style="font-size:0.8rem;">سانس</span></div>
                    </div>
                    <div style="font-size:1.8rem;">⏰</div>
                </div>
            </div>

            <div class="section-title">⚡ میز کار مربی</div>
            <div class="menu-grid">
                <a href="attendance.php" class="menu-card">
                    <div class="menu-icon-box icon-attendance">📋</div>
                    <div>
                        <div style="font-size:0.95rem; font-weight:800;">ثبت حضور و غیاب</div>
                        <div style="font-size:0.78rem; color:var(--text-muted);">ثبت سریع حاضرین و غایبین</div>
                    </div>
                </a>
                <a href="exercises.php" class="menu-card">
                    <div class="menu-icon-box icon-exercises">🛹</div>
                    <div>
                        <div style="font-size:0.95rem; font-weight:800;">حرکات و آموزش‌ها</div>
                        <div style="font-size:0.78rem; color:var(--text-muted);">بانک تمرینات و نکات مربی</div>
                    </div>
                </a>
                <a href="users.php" class="menu-card">
                    <div class="menu-icon-box icon-users">👥</div>
                    <div>
                        <div style="font-size:0.95rem; font-weight:800;">شاگردان من</div>
                        <div style="font-size:0.78rem; color:var(--text-muted);">اسامی، سطح و شماره تماس</div>
                    </div>
                </a>
                <a href="notices.php" class="menu-card">
                    <div class="menu-icon-box icon-notices">📢</div>
                    <div>
                        <div style="font-size:0.95rem; font-weight:800;">تابلو اعلانات</div>
                        <div style="font-size:0.78rem; color:var(--text-muted);">پیام‌ها و اطلاعیه‌ها</div>
                    </div>
                </a>
            </div>

        <?php else: ?>
            <!-- ======================================================== -->
            <!-- صفحه اختصاصی هنرجو با کادر سفید رنگ مشخصات                -->
            <!-- ======================================================== -->

            <!-- ۱. کادر سفید رنگ مشخصات اصلی هنرجو -->
            <div class="white-profile-card">
                <div class="white-card-header">
                    <div class="white-card-title">
                        <span>👤 کارت مشخصات هنرجو</span>
                    </div>
                    <span class="white-card-badge">باشگاه <?= htmlspecialchars(CURRENT_CLUB_NAME) ?></span>
                </div>

                <div class="white-card-grid">
                    <div class="white-item">
                        <span class="white-item-label">نام و نام خانوادگی</span>
                        <span class="white-item-val"><?= htmlspecialchars($current_user['full_name'] ?: 'بدون نام', ENT_QUOTES, 'UTF-8') ?></span>
                    </div>

                    <div class="white-item">
                        <span class="white-item-label">سن (شمسی)</span>
                        <span class="white-item-val" style="color: #0369a1;"><?= $student_age_jalali ?></span>
                    </div>

                    <div class="white-item">
                        <span class="white-item-label">سطح آموزشی</span>
                        <div>
                            <span class="white-level-badge">
                                <?= htmlspecialchars($current_user['skill_level'] ?? 'مبتدی', ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ۲. وضعیت شهریه، مربی و سانس -->
            <div class="student-sub-card">
                <div>
                    <div style="font-size:0.8rem; color:var(--text-muted);">
                        مربی: <strong style="color:#fff;"><?= htmlspecialchars($coach_name, ENT_QUOTES, 'UTF-8') ?></strong> &bull;
                        سانس: <strong style="color:#fff;"><?= htmlspecialchars($class_title, ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                    <div style="font-size:0.8rem; color:var(--text-muted); margin-top:0.35rem;">
                        اعتبار شهریه: <strong style="color:#38bdf8; font-family:monospace;"><?= to_jalali_date($my_sub_exp) ?></strong>
                    </div>
                </div>

                <div style="text-align:left;">
                    <div style="margin-bottom:0.5rem;">
                        <?= $is_sub_valid 
                            ? "<span class='badge-sub badge-sub-active'>✓ اشتراک فعال ({$days_left} روز)</span>" 
                            : "<span class='badge-sub badge-sub-expired'>✕ اشتراک منقضی</span>" 
                        ?>
                    </div>
                    <a href="payments.php" style="background:var(--primary); color:#fff; text-decoration:none; padding:0.45rem 0.95rem; border-radius:8px; font-size:0.8rem; font-weight:700; display:inline-block;">
                        💳 تمدید شهریه
                    </a>
                </div>
            </div>

            <!-- ۳. آمار جلسات این ماه -->
            <div class="student-stats-row">
                <div class="s-stat-box">
                    <div style="font-size:0.8rem; color:var(--text-muted);">حضور در این ماه</div>
                    <div class="s-stat-val" style="color:#34d399;"><?= $my_month_presents ?> <span style="font-size:0.85rem; font-weight:normal;">جلسه</span></div>
                </div>
                <div class="s-stat-box">
                    <div style="font-size:0.8rem; color:var(--text-muted);">کل جلسات ماه</div>
                    <div class="s-stat-val" style="color:#38bdf8;"><?= $my_total_sessions ?> <span style="font-size:0.85rem; font-weight:normal;">جلسه</span></div>
                </div>
            </div>

            <!-- ۴. دسترسی سریع به حرکات تمرینی سطح هنرجو -->
            <div style="background:rgba(2, 132, 199, 0.08); border:1px dashed rgba(2, 132, 199, 0.3); border-radius:16px; padding:1.1rem; text-align:center;">
                <div style="font-weight:800; color:#fff; font-size:0.92rem; margin-bottom:0.3rem;">🛹 تمرینات سطح <?= htmlspecialchars($current_user['skill_level'] ?? 'مبتدی') ?></div>
                <div style="font-size:0.78rem; color:var(--text-muted); margin-bottom:0.75rem;">ویدیوهای آموزشی و نکات فنی مربی را تمرین کنید.</div>
                <a href="exercises.php" style="background:#1e293b; color:#38bdf8; border:1px solid #334155; padding:0.4rem 1.1rem; border-radius:8px; text-decoration:none; font-size:0.8rem; font-weight:700; display:inline-block;">
                    مشاهده حرکات تمرینی ↵
                </a>
            </div>

        <?php endif; ?>

    </div>

    <!-- منوی ناوبری ۵ گزینه‌ای پایین صفحه -->
    <nav class="app-bottom-nav">
        <a href="attendance.php" class="app-nav-item">
            <span class="nav-icon">📅</span>
            <span>حضور غیاب</span>
        </a>
        <a href="shop.php" class="app-nav-item">
            <span class="nav-icon">🛒</span>
            <span>فروشگاه</span>
        </a>
        <a href="dashboard.php" class="app-nav-center active">
            <div class="app-center-btn">🏠</div>
            <span class="app-center-label">پیشخوان</span>
        </a>
        <a href="payments.php" class="app-nav-item">
            <span class="nav-icon">💳</span>
            <span>شهریه</span>
        </a>
        <a href="exercises.php" class="app-nav-item">
            <span class="nav-icon">🛹</span>
            <span>حرکات</span>
        </a>
    </nav>
</body>
</html>