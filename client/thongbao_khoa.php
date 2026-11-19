<?php
// Người dùng bấm vào thông báo "tài khoản bị khóa": đánh dấu đã đọc rồi đăng xuất ngay để không còn thao tác nào khác.
// Đặt tại: client/thongbao_khoa.php   (LienKet của thông báo khoa_tk trỏ tới file này)
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

// Thông báo đã được mở xem nên đánh dấu đã đọc (kể cả khi tài khoản đã được mở khóa lại)
try {
    $pdo->prepare("UPDATE THONGBAO SET DaDoc = 1 WHERE MaND = ? AND Loai = 'khoa_tk'")->execute([$maND]);
} catch (Throwable $e) {
    // không chặn việc đăng xuất nếu đánh dấu đã đọc thất bại
}

if ($trangThai === 'Bị khóa') {
    // Giống client/dangxuat.php: chỉ xóa khóa của sinh viên, không đụng tới phiên Admin nếu cùng trình duyệt
    unset($_SESSION['nd_id'], $_SESSION['nd_la_admin']);
    session_regenerate_id(true);
    redirect(BASE_URL . '/client/dangnhap.php?khoa=1');
}

// Đã được mở khóa trước khi bấm vào thì không đăng xuất
redirect(BASE_URL . '/client/index.php');