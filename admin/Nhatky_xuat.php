<?php
// Xuất nhật ký quản trị ra Excel/PDF theo mốc thời gian hoặc loại nội dung, kèm JSON xem trước.
require_once __DIR__ . '/../core/bootstrap.php';
Auth::requireAdmin(BASE_URL . '/admin/dangnhap.php');

function loi_xuat(string $thongBao, int $ma = 400): never
{
    http_response_code($ma);
    header('Content-Type: text/plain; charset=utf-8');
    exit($thongBao);
}

// Kiểm tra và giải mã ảnh biểu đồ JPEG (tối đa 5 MB).
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

// Tính kích thước ảnh theo chiều cao cố định (giữ tỷ lệ).
function anh_theo_cao(?array $a, int $cao, int $x = 0): ?array
{
    if (!$a) { return null; }
    return ['jpeg' => $a['jpeg'], 'rong' => (int) round($a['rong'] * $cao / $a['cao']), 'cao' => $cao, 'x' => $x];
}

// Tính kích thước ảnh theo chiều rộng cố định (giữ tỷ lệ).
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

$TOI_DA_XLSX = 20000;
$TOI_DA_PDF  = 3000;
$SO_DONG_XEM = 10;

// Cấu hình cột xuất và tỉ lệ độ rộng PDF.
$COT = [
    'tg'    => ['Thời gian',  2.3],
    'hd'    => ['Hành động',  2.8],
    'dt'    => ['Đối tượng',  2.6],
    'mota'  => ['Mô tả',      6.0],
];
// Mốc thời gian: khóa => [nhãn ngắn, nhãn đầy đủ, số ngày (0 = toàn bộ)].
$MOC = [
    'd7'    => ['7 ngày',   '7 ngày gần nhất',            7],
    'd30'   => ['30 ngày',  '30 ngày gần nhất',           30],
    'tatca' => ['Toàn bộ',  'Toàn bộ phạm vi đang xuất',  0],
];
$NOI_DUNG = ['ds' => 'Danh sách', 'nhip' => 'Biểu đồ theo ngày', 'cocau' => 'Cơ cấu hành động'];

$pdo = Database::pdo();
NhatKy::damBaoCot($pdo);
$coTT = NhatKy::coCotTrangThai($pdo);

// Lọc tham số mảng theo danh sách giá trị hợp lệ.
$chon = static fn (string $khoa, array $hopLe): array => array_values(array_intersect(
    $hopLe, is_array($_POST[$khoa] ?? null) ? array_map('strval', $_POST[$khoa]) : []
));

// Tham số từ hộp thoại xuất.
$phamVi = (string) ($_POST['pham_vi'] ?? '');
if (!in_array($phamVi, ['loc', 'tatca'], true)) {
    loi_xuat('Hãy chọn phạm vi cần xuất.');
}
$buoc = (string) ($_POST['buoc'] ?? '');
$dinhDang = (string) ($_POST['dinhdang'] ?? '');
if ($buoc === 'xemtruoc') { $dinhDang = 'excel'; }
if (!in_array($dinhDang, ['excel', 'pdf'], true)) {
    loi_xuat('Hãy chọn định dạng Excel hoặc PDF.');
}
$truong   = $chon('truong', array_keys($COT));
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

// Dựng điều kiện lọc.
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

// Mô tả bộ lọc dạng chữ.
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

// Giới hạn điều kiện truy vấn theo mốc thời gian.
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

// Thống kê tổng bản ghi của một mốc.
$thongKeMoc = static function (array $g) use ($pdo, $coTT): array {
    $st = $pdo->prepare(
        "SELECT COUNT(*) AS tong
         FROM NHATKYQUANTRI n
         JOIN ADMIN a ON a.MaAdmin = n.MaAdmin
         {$g['w']}"
    );
    $st->execute($g['th']);
    return array_map('intval', $st->fetch());
};

// Lấy danh sách bản ghi của một mốc.
$layDong = static function (array $g, int $gioiHan) use ($pdo, $sx, $coTT): array {
    $st = $pdo->prepare(
        "SELECT n.ThoiGian, n.HanhDong, n.DoiTuong, n.MoTa
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
        ];
    }
    return $ra;
};

