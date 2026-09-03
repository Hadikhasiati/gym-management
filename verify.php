<?php
// verify.php
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/db.php';
if (file_exists(__DIR__ . '/tenant.php')) {
    require_once __DIR__ . '/tenant.php';
}

if (!defined('CURRENT_CLUB_ID')) define('CURRENT_CLUB_ID', 1);
if (!defined('CURRENT_CLUB_NAME')) define('CURRENT_CLUB_NAME', 'باشگاه رادین اسکیت');
if (!defined('CURRENT_CLUB_THEME')) define('CURRENT_CLUB_THEME', '#0284c7');

$authority = $_GET['Authority'] ?? '';
$status    = $_GET['Status'] ?? '';

$is_api = (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) || isset($_GET['is_api']);

$success = false;
$message = '';
$ref_id  = '';

if (empty($authority)) {
    $message = 'شناسه بازگشت از درگاه (Authority) یافت نشد.';
} elseif ($status !== 'OK') {
    $message = 'پرداخت توسط کاربر لغو شد یا با خطا مواجه گردید.';
    // ثبت وضعیت ناموفق در دیتابیس
    $stmtFail = $pdo->prepare("UPDATE payments SET status = 'failed' WHERE tracking_code = ? AND club_id = ?");
    $stmtFail->execute([$authority, CURRENT_CLUB_ID]);
} else {
    // واکشی تراکنش ثبت شده برای این باشگاه
    $stmtPay = $pdo->prepare("SELECT * FROM payments WHERE tracking_code = ? AND club_id = ? LIMIT 1");
    $stmtPay->execute([$authority, CURRENT_CLUB_ID]);
    $payment = $stmtPay->fetch(PDO::FETCH_ASSOC);

    if (!$payment) {
        $message = 'تراکنش متناظر با این پرداخت در این باشگاه یافت نشد.';
    } else {
        // واکشی مرچنت کد اختصاصی باشگاه
        $stmtClub = $pdo->prepare("SELECT zarinpal_merchant FROM clubs WHERE id = ? LIMIT 1");
        $stmtClub->execute([CURRENT_CLUB_ID]);
        $merchant_id = $stmtClub->fetchColumn();

        // ارسال درخواست وریفای به زرین‌پال
        $verifyData = [
            'merchant_id' => $merchant_id,
            'amount'      => (int)$payment['amount'],
            'authority'   => $authority
        ];
        $jsonData = json_encode($verifyData);

        $ch = curl_init('https://payment.zarinpal.com/pg/v4/payment/verify.json');
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
        curl_close($ch);
        $response = json_decode($result, true);

        $res_code = $response['data']['code'] ?? 0;
        $ref_id   = (string)($response['data']['ref_id'] ?? '');

        if ($res_code === 100 || $res_code === 101) {
            $success = true;
            $message = 'پرداخت شهریه با موفقیت انجام و تایید شد.';

            // ۱. ثبت وضعیت موفق در جدول پرداخت‌ها
            $stmtUpPay = $pdo->prepare("UPDATE payments SET status = 'success', card_pan = ?, paid_at = NOW() WHERE id = ? AND club_id = ?");
            $stmtUpPay->execute([$ref_id, $payment['id'], CURRENT_CLUB_ID]);

            // ۲. تمدید ۳۰ روزه اشتراک کاربر
            $stmtUser = $pdo->prepare("SELECT subscription_expires_at FROM users WHERE id = ? AND club_id = ? LIMIT 1");
            $stmtUser->execute([$payment['user_id'], CURRENT_CLUB_ID]);
            $current_exp = $stmtUser->fetchColumn();

            $base_date = (empty($current_exp) || $current_exp < date('Y-m-d')) ? date('Y-m-d') : $current_exp;
            $new_expires_at = date('Y-m-d', strtotime($base_date . ' +30 days'));

            $stmtUpUser = $pdo->prepare("UPDATE users SET subscription_expires_at = ? WHERE id = ? AND club_id = ?");
            $stmtUpUser->execute([$new_expires_at, $payment['user_id'], CURRENT_CLUB_ID]);

        } else {
            $message = $response['errors']['message'] ?? 'تراکنش توسط درگاه تایید نشد.';
            $stmtFail = $pdo->prepare("UPDATE payments SET status = 'failed' WHERE id = ? AND club_id = ?");
            $stmtFail->execute([$payment['id'], CURRENT_CLUB_ID]);
        }
    }
}

if ($is_api) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success'   => $success,
        'message'   => $message,
        'ref_id'    => $ref_id,
        'authority' => $authority
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>نتیجه تراکنش | <?= htmlspecialchars(CURRENT_CLUB_NAME) ?></title>
    <style>
        :root { --primary-color: <?= htmlspecialchars(CURRENT_CLUB_THEME) ?>; }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: system-ui, -apple-system, sans-serif; }
        body { background-color: #0b1120; color: #f8fafc; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 1.5rem; }
        .result-card { background: #111827; border: 1px solid #1f2937; border-radius: 18px; max-width: 440px; width: 100%; padding: 2rem; text-align: center; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
        .icon-badge { width: 64px; height: 64px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2rem; margin: 0 auto 1.25rem auto; }
        .icon-success { background: rgba(16, 185, 129, 0.15); color: #34d399; border: 2px solid #10b981; }
        .icon-error { background: rgba(239, 68, 68, 0.15); color: #f87171; border: 2px solid #ef4444; }
        h2 { font-size: 1.2rem; font-weight: 800; margin-bottom: 0.75rem; }
        p { font-size: 0.9rem; color: #94a3b8; line-height: 1.6; margin-bottom: 1.5rem; }
        .ref-box { background: #1e293b; border: 1px solid #334155; border-radius: 10px; padding: 0.75rem; margin-bottom: 1.5rem; font-size: 0.85rem; color: #cbd5e1; }
        .ref-box strong { color: #38bdf8; font-family: monospace; }
        .btn-action { display: inline-block; width: 100%; padding: 0.85rem; background: var(--primary-color); color: #fff; border-radius: 10px; text-decoration: none; font-weight: 700; font-size: 0.95rem; }
    </style>
</head>
<body>
    <div class="result-card">
        <div class="icon-badge <?= $success ? 'icon-success' : 'icon-error' ?>">
            <?= $success ? '✓' : '✕' ?>
        </div>
        <h2 style="color: <?= $success ? '#34d399' : '#f87171' ?>;">
            <?= $success ? 'پرداخت موفقیت‌آمیز' : 'خطا در پرداخت' ?>
        </h2>
        <p><?= htmlspecialchars($message) ?></p>

        <?php if (!empty($ref_id)): ?>
            <div class="ref-box">
                شماره پیگیری تراکنش (RefID):<br>
                <strong><?= htmlspecialchars($ref_id) ?></strong>
            </div>
        <?php endif; ?>

        <a href="dashboard.php" class="btn-action">بازگشت به پیشخوان</a>
    </div>
</body>
</html>