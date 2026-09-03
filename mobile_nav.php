<?php
// mobile_nav.php
if (!defined('CURRENT_PAGE')) {
    define('CURRENT_PAGE', basename($_SERVER['PHP_SELF']));
}
?>
<style>
    /* استایل استاندارد نوار ناوبری موبایل */
    .app-bottom-nav {
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        height: 65px;
        background: rgba(15, 23, 42, 0.95);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        border-top: 1px solid rgba(255, 255, 255, 0.08);
        display: flex;
        justify-content: space-around;
        align-items: center;
        z-index: 99999;
        padding: 0 4px;
        padding-bottom: env(safe-area-inset-bottom);
        box-shadow: 0 -10px 25px rgba(0, 0, 0, 0.5);
    }
    .app-nav-item {
        flex: 1;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        text-decoration: none;
        color: #94a3b8;
        font-size: 0.7rem;
        font-weight: 700;
        gap: 3px;
        padding: 6px 0;
        transition: all 0.2s ease;
        -webkit-tap-highlight-color: transparent;
    }
    .app-nav-item .nav-icon {
        font-size: 1.3rem;
        line-height: 1;
        transition: transform 0.2s ease;
    }
    .app-nav-item.active {
        color: #38bdf8;
    }
    .app-nav-item.active .nav-icon {
        transform: translateY(-2px) scale(1.15);
    }

    /* دکمه برجسته و شناور پیشخوان در مرکز */
    .app-nav-center {
        position: relative;
        top: -14px;
        flex: 1;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        text-decoration: none;
        -webkit-tap-highlight-color: transparent;
    }
    .app-center-btn {
        width: 50px;
        height: 50px;
        border-radius: 50%;
        background: linear-gradient(135deg, #0284c7, #0369a1);
        border: 4px solid #0b1120;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.45rem;
        color: #fff;
        box-shadow: 0 6px 20px rgba(2, 132, 199, 0.5);
        transition: transform 0.2s ease;
    }
    .app-nav-center:active .app-center-btn,
    .app-nav-center.active .app-center-btn {
        transform: scale(1.08);
        box-shadow: 0 8px 25px rgba(56, 189, 248, 0.7);
    }
    .app-center-label {
        font-size: 0.7rem;
        font-weight: 800;
        color: #38bdf8;
        margin-top: 2px;
    }
</style>

<nav class="app-bottom-nav">
    <!-- ۱. حضور و غیاب -->
    <a href="attendance.php" class="app-nav-item <?= CURRENT_PAGE === 'attendance.php' ? 'active' : '' ?>">
        <span class="nav-icon">📅</span>
        <span>حضور غیاب</span>
    </a>

    <!-- ۲. فروشگاه -->
    <a href="shop.php" class="app-nav-item <?= CURRENT_PAGE === 'shop.php' ? 'active' : '' ?>">
        <span class="nav-icon">🛒</span>
        <span>فروشگاه</span>
    </a>

    <!-- ۳. پیشخوان (دکمه گرد شناور در مرکز) -->
    <a href="dashboard.php" class="app-nav-center <?= CURRENT_PAGE === 'dashboard.php' ? 'active' : '' ?>">
        <div class="app-center-btn">🏠</div>
        <span class="app-center-label">پیشخوان</span>
    </a>

    <!-- ۴. شهریه و پرداخت -->
    <a href="payments.php" class="app-nav-item <?= CURRENT_PAGE === 'payments.php' ? 'active' : '' ?>">
        <span class="nav-icon">💳</span>
        <span>شهریه</span>
    </a>

    <!-- ۵. حرکات تمرینی -->
    <a href="exercises.php" class="app-nav-item <?= CURRENT_PAGE === 'exercises.php' ? 'active' : '' ?>">
        <span class="nav-icon">🛹</span>
        <span>حرکات</span>
    </a>
</nav>