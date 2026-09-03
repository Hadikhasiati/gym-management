<?php
// superadmin.php
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/db.php';
if (file_exists(__DIR__ . '/tenant.php')) {
    require_once __DIR__ . '/tenant.php';
}
require_once __DIR__ . '/auth.php';

$current_user = check_auth();

// اعتبارسنجی دسترسی سوپرادمین (تنها ادمین باشگاه اصلی با شناسه ۱)
if ((int)($current_user['club_id'] ?? 0) !== 1 || ($current_user['role'] ?? '') !== 'admin') {
    die("<div style='direction:rtl; text-align:center; padding:3rem; font-family:tahoma; color:#ef4444; background:#0b1120;'>
            <h2>خطای عدم دسترسی</h2>
            <p style='color:#94a3b8; margin-top:0.5rem;'>این بخش منحصراً در اختیار مدیریت کل سامانه (Super Admin) می‌باشد.</p>
            <a href='dashboard.php' style='display:inline-block; margin-top:1rem; color:#38bdf8; text-decoration:none;'>بازگشت به پیشخوان</a>
         </div>");
}

// توابع تاریخ شمسی
if (!function_exists('gregorian_to_jalali')) {
    function gregorian_to_jalali($gy, $gm, $gd) {
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
    function to_jalali_date($g_date) {
        if (empty($g_date)) return '-';
        $parts = explode('-', substr($g_date, 0, 10));
        if (count($parts) !== 3) return '-';
        list($jy, $jm, $jd) = gregorian_to_jalali((int)$parts[0], (int)$parts[1], (int)$parts[2]);
        return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
    }
}

function sanitize_digits(?string $input): string {
    if ($input === null) return '';
    $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $arabic  = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
    $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    return trim(str_replace($arabic, $english, str_replace($persian, $english, $input)));
}

$msg = '';
$error = '';

// =======================================================
// ۱. ثبت باشگاه جدید و ساخت خودکار اکانت ادمین
// =======================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_club') {
    $club_name    = trim($_POST['club_name'] ?? '');
    $subdomain    = strtolower(trim($_POST['subdomain'] ?? ''));
    $owner_name   = trim($_POST['owner_name'] ?? '');
    $owner_phone  = sanitize_digits($_POST['owner_phone'] ?? '');
    $admin_pass   = trim($_POST['admin_password'] ?? '');
    $plan_months  = max(1, (int)($_POST['plan_months'] ?? 1));
    $theme_color  = trim($_POST['theme_color'] ?? '#0284c7');
    $monthly_fee  = (int)($_POST['monthly_tuition'] ?? 500000);

    // اعتبارسنجی کاراکترهای ساب‌دامین (فقط حروف انگلیسی، اعداد و خط تیره)
    if (!preg_match('/^[a-z0-9\-]{2,30}$/', $subdomain)) {
        $error = 'ساب‌دامین نامعتبر است. فقط از حروف کوچک انگلیسی (a-z)، اعداد و خط تیره استفاده کنید.';
    } elseif (in_array($subdomain, ['www', 'mail', 'cpanel', 'webmail', 'api', 'admin', 'root'])) {
        $error = 'این ساب‌دامین جزو نام‌های رزرو شده سامانه است.';
    } elseif (empty($club_name) || empty($owner_name) || empty($owner_phone) || empty($admin_pass)) {
        $error = 'لطفاً تمامی فیلدهای الزامی را پر کنید.';
    } else {
        // بررسی یکتایی ساب‌دامین
        $checkSub = $pdo->prepare("SELECT id FROM clubs WHERE subdomain = ? LIMIT 1");
        $checkSub->execute([$subdomain]);
        if ($checkSub->fetch()) {
            $error = 'این ساب‌دامین قبلاً توسط باشگاه دیگری ثبت شده است.';
        } else {
            // محاسبه تاریخ انقضای اشتراک
            $expires_at = date('Y-m-d', strtotime("+{$plan_months} months"));

            // ثبت باشگاه
            $stmtInsClub = $pdo->prepare("
                INSERT INTO clubs (name, subdomain, owner_name, owner_phone, theme_color, monthly_tuition, status, plan_expires_at, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 'active', ?, NOW())
            ");
            $stmtInsClub->execute([
                $club_name,
                $subdomain,
                $owner_name,
                $owner_phone,
                $theme_color,
                $monthly_fee,
                $expires_at
            ]);
            $new_club_id = (int)$pdo->lastInsertId();

            // ساخت خودکار حساب ادمین برای این باشگاه
            $hashed_pass = password_hash($admin_pass, PASSWORD_DEFAULT);
            $auth_token = bin2hex(random_bytes(32));

            $stmtInsAdmin = $pdo->prepare("
                INSERT INTO users (club_id, full_name, phone, password, role, skill_level, auth_token, created_at)
                VALUES (?, ?, ?, ?, 'admin', 'مدیریت', ?, NOW())
            ");
            $stmtInsAdmin->execute([
                $new_club_id,
                $owner_name,
                $owner_phone,
                $hashed_pass,
                $auth_token
            ]);

            $msg = "باشگاه «{$club_name}» با موفقیت با ساب‌دامین {$subdomain} ایجاد و اکانت مدیر فعال شد.";
        }
    }
}

// =======================================================
// ۲. تمدید یا تغییر وضعیت اشتراک باشگاه‌ها
// =======================================================
if (isset($_GET['extend_club'])) {
    $target_club_id = (int)$_GET['extend_club'];
    $months = max(1, (int)($_GET['months'] ?? 1));

    $stmtGetExp = $pdo->prepare("SELECT plan_expires_at FROM clubs WHERE id = ? LIMIT 1");
    $stmtGetExp->execute([$target_club_id]);
    $current_exp = $stmtGetExp->fetchColumn();

    $base = (empty($current_exp) || $current_exp < date('Y-m-d')) ? date('Y-m-d') : $current_exp;
    $new_exp = date('Y-m-d', strtotime("{$base} +{$months} months"));

    $pdo->prepare("UPDATE clubs SET plan_expires_at = ?, status = 'active' WHERE id = ?")->execute([$new_exp, $target_club_id]);
    header("Location: superadmin.php?msg=extended");
    exit;
}

if (isset($_GET['toggle_status'])) {
    $target_club_id = (int)$_GET['toggle_status'];
    $stmtStatus = $pdo->prepare("SELECT status FROM clubs WHERE id = ? LIMIT 1");
    $stmtStatus->execute([$target_club_id]);
    $current_status = $stmtStatus->fetchColumn();

    $new_status = ($current_status === 'active') ? 'inactive' : 'active';
    $pdo->prepare("UPDATE clubs SET status = ? WHERE id = ?")->execute([$new_status, $target_club_id]);
    header("Location: superadmin.php?msg=status_changed");
    exit;
}

// واکشی لیست کلیه باشگاه‌ها به همراه تعداد هنرجویان هرکدام
$clubs_query = $pdo->query("
    SELECT c.*, 
           (SELECT COUNT(*) FROM users u WHERE u.club_id = c.id AND u.role = 'student') as student_count,
           (SELECT COUNT(*) FROM payments p WHERE p.club_id = c.id AND p.status = 'success') as payment_count
    FROM clubs c 
    ORDER BY c.id ASC
");
$clubs = $clubs_query->fetchAll(PDO::FETCH_ASSOC);

// آمار کل پلتفرم
$total_clubs = count($clubs);
$active_clubs = 0;
$total_platform_students = 0;

foreach ($clubs as $c) {
    if ($c['status'] === 'active' && $c['plan_expires_at'] >= date('Y-m-d')) {
        $active_clubs++;
    }
    $total_platform_students += (int)$c['student_count'];
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مدیریت کل پلتفرم SaaS | سوپرادمین</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: system-ui, -apple-system, sans-serif; }
        body { background-color: #0b1120; color: #f8fafc; min-height: 100vh; padding: 1.5rem 1rem; }
        .container { max-width: 1200px; margin: 0 auto; }
        
        .header-bar {
            display: flex; justify-content: space-between; align-items: center; background: linear-gradient(135deg, #1e1b4b, #0f172a);
            border: 1px solid #4338ca; padding: 1.25rem 1.5rem; border-radius: 16px; margin-bottom: 1.5rem;
            box-shadow: 0 8px 25px rgba(0,0,0,0.4);
        }
        .header-title h1 { font-size: 1.3rem; font-weight: 800; color: #a5b4fc; }
        .header-title p { font-size: 0.82rem; color: #94a3b8; margin-top: 3px; }
        .btn-dash { background: #1e293b; color: #38bdf8; border: 1px solid #334155; padding: 0.5rem 1.1rem; border-radius: 8px; text-decoration: none; font-size: 0.85rem; font-weight: 700; }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
        .stat-card { background: #111827; border: 1px solid #1f2937; border-radius: 14px; padding: 1.2rem; }
        .stat-card.purple { border-top: 4px solid #818cf8; }
        .stat-card.green { border-top: 4px solid #34d399; }
        .stat-card.blue { border-top: 4px solid #38bdf8; }
        .stat-label { font-size: 0.82rem; color: #94a3b8; font-weight: 600; margin-bottom: 0.35rem; }
        .stat-value { font-size: 1.6rem; font-weight: 800; color: #fff; }

        .card { background: #111827; border: 1px solid #1f2937; border-radius: 16px; padding: 1.5rem; margin-bottom: 1.5rem; }
        .card h3 { font-size: 1.1rem; color: #38bdf8; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.5rem; }

        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 1rem; }
        .form-group { display: flex; flex-direction: column; gap: 0.4rem; }
        label { font-size: 0.82rem; color: #94a3b8; font-weight: 600; }
        input, select { background: #1e293b; border: 1px solid #334155; border-radius: 8px; padding: 0.65rem 0.85rem; color: #fff; font-size: 0.92rem; outline: none; }
        input:focus, select:focus { border-color: #38bdf8; }
        .btn-submit { background: linear-gradient(135deg, #4f46e5, #6366f1); color: #fff; border: none; padding: 0.85rem 1.75rem; border-radius: 8px; font-weight: 700; cursor: pointer; font-size: 0.95rem; }
        .btn-submit:hover { opacity: 0.9; }

        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; text-align: right; font-size: 0.88rem; min-width: 800px; }
        th, td { padding: 0.9rem; border-bottom: 1px solid #1f2937; vertical-align: middle; }
        th { color: #94a3b8; background: #151f30; }

        .badge-active { background: #10b98125; color: #34d399; border: 1px solid #10b981; padding: 0.25rem 0.6rem; border-radius: 6px; font-weight: 700; font-size: 0.75rem; }
        .badge-expired { background: #ef444425; color: #f87171; border: 1px solid #ef4444; padding: 0.25rem 0.6rem; border-radius: 6px; font-weight: 700; font-size: 0.75rem; }
        
        .btn-ext { background: #0284c725; color: #38bdf8; border: 1px solid #0284c7; padding: 0.35rem 0.65rem; border-radius: 6px; text-decoration: none; font-size: 0.78rem; font-weight: 700; margin-left: 4px; }
        .btn-ext:hover { background: #0284c7; color: #fff; }
        .btn-toggle { background: #f59e0b25; color: #fbbf24; border: 1px solid #f59e0b; padding: 0.35rem 0.65rem; border-radius: 6px; text-decoration: none; font-size: 0.78rem; font-weight: 700; }
        
        .alert-success { background: #10b98120; border: 1px solid #10b981; color: #34d399; padding: 0.85rem; border-radius: 10px; margin-bottom: 1.25rem; }
        .alert-error { background: #ef444420; border: 1px solid #ef4444; color: #f87171; padding: 0.85rem; border-radius: 10px; margin-bottom: 1.25rem; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header-bar">
            <div class="header-title">
                <h1>⚡ مرکز مدیریت کل پلتفرم (SaaS SuperAdmin)</h1>
                <p>مدیریت باشگاه‌ها، دامنه‌ها، اشتراک‌ها و صدور مجوزها</p>
            </div>
            <a href="dashboard.php" class="btn-dash">ورود به پنل رادین اسکیت ↵</a>
        </div>

        <?php if (!empty($msg) || (isset($_GET['msg']) && $_GET['msg'] === 'extended')): ?>
            <div class="alert-success"><?= htmlspecialchars($msg ?: '✓ اشتراک باشگاه مورد نظر با موفقیت تمدید شد.') ?></div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card purple">
                <div class="stat-label">🏢 کل باشگاه‌های ثبت‌شده</div>
                <div class="stat-value"><?= number_format($total_clubs) ?> <span style="font-size:0.85rem; font-weight:normal;">باشگاه</span></div>
            </div>
            <div class="stat-card green">
                <div class="stat-label">✓ باشگاه‌های فعال</div>
                <div class="stat-value" style="color:#4ade80;"><?= number_format($active_clubs) ?></div>
            </div>
            <div class="stat-card blue">
                <div class="stat-label">👥 مجموع هنرجویان کل پلتفرم</div>
                <div class="stat-value" style="color:#38bdf8;"><?= number_format($total_platform_students) ?> <span style="font-size:0.85rem; font-weight:normal;">نفر</span></div>
            </div>
        </div>

        <!-- فرم ثبت باشگاه جدید -->
        <div class="card">
            <h3>➕ ثبت باشگاه / مشتری جدید در پلتفرم</h3>
            <form method="POST" action="superadmin.php">
                <input type="hidden" name="action" value="create_club">
                <div class="form-grid">
                    <div class="form-group">
                        <label>نام باشگاه یا مجموعه ورزشی *</label>
                        <input type="text" name="club_name" placeholder="مثال: باشگاه اسکیت پرواز" required>
                    </div>

                    <div class="form-group">
                        <label>ساب‌دامین اختصاصی (انگلیسی) *</label>
                        <input type="text" name="subdomain" placeholder="مثال: parvaz" dir="ltr" required>
                    </div>

                    <div class="form-group">
                        <label>نام مدیر باشگاه *</label>
                        <input type="text" name="owner_name" placeholder="مثال: محمد احمدی" required>
                    </div>

                    <div class="form-group">
                        <label>شماره موبایل مدیر (نام کاربری ورود) *</label>
                        <input type="text" name="owner_phone" placeholder="09xxxxxxxxx" dir="ltr" maxlength="11" required>
                    </div>

                    <div class="form-group">
                        <label>رمز عبور اولیه مدیر باشگاه *</label>
                        <input type="password" name="admin_password" placeholder="••••••••" dir="ltr" required>
                    </div>

                    <div class="form-group">
                        <label>مدت زمان اولیه اشتراک *</label>
                        <select name="plan_months">
                            <option value="1">۱ ماهه</option>
                            <option value="3">۳ ماهه (فصلی)</option>
                            <option value="6">۶ ماهه (نیم‌سال)</option>
                            <option value="12" selected>۱۲ ماهه (یک‌ساله)</option>
                            <option value="24">۲۴ ماهه (دو ساله)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>رنگ سازمانی تم باشگاه</label>
                        <input type="text" name="theme_color" value="#0284c7" dir="ltr" placeholder="#0284c7">
                    </div>

                    <div class="form-group">
                        <label>مبلغ پیش‌فرض شهریه هنرجویان (تومان)</label>
                        <input type="number" name="monthly_tuition" value="600000" dir="ltr">
                    </div>
                </div>

                <button type="submit" class="btn-submit">🚀 ساخت باشگاه و فعال‌سازی اکانت</button>
            </form>
        </div>

        <!-- جدول لیست باشگاه‌ها -->
        <div class="card">
            <h3>🏢 لیست باشگاه‌های پلتفرم و وضعیت اشتراک</h3>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>شناسه</th>
                            <th>نام باشگاه</th>
                            <th>ساب‌دامین / آدرس</th>
                            <th>مدیریت</th>
                            <th>تعداد هنرجو</th>
                            <th>انقضای اشتراک (شمسی)</th>
                            <th>وضعیت</th>
                            <th>عملیات تمدید و مجوز</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($clubs as $c): 
                            $is_expired = ($c['plan_expires_at'] < date('Y-m-d') || $c['status'] !== 'active');
                            $club_url = "https://" . $c['subdomain'] . ".radinskateomd.ir";
                        ?>
                            <tr>
                                <td><strong>#<?= $c['id'] ?></strong></td>
                                <td>
                                    <span style="font-weight:700; color:#fff;"><?= htmlspecialchars($c['name']) ?></span>
                                    <div style="font-size:0.75rem; color:#64748b;">رنگ تم: <?= htmlspecialchars($c['theme_color']) ?></div>
                                </td>
                                <td>
                                    <a href="<?= $club_url ?>" target="_blank" style="color:#38bdf8; text-decoration:none; font-family:monospace; font-weight:700;">
                                        <?= htmlspecialchars($c['subdomain']) ?>.radinskateomd.ir ↗
                                    </a>
                                </td>
                                <td>
                                    <div><?= htmlspecialchars($c['owner_name']) ?></div>
                                    <div style="font-size:0.75rem; color:#94a3b8; font-family:monospace;"><?= htmlspecialchars($c['owner_phone']) ?></div>
                                </td>
                                <td><strong style="color:#a5b4fc;"><?= number_format($c['student_count']) ?></strong> نفر</td>
                                <td style="font-weight:700; color:#38bdf8; font-family:monospace;">
                                    <?= htmlspecialchars(to_jalali_date($c['plan_expires_at'])) ?>
                                </td>
                                <td>
                                    <?= !$is_expired ? '<span class="badge-active">✓ فعال</span>' : '<span class="badge-expired">✕ منقضی / مسدود</span>' ?>
                                </td>
                                <td>
                                    <a href="superadmin.php?extend_club=<?= $c['id'] ?>&months=1" class="btn-ext" title="تمدید ۱ ماهه">+۱ ماه</a>
                                    <a href="superadmin.php?extend_club=<?= $c['id'] ?>&months=12" class="btn-ext" title="تمدید یکساله">+۱ سال</a>
                                    <a href="superadmin.php?toggle_status=<?= $c['id'] ?>" class="btn-toggle">
                                        <?= ($c['status'] === 'active') ? 'مسدودسازی' : 'فعال‌سازی' ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>