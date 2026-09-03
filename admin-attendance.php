<?php
// admin-attendance.php
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/db.php';
if (file_exists(__DIR__ . '/tenant.php')) {
    require_once __DIR__ . '/tenant.php';
}
require_once __DIR__ . '/auth.php';

if (!defined('CURRENT_CLUB_ID')) define('CURRENT_CLUB_ID', 1);
if (!defined('CURRENT_CLUB_NAME')) define('CURRENT_CLUB_NAME', 'باشگاه رادین اسکیت');
if (!defined('CURRENT_CLUB_THEME')) define('CURRENT_CLUB_THEME', '#0284c7');

$current_user = check_auth();

// بررسی دسترسی مدیریت بر اساس نقش کاربر در دیتابیس
if (($current_user['role'] ?? '') !== 'admin') {
    echo "<div style='background: #111827; color: #f8fafc; padding: 2rem; font-family: Tahoma; direction: rtl; text-align: center;'>";
    echo "<h3 style='color: #ef4444; margin-bottom: 1rem;'>خطای عدم دسترسی</h3>";
    echo "<p>حساب کاربری شما سطح دسترسی مدیریت این باشگاه را ندارد.</p>";
    echo "<a href='dashboard.php' style='display: inline-block; margin-top: 1.5rem; background: #0284c7; color: #fff; padding: 0.5rem 1rem; border-radius: 8px; text-decoration: none;'>بازگشت به پیشخوان</a>";
    echo "</div>";
    exit;
}

// توابع تبدیل تقویم (میلادی <-> جلالی)
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

