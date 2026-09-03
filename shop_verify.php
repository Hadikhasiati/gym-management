<?php
// shop_verify.php
require_once 'auth.php';
require_once 'db.php';

$order_id = (int)($_GET['order_id'] ?? 0);
$authority = $_GET['Authority'] ?? '';
$status = $_GET['Status'] ?? '';

if (!$order_id || $status !== 'OK') {
    die('پرداخت لغو شد یا ناموفق بود. <a href="shop.php">بازگشت به فروشگاه</a>');
}

$stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
$stmt->execute([$order_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order || $order['status'] === 'paid') {
    header('Location: shop.php');
    exit;
}

$merchant_id = 'dd754924-f6dc-4836-805f-58f8333567b8';
$amount_rial = $order['total_amount'] * 10;

$data = [
    'merchant_id' => $merchant_id,
    'authority' => $authority,
    'amount' => $amount_rial,
];

$jsonData = json_encode($data);
$ch = curl_init('https://api.zarinpal.com/pg/v4/payment/verify.json');
curl_setopt($ch, CURLOPT_USERAGENT, 'ZarinPal Rest Api v1');
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Content-Length: ' . strlen($jsonData)]);

$result = curl_exec($ch);
curl_close($ch);
$result = json_decode($result, true);

if (isset($result['data']['code']) && ($result['data']['code'] == 100 || $result['data']['code'] == 101)) {
    $ref_id = $result['data']['ref_id'];
    
    // ۱. به‌روزرسانی وضعیت سفارش
    $stmtUp = $pdo->prepare("UPDATE orders SET status = 'paid', tracking_code = ? WHERE id = ?");
    $stmtUp->execute([$ref_id, $order_id]);

    // ۲. ثبت در جدول پرداخت‌های کلی سامانه
    $stmtPay = $pdo->prepare("INSERT INTO payments (user_id, amount, status, tracking_code, paid_at) VALUES (?, ?, 'success', ?, NOW())");
    $stmtPay->execute([$order['user_id'], $amount_rial, $ref_id]);

    echo "<div style='font-family:sans-serif; text-align:center; padding:3rem; direction:rtl;'>
            <h2 style='color:#10b981;'>✓ پرداخت شما با موفقیت انجام شد</h2>
            <p>شماره پیگیری: <strong>$ref_id</strong></p>
            <a href='shop.php' style='display:inline-block; margin-top:1rem; padding:0.5rem 1rem; background:#0284c7; color:#fff; text-decoration:none; border-radius:8px;'>بازگشت به فروشگاه</a>
          </div>";
} else {
    echo 'پرداخت تایید نشد: ' . ($result['errors']['message'] ?? 'خطای نامشخص');
}