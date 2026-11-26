<?php
// Chuyển quản trị viên sang giao diện sinh viên bằng tài khoản liên kết.
require_once __DIR__ . '/../core/bootstrap.php';
$maAdmin = Auth::requireAdmin(BASE_URL . '/admin/dangnhap.php');

try {
    Auth::vaoKhuSinhVien(Database::pdo(), $maAdmin);
} catch (PDOException $ex) {
    // 42S22: thiếu cột ADMIN.MaND.
    if ($ex->getCode() === '42S22') {
        http_response_code(500);
        exit('Thiếu cột ADMIN.MaND. Hãy chạy file them_lienket_admin_sinhvien.sql trong phpMyAdmin rồi thử lại.');
    }
    throw $ex;
}
redirect(BASE_URL . '/client/index.php');