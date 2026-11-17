<?php
// Xuất danh sách / thống kê trận đấu ra Excel hoặc PDF. Đặt tại: elo_quiz/admin/trandau_xuat.php
// Được gọi (POST, có CSRF) từ hộp thoại "Xuất báo cáo" ở admin/trandau.php. Mỗi lần gọi trả MỘT file; khi người dùng chọn
// "Cả hai", trình duyệt (trandau_xuat.js) gọi lần lượt hai lần (Excel rồi PDF), không nén zip (cùng cách với thongke_xuat.php).
//
// Tham số:
//   pham_vi  : loc = đang lọc (q, mon, tt, bt gửi kèm từ trang, cùng tiêu chí với danh sách), tatca = mọi trận.
//   noidung[]: tongquan = ô số liệu + số trận theo trạng thái, bieudo = ảnh biểu đồ nhịp trận đang xem (do trình duyệt chụp),
//              ds = danh sách trận, batthuong = danh sách trận bất thường (A-25).
//   truong[] : các cột của danh sách (xem $COT). Thứ tự cột trong file luôn theo $COT.
//   dinhdang : excel | pdf.
//   buoc=xemtruoc: không xuất file mà trả JSON (số liệu, vài dòng đầu của danh sách) để dựng khung "Xem trước" trong hộp thoại.
//   anh_bieudo, nhan_bieudo: ảnh JPEG (data URL) và dòng mô tả của biểu đồ đang xem.
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/TranDauGiamSat.php';
require_once __DIR__ . '/../core/XlsxWriter.php';
require_once __DIR__ . '/../core/PdfWriter.php';
Auth::requireAdmin(BASE_URL . '/admin/dangnhap.php');   // A-04

function loi_xuat(string $thongBao, int $ma = 400): never
{
    http_response_code($ma);
    header('Content-Type: text/plain; charset=utf-8');
    exit($thongBao);
}

// Ảnh biểu đồ (data URL JPEG): chỉ nhận JPEG hợp lệ, tối đa 5 MB
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(BASE_URL . '/admin/trandau.php');
}
csrf_check();
@set_time_limit(120);

$TOI_DA_XLSX = 20000;   // số dòng tối đa của MỖI bảng danh sách (vượt thì yêu cầu lọc bớt, tránh treo máy chủ)
$TOI_DA_PDF  = 3000;

// Cột được phép xuất: khóa => [tiêu đề, tỉ lệ độ rộng trong PDF]
$COT = [
    'ma'    => ['Mã trận',                     1.0],
    'mon'   => ['Môn học',                     2.2],
    'kythi' => ['Kỳ thi',                      1.6],
    'nguoi' => ['Người chơi (điểm, kết quả)',  5.0],
    'tt'    => ['Trạng thái',                  1.7],
    'canhbao' => ['Cảnh báo',                  2.2],
    'lydo'  => ['Lý do kết thúc',              2.4],
    'bd'    => ['Bắt đầu',                     2.3],
    'kt'    => ['Kết thúc',                    2.3],
    'tl'    => ['Thời lượng',                  1.4],
];
$NOI_DUNG = ['tongquan' => 'Tổng quan', 'bieudo' => 'Biểu đồ nhịp trận', 'ds' => 'Danh sách trận', 'batthuong' => 'Trận bất thường'];

$chon = static fn (string $khoa, array $hopLe): array => array_values(array_intersect(
    $hopLe, is_array($_POST[$khoa] ?? null) ? array_map('strval', $_POST[$khoa]) : []
));

