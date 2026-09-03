<?php
session_start();

// ۱. بررسی ورود کاربر
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// ۲. اتصال به دیتابیس و دریافت اطلاعات کاربر جاری
require_once 'config/database.php';
$userId = (int) $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    die("کاربر یافت نشد.");
}

// ۳. دریافت جلسات حضور کاربر در ماه جاری از جدول attendance
// در صورت داشتن فایل jdf.php می‌توانید نام ماه و روز شمسی دقیق را لود کنید
$currentMonthName = "ماه جاری";
if (file_exists('includes/jdf.php')) {
    require_once 'includes/jdf.php';
    $currentMonthName = jdate('F');
}

$daysInThisMonth = 31; // تعداد روزهای ماه
$attendedDays = [];

try {
    // خواندن تاریخ‌های حضور
    $attStmt = $pdo->prepare("SELECT session_date FROM attendance WHERE user_id = ? AND status = 'present'");
    $attStmt->execute([$userId]);
    $sessions = $attStmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($sessions as $dateStr) {
        $timestamp = strtotime($dateStr);
        if ($timestamp) {
            // در صورت استفاده از jdf روز شمسی، وگرنه روز میلادی
            $dayNum = function_exists('jdate') ? (int)jdate('d', $timestamp) : (int)date('d', $timestamp);
            $attendedDays[] = $dayNum;
        }
    }
} catch (Exception $e) {
    // در صورت خالی بودن دیتابیس
    $attendedDays = [];
}

$presentCount = count($attendedDays);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>پروفایل کاربری</title>
    <style>
        * {
            box-sizing: border-box;
            font-family: Tahoma, 'Vazir', sans-serif;
        }
        body {
            background-color: #f4f6f9;
            margin: 0;
            padding: 20px;
        }
        .profile-container {
            max-width: 800px;
            margin: 20px auto;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        .card {
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            padding: 25px;
        }

        /* چیدمان اطلاعات کاربری و تصویر */
        .profile-header {
            display: flex;
            align-items: center;
            gap: 25px;
        }
        .avatar-container {
            position: relative;
            flex-shrink: 0;
        }
        .avatar-img {
            width: 110px;
            height: 110px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #007bff;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            display: block;
        }
        .avatar-upload-btn {
            position: absolute;
            bottom: 2px;
            right: 2px;
            background: #007bff;
            color: #fff;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            border: 2px solid #fff;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
            font-size: 14px;
        }
        .user-info-overview {
            flex-grow: 1;
        }
        .user-name {
            margin: 0 0 10px 0;
            font-size: 19px;
            color: #2c3e50;
        }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
            gap: 10px;
        }
        .info-item {
            background: #f8f9fa;
            padding: 8px 12px;
            border-radius: 6px;
            border-right: 3px solid #007bff;
        }
        .info-label {
            font-size: 11px;
            color: #777;
            display: block;
            margin-bottom: 2px;
        }
        .info-value {
            font-size: 13px;
            font-weight: bold;
            color: #333;
        }
        .level-badge {
            background: #28a745;
            color: #fff;
            padding: 2px 7px;
            border-radius: 4px;
            font-size: 11px;
        }

        /* استایل کادر مربعی حضور و غیاب */
        .attendance-widget-box {
            width: 100%;
            max-width: 320px;
            background: #ffffff;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            padding: 15px;
            margin: 0 auto;
            box-sizing: border-box;
        }
        .att-widget-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 2px solid #f1f5f9;
            padding-bottom: 8px;
            margin-bottom: 12px;
        }
        .att-widget-title {
            font-size: 14px;
            font-weight: bold;
            color: #1e293b;
            margin: 0;
        }
        .att-count-tag {
            background: #dcfce7;
            color: #166534;
            font-size: 11px;
            font-weight: bold;
            padding: 3px 8px;
            border-radius: 10px;
        }
        .att-days-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 4px;
        }
        .att-day-item {
            aspect-ratio: 1 / 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            color: #64748b;
            font-size: 11px;
        }
        .att-day-item.present {
            background: #dcfce7;
            border-color: #22c55e;
            color: #15803d;
            font-weight: bold;
        }
        .att-day-item .check-mark {
            font-size: 9px;
            line-height: 1;
            color: #16a34a;
        }

        @media (max-width: 650px) {
            .profile-header {
                flex-direction: column;
                text-align: center;
            }
        }
    </style>
