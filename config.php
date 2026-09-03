<?php
// شروع نشست‌ها برای مدیریت ورود کاربران
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// تنظیم منطقه زمانی به وقت تهران و پشتیبانی کامل از زبان فارسی
date_default_timezone_set('Asia/Tehran');
header('Content-Type: text/html; charset=utf-8');

// مشخصات اختصاصی دیتابیس
define('DB_HOST', 'localhost');
define('DB_NAME', 'tyucdeii_app');
define('DB_USER', 'tyucdeii_app');
define('DB_PASS', 'Hadi@6098');

try {
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ];

    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        $options
    );

} catch (PDOException $e) {
    die("<div style='direction:rtl;font-family:tahoma;padding:20px;background:#fee2e2;color:#991b1b;border-radius:10px;margin:20px;border:1px solid #f87171;'>
            <h3 style='margin-top:0;'>خطا در اتصال به پایگاه داده!</h3>
            <p>جزئیات خطا: " . htmlspecialchars($e->getMessage()) . "</p>
         </div>");
}