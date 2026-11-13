<?php
// Xuất Nhật ký quản trị ra Excel hoặc PDF, nhiều sheet / nhiều trang. Đặt tại: elo_quiz/admin/nhatky_xuat.php
// Được gọi (POST, có CSRF) từ hộp thoại "Xuất" ở admin/nhatky.php. Mỗi lần gọi trả MỘT file; khi người dùng chọn
// định dạng "Cả hai", trình duyệt (nhatky.js) gọi lần lượt hai lần (Excel rồi PDF), không nén zip.
//
// Tham số:
//   pham_vi : loc = đang lọc (q, ad, hd, nhom, tt, tu, den gửi kèm từ trang, dựng bằng NhatKy::dieuKien giống hệt trang danh sách),
//             tatca = toàn bộ nhật ký (bỏ qua bộ lọc).
//   noidung[]: ds = danh sách nhật ký (bảng), nhip = biểu đồ thao tác theo ngày, cocau = biểu đồ cơ cấu hành động.
//   truong[]: các trường (cột) của danh sách: tg, hd, dt, mota, tt (xem $COT). Thứ tự cột trong file luôn theo $COT.
//   moc[]   : mốc thời gian, tích được nhiều: d7 = 7 ngày gần nhất, d30 = 30 ngày gần nhất (tính đến hôm nay, vẫn trong phạm vi đã lọc),
//             tatca = toàn bộ phạm vi.
//   chia    : moc  = theo MỐC (Excel: mỗi mốc một sheet "Nhật ký - 7 ngày" gồm ô số liệu, hai biểu đồ, danh sách;
//                    PDF: A4 ngang, mỗi mốc một trang tóm tắt rồi danh sách nối phía sau),
//             loai = theo LOẠI NỘI DUNG (Excel: sheet "Danh sách", "Biểu đồ theo ngày", "Cơ cấu hành động", các mốc xếp nối tiếp
//                    từ trên xuống; PDF: A4 dọc, một luồng),
//             ca   = cả hai nhóm trong cùng một file (nhóm theo mốc trước, nhóm theo loại sau).
//   dinhdang: excel | pdf.
//   sx      : asc = cũ nhất trước; mặc định mới nhất trước (giống thứ tự đang xem trên trang).
//   anh_nhip_<mốc>, anh_cocau_<mốc>: ảnh JPEG (data URL) của hai biểu đồ ứng với từng mốc, do trình duyệt vẽ từ số liệu xem trước
//             (cùng cách với admin/thongke_xuat.php), ví dụ anh_nhip_d7.
//   buoc=xemtruoc: không xuất file mà trả JSON để dựng khung "Xem trước" (số liệu, biểu đồ và vài dòng đầu của từng mốc).
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

// Ảnh dùng trong Excel, cao $cao pixel (giữ tỷ lệ) - để hai biểu đồ đặt ngang cạnh nhau cùng chiều cao; $x = pixel từ mép trái sheet
function anh_theo_cao(?array $a, int $cao, int $x = 0): ?array
{
    if (!$a) { return null; }
    return ['jpeg' => $a['jpeg'], 'rong' => (int) round($a['rong'] * $cao / $a['cao']), 'cao' => $cao, 'x' => $x];
}

// Ảnh dùng trong Excel, rộng $rong pixel (giữ tỷ lệ)
function anh_theo_rong(?array $a, int $rong, int $x = 0): ?array
{
    if (!$a) { return null; }
    return ['jpeg' => $a['jpeg'], 'rong' => $rong, 'cao' => (int) round($a['cao'] * $rong / $a['rong']), 'x' => $x];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(BASE_URL . '/admin/nhatky.php');
}
csrf_check();
@set_time_limit(120);

$TOI_DA_XLSX = 20000;   // số dòng tối đa của MỖI bảng danh sách (vượt quá thì yêu cầu lọc bớt, tránh làm treo máy chủ)
$TOI_DA_PDF  = 3000;
$SO_DONG_XEM = 10;      // số dòng đầu của mỗi mốc đưa vào khung xem trước