</head>
<body>

<div class="profile-container">

    <!-- کارت اول: تصویر و مشخصات کاربر -->
    <div class="card">
        <div class="profile-header">
            <div class="avatar-container">
                <img id="avatar-preview" 
                     class="avatar-img"
                     src="<?= !empty($user['avatar']) ? 'uploads/avatars/' . htmlspecialchars($user['avatar']) : 'assets/img/default-avatar.png'; ?>" 
                     alt="تصویر پروفایل">
                
                <label for="avatar-input" class="avatar-upload-btn" title="تغییر عکس">📷</label>
                <input type="file" id="avatar-input" name="avatar" accept="image/jpeg,image/png,image/webp" style="display: none;">
                <div id="upload-status" style="font-size: 12px; margin-top: 5px;"></div>
            </div>

            <div class="user-info-overview">
                <h2 class="user-name"><?= htmlspecialchars($user['name'] ?? 'هنرجو'); ?></h2>
                <div class="info-grid">
                    <div class="info-item">
                        <span class="info-label">سن</span>
                        <span class="info-value"><?= !empty($user['age']) ? htmlspecialchars($user['age']) . ' سال' : 'ثبت نشده'; ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">سطح مهارت</span>
                        <span class="info-value">
                            <span class="level-badge"><?= htmlspecialchars($user['level'] ?? 'مقدماتی'); ?></span>
                        </span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">شماره همراه</span>
                        <span class="info-value" dir="ltr"><?= htmlspecialchars($user['phone'] ?? '---'); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- کارت دوم: ویجت مربعی حضور و غیاب -->
    <div class="card" style="text-align: center;">
        <h3 style="margin-top: 0; margin-bottom: 15px; font-size: 16px; color: #333;">وضعیت حضور در کلاس‌ها</h3>
        
        <div class="attendance-widget-box">
            <div class="att-widget-header">
                <h4 class="att-widget-title">🗓️ <?= htmlspecialchars($currentMonthName); ?></h4>
                <span class="att-count-tag"><?= $presentCount; ?> جلسه حضور</span>
            </div>

            <div class="att-days-grid">
                <?php for ($d = 1; $d <= $daysInThisMonth; $d++): ?>
                    <?php $isPresent = in_array($d, $attendedDays); ?>
                    <div class="att-day-item <?= $isPresent ? 'present' : ''; ?>">
                        <span><?= $d; ?></span>
                        <?php if ($isPresent): ?>
                            <span class="check-mark">✔</span>
                        <?php endif; ?>
                    </div>
                <?php endfor; ?>
            </div>
        </div>
    </div>

</div>

<!-- اسکریپت آپلود ایجکس آواتار -->
<script>
document.getElementById('avatar-input').addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (!file) return;

    if (file.size > 2 * 1024 * 1024) {
        document.getElementById('upload-status').innerHTML = '<span style="color:red;">حجم فایل نباید بیش از ۲ مگابایت باشد.</span>';
        return;
    }

    const reader = new FileReader();
    reader.onload = function(event) {
        document.getElementById('avatar-preview').src = event.target.result;
    };
    reader.readAsDataURL(file);

    const formData = new FormData();
    formData.append('avatar', file);

    const statusDiv = document.getElementById('upload-status');
    statusDiv.innerHTML = '<span style="color:#666;">در حال آپلود...</span>';

    fetch('upload-avatar.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            statusDiv.innerHTML = '<span style="color:green;">' + data.message + '</span>';
        } else {
            statusDiv.innerHTML = '<span style="color:red;">' + data.message + '</span>';
        }
    })
    .catch(() => {
        statusDiv.innerHTML = '<span style="color:red;">خطا در ارسال فایل.</span>';
    });
});
</script>

</body>
</html>