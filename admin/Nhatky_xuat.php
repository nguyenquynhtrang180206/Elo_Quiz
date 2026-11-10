<?php
// Xuất Nhật ký quản trị ra Excel hoặc PDF. Đặt tại: elo_quiz/admin/nhatky_xuat.php
// Được gọi (POST, có CSRF) từ hộp thoại "Xuất" ở admin/nhatky.php. Mỗi lần gọi trả MỘT file; khi người dùng chọn
// định dạng "Cả hai", trình duyệt (nhatky.js) gọi lần lượt hai lần (Excel rồi PDF), không nén zip.
//
// Tham số:
//   pham_vi : loc = đang lọc (q, ad, hd, tt, tu, den gửi kèm từ trang, dựng bằng NhatKy::dieuKien giống hệt trang danh sách),
//             tatca = toàn bộ nhật ký (bỏ qua bộ lọc).
//   truong[]: các trường (cột) cần xuất: tg, hd, dt, mota, tt (xem $COT). Thứ tự cột trong file luôn theo $COT.
//             Bỏ trống hết mà có bieudo=1 thì file CHỈ có biểu đồ (không có bảng dữ liệu).
//   dinhdang: excel | pdf.
//   bd_ngay : khoảng ngày của biểu đồ: 7 | 30 (7/30 ngày gần nhất tính đến hôm nay, vẫn trong phạm vi đã lọc) | tatca (toàn bộ phạm vi).
//   bieudo  : 1 = kèm biểu đồ (thao tác theo ngày, cơ cấu hành động). Ảnh JPEG do trình duyệt vẽ từ số liệu xem trước
//             và gửi kèm ở anh_nhip, anh_cocau (cùng cách với admin/thongke_xuat.php).
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

// Ảnh biểu đồ (data URL JPEG): chỉ nhận JPEG hợp lệ, tối đa 5 MB mỗi ảnh
function anh_bieu_do(string $khoa): ?array
{
    $v   = (string) ($_POST[$khoa] ?? '');
    $dau = 'data:image/jpeg;base64,';
    if (strncmp($v, $dau, strlen($dau)) !== 0) { return null; }
    $b = base64_decode(substr($v, strlen($dau)), true);
    if ($b === false || strlen($b) > 5 * 1024 * 1024) { return null; }
    $kt = @getimagesizefromstring($b);
    if (!$kt || $kt[2] !== IMAGETYPE_JPEG || $kt[0] < 1 || $kt[1] < 1) { return null; }
    return ['jpeg' => $b, 'rong' => $kt[0], 'cao' => $kt[1]];
}