if (!function_exists('jalali_to_gregorian')) {
    function jalali_to_gregorian($jy, $jm, $jd) {
        $jy += 1595;
        $days = -355668 + (365 * $jy) + ((int)($jy / 33) * 8) + (int)((($jy % 33) + 3) / 4) + $jd + (($jm < 7) ? (($jm - 1) * 31) : ((($jm - 7) * 30) + 186));
        $gy = 400 * (int)($days / 146097);
        $days %= 146097;
        if ($days > 36524) {
            $gy += 100 * (int)(--$days / 36524);
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

list($cur_jy, $cur_jm, $cur_jd) = gregorian_to_jalali((int)date('Y'), (int)date('m'), (int)date('d'));
$default_jalali_date = sprintf('%04d-%02d-%02d', $cur_jy, $cur_jm, $cur_jd);
$selected_jalali_date = $_GET['date'] ?? $default_jalali_date;

$date_parts = explode('-', $selected_jalali_date);
if (count($date_parts) === 3) {
    list($gy, $gm, $gd) = jalali_to_gregorian((int)$date_parts[0], (int)$date_parts[1], (int)$date_parts[2]);
    $selected_gregorian_date = sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
} else {
    $selected_gregorian_date = date('Y-m-d');
}

// ثبت وضعیت حضور از طریق درخواست AJAX یا API اپلیکیشن
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_id']) && isset($_POST['status'])) {
    header('Content-Type: application/json; charset=utf-8');
    $target_user_id = intval($_POST['user_id']);
    $status = $_POST['status'];

    if ($status === 'present') {
        $stmtCheck = $pdo->prepare("SELECT id FROM attendance WHERE club_id = ? AND user_id = ? AND session_date = ?");
        $stmtCheck->execute([CURRENT_CLUB_ID, $target_user_id, $selected_gregorian_date]);
        if ($stmtCheck->fetch()) {
            $stmtUp = $pdo->prepare("UPDATE attendance SET status = 'present' WHERE club_id = ? AND user_id = ? AND session_date = ?");
            $stmtUp->execute([CURRENT_CLUB_ID, $target_user_id, $selected_gregorian_date]);
        } else {
            $stmtIns = $pdo->prepare("INSERT INTO attendance (club_id, user_id, session_date, status) VALUES (?, ?, ?, 'present')");
            $stmtIns->execute([CURRENT_CLUB_ID, $target_user_id, $selected_gregorian_date]);
        }
    } else {
        $stmtDel = $pdo->prepare("DELETE FROM attendance WHERE club_id = ? AND user_id = ? AND session_date = ?");
        $stmtDel->execute([CURRENT_CLUB_ID, $target_user_id, $selected_gregorian_date]);
    }

    echo json_encode(['success' => true]);
    exit;
}

// لیست هنرجویان فقط متعلق به همین باشگاه
$stmtUsers = $pdo->prepare("
    SELECT u.*, 
           (SELECT status FROM attendance a WHERE a.club_id = ? AND a.user_id = u.id AND a.session_date = ?) as attendance_status
    FROM users u
    WHERE u.club_id = ? AND u.role = 'student'
    ORDER BY u.id DESC
");
$stmtUsers->execute([CURRENT_CLUB_ID, $selected_gregorian_date, CURRENT_CLUB_ID]);
$users_list = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);

// در صورت درخواست JSON از سمت اپلیکیشن اندروید
if (isset($_GET['is_api']) || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'date'    => $selected_jalali_date,
        'users'   => $users_list
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>حضور و غیاب روزانه | <?= htmlspecialchars(CURRENT_CLUB_NAME) ?></title>
    <style>
        :root { --primary-color: <?= htmlspecialchars(CURRENT_CLUB_THEME) ?>; }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: system-ui, -apple-system, sans-serif; }
        body { background-color: #0b1120; color: #f8fafc; min-height: 100vh; padding: 1.5rem 1rem; }
        .container { max-width: 900px; margin: 0 auto; }
        .top-navbar {
            display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;
            background: #111827; border: 1px solid #1f2937; padding: 1rem 1.25rem; border-radius: 14px;
        }
        .top-navbar h2 { font-size: 1.15rem; font-weight: 800; color: #38bdf8; }
        .btn-back {
            background: #1e293b; color: #94a3b8; border: 1px solid #334155;
            padding: 0.5rem 1rem; border-radius: 8px; text-decoration: none; font-size: 0.85rem; font-weight: 700;
        }
        .btn-back:hover { background: #334155; color: #fff; }
        .filter-card {
            background: #111827; border: 1px solid #1f2937; padding: 1.25rem;
            border-radius: 14px; margin-bottom: 1.5rem; display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;
        }
        .filter-card label { font-size: 0.9rem; font-weight: 700; color: #94a3b8; }
        .filter-card input[type="text"] {
            background: #1e293b; border: 1px solid #334155; color: #fff; padding: 0.6rem 1rem; border-radius: 8px; font-size: 0.95rem;
        }
        .btn-filter {
            background: var(--primary-color); color: #fff; border: none; padding: 0.6rem 1.2rem; border-radius: 8px; font-weight: 700; cursor: pointer;
        }
        .users-table-card {
            background: #111827; border: 1px solid #1f2937; border-radius: 16px; overflow: hidden; box-shadow: 0 8px 20px rgba(0, 0, 0, 0.25);
        }
        table { width: 100%; border-collapse: collapse; text-align: right; }
        th, td { padding: 1rem 1.25rem; border-bottom: 1px solid #1f2937; font-size: 0.92rem; }
        th { background: #1f2937; color: #38bdf8; font-weight: 700; }
        tr:last-child td { border-bottom: none; }
        .user-name { font-weight: 700; color: #f1f5f9; }
        .user-phone { font-size: 0.8rem; color: #64748b; margin-top: 0.2rem; }
        .btn-status {
            padding: 0.45rem 1rem; border-radius: 8px; font-size: 0.82rem; font-weight: 700; cursor: pointer; border: 1px solid transparent; transition: 0.2s;
        }
        .btn-absent { background: #1e293b; color: #94a3b8; border-color: #334155; }
        .btn-absent:hover { background: #334155; color: #fff; }
        .btn-present { background: #059669; color: #fff; border-color: #10b981; box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3); }
        .btn-present:hover { background: #047857; }
        .action-links a { color: #38bdf8; text-decoration: none; font-size: 0.82rem; margin-right: 10px; }
        .action-links a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="container">
        <div class="top-navbar">
            <h2>📋 مدیریت حضور و غیاب روزانه | <?= htmlspecialchars(CURRENT_CLUB_NAME) ?></h2>
            <a href="dashboard.php" class="btn-back">بازگشت به پیشخوان ↵</a>
        </div>

        <form method="GET" class="filter-card">
            <div>
                <label>انتخاب تاریخ (شمسی):</label>
                <input type="text" name="date" value="<?= htmlspecialchars($selected_jalali_date) ?>" placeholder="1405-06-11">
            </div>
            <button type="submit" class="btn-filter">نمایش لیست این روز</button>
        </form>

        <div class="users-table-card">
            <table>
                <thead>
                    <tr>
                        <th>نام هنرجو</th>
                        <th>سطح / رده</th>
                        <th>وضعیت در تاریخ (<?= htmlspecialchars($selected_jalali_date) ?>)</th>
                        <th>سوابق کل</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users_list)): ?>
                        <tr>
                            <td colspan="4" style="text-align: center; color: #64748b; padding: 2rem;">هیچ هنرجویی در این باشگاه ثبت نشده است.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($users_list as $u): 
                            $uname = trim(($u['full_name'] ?? '') ?: (($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')));
                            if (empty($uname)) $uname = $u['phone'] ?? 'بدون نام';
                            $isPresent = ($u['attendance_status'] === 'present');
                        ?>
                            <tr>
                                <td>
                                    <div class="user-name"><?= htmlspecialchars($uname) ?></div>
                                    <div class="user-phone"><?= htmlspecialchars($u['phone'] ?? '') ?></div>
                                </td>
                                <td style="color: #94a3b8;"><?= htmlspecialchars($u['skill_level'] ?? 'عمومی') ?></td>
                                <td>
                                    <button type="button" 
                                            class="btn-status <?= $isPresent ? 'btn-present' : 'btn-absent' ?>" 
                                            data-userid="<?= $u['id'] ?>" 
                                            onclick="toggleAttendance(this)">
                                        <?= $isPresent ? '✓ حاضر' : '✕ غایب / ثبت نشده' ?>
                                    </button>
                                </td>
                                <td class="action-links">
                                    <a href="attendance.php?id=<?= $u['id'] ?>">مشاهده کل تقویم ↗</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        const selectedDate = "<?= $selected_jalali_date ?>";

        function toggleAttendance(button) {
            const userId = button.getAttribute('data-userid');
            const isCurrentlyPresent = button.classList.contains('btn-present');
            const newStatus = isCurrentlyPresent ? 'absent' : 'present';

            const formData = new URLSearchParams();
            formData.append('user_id', userId);
            formData.append('status', newStatus);
            formData.append('date', selectedDate);

            fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData.toString()
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (newStatus === 'present') {
                        button.classList.remove('btn-absent');
                        button.classList.add('btn-present');
                        button.innerHTML = '✓ حاضر';
                    } else {
                        button.classList.remove('btn-present');
                        button.classList.add('btn-absent');
                        button.innerHTML = '✕ غایب / ثبت نشده';
                    }
                } else {
                    alert('خطا در ثبت وضعیت حضور و غیاب');
                }
            })
            .catch(error => console.error('Error:', error));
        }
    </script>
</body>
</html>