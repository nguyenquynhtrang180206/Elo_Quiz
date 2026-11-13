<?php
// Xuất báo cáo thống kê khảo sát sinh viên (Excel hoặc PDF).
// Được gọi (POST, có CSRF) từ hộp thoại "Xuất" ở admin/khaosat.php.
// Nhận các trường: truong[], cot[], tu, den, dianhdang, kemBieuDo, và ảnh biểu đồ JPEG base64.
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/KhaoSatThongKe.php';
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
    redirect(BASE_URL . '/admin/khaosat.php');
}
csrf_check();

// ---- Tham số từ form ----
[$tu, $den] = KhaoSatThongKe::khoang($_POST);

$truong = is_array($_POST['truong'] ?? null) ? array_map('strval', $_POST['truong']) : [];
$truong = array_values(array_intersect(array_keys(KhaoSatThongKe::TRUONG), $truong));
if (!$truong) {
    loi_xuat('Hãy chọn ít nhất một nội dung cần xuất.');
}

$cot = is_array($_POST['cot'] ?? null) ? array_map('strval', $_POST['cot']) : [];
$cot = array_values(array_intersect(array_keys(KhaoSatThongKe::COT_SV), $cot));

$kemBieuDo = !empty($_POST['kemBieuDo']);

$dinhDang = (string) ($_POST['dinhdang'] ?? 'excel');
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

// Ảnh dùng trong Excel, rộng $rong pixel (giữ tỷ lệ)
function anh_theo_rong(?array $a, int $rong, int $x = 0): ?array
{
    if (!$a) { return null; }
    return ['jpeg' => $a['jpeg'], 'rong' => $rong, 'cao' => (int) round($a['cao'] * $rong / $a['rong']), 'x' => $x];
}

$anhMucTieu = anh_bieu_do('anh_mucTieu');
$anhLinhVuc = anh_bieu_do('anh_linhVuc');
$anhTrinhDo = anh_bieu_do('anh_trinhDo');

$pdo = Database::pdo();
$duLieu = KhaoSatThongKe::tinh($pdo, $tu, $den);

$luc = 'Xuất lúc ' . date('H:i d/m/Y');
$tenFile = 'thongke_khaosat_' . date('Ymd_His');

$cacKhaoSat = [
    'mucTieu' => ['tieuDe' => 'Mục tiêu tham gia Elo_Quiz', 'anh' => $anhMucTieu],
    'linhVuc' => ['tieuDe' => 'Lĩnh vực yêu thích',           'anh' => $anhLinhVuc],
    'trinhDo' => ['tieuDe' => 'Trình độ tự đánh giá',         'anh' => $anhTrinhDo],
];

