<?php
// =========================================================================
// CỔNG KHÔI PHỤC MẬT KHẨU QUẢN TRỊ -> HỢP NHẤT VỀ CỔNG CHUNG (SSO)
// Đặt tại: elo_quiz/admin/quenmatkhau.php
// =========================================================================
require_once __DIR__ . '/../core/bootstrap.php';

if (Auth::adminId() !== null) {
    redirect(BASE_URL . '/admin/index.php');
}

$params = [];
if (!empty($_GET['email'])) {
    $params['email'] = (string) $_GET['email'];
}

$url = BASE_URL . '/client/quenmatkhau.php' . ($params ? '?' . http_build_query($params) : '');
redirect($url);
