<?php
// announcements.php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
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
$is_api = (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) || isset($_GET['is_api']) || isset($_POST['is_api']);

// بررسی سطح دسترسی مدیریت
if (($current_user['role'] ?? '') !== 'admin') {
    if ($is_api) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'عدم دسترسی مدیریت']);
        exit;
    }
    header('Location: dashboard.php');
    exit;
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

if (!function_exists('to_jalali_date')) {
    function to_jalali_date($g_date) {
        if (empty($g_date)) return '-';
        $parts = explode('-', substr($g_date, 0, 10));
        if (count($parts) !== 3) return '-';
        list($jy, $jm, $jd) = gregorian_to_jalali((int)$parts[0], (int)$parts[1], (int)$parts[2]);
        return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
    }
}

$persian_months = [
    1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد',
    4 => 'تیر', 5 => 'مرداد', 6 => 'شهریور',
    7 => 'مهر', 8 => 'آبان', 9 => 'آذر',
    10 => 'دی', 11 => 'بهمن', 12 => 'اسفند'
];

list($cur_jy, $cur_jm, $cur_jd) = gregorian_to_jalali((int)date('Y'), (int)date('m'), (int)date('d'));
$error = '';

// حذف اطلاعیه با کنترل شناسه باشگاه
if (isset($_GET['delete'])) {
    $del_id = (int)$_GET['delete'];
    $stmtDel = $pdo->prepare("DELETE FROM announcements WHERE id = ? AND club_id = ?");
    $stmtDel->execute([$del_id, CURRENT_CLUB_ID]);
    
    if ($is_api) {
        echo json_encode(['success' => true, 'message' => 'اطلاعیه حذف شد']);
        exit;
    }
    header('Location: announcements.php?msg=deleted');
    exit;
}

