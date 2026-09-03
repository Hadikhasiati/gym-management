<?php
// settings.php
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

// ساخت و بررسی فیلدهای اختصاصی ملی‌پیامک، فعال‌سازی ثبت‌نام و مالی
try {
    $cols = $pdo->query("SHOW COLUMNS FROM `clubs`")->fetchAll(PDO::FETCH_COLUMN);
    
    if (!in_array('tuition_fee', $cols)) $pdo->exec("ALTER TABLE `clubs` ADD COLUMN `tuition_fee` BIGINT NULL DEFAULT 1500000");
    if (!in_array('zarinpal_merchant', $cols)) $pdo->exec("ALTER TABLE `clubs` ADD COLUMN `zarinpal_merchant` VARCHAR(100) NULL");
    if (!in_array('theme_color', $cols)) $pdo->exec("ALTER TABLE `clubs` ADD COLUMN `theme_color` VARCHAR(20) DEFAULT '#0284c7'");
    
    // ستون‌های ملی‌پیامک
    if (!in_array('sms_username', $cols)) $pdo->exec("ALTER TABLE `clubs` ADD COLUMN `sms_username` VARCHAR(100) NULL");
    if (!in_array('sms_password', $cols)) $pdo->exec("ALTER TABLE `clubs` ADD COLUMN `sms_password` VARCHAR(100) NULL");
    if (!in_array('sms_pattern_otp', $cols)) $pdo->exec("ALTER TABLE `clubs` ADD COLUMN `sms_pattern_otp` VARCHAR(50) NULL");
    if (!in_array('sms_pattern_activation', $cols)) $pdo->exec("ALTER TABLE `clubs` ADD COLUMN `sms_pattern_activation` VARCHAR(50) NULL");
    if (!in_array('sms_pattern_tuition', $cols)) $pdo->exec("ALTER TABLE `clubs` ADD COLUMN `sms_pattern_tuition` VARCHAR(50) NULL");
} catch (Exception $e) {}

$msg = '';
$error = '';

// ==========================================
// ذخیره تنظیمات ملی‌پیامک و مالی
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_settings') {
    $club_name         = trim($_POST['club_name'] ?? CURRENT_CLUB_NAME);
    $theme_color       = trim($_POST['theme_color'] ?? '#0284c7');
    $zarinpal_merchant = trim($_POST['zarinpal_merchant'] ?? '');
    
    // تمیزکاری شهریه
    $raw_fee = (string)($_POST['tuition_fee'] ?? '1500000');
    $persian = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    $arabic  = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
    $english = ['0','1','2','3','4','5','6','7','8','9'];
    $clean_fee = str_replace($arabic, $english, str_replace($persian, $english, $raw_fee));
    $tuition_fee = (int)preg_replace('/[^0-9]/', '', $clean_fee);
    if ($tuition_fee <= 0) $tuition_fee = 1500000;

    // فیلدهای ملی‌پیامک
    $sms_username           = trim($_POST['sms_username'] ?? '');
    $sms_password           = trim($_POST['sms_password'] ?? '');
    $sms_pattern_otp        = trim($_POST['sms_pattern_otp'] ?? '');
    $sms_pattern_activation = trim($_POST['sms_pattern_activation'] ?? '');
    $sms_pattern_tuition    = trim($_POST['sms_pattern_tuition'] ?? '');

    try {
        $stmtUp = $pdo->prepare("
            UPDATE clubs 
            SET name = ?, theme_color = ?, zarinpal_merchant = ?, tuition_fee = ?,
                sms_username = ?, sms_password = ?,
                sms_pattern_otp = ?, sms_pattern_activation = ?, sms_pattern_tuition = ?
            WHERE id = ?
        ");
        $stmtUp->execute([
            $club_name, $theme_color, $zarinpal_merchant, $tuition_fee,
            $sms_username, $sms_password,
            $sms_pattern_otp, $sms_pattern_activation, $sms_pattern_tuition,
            CURRENT_CLUB_ID
        ]);
        $msg = 'تنظیمات سامانه ملی‌پیامک، پترن فعال‌سازی و شهریه با موفقیت به‌روزرسانی شد.';
    } catch (Exception $e) {
        $error = 'خطا در ثبت اطلاعات: ' . $e->getMessage();
    }
}

// واکشی اطلاعات جاری باشگاه
$stmtClub = $pdo->prepare("SELECT * FROM clubs WHERE id = ? LIMIT 1");
$stmtClub->execute([CURRENT_CLUB_ID]);
$club_data = $stmtClub->fetch(PDO::FETCH_ASSOC) ?: [];

