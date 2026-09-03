<?php
// login.php
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

$step = 'phone'; 
$msg = '';
$error = '';

function clean_digits(?string $input): string {
    if ($input === null) return '';
    $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $arabic  = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
    $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    return trim(str_replace($arabic, $english, str_replace($persian, $english, $input)));
}

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

$stmtClub = $pdo->prepare("SELECT sms_username, sms_password, sms_pattern_otp FROM clubs WHERE id = ? LIMIT 1");
$stmtClub->execute([CURRENT_CLUB_ID]);
$club_sms = $stmtClub->fetch(PDO::FETCH_ASSOC) ?: [];

// اگر کاربر در مرحله تایید است، استپ را حفظ کن
if (isset($_SESSION['login_phone']) && isset($_SESSION['login_user_id'])) {
    $step = 'verify';
}

// بررسی قفل امنیتی سراسری در سشن
if (isset($_SESSION['login_lock_until']) && time() < $_SESSION['login_lock_until']) {
    $remaining_lock = $_SESSION['login_lock_until'] - time();
    $error = "به دلیل ۳ بار ورود اشتباه کد تایید، پنل موقتاً قفل شده است. لطفاً {$remaining_lock} ثانیه دیگر صبر کنید.";
    $step = 'locked';
} elseif (isset($_SESSION['login_lock_until']) && time() >= $_SESSION['login_lock_until']) {
    unset($_SESSION['login_lock_until'], $_SESSION['login_attempts']);
}

// ==========================================
// ۱. مرحله اول: بررسی شماره موبایل
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'check_phone' && $step !== 'locked') {
    $phone = clean_digits($_POST['phone'] ?? '');

    if (!preg_match('/^09[0-9]{9}$/', $phone)) {
        $error = 'شماره موبایل نامعتبر است (باید با 09 شروع شود).';
    } else {
        $stmtUser = $pdo->prepare("SELECT id, is_active FROM users WHERE phone = ? AND club_id = ? LIMIT 1");
        $stmtUser->execute([$phone, CURRENT_CLUB_ID]);
        $user = $stmtUser->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $_SESSION['pre_register_phone'] = $phone;
            header("Location: register.php?phone=" . urlencode($phone));
            exit;
        } else {
            $otp = (string)random_int(10000, 99999);
            $otp_expiry = date('Y-m-d H:i:s', strtotime('+5 minutes'));

            $stmtUp = $pdo->prepare("UPDATE users SET otp_code = ?, otp_expires_at = ? WHERE id = ?");
            $stmtUp->execute([$otp, $otp_expiry, $user['id']]);

            $bodyId = (string)($club_sms['sms_pattern_otp'] ?? '');
            send_melipayamak_otp($phone, $otp, (string)($club_sms['sms_username'] ?? ''), (string)($club_sms['sms_password'] ?? ''), $bodyId);

            $_SESSION['login_phone'] = $phone;
            $_SESSION['login_user_id'] = $user['id'];
            $_SESSION['login_attempts'] = 0; // ریست تعداد تلاش‌های اشتباه
            $step = 'verify';
            $msg = 'کد تایید ورود به شماره موبایل شما ارسال شد.';
        }
    }
}

