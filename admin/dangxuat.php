<?php
// Đăng xuất quản trị và chuyển về trang đăng nhập chung.
require_once __DIR__ . '/../core/bootstrap.php';

Auth::dangXuat('admin');
redirect(BASE_URL . '/client/dangnhap.php');
