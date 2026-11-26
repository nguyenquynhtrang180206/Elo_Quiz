<?php
// Xuất báo cáo thống kê tổng quan và theo môn học ra Excel hoặc PDF.
require_once __DIR__ . '/../core/bootstrap.php';
Auth::requireAdmin(BASE_URL . '/admin/dangnhap.php');

function loi_xuat(string $thongBao, int $ma = 400): never
{
    http_response_code($ma);
    header('Content-Type: text/plain; charset=utf-8');
    exit($thongBao);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(BASE_URL . '/admin/index.php');
}
csrf_check();

// Tham số từ hộp thoại xuất.
$trang = (string) ($_POST['trang'] ?? '');
if (!in_array($trang, ['tongquan', 'mon', 'ca'], true)) {
    loi_xuat('Hãy chọn trang thống kê cần xuất.');
}
$chon = $trang === 'ca' ? ['tongquan', 'mon'] : [$trang];
$coTq  = in_array('tongquan', $chon, true);
$coMon = in_array('mon', $chon, true);

$khoang = ThongKe::locKhoang($_POST['ngay'] ?? []);
if (!$khoang) {
    loi_xuat('Hãy chọn ít nhất một mốc thời gian.');
}
$dinhDang = (string) ($_POST['dinhdang'] ?? '');
if (!in_array($dinhDang, ['excel', 'pdf'], true)) {
    loi_xuat('Hãy chọn định dạng Excel hoặc PDF.');
}
$chia = (string) ($_POST['chia'] ?? '');
if (!in_array($chia, ['ngay', 'loai', 'ca'], true)) {
    loi_xuat('Hãy chọn cách chia sheet / trang.');
}
$coNgay = $chia !== 'loai';   // có nhóm "theo mốc ngày"
$coLoai = $chia !== 'ngay';   // có nhóm "theo loại biểu đồ"

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

// Tính kích thước ảnh theo chiều rộng cố định (giữ tỷ lệ).
function anh_theo_rong(?array $a, int $rong, int $x = 0): ?array
{
    if (!$a) { return null; }
    return ['jpeg' => $a['jpeg'], 'rong' => $rong, 'cao' => (int) round($a['cao'] * $rong / $a['rong']), 'x' => $x];
}

// Tính kích thước ảnh theo chiều cao cố định (giữ tỷ lệ).
function anh_theo_cao(?array $a, int $cao, int $x = 0): ?array
{
    if (!$a) { return null; }
    return ['jpeg' => $a['jpeg'], 'rong' => (int) round($a['rong'] * $cao / $a['cao']), 'cao' => $cao, 'x' => $x];
}

$pdo = Database::pdo();
$du = [];   // [mốc] => ['tq' => ..., 'mon' => ..., 'anhTq' => ..., 'anhMon' => ...]
foreach ($khoang as $n) {
    $du[$n] = [
        'tq'     => $coTq  ? ThongKe::tongQuan($pdo, $n) : null,
        'mon'    => $coMon ? ThongKe::theoMon($pdo, $n)  : null,
        'anhTq'  => $coTq  ? anh_bieu_do('anh_tongquan_' . $n) : null,
        'anhMon' => $coMon ? anh_bieu_do('anh_mon_' . $n)      : null,
    ];
}

$luc     = 'Xuất lúc ' . date('H:i d/m/Y');
$tenFile = 'thongke_' . date('Ymd_His');