// ==========================================
// ۲. مرحله دوم: تایید کد OTP با بررسی ۳ بار اشتباه
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_login_otp' && $step !== 'locked') {
    $step = 'verify';
    $entered_otp = clean_digits($_POST['otp'] ?? '');
    $phone = $_SESSION['login_phone'] ?? '';
    $user_id = (int)($_SESSION['login_user_id'] ?? 0);

    if (empty($entered_otp) || $user_id <= 0) {
        $error = 'لطفاً کد تایید را وارد کنید.';
    } else {
        $stmtV = $pdo->prepare("SELECT * FROM users WHERE id = ? AND phone = ? AND club_id = ? LIMIT 1");
        $stmtV->execute([$user_id, $phone, CURRENT_CLUB_ID]);
        $user = $stmtV->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $error = 'کاربر یافت نشد.';
        } elseif ($user['otp_code'] !== $entered_otp) {
            // افزایش تعداد تلاش‌های اشتباه
            $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
            $left_attempts = 3 - $_SESSION['login_attempts'];

            if ($_SESSION['login_attempts'] >= 3) {
                $_SESSION['login_lock_until'] = time() + 60; // قفل ۱ دقیقه‌ای
                $step = 'locked';
                $error = 'به دلیل ۳ بار ورود اشتباه کد تایید، پنل برای ۱ دقیقه قفل شد.';
            } else {
                $error = "کد تایید اشتباه است. ({$left_attempts} تلاش دیگر باقی مانده است)";
            }
        } elseif (strtotime($user['otp_expires_at']) < time()) {
            $error = 'کد تایید منقضی شده است. لطفاً درخواست کد جدید کنید.';
        } else {
            $stmtAct = $pdo->prepare("UPDATE users SET otp_code = NULL, otp_expires_at = NULL WHERE id = ?");
            $stmtAct->execute([$user_id]);

            $_SESSION['user_id'] = $user_id;
            unset($_SESSION['login_phone'], $_SESSION['login_user_id'], $_SESSION['login_attempts'], $_SESSION['login_lock_until']);

            header("Location: dashboard.php");
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
    <title>ورود به سامانه | <?= htmlspecialchars(CURRENT_CLUB_NAME) ?></title>
    <style>
        :root { --primary: <?= htmlspecialchars(CURRENT_CLUB_THEME) ?>; }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: system-ui, -apple-system, sans-serif; }
        body { background-color: #0b1120; color: #f8fafc; min-height: 100vh; padding: 1.5rem 1rem; display: flex; align-items: center; justify-content: center; }
        .login-container { max-width: 440px; width: 100%; }
        
        .header-card { text-align: center; margin-bottom: 1.5rem; }
        .club-badge { width: 60px; height: 60px; border-radius: 18px; background: linear-gradient(135deg, var(--primary), #0369a1); display: inline-flex; align-items: center; justify-content: center; font-size: 2rem; margin-bottom: 0.6rem; box-shadow: 0 8px 20px rgba(2, 132, 199, 0.3); }
        .club-name { font-size: 1.35rem; font-weight: 800; color: #fff; }
        .page-desc { font-size: 0.85rem; color: #94a3b8; margin-top: 4px; }

        .card { background: rgba(17, 24, 39, 0.85); border: 1px solid rgba(255,255,255,0.08); border-radius: 20px; padding: 1.75rem; box-shadow: 0 10px 30px rgba(0,0,0,0.4); }
        .form-group { margin-bottom: 1.1rem; display: flex; flex-direction: column; gap: 0.4rem; }
        label { font-size: 0.84rem; color: #94a3b8; font-weight: 700; }
        input { background: #1e293b; border: 1px solid #334155; border-radius: 10px; padding: 0.75rem 0.9rem; color: #fff; font-size: 0.95rem; outline: none; }
        input:focus { border-color: var(--primary); }

        .btn-submit { width: 100%; height: 50px; background: linear-gradient(135deg, #0284c7, #0369a1); color: #fff; border: none; border-radius: 12px; font-size: 1rem; font-weight: 800; cursor: pointer; margin-top: 0.5rem; box-shadow: 0 6px 20px rgba(2, 132, 199, 0.3); }
        .btn-submit:disabled { background: #334155; color: #64748b; cursor: not-allowed; box-shadow: none; }
        
        .alert-success { background: rgba(16, 185, 129, 0.15); border: 1px solid #10b981; color: #34d399; padding: 0.85rem; border-radius: 10px; margin-bottom: 1.25rem; font-size: 0.85rem; }
        .alert-error { background: rgba(239, 68, 68, 0.15); border: 1px solid #ef4444; color: #f87171; padding: 0.85rem; border-radius: 10px; margin-bottom: 1.25rem; font-size: 0.85rem; }
        
        .timer-box { font-size: 0.82rem; color: #94a3b8; text-align: center; margin-top: 0.85rem; }
        .register-link { text-align: center; margin-top: 1.25rem; font-size: 0.85rem; color: #94a3b8; }
        .register-link a { color: #38bdf8; text-decoration: none; font-weight: 700; }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="header-card">
            <div class="club-badge">🛹</div>
            <h1 class="club-name"><?= htmlspecialchars(CURRENT_CLUB_NAME) ?></h1>
            <p class="page-desc">سامانه مدیریت و آموزش باشگاه</p>
        </div>

        <?php if ($msg): ?><div class="alert-success">✓ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert-error">✕ <?= htmlspecialchars($error) ?></div><?php endif; ?>

        <div class="card">
            <?php if ($step === 'locked'): ?>
                <!-- حالت قفل ۱ دقیقه‌ای -->
                <div style="text-align:center; padding: 1rem 0;">
                    <div style="font-size: 3rem; margin-bottom: 0.75rem;">🔒</div>
                    <h3 style="color: #f87171; font-size: 1.1rem; margin-bottom: 0.5rem;">حساب موقتاً قفل شد</h3>
                    <p style="font-size: 0.85rem; color: #94a3b8;" id="lockMessage">به دلیل تلاش‌های ناموفق مکرر، لطفاً صبور باشید...</p>
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
                            window.location.reload();
                        }
                    }, 1000);
                </script>
            <?php elseif ($step === 'phone'): ?>
                <!-- مرحله ۱: ورود شماره موبایل -->
                <form method="POST">
                    <input type="hidden" name="action" value="check_phone">
                    <div class="form-group">
                        <label>شماره موبایل خود را وارد کنید *</label>
                        <input type="text" name="phone" placeholder="09xxxxxxxxx" maxlength="11" dir="ltr" autofocus required>
                        <div style="font-size: 0.75rem; color: #64748b; margin-top: 3px;">در صورت عدم ثبت‌نام، به صورت خودکار به فرم ثبت‌نام هدایت می‌شوید.</div>
                    </div>
                    <button type="submit" class="btn-submit">ادامه و بررسی ↵</button>
                </form>
            <?php else: ?>
                <!-- مرحله ۲: وارد کردن کد تایید OTP با تایمر معکوس ۱ دقیقه‌ای -->
                <form method="POST" id="verifyForm">
                    <input type="hidden" name="action" value="verify_login_otp">
                    <div style="text-align:center; margin-bottom:1.25rem;">
                        <h3 style="font-size:1.1rem; color:#38bdf8;">کد تایید ورود</h3>
                        <p style="font-size:0.82rem; color:#94a3b8; margin-top:4px;">کد ۵ رقمی ارسال شده به شماره <?= htmlspecialchars($_SESSION['login_phone'] ?? '') ?></p>
                    </div>
                    <div class="form-group">
                        <input type="text" name="otp" placeholder="•••••" maxlength="5" style="text-align:center; font-size:1.5rem; letter-spacing:0.4rem; font-weight:900;" dir="ltr" autofocus required>
                    </div>
                    <button type="submit" class="btn-submit">تایید کد و ورود به پیشخوان ↵</button>
                </form>

                <div class="timer-box" id="timerContainer">
                    ارسال مجدد کد تا <span id="countdownTimer" style="color: #38bdf8; font-weight: 800;">60</span> ثانیه دیگر
                </div>
                <div style="text-align: center; margin-top: 0.75rem;">
                    <a href="login.php?reset=1" id="resendLink" style="color: #64748b; font-size: 0.8rem; text-decoration: none; pointer-events: none;">ارسال مجدد کد تایید</a>
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
            <div class="register-link">
                حساب کاربری ندارید؟ <a href="register.php">ثبت‌نام کنید</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>