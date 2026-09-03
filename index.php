<?php
// index.php
require_once 'auth.php';

if (isset($_SESSION['user_id'])) {
    header("Location: dashboard");
} else {
    header("Location: login");
}
exit;