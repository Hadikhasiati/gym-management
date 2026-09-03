<?php
// db.php
$db_host = 'localhost';
$db_name = 'tyucdeii_app';
$db_user = 'tyucdeii_app';
$db_pass = 'Hadi@6098'; // رمز عبور دیتابیس را اینجا وارد کنید

try {
    $pdo = new PDO("mysql:host={$db_host};dbname={$db_name};charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    die("خطا در برقراری ارتباط با پایگاه‌داده: " . $e->getMessage());
}