// Các trường được phép xuất: khóa => [tiêu đề cột, tỉ lệ độ rộng cột trong PDF]
$COT = [
    'tg'    => ['Thời gian',  2.3],
    'hd'    => ['Hành động',  2.8],
    'dt'    => ['Đối tượng',  2.6],
    'mota'  => ['Mô tả',      6.0],
    'tt'    => ['Trạng thái', 1.9],
];
// Mốc thời gian: khóa => [nhãn ngắn (tên sheet), nhãn đầy đủ, số ngày (0 = toàn bộ phạm vi)]
$MOC = [
    'd7'    => ['7 ngày',   '7 ngày gần nhất',            7],
    'd30'   => ['30 ngày',  '30 ngày gần nhất',           30],
    'tatca' => ['Toàn bộ',  'Toàn bộ phạm vi đang xuất',  0],
];
$NOI_DUNG = ['ds' => 'Danh sách', 'nhip' => 'Biểu đồ theo ngày', 'cocau' => 'Cơ cấu hành động'];

$pdo = Database::pdo();
NhatKy::damBaoCot($pdo);
$coTT = NhatKy::coCotTrangThai($pdo);

// Lấy các giá trị hợp lệ của một tham số dạng mảng (giữ thứ tự của danh sách $hopLe)
$chon = static fn (string $khoa, array $hopLe): array => array_values(array_intersect(
    $hopLe, is_array($_POST[$khoa] ?? null) ? array_map('strval', $_POST[$khoa]) : []
));

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
$truong   = $chon('truong', array_keys($COT));      // thứ tự cột trong file luôn cố định theo $COT
$noiDung  = $chon('noidung', array_keys($NOI_DUNG));
$moc      = $chon('moc', array_keys($MOC));
$chia     = (string) ($_POST['chia'] ?? '');
$coDs     = in_array('ds', $noiDung, true);
$coNhip   = in_array('nhip', $noiDung, true);
$coCoCau  = in_array('cocau', $noiDung, true);
if ($buoc !== 'xemtruoc') {
    if (!$noiDung)                       { loi_xuat('Hãy chọn ít nhất một nội dung cần xuất.'); }
    if ($coDs && !$truong)               { loi_xuat('Hãy chọn ít nhất một trường cho danh sách nhật ký.'); }
    if (!$moc)                           { loi_xuat('Hãy chọn ít nhất một mốc thời gian.'); }
    if (!in_array($chia, ['moc', 'loai', 'ca'], true)) { loi_xuat('Hãy chọn cách chia sheet / trang.'); }
}
$sx = (($_POST['sx'] ?? '') === 'asc') ? 'ASC' : 'DESC';
$sapXepChu = $sx === 'ASC' ? 'cũ nhất trước' : 'mới nhất trước';

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

// ---- Điều kiện theo mốc: 7 / 30 ngày gần nhất = thêm điều kiện thời gian vào bộ lọc; toàn bộ = giữ nguyên phạm vi ----
$gioiHanMoc = static function (string $m) use ($where, $tham, $MOC): array {
    $soNgay = $MOC[$m][2];
    if ($soNgay === 0) {
        return ['w' => $where, 'th' => $tham, 'tu' => '', 'den' => ''];
    }
    $tu = date('Y-m-d', strtotime('-' . ($soNgay - 1) . ' days'));
    return [
        'w'   => ($where === '' ? 'WHERE' : $where . ' AND') . ' n.ThoiGian >= ?',
        'th'  => array_merge($tham, [$tu . ' 00:00:00']),
        'tu'  => $tu,
        'den' => date('Y-m-d'),
    ];
};
$khoangChu = static fn (array $g): string => $g['tu'] !== ''
    ? $ngayVN($g['tu']) . ' – ' . $ngayVN($g['den'])
    : 'Toàn bộ thời gian trong phạm vi';

// Số liệu tổng quan của một mốc
$thongKeMoc = static function (array $g) use ($pdo, $coTT): array {
    $thatBaiSql = $coTT ? "COALESCE(SUM(n.TrangThai = 'Thất bại'), 0) AS thatbai" : "0 AS thatbai";
    $st = $pdo->prepare(
        "SELECT COUNT(*) AS tong,
                $thatBaiSql
         FROM NHATKYQUANTRI n
         JOIN ADMIN a ON a.MaAdmin = n.MaAdmin
         {$g['w']}"
    );
    $st->execute($g['th']);
    return array_map('intval', $st->fetch());
};