// Ảnh dùng trong Excel, cao $cao pixel (giữ tỷ lệ); $x là vị trí pixel tính từ mép trái sheet
function anh_theo_cao(?array $a, int $cao, int $x = 0): ?array
{
    if (!$a) { return null; }
    return ['jpeg' => $a['jpeg'], 'rong' => (int) round($a['rong'] * $cao / $a['cao']), 'cao' => $cao, 'x' => $x];
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
$coBieuDo = (string) ($_POST['bieudo'] ?? '') === '1';
if (!$truong && !$coBieuDo && $buoc !== 'xemtruoc') {
    loi_xuat('Hãy chọn ít nhất một trường hoặc tích "Kèm biểu đồ".');
}
$sx = (($_POST['sx'] ?? '') === 'asc') ? 'ASC' : 'DESC';
$bdNgay = (string) ($_POST['bd_ngay'] ?? '7');
if (!in_array($bdNgay, ['7', '30', 'tatca'], true)) { $bdNgay = '7'; }

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
    if (isset($loc['ad'])) {
        $stAd = $pdo->prepare('SELECT HoTen FROM ADMIN WHERE MaAdmin = ?');
        $stAd->execute([$loc['ad']]);
        $phan[] = 'quản trị viên ' . ((string) ($stAd->fetchColumn() ?: ('#' . $loc['ad'])));
    }
    if (isset($loc['nhom'])) { $phan[] = 'nhóm "' . NhatKy::NHOM[$loc['nhom']][0] . '"'; }
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

// Số liệu cho hai biểu đồ, tính trên ĐÚNG phạm vi đang xuất (cùng $where với bảng dữ liệu)
$soLieuBieuDo = static function () use ($pdo, $where, $tham, $bdNgay): array {
    // 7 / 30 ngày gần nhất: thêm điều kiện thời gian vào bộ lọc; tatca: giữ nguyên phạm vi
    $w = $where; $th = $tham; $soNgay = 0; $tuNgay = '';
    if ($bdNgay !== 'tatca') {
        $soNgay = (int) $bdNgay;
        $tuNgay = date('Y-m-d', strtotime('-' . ($soNgay - 1) . ' days'));
        $w  = ($where === '' ? 'WHERE' : $where . ' AND') . ' n.ThoiGian >= ?';
        $th[] = $tuNgay . ' 00:00:00';
    }
    // 1) Số thao tác theo từng ngày (ngày không có thao tác được điền 0 để các cột liền nhau)
    $st = $pdo->prepare(
        "SELECT DATE(n.ThoiGian) AS ngay, COUNT(*) AS tong,
                COALESCE(SUM(n.TrangThai = 'Thất bại'), 0) AS loi
         FROM NHATKYQUANTRI n
         JOIN ADMIN a ON a.MaAdmin = n.MaAdmin
         $w
         GROUP BY DATE(n.ThoiGian)
         ORDER BY ngay"
    );
    $st->execute($th);
    $co = [];
    foreach ($st->fetchAll() as $r) { $co[$r['ngay']] = ['tong' => (int) $r['tong'], 'loi' => (int) $r['loi']]; }
    $theoNgay = [];
    if ($soNgay > 0 || $co) {
        $ngay = array_keys($co);
        $d    = new DateTime($soNgay > 0 ? $tuNgay : reset($ngay));
        $cuoi = new DateTime($soNgay > 0 ? date('Y-m-d') : end($ngay));
        for ($i = 0; $i < 400 && $d <= $cuoi; $i++) {
            $k = $d->format('Y-m-d');
            $theoNgay[] = ['ngay' => $k, 'tong' => $co[$k]['tong'] ?? 0, 'loi' => $co[$k]['loi'] ?? 0];
            $d->modify('+1 day');
        }
    }

    // 2) Cơ cấu hành động theo nhóm (cùng cách chia nhóm như chip lọc ở trang nhật ký)
    $st = $pdo->prepare(
        "SELECT n.HanhDong, COUNT(*) AS so
         FROM NHATKYQUANTRI n
         JOIN ADMIN a ON a.MaAdmin = n.MaAdmin
         $w
         GROUP BY n.HanhDong"
    );
    $st->execute($th);
    $dem = [];
    foreach ($st->fetchAll() as $r) {
        $khoa = NhatKy::nhomCuaHanhDong((string) $r['HanhDong']) ?? 'khac';
        $dem[$khoa] = ($dem[$khoa] ?? 0) + (int) $r['so'];
    }
    $mau = ['xoa' => '#d6293e', 'mo' => '#22a06b', 'khoa' => '#e3a93b', 'sua' => '#2f6bff', 'xuat' => '#7c3aed', 'khac' => '#8b95b0'];
    $coCau = [];
    foreach ($dem as $khoa => $so) {
        $coCau[] = ['nhan' => $khoa === 'khac' ? 'Khác' : NhatKy::NHOM[$khoa][0], 'so' => $so, 'mau' => $mau[$khoa]];
    }
    usort($coCau, static fn (array $a, array $b): int => $b['so'] <=> $a['so']);
    return ['theoNgay' => $theoNgay, 'coCau' => $coCau];
};

// =====================================================================================================
// XEM TRƯỚC (JSON)
// =====================================================================================================
if ($buoc === 'xemtruoc') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $bd = $tong > 0 ? $soLieuBieuDo() : ['theoNgay' => [], 'coCau' => []];
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
        'theoNgay'  => $bd['theoNgay'],
        'coCau'     => $bd['coCau'],
    ], JSON_UNESCAPED_UNICODE));
}