try {
    if ($dinhDang === 'excel') {
        // Mã định dạng dòng Excel: 0 thường, 1 đậm, 2 tiêu đề lớn, 3-5 ô số liệu, 6 tiêu đề bảng.
        $t  = static fn (array $o): array => ['kieu' => 0, 'o' => $o];
        $b  = static fn (array $o): array => ['kieu' => 1, 'o' => $o];
        $th = static fn (array $o): array => ['kieu' => 6, 'o' => $o];
        $tdl = static fn (string $o): array => ['kieu' => 2, 'o' => [$o]];
        $trong = ['kieu' => 0, 'o' => []];

        // Khối bảng dùng chung.
        $bangChiSo = static function (array $tq) use ($t, $th, $trong): array {
            $d = [$th(['Chỉ số', 'Giá trị', 'So với kỳ trước'])];
            foreach ($tq['the'] as $r) { $d[] = $t($r); }
            $d[] = $t(['Tổng số môn học', $tq['tongMon'], '']);
            $d[] = $t(['Tổng số câu hỏi', $tq['tongCauHoi'], '']);
            return array_merge($d, [$trong]);
        };
        $bangTheoMoc = static function (array $tq) use ($t, $th, $trong): array {
            $d = [$th(['Gom theo ' . $tq['kieu'], 'Người dùng mới', 'Trận đấu'])];
            foreach ($tq['nhan'] as $i => $nhan) { $d[] = $t([$nhan, $tq['dangky'][$i], $tq['tran'][$i]]); }
            return array_merge($d, [$trong]);
        };
        $bangTrangThai = static function (array $tq) use ($t, $th, $trong): array {
            $d = [$th(['Trận đấu theo trạng thái', 'Số trận'])];
            foreach ($tq['trangThai'] as $ten => $so) { $d[] = $t([$ten, $so]); }
            return array_merge($d, [$trong]);
        };
        $bangMon = static function (array $mon, int $n) use ($t, $b, $th, $trong): array {
            if (!$mon['ds']) { return [$t(['Chưa có trận nào trong ' . ThongKe::nhanKhoang($n) . ' gần nhất.']), $trong]; }
            $d = [$th(['Môn học', 'Số trận', 'Tỷ lệ (%)'])];
            foreach ($mon['ds'] as $m) { $d[] = $t([$m['ten'], $m['so'], $m['phantram']]); }
            $d[] = $b(['Tổng', $mon['tong'], '']);
            return array_merge($d, [$trong]);
        };

        $sheets = [];

        // Nhóm 1: Sheet theo mốc ngày.
        if ($coNgay) {
            foreach ($khoang as $n) {
                $tq = $du[$n]['tq'];
                $mon = $du[$n]['mon'];
                $d = [$tdl('Báo cáo thống kê - ' . ThongKe::nhanKhoang($n) . ' (' . ThongKe::khoangNgay($n) . ')'),
                      $t([$luc]), $trong];
                if ($tq) {
                    $d[] = ['kieu' => 3, 'o' => array_column($tq['the'], 0)];
                    $d[] = ['kieu' => 4, 'o' => array_column($tq['the'], 1)];
                    $d[] = ['kieu' => 5, 'o' => array_map(static fn ($r) => 'So với kỳ trước: ' . $r[2], $tq['the'])];
                    $d[] = $trong;
                }
                // Đặt hai biểu đồ ngang cạnh nhau cùng chiều cao.
                $anhTq  = anh_theo_cao($du[$n]['anhTq'], 240);
                $anhMon = ($mon && $mon['ds']) ? anh_theo_cao($du[$n]['anhMon'], 240) : null;
                if ($anhTq && $anhMon) { $anhMon['x'] = $anhTq['rong'] + 16; }
                $anh = array_values(array_filter([$anhTq, $anhMon]));
                if ($anh) { $d[] = ['anh' => $anh]; }
                if ($tq)  { $d = array_merge($d, $bangChiSo($tq), $bangTheoMoc($tq), $bangTrangThai($tq)); }
                if ($mon) { $d = array_merge($d, $bangMon($mon, $n)); }
                $sheets[] = ['ten' => 'Ngày - ' . ThongKe::nhanKhoang($n), 'rongToiThieu' => 16, 'dong' => $d];
            }
        }

        // Nhóm 2: Sheet theo loại biểu đồ.
        if ($coLoai) {
            if ($coTq) {
                $d = [$tdl('Thống kê tổng quan - theo loại biểu đồ'), $t([$luc . ' · Mốc: '
                      . implode(', ', array_map([ThongKe::class, 'nhanKhoang'], $khoang))]), $trong];
                foreach ($khoang as $n) {
                    $tq = $du[$n]['tq'];
                    $d[] = $b(['Mốc ' . ThongKe::nhanKhoang($n) . ' (' . ThongKe::khoangNgay($n) . ')']);
                    $anh = anh_theo_rong($du[$n]['anhTq'], 720);
                    if ($anh) { $d[] = ['anh' => [$anh]]; }
                    $d = array_merge($d, $bangChiSo($tq), $bangTheoMoc($tq), $bangTrangThai($tq), [$trong]);
                }
                $sheets[] = ['ten' => 'Loại - Tổng quan', 'rongToiThieu' => 16, 'dong' => $d];
            }
            if ($coMon) {
                $d = [$tdl('Thống kê theo môn học - theo loại biểu đồ'), $t([$luc . ' · Mốc: '
                      . implode(', ', array_map([ThongKe::class, 'nhanKhoang'], $khoang))]), $trong];
                foreach ($khoang as $n) {
                    $mon = $du[$n]['mon'];
                    $d[] = $b(['Mốc ' . ThongKe::nhanKhoang($n) . ' (' . ThongKe::khoangNgay($n) . ')']);
                    $anh = $mon['ds'] ? anh_theo_rong($du[$n]['anhMon'], 320) : null;
                    if ($anh) { $d[] = ['anh' => [$anh]]; }
                    $d = array_merge($d, $bangMon($mon, $n), [$trong]);
                }
                $sheets[] = ['ten' => 'Loại - Theo môn học', 'rongToiThieu' => 16, 'dong' => $d];
            }
        }
        XlsxWriter::taiNhieuSheet($tenFile . '.xlsx', $sheets);
    }

    // Xuất PDF.
    $pdf = new PdfWriter(__DIR__ . '/../assets/fonts');

    // Nhóm 1: Theo mốc ngày (A4 ngang).
    if ($coNgay) {
        $pdf->khoTrang(true);
        foreach ($khoang as $i => $n) {
            $tq = $du[$n]['tq'];
            $mon = $du[$n]['mon'];
            if ($i > 0) { $pdf->trangMoi(); }
            $pdf->tieuDe('Báo cáo thống kê - ' . ThongKe::nhanKhoang($n), 20);
            $pdf->doan(ThongKe::khoangNgay($n) . ' · ' . $luc, 10, false, '#5b6b8c');
            $pdf->khoang(8);
            if ($tq) { $pdf->oSoLieu($tq['the']); }
            $anh = [];
            if ($du[$n]['anhTq'])                           { $anh[] = $du[$n]['anhTq']['jpeg']; }
            if ($mon && $mon['ds'] && $du[$n]['anhMon'])    { $anh[] = $du[$n]['anhMon']['jpeg']; }
            if ($anh) { $pdf->anhHang($anh, 230); }
            if ($mon && $mon['ds']) { $pdf->chuThich($mon['ds']); }
            elseif ($mon)           { $pdf->doan('Chưa có trận nào trong ' . ThongKe::nhanKhoang($n) . ' gần nhất.'); }

            // Bảng chi tiết nối tiếp sau trang tóm tắt.
            $pdf->trangMoi();
            $pdf->tieuDe('Chi tiết - ' . ThongKe::nhanKhoang($n) . ' (' . ThongKe::khoangNgay($n) . ')', 15, '#2f6bff');
            $pdf->khoang(4);
            if ($tq) {
                $pdf->bang(['Chỉ số', 'Giá trị', 'So với kỳ trước'], array_merge(
                    $tq['the'],
                    [['Tổng số môn học', (string) $tq['tongMon'], ''], ['Tổng số câu hỏi', (string) $tq['tongCauHoi'], '']]
                ), [3, 2, 2]);
                $dong = [];
                foreach ($tq['nhan'] as $k => $nhan) { $dong[] = [$nhan, (string) $tq['dangky'][$k], (string) $tq['tran'][$k]]; }
                $pdf->bang(['Gom theo ' . $tq['kieu'], 'Người dùng mới', 'Trận đấu'], $dong, [2, 2, 2]);
                $dong = [];
                foreach ($tq['trangThai'] as $ten => $so) { $dong[] = [$ten, (string) $so]; }
                $pdf->bang(['Trận đấu theo trạng thái', 'Số trận'], $dong, [3, 1]);
            }
            if ($mon && $mon['ds']) {
                $dong = [];
                $mauHang = [];
                foreach ($mon['ds'] as $k => $m) {
                    $dong[] = [$m['ten'], (string) $m['so'], $m['phantram'] . '%'];
                    $mauHang[$k] = $m['mau'];
                }
                $dong[] = ['Tổng', (string) $mon['tong'], '100%'];
                $pdf->bang(['Môn học', 'Số trận', 'Tỷ lệ'], $dong, [4, 1, 1], $mauHang);
            }
        }
    }

    // Nhóm 2: Theo loại biểu đồ (A4 dọc).
    if ($coLoai) {
        $pdf->khoTrang(false);
        $pdf->tieuDe('BÁO CÁO THỐNG KÊ - THEO LOẠI BIỂU ĐỒ', 18);
        $pdf->doan($luc . ' · Mốc thời gian: ' . implode(', ', array_map([ThongKe::class, 'nhanKhoang'], $khoang)), 9, false, '#5b6b8c');
        $pdf->khoang(10);
        $dau = true;
        if ($coTq) {
            $dau = false;
            foreach ($khoang as $i => $n) {
                $tq = $du[$n]['tq'];
                if ($i > 0) { $pdf->khoang(10); }
                $pdf->tieuDe('Thống kê tổng quan - ' . ThongKe::nhanKhoang($n) . ' (' . ThongKe::khoangNgay($n) . ')', 15, '#2f6bff');
                $pdf->khoang(4);
                $pdf->bang(['Chỉ số', 'Giá trị', 'So với kỳ trước'], array_merge(
                    $tq['the'],
                    [['Tổng số môn học', (string) $tq['tongMon'], ''], ['Tổng số câu hỏi', (string) $tq['tongCauHoi'], '']]
                ), [3, 2, 2]);
                $pdf->doan('Biểu đồ người dùng mới (cột) và số trận đấu (đường), gom theo ' . $tq['kieu'] . '.', 9, true);
                if ($du[$n]['anhTq']) { $pdf->anh($du[$n]['anhTq']['jpeg']); }
                $dong = [];
                foreach ($tq['nhan'] as $k => $nhan) { $dong[] = [$nhan, (string) $tq['dangky'][$k], (string) $tq['tran'][$k]]; }
                $pdf->bang(['Mốc thời gian', 'Người dùng mới', 'Trận đấu'], $dong, [2, 2, 2]);
                $dong = [];
                foreach ($tq['trangThai'] as $ten => $so) { $dong[] = [$ten, (string) $so]; }
                $pdf->bang(['Trận đấu theo trạng thái', 'Số trận'], $dong, [3, 1]);
            }
        }
        if ($coMon) {
            if (!$dau) { $pdf->trangMoi(); }
            foreach ($khoang as $i => $n) {
                $mon = $du[$n]['mon'];
                if ($i > 0) { $pdf->khoang(10); }
                $pdf->tieuDe('Thống kê theo môn học - ' . ThongKe::nhanKhoang($n) . ' (' . ThongKe::khoangNgay($n) . ')', 15, '#2f6bff');
                $pdf->khoang(4);
                if (!$mon['ds']) {
                    $pdf->doan('Chưa có trận nào trong ' . ThongKe::nhanKhoang($n) . ' gần nhất.');
                    continue;
                }
                if ($du[$n]['anhMon']) { $pdf->anh($du[$n]['anhMon']['jpeg']); }
                $dong = [];
                $mauHang = [];
                foreach ($mon['ds'] as $k => $m) {
                    $dong[] = [$m['ten'], (string) $m['so'], $m['phantram'] . '%'];
                    $mauHang[$k] = $m['mau'];
                }
                $dong[] = ['Tổng', (string) $mon['tong'], '100%'];
                $pdf->bang(['Môn học', 'Số trận', 'Tỷ lệ'], $dong, [4, 1, 1], $mauHang);
            }
        }
    }
    $pdf->tai($tenFile . '.pdf');
} catch (Throwable $ex) {
    loi_xuat('Không tạo được file báo cáo: ' . $ex->getMessage(), 500);
}