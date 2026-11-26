<?php
// API trả về JSON số liệu thống kê theo mốc thời gian cho hộp thoại xem trước.
require_once __DIR__ . '/../core/bootstrap.php';
Auth::requireAdmin(BASE_URL . '/admin/dangnhap.php');

$ds = ThongKe::locKhoang($_GET['ngay'] ?? []);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!$ds) {
    http_response_code(400);
    exit(json_encode(['loi' => 'Chưa chọn mốc thời gian hợp lệ.'], JSON_UNESCAPED_UNICODE));
}
$pdo = Database::pdo();
$kq = [];
foreach ($ds as $n) {
    $kq[$n] = ['nhan' => ThongKe::nhanKhoang($n), 'khoang' => ThongKe::khoangNgay($n), 'tq' => ThongKe::tongQuan($pdo, $n), 'mon' => ThongKe::theoMon($pdo, $n)];
}
echo json_encode($kq, JSON_UNESCAPED_UNICODE);