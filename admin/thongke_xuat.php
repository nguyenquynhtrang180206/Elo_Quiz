<?php
// Xuất báo cáo thống kê (Excel / PDF) cho Thống kê tổng quan và Thống kê theo môn học, theo nhiều mốc thời gian.
// Được gọi (POST, có CSRF) từ hộp thoại "Xuất" ở admin/index.php sau khi người dùng đã xem trước và bấm xác nhận.
// Ảnh biểu đồ do trình duyệt chụp từ chính biểu đồ trong phần xem trước (JPEG, nền trắng) nên file xuất có màu giống màn hình.
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/ThongKe.php';
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
    redirect(BASE_URL . '/admin/index.php');
}
csrf_check();

// ---- Hộp thoại: trang nào, mốc thời gian nào, định dạng nào ----
$chon = array_values(array_intersect(['tongquan', 'mon'], (array) ($_POST['trang'] ?? [])));
if (!$chon) {
    loi_xuat('Hãy chọn ít nhất một trang thống kê để xuất.');
}
$khoang = ThongKe::locKhoang($_POST['ngay'] ?? []);
if (!$khoang) {
    loi_xuat('Hãy chọn ít nhất một mốc thời gian.');
}
$dinhDang = (string) ($_POST['dinhdang'] ?? '');
if (!in_array($dinhDang, ['excel', 'pdf'], true)) {
    loi_xuat('Hãy chọn định dạng Excel hoặc PDF.');
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

// Ảnh dùng trong Excel: rộng tối đa $toiDa pixel, giữ tỷ lệ
function anh_excel(?array $a, int $toiDa): array
{
    if (!$a) { return []; }
    $w = min($toiDa, $a['rong']);
    return ['anh' => ['jpeg' => $a['jpeg'], 'rong' => $w, 'cao' => (int) round($a['cao'] * $w / $a['rong'])]];
}

$pdo = Database::pdo();
$du = [];   // [mốc] => ['tq' => ..., 'mon' => ..., 'anhTq' => ..., 'anhMon' => ...]
foreach ($khoang as $n) {
    $du[$n] = [
        'tq'     => in_array('tongquan', $chon, true) ? ThongKe::tongQuan($pdo, $n) : null,
        'mon'    => in_array('mon', $chon, true)      ? ThongKe::theoMon($pdo, $n)  : null,
        'anhTq'  => in_array('tongquan', $chon, true) ? anh_bieu_do('anh_tongquan_' . $n) : null,
        'anhMon' => in_array('mon', $chon, true)      ? anh_bieu_do('anh_mon_' . $n)      : null,
    ];
}

$luc     = 'Xuất lúc ' . date('H:i d/m/Y');
$tenFile = 'thongke_' . date('Ymd_His');

try {
    if ($dinhDang === 'excel') {
        // Mỗi (trang thống kê x mốc thời gian) là một sheet riêng. Thứ tự: trang trước, mốc từ ngắn đến dài.
        $b = static fn (array $o): array => ['kieu' => 1, 'o' => $o];
        $t = static fn (array $o): array => ['kieu' => 0, 'o' => $o];
        $trang = ['kieu' => 0, 'o' => []];
        $sheets = [];

        if (in_array('tongquan', $chon, true)) {
            foreach ($khoang as $n) {
                $tq = $du[$n]['tq'];
                $d = [['kieu' => 2, 'o' => ['Thống kê tổng quan - ' . ThongKe::nhanKhoang($n)]],
                      $t([$luc . ' · Khoảng thời gian: ' . ThongKe::nhanKhoang($n) . ' gần nhất']), $trang,
                      $b(['Chỉ số', 'Giá trị', 'So với kỳ trước'])];
                foreach ($tq['the'] as $r) { $d[] = $t($r); }
                $d[] = $t(['Tổng số môn học', $tq['tongMon'], '']);
                $d[] = $t(['Tổng số câu hỏi', $tq['tongCauHoi'], '']);
                $d[] = $trang;
                $d[] = $b(['Gom theo ' . $tq['kieu'], 'Người dùng mới', 'Trận đấu']);
                foreach ($tq['nhan'] as $i => $nhan) { $d[] = $t([$nhan, $tq['dangky'][$i], $tq['tran'][$i]]); }
                $d[] = $trang;
                $d[] = $b(['Trận đấu theo trạng thái', 'Số trận']);
                foreach ($tq['trangThai'] as $ten => $so) { $d[] = $t([$ten, $so]); }
                $d[] = $trang;
                $d[] = $b(['Biểu đồ']);
                $sheets[] = ['ten' => 'Tổng quan - ' . ThongKe::nhanKhoang($n), 'dong' => $d] + anh_excel($du[$n]['anhTq'], 720);
            }
        }
        if (in_array('mon', $chon, true)) {
            foreach ($khoang as $n) {
                $mon = $du[$n]['mon'];
                $d = [['kieu' => 2, 'o' => ['Thống kê theo môn học - ' . ThongKe::nhanKhoang($n)]],
                      $t([$luc . ' · Khoảng thời gian: ' . ThongKe::nhanKhoang($n) . ' gần nhất']), $trang,
                      $b(['Môn học', 'Số trận', 'Tỷ lệ (%)'])];
                foreach ($mon['ds'] as $m) { $d[] = $t([$m['ten'], $m['so'], $m['phantram']]); }
                if (!$mon['ds']) { $d[] = $t(['Chưa có trận nào trong khoảng này.']); }
                else             { $d[] = $b(['Tổng', $mon['tong'], '']); }
                $d[] = $trang;
                $d[] = $b(['Biểu đồ']);
                $sheets[] = ['ten' => 'Theo môn học - ' . ThongKe::nhanKhoang($n), 'dong' => $d] + anh_excel($du[$n]['anhMon'], 520);
            }
        }
        XlsxWriter::taiNhieuSheet($tenFile . '.xlsx', $sheets);
    }

    // ---- PDF: khổ dọc, một luồng dọc duy nhất ----
    $pdf = new PdfWriter(__DIR__ . '/../assets/fonts');
    $pdf->tieuDe('BÁO CÁO THỐNG KÊ', 20);
    $pdf->doan($luc . ' · Mốc thời gian: ' . implode(', ', array_map([ThongKe::class, 'nhanKhoang'], $khoang)), 9, false, '#5b6b8c');
    $pdf->khoang(10);
    $dau = true;
    if (in_array('tongquan', $chon, true)) {
        $dau = false;
        foreach ($khoang as $i => $n) {
            $tq = $du[$n]['tq'];
            if ($i > 0) { $pdf->khoang(10); }
            $pdf->tieuDe('Thống kê tổng quan - ' . ThongKe::nhanKhoang($n), 15, '#2f6bff');
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
    if (in_array('mon', $chon, true)) {
        if (!$dau) { $pdf->trangMoi(); }
        foreach ($khoang as $i => $n) {
            $mon = $du[$n]['mon'];
            if ($i > 0) { $pdf->khoang(10); }
            $pdf->tieuDe('Thống kê theo môn học - ' . ThongKe::nhanKhoang($n), 15, '#2f6bff');
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
    $pdf->tai($tenFile . '.pdf');
} catch (Throwable $ex) {
    loi_xuat('Không tạo được file báo cáo: ' . $ex->getMessage(), 500);
}