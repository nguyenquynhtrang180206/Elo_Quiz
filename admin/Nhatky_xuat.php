<?php
// Xuất Nhật ký quản trị ra Excel hoặc PDF. Đặt tại: elo_quiz/admin/nhatky_xuat.php
// Được gọi (POST, có CSRF) từ hộp thoại "Xuất" ở admin/nhatky.php. Mỗi lần gọi trả MỘT file; khi người dùng chọn
// định dạng "Cả hai", trình duyệt (nhatky.js) gọi lần lượt hai lần (Excel rồi PDF), không nén zip.
//
// Tham số:
//   pham_vi : loc = đang lọc (q, ad, hd, tt, tu, den gửi kèm từ trang, dựng bằng NhatKy::dieuKien giống hệt trang danh sách),
//             tatca = toàn bộ nhật ký (bỏ qua bộ lọc).
//   truong[]: các trường (cột) cần xuất: tg, hd, dt, mota, tt (xem $COT). Thứ tự cột trong file luôn theo $COT.
//   dinhdang: excel | pdf.
//   sx      : asc = cũ nhất trước; mặc định mới nhất trước (giống thứ tự đang xem trên trang).
//   buoc=xemtruoc: không xuất file mà trả JSON để dựng khung "Xem trước" (tổng số, số thành công/thất bại, vài dòng đầu).
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/XlsxWriter.php';
require_once __DIR__ . '/../core/PdfWriter.php';
Auth::requireAdmin(BASE_URL . '/admin/login.php');   // A-04

function loi_xuat(string $thongBao, int $ma = 400): never
{
    http_response_code($ma);
    header('Content-Type: text/plain; charset=utf-8');
    exit($thongBao);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(BASE_URL . '/admin/nhatky.php');
}
csrf_check();
@set_time_limit(120);

$TOI_DA_XLSX = 20000;   // vượt quá thì yêu cầu lọc bớt (tránh làm treo máy chủ)
$TOI_DA_PDF  = 3000;
$SO_DONG_XEM = 50;      // số dòng đầu đưa vào khung xem trước

// Các trường được phép xuất: khóa => [tiêu đề cột, tỉ lệ độ rộng cột trong PDF]
$COT = [
    'tg'    => ['Thời gian',  2.3],
    'hd'    => ['Hành động',  2.8],
    'dt'    => ['Đối tượng',  2.6],
    'mota'  => ['Mô tả',      6.0],
    'tt'    => ['Trạng thái', 1.9],
];

$pdo = Database::pdo();

// ---- Tham số từ hộp thoại ----
$phamVi = (string) ($_POST['pham_vi'] ?? '');
if (!in_array($phamVi, ['loc', 'tatca'], true)) {
    loi_xuat('Hãy chọn phạm vi cần xuất.');
}
$buoc = (string) ($_POST['buoc'] ?? '');
$dinhDang = (string) ($_POST['dinhdang'] ?? '');
if ($buoc === 'xemtruoc') { $dinhDang = 'excel'; }   // xem trước không cần định dạng
if (!in_array($dinhDang, ['excel', 'pdf'], true)) {
    loi_xuat('Hãy chọn định dạng Excel hoặc PDF.');
}
// array_intersect giữ thứ tự của $COT nên thứ tự cột trong file luôn cố định, không phụ thuộc thứ tự gửi lên
$truong = array_values(array_intersect(array_keys($COT),
    is_array($_POST['truong'] ?? null) ? array_map('strval', $_POST['truong']) : []));
if (!$truong && $buoc !== 'xemtruoc') {
    loi_xuat('Hãy chọn ít nhất một trường cần xuất.');
}
$sx = (($_POST['sx'] ?? '') === 'asc') ? 'ASC' : 'DESC';

// ---- Bộ lọc ----
if ($phamVi === 'loc') {
    $kq = NhatKy::dieuKien($_POST);
    if ($kq['loi'] !== '') {
        loi_xuat($kq['loi']);
    }
    $where = $kq['where'];
    $tham  = $kq['tham'];
    $loc   = $kq['loc'];
} else {
    $where = '';
    $tham  = [];
    $loc   = [];
}

