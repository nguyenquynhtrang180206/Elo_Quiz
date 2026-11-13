<?php
// Đăng xuất quản trị (A-01). Menu ở layout/header.php đã trỏ tới file này.
require_once __DIR__ . '/../core/bootstrap.php';

// Chỉ xóa khóa của Admin, không đụng tới phiên sinh viên nếu cùng trình duyệt
unset($_SESSION['admin_id']);
// Phiên sinh viên do admin chuyển sang cũng kết thúc cùng lúc, tránh để lại phiên trên máy dùng chung
if (isset($_SESSION['nd_la_admin'])) {
    unset($_SESSION['nd_id'], $_SESSION['nd_la_admin']);
}
session_regenerate_id(true);

redirect(BASE_URL . '/admin/dangnhap.php');