// ثبت اطلاعیه جدید
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['create_announcement']) || $is_api)) {
    $title = trim($_POST['title'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $target_type = $_POST['target_type'] ?? 'all';
    
    $exp_day = (int)($_POST['exp_day'] ?? $cur_jd);
    $exp_month = (int)($_POST['exp_month'] ?? $cur_jm);
    $exp_year = (int)($_POST['exp_year'] ?? $cur_jy);

    list($gy, $gm, $gd) = jalali_to_gregorian($exp_year, $exp_month, $exp_day);
    $expires_at = sprintf('%04d-%02d-%02d', $gy, $gm, $gd);

    $target_value = null;
    if ($target_type === 'level') {
        $target_value = $_POST['target_level'] ?? null;
    } elseif ($target_type === 'user') {
        $target_value = $_POST['target_user'] ?? null;
    }

    if (empty($title) || empty($message)) {
        $error = 'لطفاً تمام فیلدهای الزامی را پر کنید.';
        if ($is_api) {
            echo json_encode(['success' => false, 'message' => $error]);
            exit;
        }
    } else {
        $stmtInsert = $pdo->prepare("
            INSERT INTO announcements (club_id, title, message, target_type, target_value, expires_at) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmtInsert->execute([CURRENT_CLUB_ID, $title, $message, $target_type, $target_value, $expires_at]);
        
        if ($is_api) {
            echo json_encode(['success' => true, 'message' => 'اطلاعیه با موفقیت ثبت شد']);
            exit;
        }
        header('Location: announcements.php?msg=created');
        exit;
    }
}

// واکشی هنرجویان و اطلاعیه‌ها به تفکیک باشگاه
$stmtSt = $pdo->prepare("SELECT id, full_name, phone, skill_level FROM users WHERE role = 'student' AND club_id = ? ORDER BY full_name ASC");
$stmtSt->execute([CURRENT_CLUB_ID]);
$students = $stmtSt->fetchAll(PDO::FETCH_ASSOC);

$stmtAnn = $pdo->prepare("SELECT * FROM announcements WHERE club_id = ? ORDER BY id DESC");
$stmtAnn->execute([CURRENT_CLUB_ID]);
$announcements = $stmtAnn->fetchAll(PDO::FETCH_ASSOC);

if ($is_api) {
    echo json_encode([
        'success'       => true,
        'announcements' => $announcements,
        'students'      => $students
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مدیریت اطلاعیه‌ها | <?= htmlspecialchars(CURRENT_CLUB_NAME) ?></title>

    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#0b1120">

    <style>
        :root { --primary-color: <?= htmlspecialchars(CURRENT_CLUB_THEME) ?>; }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: system-ui, -apple-system, sans-serif; }
        body { background-color: #0b1120; color: #f8fafc; min-height: 100vh; padding: 1.5rem 1rem; }
        .container { max-width: 1000px; margin: 0 auto; }
        .top-navbar { display: flex; justify-content: space-between; align-items: center; background: #111827; border: 1px solid #1f2937; padding: 1rem 1.25rem; border-radius: 14px; margin-bottom: 1.5rem; }
        .btn-back { background: #1e293b; color: #94a3b8; border: 1px solid #334155; padding: 0.5rem 1rem; border-radius: 8px; text-decoration: none; font-size: 0.85rem; font-weight: 700; }
        .btn-back:hover { background: #334155; color: #fff; }
        .card { background: #111827; border: 1px solid #1f2937; border-radius: 14px; padding: 1.5rem; margin-bottom: 1.5rem; }
        .card h3 { font-size: 1.1rem; color: #38bdf8; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 1rem; }
        .form-group { display: flex; flex-direction: column; gap: 0.4rem; }
        .form-group label { font-size: 0.85rem; color: #94a3b8; font-weight: 600; }
        .form-control { background: #1e293b; border: 1px solid #334155; border-radius: 8px; padding: 0.65rem 0.85rem; color: #fff; font-size: 0.9rem; outline: none; }
        .form-control:focus { border-color: #38bdf8; }
        textarea.form-control { resize: vertical; min-height: 90px; }
        .date-select-group { display: flex; gap: 0.5rem; }
        .date-select-group select { flex: 1; }
        .btn-submit { background: var(--primary-color); color: #fff; border: none; padding: 0.75rem 1.5rem; border-radius: 8px; font-weight: 700; cursor: pointer; transition: 0.2s; }
        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; text-align: right; font-size: 0.88rem; }
        th, td { padding: 0.8rem; border-bottom: 1px solid #1f2937; }
        th { color: #94a3b8; }
        .badge { display: inline-block; padding: 0.25rem 0.6rem; border-radius: 6px; font-size: 0.75rem; font-weight: 700; }
        .badge-all { background: #0284c725; color: #38bdf8; border: 1px solid #0284c7; }
        .badge-level { background: #8b5cf625; color: #a78bfa; border: 1px solid #8b5cf6; }
        .badge-user { background: #10b98125; color: #34d399; border: 1px solid #10b981; }
        .btn-del { background: #ef444420; color: #ef4444; border: 1px solid #ef4444; padding: 0.3rem 0.6rem; border-radius: 6px; text-decoration: none; font-size: 0.75rem; }
        .alert-success { background: #10b98120; border: 1px solid #10b981; color: #34d399; padding: 0.75rem; border-radius: 8px; margin-bottom: 1rem; }
        .alert-error { background: #ef444420; border: 1px solid #ef4444; color: #f87171; padding: 0.75rem; border-radius: 8px; margin-bottom: 1rem; }
    </style>
</head>
<body>
    <div class="container">
        <div class="top-navbar">
            <h2 style="font-size: 1.15rem; color: #38bdf8;">📢 مدیریت اطلاعیه‌ها | <?= htmlspecialchars(CURRENT_CLUB_NAME) ?></h2>
            <a href="dashboard.php" class="btn-back">بازگشت به پیشخوان ↵</a>
        </div>

        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'created'): ?>
            <div class="alert-success">✓ اطلاعیه جدید با موفقیت ثبت شد.</div>
        <?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'deleted'): ?>
            <div class="alert-success">✓ اطلاعیه مورد نظر حذف شد.</div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="card">
            <h3>✍️ ایجاد اطلاعیه جدید</h3>
            <form method="POST" action="announcements.php">
                <div class="form-grid">
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>عنوان اطلاعیه *</label>
                        <input type="text" name="title" class="form-control" placeholder="مثال: تغییر ساعت کلاس" required>
                    </div>

                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>متن پیام *</label>
                        <textarea name="message" class="form-control" placeholder="متن پیام خود را وارد کنید..." required></textarea>
                    </div>

                    <div class="form-group">
                        <label>مخاطب *</label>
                        <select name="target_type" id="targetTypeSelect" class="form-control" onchange="handleTargetChange()">
                            <option value="all">همه کاربران (عمومی)</option>
                            <option value="level">یک سطح آموزشی خاص</option>
                            <option value="user">یک هنرجوی خاص</option>
                        </select>
                    </div>

                    <div class="form-group" id="levelSelectBox" style="display: none;">
                        <label>انتخاب سطح</label>
                        <select name="target_level" class="form-control">
                            <option value="مبتدی">مبتدی</option>
                            <option value="پیشرفته">پیشرفته</option>
                            <option value="فری استایل">فری استایل</option>
                            <option value="سرعت">سرعت</option>
                        </select>
                    </div>

                    <div class="form-group" id="userSelectBox" style="display: none;">
                        <label>انتخاب هنرجو</label>
                        <select name="target_user" class="form-control">
                            <?php foreach ($students as $st): ?>
                                <option value="<?= $st['id'] ?>">
                                    <?= htmlspecialchars($st['full_name'] ?: $st['phone']) ?> (<?= htmlspecialchars($st['skill_level'] ?? 'نامشخص') ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>تاریخ انقضا (شمسی) *</label>
                        <div class="date-select-group">
                            <select name="exp_day" class="form-control">
                                <?php for ($d = 1; $d <= 31; $d++): ?>
                                    <option value="<?= $d ?>" <?= ($d === $cur_jd) ? 'selected' : '' ?>><?= $d ?></option>
                                <?php endfor; ?>
                            </select>

                            <select name="exp_month" class="form-control">
                                <?php foreach ($persian_months as $m_num => $m_name): ?>
                                    <option value="<?= $m_num ?>" <?= ($m_num === $cur_jm) ? 'selected' : '' ?>><?= $m_name ?></option>
                                <?php endforeach; ?>
                            </select>

                            <select name="exp_year" class="form-control">
                                <?php for ($y = $cur_jy; $y <= $cur_jy + 2; $y++): ?>
                                    <option value="<?= $y ?>" <?= ($y === $cur_jy) ? 'selected' : '' ?>><?= $y ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <button type="submit" name="create_announcement" class="btn-submit">🚀 انتشار اطلاعیه</button>
            </form>
        </div>

        <div class="card">
            <h3>📋 لیست اطلاعیه‌های فعال</h3>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>عنوان</th>
                            <th>مخاطب</th>
                            <th>تاریخ انقضا (شمسی)</th>
                            <th>وضعیت</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($announcements)): ?>
                            <tr><td colspan="5" style="text-align:center; color:#64748b; padding:1.5rem;">اطلاعیه‌ای ثبت نشده است.</td></tr>
                        <?php else: ?>
                            <?php foreach ($announcements as $ann): 
                                $is_expired = ($ann['expires_at'] < date('Y-m-d'));
                            ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($ann['title']) ?></strong>
                                        <div style="font-size:0.78rem; color:#94a3b8; margin-top:3px; max-width:300px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                            <?= htmlspecialchars($ann['message']) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($ann['target_type'] === 'all'): ?>
                                            <span class="badge badge-all">همه کاربران</span>
                                        <?php elseif ($ann['target_type'] === 'level'): ?>
                                            <span class="badge badge-level">سطح: <?= htmlspecialchars($ann['target_value'] ?? '') ?></span>
                                        <?php else: ?>
                                            <span class="badge badge-user">کاربر ID: <?= htmlspecialchars($ann['target_value'] ?? '') ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-size:0.88rem; font-weight:700; color:#38bdf8;">
                                        <?= htmlspecialchars(to_jalali_date($ann['expires_at'])) ?>
                                    </td>
                                    <td>
                                        <?= $is_expired ? '<span style="color:#f87171; font-weight:700;">منقضی</span>' : '<span style="color:#4ade80; font-weight:700;">فعال</span>' ?>
                                    </td>
                                    <td>
                                        <a href="announcements.php?delete=<?= $ann['id'] ?>" class="btn-del" onclick="return confirm('اطلاعیه حذف شود؟')">حذف</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        function handleTargetChange() {
            const type = document.getElementById('targetTypeSelect').value;
            document.getElementById('levelSelectBox').style.display = (type === 'level') ? 'block' : 'none';
            document.getElementById('userSelectBox').style.display = (type === 'user') ? 'block' : 'none';
        }
    </script>
</body>
</html>