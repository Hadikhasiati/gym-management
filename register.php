<?php
// register.php
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

if (!defined('CURRENT_CLUB_ID')) define('CURRENT_CLUB_ID', 1);
if (!defined('CURRENT_CLUB_NAME')) define('CURRENT_CLUB_NAME', 'باشگاه رادین اسکیت');
if (!defined('CURRENT_CLUB_THEME')) define('CURRENT_CLUB_THEME', '#0284c7');

$step = 'form'; // 'form' (فرم ثبت‌نام), 'verify' (تایید کد), 'locked' (قفل امنیتی)
$msg = '';
$error = '';

function clean_digits(?string $input): string {
    if ($input === null) return '';
    $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $arabic  = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
    $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    return trim(str_replace($arabic, $english, str_replace($persian, $english, $input)));
}

// تابع ارسال پترن ملی‌پیامک
function send_melipayamak_otp(string $to, string $otp, string $username, string $password, string $bodyId): bool {
    if (empty($username) || empty($password) || empty($bodyId)) return false;

    $data = [
        'username' => $username,
        'password' => $password,
        'text'     => $otp,
        'to'       => $to,
        'bodyId'   => (int)$bodyId
    ];

    $ch = curl_init('https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber');
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200 && $response) {
        $res = json_decode((string)$response, true);
        if (isset($res['Value']) && is_numeric($res['Value']) && (float)$res['Value'] > 100) return true;
    }
    return false;
}

$stmtClub = $pdo->prepare("SELECT sms_username, sms_password, sms_pattern_otp, sms_pattern_activation FROM clubs WHERE id = ? LIMIT 1");
$stmtClub->execute([CURRENT_CLUB_ID]);
$club_sms = $stmtClub->fetch(PDO::FETCH_ASSOC) ?: [];

// بررسی استپ جاری بر اساس سشن
if (isset($_SESSION['reg_phone']) && isset($_SESSION['reg_user_id'])) {
    $step = 'verify';
}

// بررسی قفل امنیتی ثبت‌نام
if (isset($_SESSION['reg_lock_until']) && time() < $_SESSION['reg_lock_until']) {
    $remaining_lock = $_SESSION['reg_lock_until'] - time();
    $error = "به دلیل ۳ بار ورود اشتباه کد تایید، ثبت‌نام موقتاً قفل شده است. لطفاً {$remaining_lock} ثانیه دیگر صبر کنید.";
    $step = 'locked';
} elseif (isset($_SESSION['reg_lock_until']) && time() >= $_SESSION['reg_lock_until']) {
    unset($_SESSION['reg_lock_until'], $_SESSION['reg_attempts']);
}

// دریافت شماره موبایل خودکار در صورت هدایت از صفحه ورود
$pre_phone = clean_digits($_GET['phone'] ?? ($_SESSION['pre_register_phone'] ?? ($_POST['phone'] ?? '')));

