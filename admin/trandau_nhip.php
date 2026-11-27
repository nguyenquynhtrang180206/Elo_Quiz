<?php
// API trả về số liệu trận đấu theo mốc thời gian (JSON) cho biểu đồ.
require_once __DIR__ . '/../core/bootstrap.php';
Auth::requireAdmin(BASE_URL . '/admin/dangnhap.php');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Cấu hình khoảng thời gian: [tổng giây, bước giây, nhãn đơn vị, định dạng nhãn].
$cauHinh = [
    '1h' => [3600,   300,   '5 phút',  'H:i'],
    '3h' => [10800,  300,   '5 phút',  'H:i'],
    '1d' => [86400,  1800,  '30 phút', 'H:i'],
    '1w' => [604800, 10800, '3 giờ',   'd/m'],
    '1m' => [2592000, 86400, '1 ngày', 'd/m'],
];
$k = strtolower((string) ($_GET['khoang'] ?? '3h'));
$ngayMode = in_array($k, ['d7', 'd30', 'd90', 'tuychon'], true);
if (!$ngayMode && !isset($cauHinh[$k])) {
    $k = '3h';
}
if (!$ngayMode) {
    [$tong, $buoc, $donVi, $dangNhan] = $cauHinh[$k];
}

$cheDo = (string) ($_GET['che_do'] ?? 'batdau');
if (!in_array($cheDo, ['batdau', 'dadau', 'tichluy'], true)) {
    $cheDo = 'batdau';
}
$cot  = $cheDo === 'batdau' ? 't.ThoiGianBatDau' : 't.ThoiGianKetThuc';
$loc  = $cheDo === 'batdau' ? '' : " AND t.TrangThai = 'Kết thúc'";

$pdo    = Database::pdo();
$nguong = TranDauGiamSat::nguongGiay();
[$dkBt, $thamBt] = TranDauGiamSat::dieuKienBatThuong(TranDauGiamSat::coCotTao($pdo), $nguong);

