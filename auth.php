<?php
// auth.php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();
}

require_once __DIR__ . '/db.php';
if (file_exists(__DIR__ . '/tenant.php')) {
    require_once __DIR__ . '/tenant.php';
}

if (!defined('CURRENT_CLUB_ID')) define('CURRENT_CLUB_ID', 1);

/**
 * بررسی لاگین بودن و اعتبار باشگاه کاربر
 */
function check_auth() {
    global $pdo;

    // ۱. بررسی توکن اپلیکیشن اندروید (Bearer Token)
    $headers = getallheaders();
    $auth_header = $headers['Authorization'] ?? $headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    
    if (preg_match('/Bearer\s(\S+)/', $auth_header, $matches)) {
        $token = $matches[1];
        $stmt = $pdo->prepare("SELECT * FROM users WHERE auth_token = ? AND club_id = ? LIMIT 1");
        $stmt->execute([$token, CURRENT_CLUB_ID]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user'] = $user;
            $_SESSION['user_role'] = $user['role'];
            return $user;
        }
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'توکن نامعتبر است یا منقضی شده است']);
        exit;
    }

    // ۲. بررسی نشست تحت وب مرورگر
    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php");
        exit;
    }

    // ۳. واکشی کامل مشخصات کاربر و اعتبارسنجی باشگاه
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([(int)$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || (int)$user['club_id'] !== CURRENT_CLUB_ID) {
        unset($_SESSION['user_id']);
        unset($_SESSION['user']);
        unset($_SESSION['user_role']);
        header("Location: login.php?error=unauthorized_club");
        exit;
    }

    $_SESSION['user'] = $user;
    $_SESSION['user_role'] = $user['role'];
    return $user;
}

/**
 * تابع بازگرداندن مشخصات کاربر جاری (سازگاری کامل با فایل‌های قبلی)
 */
function current_user() {
    global $pdo;
    if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
        return $_SESSION['user'];
    }
    if (isset($_SESSION['user_id'])) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$_SESSION['user_id']]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($u) {
            $_SESSION['user'] = $u;
            return $u;
        }
    }
    return null;
}

/**
 * بررسی دسترسی ادمین
 */
function is_club_admin() {
    $user = current_user();
    return ($user && ($user['role'] ?? '') === 'admin');
}