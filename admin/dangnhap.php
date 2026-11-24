<?php
// =========================================================================
// CỔNG ĐĂNG NHẬP QUẢN TRỊ -> HỢP NHẤT VỀ GIAO DIỆN ĐĂNG NHẬP CHUNG
// Đặt tại: elo_quiz/admin/dangnhap.php
// Mọi yêu cầu vào /admin/dangnhap.php được chuyển về giao diện đăng nhập duy nhất
// tại /client/dangnhap.php (hệ thống tự động phân quyền theo tài khoản đăng nhập).
// =========================================================================
require_once __DIR__ . '/../core/bootstrap.php';

if (Auth::adminId() !== null) {
    redirect(BASE_URL . '/admin/index.php');
}

$params = [];
if (!empty($_GET['ten_dang_nhap'])) {
    $params['email'] = (string) $_GET['ten_dang_nhap'];
}
if (!empty($_GET['email'])) {
    $params['email'] = (string) $_GET['email'];
}

$url = BASE_URL . '/client/dangnhap.php' . ($params ? '?' . http_build_query($params) : '');
redirect($url);