// Chia mốc theo ngày (7/30/90 ngày hoặc tùy chọn).
if ($ngayMode) {
    $loiNgay = static function (string $m): never {
        http_response_code(400);
        exit(json_encode(['loi' => $m], JSON_UNESCAPED_UNICODE));
    };
    if ($k !== 'tuychon') {
        $denD = new DateTime('today');
        $tuD  = (clone $denD)->modify('-' . ((int) substr($k, 1) - 1) . ' days');
    } else {
        $tuD  = DateTime::createFromFormat('!Y-m-d', (string) ($_GET['tu'] ?? ''));
        $denD = DateTime::createFromFormat('!Y-m-d', (string) ($_GET['den'] ?? ''));
        if (!$tuD || !$denD) { $loiNgay('Hãy chọn đủ "Từ ngày" và "Đến ngày".'); }
        if ($tuD > $denD) { [$tuD, $denD] = [$denD, $tuD]; }
        if ($tuD->diff($denD)->days > 365) { $loiNgay('Chỉ chọn được tối đa 366 ngày.'); }
    }
    $tuS = $tuD->format('Y-m-d');
    $denS = $denD->format('Y-m-d');
    $trongN = "$cot >= ? AND $cot < DATE_ADD(?, INTERVAL 1 DAY)$loc";
    $cotGhep = TranDauGiamSat::coCotTao($pdo) ? 'TIMESTAMPDIFF(SECOND, t.ThoiGianTao, NOW(3))' : 'NULL';

    $st = $pdo->prepare("SELECT DATE($cot) AS d, COUNT(*) AS n, COALESCE(SUM($dkBt), 0) AS bt FROM TRANDAU t WHERE $trongN GROUP BY DATE($cot)");
    $st->execute(array_merge($thamBt, [$tuS, $denS]));
    $theoNgay = [];
    foreach ($st->fetchAll() as $r) { $theoNgay[$r['d']] = [(int) $r['n'], (int) $r['bt']]; }

    $st = $pdo->prepare(
        "SELECT t.MaTran, t.TrangThai, t.LyDoKetThuc, DATE($cot) AS d,
                TIMESTAMPDIFF(SECOND, t.ThoiGianBatDau, NOW(3)) AS giay_dien_ra, $cotGhep AS giay_da_ghep
           FROM TRANDAU t WHERE $trongN AND $dkBt ORDER BY t.MaTran DESC LIMIT 400"
    );
    $st->execute(array_merge([$tuS, $denS], $thamBt));
    $co = [];
    foreach ($st->fetchAll() as $r) {
        if (!isset($co[$r['d']])) { $co[$r['d']] = [(int) $r['MaTran'], (string) TranDauGiamSat::nhan($r, $nguong)]; }
    }

    $st = $pdo->prepare("SELECT AVG(TIMESTAMPDIFF(SECOND, t.ThoiGianBatDau, t.ThoiGianKetThuc)) FROM TRANDAU t
                          WHERE t.TrangThai = 'Kết thúc' AND t.ThoiGianKetThuc IS NOT NULL AND t.ThoiGianBatDau IS NOT NULL AND $trongN");
    $st->execute([$tuS, $denS]);
    $tb = $st->fetchColumn();

    $nen = 0;   // Số trận kết thúc trước khoảng đang xem (chế độ tích lũy).
    if ($cheDo === 'tichluy') {
        $st = $pdo->prepare("SELECT COUNT(*) FROM TRANDAU t WHERE t.TrangThai = 'Kết thúc' AND t.ThoiGianKetThuc < ?");
        $st->execute([$tuS]);
        $nen = (int) $st->fetchColumn();
    }
    $diem = []; $chay = $nen;
    foreach (new DatePeriod($tuD, new DateInterval('P1D'), (clone $denD)->modify('+1 day')) as $d) {
        $kd = $d->format('Y-m-d');
        [$n, $bt] = $theoNgay[$kd] ?? [0, 0];
        if ($cheDo === 'tichluy') { $chay += $n; $n = $chay; }
        $diem[] = ['t' => $d->getTimestamp(), 'nhan' => $d->format('d/m'), 'day' => $d->format('d/m/Y'),
                   'n' => $n, 'bt' => $bt, 'ma' => $co[$kd][0] ?? null, 'lydo' => $co[$kd][1] ?? null];
    }
    $so = array_column($diem, 'n');
    $soMoc = count($so);
    echo json_encode([
        'khoang' => $k, 'che_do' => $cheDo, 'don_vi' => 'ngày', 'diem' => $diem,
        'tk' => [
            'hien_tai'   => $soMoc ? $so[$soMoc - 1] : 0,
            'truoc'      => $soMoc > 1 ? $so[$soMoc - 2] : null,
            'cao'        => $soMoc ? max($so) : 0,
            'thap'       => $soMoc ? min($so) : 0,
            'tong'       => $cheDo === 'tichluy' ? ($soMoc ? $so[$soMoc - 1] - $nen : 0) : array_sum($so),
            'bat_thuong' => array_sum(array_column($diem, 'bt')),
            'tb_moc'     => $cheDo === 'tichluy' ? null : ($soMoc ? round(array_sum($so) / $soMoc, 1) : 0),
            'thoi_luong' => TranDauGiamSat::thoiLuong($tb === false ? null : $tb),
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$cuoi = intdiv(time(), $buoc) - 1;                 // Bỏ mốc đang chạy dở gần nhất.
$dau  = $cuoi - intdiv($tong, $buoc) + 1;
$tu   = $dau * $buoc;
$den  = ($cuoi + 1) * $buoc;
$trongKhoang = "$cot >= FROM_UNIXTIME(?) AND $cot < FROM_UNIXTIME(?)$loc";

// Thống kê số trận và trận bất thường theo mốc.
$st = $pdo->prepare(
    "SELECT FLOOR(UNIX_TIMESTAMP($cot) / ?) AS b, COUNT(*) AS n, COALESCE(SUM($dkBt), 0) AS bt
       FROM TRANDAU t WHERE $trongKhoang GROUP BY 1"
);
$st->execute(array_merge([$buoc], $thamBt, [$tu, $den]));
$theoMoc = [];
foreach ($st->fetchAll() as $r) {
    $theoMoc[(int) $r['b']] = [(int) $r['n'], (int) $r['bt']];
}

// Trận bất thường mới nhất trong mỗi mốc (hiển thị tooltip).
$st = $pdo->prepare(
    "SELECT t.MaTran, t.TrangThai, t.LyDoKetThuc,
            FLOOR(UNIX_TIMESTAMP($cot) / ?) AS b,
            TIMESTAMPDIFF(SECOND, t.ThoiGianBatDau, NOW(3)) AS giay_dien_ra,
            " . (TranDauGiamSat::coCotTao($pdo) ? 'TIMESTAMPDIFF(SECOND, t.ThoiGianTao, NOW(3))' : 'NULL') . " AS giay_da_ghep
       FROM TRANDAU t WHERE $trongKhoang AND $dkBt
      ORDER BY t.MaTran DESC LIMIT 300"
);
$st->execute(array_merge([$buoc, $tu, $den], $thamBt));
$co = [];
foreach ($st->fetchAll() as $r) {
    $b = (int) $r['b'];
    if (!isset($co[$b])) {
        $co[$b] = [(int) $r['MaTran'], (string) TranDauGiamSat::nhan($r, $nguong)];
    }
}

// Thời lượng trung bình các trận đã kết thúc.
$st = $pdo->prepare(
    "SELECT AVG(TIMESTAMPDIFF(SECOND, t.ThoiGianBatDau, t.ThoiGianKetThuc))
       FROM TRANDAU t WHERE t.TrangThai = 'Kết thúc' AND t.ThoiGianKetThuc IS NOT NULL AND t.ThoiGianBatDau IS NOT NULL AND $trongKhoang"
);
$st->execute([$tu, $den]);
$tb = $st->fetchColumn();

// Mốc xuất phát cho chế độ tích lũy.
$nen = 0;
if ($cheDo === 'tichluy') {
    $st = $pdo->prepare("SELECT COUNT(*) FROM TRANDAU t WHERE t.TrangThai = 'Kết thúc' AND t.ThoiGianKetThuc < FROM_UNIXTIME(?)");
    $st->execute([$tu]);
    $nen = (int) $st->fetchColumn();
}

// Điền đủ các mốc (mốc trống = 0).
$chay = $nen;
$diem = [];
for ($i = $dau; $i <= $cuoi; $i++) {
    $ts = $i * $buoc;
    [$n, $bt] = $theoMoc[$i] ?? [0, 0];
    if ($cheDo === 'tichluy') {
        $chay += $n;
        $n = $chay;
    }
    $diem[] = [
        't'    => $ts,
        'nhan' => date($dangNhan, $ts),
        'day'  => date('H:i - d/m', $ts),
        'n'    => $n,
        'bt'   => $bt,
        'ma'   => $co[$i][0] ?? null,
        'lydo' => $co[$i][1] ?? null,
    ];
}
$so   = array_column($diem, 'n');
$soMoc = count($so);
echo json_encode([
    'khoang' => $k,
    'che_do' => $cheDo,
    'don_vi' => $donVi,
    'diem'   => $diem,
    'tk'     => [
        'hien_tai'  => $soMoc ? $so[$soMoc - 1] : 0,
        'truoc'     => $soMoc > 1 ? $so[$soMoc - 2] : null,
        'cao'       => $soMoc ? max($so) : 0,
        'thap'      => $soMoc ? min($so) : 0,
        'tong'      => $cheDo === 'tichluy' ? ($soMoc ? $so[$soMoc - 1] - $nen : 0) : array_sum($so),
        'bat_thuong' => array_sum(array_column($diem, 'bt')),
        'tb_moc'    => $cheDo === 'tichluy' ? null : ($soMoc ? round(array_sum($so) / $soMoc, 1) : 0),
        'thoi_luong' => TranDauGiamSat::thoiLuong($tb === false ? null : $tb),
    ],
], JSON_UNESCAPED_UNICODE);