$phamVi = (string) ($_POST['pham_vi'] ?? '');
if (!in_array($phamVi, ['loc', 'tatca'], true)) { loi_xuat('Hãy chọn phạm vi cần xuất.'); }
$dinhDang = (string) ($_POST['dinhdang'] ?? '');
$xemTruoc = (($_POST['buoc'] ?? '') === 'xemtruoc');
if ($xemTruoc) { $dinhDang = 'excel'; }   // xem trước không cần định dạng
if (!in_array($dinhDang, ['excel', 'pdf'], true)) { loi_xuat('Hãy chọn định dạng Excel hoặc PDF.'); }
$noiDung = $chon('noidung', array_keys($NOI_DUNG));
$truong  = $chon('truong', array_keys($COT));
if (!$xemTruoc && !$noiDung) { loi_xuat('Hãy chọn ít nhất một nội dung cần xuất.'); }
$coTq = in_array('tongquan', $noiDung, true);
$coBd = in_array('bieudo', $noiDung, true);
$coDs = in_array('ds', $noiDung, true);
$coBt = in_array('batthuong', $noiDung, true);
if (!$xemTruoc && ($coDs || $coBt) && !$truong) { loi_xuat('Hãy chọn ít nhất một cột cho danh sách trận.'); }

$pdo      = Database::pdo();
$coCotTao = TranDauGiamSat::coCotTao($pdo);
$nguongGiay = TranDauGiamSat::nguongGiay();
$nguongPhut = intdiv($nguongGiay, 60);
[$dkBt, $thamBt] = TranDauGiamSat::dieuKienBatThuong($coCotTao, $nguongGiay);
$cotTao = $coCotTao ? ', TIMESTAMPDIFF(SECOND, t.ThoiGianTao, NOW(3)) AS giay_da_ghep' : ', NULL AS giay_da_ghep';

// ---- Bộ lọc (cùng tiêu chí với admin/trandau.php) ----
$dieuKien = []; $tham = []; $moTaLoc = [];
if ($phamVi === 'loc') {
    $q   = trim((string) ($_POST['q'] ?? ''));
    $mon = (int) ($_POST['mon'] ?? 0);
    $tt  = (string) ($_POST['tt'] ?? '');
    $bt  = ($_POST['bt'] ?? '') === '1';
    if (!in_array($tt, ['Đã ghép', 'Đang diễn ra', 'Kết thúc', 'Hủy'], true)) { $tt = ''; }
    if ($q !== '') {
        $maTran = ctype_digit(ltrim($q, '#')) ? (int) ltrim($q, '#') : 0;
        $like   = '%' . addcslashes($q, '\\%_') . '%';
        $dieuKien[] = '(t.MaTran = ? OR EXISTS (
            SELECT 1 FROM NGUOICHOITRANDAU p JOIN NGUOIDUNG n ON n.MaND = p.MaND
             WHERE p.MaTran = t.MaTran AND (n.HoTen LIKE ? OR n.Email LIKE ?)))';
        array_push($tham, $maTran, $like, $like);
        $moTaLoc[] = 'Tìm "' . mb_substr($q, 0, 60) . '"';
    }
    if ($mon > 0) {
        $dieuKien[] = 't.MaMon = ?';
        $tham[]     = $mon;
        $st = $pdo->prepare('SELECT TenMon FROM MONHOC WHERE MaMon = ?');
        $st->execute([$mon]);
        $moTaLoc[] = 'Môn: ' . ($st->fetchColumn() ?: '#' . $mon);
    }
    if ($tt !== '') {
        $dieuKien[] = 't.TrangThai = ?';
        $tham[]     = $tt;
        $moTaLoc[]  = 'Trạng thái: ' . $tt;
    }
    if ($bt) {
        $dieuKien[] = $dkBt;
        array_push($tham, ...$thamBt);
        $moTaLoc[] = 'Chỉ trận bất thường';
    }
}
$where = $dieuKien ? 'WHERE ' . implode(' AND ', $dieuKien) : '';
$moTaPhamVi = $phamVi === 'tatca' ? 'Toàn bộ trận đấu' : ($moTaLoc ? 'Đang lọc: ' . implode(' · ', $moTaLoc) : 'Đang lọc: không có bộ lọc (mọi trận)');

