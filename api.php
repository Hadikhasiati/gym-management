<?php
// api.php - REST API Hub for Android Application
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Club-ID');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

ini_set('display_errors', '0');
error_reporting(0);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/tenant.php';

$action = $_GET['action'] ?? '';
$raw_input = file_get_contents('php://input');
$json_data = json_decode($raw_input, true) ?? [];
$request_data = array_merge($_GET, $_POST, $json_data);

function json_out(bool $success, string $message = '', array $data = [], int $status_code = 200) {
    http_response_code($status_code);
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

// تابع احراز هویت توکن کاربر
function get_auth_user($pdo) {
    $headers = getallheaders();
    $auth_header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    
    if (!preg_match('/Bearer\s(\S+)/', $auth_header, $matches)) {
        json_out(false, 'توکن احراز هویت ارسال نشده است.', [], 401);
    }
    
    $token = $matches[1];
    $stmt = $pdo->prepare("SELECT * FROM users WHERE auth_token = ? AND club_id = ? LIMIT 1");
    $stmt->execute([$token, CURRENT_CLUB_ID]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        json_out(false, 'توکن نامعتبر است یا منقضی شده است.', [], 401);
    }
    return $user;
}

switch ($action) {
    // ۱. دریافت اطلاعات عمومی و برندینگ باشگاه
    case 'club_info':
        json_out(true, '', [
            'club' => [
                'id'          => CURRENT_CLUB_ID,
                'name'        => CURRENT_CLUB_NAME,
                'theme_color' => CURRENT_CLUB_THEME,
                'logo_url'    => CURRENT_CLUB_LOGO
            ]
        ]);
        break;

    // ۲. ورود کاربر
    case 'login':
        $phone = trim($request_data['phone'] ?? '');
        $password = trim($request_data['password'] ?? '');

        if (empty($phone) || empty($password)) {
            json_out(false, 'شماره موبایل و رمز عبور الزامی است.', [], 400);
        }

        $stmt = $pdo->prepare("SELECT * FROM users WHERE phone = ? AND club_id = ? LIMIT 1");
        $stmt->execute([$phone, CURRENT_CLUB_ID]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {
            $token = bin2hex(random_bytes(32));
            $pdo->prepare("UPDATE users SET auth_token = ? WHERE id = ?")->execute([$token, $user['id']]);

            json_out(true, 'ورود با موفقیت انجام شد.', [
                'token' => $token,
                'user'  => [
                    'id'          => (int)$user['id'],
                    'full_name'   => $user['full_name'],
                    'phone'       => $user['phone'],
                    'role'        => $user['role'],
                    'skill_level' => $user['skill_level']
                ]
            ]);
        } else {
            json_out(false, 'شماره موبایل یا رمز عبور اشتباه است.', [], 401);
        }
        break;

    // ۳. ثبت‌نام هنرجو
    case 'register':
        $full_name   = trim($request_data['full_name'] ?? '');
        $phone       = trim($request_data['phone'] ?? '');
        $password    = trim($request_data['password'] ?? '');
        $skill_level = trim($request_data['skill_level'] ?? 'مبتدی');
        $birth_date  = trim($request_data['birth_date'] ?? '');

        if (empty($full_name) || empty($phone) || empty($password)) {
            json_out(false, 'تکمیل فیلدهای نام، شماره موبایل و رمز عبور الزامی است.', [], 400);
        }

        $stmtCheck = $pdo->prepare("SELECT id FROM users WHERE phone = ? AND club_id = ? LIMIT 1");
        $stmtCheck->execute([$phone, CURRENT_CLUB_ID]);
        if ($stmtCheck->fetch()) {
            json_out(false, 'این شماره موبایل قبلاً در این باشگاه ثبت شده است.', [], 409);
        }

        $token = bin2hex(random_bytes(32));
        $stmtIns = $pdo->prepare("
            INSERT INTO users (club_id, full_name, phone, password, role, skill_level, birth_date, auth_token, created_at)
            VALUES (?, ?, ?, ?, 'student', ?, ?, ?, NOW())
        ");
        $stmtIns->execute([
            CURRENT_CLUB_ID,
            $full_name,
            $phone,
            password_hash($password, PASSWORD_DEFAULT),
            $skill_level,
            $birth_date ?: null,
            $token
        ]);

        json_out(true, 'ثبت‌نام با موفقیت انجام شد.', [
            'token' => $token,
            'user'  => [
                'id'          => (int)$pdo->lastInsertId(),
                'full_name'   => $full_name,
                'phone'       => $phone,
                'role'        => 'student',
                'skill_level' => $skill_level
            ]
        ], 201);
        break;

    // ۴. دریافت اطلاعات پیشخوان و آمار کاربر
    case 'dashboard':
        $user = get_auth_user($pdo);
        $today = date('Y-m-d');

        $is_paid = (!empty($user['subscription_expires_at']) && $user['subscription_expires_at'] >= $today);
        $days_left = 0;
        if ($is_paid) {
            $d1 = new DateTime($today);
            $d2 = new DateTime($user['subscription_expires_at']);
            $days_left = $d1->diff($d2)->days;
        }

        // دریافت اطلاعیه‌ها
        $stmtAnn = $pdo->prepare("
            SELECT id, title, message, expires_at 
            FROM announcements 
            WHERE club_id = ? AND expires_at >= ? 
              AND (target_type = 'all' OR (target_type = 'level' AND target_value = ?) OR (target_type = 'user' AND target_value = ?))
            ORDER BY id DESC LIMIT 5
        ");
        $stmtAnn->execute([CURRENT_CLUB_ID, $today, $user['skill_level'], (string)$user['id']]);
        $announcements = $stmtAnn->fetchAll(PDO::FETCH_ASSOC);

        json_out(true, '', [
            'user' => [
                'id'          => (int)$user['id'],
                'full_name'   => $user['full_name'],
                'phone'       => $user['phone'],
                'role'        => $user['role'],
                'skill_level' => $user['skill_level'],
                'is_paid'     => $is_paid,
                'days_left'   => $days_left,
                'expires_at'  => $user['subscription_expires_at']
            ],
            'announcements' => $announcements
        ]);
        break;

    // ۵. دریافت تاریخچه حضور و غیاب
    case 'attendance':
        $user = get_auth_user($pdo);
        $stmt = $pdo->prepare("SELECT session_date, status FROM attendance WHERE user_id = ? AND club_id = ? ORDER BY session_date DESC");
        $stmt->execute([$user['id'], CURRENT_CLUB_ID]);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        json_out(true, '', ['attendance' => $records]);
        break;

    // ۶. لیست محصولات فروشگاه
    case 'shop':
        $user = get_auth_user($pdo);
        
        $cats = $pdo->prepare("SELECT * FROM product_categories WHERE club_id = ? ORDER BY id DESC");
        $cats->execute([CURRENT_CLUB_ID]);

        $prods = $pdo->prepare("SELECT * FROM products WHERE club_id = ? ORDER BY id DESC");
        $prods->execute([CURRENT_CLUB_ID]);

        json_out(true, '', [
            'categories' => $cats->fetchAll(PDO::FETCH_ASSOC),
            'products'   => $prods->fetchAll(PDO::FETCH_ASSOC)
        ]);
        break;

    // ۷. ثبت سفارش خرید
    case 'order':
        $user = get_auth_user($pdo);
        $product_id = (int)($request_data['product_id'] ?? 0);
        $quantity   = max(1, (int)($request_data['quantity'] ?? 1));
        $size       = trim($request_data['size'] ?? '');
        $color      = trim($request_data['color'] ?? '');

        $stmtP = $pdo->prepare("SELECT price FROM products WHERE id = ? AND club_id = ? LIMIT 1");
        $stmtP->execute([$product_id, CURRENT_CLUB_ID]);
        $prod = $stmtP->fetch(PDO::FETCH_ASSOC);

        if (!$prod) {
            json_out(false, 'محصول یافت نشد.', [], 404);
        }

        $total_amount = (int)$prod['price'] * $quantity;
        $stmtOrd = $pdo->prepare("
            INSERT INTO orders (club_id, user_id, product_id, quantity, selected_size, selected_color, total_amount, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'paid', NOW())
        ");
        $stmtOrd->execute([CURRENT_CLUB_ID, $user['id'], $product_id, $quantity, $size, $color, $total_amount]);

        json_out(true, 'سفارش شما با موفقیت ثبت شد.', ['order_id' => $pdo->lastInsertId()]);
        break;

    default:
        json_out(false, 'اندپوینت یا اکشن نامعتبر است.', [], 404);
        break;
}