try {
    if ($dinhDang === 'excel') {
        $t  = static fn (array $o): array => ['kieu' => 0, 'o' => $o];
        $b  = static fn (array $o): array => ['kieu' => 1, 'o' => $o];
        $th = static fn (array $o): array => ['kieu' => 6, 'o' => $o];
        $tdl = static fn (string $o): array => ['kieu' => 2, 'o' => [$o]];
        $trong = ['kieu' => 0, 'o' => []];

        $dong = [
            $tdl('BÁO CÁO THỐNG KÊ KHẢO SÁT SINH VIÊN'),
            $t(['Khoảng ngày: ' . $duLieu['khoang'] . ' · ' . $luc]),
            $trong,
        ];

        if (in_array('the', $truong, true)) {
            $dong[] = ['kieu' => 3, 'o' => array_column($duLieu['the'], 0)];
            $dong[] = ['kieu' => 4, 'o' => array_column($duLieu['the'], 1)];
            $dong[] = ['kieu' => 5, 'o' => array_map(static fn ($r) => (string) $r[2], $duLieu['the'])];
            $dong[] = $trong;
        }

        foreach ($cacKhaoSat as $maKS => $info) {
            if (in_array($maKS, $truong, true)) {
                $sub = $duLieu[$maKS];
                $dong[] = $b([$info['tieuDe'] . ' (' . array_sum($sub['luot']) . ' lượt chọn)']);
                if ($kemBieuDo && $info['anh']) {
                    $aRong = anh_theo_rong($info['anh'], 600);
                    if ($aRong) {
                        $dong[] = ['anh' => [$aRong]];
                        $soDongTrong = max(1, (int) ceil($aRong['cao'] / 20));
                        for ($si = 0; $si < $soDongTrong; $si++) {
                            $dong[] = $trong;
                        }
                    }
                }
                $dong[] = $th(['Lựa chọn', 'Lượt chọn', 'Tỷ lệ']);
                foreach ($sub['nhan'] as $i => $nhan) {
                    $dong[] = $t([$nhan, (int) $sub['luot'][$i], $sub['tyLe'][$i] . '%']);
                }
                $dong[] = $trong;
            }
        }

        if (in_array('chiTiet', $truong, true)) {
            $dong[] = $b(['Chi tiết câu hỏi khảo sát (Tỷ lệ tính trên ' . $duLieu['hoanTat'] . ' sinh viên hoàn tất)']);
            $dong[] = $th(['Câu hỏi', 'Đáp án nhiều nhất', 'Số lượng', 'Tỷ lệ']);
            foreach ($duLieu['chiTiet'] as $c) {
                $dong[] = $t([$c['cau'], $c['dapAn'], (int) $c['so'], $c['tyLe'] . '%']);
            }
            $dong[] = $trong;
        }

        if (in_array('sinhVien', $truong, true)) {
            $cotChon = $cot ?: array_keys(KhaoSatThongKe::COT_SV);
            $dong[] = $b(['Danh sách sinh viên (' . count($duLieu['sinhVien']) . ' sinh viên)']);
            $dong[] = $th(array_map(static fn ($k) => KhaoSatThongKe::COT_SV[$k] ?? $k, $cotChon));
            foreach ($duLieu['sinhVien'] as $sv) {
                $dong[] = $t(array_map(static fn ($k) => $sv[$k] ?? '—', $cotChon));
            }
            $dong[] = $trong;
        }

        XlsxWriter::taiNhieuSheet($tenFile . '.xlsx', [
            ['ten' => 'Thống kê khảo sát', 'rongToiThieu' => 16, 'dong' => $dong]
        ]);
        exit;
    }

    // ---- PDF ----
    $pdf = new PdfWriter(__DIR__ . '/../assets/fonts');
    $pdf->khoTrang(false);
    $pdf->tieuDe('BÁO CÁO THỐNG KÊ KHẢO SÁT', 18);
    $pdf->doan('Khoảng ngày: ' . $duLieu['khoang'] . ' · ' . $luc, 10, false, '#5b6b8c');
    $pdf->khoang(8);

    if (in_array('the', $truong, true)) {
        $pdf->oSoLieu($duLieu['the']);
    }

    foreach ($cacKhaoSat as $maKS => $info) {
        if (in_array($maKS, $truong, true)) {
            $sub = $duLieu[$maKS];
            $pdf->tieuDe($info['tieuDe'] . ' (' . array_sum($sub['luot']) . ' lượt chọn)', 13, '#2f6bff');
            if ($kemBieuDo && $info['anh']) {
                $pdf->anh($info['anh']['jpeg']);
            }
            $hang = [];
            foreach ($sub['nhan'] as $i => $nhan) {
                $hang[] = [$nhan, (string) $sub['luot'][$i], $sub['tyLe'][$i] . '%'];
            }
            $pdf->bang(['Lựa chọn', 'Lượt chọn', 'Tỷ lệ'], $hang, [4, 1, 1]);
            $pdf->khoang(6);
        }
    }

    if (in_array('chiTiet', $truong, true)) {
        $pdf->tieuDe('Chi tiết câu hỏi khảo sát', 13, '#2f6bff');
        $hang = [];
        foreach ($duLieu['chiTiet'] as $c) {
            $hang[] = [$c['cau'], (string) $c['dapAn'], (string) $c['so'], $c['tyLe'] . '%'];
        }
        $pdf->bang(['Câu hỏi', 'Đáp án nhiều nhất', 'Số lượng', 'Tỷ lệ'], $hang, [4, 3, 1, 1]);
        $pdf->khoang(6);
    }

    if (in_array('sinhVien', $truong, true)) {
        $cotChon = $cot ?: array_keys(KhaoSatThongKe::COT_SV);
        $pdf->tieuDe('Danh sách sinh viên (' . count($duLieu['sinhVien']) . ' sinh viên)', 13, '#2f6bff');
        $tieuDeCot = array_map(static fn ($k) => KhaoSatThongKe::COT_SV[$k] ?? $k, $cotChon);
        $hang = [];
        foreach ($duLieu['sinhVien'] as $sv) {
            $hang[] = array_map(static fn ($k) => (string) ($sv[$k] ?? '—'), $cotChon);
        }
        $tyLe = array_fill(0, count($cotChon), 2);
        if (in_array('hoTen', $cotChon, true)) { $tyLe[array_search('hoTen', $cotChon, true)] = 3; }
        if (in_array('mucTieu', $cotChon, true)) { $tyLe[array_search('mucTieu', $cotChon, true)] = 3; }
        if (in_array('linhVuc', $cotChon, true)) { $tyLe[array_search('linhVuc', $cotChon, true)] = 3; }
        $pdf->bang($tieuDeCot, $hang, $tyLe);
    }

    $pdf->tai($tenFile . '.pdf');
} catch (Throwable $e) {
    loi_xuat('Lỗi khi tạo file: ' . $e->getMessage(), 500);
}
