<?php
// API thông báo của admin (AJAX, chuông ở trang Tổng quan). Đặt tại: admin/thongbao_api.php
require_once __DIR__ . '/../core/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

$maAdmin = Auth::adminId();
if ($maAdmin === null) {
    echo json_encode(['ok' => false, 'msg' => 'Chưa đăng nhập']);
    exit;
}
$pdo    = Database::pdo();
$action = (string) ($_GET['action'] ?? $_POST['action'] ?? 'lay_danh_sach');

// Hành động ghi dữ liệu: chỉ nhận POST và phải đúng mã CSRF
if (in_array($action, ['doc_mot', 'doc_het'], true)) {
    $gui = $_POST['csrf'] ?? '';
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !is_string($gui) || !hash_equals($_SESSION['csrf'] ?? '', $gui)) {
        echo json_encode(['ok' => false, 'msg' => 'Yêu cầu không hợp lệ']);
        exit;
    }
}

if ($action === 'dem') {
    echo json_encode(['ok' => true, 'soChuaDoc' => ThongBaoAdmin::laySoChuaDoc($pdo, $maAdmin)]);
    exit;
}

if ($action === 'lay_danh_sach') {
    // Mặc định chỉ 5 thông báo gần nhất; all=1 (nút "Tất cả thông báo") mới lấy toàn bộ (tối đa 200)
    $tatCa = (string) ($_GET['all'] ?? '') === '1';
    $rows  = ThongBaoAdmin::layDanhSach($pdo, $maAdmin, $tatCa ? 200 : 5, 0);
    $ds = [];
    foreach ($rows as $r) {
        $tu = preg_split('/\s+/', trim((string) $r['ten']));
        $chuCai = mb_strtoupper(mb_substr((string) end($tu), 0, 1)) ?: 'U';
        $nd = preg_replace('/^(.{80}).+$/su', '$1…', trim((string) $r['noidung']));
        $ds[] = [
            'loai'      => (int) $r['loai'],
            'ma'        => (int) $r['ma'],
            'tieu_de'   => 'đã đề xuất môn học:',
            'noi_dung'  => $nd,
            'ten'       => (string) $r['ten'],
            'chu_cai'   => $chuCai,
            'lien_ket'  => ThongBaoAdmin::lienKet((int) $r['ma_bai']),
            'da_doc'    => (int) $r['da_doc'] === 1,
            'thoi_gian' => ThongBaoHelper::tinhThoiGian((string) $r['tg']),
        ];
    }
    echo json_encode([
        'ok'        => true,
        'soChuaDoc' => ThongBaoAdmin::laySoChuaDoc($pdo, $maAdmin),
        'tong'      => ThongBaoAdmin::demTatCa($pdo),
        'danhSach'  => $ds,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'doc_mot') {
    ThongBaoAdmin::danhDauDaDoc($pdo, $maAdmin, (int) ($_POST['loai'] ?? 0), (int) ($_POST['ma'] ?? 0));
    echo json_encode(['ok' => true, 'soChuaDoc' => ThongBaoAdmin::laySoChuaDoc($pdo, $maAdmin)]);
    exit;
}

if ($action === 'doc_het') {
    ThongBaoAdmin::danhDauTatCaDaDoc($pdo, $maAdmin);
    echo json_encode(['ok' => true, 'soChuaDoc' => 0]);
    exit;
}

echo json_encode(['ok' => false, 'msg' => 'Hành động không hợp lệ']);