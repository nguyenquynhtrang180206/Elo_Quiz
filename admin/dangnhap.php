<?php
// Chuyển hướng cổng đăng nhập quản trị về trang đăng nhập chung.
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