$current_fee            = (int)($club_data['tuition_fee'] ?? 1500000);
$current_merchant       = (string)($club_data['zarinpal_merchant'] ?? '');
$current_name           = (string)($club_data['name'] ?? CURRENT_CLUB_NAME);
$current_theme          = (string)($club_data['theme_color'] ?? CURRENT_CLUB_THEME);
$sms_username           = (string)($club_data['sms_username'] ?? '');
$sms_password           = (string)($club_data['sms_password'] ?? '');
$sms_pattern_otp        = (string)($club_data['sms_pattern_otp'] ?? '');
$sms_pattern_activation = (string)($club_data['sms_pattern_activation'] ?? '');
$sms_pattern_tuition    = (string)($club_data['sms_pattern_tuition'] ?? '');
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>تنظیمات باشگاه و ملی‌پیامک | <?= htmlspecialchars($current_name) ?></title>
    <style>
        :root { --primary: <?= htmlspecialchars($current_theme) ?>; }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: system-ui, -apple-system, sans-serif; }
        body { background-color: #0b1120; color: #f8fafc; min-height: 100vh; padding: 1.2rem 0.9rem; line-height: 1.5; }
        .container { max-width: 680px; margin: 0 auto; }
        
        .header-bar { display: flex; justify-content: space-between; align-items: center; background: rgba(30, 41, 59, 0.7); border: 1px solid rgba(255,255,255,0.08); backdrop-filter: blur(12px); border-radius: 16px; padding: 0.85rem 1.1rem; margin-bottom: 1.25rem; }
        .btn-back { background: #1e293b; color: #38bdf8; border: 1px solid #334155; padding: 0.45rem 0.9rem; border-radius: 8px; text-decoration: none; font-size: 0.82rem; font-weight: 700; }

        .card { background: rgba(17, 24, 39, 0.85); border: 1px solid rgba(255,255,255,0.08); border-radius: 20px; padding: 1.4rem; margin-bottom: 1.25rem; box-shadow: 0 8px 25px rgba(0,0,0,0.3); }
        .card-title { font-size: 1.05rem; font-weight: 800; color: #38bdf8; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.5rem; }

        .form-group { margin-bottom: 1.2rem; display: flex; flex-direction: column; gap: 0.4rem; }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0.85rem; }
        label { font-size: 0.84rem; color: #94a3b8; font-weight: 700; }
        .hint { font-size: 0.75rem; color: #64748b; margin-top: 2px; }
        
        .input-box { background: #1e293b; border: 1px solid #334155; border-radius: 10px; padding: 0.75rem 0.9rem; color: #fff; font-size: 0.92rem; outline: none; width: 100%; transition: border-color 0.2s; }
        .input-box:focus { border-color: var(--primary); }

        .fee-preview { font-size: 0.85rem; color: #fbbf24; font-weight: 800; margin-top: 0.35rem; }

        .btn-submit { width: 100%; height: 50px; background: linear-gradient(135deg, #0284c7, #0369a1); color: #fff; border: none; border-radius: 12px; font-size: 1rem; font-weight: 800; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 0.5rem; box-shadow: 0 6px 20px rgba(2, 132, 199, 0.4); margin-top: 1.25rem; }
        .btn-submit:hover { opacity: 0.95; }

        .alert-success { background: rgba(16, 185, 129, 0.15); border: 1px solid #10b981; color: #34d399; padding: 0.85rem; border-radius: 12px; margin-bottom: 1.25rem; font-size: 0.88rem; }
        .alert-error { background: rgba(239, 68, 68, 0.15); border: 1px solid #ef4444; color: #f87171; padding: 0.85rem; border-radius: 12px; margin-bottom: 1.25rem; font-size: 0.88rem; }

        @media (max-width: 550px) {
            .grid-2 { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header-bar">
            <div>
                <h2 style="font-size: 1.05rem; color: #38bdf8;">⚙️ تنظیمات مدیریت باشگاه</h2>
                <div style="font-size: 0.75rem; color: #64748b;"><?= htmlspecialchars($current_name) ?></div>
            </div>
            <a href="dashboard.php" class="btn-back">بازگشت ↵</a>
        </div>

        <?php if ($msg): ?><div class="alert-success">✓ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <form method="POST">
            <input type="hidden" name="action" value="save_settings">

            <!-- بخش ۱: سامانه ملی‌پیامک (Melipayamak) -->
            <div class="card">
                <div class="card-title">📱 اتصال به سامانه ملی‌پیامک (Melipayamak)</div>
                
                <div class="grid-2">
                    <div class="form-group">
                        <label>نام کاربری پنل ملی‌پیامک *</label>
                        <input type="text" name="sms_username" value="<?= htmlspecialchars($sms_username) ?>" class="input-box" placeholder="مثلاً: 0912xxxxxxx یا نام کاربری" dir="ltr" required>
                    </div>
                    <div class="form-group">
                        <label>کلمه عبور پنل ملی‌پیامک *</label>
                        <input type="password" name="sms_password" value="<?= htmlspecialchars($sms_password) ?>" class="input-box" placeholder="••••••••" dir="ltr" required>
                    </div>
                </div>

                <div style="border-top: 1px dashed rgba(255,255,255,0.08); margin: 1rem 0 0.8rem 0;"></div>
                <div style="font-size:0.85rem; font-weight:800; color:#cbd5e1; margin-bottom:0.75rem;">کد متن الگوهای خدماتی ملی‌پیامک (BodyId):</div>

                <!-- پترن‌های تایید ورود و فعالسازی ثبت‌نام -->
                <div class="grid-2">
                    <div class="form-group">
                        <label>کد پترن کد تایید ورود (OTP)</label>
                        <input type="text" name="sms_pattern_otp" value="<?= htmlspecialchars($sms_pattern_otp) ?>" class="input-box" placeholder="مثلاً: 123456" dir="ltr">
                        <div class="hint">ارسال رمز یکبار مصرف ورود با متغیر {0}.</div>
                    </div>
                    <div class="form-group">
                        <label>کد پترن فعال‌سازی حساب پس از ثبت‌نام</label>
                        <input type="text" name="sms_pattern_activation" value="<?= htmlspecialchars($sms_pattern_activation) ?>" class="input-box" placeholder="مثلاً: 123457" dir="ltr">
                        <div class="hint">پیامک خوش‌آمدگویی و فعال‌سازی حساب کاربری هنرجو.</div>
                    </div>
                </div>

                <!-- پترن یادآوری شهریه -->
                <div class="form-group">
                    <label>کد پترن یادآوری اتمام شهریه</label>
                    <input type="text" name="sms_pattern_tuition" value="<?= htmlspecialchars($sms_pattern_tuition) ?>" class="input-box" placeholder="مثلاً: 654321" dir="ltr">
                    <div class="hint">کد عددی الگوی تایید شده برای اطلاع‌رسانی پایان دوره و تمدید.</div>
                </div>
            </div>

            <!-- بخش ۲: شهریه و زرین‌پال -->
            <div class="card">
                <div class="card-title">💳 شهریه و درگاه پرداخت آنلاین</div>
                
                <div class="form-group">
                    <label>مبلغ شهریه دوره ماهانه (تومان) *</label>
                    <input type="text" name="tuition_fee" id="tuitionInput" value="<?= number_format($current_fee) ?>" class="input-box" style="font-weight:900; font-size:1.1rem; color:#fbbf24;" oninput="formatNumber(this)" dir="ltr" required>
                    <div class="fee-preview" id="feeInWords">معادل: <?= number_format($current_fee) ?> تومان</div>
                    <div class="hint">این مبلغ مبنای تراکنش آنلاین هنرجو است.</div>
                </div>

                <div class="form-group">
                    <label>مرچنت‌کد درگاه زرین‌پال (Merchant ID)</label>
                    <input type="text" name="zarinpal_merchant" value="<?= htmlspecialchars($current_merchant) ?>" class="input-box" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" dir="ltr">
                    <div class="hint">کد ۳۶ کاراکتری درگاه باشگاه در زرین‌پال.</div>
                </div>
            </div>

            <!-- بخش ۳: تم و اطلاعات باشگاه -->
            <div class="card">
                <div class="card-title" style="color:#fff;">🎨 اطلاعات و ظاهر باشگاه</div>

                <div class="form-group">
                    <label>نام رسمی باشگاه</label>
                    <input type="text" name="club_name" value="<?= htmlspecialchars($current_name) ?>" class="input-box" required>
                </div>

                <div class="form-group">
                    <label>رنگ سازمانی و تم پنل</label>
                    <div style="display:flex; gap:0.5rem; align-items:center;">
                        <input type="color" name="theme_color" value="<?= htmlspecialchars($current_theme) ?>" style="width:50px; height:42px; border:none; border-radius:8px; cursor:pointer; background:none;">
                        <input type="text" value="<?= htmlspecialchars($current_theme) ?>" class="input-box" readonly style="flex:1;">
                    </div>
                </div>

                <button type="submit" class="btn-submit">💾 ذخیره تنظیمات ملی‌پیامک و باشگاه</button>
            </div>
        </form>
    </div>

    <script>
        function formatNumber(input) {
            let val = input.value.replace(/[^0-9]/g, '');
            if (val === '') {
                document.getElementById('feeInWords').innerText = '';
                return;
            }
            let formatted = Number(val).toLocaleString('en-US');
            input.value = formatted;
            document.getElementById('feeInWords').innerText = 'معادل: ' + formatted + ' تومان (' + (Number(val) * 10).toLocaleString('en-US') + ' ریال)';
        }
    </script>
</body>
</html>