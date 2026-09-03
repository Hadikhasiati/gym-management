<?php
// payments.php
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

$today = date('Y-m-d');
$payment_failed_screen = false;
$payment_success_screen = false;
$error_detail = '';
$success_detail = '';

// ساخت خودکار جدول payments
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `payments` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `club_id` INT NOT NULL DEFAULT 1,
          `user_id` INT NOT NULL,
          `amount` INT NOT NULL,
          `status` ENUM('pending', 'success', 'failed') NOT NULL DEFAULT 'pending',
          `tracking_code` VARCHAR(100) NULL,
          `authority` VARCHAR(100) NULL,
          `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
          INDEX (`club_id`),
          INDEX (`user_id`),
          INDEX (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $cols = $pdo->query("SHOW COLUMNS FROM `payments`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('tracking_code', $cols)) $pdo->exec("ALTER TABLE `payments` ADD COLUMN `tracking_code` VARCHAR(100) NULL AFTER `amount`");
    if (!in_array('authority', $cols)) $pdo->exec("ALTER TABLE `payments` ADD COLUMN `authority` VARCHAR(100) NULL AFTER `tracking_code`");
    if (!in_array('club_id', $cols)) $pdo->exec("ALTER TABLE `payments` ADD COLUMN `club_id` INT NOT NULL DEFAULT 1 AFTER `id`");
} catch (Exception $e) {}

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

// ========================================================
// واکشی نرخ دقیق و زنده شهریه باشگاه از دیتابیس
// ========================================================
$monthly_tuition = 1500000;
$zarinpal_merchant = '';

try {
    $stmtClub = $pdo->prepare("SELECT tuition_fee, zarinpal_merchant FROM clubs WHERE id = ? LIMIT 1");
    $stmtClub->execute([CURRENT_CLUB_ID]);
    $c_data = $stmtClub->fetch(PDO::FETCH_ASSOC);

    if ($c_data) {
        $raw_fee = (string)($c_data['tuition_fee'] ?? '1500000');
        $persian = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
        $arabic  = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
        $english = ['0','1','2','3','4','5','6','7','8','9'];
        $clean_fee = str_replace($arabic, $english, str_replace($persian, $english, $raw_fee));
        $tuition_from_db = (int)preg_replace('/[^0-9]/', '', $clean_fee);

        if ($tuition_from_db > 0) {
            $monthly_tuition = $tuition_from_db;
        }
        $zarinpal_merchant = trim((string)($c_data['zarinpal_merchant'] ?? ''));
    }
} catch (Exception $e) {}

// واکشی تاریخ اعتبار کاربر
$stmtCur = $pdo->prepare("SELECT subscription_expires_at FROM users WHERE id = ? LIMIT 1");
$stmtCur->execute([$current_user['id']]);
$user_sub_exp = $stmtCur->fetchColumn() ?: null;

// ==========================================
// ۱. بررسی بازگشت از درگاه پرداخت (Callback)
// ==========================================
if (isset($_GET['Authority']) || isset($_GET['Status'])) {
    $authority = trim((string)($_GET['Authority'] ?? ''));
    $status    = trim((string)($_GET['Status'] ?? ''));

    $stmtFind = $pdo->prepare("SELECT * FROM payments WHERE authority = ? AND club_id = ? AND user_id = ? LIMIT 1");
    $stmtFind->execute([$authority, CURRENT_CLUB_ID, $current_user['id']]);
    $pending_payment = $stmtFind->fetch(PDO::FETCH_ASSOC);

    if ($status === 'NOK' || empty($authority) || !$pending_payment) {
        $payment_failed_screen = true;
        $error_detail = 'تراکنش توسط شما لغو شد یا با خطای بانکی مواجه گردید.';
        if ($pending_payment) {
            $stmtFail = $pdo->prepare("UPDATE payments SET status = 'failed' WHERE id = ?");
            $stmtFail->execute([$pending_payment['id']]);
        }
    } elseif ($status === 'OK' && $pending_payment) {
        $amount_toman = (int)$pending_payment['amount'];
        $amount_rial  = $amount_toman * 10;

        $verify_data = [
            "merchant_id" => $zarinpal_merchant,
            "amount"      => $amount_rial,
            "authority"   => $authority
        ];

        $ch = curl_init('https://payment.zarinpal.com/pg/v4/payment/verify.json');
        curl_setopt($ch, CURLOPT_USERAGENT, 'ZarinPal Rest Api v4');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($verify_data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $verify_res = curl_exec($ch);
        curl_close($ch);

        $v_data = json_decode((string)$verify_res, true);
        $v_code = $v_data['data']['code'] ?? -1;

        if ($v_code === 100 || $v_code === 101) {
            $ref_id = (string)($v_data['data']['ref_id'] ?? $pending_payment['tracking_code']);

            $base_date = (!empty($user_sub_exp) && $user_sub_exp >= $today) ? $user_sub_exp : $today;
            $new_expiry = date('Y-m-d', strtotime("{$base_date} +30 days"));

            $stmtOk = $pdo->prepare("UPDATE payments SET status = 'success', tracking_code = ? WHERE id = ?");
            $stmtOk->execute([$ref_id, $pending_payment['id']]);

            $stmtUpUser = $pdo->prepare("UPDATE users SET subscription_expires_at = ? WHERE id = ?");
            $stmtUpUser->execute([$new_expiry, $current_user['id']]);

            $user_sub_exp = $new_expiry;
            $payment_success_screen = true;
            $success_detail = "پرداخت شهریه به مبلغ " . number_format($amount_toman) . " تومان با موفقیت ثبت شد. کد رهگیری: {$ref_id}";
        } else {
            $payment_failed_screen = true;
            $error_detail = 'تاییدیه پرداخت از سوی بانک صادر نشد (کد خطا: ' . $v_code . '). در صورت کسر وجه، حداکثر تا ۷۲ ساعت به حساب شما بازمی‌گردد.';
            $stmtFail = $pdo->prepare("UPDATE payments SET status = 'failed' WHERE id = ?");
            $stmtFail->execute([$pending_payment['id']]);
        }
    }
}

// ==========================================
// ۲. ارسال کاربر به درگاه زرین‌پال با مبلغ دقیق
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'process_payment') {
    $amount_to_pay = $monthly_tuition; // دقیقاً مبلغ خوانده شده از جدول clubs
    $tracking_code = 'RAD-' . date('ymd') . '-' . rand(1000, 9999);

    if (!empty($zarinpal_merchant) && strlen($zarinpal_merchant) >= 30) {
        $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
        $callback_url = "{$scheme}://{$_SERVER['HTTP_HOST']}{$_SERVER['PHP_SELF']}";
        
        $data = [
            "merchant_id" => $zarinpal_merchant,
            "amount"      => $amount_to_pay * 10, // تبدیل به ریال
            "callback_url"=> $callback_url,
            "description" => "تمدید ۳۰ روزه شهریه باشگاه " . CURRENT_CLUB_NAME,
            "metadata"    => ["mobile" => $current_user['phone']]
        ];

        $ch = curl_init('https://payment.zarinpal.com/pg/v4/payment/request.json');
        curl_setopt($ch, CURLOPT_USERAGENT, 'ZarinPal Rest Api v4');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $result = curl_exec($ch);
        curl_close($ch);

        $res = json_decode((string)$result, true);
        if (isset($res['data']['code']) && $res['data']['code'] == 100) {
            $authority = $res['data']['authority'];
            $stmtIns = $pdo->prepare("
                INSERT INTO payments (club_id, user_id, amount, status, tracking_code, authority, created_at)
                VALUES (?, ?, ?, 'pending', ?, ?, NOW())
            ");
            $stmtIns->execute([CURRENT_CLUB_ID, $current_user['id'], $amount_to_pay, $tracking_code, $authority]);
            header("Location: https://payment.zarinpal.com/pg/StartPay/" . $authority);
            exit;
        } else {
            $payment_failed_screen = true;
            $error_detail = 'امکان اتصال به درگاه زرین‌پال میسر نشد. لطفاً از درستی مرچنت‌کد در تنظیمات مدیریت اطمینان حاصل کنید.';
        }
    } else {
        // حالت تایید آنی در صورت نبود مرچنت
        $base_date = (!empty($user_sub_exp) && $user_sub_exp >= $today) ? $user_sub_exp : $today;
        $new_expiry = date('Y-m-d', strtotime("{$base_date} +30 days"));

        $stmtPay = $pdo->prepare("
            INSERT INTO payments (club_id, user_id, amount, status, tracking_code, authority, created_at)
            VALUES (?, ?, ?, 'success', ?, 'DIRECT', NOW())
        ");
        $stmtPay->execute([CURRENT_CLUB_ID, $current_user['id'], $amount_to_pay, $tracking_code]);

        $stmtUpUser = $pdo->prepare("UPDATE users SET subscription_expires_at = ? WHERE id = ?");
        $stmtUpUser->execute([$new_expiry, $current_user['id']]);

        $user_sub_exp = $new_expiry;
        $payment_success_screen = true;
        $success_detail = "پرداخت شهریه به مبلغ " . number_format($amount_to_pay) . " تومان با موفقیت انجام شد. اعتبار شما تا تاریخ " . to_jalali_date($new_expiry) . " افزایش یافت.";
    }
}

// وضعیت روزهای باقیمانده
$is_active = (!empty($user_sub_exp) && $user_sub_exp >= $today);
$days_remaining = 0;
if ($is_active && $user_sub_exp) {
    $diff = strtotime($user_sub_exp) - strtotime($today);
    $days_remaining = max(0, (int)round($diff / 86400));
}

// سوابق پرداخت‌های موفق
$stmtHistory = $pdo->prepare("
    SELECT * FROM payments 
    WHERE club_id = ? AND user_id = ? AND status = 'success' 
    ORDER BY id DESC
");
$stmtHistory->execute([CURRENT_CLUB_ID, $current_user['id']]);
$payments_history = $stmtHistory->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>شهریه و پرداخت آنلاین | <?= htmlspecialchars(CURRENT_CLUB_NAME) ?></title>
    
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#0b1120">

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
        body { background-color: var(--bg-dark); color: var(--text-main); min-height: 100vh; padding: 1.2rem 0.9rem calc(85px + env(safe-area-inset-bottom)) 0.9rem; line-height: 1.5; }
        .container { max-width: 760px; margin: 0 auto; }

        .header-bar {
            display: flex; justify-content: space-between; align-items: center; background: rgba(30, 41, 59, 0.7);
            border: 1px solid var(--border-color); backdrop-filter: blur(12px); border-radius: 16px;
            padding: 0.85rem 1.1rem; margin-bottom: 1.25rem;
        }
        .btn-back { background: #1e293b; color: #38bdf8; border: 1px solid #334155; padding: 0.45rem 0.9rem; border-radius: 8px; text-decoration: none; font-size: 0.82rem; font-weight: 700; }

        /* کارت پیام خطا و موفقیت */
        .result-card {
            background: linear-gradient(135deg, #1e293b, #0f172a); border-radius: 20px;
            padding: 1.75rem 1.4rem; margin-bottom: 1.5rem; text-align: center;
        }
        .result-card.failed { border: 1px solid rgba(239, 68, 68, 0.4); box-shadow: 0 10px 30px rgba(239, 68, 68, 0.15); }
        .result-card.success { border: 1px solid rgba(16, 185, 129, 0.4); box-shadow: 0 10px 30px rgba(16, 185, 129, 0.15); }
        .result-icon-circle { width: 64px; height: 64px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2rem; margin: 0 auto 1rem auto; }
        .failed .result-icon-circle { background: rgba(239, 68, 68, 0.15); border: 2px solid #ef4444; color: #f87171; }
        .success .result-icon-circle { background: rgba(16, 185, 129, 0.15); border: 2px solid #10b981; color: #34d399; }
        .result-title { font-size: 1.2rem; font-weight: 900; margin-bottom: 0.4rem; }
        .failed .result-title { color: #f87171; }
        .success .result-title { color: #34d399; }
        .result-desc { font-size: 0.88rem; color: #cbd5e1; line-height: 1.6; margin-bottom: 1.5rem; }
        
        .result-actions { display: flex; gap: 0.75rem; justify-content: center; flex-wrap: wrap; }
        .btn-dashboard-return { background: linear-gradient(135deg, #0284c7, #0369a1); color: #fff; text-decoration: none; padding: 0.75rem 1.5rem; border-radius: 12px; font-weight: 800; font-size: 0.9rem; display: inline-flex; align-items: center; gap: 0.4rem; }
        .btn-retry-pay { background: #1e293b; color: #f8fafc; border: 1px solid #334155; text-decoration: none; padding: 0.75rem 1.25rem; border-radius: 12px; font-weight: 700; font-size: 0.88rem; }

        /* کارت ۱: وضعیت شهریه */
        .status-card {
            background: linear-gradient(135deg, #1e293b, #0f172a); border: 1px solid var(--border-color);
            border-radius: 20px; padding: 1.4rem; margin-bottom: 1.25rem; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.4);
            position: relative; overflow: hidden;
        }
        .status-card::before { content: ''; position: absolute; top: 0; right: 0; left: 0; height: 4px; background: <?= $is_active ? '#10b981' : '#ef4444' ?>; }
        .status-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.8rem; }
        .badge-status { padding: 0.35rem 0.85rem; border-radius: 8px; font-size: 0.82rem; font-weight: 800; }
        .badge-active { background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid #10b981; }
        .badge-expired { background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid #ef4444; }

        .days-counter-box { display: flex; align-items: baseline; gap: 0.4rem; margin: 0.4rem 0; }
        .days-number { font-size: 2.2rem; font-weight: 900; color: <?= $is_active ? '#38bdf8' : '#f87171' ?>; line-height: 1; }
        .days-text { font-size: 0.95rem; color: var(--text-muted); font-weight: 700; }

        /* کارت ۲: پرداخت و تمدید */
        .payment-card {
            background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 20px;
            padding: 1.4rem; margin-bottom: 1.5rem; box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
        }
        .plan-header { display: flex; align-items: center; justify-content: space-between; border-bottom: 1px dashed rgba(255,255,255,0.1); padding-bottom: 1rem; margin-bottom: 1rem; }
        .plan-title { font-size: 1.05rem; font-weight: 800; color: #fff; }
        .plan-duration { font-size: 0.8rem; color: #38bdf8; background: rgba(2, 132, 199, 0.15); padding: 0.25rem 0.65rem; border-radius: 6px; font-weight: 700; }
        
        .price-row { display: flex; justify-content: space-between; align-items: center; margin: 1.25rem 0; }
        .price-label { font-size: 0.88rem; color: var(--text-muted); font-weight: 600; }
        .price-val { font-size: 1.6rem; font-weight: 900; color: #fbbf24; }

        .btn-pay {
            width: 100%; height: 52px; background: linear-gradient(135deg, #0284c7, #0369a1);
            color: #fff; border: none; border-radius: 12px; font-size: 1.05rem; font-weight: 800;
            cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 0.5rem;
            box-shadow: 0 6px 20px rgba(2, 132, 199, 0.4); transition: transform 0.2s;
        }
        .btn-pay:hover { transform: translateY(-2px); }

        /* کارت ۳: سوابق */
        .history-card {
            background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 20px;
            padding: 1.4rem; box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
        }
        .section-heading { font-size: 1rem; font-weight: 800; color: #38bdf8; margin-bottom: 1rem; }
        .history-item {
            background: rgba(30, 41, 59, 0.5); border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 12px; padding: 0.95rem; margin-bottom: 0.75rem;
            display: flex; justify-content: space-between; align-items: center;
        }
        .history-title { font-size: 0.9rem; font-weight: 800; color: #fff; }
        .history-date { font-size: 0.75rem; color: var(--text-muted); font-family: monospace; }
        .history-amount { font-size: 0.98rem; font-weight: 800; color: #34d399; text-align: left; }
        .history-code { font-size: 0.72rem; color: #64748b; font-family: monospace; margin-top: 2px; }

        /* نوار ناوبری پایین */
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
    </style>
</head>
<body>
    <div class="container">

        <div class="header-bar">
            <div>
                <h2 style="font-size: 1.05rem; color: #38bdf8;">💳 شهریه و پرداخت آنلاین</h2>
                <div style="font-size: 0.75rem; color: #64748b;"><?= htmlspecialchars(CURRENT_CLUB_NAME) ?></div>
            </div>
            <a href="dashboard.php" class="btn-back">بازگشت به پیشخوان ↵</a>
        </div>

        <!-- کارت خطای پرداخت -->
        <?php if ($payment_failed_screen): ?>
            <div class="result-card failed">
                <div class="result-icon-circle">✕</div>
                <h3 class="result-title">پرداخت انجام نشد</h3>
                <p class="result-desc"><?= htmlspecialchars($error_detail) ?></p>
                <div class="result-actions">
                    <a href="dashboard.php" class="btn-dashboard-return">
                        <span>🏠</span>
                        <span>بازگشت به پیشخوان</span>
                    </a>
                    <a href="payments.php" class="btn-retry-pay">
                        <span>🔄</span>
                        <span>تلاش مجدد</span>
                    </a>
                </div>
            </div>
        <?php endif; ?>

        <!-- کارت موفقیت پرداخت -->
        <?php if ($payment_success_screen): ?>
            <div class="result-card success">
                <div class="result-icon-circle">✓</div>
                <h3 class="result-title">پرداخت با موفقیت انجام شد</h3>
                <p class="result-desc"><?= htmlspecialchars($success_detail) ?></p>
                <div class="result-actions">
                    <a href="dashboard.php" class="btn-dashboard-return" style="background: linear-gradient(135deg, #10b981, #059669);">
                        <span>🏠</span>
                        <span>ورود به پیشخوان کاربری</span>
                    </a>
                </div>
            </div>
        <?php endif; ?>

        <!-- ۱. وضعیت شهریه فعلی -->
        <div class="status-card">
            <div class="status-head">
                <span style="font-size:0.85rem; color:var(--text-muted); font-weight:700;">وضعیت عضویت شما</span>
                <span class="badge-status <?= $is_active ? 'badge-active' : 'badge-expired' ?>">
                    <?= $is_active ? '✓ شهریه فعال' : '✕ منقضی شده' ?>
                </span>
            </div>

            <div class="days-counter-box">
                <span class="days-number"><?= $is_active ? $days_remaining : 0 ?></span>
                <span class="days-text">روز باقیمانده از اعتبار دوره</span>
            </div>

            <div style="display:flex; justify-content:space-between; align-items:center; font-size:0.82rem; color:var(--text-muted); border-top:1px dashed rgba(255,255,255,0.08); padding-top:0.75rem; margin-top:0.75rem;">
                <span>انقضای شهریه:</span>
                <strong style="color:#fff; font-family:monospace; font-size:0.92rem;"><?= to_jalali_date($user_sub_exp) ?></strong>
            </div>
        </div>

        <!-- ۲. تمدید و پرداخت -->
        <div class="payment-card">
            <div class="plan-header">
                <div class="plan-title">
                    <span>🛹 دوره ماهانه آموزش اسکیت</span>
                </div>
                <span class="plan-duration">۳۰ روزه (۱۲ جلسه)</span>
            </div>

            <div style="font-size:0.82rem; color:var(--text-muted); line-height:1.6; margin-bottom:1rem;">
                با پرداخت شهریه، اشتراک شما ۳۰ روز تمدید می‌شود و دسترسی به سانس‌ها و حرکات آموزشی شارژ خواهد شد.
            </div>

            <div class="price-row">
                <span class="price-label">مبلغ قابل پرداخت:</span>
                <div>
                    <span class="price-val"><?= number_format($monthly_tuition) ?></span>
                    <span style="font-size:0.85rem; color:var(--text-muted);">تومان</span>
                </div>
            </div>

            <form method="POST">
                <input type="hidden" name="action" value="process_payment">
                <button type="submit" class="btn-pay">
                    <span>💳</span>
                    <span>پرداخت آنلاین و تمدید اشتراک</span>
                </button>
            </form>
        </div>

        <!-- ۳. سوابق پرداخت -->
        <div class="history-card">
            <div class="section-heading">
                <span>📋 سوابق پرداخت‌های موفق</span>
            </div>

            <?php if (empty($payments_history)): ?>
                <div style="text-align:center; color:#64748b; padding:2rem 1rem; font-size:0.85rem;">
                    سابقه‌ای برای نمایش وجود ندارد.
                </div>
            <?php else: ?>
                <?php foreach ($payments_history as $pay): ?>
                    <div class="history-item">
                        <div>
                            <div class="history-title">تمدید ۳۰ روزه شهریه</div>
                            <div class="history-date">تاریخ: <?= to_jalali_date($pay['created_at']) ?></div>
                        </div>
                        <div style="text-align:left;">
                            <div class="history-amount"><?= number_format((int)$pay['amount']) ?> تومان</div>
                            <div class="history-code"><?= htmlspecialchars($pay['tracking_code'] ?: (string)$pay['id']) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

    </div>

    <!-- منوی پایین صفحه -->
    <nav class="app-bottom-nav">
        <a href="attendance.php" class="app-nav-item">
            <span class="nav-icon">📅</span>
            <span>حضور غیاب</span>
        </a>
        <a href="shop.php" class="app-nav-item">
            <span class="nav-icon">🛒</span>
            <span>فروشگاه</span>
        </a>
        <a href="dashboard.php" class="app-nav-center">
            <div class="app-center-btn">🏠</div>
            <span class="app-center-label">پیشخوان</span>
        </a>
        <a href="payments.php" class="app-nav-item active">
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