// ==========================================
// ۱. مرحله اول: دریافت اطلاعات و بررسی یکتایی
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_info' && $step !== 'locked') {
    $full_name     = trim($_POST['full_name'] ?? '');
    $father_name   = trim($_POST['father_name'] ?? '');
    $national_code = clean_digits($_POST['national_code'] ?? '');
    $gender        = in_array($_POST['gender'] ?? '', ['male', 'female']) ? $_POST['gender'] : 'male';
    $birth_date    = clean_digits($_POST['birth_date'] ?? '');
    $phone         = clean_digits($_POST['phone'] ?? '');
    $address       = trim($_POST['address'] ?? '');
    $skill_level   = trim($_POST['skill_level'] ?? 'مبتدی');
    $password      = trim($_POST['password'] ?? '');

    if (empty($full_name) || empty($national_code) || empty($phone) || empty($password)) {
        $error = 'لطفاً فیلدهای ستاره‌دار را تکمیل کنید.';
    } elseif (!preg_match('/^09[0-9]{9}$/', $phone)) {
        $error = 'شماره موبایل نامعتبر است.';
    } elseif (strlen($national_code) !== 10 || !is_numeric($national_code)) {
        $error = 'کد ملی باید دقیقاً ۱۰ رقم باشد.';
    } else {
        // بررسی یکتایی شماره موبایل و کد ملی در این باشگاه
        $stmtCheck = $pdo->prepare("SELECT phone, national_code FROM users WHERE club_id = ? AND (phone = ? OR national_code = ?)");
        $stmtCheck->execute([CURRENT_CLUB_ID, $phone, $national_code]);
        $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            if ($existing['phone'] === $phone) {
                $error = 'این شماره موبایل قبلاً در این باشگاه ثبت‌نام شده است.';
            } else {
                $error = 'این کد ملی قبلاً در این باشگاه ثبت شده است.';
            }
        } else {
            $otp = (string)random_int(10000, 99999);
            $otp_expiry = date('Y-m-d H:i:s', strtotime('+5 minutes'));
            $hashed_pass = password_hash($password, PASSWORD_DEFAULT);
            $token = bin2hex(random_bytes(32));

            $stmtIns = $pdo->prepare("
                INSERT INTO users (club_id, full_name, father_name, national_code, gender, birth_date, phone, address, skill_level, password, role, is_active, otp_code, otp_expires_at, auth_token, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'student', 0, ?, ?, ?, NOW())
            ");
            $stmtIns->execute([CURRENT_CLUB_ID, $full_name, $father_name, $national_code, $gender, $birth_date, $phone, $address, $skill_level, $hashed_pass, $otp, $otp_expiry, $token]);
            $user_id = (int)$pdo->lastInsertId();

            $bodyId = !empty($club_sms['sms_pattern_activation']) ? $club_sms['sms_pattern_activation'] : ($club_sms['sms_pattern_otp'] ?? '');
            send_melipayamak_otp($phone, $otp, (string)($club_sms['sms_username'] ?? ''), (string)($club_sms['sms_password'] ?? ''), (string)$bodyId);

            $_SESSION['reg_phone'] = $phone;
            $_SESSION['reg_user_id'] = $user_id;
            $_SESSION['reg_attempts'] = 0;
            unset($_SESSION['pre_register_phone']);
            $step = 'verify';
            $msg = 'کد فعال‌سازی به شماره موبایل شما ارسال شد.';
        }
    }
}