$st = $pdo->prepare("SELECT COUNT(*) FROM TRANDAU t $where");
$st->execute($tham);
$tongPhamVi = (int) $st->fetchColumn();
if ($coDs && !$xemTruoc) {
    $gioiHan = $dinhDang === 'pdf' ? $TOI_DA_PDF : $TOI_DA_XLSX;
    if ($tongPhamVi > $gioiHan) {
        loi_xuat('Có ' . number_format($tongPhamVi, 0, ',', '.') . ' trận, vượt giới hạn ' . number_format($gioiHan, 0, ',', '.')
            . ' dòng cho file ' . ($dinhDang === 'pdf' ? 'PDF' : 'Excel') . '. Hãy lọc bớt (theo môn, trạng thái, người chơi) rồi xuất lại.');
    }
}

// ---- Số liệu tổng quan trong phạm vi ----
$tq = ['tong' => 0, 'hom_nay' => 0, 'tb' => null, 'bat_thuong' => 0, 'tt' => ['Đã ghép' => 0, 'Đang diễn ra' => 0, 'Kết thúc' => 0, 'Hủy' => 0]];
if ($coTq) {
    $moc = $coCotTao ? 'COALESCE(t.ThoiGianBatDau, t.ThoiGianTao)' : 't.ThoiGianBatDau';
    $st = $pdo->prepare(
        "SELECT COUNT(*) AS tong, COALESCE(SUM(DATE($moc) = CURDATE()), 0) AS hom_nay,
                AVG(CASE WHEN t.TrangThai = 'Kết thúc' AND t.ThoiGianBatDau IS NOT NULL AND t.ThoiGianKetThuc IS NOT NULL
                         THEN TIMESTAMPDIFF(SECOND, t.ThoiGianBatDau, t.ThoiGianKetThuc) END) AS tb,
                COALESCE(SUM($dkBt), 0) AS bat_thuong
           FROM TRANDAU t $where"
    );
    $st->execute(array_merge($thamBt, $tham));
    $r = $st->fetch();
    $tq['tong'] = (int) $r['tong']; $tq['hom_nay'] = (int) $r['hom_nay']; $tq['tb'] = $r['tb']; $tq['bat_thuong'] = (int) $r['bat_thuong'];
    $st = $pdo->prepare("SELECT t.TrangThai, COUNT(*) FROM TRANDAU t $where GROUP BY t.TrangThai");
    $st->execute($tham);
    foreach ($st->fetchAll(PDO::FETCH_NUM) as [$k, $n]) { $tq['tt'][$k] = (int) $n; }
}

