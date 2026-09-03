<?php
// admin_shop.php
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/db.php';
if (file_exists(__DIR__ . '/tenant.php')) {
    require_once __DIR__ . '/tenant.php';
}
require_once __DIR__ . '/auth.php';

if (!defined('CURRENT_CLUB_ID')) define('CURRENT_CLUB_ID', 1);
if (!defined('CURRENT_CLUB_NAME')) define('CURRENT_CLUB_NAME', 'باشگاه رادین اسکیت');
if (!defined('CURRENT_CLUB_THEME')) define('CURRENT_CLUB_THEME', '#0284c7');

$current_user = check_auth();
$is_api = (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) || isset($_GET['is_api']) || isset($_POST['is_api']);

if (($current_user['role'] ?? '') !== 'admin') {
    if ($is_api) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'عدم دسترسی مدیریت']);
        exit;
    }
    header('Location: dashboard.php');
    exit;
}

$msg = '';
$error = '';

// ۱. افزودن دسته‌بندی جدید برای همین باشگاه
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_category'])) {
    $cat_name = trim($_POST['category_name'] ?? '');
    if (!empty($cat_name)) {
        $stmtCat = $pdo->prepare("INSERT INTO product_categories (club_id, name) VALUES (?, ?)");
        $stmtCat->execute([CURRENT_CLUB_ID, $cat_name]);
        $msg = 'دسته‌بندی با موفقیت اضافه شد.';
    }
}

