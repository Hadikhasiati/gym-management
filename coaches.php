<?php
// coaches.php
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

// فقط مدیر باشگاه دسترسی دارد
if (($current_user['role'] ?? '') !== 'admin') {
    header("Location: dashboard.php");
    exit;
}

$msg = '';
$error = '';

function sanitize_digits(?string $input): string {
    if ($input === null) return '';
    $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $arabic  = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
    $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    return trim(str_replace($arabic, $english, str_replace($persian, $english, $input)));
}

// ۱. افزودن مربی جدید
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_coach') {
    $full_name = trim($_POST['full_name'] ?? '');
    $phone     = sanitize_digits($_POST['phone'] ?? '');
    $password  = trim($_POST['password'] ?? '');

    if (empty($full_name) || empty($phone) || empty($password)) {
        $error = 'تمامی فیلدهای مربی الزامی است.';
    } elseif (!preg_match('/^09[0-9]{9}$/', $phone)) {
        $error = 'شماره موبایل وارد شده نامعتبر است.';
    } else {
        $check = $pdo->prepare("SELECT id FROM users WHERE phone = ? AND club_id = ? LIMIT 1");
        $check->execute([$phone, CURRENT_CLUB_ID]);
        if ($check->fetch()) {
            $error = 'این شماره موبایل قبلاً در این باشگاه ثبت شده است.';
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $token = bin2hex(random_bytes(32));
            $stmt = $pdo->prepare("
                INSERT INTO users (club_id, full_name, phone, password, role, auth_token, created_at)
                VALUES (?, ?, ?, ?, 'coach', ?, NOW())
            ");
            $stmt->execute([CURRENT_CLUB_ID, $full_name, $phone, $hashed, $token]);
            $msg = "مربی «{$full_name}» با موفقیت افزوده شد.";
        }
    }
}

// ۲. ساخت سانس / کلاس تمرینی
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_class') {
    $title      = trim($_POST['title'] ?? '');
    $coach_id   = (int)($_POST['coach_id'] ?? 0);
    $days       = trim($_POST['days'] ?? '');
    $start_time = trim($_POST['start_time'] ?? '');
    $end_time   = trim($_POST['end_time'] ?? '');

    if (empty($title) || empty($days) || $coach_id <= 0) {
        $error = 'عنوان کلاس، انتخاب مربی و روزهای برگزاری الزامی است.';
    } else {
        $stmtCls = $pdo->prepare("
            INSERT INTO classes (club_id, coach_id, title, days, start_time, end_time, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmtCls->execute([CURRENT_CLUB_ID, $coach_id, $title, $days, $start_time, $end_time]);
        $msg = "سانس تمرینی «{$title}» ثبت گردید.";
    }
}

// ۳. انتساب هنرجو به مربی و سانس
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assign_student') {
    $student_id = (int)($_POST['student_id'] ?? 0);
    $class_id   = (int)($_POST['class_id'] ?? 0);

    if ($student_id > 0 && $class_id > 0) {
        // واکشی شناسه مربی از روی کلاس
        $stmtC = $pdo->prepare("SELECT coach_id FROM classes WHERE id = ? AND club_id = ? LIMIT 1");
        $stmtC->execute([$class_id, CURRENT_CLUB_ID]);
        $coach_id = (int)$stmtC->fetchColumn();

        $stmtUp = $pdo->prepare("UPDATE users SET class_id = ?, coach_id = ? WHERE id = ? AND club_id = ?");
        $stmtUp->execute([$class_id, $coach_id, $student_id, CURRENT_CLUB_ID]);
        $msg = "هنرجو با موفقیت به سانس و مربی مربوطه متصل شد.";
    }
}

// ۴. حذف مربی یا سانس
if (isset($_GET['del_coach'])) {
    $del_id = (int)$_GET['del_coach'];
    $pdo->prepare("DELETE FROM users WHERE id = ? AND club_id = ? AND role = 'coach'")->execute([$del_id, CURRENT_CLUB_ID]);
    header("Location: coaches.php?msg=" . urlencode('مربی با موفقیت حذف شد.'));
    exit;
}