// ---- Danh sách trận (và danh sách bất thường) ----
$sqlDs = static function (string $whereSql) use ($cotTao): string {
    return "SELECT t.MaTran, m.TenMon, k.TenLoaiKT, t.TrangThai, t.LyDoKetThuc, t.ThoiGianBatDau, t.ThoiGianKetThuc,
                   TIMESTAMPDIFF(SECOND, t.ThoiGianBatDau, NOW(3)) AS giay_dien_ra,
                   TIMESTAMPDIFF(SECOND, t.ThoiGianBatDau, t.ThoiGianKetThuc) AS giay_tong $cotTao,
                   (SELECT GROUP_CONCAT(CONCAT(n.HoTen, ' (', p.DiemSo, IF(p.KetQua IS NULL, '', CONCAT(', ', p.KetQua)),
                                               IF(p.TrangThaiThamGia = 'Bỏ trận', ', bỏ trận', ''), ')')
                                        ORDER BY p.MaND SEPARATOR ' vs ')
                      FROM NGUOICHOITRANDAU p JOIN NGUOIDUNG n ON n.MaND = p.MaND WHERE p.MaTran = t.MaTran) AS nguoi
              FROM TRANDAU t
              JOIN MONHOC m ON m.MaMon = t.MaMon
              JOIN LOAIKYTHI k ON k.MaLoaiKT = t.MaLoaiKT
              $whereSql
             ORDER BY t.MaTran DESC";
};
$ngay = static fn ($v): string => $v ? date('d/m/Y H:i:s', strtotime((string) $v)) : '';
$dongTran = static function (array $r) use ($nguongGiay): array {
    $nhan = TranDauGiamSat::nhan($r, $nguongGiay);
    if ($r['TrangThai'] === 'Kết thúc' || $r['TrangThai'] === 'Hủy') { $tl = $r['giay_tong'] !== null ? TranDauGiamSat::thoiLuong($r['giay_tong']) : ''; }
    elseif ($r['TrangThai'] === 'Đang diễn ra') { $tl = TranDauGiamSat::thoiLuong($r['giay_dien_ra']) . ' (đang chạy)'; }
    else { $tl = ''; }
    return [
        'ma' => (int) $r['MaTran'], 'mon' => $r['TenMon'], 'kythi' => $r['TenLoaiKT'], 'nguoi' => (string) $r['nguoi'],
        'tt' => $r['TrangThai'], 'canhbao' => (string) $nhan, 'lydo' => (string) $r['LyDoKetThuc'],
        'bd' => $r['ThoiGianBatDau'] ? date('d/m/Y H:i:s', strtotime($r['ThoiGianBatDau'])) : '',
        'kt' => $r['ThoiGianKetThuc'] ? date('d/m/Y H:i:s', strtotime($r['ThoiGianKetThuc'])) : '',
        'tl' => $tl, '_bt' => $nhan !== null,
    ];
};
$dsTran = [];
if ($coDs) {
    $st = $pdo->prepare($sqlDs($where) . ($xemTruoc ? ' LIMIT 10' : ''));
    $st->execute($tham);
    foreach ($st->fetchAll() as $r) { $dsTran[] = $dongTran($r); }
}
$dsBt = [];
if ($coBt) {
    $w2 = $dieuKien ? 'WHERE ' . implode(' AND ', $dieuKien) . ' AND ' . $dkBt : 'WHERE ' . $dkBt;
    $st = $pdo->prepare($sqlDs($w2) . ($xemTruoc ? ' LIMIT 10' : ' LIMIT 1000'));
    $st->execute(array_merge($tham, $thamBt));
    foreach ($st->fetchAll() as $r) { $dsBt[] = $dongTran($r); }
}

$anh      = $coBd ? anh_bieu_do('anh_bieudo') : null;
$nhanBd   = mb_substr(trim((string) ($_POST['nhan_bieudo'] ?? '')), 0, 120);
$luc      = 'Xuất lúc ' . date('H:i d/m/Y');
$tenFile  = 'trandau_' . date('Ymd_His');
$so       = static fn (int $n): string => number_format($n, 0, ',', '.');
$tieuDeCot = array_map(static fn (string $k): string => $COT[$k][0], $truong);
$tyLe     = array_map(static fn (string $k): float => $COT[$k][1], $truong);
$tbChu    = TranDauGiamSat::thoiLuong($tq['tb']);

// ---- Xem trước: trả JSON, không xuất file, không ghi nhật ký ----
if ($xemTruoc) {
    $demBt = 0;
    if ($coBt) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM TRANDAU t $w2");
        $st->execute(array_merge($tham, $thamBt));
        $demBt = (int) $st->fetchColumn();
    }
    $cat = static fn (array $ds): array => array_map(
        static fn (array $r): array => array_map(static fn (string $k): string => (string) $r[$k], $GLOBALS['truong']), $ds);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'tong'  => $tongPhamVi,
        'mota'  => $moTaPhamVi,
        'tq'    => $coTq ? ['tong' => $tq['tong'], 'hom_nay' => $tq['hom_nay'], 'tb' => $tbChu, 'bat_thuong' => $tq['bat_thuong'], 'tt' => $tq['tt']] : null,
        'cot'   => $tieuDeCot,
        'ds'    => $coDs ? $cat($dsTran) : null,
        'bt'    => $coBt ? $cat($dsBt) : null,
        'tongBt' => $demBt,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Ghi vết việc xuất (gọi trước khi gửi file vì gửi xong là kết thúc chương trình)