// ۲. افزودن محصول با اعتبارسنجی امن تصویر
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_product'])) {
    $name = trim($_POST['name'] ?? '');
    $category_id = (int)($_POST['category_id'] ?? 0);
    $price = (int)($_POST['price'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $sizes = trim($_POST['sizes'] ?? '');
    $colors = trim($_POST['colors'] ?? '');
    $image_path = null;

    if (!empty($_FILES['image']['tmp_name'])) {
        $upload_dir = 'uploads/products/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        $file_info = getimagesize($_FILES['image']['tmp_name']);
        if ($file_info !== false) {
            $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'webp'];
            if (in_array($ext, $allowed)) {
                $filename = 'prod_' . CURRENT_CLUB_ID . '_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                $target = $upload_dir . $filename;
                if (move_uploaded_file($_FILES['image']['tmp_name'], $target)) {
                    $image_path = $target;
                }
            }
        }
    }

    if (!empty($name) && $category_id > 0 && $price > 0) {
        $stmtProd = $pdo->prepare("INSERT INTO products (club_id, category_id, name, description, price, image, sizes, colors) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmtProd->execute([CURRENT_CLUB_ID, $category_id, $name, $description, $price, $image_path, $sizes, $colors]);
        $msg = 'محصول با موفقیت ثبت شد.';
    } else {
        $error = 'نام محصول، دسته‌بندی و قیمت را وارد کنید.';
    }
}

// ۳. تغییر وضعیت سفارش
if (isset($_GET['delivered_order'])) {
    $order_id = (int)$_GET['delivered_order'];
    $stmtUp = $pdo->prepare("UPDATE orders SET status = 'delivered' WHERE id = ? AND club_id = ?");
    $stmtUp->execute([$order_id, CURRENT_CLUB_ID]);
    header('Location: admin_shop.php');
    exit;
}

// ۴. حذف محصول
if (isset($_GET['delete_product'])) {
    $del_id = (int)$_GET['delete_product'];
    $stmtDel = $pdo->prepare("DELETE FROM products WHERE id = ? AND club_id = ?");
    $stmtDel->execute([$del_id, CURRENT_CLUB_ID]);
    header('Location: admin_shop.php');
    exit;
}

// دریافت اطلاعات مخصوص باشگاه جاری
$stmtCategories = $pdo->prepare("SELECT * FROM product_categories WHERE club_id = ? ORDER BY id DESC");
$stmtCategories->execute([CURRENT_CLUB_ID]);
$categories = $stmtCategories->fetchAll(PDO::FETCH_ASSOC);

$stmtProducts = $pdo->prepare("
    SELECT p.*, c.name as category_name 
    FROM products p 
    LEFT JOIN product_categories c ON p.category_id = c.id 
    WHERE p.club_id = ?
    ORDER BY p.id DESC
");
$stmtProducts->execute([CURRENT_CLUB_ID]);
$products = $stmtProducts->fetchAll(PDO::FETCH_ASSOC);

$stmtOrders = $pdo->prepare("
    SELECT o.*, u.full_name, u.phone, p.name as product_name, p.image as product_image
    FROM orders o
    JOIN users u ON o.user_id = u.id
    JOIN products p ON o.product_id = p.id
    WHERE o.club_id = ?
    ORDER BY o.id DESC
");
$stmtOrders->execute([CURRENT_CLUB_ID]);
$orders = $stmtOrders->fetchAll(PDO::FETCH_ASSOC);

if ($is_api) {
    echo json_encode([
        'success'    => true,
        'categories' => $categories,
        'products'   => $products,
        'orders'     => $orders
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مدیریت فروشگاه | <?= htmlspecialchars(CURRENT_CLUB_NAME) ?></title>

    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#0b1120">

    <style>
        :root { --primary-color: <?= htmlspecialchars(CURRENT_CLUB_THEME) ?>; }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: system-ui, -apple-system, sans-serif; }
        body { background-color: #0b1120; color: #f8fafc; min-height: 100vh; padding: 1.5rem 1rem; }
        .container { max-width: 1100px; margin: 0 auto; }
        .top-navbar { display: flex; justify-content: space-between; align-items: center; background: #111827; border: 1px solid #1f2937; padding: 1rem 1.25rem; border-radius: 14px; margin-bottom: 1.5rem; }
        .btn-back { background: #1e293b; color: #94a3b8; border: 1px solid #334155; padding: 0.5rem 1rem; border-radius: 8px; text-decoration: none; font-size: 0.85rem; font-weight: 700; }
        .card { background: #111827; border: 1px solid #1f2937; border-radius: 14px; padding: 1.5rem; margin-bottom: 1.5rem; }
        .card h3 { font-size: 1.1rem; color: #38bdf8; margin-bottom: 1rem; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 1rem; }
        .form-group { display: flex; flex-direction: column; gap: 0.4rem; }
        .form-group label { font-size: 0.85rem; color: #94a3b8; font-weight: 600; }
        .form-control { background: #1e293b; border: 1px solid #334155; border-radius: 8px; padding: 0.65rem 0.85rem; color: #fff; font-size: 0.9rem; outline: none; }
        .form-control:focus { border-color: #38bdf8; }
        .btn-submit { background: var(--primary-color); color: #fff; border: none; padding: 0.75rem 1.5rem; border-radius: 8px; font-weight: 700; cursor: pointer; }
        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; text-align: right; font-size: 0.88rem; min-width: 600px; }
        th, td { padding: 0.8rem; border-bottom: 1px solid #1f2937; vertical-align: middle; }
        th { color: #94a3b8; }
        .prod-img { width: 45px; height: 45px; border-radius: 8px; object-fit: cover; background: #1e293b; }
        .badge-paid { background: #10b98125; color: #34d399; border: 1px solid #10b981; padding: 0.2rem 0.5rem; border-radius: 6px; font-weight: 700; font-size: 0.75rem; }
        .badge-delivered { background: #3b82f625; color: #60a5fa; border: 1px solid #3b82f6; padding: 0.2rem 0.5rem; border-radius: 6px; font-weight: 700; font-size: 0.75rem; }
        .badge-pending { background: #f59e0b25; color: #fbbf24; border: 1px solid #f59e0b; padding: 0.2rem 0.5rem; border-radius: 6px; font-weight: 700; font-size: 0.75rem; }
    </style>
</head>
<body>
    <div class="container">
        <div class="top-navbar">
            <h2 style="font-size: 1.15rem; color: #38bdf8;">🛒 مدیریت فروشگاه | <?= htmlspecialchars(CURRENT_CLUB_NAME) ?></h2>
            <a href="dashboard.php" class="btn-back">بازگشت به پیشخوان ↵</a>
        </div>

        <?php if (!empty($msg)): ?>
            <div style="background:#10b98120; border:1px solid #10b981; color:#34d399; padding:0.75rem; border-radius:8px; margin-bottom:1rem;"><?= htmlspecialchars($msg) ?></div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div style="background:#ef444420; border:1px solid #ef4444; color:#f87171; padding:0.75rem; border-radius:8px; margin-bottom:1rem;"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="card">
            <h3>🏷️ افزودن دسته‌بندی جدید</h3>
            <form method="POST" style="display:flex; gap:0.75rem; flex-wrap:wrap;">
                <input type="text" name="category_name" class="form-control" style="flex:1; min-width:200px;" placeholder="نام دسته (مثال: لوازم جانبی، کلاه، کفش)" required>
                <button type="submit" name="add_category" class="btn-submit">ثبت دسته</button>
            </form>
        </div>

        <div class="card">
            <h3>➕ افزودن محصول جدید</h3>
            <form method="POST" enctype="multipart/form-data">
                <div class="form-grid">
                    <div class="form-group">
                        <label>نام محصول *</label>
                        <input type="text" name="name" class="form-control" placeholder="نام محصول" required>
                    </div>

                    <div class="form-group">
                        <label>دسته‌بندی *</label>
                        <select name="category_id" class="form-control" required>
                            <option value="">انتخاب دسته بندی...</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>قیمت (تومان) *</label>
                        <input type="number" name="price" class="form-control" placeholder="مبلغ به تومان" required>
                    </div>

                    <div class="form-group">
                        <label>عکس محصول</label>
                        <input type="file" name="image" class="form-control" accept="image/*">
                    </div>

                    <div class="form-group">
                        <label>سایزها (با کاما جدا کنید)</label>
                        <input type="text" name="sizes" class="form-control" placeholder="مثال: 36,37,38,39">
                    </div>

                    <div class="form-group">
                        <label>رنگ‌ها (با کاما جدا کنید)</label>
                        <input type="text" name="colors" class="form-control" placeholder="مثال: مشکی,آبی,قرمز">
                    </div>

                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>توضیحات محصول</label>
                        <textarea name="description" class="form-control" rows="3" placeholder="توضیحات و ویژگی‌ها..."></textarea>
                    </div>
                </div>
                <button type="submit" name="add_product" class="btn-submit">🚀 انتشار محصول</button>
            </form>
        </div>

        <div class="card">
            <h3>📦 لیست سفارشات</h3>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>هنرجو</th>
                            <th>محصول</th>
                            <th>سایز / رنگ</th>
                            <th>تعداد</th>
                            <th>مبلغ کل</th>
                            <th>وضعیت پرداخت</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($orders)): ?>
                            <tr><td colspan="7" style="text-align:center; color:#64748b; padding:1.5rem;">سفارشی ثبت نشده است.</td></tr>
                        <?php else: ?>
                            <?php foreach ($orders as $ord): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($ord['full_name']) ?></strong><br>
                                        <span style="font-size:0.75rem; color:#64748b;"><?= htmlspecialchars($ord['phone']) ?></span>
                                    </td>
                                    <td><?= htmlspecialchars($ord['product_name']) ?></td>
                                    <td>سایز: <?= htmlspecialchars($ord['selected_size'] ?: '-') ?> | رنگ: <?= htmlspecialchars($ord['selected_color'] ?: '-') ?></td>
                                    <td><?= $ord['quantity'] ?> عدد</td>
                                    <td style="font-weight:700; color:#38bdf8;"><?= number_format($ord['total_amount']) ?> ت</td>
                                    <td>
                                        <?php if ($ord['status'] === 'paid'): ?>
                                            <span class="badge-paid">✓ پرداخت شده</span>
                                        <?php elseif ($ord['status'] === 'delivered'): ?>
                                            <span class="badge-delivered">✓ تحویل داده شد</span>
                                        <?php else: ?>
                                            <span class="badge-pending">در انتظار</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($ord['status'] === 'paid'): ?>
                                            <a href="admin_shop.php?delivered_order=<?= $ord['id'] ?>" style="background:var(--primary-color); color:#fff; padding:0.25rem 0.6rem; border-radius:6px; text-decoration:none; font-size:0.75rem;">ثبت تحویل</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <h3>📋 لیست محصولات فروشگاه</h3>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>تصویر</th>
                            <th>نام محصول</th>
                            <th>دسته</th>
                            <th>قیمت (تومان)</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $p): ?>
                            <tr>
                                <td>
                                    <?php if (!empty($p['image'])): ?>
                                        <img src="<?= htmlspecialchars($p['image']) ?>" class="prod-img">
                                    <?php else: ?>
                                        <div class="prod-img" style="display:flex; align-items:center; justify-content:center;">⛸️</div>
                                    <?php endif; ?>
                                </td>
                                <td><strong><?= htmlspecialchars($p['name']) ?></strong></td>
                                <td><span style="background:#1e293b; padding:0.2rem 0.5rem; border-radius:6px; font-size:0.8rem;"><?= htmlspecialchars($p['category_name'] ?? 'عمومی') ?></span></td>
                                <td style="font-weight:700; color:#4ade80;"><?= number_format($p['price']) ?></td>
                                <td>
                                    <a href="admin_shop.php?delete_product=<?= $p['id'] ?>" style="color:#ef4444; text-decoration:none; font-size:0.8rem;" onclick="return confirm('محصول حذف شود؟')">حذف</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>