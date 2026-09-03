<?php
// shop.php
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once 'auth.php';
require_once 'db.php';

if (function_exists('check_auth')) {
    check_auth();
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id'] ?? 0]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header('Location: login.php');
    exit;
}

$selected_cat = isset($_GET['cat']) ? (int)$_GET['cat'] : 0;

$categories = $pdo->query("SELECT * FROM product_categories ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

if ($selected_cat > 0) {
    $stmtProd = $pdo->prepare("SELECT * FROM products WHERE category_id = ? AND status = 'active' ORDER BY id DESC");
    $stmtProd->execute([$selected_cat]);
    $products = $stmtProd->fetchAll(PDO::FETCH_ASSOC);
} else {
    $products = $pdo->query("SELECT * FROM products WHERE status = 'active' ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
}

// سفارشات قبلی هنرجو
$my_orders = $pdo->prepare("
    SELECT o.*, p.name as product_name, p.image 
    FROM orders o 
    JOIN products p ON o.product_id = p.id 
    WHERE o.user_id = ? 
    ORDER BY o.id DESC
");
$my_orders->execute([$user['id']]);
$user_orders = $my_orders->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فروشگاه تجهیزات اسکیت | باشگاه رادین اسکیت</title>

    <!-- اتصال به تنظیمات اپلیکیشن PWA -->
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#0b1120">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="apple-touch-icon" href="icon-192.png">

    <script>
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('sw.js')
                .then(reg => console.log('PWA ServiceWorker Active'))
                .catch(err => console.error('PWA Error', err));
        }
    </script>

    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: system-ui, -apple-system, sans-serif; }
        body { background-color: #0b1120; color: #f8fafc; min-height: 100vh; padding: 1.5rem 1rem; }
        .container { max-width: 1100px; margin: 0 auto; }
        .top-navbar { display: flex; justify-content: space-between; align-items: center; background: #111827; border: 1px solid #1f2937; padding: 1rem 1.25rem; border-radius: 14px; margin-bottom: 1.5rem; }
        .btn-back { background: #1e293b; color: #94a3b8; border: 1px solid #334155; padding: 0.5rem 1rem; border-radius: 8px; text-decoration: none; font-size: 0.85rem; font-weight: 700; }

        /* فیلتر دسته‌بندی‌ها */
        .category-tabs { display: flex; gap: 0.6rem; overflow-x: auto; padding-bottom: 0.75rem; margin-bottom: 1.5rem; }
        .cat-chip { background: #1e293b; border: 1px solid #334155; color: #94a3b8; padding: 0.5rem 1.1rem; border-radius: 25px; text-decoration: none; font-size: 0.85rem; font-weight: 700; white-space: nowrap; transition: 0.2s; }
        .cat-chip.active, .cat-chip:hover { background: #0284c7; color: #fff; border-color: #0284c7; }

        /* شبکه محصولات */
        .products-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 1.25rem; margin-bottom: 2rem; }
        .product-card {
            background: #111827; border: 1px solid #1f2937; border-radius: 16px; overflow: hidden;
            display: flex; flex-direction: column; justify-content: space-between; transition: transform 0.2s;
        }
        .product-card:hover { transform: translateY(-4px); border-color: #38bdf8; }
        .prod-image-wrapper { width: 100%; height: 180px; background: #1e293b; position: relative; }
        .prod-image-wrapper img { width: 100%; height: 100%; object-fit: cover; }
        .prod-content { padding: 1rem; flex: 1; display: flex; flex-direction: column; justify-content: space-between; }
        .prod-title { font-size: 1rem; font-weight: 800; color: #f1f5f9; margin-bottom: 0.4rem; }
        .prod-desc { font-size: 0.8rem; color: #94a3b8; line-height: 1.5; margin-bottom: 1rem; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .prod-footer { display: flex; justify-content: space-between; align-items: center; border-top: 1px solid #1f2937; padding-top: 0.75rem; }
        .prod-price { font-size: 1.05rem; font-weight: 800; color: #4ade80; }
        .btn-buy { background: linear-gradient(135deg, #10b981, #059669); color: #fff; border: none; padding: 0.45rem 0.9rem; border-radius: 8px; font-weight: 700; font-size: 0.85rem; cursor: pointer; }

        /* مودال سفارش */
        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.75); backdrop-filter: blur(5px); display: none; align-items: center; justify-content: center; z-index: 999; padding: 1rem; }
        .modal-card { background: #111827; border: 1px solid #38bdf8; border-radius: 16px; max-width: 450px; width: 100%; padding: 1.5rem; }
        .form-group { display: flex; flex-direction: column; gap: 0.4rem; margin-bottom: 0.85rem; }
        .form-group label { font-size: 0.82rem; color: #94a3b8; font-weight: 600; }
        .form-control { background: #1e293b; border: 1px solid #334155; border-radius: 8px; padding: 0.6rem; color: #fff; font-size: 0.9rem; outline: none; }
    </style>
</head>
<body>
    <div class="container">
        <div class="top-navbar">
            <h2 style="font-size: 1.15rem; color: #38bdf8;">🛒 فروشگاه و تجهیزات تخصصی اسکیت</h2>
            <a href="dashboard.php" class="btn-back">بازگشت به پیشخوان ↵</a>
        </div>

        <!-- فیلتر دسته‌بندی‌ها -->
        <div class="category-tabs">
            <a href="shop.php" class="cat-chip <?= $selected_cat === 0 ? 'active' : '' ?>">همه محصولات</a>
            <?php foreach ($categories as $cat): ?>
                <a href="shop.php?cat=<?= $cat['id'] ?>" class="cat-chip <?= $selected_cat === $cat['id'] ? 'active' : '' ?>">
                    <?= htmlspecialchars($cat['name']) ?>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- لیست محصولات -->
        <div class="products-grid">
            <?php if (empty($products)): ?>
                <div style="grid-column: 1/-1; text-align:center; padding:3rem; color:#64748b;">محصولی در این دسته‌بندی یافت نشد.</div>
            <?php else: ?>
                <?php foreach ($products as $prod): ?>
                    <div class="product-card">
                        <div class="prod-image-wrapper">
                            <?php if (!empty($prod['image'])): ?>
                                <img src="<?= htmlspecialchars($prod['image']) ?>" alt="<?= htmlspecialchars($prod['name']) ?>">
                            <?php else: ?>
                                <div style="width:100%; height:100%; display:flex; align-items:center; justify-content:center; font-size:3rem;">⛸️</div>
                            <?php endif; ?>
                        </div>
                        <div class="prod-content">
                            <div>
                                <h3 class="prod-title"><?= htmlspecialchars($prod['name']) ?></h3>
                                <p class="prod-desc"><?= htmlspecialchars($prod['description'] ?: 'بدون توضیحات اضافی') ?></p>
                            </div>
                            <div class="prod-footer">
                                <div class="prod-price"><?= number_format($prod['price']) ?> <span style="font-size:0.75rem; font-weight:normal;">تومان</span></div>
                                <button class="btn-buy" onclick="openOrderModal(<?= htmlspecialchars(json_encode($prod)) ?>)">خرید آنلاین</button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- بخش سفارش‌های قبلی هنرجو -->
        <?php if (!empty($user_orders)): ?>
            <div style="background:#111827; border:1px solid #1f2937; border-radius:14px; padding:1.25rem; margin-top:2rem;">
                <h3 style="font-size:1rem; color:#38bdf8; margin-bottom:1rem;">📦 سوابق خریدهای شما از فروشگاه</h3>
                <div style="overflow-x:auto;">
                    <table style="width:100%; border-collapse:collapse; text-align:right; font-size:0.85rem;">
                        <thead>
                            <tr style="color:#94a3b8; border-bottom:1px solid #1f2937;">
                                <th style="padding:0.6rem;">محصول</th>
                                <th>تعداد</th>
                                <th>مبلغ پرداختی</th>
                                <th>وضعیت</th>
                                <th>کد رهگیری</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($user_orders as $uo): ?>
                                <tr style="border-bottom:1px solid #1f2937;">
                                    <td style="padding:0.6rem;"><strong><?= htmlspecialchars($uo['product_name']) ?></strong> (<?= htmlspecialchars($uo['selected_size'] ?: '-') ?>)</td>
                                    <td><?= $uo['quantity'] ?></td>
                                    <td style="color:#4ade80; font-weight:700;"><?= number_format($uo['total_amount']) ?> ت</td>
                                    <td>
                                        <?= $uo['status'] === 'paid' ? '<span style="color:#4ade80;">پرداخت شده</span>' : ($uo['status'] === 'delivered' ? '<span style="color:#38bdf8;">تحویل داده شده</span>' : '<span style="color:#fbbf24;">در انتظار</span>') ?>
                                    </td>
                                    <td style="font-family:monospace; color:#94a3b8;"><?= htmlspecialchars($uo['tracking_code'] ?: '-') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- پنجره مودال خرید و پرداخت -->
    <div class="modal-overlay" id="orderModal">
        <div class="modal-card">
            <h3 id="modalProdTitle" style="color:#38bdf8; margin-bottom:1rem; font-size:1.1rem;">ثبت سفارش و پرداخت</h3>
            
            <form action="shop_pay.php" method="POST">
                <input type="hidden" name="product_id" id="modalProdId">
                <input type="hidden" name="unit_price" id="modalProdPrice">

                <div class="form-group" id="sizeGroup">
                    <label>انتخاب سایز:</label>
                    <select name="size" id="modalSizes" class="form-control"></select>
                </div>

                <div class="form-group" id="colorGroup">
                    <label>انتخاب رنگ:</label>
                    <select name="color" id="modalColors" class="form-control"></select>
                </div>

                <div class="form-group">
                    <label>تعداد سفارش:</label>
                    <input type="number" name="quantity" id="modalQty" class="form-control" value="1" min="1" max="10" onchange="calculateTotal()" required>
                </div>

                <div style="background:#1e293b; padding:0.75rem; border-radius:8px; margin-bottom:1rem; display:flex; justify-content:space-between; align-items:center;">
                    <span style="font-size:0.85rem; color:#94a3b8;">مبلغ نهایی پرداخت:</span>
                    <span id="modalTotalPrice" style="font-size:1.1rem; font-weight:800; color:#4ade80;">۰ تومان</span>
                </div>

                <div style="display:flex; gap:0.5rem; justify-content:flex-end;">
                    <button type="button" onclick="closeOrderModal()" style="background:#334155; color:#fff; border:none; padding:0.6rem 1rem; border-radius:8px; cursor:pointer;">انصراف</button>
                    <button type="submit" style="background:linear-gradient(135deg, #10b981, #059669); color:#fff; border:none; padding:0.6rem 1.25rem; border-radius:8px; font-weight:700; cursor:pointer;">انتقال به درگاه پرداخت 💳</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let currentPrice = 0;

        function openOrderModal(product) {
            currentPrice = product.price;
            document.getElementById('modalProdId').value = product.id;
            document.getElementById('modalProdPrice').value = product.price;
            document.getElementById('modalProdTitle').innerText = 'خرید: ' + product.name;
            
            // سایزها
            const sizeSelect = document.getElementById('modalSizes');
            sizeSelect.innerHTML = '';
            if (product.sizes && product.sizes.trim() !== '') {
                document.getElementById('sizeGroup').style.display = 'flex';
                product.sizes.split(',').forEach(s => {
                    const opt = document.createElement('option');
                    opt.value = s.trim();
                    opt.innerText = s.trim();
                    sizeSelect.appendChild(opt);
                });
            } else {
                document.getElementById('sizeGroup').style.display = 'none';
            }

            // رنگ‌ها
            const colorSelect = document.getElementById('modalColors');
            colorSelect.innerHTML = '';
            if (product.colors && product.colors.trim() !== '') {
                document.getElementById('colorGroup').style.display = 'flex';
                product.colors.split(',').forEach(c => {
                    const opt = document.createElement('option');
                    opt.value = c.trim();
                    opt.innerText = c.trim();
                    colorSelect.appendChild(opt);
                });
            } else {
                document.getElementById('colorGroup').style.display = 'none';
            }

            document.getElementById('modalQty').value = 1;
            calculateTotal();
            document.getElementById('orderModal').style.display = 'flex';
        }

        function calculateTotal() {
            const qty = parseInt(document.getElementById('modalQty').value) || 1;
            const total = currentPrice * qty;
            document.getElementById('modalTotalPrice').innerText = total.toLocaleString('fa-IR') + ' تومان';
        }

        function closeOrderModal() {
            document.getElementById('orderModal').style.display = 'none';
        }
    </script>
</body>
</html>