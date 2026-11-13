<?php
// Đăng xuất sinh viên (C-03). Menu ở layout/header.php đã trỏ tới file này.
require_once __DIR__ . '/../core/bootstrap.php';

// Chỉ xóa khóa của sinh viên, không đụng tới phiên Admin nếu cùng trình duyệt
unset($_SESSION['nd_id'], $_SESSION['nd_la_admin']);   // phiên admin (nếu có) vẫn giữ nguyên
session_regenerate_id(true);

redirect(BASE_URL . '/client/dangnhap.php');