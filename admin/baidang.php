<?php
// Chuyển hướng tới trang kiểm duyệt bài đăng cộng đồng của Admin.
require_once __DIR__ . '/../core/bootstrap.php';
Auth::requireAdmin(BASE_URL . '/admin/dangnhap.php');

$id = (int) ($_GET['id'] ?? 0);
if ($id > 0) {
    redirect(BASE_URL . '/admin/congdong_baidang.php?id=' . $id);
}
redirect(BASE_URL . '/admin/congdong.php');