// ==========================================
// ۲. مرحله دوم: تایید کد OTP با بررسی ۳ بار اشتباه
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_otp' && $step !== 'locked') {
    $step = 'verify';
    $entered_otp = clean_digits($_POST['otp'] ?? '');
    $phone = $_SESSION['reg_phone'] ?? '';
    $user_id = (int)($_SESSION['reg_user_id'] ?? 0);

    if (empty($entered_otp) || $user_id <= 0) {
        $error = 'لطفاً کد تایید را وارد کنید.';
    } else {
        $stmtV = $pdo->prepare("SELECT * FROM users WHERE id = ? AND phone = ? AND club_id = ? LIMIT 1");
        $stmtV->execute([$user_id, $phone, CURRENT_CLUB_ID]);
        $user = $stmtV->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $error = 'کاربر یافت نشد.';
        } elseif ($user['otp_code'] !== $entered_otp) {
            $_SESSION['reg_attempts'] = ($_SESSION['reg_attempts'] ?? 0) + 1;
            $left = 3 - $_SESSION['reg_attempts'];
            if ($_SESSION['reg_attempts'] >= 3) {
                $_SESSION['reg_lock_until'] = time() + 60; // قفل ۱ دقیقه‌ای
                $step = 'locked';
                $error = 'به دلیل ۳ بار ورود اشتباه کد تایید، ثبت‌نام برای ۱ دقیقه قفل شد.';
            } else {
                $error = "کد تایید اشتباه است. ({$left} تلاش دیگر باقی مانده است)";
            }
        } elseif (strtotime($user['otp_expires_at']) < time()) {
            $error = 'کد تایید منقضی شده است. لطفاً مجدداً تلاش کنید.';
        } else {
            // فعال‌سازی حساب کاربری
            $stmtAct = $pdo->prepare("UPDATE users SET is_active = 1, otp_code = NULL, otp_expires_at = NULL WHERE id = ?");
            $stmtAct->execute([$user_id]);

            $_SESSION['user_id'] = $user_id;
            unset($_SESSION['reg_phone'], $_SESSION['reg_user_id'], $_SESSION['reg_attempts'], $_SESSION['reg_lock_until']);

            // انتقال مستقیم به صفحه پرداخت شهریه
            header("Location: payments.php");
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>ثبت‌نام هنرجو | <?= htmlspecialchars(CURRENT_CLUB_NAME) ?></title>
    <style>
        :root { --primary: <?= htmlspecialchars(CURRENT_CLUB_THEME) ?>; }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: system-ui, -apple-system, sans-serif; }
        body { background-color: #0b1120; color: #f8fafc; min-height: 100vh; padding: 1.5rem 1rem; display: flex; align-items: center; justify-content: center; }
        .register-container { max-width: 580px; width: 100%; }
        
        .header-card { text-align: center; margin-bottom: 1.5rem; }
        .club-badge { width: 56px; height: 56px; border-radius: 16px; background: linear-gradient(135deg, var(--primary), #0369a1); display: inline-flex; align-items: center; justify-content: center; font-size: 1.8rem; margin-bottom: 0.5rem; }
        .club-name { font-size: 1.25rem; font-weight: 800; color: #fff; }
        .page-desc { font-size: 0.85rem; color: #94a3b8; margin-top: 4px; }

        .card { background: rgba(17, 24, 39, 0.85); border: 1px solid rgba(255,255,255,0.08); border-radius: 20px; padding: 1.5rem; box-shadow: 0 10px 30px rgba(0,0,0,0.4); }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.85rem; }
        .form-group { margin-bottom: 0.9rem; display: flex; flex-direction: column; gap: 0.35rem; }
        .full-width { grid-column: span 2; }
        
        label { font-size: 0.82rem; color: #94a3b8; font-weight: 700; }
        input, select, textarea { background: #1e293b; border: 1px solid #334155; border-radius: 10px; padding: 0.7rem 0.85rem; color: #fff; font-size: 0.9rem; outline: none; }
        input:focus, select:focus, textarea:focus { border-color: var(--primary); }

        .btn-submit { width: 100%; height: 48px; background: linear-gradient(135deg, #0284c7, #0369a1); color: #fff; border: none; border-radius: 12px; font-size: 0.95rem; font-weight: 800; cursor: pointer; margin-top: 0.5rem; }
        .alert-success { background: rgba(16, 185, 129, 0.15); border: 1px solid #10b981; color: #34d399; padding: 0.8rem; border-radius: 10px; margin-bottom: 1.2rem; font-size: 0.85rem; }
        .alert-error { background: rgba(239, 68, 68, 0.15); border: 1px solid #ef4444; color: #f87171; padding: 0.8rem; border-radius: 10px; margin-bottom: 1.2rem; font-size: 0.85rem; }
        
        .timer-box { font-size: 0.82rem; color: #94a3b8; text-align: center; margin-top: 0.85rem; }
        .login-link { text-align: center; margin-top: 1.25rem; font-size: 0.85rem; color: #94a3b8; }
        .login-link a { color: #38bdf8; text-decoration: none; font-weight: 700; }

        @media (max-width: 480px) {
            .form-grid { grid-template-columns: 1fr; }
            .full-width { grid-column: span 1; }
        }
    </style>
</head>
<body>
    <div class="register-container">
        <div class="header-card">
            <div class="club-badge">🛹</div>
            <h1 class="club-name"><?= htmlspecialchars(CURRENT_CLUB_NAME) ?></h1>
            <p class="page-desc">تکمیل اطلاعات فردی و فعال‌سازی حساب کاربری</p>
        </div>

        <?php if ($msg): ?><div class="alert-success">✓ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert-error">✕ <?= htmlspecialchars($error) ?></div><?php endif; ?>

        <div class="card">
            <?php if ($step === 'locked'): ?>
                <!-- حالت قفل ۱ دقیقه‌ای ثبت‌نام -->
                <div style="text-align:center; padding: 1rem 0;">
                    <div style="font-size: 3rem; margin-bottom: 0.75rem;">🔒</div>
                    <h3 style="color: #f87171; font-size: 1.1rem; margin-bottom: 0.5rem;">بخش ثبت‌نام موقتاً قفل شد</h3>
                    <p style="font-size: 0.85rem; color: #94a3b8;">به دلیل تلاش‌های ناموفق مکرر، لطفاً صبور باشید...</p>
                    <div style="font-size: 1.5rem; font-weight: 900; color: #38bdf8; margin-top: 1rem;" id="lockTimer">60</div>
                </div>
                <script>
                    let timeLeft = 60;
                    const timerEl = document.getElementById('lockTimer');
                    const countdown = setInterval(() => {
                        timeLeft--;
                        timerEl.innerText = timeLeft;
                        if (timeLeft <= 0) {
                            clearInterval(countdown);
                            window.location.href = 'register.php';
                        }
                    }, 1000);
                </script>
            <?php elseif ($step === 'form'): ?>
                <!-- مرحله ۱: فرم اطلاعات -->
                <form method="POST">
                    <input type="hidden" name="action" value="submit_info">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>نام و نام خانوادگی *</label>
                            <input type="text" name="full_name" placeholder="علی رضایی" required>
                        </div>
                        <div class="form-group">
                            <label>نام پدر</label>
                            <input type="text" name="father_name" placeholder="حسین">
                        </div>
                        <div class="form-group">
                            <label>کد ملی (۱۰ رقم) *</label>
                            <input type="text" name="national_code" placeholder="xxxxxxxxxx" maxlength="10" dir="ltr" required>
                        </div>
                        <div class="form-group">
                            <label>جنسیت *</label>
                            <select name="gender" required>
                                <option value="male">پسر / آقا</option>
                                <option value="female">دختر / خانم</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>تاریخ تولد (شمسی)</label>
                            <input type="text" name="birth_date" placeholder="1392/05/10" dir="ltr">
                        </div>
                        <div class="form-group">
                            <label>سطح آموزشی *</label>
                            <select name="skill_level" required>
                                <option value="مبتدی">مبتدی</option>
                                <option value="پیشرفته">پیشرفته</option>
                                <option value="فری استایل">فری استایل</option>
                                <option value="سرعت">سرعت</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>شماره موبایل *</label>
                            <input type="text" name="phone" value="<?= htmlspecialchars($pre_phone) ?>" placeholder="09xxxxxxxxx" maxlength="11" dir="ltr" required>
                        </div>
                        <div class="form-group">
                            <label>رمز عبور *</label>
                            <input type="password" name="password" placeholder="••••••••" dir="ltr" required>
                        </div>
                        <div class="form-group full-width">
                            <label>آدرس سکونت</label>
                            <textarea name="address" rows="2" placeholder="شهر، خیابان..."></textarea>
                        </div>
                    </div>
                    <button type="submit" class="btn-submit">دریافت کد فعال‌سازی ↵</button>
                </form>
            <?php else: ?>
                <!-- مرحله ۲: وارد کردن کد تایید OTP همراه با تایمر معکوس -->
                <form method="POST">
                    <input type="hidden" name="action" value="verify_otp">
                    <div style="text-align:center; margin-bottom:1.25rem;">
                        <h3 style="font-size:1.1rem; color:#38bdf8;">کد تایید را وارد کنید</h3>
                        <p style="font-size:0.82rem; color:#94a3b8; margin-top:4px;">کد ارسال شده به شماره <?= htmlspecialchars($_SESSION['reg_phone'] ?? '') ?></p>
                    </div>
                    <div class="form-group">
                        <input type="text" name="otp" placeholder="•••••" maxlength="5" style="text-align:center; font-size:1.5rem; letter-spacing:0.4rem; font-weight:900;" dir="ltr" autofocus required>
                    </div>
                    <button type="submit" class="btn-submit">تایید و ورود به صفحه پرداخت شهریه ↵</button>
                </form>

                <div class="timer-box" id="timerContainer">
                    ارسال مجدد کد تا <span id="countdownTimer" style="color: #38bdf8; font-weight: 800;">60</span> ثانیه دیگر
                </div>
                <div style="text-align: center; margin-top: 0.75rem;">
                    <a href="register.php?reset=1" id="resendLink" style="color: #64748b; font-size: 0.8rem; text-decoration: none; pointer-events: none;">ارسال مجدد کد تایید</a>
                </div>

                <script>
                    let seconds = 60;
                    const timerSpan = document.getElementById('countdownTimer');
                    const resendLink = document.getElementById('resendLink');
                    const timerContainer = document.getElementById('timerContainer');

                    const interval = setInterval(() => {
                        seconds--;
                        timerSpan.innerText = seconds;
                        if (seconds <= 0) {
                            clearInterval(interval);
                            timerContainer.style.display = 'none';
                            resendLink.style.color = '#38bdf8';
                            resendLink.style.pointerEvents = 'auto';
                        }
                    }, 1000);
                </script>
            <?php endif; ?>
        </div>

        <?php if ($step !== 'locked'): ?>
            <div class="login-link">قبلاً ثبت‌نام کرده‌اید؟ <a href="login.php">ورود به حساب</a></div>
        <?php endif; ?>
    </div>
</body>
</html>