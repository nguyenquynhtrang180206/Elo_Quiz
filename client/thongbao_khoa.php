<?php
// Xử lý thông báo khóa tài khoản: đánh dấu đã đọc và đăng xuất nếu đang bị khóa
require_once __DIR__ . '/../core/bootstrap.php';

$maND = Auth::clientId();
if ($maND === null) {
    redirect(BASE_URL . '/client/dangnhap.php');
}
// Admin đang dùng khu sinh viên không bị đăng xuất khỏi phiên của mình
if (Auth::laAdminSinhVien()) {
    redirect(BASE_URL . '/client/index.php');
}

$pdo = Database::pdo();
$st  = $pdo->prepare('SELECT TrangThai FROM NGUOIDUNG WHERE MaND = ?');
$st->execute([$maND]);
$trangThai = $st->fetchColumn();

// Đánh dấu đã đọc kể cả khi tài khoản đã được mở khóa lại
try {
    $pdo->prepare("UPDATE THONGBAO SET DaDoc = 1 WHERE MaND = ? AND Loai = 'khoa_tk'")->execute([$maND]);
} catch (Throwable $e) {
    // Không chặn việc đăng xuất nếu đánh dấu đã đọc thất bại
}

if ($trangThai === 'Bị khóa') {
    // Chỉ xóa phiên sinh viên, giữ nguyên phiên quản trị nếu đang mở cùng trình duyệt
    unset($_SESSION['nd_id'], $_SESSION['nd_la_admin']);
    session_regenerate_id(true);
    redirect(BASE_URL . '/client/dangnhap.php?khoa=1');
}

// Đã được mở khóa trước khi bấm vào thì không đăng xuất
redirect(BASE_URL . '/client/index.php');