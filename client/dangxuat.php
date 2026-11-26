<?php
// Đăng xuất tài khoản sinh viên
require_once __DIR__ . '/../core/bootstrap.php';

Auth::dangXuat('client');
redirect(BASE_URL . '/client/dangnhap.php');
