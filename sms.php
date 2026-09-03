<?php
// sms.php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
if (file_exists(__DIR__ . '/tenant.php')) {
    require_once __DIR__ . '/tenant.php';
}

if (!defined('CURRENT_CLUB_ID')) define('CURRENT_CLUB_ID', 1);
if (!defined('CURRENT_CLUB_NAME')) define('CURRENT_CLUB_NAME', 'باشگاه رادین اسکیت');

/**
 * ارسال پیامک OTP هوشمند با نام اختصاصی هر باشگاه
 */
function send_otp_sms(string $to_phone, string $otp_code, $pattern_type = 'login'): bool {
    global $pdo;

    // ۱. مشخصات پنل ملی‌پیامک
    $username = '9163363371';
    $password = 'f1477796-0932-42ff-b02e-9872a9a042c1';

    // ۲. دریافت نام باشگاه جاری از تنظیمات یا ثابت سراسری
    $club_name = CURRENT_CLUB_NAME;
    $body_id = 514224; // کد پترن جدید تایید شده را اینجا جایگزین کنید

    if (isset($pdo)) {
        try {
            $stmtClub = $pdo->prepare("SELECT name, sms_pattern_login FROM clubs WHERE id = ? LIMIT 1");
            $stmtClub->execute([CURRENT_CLUB_ID]);
            $club = $stmtClub->fetch(PDO::FETCH_ASSOC);

            if ($club) {
                if (!empty($club['name'])) $club_name = trim($club['name']);
                if (!empty($club['sms_pattern_login'])) $body_id = (int)$club['sms_pattern_login'];
            }
        } catch (Exception $e) {}
    }

    // ۳. ترکیب کد و نام باشگاه برای الگوهای دو متغیره ({0};{1})
    $formatted_text = $otp_code . ';' . $club_name;

    // ۴. ارسال درخواست به وب‌سرویس ملی‌پیامک
    $url = "https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber";
    $payload = json_encode([
        'username' => $username,
        'password' => $password,
        'text'     => $formatted_text,
        'to'       => $to_phone,
        'bodyId'   => $body_id
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($payload)
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200 && $response) {
        $resData = json_decode($response, true);
        if (isset($resData['RetStatus']) && (int)$resData['RetStatus'] === 1) {
            return true;
        }
        if (isset($resData['Value']) && is_numeric($resData['Value']) && (float)$resData['Value'] > 1000) {
            return true;
        }
        error_log("MeliPayamak Multi-Param Error: " . $response);
    }

    return false;
}