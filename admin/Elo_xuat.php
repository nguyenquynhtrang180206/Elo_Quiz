<?php
// Xuất bảng xếp hạng Elo ra Excel/PDF và trả JSON xem trước.
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

// Tính kích thước ảnh theo chiều rộng cố định (giữ tỷ lệ).
function anh_theo_rong(?array $a, int $rong, int $x = 0): ?array
{
    if (!$a) { return null; }
    return ['jpeg' => $a['jpeg'], 'rong' => $rong, 'cao' => (int) round($a['cao'] * $rong / $a['rong']), 'x' => $x];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(BASE_URL . '/admin/nguoidung.php#bxh');
}
csrf_check();
@set_time_limit(120);

$TOI_DA_XLSX = 10000;
$TOI_DA_PDF  = 3000;
$SO_DONG_XEM = 10;

// Cấu hình cột xuất và tỉ lệ độ rộng trong PDF.
$COT = [
    'hang'   => ['Hạng',         0.8],
    'ten'    => ['Họ tên',       2.6],
    'email'  => ['Email',        3.4],
    'bac'    => ['Bậc',          1.4],
    'elo'    => ['Elo',          1.1],
    'sotran' => ['Số trận',      1.1],
    'tyle'   => ['Tỷ lệ thắng',  1.5],
    'tt'     => ['Trạng thái',   1.7],
];

$pdo = Database::pdo();

// Tham số từ hộp thoại xuất.
$monHoc = $pdo->query('SELECT MaMon, TenMon FROM MONHOC ORDER BY TenMon')->fetchAll();
$tenMon = array_column($monHoc, 'TenMon', 'MaMon');
$mon    = (int) ($_POST['mon'] ?? 0);
if ($mon !== 0 && !isset($tenMon[$mon])) {
    loi_xuat('Môn học không hợp lệ.');
}
$top = (int) ($_POST['top'] ?? 0);
if (!in_array($top, [0, 10, 50, 100], true)) { $top = 0; }

$buoc     = (string) ($_POST['buoc'] ?? '');
$dinhDang = (string) ($_POST['dinhdang'] ?? '');
if ($buoc === 'xemtruoc') { $dinhDang = 'excel'; }
if (!in_array($dinhDang, ['excel', 'pdf'], true)) {
    loi_xuat('Hãy chọn định dạng Excel hoặc PDF.');
}
$truongGui = is_array($_POST['truong'] ?? null) ? array_map('strval', $_POST['truong']) : [];
$truong    = array_values(array_intersect(array_keys($COT), $truongGui));   // giữ thứ tự cột cố định
$NOI_DUNG  = ['ds' => 'Bảng xếp hạng', 'bac' => 'Sơ đồ phân bố theo bậc', 'pho' => 'Sơ đồ phân bố điểm Elo'];
$noiDung   = array_values(array_intersect(array_keys($NOI_DUNG), is_array($_POST['noidung'] ?? null) ? array_map('strval', $_POST['noidung']) : []));
$coDs  = in_array('ds', $noiDung, true);
$coBac = in_array('bac', $noiDung, true);
$coPho = in_array('pho', $noiDung, true);
if ($buoc !== 'xemtruoc') {
    if (!$noiDung)         { loi_xuat('Hãy chọn ít nhất một nội dung cần xuất.'); }
    if ($coDs && !$truong) { loi_xuat('Hãy chọn ít nhất một cột cho bảng xếp hạng.'); }
}

