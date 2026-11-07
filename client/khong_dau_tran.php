<?php
// Admin về trang sinh viên: vào thẳng, không đăng nhập lại. Đặt tại: elo_quiz/admin/vesinhvien.php
require_once __DIR__ . '/../core/bootstrap.php';
$maAdmin = Auth::requireAdmin(BASE_URL . '/admin/login.php');   // A-04

try {
    Auth::vaoKhuSinhVien(Database::pdo(), $maAdmin);
} catch (PDOException $ex) {
    // 42S22 = thiếu cột: chưa chạy file them_lienket_admin_sinhvien.sql
    if ($ex->getCode() === '42S22') {
        http_response_code(500);
        exit('Thiếu cột ADMIN.MaND. Hãy chạy file them_lienket_admin_sinhvien.sql trong phpMyAdmin rồi thử lại.');
    }
    throw $ex;
}
redirect(BASE_URL . '/client/index.php');