NhatKy::ghi('Xuất danh sách trận đấu', null,
    ($dinhDang === 'pdf' ? 'PDF' : 'Excel') . ' · ' . $so($tongPhamVi) . ' trận trong phạm vi · Nội dung: '
    . implode(', ', array_map(static fn (string $k): string => $NOI_DUNG[$k], $noiDung))
    . (($coDs || $coBt) ? ' · Cột: ' . implode(', ', $tieuDeCot) : '') . ' · ' . $moTaPhamVi . '.');

$mauTT = ['Kết thúc' => ['xlsx' => 10, 'pdf' => '#dcf5ea'], 'Hủy' => ['xlsx' => 8, 'pdf' => '#fde3e6'],
          'Đang diễn ra' => ['xlsx' => 0, 'pdf' => '#e3ebff'], 'Đã ghép' => ['xlsx' => 0, 'pdf' => '#fff4d6']];

try {
    // ============================== EXCEL ==============================
    if ($dinhDang === 'excel') {
        $hangDs = static function (array $ds) use ($truong, $tieuDeCot, $mauTT): array {
            $h = [['kieu' => 6, 'o' => $tieuDeCot]];
            foreach ($ds as $r) {
                $o = [];
                foreach ($truong as $k) {
                    $s = $k === 'tt' ? ($mauTT[$r['tt']]['xlsx'] ?? 0) : 0;
                    $o[] = $s ? ['g' => $r[$k], 's' => $s] : $r[$k];
                }
                $h[] = ['kieu' => 7, 'o' => $o];
            }
            return $h;
        };
        $ghiChu = static fn (string $s): array => ['kieu' => 16, 'o' => [$s]];
        $sheets = [];

        if ($coTq || $coBd) {
            $h = [['kieu' => 2, 'o' => ['Trận đấu - Tổng quan']], $ghiChu($luc), $ghiChu($moTaPhamVi)];
            if ($coTq) {
                $h[] = ['kieu' => 3, 'o' => ['Tổng số trận', 'Trận hôm nay', 'Thời lượng TB', 'Trận bất thường']];
                $h[] = ['kieu' => 4, 'o' => [$tq['tong'], $tq['hom_nay'], $tbChu, $tq['bat_thuong']]];
                $h[] = ['kieu' => 0, 'o' => []];
                $h[] = ['kieu' => 6, 'o' => ['Trạng thái', 'Số trận', 'Tỷ lệ']];
                $tongTT = max(1, array_sum($tq['tt']));
                foreach ($tq['tt'] as $ten => $n) { $h[] = ['kieu' => 0, 'o' => [$ten, $n, round($n * 100 / $tongTT) . '%']]; }
            }
            if ($anh) {
                $h[] = ['kieu' => 0, 'o' => []];
                if ($nhanBd !== '') { $h[] = ['kieu' => 1, 'o' => [$nhanBd]]; }
                $h[] = ['anh' => [['jpeg' => $anh['jpeg'], 'rong' => 720, 'cao' => (int) round($anh['cao'] * 720 / $anh['rong']), 'x' => 0]]];
            }
            $sheets[] = ['ten' => 'Tổng quan', 'rongToiThieu' => 16, 'dong' => $h];
        }
        if ($coDs) {
            $h = [['kieu' => 2, 'o' => ['Trận đấu - Danh sách (' . $so(count($dsTran)) . ' trận)']], $ghiChu($luc . ' · ' . $moTaPhamVi)];
            $h = array_merge($h, $dsTran ? $hangDs($dsTran) : [$ghiChu('Không có trận nào khớp.')]);
            $sheets[] = ['ten' => 'Danh sách trận', 'rongToiThieu' => 14, 'dong' => $h];
        }
        if ($coBt) {
            $h = [['kieu' => 2, 'o' => ['Trận đấu - Trận bất thường (' . $so(count($dsBt)) . ' trận)']],
                  $ghiChu($luc . ' · Hủy do lỗi hệ thống, có người bị xử thua, hoặc chưa xong quá ' . $nguongPhut . ' phút. ' . $moTaPhamVi)];
            $h = array_merge($h, $dsBt ? $hangDs($dsBt) : [$ghiChu('Không có trận bất thường.')]);
            $sheets[] = ['ten' => 'Trận bất thường', 'rongToiThieu' => 14, 'dong' => $h];
        }
        XlsxWriter::taiNhieuSheet($tenFile . '.xlsx', $sheets);
    }

    // ============================== PDF (A4 ngang) ==============================
    $pdf = new PdfWriter(__DIR__ . '/../assets/fonts');
    $pdf->khoTrang(true);
    $veDs = static function (array $ds) use ($pdf, $truong, $tieuDeCot, $tyLe, $mauTT): void {
        $viTri = array_search('tt', $truong, true);
        $dong = []; $mauO = [];
        foreach ($ds as $i => $r) {
            $o = [];
            foreach ($truong as $k) { $o[] = $r[$k]; }
            $dong[] = $o;
            if ($viTri !== false && isset($mauTT[$r['tt']])) { $mauO[$i] = [$viTri => $mauTT[$r['tt']]['pdf']]; }
        }
        $pdf->bang($tieuDeCot, $dong, $tyLe, [], $mauO, $viTri !== false ? [$viTri] : []);
    };
    $dau = true;
    if ($coTq || $coBd) {
        $dau = false;
        $pdf->tieuDe('TRẬN ĐẤU - TỔNG QUAN', 20);
        $pdf->doan($luc . ' · ' . $moTaPhamVi, 10, false, '#5b6b8c');
        $pdf->khoang(8);
        if ($coTq) {
            $pdf->oSoLieu([['Tổng số trận', $so($tq['tong']), ''], ['Trận hôm nay', $so($tq['hom_nay']), ''],
                           ['Thời lượng TB', $tbChu, ''], ['Trận bất thường', $so($tq['bat_thuong']), '']]);
        }
        if ($anh) {
            if ($nhanBd !== '') { $pdf->doan($nhanBd, 10, true); }
            $pdf->anh($anh['jpeg']);
        }
        if ($coTq) {
            $tongTT = max(1, array_sum($tq['tt']));
            $dong = [];
            foreach ($tq['tt'] as $ten => $n) { $dong[] = [$ten, $so($n), round($n * 100 / $tongTT) . '%']; }
            $pdf->khoang(6);
            $pdf->bang(['Trạng thái', 'Số trận', 'Tỷ lệ'], $dong, [3, 1, 1], [], [], [1, 2]);
        }
    }
    if ($coDs) {
        if (!$dau) { $pdf->trangMoi(); }
        $dau = false;
        $pdf->tieuDe('Danh sách trận (' . $so(count($dsTran)) . ' trận)', 15, '#2f6bff');
        $pdf->khoang(4);
        if ($dsTran) { $veDs($dsTran); } else { $pdf->doan('Không có trận nào khớp.'); }
    }
    if ($coBt) {
        if (!$dau) { $pdf->trangMoi(); }
        $pdf->tieuDe('Trận bất thường (' . $so(count($dsBt)) . ' trận)', 15, '#2f6bff');
        $pdf->doan('Hủy do lỗi hệ thống, có người bị xử thua, hoặc chưa xong quá ' . $nguongPhut . ' phút.', 9, false, '#5b6b8c');
        $pdf->khoang(4);
        if ($dsBt) { $veDs($dsBt); } else { $pdf->doan('Không có trận bất thường.'); }
    }
    $pdf->tai($tenFile . '.pdf');
} catch (Throwable $ex) {
    loi_xuat('Không xuất được file: ' . $ex->getMessage(), 500);
}