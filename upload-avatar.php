<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

// بررسی وضعیت لاگین
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'ابتدا وارد حساب خود شوید.']);
    exit;
}

// فراخوانی فایل اتصال به دیتابیس (مسیر فایل دیتابیس پروژه)
require_once 'config/database.php'; 

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['avatar'])) {
    $file = $_FILES['avatar'];
    $userId = (int) $_SESSION['user_id'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'خطا در ارسال فایل.']);
        exit;
    }

    // محدودیت حجم (حداکثر ۲ مگابایت)
    if ($file['size'] > 2 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'حجم تصویر نباید بیشتر از ۲ مگابایت باشد.']);
        exit;
    }

    // اعتبارسنجی فرمت فایل
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $allowedMimes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp'
    ];

    if (!array_key_exists($mimeType, $allowedMimes)) {
        echo json_encode(['success' => false, 'message' => 'فقط فرمت‌های JPG، PNG و WEBP مجاز هستند.']);
        exit;
    }

    $uploadDir = __DIR__ . '/uploads/avatars/';
    $extension = $allowedMimes[$mimeType];
    $newFileName = 'user_' . $userId . '_' . time() . '.' . $extension;
    $targetPath = $uploadDir . $newFileName;

    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        try {
            // حذف تصویر قبلی کاربر از هاست جهت جلوگیری از پر شدن حافظه
            $stmt = $pdo->prepare("SELECT avatar FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $oldAvatar = $stmt->fetchColumn();

            if ($oldAvatar && file_exists($uploadDir . $oldAvatar)) {
                unlink($uploadDir . $oldAvatar);
            }

            // ثبت نام تصویر جدید در جدول users
            $update = $pdo->prepare("UPDATE users SET avatar = ? WHERE id = ?");
            $update->execute([$newFileName, $userId]);

            // به‌روزرسانی سشن
            $_SESSION['user_avatar'] = $newFileName;

            echo json_encode([
                'success' => true, 
                'message' => 'تصویر پروفایل با موفقیت بروز شد.',
                'avatar_url' => 'uploads/avatars/' . $newFileName
            ]);
            exit;

        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'خطا در ثبت اطلاعات در دیتابیس.']);
            exit;
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'خطا در ذخیره فایل روی هاست.']);
        exit;
    }
}

echo json_encode(['success' => false, 'message' => 'درخواست نامعتبر است.']);
exit;