if (isset($_GET['del_class'])) {
    $del_id = (int)$_GET['del_class'];
    $pdo->prepare("DELETE FROM classes WHERE id = ? AND club_id = ?")->execute([$del_id, CURRENT_CLUB_ID]);
    header("Location: coaches.php?msg=" . urlencode('سانس تمرینی حذف شد.'));
    exit;
}

// واکشی لیست مربیان باشگاه
$stmtCoaches = $pdo->prepare("
    SELECT u.*, 
           (SELECT COUNT(*) FROM users s WHERE s.coach_id = u.id AND s.role = 'student') as student_count,
           (SELECT COUNT(*) FROM classes c WHERE c.coach_id = u.id) as class_count
    FROM users u 
    WHERE u.club_id = ? AND u.role = 'coach' 
    ORDER BY u.id DESC
");
$stmtCoaches->execute([CURRENT_CLUB_ID]);
$coaches = $stmtCoaches->fetchAll(PDO::FETCH_ASSOC);

// واکشی لیست سانس‌ها
$stmtClasses = $pdo->prepare("
    SELECT c.*, u.full_name as coach_name,
           (SELECT COUNT(*) FROM users s WHERE s.class_id = c.id) as student_count
    FROM classes c
    LEFT JOIN users u ON c.coach_id = u.id
    WHERE c.club_id = ?
    ORDER BY c.id DESC
");
$stmtClasses->execute([CURRENT_CLUB_ID]);
$classes = $stmtClasses->fetchAll(PDO::FETCH_ASSOC);

// واکشی هنرجویان برای فرم انتساب
$stmtStudents = $pdo->prepare("SELECT id, full_name, phone, class_id FROM users WHERE club_id = ? AND role = 'student' ORDER BY full_name ASC");
$stmtStudents->execute([CURRENT_CLUB_ID]);
$all_students = $stmtStudents->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مدیریت مربیان و سانس‌ها | <?= htmlspecialchars(CURRENT_CLUB_NAME) ?></title>
    <style>
        :root { --primary: <?= htmlspecialchars(CURRENT_CLUB_THEME) ?>; }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: system-ui, -apple-system, sans-serif; }
        body { background-color: #0b1120; color: #f8fafc; min-height: 100vh; padding: 1.5rem 1rem; }
        .container { max-width: 1100px; margin: 0 auto; }
        .top-nav { display: flex; justify-content: space-between; align-items: center; background: #111827; border: 1px solid rgba(255,255,255,0.08); padding: 1rem 1.25rem; border-radius: 16px; margin-bottom: 1.5rem; }
        .btn-back { background: #1e293b; color: #38bdf8; border: 1px solid #334155; padding: 0.5rem 1.1rem; border-radius: 8px; text-decoration: none; font-size: 0.85rem; font-weight: 700; }
        
        .grid-2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1.25rem; margin-bottom: 1.5rem; }
        .card { background: #111827; border: 1px solid rgba(255,255,255,0.08); border-radius: 16px; padding: 1.5rem; box-shadow: 0 4px 20px rgba(0,0,0,0.3); }
        .card-title { font-size: 1.05rem; font-weight: 800; color: #38bdf8; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem; }
        
        .form-group { margin-bottom: 0.85rem; display: flex; flex-direction: column; gap: 0.35rem; }
        label { font-size: 0.82rem; color: #94a3b8; font-weight: 600; }
        input, select { background: #1e293b; border: 1px solid #334155; border-radius: 8px; padding: 0.65rem 0.85rem; color: #fff; font-size: 0.9rem; outline: none; }
        input:focus, select:focus { border-color: var(--primary); }
        .btn-submit { background: var(--primary); color: #fff; border: none; padding: 0.75rem 1.5rem; border-radius: 8px; font-weight: 700; cursor: pointer; width: 100%; margin-top: 0.5rem; }
        
        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; text-align: right; font-size: 0.88rem; min-width: 600px; }
        th, td { padding: 0.85rem 0.75rem; border-bottom: 1px solid rgba(255,255,255,0.05); }
        th { color: #94a3b8; }
        .badge { background: #1e293b; color: #a5b4fc; padding: 0.25rem 0.6rem; border-radius: 6px; font-size: 0.78rem; font-weight: 700; }
        .btn-del { color: #ef4444; text-decoration: none; font-size: 0.78rem; font-weight: 700; }

        .alert-success { background: rgba(16, 185, 129, 0.15); border: 1px solid #10b981; color: #34d399; padding: 0.85rem; border-radius: 10px; margin-bottom: 1.25rem; }
        .alert-error { background: rgba(239, 68, 68, 0.15); border: 1px solid #ef4444; color: #f87171; padding: 0.85rem; border-radius: 10px; margin-bottom: 1.25rem; }
    </style>
</head>
<body>
    <div class="container">
        <div class="top-nav">
            <h2 style="font-size: 1.15rem; color: #38bdf8;">🥋 مدیریت مربیان، سانس‌ها و شاگردان | <?= htmlspecialchars(CURRENT_CLUB_NAME) ?></h2>
            <a href="dashboard.php" class="btn-back">بازگشت به پیشخوان ↵</a>
        </div>

        <?php if ($msg || isset($_GET['msg'])): ?>
            <div class="alert-success">✓ <?= htmlspecialchars($msg ?: $_GET['msg']) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- فرم‌های افزودن مربی و سانس -->
        <div class="grid-2">
            <!-- فرم ثبت مربی جدید -->
            <div class="card">
                <div class="card-title">➕ تعریف مربی جدید</div>
                <form method="POST">
                    <input type="hidden" name="action" value="add_coach">
                    <div class="form-group">
                        <label>نام و نام خانوادگی مربی *</label>
                        <input type="text" name="full_name" placeholder="مثال: استاد حسینی" required>
                    </div>
                    <div class="form-group">
                        <label>شماره موبایل مربی (نام کاربری ورود) *</label>
                        <input type="text" name="phone" placeholder="09xxxxxxxxx" dir="ltr" maxlength="11" required>
                    </div>
                    <div class="form-group">
                        <label>رمز عبور اولیه *</label>
                        <input type="password" name="password" placeholder="••••••••" dir="ltr" required>
                    </div>
                    <button type="submit" class="btn-submit">ثبت مربی</button>
                </form>
            </div>

            <!-- فرم ایجاد سانس / کلاس -->
            <div class="card">
                <div class="card-title">⏰ ایجاد سانس / کلاس تمرینی جدید</div>
                <form method="POST">
                    <input type="hidden" name="action" value="add_class">
                    <div class="form-group">
                        <label>عنوان کلاس *</label>
                        <input type="text" name="title" placeholder="مثال: سانس تخصصی سرعت (عصر)" required>
                    </div>
                    <div class="form-group">
                        <label>مربی مسئول این سانس *</label>
                        <select name="coach_id" required>
                            <option value="">-- انتخاب مربی --</option>
                            <?php foreach ($coaches as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['full_name']) ?> (<?= htmlspecialchars($c['phone']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>روزهای برگزاری *</label>
                        <input type="text" name="days" placeholder="مثال: زوج (شنبه، دوشنبه، چهارشنبه)" required>
                    </div>
                    <div style="display:flex; gap:0.5rem;">
                        <div class="form-group" style="flex:1;">
                            <label>ساعت شروع</label>
                            <input type="text" name="start_time" placeholder="18:00" dir="ltr">
                        </div>
                        <div class="form-group" style="flex:1;">
                            <label>ساعت پایان</label>
                            <input type="text" name="end_time" placeholder="19:30" dir="ltr">
                        </div>
                    </div>
                    <button type="submit" class="btn-submit">ایجاد سانس کلاسی</button>
                </form>
            </div>
        </div>

        <!-- فرم انتساب سریع هنرجو به سانس -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-title">🎯 انتساب هنرجو به کلاس و مربی</div>
            <form method="POST" style="display:flex; gap:0.75rem; flex-wrap:wrap; align-items:flex-end;">
                <input type="hidden" name="action" value="assign_student">
                
                <div class="form-group" style="flex:1; min-width:200px; margin:0;">
                    <label>انتخاب هنرجو:</label>
                    <select name="student_id" required>
                        <option value="">-- انتخاب هنرجو --</option>
                        <?php foreach ($all_students as $st): ?>
                            <option value="<?= $st['id'] ?>">
                                <?= htmlspecialchars($st['full_name'] ?: 'بدون نام') ?> (<?= htmlspecialchars($st['phone']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="flex:1; min-width:200px; margin:0;">
                    <label>انتقال به سانس / کلاس:</label>
                    <select name="class_id" required>
                        <option value="">-- انتخاب سانس --</option>
                        <?php foreach ($classes as $cls): ?>
                            <option value="<?= $cls['id'] ?>">
                                <?= htmlspecialchars($cls['title']) ?> - مربی: <?= htmlspecialchars($cls['coach_name'] ?? 'نامشخص') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" class="btn-submit" style="width:auto; padding:0.65rem 1.5rem; margin:0;">ثبت انتساب</button>
            </form>
        </div>

        <!-- جدول لیست مربیان -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-title">👥 لیست مربیان فعال باشگاه</div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>نام مربی</th>
                            <th>شماره تماس</th>
                            <th>تعداد سانس‌ها</th>
                            <th>تعداد کل شاگردان</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($coaches)): ?>
                            <tr><td colspan="5" style="text-align:center; color:#64748b; padding:2rem;">هنوز هیچ مربی برای این باشگاه ثبت نشده است.</td></tr>
                        <?php else: ?>
                            <?php foreach ($coaches as $coach): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($coach['full_name']) ?></strong></td>
                                    <td><span style="font-family:monospace; color:#94a3b8;"><?= htmlspecialchars($coach['phone']) ?></span></td>
                                    <td><span class="badge"><?= $coach['class_count'] ?> سانس</span></td>
                                    <td><strong style="color:#38bdf8;"><?= $coach['student_count'] ?></strong> نفر</td>
                                    <td>
                                        <a href="coaches.php?del_coach=<?= $coach['id'] ?>" class="btn-del" onclick="return confirm('آیا از حذف این مربی اطمینان دارید؟')">حذف</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- جدول لیست سانس‌ها -->
        <div class="card">
            <div class="card-title">⏰ لیست سانس‌ها و کلاس‌های تمرینی</div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>عنوان سانس</th>
                            <th>مربی مسئول</th>
                            <th>روزهای برگزاری</th>
                            <th>ساعت برگزاری</th>
                            <th>شاگردان ثبت‌نامی</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($classes)): ?>
                            <tr><td colspan="6" style="text-align:center; color:#64748b; padding:2rem;">هنوز سانسی تعریف نشده است.</td></tr>
                        <?php else: ?>
                            <?php foreach ($classes as $cls): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($cls['title']) ?></strong></td>
                                    <td><span style="color:#a5b4fc; font-weight:700;"><?= htmlspecialchars($cls['coach_name'] ?? 'نامشخص') ?></span></td>
                                    <td><?= htmlspecialchars($cls['days']) ?></td>
                                    <td><span style="font-family:monospace; color:#94a3b8;"><?= htmlspecialchars($cls['start_time']) ?> تا <?= htmlspecialchars($cls['end_time']) ?></span></td>
                                    <td><strong style="color:#34d399;"><?= $cls['student_count'] ?></strong> هنرجو</td>
                                    <td>
                                        <a href="coaches.php?del_class=<?= $cls['id'] ?>" class="btn-del" onclick="return confirm('آیا این سانس حذف شود؟')">حذف</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>