// =====================================================================================================
// XUẤT FILE
// =====================================================================================================
if ($tong === 0) {
    loi_xuat('Không có bản ghi nhật ký nào trong phạm vi đã chọn.');
}
$toiDa = $dinhDang === 'pdf' ? $TOI_DA_PDF : $TOI_DA_XLSX;
if ($truong && $tong > $toiDa) {   // giới hạn số dòng chỉ áp dụng khi có bảng dữ liệu
    loi_xuat('Phạm vi có ' . number_format($tong, 0, ',', '.') . ' bản ghi, vượt giới hạn ' . number_format($toiDa, 0, ',', '.')
        . ' bản ghi của file ' . ($dinhDang === 'pdf' ? 'PDF' : 'Excel') . '. Hãy lọc bớt (theo ngày, hành động...) rồi xuất lại.');
}

$dong     = $truong ? $layDong($toiDa) : [];
$tenFile  = 'nhatky_quantri_' . date('Ymd_His');
$bayGio   = date('d/m/Y H:i');
$tieuDeCot = array_map(static fn (string $k): string => $COT[$k][0], $truong);
$soThanhCong = $tong - $tk['thatbai'];
$tomTat   = number_format($tong, 0, ',', '.') . ' bản ghi · thành công ' . number_format($soThanhCong, 0, ',', '.')
          . ' · thất bại ' . number_format($tk['thatbai'], 0, ',', '.');

// Ghi vết việc xuất (chỉ đọc dữ liệu nên không ảnh hưởng nội dung file vừa lấy). Gọi trước khi gửi file vì tai() kết thúc chương trình.
NhatKy::ghi('Xuất nhật ký quản trị', null,
    ($dinhDang === 'pdf' ? 'PDF' : 'Excel') . ' · ' . $tong . ' bản ghi · ' . ($truong ? 'Cột: ' . implode(', ', $tieuDeCot) : 'Chỉ biểu đồ') . ' · ' . $moTaPhamVi . '.');

// Biểu đồ (chỉ khi người dùng tích "Kèm biểu đồ" và trình duyệt gửi ảnh lên)
$anhNhip  = $coBieuDo ? anh_bieu_do('anh_nhip')  : null;
$anhCoCau = $coBieuDo ? anh_bieu_do('anh_cocau') : null;

if (!$truong && !$anhNhip && !$anhCoCau) {
    loi_xuat('Không có biểu đồ nào để xuất (khoảng ngày đã chọn không có thao tác). Hãy chọn trường dữ liệu hoặc đổi khoảng ngày.');
}

if ($dinhDang === 'excel') {
    $anhXlsx = array_values(array_filter([anh_theo_cao($anhNhip, 240), anh_theo_cao($anhCoCau, 240)]));
    if (count($anhXlsx) === 2) { $anhXlsx[1]['x'] = $anhXlsx[0]['rong'] + 16; }
    $hang = [
        ['kieu' => 2,  'o' => ['Nhật ký quản trị']],
        ['kieu' => 16, 'o' => ['Xuất lúc ' . $bayGio . ' · ' . $tomTat]],
        ['kieu' => 16, 'o' => [$moTaPhamVi . ' · Xếp ' . ($sx === 'ASC' ? 'cũ nhất trước' : 'mới nhất trước')]],
    ];
    if ($anhXlsx) { $hang[] = ['anh' => $anhXlsx]; }   // hai biểu đồ cạnh nhau, nằm giữa phần tiêu đề và bảng dữ liệu
    if ($truong) { $hang[] = ['kieu' => 6, 'o' => $tieuDeCot]; }
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
$anhPdf = array_values(array_filter([$anhNhip['jpeg'] ?? null, $anhCoCau['jpeg'] ?? null]));
if ($anhPdf) { $pdf->anhHang($anhPdf, 190); }

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
if ($truong) { $pdf->bang($tieuDeCot, $dongPdf, $tyLe, [], $mauO, $viTriTT !== false ? [$viTriTT] : []); }
$pdf->tai($tenFile . '.pdf');