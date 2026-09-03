<?php
// pay.php
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/db.php';
if (file_exists(__DIR__ . '/tenant.php')) {
    require_once __DIR__ . '/tenant.php';
}
require_once __DIR__ . '/auth.php';

if (!defined('CURRENT_CLUB_ID')) define('CURRENT_CLUB_ID', 1);
if (!defined('CURRENT_CLUB_NAME')) define('CURRENT_CLUB_NAME', 'باشگاه رادین اسکیت');

$current_user = check_auth();
$is_api = (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) || isset($_GET['is_api']) || isset($_POST['is_api']);

// واکشی تنظیمات مالی اختصاصی باشگاه
$stmtClub = $pdo->prepare("SELECT name, zarinpal_merchant, monthly_tuition FROM clubs WHERE id = ? LIMIT 1");
$stmtClub->execute([CURRENT_CLUB_ID]);
$club = $stmtClub->fetch(PDO::FETCH_ASSOC);

$merchant_id = trim($club['zarinpal_merchant'] ?? '');
$amountToman = (int)($club['monthly_tuition'] ?? 500000);
$amount      = $amountToman * 10; // تبدیل تومان به ریال
$club_name   = $club['name'] ?? CURRENT_CLUB_NAME;

// بررسی اولیه مرچنت کد
if (empty($merchant_id) || strlen($merchant_id) < 30) {
    $err_text = 'مرچنت کد زرین‌پال در بخش تنظیمات باشگاه ثبت نشده یا نامعتبر است.';
    if ($is_api) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $err_text], JSON_UNESCAPED_UNICODE);
        exit;
    }
    die("
    <!DOCTYPE html>
    <html lang='fa' dir='rtl'>
    <head>
        <meta charset='UTF-8'>
        <title>خطای درگاه پرداخت</title>
        <body style='background:#0b1120; color:#f87171; font-family:tahoma; text-align:center; padding:3rem;'>
            <h2>خطای تنظیمات درگاه پرداخت</h2>
            <p style='color:#cbd5e1; margin-top:10px;'>{$err_text}</p>
            <a href='dashboard.php' style='color:#38bdf8; text-decoration:none;'>بازگشت به پیشخوان</a>
        </body>
    </html>");
}

$user_display = $current_user['full_name'] ?: $current_user['phone'];
$description  = 'شهریه دوره ' . $club_name . ' - ' . $user_display;

// ساخت آدرس بازگشت داینامیک بر اساس دامنه یا ساب‌دامین جاری
$host = $_SERVER['HTTP_HOST'] ?? 'ap.radinskateomd.ir';
$callback_url = "https://{$host}/verify.php";

// ثبت رکورد با شناسه باشگاه جاری
$stmt = $pdo->prepare("INSERT INTO payments (club_id, user_id, amount, status, description) VALUES (?, ?, ?, 'pending', ?)");
$stmt->execute([CURRENT_CLUB_ID, $current_user['id'], $amount, $description]);
$payment_id = $pdo->lastInsertId();

// آماده‌سازی درخواست به زرین‌پال
$data = [
    'merchant_id'  => $merchant_id,
    'amount'       => (int)$amount,
    'description'  => $description,
    'callback_url' => $callback_url,
    'metadata'     => [
        'mobile'  => $current_user['phone'],
        'club_id' => CURRENT_CLUB_ID
    ]
];

$jsonData = json_encode($data);

$ch = curl_init('https://payment.zarinpal.com/pg/v4/payment/request.json');
curl_setopt($ch, CURLOPT_USERAGENT, 'ZarinPal Rest Api v4');
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/json',
    'Content-Type: application/json',
    'Content-Length: ' . strlen($jsonData)
]);

$result = curl_exec($ch);
$curl_err = curl_error($ch);
curl_close($ch);

if ($curl_err) {
    if ($is_api) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'خطای شبکه در اتصال به درگاه'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    die("خطای ارتباط با درگاه پرداخت: " . htmlspecialchars($curl_err));
}

$response = json_decode($result, true);

if (isset($response['data']['authority']) && $response['data']['code'] == 100) {
    $authority = $response['data']['authority'];
    $payment_url = 'https://payment.zarinpal.com/pg/StartPay/' . $authority;
    
    // ذخیره اتوریتی در دیتابیس
    $update = $pdo->prepare("UPDATE payments SET tracking_code = ? WHERE id = ? AND club_id = ?");
    $update->execute([$authority, $payment_id, CURRENT_CLUB_ID]);

    if ($is_api) {
        echo json_encode([
            'success'     => true,
            'payment_url' => $payment_url,
            'authority'   => $authority,
            'amount'      => $amountToman
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    header('Location: ' . $payment_url);
    exit;
} else {
    $err_msg = $response['errors']['message'] ?? 'خطای ناشناخته در اتصال به زرین‌پال';
    if ($is_api) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $err_msg], JSON_UNESCAPED_UNICODE);
        exit;
    }
    die("
    <!DOCTYPE html>
    <html lang='fa' dir='rtl'>
    <head>
        <meta charset='UTF-8'>
        <title>خطای پرداخت</title>
        <body style='background:#0b1120; color:#f87171; font-family:tahoma; text-align:center; padding:3rem;'>
            <h2>خطا در ایجاد تراکنش بانکی</h2>
            <p style='color:#cbd5e1; margin-top:10px;'>{$err_msg}</p>
            <a href='dashboard.php' style='color:#38bdf8; text-decoration:none;'>بازگشت به پیشخوان</a>
        </body>
    </html>");
}