// Bậc rank tính theo ELO_KHOI_TAO, không lưu CSDL.
$eloKt   = Config::int('ELO_KHOI_TAO', 1000);
$bacRank = [
    ['ten' => 'Cao thủ',   'tu' => $eloKt + 450, 'mau' => '#c13cff'],
    ['ten' => 'Kim cương', 'tu' => $eloKt + 300, 'mau' => '#2f8bff'],
    ['ten' => 'Vàng',      'tu' => $eloKt + 150, 'mau' => '#f5a524'],
    ['ten' => 'Bạc',       'tu' => $eloKt + 50,  'mau' => '#94a3b8'],
    ['ten' => 'Đồng',      'tu' => PHP_INT_MIN,  'mau' => '#b9733a'],
];
$bacCua = static function (int $elo) use ($bacRank): array {
    foreach ($bacRank as $b) {
        if ($elo >= $b['tu']) { return $b; }
    }
    return end($bacRank);
};
$ty = static fn (int $thang, int $tran): string => $tran > 0 ? number_format($thang * 100 / $tran, 1) . '%' : '—';

$nMin = max(1, Config::int('SO_TRAN_TOI_THIEU_BXH', 10));

// Truy vấn bảng xếp hạng theo môn hoặc toàn trường.
if ($mon > 0) {
    $from  = 'FROM ELOMONHOC e JOIN NGUOIDUNG n ON n.MaND = e.MaND WHERE e.MaMon = ?';
    $tham  = [$mon];
    $cot   = 'n.MaND, n.HoTen, n.Email, n.TrangThai, e.DiemElo AS elo, e.SoTran, e.SoThang';
    $order = 'ORDER BY e.DiemElo DESC, e.SoTran DESC, n.MaND';
    $sqlDem = "SELECT COUNT(*) $from";
    $cotElo = 'e.DiemElo AS elo';
} else {
    $from  = 'FROM ELOMONHOC e JOIN NGUOIDUNG n ON n.MaND = e.MaND WHERE e.SoTran >= ? GROUP BY n.MaND, n.HoTen, n.Email, n.TrangThai';
    $tham  = [$nMin];
    $cot   = 'n.MaND, n.HoTen, n.Email, n.TrangThai, ROUND(AVG(e.DiemElo)) AS elo, SUM(e.SoTran) AS SoTran, SUM(e.SoThang) AS SoThang';
    $order = 'ORDER BY AVG(e.DiemElo) DESC, SUM(e.SoTran) DESC, n.MaND';
    $sqlDem = "SELECT COUNT(*) FROM (SELECT n.MaND $from) x";
    $cotElo = 'AVG(e.DiemElo) AS elo';
}
$tenBxh = $mon > 0 ? 'môn ' . $tenMon[$mon] : 'toàn trường';

