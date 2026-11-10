<?php
// Trả JSON số liệu Thống kê khảo sát (chỉ đọc) cho phần XEM TRƯỚC của hộp thoại "Xuất" ở admin/khaosat.php.
// Gọi: khaosat_du_lieu.php?tu=2026-11-01&den=2026-11-30
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/KhaoSatThongKe.php';
Auth::requireAdmin(BASE_URL . '/admin/login.php');   // A-04

[$tu, $den] = KhaoSatThongKe::khoang($_GET);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode(KhaoSatThongKe::tinh(Database::pdo(), $tu, $den), JSON_UNESCAPED_UNICODE);