// Mô tả bộ lọc bằng chữ (ghi vào đầu file và vào nhật ký)
$ngayVN = static function (string $s): string {
    $d = DateTime::createFromFormat('Y-m-d', $s);
    return $d ? $d->format('d/m/Y') : $s;
};
if ($phamVi === 'tatca') {
    $moTaPhamVi = 'Toàn bộ nhật ký (không lọc)';
} else {
    $phan = [];
    if (isset($loc['q']))   { $phan[] = 'từ khóa "' . $loc['q'] . '"'; }
    if (isset($loc['hd']))  { $phan[] = 'hành động "' . $loc['hd'] . '"'; }
    if (isset($loc['tt']))  { $phan[] = 'trạng thái ' . $loc['tt']; }
    if (isset($loc['tu']))  { $phan[] = 'từ ' . $ngayVN($loc['tu']); }
    if (isset($loc['den'])) { $phan[] = 'đến ' . $ngayVN($loc['den']); }
    $moTaPhamVi = $phan ? 'Đang lọc: ' . implode(' · ', $phan) : 'Đang lọc: không có bộ lọc nào (toàn bộ nhật ký)';
}

// ---- Số liệu tổng quan của phạm vi ----
$st = $pdo->prepare(
    "SELECT COUNT(*) AS tong,
            COALESCE(SUM(n.TrangThai = 'Thất bại'), 0) AS thatbai
     FROM NHATKYQUANTRI n
     JOIN ADMIN a ON a.MaAdmin = n.MaAdmin
     $where"
);
$st->execute($tham);
$tk = array_map('intval', $st->fetch());
$tong = $tk['tong'];

// Lấy các dòng (đã định dạng sẵn để dùng chung cho xem trước, Excel và PDF)
$layDong = static function (int $gioiHan) use ($pdo, $where, $tham, $sx): array {
    $st = $pdo->prepare(
        "SELECT n.ThoiGian, n.HanhDong, n.DoiTuong, n.MoTa, n.TrangThai
         FROM NHATKYQUANTRI n
         JOIN ADMIN a ON a.MaAdmin = n.MaAdmin
         $where
         ORDER BY n.ThoiGian $sx, n.MaNhatKy $sx
         LIMIT " . (int) $gioiHan
    );
    $st->execute($tham);
    $ra = [];
    foreach ($st as $r) {
        $ra[] = [
            'tg'    => date('d/m/Y H:i:s', strtotime($r['ThoiGian'])),
            'hd'    => (string) $r['HanhDong'],
            'dt'    => (string) ($r['DoiTuong'] ?? ''),
            'mota'  => (string) ($r['MoTa'] ?? ''),
            'tt'    => (string) $r['TrangThai'],
        ];
    }
    return $ra;
};

// =====================================================================================================
// XEM TRƯỚC (JSON)
// =====================================================================================================
if ($buoc === 'xemtruoc') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    exit(json_encode([
        'tong'      => $tong,
        'thanhCong' => $tong - $tk['thatbai'],
        'thatBai'   => $tk['thatbai'],
        'phamVi'    => $moTaPhamVi,
        'sapXep'    => $sx === 'ASC' ? 'cũ nhất trước' : 'mới nhất trước',
        'soDongXem' => $SO_DONG_XEM,
        'toiDaXlsx' => $TOI_DA_XLSX,
        'toiDaPdf'  => $TOI_DA_PDF,
        'dong'      => $tong > 0 ? $layDong($SO_DONG_XEM) : [],
    ], JSON_UNESCAPED_UNICODE));
}

// =====================================================================================================
// XUẤT FILE
// =====================================================================================================
if ($tong === 0) {
    loi_xuat('Không có bản ghi nhật ký nào trong phạm vi đã chọn.');
}
$toiDa = $dinhDang === 'pdf' ? $TOI_DA_PDF : $TOI_DA_XLSX;
if ($tong > $toiDa) {
    loi_xuat('Phạm vi có ' . number_format($tong, 0, ',', '.') . ' bản ghi, vượt giới hạn ' . number_format($toiDa, 0, ',', '.')
        . ' bản ghi của file ' . ($dinhDang === 'pdf' ? 'PDF' : 'Excel') . '. Hãy lọc bớt (theo ngày, hành động...) rồi xuất lại.');
}

