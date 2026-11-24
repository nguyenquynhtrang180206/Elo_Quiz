<?php
// Đăng xuất quản trị (A-01) -> Quay về giao diện đăng nhập chung.
require_once __DIR__ . '/../core/bootstrap.php';

Auth::dangXuat('admin');
redirect(BASE_URL . '/client/dangnhap.php');
