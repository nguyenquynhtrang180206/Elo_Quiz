<?php
// API Xử lý thông báo người dùng (AJAX)
// Đặt tại: client/thongbao_api.php
require_once __DIR__ . '/../core/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$maND = Auth::clientId();
if ($maND === null) {
    echo json_encode(['ok' => false, 'yeuCauLogin' => true, 'msg' => 'Chưa đăng nhập']);
    exit;
}

$pdo = Database::pdo();
$action = (string) ($_GET['action'] ?? $_POST['action'] ?? 'lay_danh_sach');

if ($action === 'dem') {
    // Chỉ đếm số thông báo chưa đọc (nhẹ nhất để polling)
    $soChuaDoc = ThongBaoHelper::laySoChuaDoc($pdo, $maND);
    echo json_encode(['ok' => true, 'soChuaDoc' => $soChuaDoc]);
    exit;
}

if ($action === 'lay_danh_sach') {
    $soChuaDoc = ThongBaoHelper::laySoChuaDoc($pdo, $maND);
    $limit = min(20, max(1, (int) ($_GET['limit'] ?? 8)));
    $offset = max(0, (int) ($_GET['offset'] ?? 0));
    $rawList = ThongBaoHelper::layDanhSach($pdo, $maND, $limit, $offset);

    $danhSach = [];
    foreach ($rawList as $r) {
        $avatarUrl = !empty($r['AnhNguoiGui']) ? BASE_URL . '/' . ltrim($r['AnhNguoiGui'], '/') : '';
        $letter = mb_strtoupper(mb_substr($r['TenNguoiGui'] ?? 'U', 0, 1));
        
        $danhSach[] = [
            'ma_tb'         => (int) $r['MaTB'],
            'ma_bai'        => (int) $r['MaBaiDang'],
            'loai'          => $r['Loai'],
            'tieu_de'       => $r['TieuDe'],
            'noi_dung'      => $r['NoiDung'] ?? '',
            'lien_ket'      => $r['LienKet'],
            'da_doc'        => (int) $r['DaDoc'] === 1,
            'ngay_tao'      => $r['NgayTao'],
            'thoi_gian'     => ThongBaoHelper::tinhThoiGian($r['NgayTao']),
            'ten_nguoi_gui' => $r['TenNguoiGui'] ?? 'Thành viên',
            'anh_nguoi_gui' => $avatarUrl,
            'chu_cai'       => $letter,
        ];
    }

    echo json_encode([
        'ok'        => true,
        'soChuaDoc' => $soChuaDoc,
        'danhSach'  => $danhSach,
    ]);
    exit;
}

if ($action === 'doc_mot') {
    $maTB = (int) ($_POST['ma_tb'] ?? 0);
    if ($maTB > 0) {
        ThongBaoHelper::danhDauDaDoc($pdo, $maTB, $maND);
    }
    $soChuaDoc = ThongBaoHelper::laySoChuaDoc($pdo, $maND);
    echo json_encode(['ok' => true, 'soChuaDoc' => $soChuaDoc]);
    exit;
}

if ($action === 'doc_het') {
    ThongBaoHelper::danhDauTatCaDaDoc($pdo, $maND);
    echo json_encode(['ok' => true, 'soChuaDoc' => 0]);
    exit;
}

if ($action === 'xoa') {
    $maTB = (int) ($_POST['ma_tb'] ?? 0);
    if ($maTB > 0) {
        ThongBaoHelper::xoaThongBao($pdo, $maTB, $maND);
    }
    $soChuaDoc = ThongBaoHelper::laySoChuaDoc($pdo, $maND);
    echo json_encode(['ok' => true, 'soChuaDoc' => $soChuaDoc]);
    exit;
}

echo json_encode(['ok' => false, 'msg' => 'Hành động không hợp lệ']);
exit;