$dong     = $layDong($toiDa);
$tenFile  = 'nhatky_quantri_' . date('Ymd_His');
$bayGio   = date('d/m/Y H:i');
$tieuDeCot = array_map(static fn (string $k): string => $COT[$k][0], $truong);
$soThanhCong = $tong - $tk['thatbai'];
$tomTat   = number_format($tong, 0, ',', '.') . ' bản ghi · thành công ' . number_format($soThanhCong, 0, ',', '.')
          . ' · thất bại ' . number_format($tk['thatbai'], 0, ',', '.');

// Ghi vết việc xuất (chỉ đọc dữ liệu nên không ảnh hưởng nội dung file vừa lấy). Gọi trước khi gửi file vì tai() kết thúc chương trình.
NhatKy::ghi('Xuất nhật ký quản trị', null,
    ($dinhDang === 'pdf' ? 'PDF' : 'Excel') . ' · ' . $tong . ' bản ghi · Cột: ' . implode(', ', $tieuDeCot) . ' · ' . $moTaPhamVi . '.');

if ($dinhDang === 'excel') {
    $hang = [
        ['kieu' => 2,  'o' => ['Nhật ký quản trị']],
        ['kieu' => 16, 'o' => ['Xuất lúc ' . $bayGio . ' · ' . $tomTat]],
        ['kieu' => 16, 'o' => [$moTaPhamVi . ' · Xếp ' . ($sx === 'ASC' ? 'cũ nhất trước' : 'mới nhất trước')]],
        ['kieu' => 6,  'o' => $tieuDeCot],
    ];
    foreach ($dong as $r) {
        $o = [];
        foreach ($truong as $k) {
            // Trạng thái tô màu: 8 = nền đỏ nhạt, 10 = nền xanh nhạt (cùng bộ kiểu ô với các file xuất khác)
            $o[] = $k === 'tt' ? ['g' => $r[$k], 's' => $r[$k] === 'Thất bại' ? 8 : 10] : $r[$k];
        }
        $hang[] = ['kieu' => 7, 'o' => $o];
    }
    XlsxWriter::taiNhieuSheet($tenFile . '.xlsx', [['ten' => 'Nhật ký quản trị', 'rongToiThieu' => 12, 'dong' => $hang]]);
}

// ---- PDF (A4 ngang, bảng tự xuống dòng và lặp dòng tiêu đề khi sang trang) ----
$pdf = new PdfWriter(__DIR__ . '/../assets/fonts');
$pdf->khoTrang(true);
$pdf->tieuDe('Nhật ký quản trị', 18);
$pdf->doan('Xuất lúc ' . $bayGio . ' · ' . $tomTat, 9, false, '#5b6b8c');
$pdf->doan($moTaPhamVi . ' · Xếp ' . ($sx === 'ASC' ? 'cũ nhất trước' : 'mới nhất trước'), 9, false, '#5b6b8c');
$pdf->khoang(6);

$viTriTT = array_search('tt', $truong, true);
$dongPdf = [];
$mauO    = [];
foreach ($dong as $i => $r) {
    $o = [];
    foreach ($truong as $k) { $o[] = $r[$k]; }
    $dongPdf[] = $o;
    if ($viTriTT !== false) {
        $mauO[$i] = [$viTriTT => $r['tt'] === 'Thất bại' ? '#fde3e6' : '#dcf5ea'];   // tô nền ô trạng thái
    }
}
$tyLe = array_map(static fn (string $k): float => $COT[$k][1], $truong);
$pdf->bang($tieuDeCot, $dongPdf, $tyLe, [], $mauO, $viTriTT !== false ? [$viTriTT] : []);
$pdf->tai($tenFile . '.pdf');