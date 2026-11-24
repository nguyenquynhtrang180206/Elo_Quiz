<?php
// Đăng xuất sinh viên (C-03). Đặt tại: elo_quiz/client/dangxuat.php
require_once __DIR__ . '/../core/bootstrap.php';

Auth::dangXuat('client');
redirect(BASE_URL . '/client/dangnhap.php');
