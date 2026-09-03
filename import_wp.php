<?php
// import_wp.php
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once 'db.php'; // اتصال به دیتابیس پنل جدید ($pdo)

// مشخصات دیتابیس وردپرس
$wp_db_host = 'localhost';
$wp_db_name = 'tyucdeii_hadi';
$wp_db_user = 'tyucdeii_hadi';
$wp_db_pass = 'Hadi@6098';

function clean_phone($num) {
    $fa = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
    $en = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    $num = str_replace($fa, $en, trim((string)$num));
    $num = preg_replace('/[^0-9]/', '', $num);
    if (strlen($num) === 10 && substr($num, 0, 1) === '9') {
        $num = '0' . $num;
    }
    return $num;
}

function clean_digits($str) {
    if (empty($str)) return null;
    $fa = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
    $en = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    return preg_replace('/[^0-9]/', '', str_replace($fa, $en, trim((string)$str)));
}

$updated = 0;
$imported = 0;
$skipped = 0;
$log = [];

try {
    $wp_pdo = new PDO("mysql:host={$wp_db_host};dbname={$wp_db_name};charset=utf8mb4", $wp_db_user, $wp_db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // یافتن جدول اصلی کاربران و یوزرمتا
    $allTables = $wp_pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $usersTable = null;
    $usermetaTable = null;

    foreach ($allTables as $table) {
        try {
            $cols = $wp_pdo->query("SHOW COLUMNS FROM `{$table}` LIKE 'user_login'")->fetchAll();
            if (!empty($cols)) {
                $usersTable = $table;
                $prefix = preg_replace('/users$/', '', $table);
                $usermetaTable = $prefix . 'usermeta';
                break;
            }
        } catch (Exception $e) {
            continue;
        }
    }

    if (!$usersTable) {
        throw new Exception("جدول کاربران وردپرس یافت نشد.");
    }

    $wp_users = $wp_pdo->query("SELECT * FROM `{$usersTable}`")->fetchAll();

    foreach ($wp_users as $w_user) {
        $wp_id = $w_user['ID'];

        // دریافت متادیتاهای کاربر
        $meta = [];
        if ($usermetaTable && in_array($usermetaTable, $allTables)) {
            $stmtMeta = $wp_pdo->prepare("SELECT meta_key, meta_value FROM `{$usermetaTable}` WHERE user_id = ?");
            $stmtMeta->execute([$wp_id]);
            $meta = $stmtMeta->fetchAll(PDO::FETCH_KEY_PAIR);
        }

        // استخراج شماره موبایل
        $phone = '';
        $possible_phone_keys = ['phone', 'mobile', 'billing_phone', 'digits_phone', 'user_phone', 'username', 'mobile_number', 'cellphone'];
        foreach ($possible_phone_keys as $k) {
            if (!empty($meta[$k])) {
                $clean = clean_phone($meta[$k]);
                if (strlen($clean) === 11 && substr($clean, 0, 2) === '09') {
                    $phone = $clean;
                    break;
                }
            }
        }

        if (empty($phone) && !empty($w_user['user_login'])) {
            $cleanLogin = clean_phone($w_user['user_login']);
            if (strlen($cleanLogin) === 11 && substr($cleanLogin, 0, 2) === '09') {
                $phone = $cleanLogin;
            }
        }

        if (empty($phone)) {
            $displayName = $w_user['display_name'] ?? $w_user['user_login'] ?? 'کاربر بدون نام';
            $skipped++;
            $log[] = "کاربر «{$displayName}» به دلیل عدم وجود شماره موبایل رد شد.";
            continue;
        }

        // ۱. نام و نام خانوادگی
        $first_name = trim($meta['first_name'] ?? '');
        $last_name  = trim($meta['last_name'] ?? '');
        $full_name  = trim($first_name . ' ' . $last_name);
        if (empty($full_name)) {
            $full_name = trim($w_user['display_name'] ?? '') ?: 'هنرجو';
        }

        // ۲. نام پدر (input_box_1787736920)
        $father_name = trim($meta['input_box_1787736920'] ?? ($meta['father_name'] ?? ''));

        // ۳. شماره ملی (input_box_1787736891)
        $raw_national = $meta['input_box_1787736891'] ?? ($meta['national_code'] ?? '');
        $national_code = clean_digits($raw_national);

        // ۴. تاریخ تولد (date_box_1786132214)
        $birth_date = trim($meta['date_box_1786132214'] ?? ($meta['birth_date'] ?? ''));

        // ۵. آدرس (input_box_1786171268)
        $address = trim($meta['input_box_1786171268'] ?? ($meta['billing_address_1'] ?? ($meta['address'] ?? '')));

        // ۶. سطح آموزشی (select_1786132487)
        $raw_level = trim($meta['select_1786132487'] ?? ($meta['skill_level'] ?? 'مبتدی'));
        $skill_level = 'مبتدی';
        if (!empty($raw_level)) {
            if (strpos($raw_level, 'پیشرفته') !== false || stripos($raw_level, 'advanced') !== false) {
                $skill_level = 'پیشرفته';
            } elseif (strpos($raw_level, 'فری استایل') !== false || strpos($raw_level, 'فری‌استایل') !== false || stripos($raw_level, 'freestyle') !== false) {
                $skill_level = 'فری استایل';
            } elseif (strpos($raw_level, 'سرعت') !== false || stripos($raw_level, 'speed') !== false) {
                $skill_level = 'سرعت';
            } elseif (strpos($raw_level, 'مبتدی') !== false || stripos($raw_level, 'beginner') !== false) {
                $skill_level = 'مبتدی';
            } else {
                $skill_level = $raw_level;
            }
        }

        $regDate = $w_user['user_registered'] ?? date('Y-m-d H:i:s');
        $password = $w_user['user_pass'] ?? password_hash('123456', PASSWORD_DEFAULT);

        // بررسی وجود کاربر در دیتابیس پنل جدید
        $check = $pdo->prepare("SELECT id FROM users WHERE phone = ? LIMIT 1");
        $check->execute([$phone]);
        $existing = $check->fetch();

        if ($existing) {
            // به‌روزرسانی دقیق مشخصات
            $upStmt = $pdo->prepare("
                UPDATE users SET 
                    first_name = ?,
                    last_name = ?,
                    full_name = ?,
                    father_name = ?,
                    national_code = ?,
                    birth_date = ?,
                    address = ?,
                    skill_level = ?
                WHERE id = ?
            ");
            $upStmt->execute([
                $first_name,
                $last_name,
                $full_name,
                $father_name,
                $national_code,
                $birth_date,
                $address,
                $skill_level,
                $existing['id']
            ]);
            $updated++;
        } else {
            // ایجاد کاربر جدید
            $ins = $pdo->prepare("
                INSERT INTO users 
                (first_name, last_name, full_name, father_name, national_code, birth_date, phone, password, address, skill_level, role, status, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'student', 'active', ?)
            ");
            $ins->execute([
                $first_name,
                $last_name,
                $full_name,
                $father_name,
                $national_code,
                $birth_date,
                $phone,
                $password,
                $address,
                $skill_level,
                $regDate
            ]);
            $imported++;
        }
    }

} catch (Exception $e) {
    die("<div style='color:#f87171; background:#111827; font-family:system-ui; direction:rtl; padding:2rem; border-radius:12px; margin:2rem auto; max-width:600px; border:1px solid #ef4444;'>
        <h3>خطا در عملیات:</h3>
        <p style='margin-top:10px; color:#cbd5e1;'>" . htmlspecialchars($e->getMessage()) . "</p>
    </div>");
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>تکمیل نهایی اطلاعات هنرجویان | باشگاه رادین اسکیت</title>
    <style>
        body { background:#0b1120; color:#f8fafc; font-family:system-ui, sans-serif; padding:2rem; }
        .box { max-width:650px; margin:0 auto; background:#111827; border:1px solid #1f2937; border-radius:14px; padding:2rem; box-shadow:0 10px 25px rgba(0,0,0,0.5); }
        .success { color:#4ade80; font-size:1.2rem; font-weight:800; margin-bottom:1rem; }
        .stat-badge { background:#1e293b; border:1px solid #334155; padding:0.6rem 1rem; border-radius:8px; margin-bottom:0.6rem; font-size:0.95rem; }
        .log-list { background:#1e293b; padding:1rem; border-radius:8px; font-size:0.85rem; max-height:200px; overflow-y:auto; color:#cbd5e1; margin-top:0.5rem; }
        .btn { display:inline-block; margin-top:1.5rem; background:#0284c7; color:#fff; padding:0.65rem 1.3rem; border-radius:8px; text-decoration:none; font-weight:700; }
    </style>
</head>
<body>
    <div class="box">
        <div class="success">✓ اطلاعات با موفقیت بر اساس کلیدهای متادیتا همگام‌سازی شد</div>
        
        <div class="stat-badge">🔄 <strong>تعداد پرونده‌های تکمیل و اصلاح‌شده:</strong> <?= $updated ?> نفر</div>
        <div class="stat-badge">➕ <strong>تعداد هنرجویان جدید ثبت‌شده:</strong> <?= $imported ?> نفر</div>
        <div class="stat-badge">⚠️ <strong>تعداد رکوردهای فاقد شماره همراه:</strong> <?= $skipped ?> نفر</div>
        
        <?php if (!empty($log)): ?>
            <h4 style="margin:1.2rem 0 0.5rem 0; font-size:0.9rem; color:#94a3b8;">گزارش موارد بدون شماره:</h4>
            <div class="log-list">
                <?php foreach ($log as $l): ?>
                    <div style="margin-bottom:4px;">• <?= htmlspecialchars($l) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <a href="users.php" class="btn">مشاهده جدول کامل هنرجویان در پیشخوان ↵</a>
    </div>
</body>
</html>