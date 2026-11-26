<?php
// API trả về số hoạt động quản trị theo từng ngày (JSON).
require_once __DIR__ . '/../core/bootstrap.php';
Auth::requireAdmin(BASE_URL . '/admin/dangnhap.php');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$so  = ((int) ($_GET['so'] ?? 7)) >= 30 ? 30 : 7;
$den = NhatKy::chuanHoaNgay(isset($_GET['den']) ? (string) $_GET['den'] : null);
echo json_encode(
    ['den' => $den, 'so' => $so, 'nhip' => NhatKy::nhipTheoNgay(Database::pdo(), $so, $den)],
    JSON_UNESCAPED_UNICODE
);