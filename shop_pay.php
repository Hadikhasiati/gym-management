<?php
// shop_pay.php
require_once 'auth.php';
require_once 'db.php';

if (function_exists('check_auth')) {
    check_auth();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['product_id'])) {
    header('Location: shop.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$product_id = (int)$_POST['product_id'];
$size = trim($_POST['size'] ?? '');
$color = trim($_POST['color'] ?? '');
$quantity = max(1, (int)$_POST['quantity']);

// بررسی محصول و محاسبه مبلغ
$stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? AND status = 'active'");
$stmt->execute([$product_id]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    die('محصول مورد نظر یافت نشد.');
}

$unit_price = $product['price'];
$total_amount = $unit_price * $quantity; // تومان

// ثبت سفارش به صورت pending
$stmtOrder = $pdo->prepare("
    INSERT INTO orders (user_id, product_id, selected_size, selected_color, quantity, unit_price, total_amount, status) 
    VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
");
$stmtOrder->execute([$user_id, $product_id, $size, $color, $quantity, $unit_price, $total_amount]);
$order_id = $pdo->lastInsertId();

// دریافت مرچنت زرین‌پال از تنظیمات دیتابیس یا ثابت
$merchant_id = 'dd754924-f6dc-4836-805f-58f8333567b8'; // یا خواندن از جدول settings

// فراخوانی درگاه زرین‌پال (تبدیل به ریال برای زرین‌پال)
$amount_rial = $total_amount * 10;
$callback_url = "https://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/shop_verify.php?order_id=" . $order_id;

$data = [
    'merchant_id' => $merchant_id,
    'amount' => $amount_rial,
    'description' => 'خرید ' . $product['name'] . ' از فروشگاه باشگاه',
    'callback_url' => $callback_url,
];

$jsonData = json_encode($data);
$ch = curl_init('https://api.zarinpal.com/pg/v4/payment/request.json');
curl_setopt($ch, CURLOPT_USERAGENT, 'ZarinPal Rest Api v1');
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Content-Length: ' . strlen($jsonData)]);

$result = curl_exec($ch);
curl_close($ch);
$result = json_decode($result, true);

if (isset($result['data']['code']) && $result['data']['code'] == 100) {
    $authority = $result['data']['authority'];
    header('Location: https://www.zarinpal.com/pg/StartPay/' . $authority);
    exit;
} else {
    echo 'خطا در اتصال به درگاه: ' . ($result['errors']['message'] ?? 'خطای نامشخص');
}