// Số liệu biểu đồ theo ngày và cơ cấu hành động của một mốc.
$soLieuBieuDo = static function (array $g) use ($pdo, $coTT): array {
    // Số thao tác theo ngày (điền 0 cho ngày không phát sinh).
    $st = $pdo->prepare(
        "SELECT DATE(n.ThoiGian) AS ngay, COUNT(*) AS tong
         FROM NHATKYQUANTRI n
         JOIN ADMIN a ON a.MaAdmin = n.MaAdmin
         {$g['w']}
         GROUP BY DATE(n.ThoiGian)
         ORDER BY ngay"
    );
    $st->execute($g['th']);
    $co = [];
    foreach ($st->fetchAll() as $r) { $co[$r['ngay']] = ['tong' => (int) $r['tong']]; }
    $theoNgay = [];
    if ($g['tu'] !== '' || $co) {
        $d    = new DateTime($g['tu'] !== '' ? $g['tu']  : (string) array_key_first($co));
        $cuoi = new DateTime($g['tu'] !== '' ? $g['den'] : (string) array_key_last($co));
        for ($i = 0; $i < 400 && $d <= $cuoi; $i++) {
            $k = $d->format('Y-m-d');
            $theoNgay[] = ['ngay' => $k, 'tong' => $co[$k]['tong'] ?? 0];
            $d->modify('+1 day');
        }
    }

    // Cơ cấu hành động theo nhóm.
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

// Trả JSON xem trước cho cả 3 mốc.
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

// Xuất file Excel/PDF.
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

// Ghi nhật ký thao tác xuất trước khi gửi file.
NhatKy::ghi('Xuất nhật ký quản trị', null,
    ($dinhDang === 'pdf' ? 'PDF' : 'Excel') . ' · ' . $tongPhamVi . ' bản ghi trong phạm vi · Nội dung: '
    . implode(', ', array_map(static fn (string $k): string => $NOI_DUNG[$k], $noiDung))
    . ' · Mốc: ' . implode(', ', array_map(static fn (string $m): string => $MOC[$m][0], $moc))
    . ' · Chia: ' . ['moc' => 'theo mốc', 'loai' => 'theo loại', 'ca' => 'cả hai'][$chia]
    . ($coDs ? ' · Cột: ' . implode(', ', $tieuDeCot) : '') . ' · ' . $moTaPhamVi . '.');

$coNhomMoc  = $chia !== 'loai';
$coNhomLoai = $chia !== 'moc';

try {
    // Xuất Excel nhiều sheet.
    if ($dinhDang === 'excel') {
        $hangDs = static function (array $d) use ($truong, $tieuDeCot): array {
            $h = [['kieu' => 6, 'o' => $tieuDeCot]];
            foreach ($d['dong'] as $r) {
                $o = [];
                foreach ($truong as $k) {
                    $o[] = $r[$k];
                }
                $h[] = ['kieu' => 7, 'o' => $o];
            }
            return $h;
        };
        $ghiChuTrong = static fn (string $s): array => ['kieu' => 16, 'o' => [$s]];
        $sheets = [];

        // Nhóm theo mốc thời gian.
        if ($coNhomMoc) {
            foreach ($moc as $m) {
                $d = $du[$m];
                $h = [
                    ['kieu' => 2,  'o' => ['Nhật ký quản trị - ' . $MOC[$m][1]]],
                    ['kieu' => 16, 'o' => [$luc . ' · ' . $khoangChu($d['g'])]],
                    ['kieu' => 16, 'o' => [$dongPhu]],
                    ['kieu' => 3,  'o' => ['Tổng bản ghi']],
                    ['kieu' => 4,  'o' => [$d['tong']]],
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

        // Nhóm theo loại nội dung.
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
                    $h[] = ['kieu' => 6, 'o' => ['Ngày', 'Tổng thao tác']];
                    foreach ($d['bd']['theoNgay'] as $r) {
                        $h[] = ['kieu' => 0, 'o' => [$ngayVN($r['ngay']), $r['tong']]];
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

    // Xuất PDF.
    $pdf = new PdfWriter(__DIR__ . '/../assets/fonts');

    $veDs = static function (array $d) use ($pdf, $truong, $tieuDeCot, $COT): void {
        $dongPdf = [];
        foreach ($d['dong'] as $r) {
            $o = [];
            foreach ($truong as $k) { $o[] = $r[$k]; }
            $dongPdf[] = $o;
        }
        $tyLe = array_map(static fn (string $k): float => $COT[$k][1], $truong);
        $pdf->bang($tieuDeCot, $dongPdf, $tyLe);
    };

    // Nhóm theo mốc (A4 ngang).
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

    // Nhóm theo loại nội dung (A4 dọc).
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
                    $dongB[] = [$ngayVN($r['ngay']), (string) $r['tong']];
                }
                $pdf->bang(['Ngày', 'Tổng thao tác'], $dongB, [2, 1.5], [], [], [1]);
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