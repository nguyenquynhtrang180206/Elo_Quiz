<?php
// Trả JSON số liệu thống kê theo từng mốc thời gian (chỉ đọc) cho hộp thoại xem trước khi xuất báo cáo.
// Gọi: thongke_du_lieu.php?ngay[]=7&ngay[]=30  (mốc hợp lệ: 7, 30, 90, 365)
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/ThongKe.php';
Auth::requireAdmin(BASE_URL . '/admin/login.php');   // A-04

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
    $kq[$n] = ['nhan' => ThongKe::nhanKhoang($n), 'tq' => ThongKe::tongQuan($pdo, $n), 'mon' => ThongKe::theoMon($pdo, $n)];
}
echo json_encode($kq, JSON_UNESCAPED_UNICODE);