// Lấy các dòng của một mốc (đã định dạng sẵn để dùng chung cho xem trước, Excel và PDF)
$layDong = static function (array $g, int $gioiHan) use ($pdo, $sx, $coTT): array {
    $cotTT = $coTT ? "n.TrangThai" : "'Thành công' AS TrangThai";
    $st = $pdo->prepare(
        "SELECT n.ThoiGian, n.HanhDong, n.DoiTuong, n.MoTa, $cotTT
         FROM NHATKYQUANTRI n
         JOIN ADMIN a ON a.MaAdmin = n.MaAdmin
         {$g['w']}
         ORDER BY n.ThoiGian $sx, n.MaNhatKy $sx
         LIMIT " . (int) $gioiHan
    );
    $st->execute($g['th']);
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

// Số liệu hai biểu đồ của một mốc: [theoNgay => [ngay, tong, loi]..., coCau => [nhan, so, mau]...]
$soLieuBieuDo = static function (array $g) use ($pdo, $coTT): array {
    // 1) Số thao tác theo từng ngày (ngày không có thao tác được điền 0 để các cột liền nhau)
    $loiSql = $coTT ? "COALESCE(SUM(n.TrangThai = 'Thất bại'), 0) AS loi" : "0 AS loi";
    $st = $pdo->prepare(
        "SELECT DATE(n.ThoiGian) AS ngay, COUNT(*) AS tong,
                $loiSql
         FROM NHATKYQUANTRI n
         JOIN ADMIN a ON a.MaAdmin = n.MaAdmin
         {$g['w']}
         GROUP BY DATE(n.ThoiGian)
         ORDER BY ngay"
    );
    $st->execute($g['th']);
    $co = [];
    foreach ($st->fetchAll() as $r) { $co[$r['ngay']] = ['tong' => (int) $r['tong'], 'loi' => (int) $r['loi']]; }
    $theoNgay = [];
    if ($g['tu'] !== '' || $co) {
        $d    = new DateTime($g['tu'] !== '' ? $g['tu']  : (string) array_key_first($co));
        $cuoi = new DateTime($g['tu'] !== '' ? $g['den'] : (string) array_key_last($co));
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
         {$g['w']}
         GROUP BY n.HanhDong"
    );
    $st->execute($g['th']);
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
// XEM TRƯỚC (JSON): số liệu của CẢ BA mốc (trình duyệt chỉ hiện các mốc đang tích; đổi mốc không phải gọi lại)
// =====================================================================================================
if ($buoc === 'xemtruoc') {
    $ra = [];
    foreach ($MOC as $m => $thongTin) {
        $g = $gioiHanMoc($m);
        $t = $thongKeMoc($g);
        $bd = $t['tong'] > 0 ? $soLieuBieuDo($g) : ['theoNgay' => [], 'coCau' => []];
        $ra[$m] = [
            'nhan'      => $thongTin[0],
            'nhanDai'   => $thongTin[1],
            'khoang'    => $khoangChu($g),
            'tong'      => $t['tong'],
            'thanhCong' => $t['tong'] - $t['thatbai'],
            'thatBai'   => $t['thatbai'],
            'theoNgay'  => $bd['theoNgay'],
            'coCau'     => $bd['coCau'],
            'dong'      => $t['tong'] > 0 ? $layDong($g, $SO_DONG_XEM) : [],
        ];
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    exit(json_encode([
        'tong'      => $ra['tatca']['tong'],
        'phamVi'    => $moTaPhamVi,
        'sapXep'    => $sapXepChu,
        'soDongXem' => $SO_DONG_XEM,
        'toiDaXlsx' => $TOI_DA_XLSX,
        'toiDaPdf'  => $TOI_DA_PDF,
        'moc'       => $ra,
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
}

// =====================================================================================================
// XUẤT FILE
// =====================================================================================================
$tongPhamVi = $thongKeMoc($gioiHanMoc('tatca'))['tong'];
if ($tongPhamVi === 0) {
    loi_xuat('Không có bản ghi nhật ký nào trong phạm vi đã chọn.');
}
$toiDa = $dinhDang === 'pdf' ? $TOI_DA_PDF : $TOI_DA_XLSX;

// Dữ liệu của từng mốc đã chọn
$du = [];
$coAnh = false;
foreach ($moc as $m) {
    $g = $gioiHanMoc($m);
    $t = $thongKeMoc($g);
    if ($coDs && $t['tong'] > $toiDa) {
        loi_xuat('Mốc "' . $MOC[$m][1] . '" có ' . number_format($t['tong'], 0, ',', '.') . ' bản ghi, vượt giới hạn '
            . number_format($toiDa, 0, ',', '.') . ' bản ghi của file ' . ($dinhDang === 'pdf' ? 'PDF' : 'Excel')
            . '. Hãy lọc bớt (theo ngày, hành động...) hoặc bỏ mốc này rồi xuất lại.');
    }
    $du[$m] = [
        'g'        => $g,
        'tong'     => $t['tong'],
        'loi'      => $t['thatbai'],
        'dong'     => $coDs ? $layDong($g, $toiDa) : [],
        'bd'       => ($coNhip || $coCoCau) && $t['tong'] > 0 ? $soLieuBieuDo($g) : ['theoNgay' => [], 'coCau' => []],
        'anhNhip'  => $coNhip  ? anh_bieu_do('anh_nhip_'  . $m) : null,
        'anhCoCau' => $coCoCau ? anh_bieu_do('anh_cocau_' . $m) : null,
    ];
    if ($du[$m]['anhNhip'] || $du[$m]['anhCoCau']) { $coAnh = true; }
}
if (!$coDs && $chia === 'moc' && !$coAnh) {
    loi_xuat('Không có biểu đồ nào để xuất (các mốc đã chọn không có thao tác). Hãy chọn thêm danh sách, đổi mốc hoặc đổi phạm vi.');
}

$tenFile   = 'nhatky_quantri_' . date('Ymd_His');
$bayGio    = date('d/m/Y H:i');
$luc       = 'Xuất lúc ' . $bayGio;
$tieuDeCot = array_map(static fn (string $k): string => $COT[$k][0], $truong);
$so        = static fn (int $n): string => number_format($n, 0, ',', '.');
$dongPhu   = $moTaPhamVi . ' · Xếp ' . $sapXepChu;

// Ghi vết việc xuất (chỉ đọc dữ liệu nên không ảnh hưởng nội dung file vừa lấy). Gọi trước khi gửi file vì file được gửi xong là kết thúc chương trình.
NhatKy::ghi('Xuất nhật ký quản trị', null,
    ($dinhDang === 'pdf' ? 'PDF' : 'Excel') . ' · ' . $tongPhamVi . ' bản ghi trong phạm vi · Nội dung: '
    . implode(', ', array_map(static fn (string $k): string => $NOI_DUNG[$k], $noiDung))
    . ' · Mốc: ' . implode(', ', array_map(static fn (string $m): string => $MOC[$m][0], $moc))
    . ' · Chia: ' . ['moc' => 'theo mốc', 'loai' => 'theo loại', 'ca' => 'cả hai'][$chia]
    . ($coDs ? ' · Cột: ' . implode(', ', $tieuDeCot) : '') . ' · ' . $moTaPhamVi . '.');

$coNhomMoc  = $chia !== 'loai';   // có nhóm "theo mốc"
$coNhomLoai = $chia !== 'moc';    // có nhóm "theo loại nội dung"

try {
    // =================================================================================================
    // EXCEL: nhiều sheet
    // =================================================================================================
    if ($dinhDang === 'excel') {
        // Bảng danh sách của một mốc: dòng tiêu đề + các dòng dữ liệu (ô trạng thái tô màu: 8 = nền đỏ nhạt, 10 = nền xanh nhạt)
        $hangDs = static function (array $d) use ($truong, $tieuDeCot): array {
            $h = [['kieu' => 6, 'o' => $tieuDeCot]];
            foreach ($d['dong'] as $r) {
                $o = [];
                foreach ($truong as $k) {
                    $o[] = $k === 'tt' ? ['g' => $r[$k], 's' => $r[$k] === 'Thất bại' ? 8 : 10] : $r[$k];
                }
                $h[] = ['kieu' => 7, 'o' => $o];
            }
            return $h;
        };
        $ghiChuTrong = static fn (string $s): array => ['kieu' => 16, 'o' => [$s]];
        $sheets = [];

        // ---- Nhóm 1: THEO MỐC - mỗi mốc một sheet: tiêu đề, ô số liệu, hai biểu đồ cạnh nhau, danh sách ----
        if ($coNhomMoc) {
            foreach ($moc as $m) {
                $d = $du[$m];
                $h = [
                    ['kieu' => 2,  'o' => ['Nhật ký quản trị - ' . $MOC[$m][1]]],
                    ['kieu' => 16, 'o' => [$luc . ' · ' . $khoangChu($d['g'])]],
                    ['kieu' => 16, 'o' => [$dongPhu]],
                    ['kieu' => 3,  'o' => ['Tổng bản ghi', 'Thành công', 'Thất bại']],
                    ['kieu' => 4,  'o' => [$d['tong'], $d['tong'] - $d['loi'], $d['loi']]],
                ];
                $anh = array_values(array_filter([anh_theo_cao($d['anhNhip'], 240), anh_theo_cao($d['anhCoCau'], 240)]));
                if (count($anh) === 2) { $anh[1]['x'] = $anh[0]['rong'] + 16; }
                if ($anh) { $h[] = ['anh' => $anh]; }
                if ($coDs) {
                    if ($d['tong'] === 0) { $h[] = $ghiChuTrong('Không có bản ghi nhật ký nào trong mốc này.'); }
                    else                  { $h = array_merge($h, $hangDs($d)); }
                }
                $sheets[] = ['ten' => 'Nhật ký - ' . $MOC[$m][0], 'rongToiThieu' => 12, 'dong' => $h];
            }
        }

        // ---- Nhóm 2: THEO LOẠI NỘI DUNG - mỗi loại một sheet, các mốc xếp nối tiếp từ trên xuống ----
        if ($coNhomLoai) {
            if ($coDs) {
                $h = [['kieu' => 2, 'o' => ['Nhật ký quản trị - Danh sách']], $ghiChuTrong($luc . ' · ' . $dongPhu)];
                foreach ($moc as $m) {
                    $d = $du[$m];
                    $h[] = ['kieu' => 0, 'o' => []];
                    $h[] = ['kieu' => 2, 'o' => [$MOC[$m][1] . ' · ' . $so($d['tong']) . ' bản ghi']];
                    $h[] = $ghiChuTrong($khoangChu($d['g']));
                    if ($d['tong'] === 0) { $h[] = $ghiChuTrong('Không có bản ghi nhật ký nào trong mốc này.'); }
                    else                  { $h = array_merge($h, $hangDs($d)); }
                }
                $sheets[] = ['ten' => $NOI_DUNG['ds'], 'rongToiThieu' => 12, 'dong' => $h];
            }
            if ($coNhip) {
                $h = [['kieu' => 2, 'o' => ['Nhật ký quản trị - Thao tác theo ngày']], $ghiChuTrong($luc . ' · ' . $dongPhu)];
                foreach ($moc as $m) {
                    $d = $du[$m];
                    $h[] = ['kieu' => 0, 'o' => []];
                    $h[] = ['kieu' => 2, 'o' => [$MOC[$m][1]]];
                    $h[] = $ghiChuTrong($khoangChu($d['g']));
                    if (!$d['bd']['theoNgay']) { $h[] = $ghiChuTrong('Không có thao tác nào trong mốc này.'); continue; }
                    if ($d['anhNhip']) { $h[] = ['anh' => [anh_theo_rong($d['anhNhip'], 720)]]; }
                    $h[] = ['kieu' => 6, 'o' => ['Ngày', 'Tổng thao tác', 'Thành công', 'Thất bại']];
                    foreach ($d['bd']['theoNgay'] as $r) {
                        $h[] = ['kieu' => 0, 'o' => [$ngayVN($r['ngay']), $r['tong'], $r['tong'] - $r['loi'], $r['loi']]];
                    }
                }
                $sheets[] = ['ten' => $NOI_DUNG['nhip'], 'rongToiThieu' => 14, 'dong' => $h];
            }
            if ($coCoCau) {
                $h = [['kieu' => 2, 'o' => ['Nhật ký quản trị - Cơ cấu hành động']], $ghiChuTrong($luc . ' · ' . $dongPhu)];
                foreach ($moc as $m) {
                    $d = $du[$m];
                    $h[] = ['kieu' => 0, 'o' => []];
                    $h[] = ['kieu' => 2, 'o' => [$MOC[$m][1]]];
                    $h[] = $ghiChuTrong($khoangChu($d['g']));
                    if (!$d['bd']['coCau']) { $h[] = $ghiChuTrong('Không có thao tác nào trong mốc này.'); continue; }
                    if ($d['anhCoCau']) { $h[] = ['anh' => [anh_theo_rong($d['anhCoCau'], 320)]]; }
                    $tongNhom = max(1, array_sum(array_column($d['bd']['coCau'], 'so')));
                    $h[] = ['kieu' => 6, 'o' => ['Nhóm hành động', 'Số lượng', 'Tỷ lệ']];
                    foreach ($d['bd']['coCau'] as $r) {
                        $h[] = ['kieu' => 0, 'o' => [$r['nhan'], $r['so'], round($r['so'] * 100 / $tongNhom) . '%']];
                    }
                }
                $sheets[] = ['ten' => $NOI_DUNG['cocau'], 'rongToiThieu' => 14, 'dong' => $h];
            }
        }
        XlsxWriter::taiNhieuSheet($tenFile . '.xlsx', $sheets);
    }

    // =================================================================================================
    // PDF
    // =================================================================================================
    $pdf = new PdfWriter(__DIR__ . '/../assets/fonts');

    // Bảng danh sách của một mốc (ô trạng thái tô nền)
    $veDs = static function (array $d) use ($pdf, $truong, $tieuDeCot, $COT): void {
        $viTriTT = array_search('tt', $truong, true);
        $dongPdf = [];
        $mauO    = [];
        foreach ($d['dong'] as $i => $r) {
            $o = [];
            foreach ($truong as $k) { $o[] = $r[$k]; }
            $dongPdf[] = $o;
            if ($viTriTT !== false) {
                $mauO[$i] = [$viTriTT => $r['tt'] === 'Thất bại' ? '#fde3e6' : '#dcf5ea'];   // tô nền ô trạng thái
            }
        }
        $tyLe = array_map(static fn (string $k): float => $COT[$k][1], $truong);
        $pdf->bang($tieuDeCot, $dongPdf, $tyLe, [], $mauO, $viTriTT !== false ? [$viTriTT] : []);
    };

    // ---- Nhóm 1: THEO MỐC - khổ A4 NGANG. Mỗi mốc một trang tóm tắt (tiêu đề + khoảng ngày, ô số liệu, hai biểu đồ cạnh nhau),
    //      danh sách nối phía sau; mốc tiếp theo bắt đầu ở trang mới.
    if ($coNhomMoc) {
        $pdf->khoTrang(true);
        foreach ($moc as $i => $m) {
            $d = $du[$m];
            if ($i > 0) { $pdf->trangMoi(); }
            $pdf->tieuDe('Nhật ký quản trị - ' . $MOC[$m][1], 20);
            $pdf->doan($luc . ' · ' . $khoangChu($d['g']), 10, false, '#5b6b8c');
            $pdf->doan($dongPhu, 9, false, '#5b6b8c');
            $pdf->khoang(8);
            $pdf->oSoLieu([
                ['Tổng bản ghi', $so($d['tong']), ''],
                ['Thành công',   $so($d['tong'] - $d['loi']), ''],
                ['Thất bại',     $so($d['loi']), ''],
            ]);
            $anh = array_values(array_filter([$d['anhNhip']['jpeg'] ?? null, $d['anhCoCau']['jpeg'] ?? null]));
            if ($anh) { $pdf->anhHang($anh, 230); }
            if (($coNhip || $coCoCau) && !$anh && $d['tong'] === 0) { $pdf->doan('Không có thao tác nào trong mốc này.'); }
            if ($coDs) {
                $pdf->trangMoi();
                $pdf->tieuDe('Danh sách - ' . $MOC[$m][1] . ' (' . $khoangChu($d['g']) . ')', 15, '#2f6bff');
                $pdf->khoang(4);
                if ($d['tong'] === 0) { $pdf->doan('Không có bản ghi nhật ký nào trong mốc này.'); }
                else                  { $veDs($d); }
            }
        }
    }

    // ---- Nhóm 2: THEO LOẠI NỘI DUNG - khổ A4 DỌC, một luồng dọc (Danh sách, rồi Thao tác theo ngày, rồi Cơ cấu hành động) ----
    if ($coNhomLoai) {
        $pdf->khoTrang(false);
        $pdf->tieuDe('NHẬT KÝ QUẢN TRỊ - THEO LOẠI NỘI DUNG', 18);
        $pdf->doan($luc . ' · Mốc: ' . implode(', ', array_map(static fn (string $m): string => $MOC[$m][0], $moc)), 9, false, '#5b6b8c');
        $pdf->doan($dongPhu, 9, false, '#5b6b8c');
        $pdf->khoang(10);
        $dau = true;
        if ($coDs) {
            $dau = false;
            foreach ($moc as $i => $m) {
                $d = $du[$m];
                if ($i > 0) { $pdf->khoang(10); }
                $pdf->tieuDe('Danh sách - ' . $MOC[$m][1] . ' (' . $khoangChu($d['g']) . ')', 15, '#2f6bff');
                $pdf->khoang(4);
                if ($d['tong'] === 0) { $pdf->doan('Không có bản ghi nhật ký nào trong mốc này.'); continue; }
                $veDs($d);
            }
        }
        if ($coNhip) {
            if (!$dau) { $pdf->trangMoi(); }
            $dau = false;
            foreach ($moc as $i => $m) {
                $d = $du[$m];
                if ($i > 0) { $pdf->khoang(10); }
                $pdf->tieuDe('Thao tác theo ngày - ' . $MOC[$m][1] . ' (' . $khoangChu($d['g']) . ')', 15, '#2f6bff');
                $pdf->khoang(4);
                if (!$d['bd']['theoNgay']) { $pdf->doan('Không có thao tác nào trong mốc này.'); continue; }
                if ($d['anhNhip']) { $pdf->anh($d['anhNhip']['jpeg']); }
                $dongB = [];
                foreach ($d['bd']['theoNgay'] as $r) {
                    $dongB[] = [$ngayVN($r['ngay']), (string) $r['tong'], (string) ($r['tong'] - $r['loi']), (string) $r['loi']];
                }
                $pdf->bang(['Ngày', 'Tổng thao tác', 'Thành công', 'Thất bại'], $dongB, [2, 1.5, 1.5, 1.5], [], [], [1, 2, 3]);
            }
        }
        if ($coCoCau) {
            if (!$dau) { $pdf->trangMoi(); }
            foreach ($moc as $i => $m) {
                $d = $du[$m];
                if ($i > 0) { $pdf->khoang(10); }
                $pdf->tieuDe('Cơ cấu hành động - ' . $MOC[$m][1] . ' (' . $khoangChu($d['g']) . ')', 15, '#2f6bff');
                $pdf->khoang(4);
                if (!$d['bd']['coCau']) { $pdf->doan('Không có thao tác nào trong mốc này.'); continue; }
                if ($d['anhCoCau']) { $pdf->anhHang([$d['anhCoCau']['jpeg']], 230); }
                $tongNhom = max(1, array_sum(array_column($d['bd']['coCau'], 'so')));
                $dongB = [];
                $mauHang = [];
                foreach ($d['bd']['coCau'] as $k => $r) {
                    $dongB[] = [$r['nhan'], (string) $r['so'], round($r['so'] * 100 / $tongNhom) . '%'];
                    $mauHang[$k] = $r['mau'];
                }
                $pdf->bang(['Nhóm hành động', 'Số lượng', 'Tỷ lệ'], $dongB, [3, 1, 1], $mauHang, [], [1, 2]);
            }
        }
    }
    $pdf->tai($tenFile . '.pdf');
} catch (Throwable $ex) {
    loi_xuat('Không tạo được file xuất: ' . $ex->getMessage(), 500);
}