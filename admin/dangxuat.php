<?php
// Đăng xuất quản trị (A-01). Menu ở layout/header.php đã trỏ tới file này.
require_once __DIR__ . '/../core/bootstrap.php';

// Chỉ xóa khóa của Admin, không đụng tới phiên sinh viên nếu cùng trình duyệt
unset($_SESSION['admin_id']);
session_regenerate_id(true);

redirect(BASE_URL . '/admin/login.php');