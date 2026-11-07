<?php
// API xem nhanh một câu hỏi (AJAX, ngăn "Xem nhanh" ở admin/cauhoi.php). Chỉ đọc, không ghi gì.
// Đặt tại: elo_quiz/admin/cauhoi_api.php     Gọi: cauhoi_api.php?id=<MaCauHoi>
require_once __DIR__ . '/../core/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// A-04: chỉ quản trị viên đã đăng nhập
if (Auth::adminId() === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'msg' => 'Chưa đăng nhập'], JSON_UNESCAPED_UNICODE);
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'Thiếu mã câu hỏi'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = Database::pdo();

$st = $pdo->prepare(
    'SELECT c.MaCauHoi, c.NoiDung, c.GiaiThich, c.DoKho, c.TrangThai, c.MaMon, m.TenMon, k.TenLoaiKT,
            (SELECT COUNT(DISTINCT t.MaTran) FROM CAUHOITRANDAU t WHERE t.MaCauHoi = c.MaCauHoi) AS so_tran
     FROM CAUHOI c
     JOIN MONHOC m ON m.MaMon = c.MaMon
     LEFT JOIN LOAIKYTHI k ON k.MaLoaiKT = c.MaLoaiKT
     WHERE c.MaCauHoi = ?'
);
$st->execute([$id]);
$c = $st->fetch();
if (!$c) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'msg' => 'Không tìm thấy câu hỏi'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Đáp án theo thứ tự lưu (A, B, C, D); thứ tự xáo trộn trong trận không ảnh hưởng màn này
$st = $pdo->prepare('SELECT NoiDungDapAn, LaDapAnDung FROM DAPAN WHERE MaCauHoi = ? ORDER BY MaDapAn');
$st->execute([$id]);
$dapAn = [];
foreach ($st->fetchAll() as $d) {
    $dapAn[] = [
        'noidung' => (string) $d['NoiDungDapAn'],
        'dung'    => (int) $d['LaDapAnDung'] === 1,
    ];
}

echo json_encode([
    'ok'        => true,
    'id'        => (int) $c['MaCauHoi'],
    'noidung'   => (string) $c['NoiDung'],
    'giaithich' => $c['GiaiThich'] === null ? '' : (string) $c['GiaiThich'],
    'dokho'     => (string) $c['DoKho'],
    'trangthai' => (string) $c['TrangThai'],
    'mamon'     => (int) $c['MaMon'],
    'tenmon'    => (string) $c['TenMon'],
    'loaikt'    => $c['TenLoaiKT'] === null ? 'Cả hai kỳ' : (string) $c['TenLoaiKT'],
    'so_tran'   => (int) $c['so_tran'],   // > 0: câu đã dùng trong trận, chỉ xem và ẩn/hiện (A-21)
    'dapan'     => $dapAn,
], JSON_UNESCAPED_UNICODE);