<?php
// Trả về số hoạt động theo từng ngày (JSON) cho biểu đồ ở trang Nhật ký quản trị. Chỉ đọc.
// Gọi: nhatky_nhip.php?den=YYYY-MM-DD&so=7|30   (den bỏ trống hoặc sai thì lấy hôm nay; so khác 30 thì lấy 7)
// Đặt tại: elo_quiz/admin/nhatky_nhip.php
require_once __DIR__ . '/../core/bootstrap.php';
Auth::requireAdmin(BASE_URL . '/admin/dangnhap.php');   // A-04

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$so  = ((int) ($_GET['so'] ?? 7)) >= 30 ? 30 : 7;
$den = NhatKy::chuanHoaNgay(isset($_GET['den']) ? (string) $_GET['den'] : null);
echo json_encode(
    ['den' => $den, 'so' => $so, 'nhip' => NhatKy::nhipTheoNgay(Database::pdo(), $so, $den)],
    JSON_UNESCAPED_UNICODE
);