try {
    $st = $pdo->prepare($sqlDem);
    $st->execute($tham);
    $tong   = (int) $st->fetchColumn();
    $soXuat = $top > 0 ? min($tong, $top) : $tong;

    // Thống kê phân bố Elo theo bậc và khoảng điểm.
    $st = $pdo->prepare("SELECT $cotElo $from LIMIT 20000");
    $st->execute($tham);
    $moiElo = array_map('intval', array_map('round', array_map('floatval', $st->fetchAll(PDO::FETCH_COLUMN))));
    $phanBo = array_fill(0, count($bacRank), 0);
    foreach ($moiElo as $gt) {
        $b = $bacCua($gt);
        foreach ($bacRank as $i => $bb) {
            if ($bb['ten'] === $b['ten']) { $phanBo[$i]++; break; }
        }
    }
    $tb  = $moiElo ? (int) round(array_sum($moiElo) / count($moiElo)) : 0;
    $max = $moiElo ? max($moiElo) : 0;
    $pho = ['nhan' => [], 'so' => [], 'mau' => []];
    if ($moiElo) {
        $buoc2 = ($max - min($moiElo)) > 1500 ? 200 : 100;
        for ($x = (int) (floor(min($moiElo) / $buoc2) * $buoc2); $x <= $max; $x += $buoc2) {
            $c = 0;
            foreach ($moiElo as $gt) {
                if ($gt >= $x && $gt < $x + $buoc2) { $c++; }
            }
            $pho['nhan'][] = $x . '–' . ($x + $buoc2 - 1);
            $pho['so'][]   = $c;
            $pho['mau'][]  = $bacCua($x + intdiv($buoc2, 2))['mau'];
        }
    }
    $dsBac = [];
    foreach ($bacRank as $i => $b) {
        $dsBac[] = ['ten' => $b['ten'], 'so' => $phanBo[$i], 'mau' => $b['mau'], 'tu' => $b['tu'] === PHP_INT_MIN ? null : $b['tu']];
    }

    // Lấy danh sách dòng (xem trước hoặc xuất file).
    $gioiHan = $buoc === 'xemtruoc' ? $SO_DONG_XEM : (($dinhDang === 'pdf' ? $TOI_DA_PDF : $TOI_DA_XLSX) + 1);
    if ($top > 0) { $gioiHan = min($gioiHan, $top); }
    $st = $pdo->prepare("SELECT $cot $from $order LIMIT " . (int) $gioiHan);
    $st->execute($tham);
    $nguoi = $st->fetchAll();

    $dongDu = [];
    foreach ($nguoi as $i => $r) {
        $elo = (int) $r['elo'];
        $dongDu[] = [
            'hang'   => $i + 1,
            'ten'    => (string) $r['HoTen'],
            'email'  => (string) $r['Email'],
            'bac'    => $bacCua($elo)['ten'],
            'elo'    => $elo,
            'sotran' => (int) $r['SoTran'],
            'tyle'   => $ty((int) $r['SoThang'], (int) $r['SoTran']),
            'tt'     => (string) $r['TrangThai'],
        ];
    }

    // Trả JSON xem trước.
    if ($buoc === 'xemtruoc') {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode([
            'tong'      => $tong,
            'soXuat'    => $soXuat,
            'tb'        => $tb,
            'max'       => $max,
            'tenBxh'    => $tenBxh,
            'bac'       => $dsBac,
            'pho'       => $pho,
            'dong'      => $dongDu,
            'toiDaXlsx' => $TOI_DA_XLSX,
            'toiDaPdf'  => $TOI_DA_PDF,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    // Xuất file Excel/PDF.
    if ($tong === 0) {
        loi_xuat('Chưa có người chơi nào được xếp hạng trong ' . $tenBxh . '.');
    }
    $gh = $dinhDang === 'pdf' ? $TOI_DA_PDF : $TOI_DA_XLSX;
    if ($coDs && $soXuat > $gh) {
        loi_xuat('Có ' . number_format($soXuat, 0, ',', '.') . ' người, vượt giới hạn ' . number_format($gh, 0, ',', '.')
            . ' dòng của file ' . ($dinhDang === 'pdf' ? 'PDF' : 'Excel') . '. Hãy chọn Top nhỏ hơn rồi xuất lại.');
    }

    $tieuDe = [];
    foreach ($truong as $k) { $tieuDe[] = $COT[$k][0]; }
    $dong = [];
    foreach ($dongDu as $r) {
        $d = [];
        foreach ($truong as $k) { $d[] = $r[$k]; }
        $dong[] = $d;
    }

    $tenFile = 'bxh_' . date('Ymd_His');
    $anhBac  = $coBac ? anh_bieu_do('anh_bac') : null;
    $anhPho  = $coPho ? anh_bieu_do('anh_pho') : null;
    $luc     = 'BXH ' . $tenBxh . ($top > 0 ? ' · Top ' . $top : ' · toàn bộ') . ' · Xuất lúc ' . date('d/m/Y H:i');
    $tongNhom = max(1, array_sum($phanBo));

    if ($dinhDang === 'excel') {
        $ghiChu = static fn (string $t): array => ['kieu' => 0, 'o' => [$t]];
        $sheets = [];
        if ($coBac || $coPho) {
            $h = [['kieu' => 2, 'o' => ['Bảng xếp hạng - Sơ đồ']], $ghiChu($luc)];
            if ($coBac) {
                $h[] = ['kieu' => 0, 'o' => []];
                $h[] = ['kieu' => 2, 'o' => ['Phân bố theo bậc']];
                if ($anhBac) { $h[] = ['anh' => [anh_theo_rong($anhBac, 320)]]; }
                $h[] = ['kieu' => 6, 'o' => ['Bậc', 'Số người', 'Tỷ lệ', 'Từ Elo']];
                foreach ($dsBac as $b) {
                    $h[] = ['kieu' => 0, 'o' => [$b['ten'], $b['so'], round($b['so'] * 100 / $tongNhom) . '%', $b['tu'] ?? '—']];
                }
            }
            if ($coPho) {
                $h[] = ['kieu' => 0, 'o' => []];
                $h[] = ['kieu' => 2, 'o' => ['Phân bố điểm Elo']];
                if ($anhPho) { $h[] = ['anh' => [anh_theo_rong($anhPho, 720)]]; }
                $h[] = ['kieu' => 6, 'o' => ['Khoảng Elo', 'Số người']];
                foreach ($pho['nhan'] as $i => $nhan) { $h[] = ['kieu' => 0, 'o' => [$nhan, $pho['so'][$i]]]; }
            }
            $sheets[] = ['ten' => 'Sơ đồ', 'rongToiThieu' => 14, 'dong' => $h];
        }
        if ($coDs) {
            $h = [['kieu' => 2, 'o' => ['Bảng xếp hạng']], $ghiChu($luc), ['kieu' => 0, 'o' => []], ['kieu' => 6, 'o' => $tieuDe]];
            foreach ($dong as $d) { $h[] = ['kieu' => 0, 'o' => $d]; }
            $sheets[] = ['ten' => 'Bảng xếp hạng', 'rongToiThieu' => 12, 'dong' => $h];
        }
        XlsxWriter::taiNhieuSheet($tenFile . '.xlsx', $sheets);
    }

    // PDF dùng khổ A4 ngang khi bảng nhiều cột.
    $pdf = new PdfWriter(__DIR__ . '/../assets/fonts');
    $pdf->khoTrang($coDs && count($tieuDe) > 6);
    $pdf->tieuDe($coDs ? 'Bảng xếp hạng Elo' : 'Thống kê xếp hạng Elo', 20);
    $pdf->doan($luc, 10, false, '#5b6b8c');
    $pdf->khoang(8);
    $pdf->oSoLieu([
        ['Người chơi được xếp hạng', number_format($tong, 0, ',', '.'), ''],
        ['Elo trung bình',           number_format($tb, 0, ',', '.'), ''],
        ['Elo cao nhất',             number_format($max, 0, ',', '.'), ''],
    ]);
    $anhPdf = array_values(array_filter([$anhBac['jpeg'] ?? null, $anhPho['jpeg'] ?? null]));
    if ($anhPdf) { $pdf->anhHang($anhPdf, 230); }
    if (!$coDs) { $pdf->tai($tenFile . '.pdf'); }

    $tyLe = [];
    $canGiua = [];
    $mauO = [];
    $viTriTT = null;
    foreach ($truong as $k) {
        if ($k === 'tt') { $viTriTT = count($tyLe); }
        if (in_array($k, ['hang', 'elo', 'sotran', 'tyle', 'tt', 'bac'], true)) { $canGiua[] = count($tyLe); }
        $tyLe[] = $COT[$k][1];
    }
    $dongPdf = [];
    foreach ($dong as $i => $d) {
        $o = [];
        foreach ($d as $v) { $o[] = $v === null ? '—' : (string) $v; }
        $dongPdf[] = $o;
        if ($viTriTT !== null) {
            $mauO[$i] = [$viTriTT => ($d[$viTriTT] ?? '') === 'Bị khóa' ? '#fde3e6' : '#dcf5ea'];
        }
    }
    $pdf->khoang(6);
    $pdf->bang($tieuDe, $dongPdf, $tyLe, [], $mauO, $canGiua);
    $pdf->tai($tenFile . '.pdf');
} catch (Throwable $ex) {
    loi_xuat('Không tạo được file xuất: ' . $ex->getMessage(), 500);
}
