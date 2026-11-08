<?php
// Xuất Ngân hàng câu hỏi ra Excel hoặc PDF.
// Đặt tại: elo_quiz/admin/cauhoi_xuat.php
// Được gọi (POST, có CSRF) từ hộp thoại "Xuất" ở admin/cauhoi.php. Mỗi lần gọi trả MỘT file; khi người dùng chọn
// định dạng "Cả hai", trình duyệt (cauhoi.js) gọi lần lượt hai lần (Excel rồi PDF).
//
// Phạm vi (pham_vi): loc = đang lọc (q, mon, kt, dk, tt, loc gửi kèm từ trang), chon = các câu đã tích (ids[]),
//                    lech = chỉ câu lệch độ khó, tatca = toàn bộ ngân hàng.
// Nội dung (noidung[]): danhsach = danh sách câu hỏi (kèm đáp án đúng, giải thích, tỉ lệ đúng thực tế),
//                       hieuchinh = báo cáo hiệu chỉnh độ khó (ngưỡng, phân bố tỉ lệ đúng, các câu lệch),
//                       bando = bản đồ phủ câu hỏi. Hai mục hieuchinh và bando chọn độc lập, có thể chọn cả hai.
// buoc=xemtruoc: không xuất file mà trả JSON số liệu xem trước của phạm vi (tổng quan, vài dòng đầu, câu lệch, bản đồ phủ,
//                phân bố tỉ lệ đúng). Trình duyệt dùng để dựng khung "Xem trước" và vẽ biểu đồ Chart.js giống hệt trên trang,
//                rồi gửi ảnh JPEG lại trong trường anh_phanbo khi xuất.
//
// Các ngưỡng và công thức tỉ lệ đúng dưới đây phải khớp với admin/cauhoi.php để số trong file giống số trên màn hình.
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/CauHoiHelper.php';
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
    redirect(BASE_URL . '/admin/cauhoi.php');
}
csrf_check();
@set_time_limit(180);

$TOI_DA_CAU = 3000;   // vượt quá thì yêu cầu lọc bớt (tránh làm treo máy chủ)
$TOI_DA_LECH_PDF = 300;

$pdo = Database::pdo();

// ---- Tham số từ hộp thoại ----
$phamVi = (string) ($_POST['pham_vi'] ?? '');
if (!in_array($phamVi, ['loc', 'chon', 'lech', 'tatca'], true)) {
    loi_xuat('Hãy chọn phạm vi cần xuất.');
}
$noiDung = array_values(array_intersect(['danhsach', 'hieuchinh', 'bando'],
    is_array($_POST['noidung'] ?? null) ? array_map('strval', $_POST['noidung']) : []));
if (!$noiDung) {
    loi_xuat('Hãy chọn ít nhất một nội dung cần xuất.');
}
$coDS = in_array('danhsach', $noiDung, true);
$coHC = in_array('hieuchinh', $noiDung, true);
$coBD = in_array('bando', $noiDung, true);   // bản đồ phủ do người dùng tự tích (hộp thoại tự gợi ý tích khi chọn báo cáo hiệu chỉnh)
$buoc = (string) ($_POST['buoc'] ?? '');
$dinhDang = (string) ($_POST['dinhdang'] ?? '');
if ($buoc === 'xemtruoc') { $dinhDang = 'excel'; }   // xem trước không cần định dạng
if (!in_array($dinhDang, ['excel', 'pdf'], true)) {
    loi_xuat('Hãy chọn định dạng Excel hoặc PDF.');
}

// ---- Ngưỡng (khớp với cauhoi.php) ----
$MIN_LUOT   = min(200, max(1, Config::int('MIN_LUOT_DO_KHO', 2)));
$NGUONG_DE  = 70;
$NGUONG_KHO = 40;
$PHU_DU     = 20;
$PHU_TOT    = 40;
$NGUONG_DO  = min($PHU_DU, max(1, Config::int('SO_CAU', 10)));
$DO_KHO     = CauHoiHelper::DO_KHO;   // ['Dễ', 'Trung bình', 'Khó']

$hangTheoTyLe = static function (int $dung, int $luot) use ($NGUONG_DE, $NGUONG_KHO): string {
    if ($dung * 100 >= $NGUONG_DE * $luot) { return 'Dễ'; }
    if ($dung * 100 < $NGUONG_KHO * $luot) { return 'Khó'; }
    return 'Trung bình';
};
// Mức lệch (điểm phần trăm so với khoảng đúng của nhãn): như $sqlMucLech trong cauhoi.php
$mucLech = static function (string $nhan, float $ty) use ($NGUONG_DE, $NGUONG_KHO): float {
    if ($nhan === 'Dễ')  { return $NGUONG_DE - $ty; }
    if ($nhan === 'Khó') { return $ty - $NGUONG_KHO; }
    return $ty >= $NGUONG_DE ? $ty - $NGUONG_DE : $NGUONG_KHO - $ty;
};

// ---- Mảnh SQL dùng chung (khớp với cauhoi.php) ----
$tlSub = "(SELECT t.MaCauHoi, COUNT(*) AS luot, SUM(d.LaDapAnDung = 1) AS dung
           FROM CHITIETTRALOI t LEFT JOIN DAPAN d ON d.MaDapAn = t.MaDapAn
           WHERE t.TrangThai IN ('Đã trả lời', 'Hết giờ') GROUP BY t.MaCauHoi)";
$dungSub = '(SELECT DISTINCT MaCauHoi FROM CAUHOITRANDAU)';
$joinTL  = "LEFT JOIN $tlSub tl ON tl.MaCauHoi = c.MaCauHoi LEFT JOIN $dungSub u ON u.MaCauHoi = c.MaCauHoi";
$sqlLech = "(tl.luot >= $MIN_LUOT AND (
              (c.DoKho = 'Dễ' AND tl.dung * 100 < $NGUONG_DE * tl.luot)
           OR (c.DoKho = 'Khó' AND tl.dung * 100 >= $NGUONG_KHO * tl.luot)
           OR (c.DoKho = 'Trung bình' AND (tl.dung * 100 >= $NGUONG_DE * tl.luot OR tl.dung * 100 < $NGUONG_KHO * tl.luot))))";

// ---- Điều kiện theo phạm vi ----
$dieuKien = [];
$tham     = [];
$moTaPhamVi = '';
$monLoc = 0;   // môn đang lọc (để bản đồ phủ chỉ hiện môn đó)
$tenMonLoc = '';

if ($phamVi === 'loc') {
    $q   = trim((string) ($_POST['q'] ?? ''));
    $mon = (int) ($_POST['mon'] ?? 0);
    $kt  = (string) ($_POST['kt'] ?? '');
    $dk  = (string) ($_POST['dk'] ?? '');
    $tt  = (string) ($_POST['tt'] ?? '');
    $loc = (string) ($_POST['loc'] ?? '');
    if (!in_array($dk, $DO_KHO, true)) { $dk = ''; }
    if (!in_array($tt, ['Hiển thị', 'Đã ẩn'], true)) { $tt = ''; }
    if (!in_array($loc, ['lech', 'chua'], true)) { $loc = ''; }
    if ($kt !== 'chung' && !ctype_digit($kt)) { $kt = ''; }

    $parts = [];
    if ($q !== '') {
        $like = '%' . addcslashes($q, '\\%_') . '%';
        if (preg_match('/^#?(\d{1,9})$/', $q, $mm)) {
            $dieuKien[] = '(c.NoiDung LIKE ? OR c.MaCauHoi = ?)';
            $tham[] = $like;
            $tham[] = (int) $mm[1];
        } else {
            $dieuKien[] = 'c.NoiDung LIKE ?';
            $tham[] = $like;
        }
        $parts[] = 'từ khóa "' . mb_substr($q, 0, 40) . '"';
    }
    if ($mon > 0) {
        $dieuKien[] = 'c.MaMon = ?';
        $tham[] = $mon;
        $monLoc = $mon;
        $st = $pdo->prepare('SELECT TenMon FROM MONHOC WHERE MaMon = ?');
        $st->execute([$mon]);
        $tenMonLoc = (string) ($st->fetchColumn() ?: ('#' . $mon));
        $parts[] = 'môn ' . $tenMonLoc;
    }
    if ($kt === 'chung') {
        $dieuKien[] = 'c.MaLoaiKT IS NULL';
        $parts[] = 'loại kỳ thi: dùng chung';
    } elseif ($kt !== '') {
        $dieuKien[] = 'c.MaLoaiKT = ?';
        $tham[] = (int) $kt;
        $st = $pdo->prepare('SELECT TenLoaiKT FROM LOAIKYTHI WHERE MaLoaiKT = ?');
        $st->execute([(int) $kt]);
        $parts[] = 'loại kỳ thi: ' . (string) ($st->fetchColumn() ?: ('#' . $kt));
    }
    if ($dk !== '')  { $dieuKien[] = 'c.DoKho = ?';     $tham[] = $dk;  $parts[] = 'độ khó: ' . $dk; }
    if ($tt !== '')  { $dieuKien[] = 'c.TrangThai = ?'; $tham[] = $tt;  $parts[] = 'trạng thái: ' . $tt; }
    if ($loc === 'lech') { $dieuKien[] = $sqlLech;                 $parts[] = 'chỉ câu lệch độ khó'; }
    if ($loc === 'chua') { $dieuKien[] = 'u.MaCauHoi IS NULL';     $parts[] = 'chỉ câu chưa từng dùng trong trận'; }
    $moTaPhamVi = 'Đang lọc' . ($parts ? ' (' . implode(', ', $parts) . ')' : ' (không có bộ lọc: toàn bộ ngân hàng)');
} elseif ($phamVi === 'chon') {
    $ids = [];
    foreach ((is_array($_POST['ids'] ?? null) ? $_POST['ids'] : []) as $v) {
        if (is_scalar($v) && ctype_digit((string) $v) && (int) $v > 0) { $ids[(int) $v] = true; }
    }
    $ids = array_keys($ids);
    if (!$ids) { loi_xuat('Chưa chọn câu hỏi nào để xuất.'); }
    if (count($ids) > $TOI_DA_CAU) { loi_xuat('Chọn quá nhiều câu (tối đa ' . $TOI_DA_CAU . ' câu mỗi lần xuất).'); }
    $dieuKien[] = 'c.MaCauHoi IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
    foreach ($ids as $i) { $tham[] = $i; }
    $moTaPhamVi = 'Các câu đã chọn (' . count($ids) . ' câu)';
} elseif ($phamVi === 'lech') {
    $dieuKien[] = $sqlLech;
    $moTaPhamVi = 'Chỉ các câu lệch độ khó';
} else {
    $moTaPhamVi = 'Toàn bộ ngân hàng câu hỏi';
}
$where = $dieuKien ? 'WHERE ' . implode(' AND ', $dieuKien) : '';

// ---- Lấy câu hỏi trong phạm vi ----
$st = $pdo->prepare(
    "SELECT c.MaCauHoi, c.NoiDung, c.GiaiThich, c.DoKho, c.TrangThai, m.TenMon, k.TenLoaiKT,
            COALESCE(tl.luot, 0) AS luot, COALESCE(tl.dung, 0) AS dung,
            (SELECT COUNT(DISTINCT x.MaTran) FROM CAUHOITRANDAU x WHERE x.MaCauHoi = c.MaCauHoi) AS so_tran
     FROM CAUHOI c
     JOIN MONHOC m ON m.MaMon = c.MaMon
     LEFT JOIN LOAIKYTHI k ON k.MaLoaiKT = c.MaLoaiKT
     $joinTL
     $where
     ORDER BY m.TenMon, c.MaCauHoi
     LIMIT " . ($TOI_DA_CAU + 1)
);
$st->execute($tham);
$cau = $st->fetchAll();
if (count($cau) > $TOI_DA_CAU) {
    loi_xuat('Phạm vi có hơn ' . $TOI_DA_CAU . ' câu hỏi, vượt giới hạn mỗi lần xuất. Hãy lọc bớt (theo môn, độ khó...) rồi xuất lại.');
}
if (!$cau && ($coDS || $coHC) && $buoc !== 'xemtruoc') {
    loi_xuat('Không có câu hỏi nào trong phạm vi đã chọn.');
}

// ---- Tính số liệu từng câu ----
$tong = count($cau);
$duDL = 0; $soLech = 0;
$theoNhan = array_fill_keys($DO_KHO, 0);
$phanBo = array_fill(0, 10, 0);
$lechDs = [];
foreach ($cau as $i => $r) {
    $luot = (int) $r['luot']; $dung = (int) $r['dung'];
    $du   = $luot >= $MIN_LUOT;
    $ty   = $luot > 0 ? $dung * 100 / $luot : 0.0;
    $goiY = $du ? $hangTheoTyLe($dung, $luot) : '';
    $lech = $du && $goiY !== $r['DoKho'];
    $cau[$i]['_luot'] = $luot; $cau[$i]['_dung'] = $dung; $cau[$i]['_du'] = $du;
    $cau[$i]['_ty'] = $ty; $cau[$i]['_goiy'] = $goiY; $cau[$i]['_lech'] = $lech;
    if (isset($theoNhan[$r['DoKho']])) { $theoNhan[$r['DoKho']]++; }
    if ($du) {
        $duDL++;
        $phanBo[min(9, (int) floor($ty / 10))]++;
    }
    if ($lech) {
        $soLech++;
        $cau[$i]['_muc'] = $mucLech((string) $r['DoKho'], $ty);
        $lechDs[] = $cau[$i];
    }
}
usort($lechDs, static fn (array $a, array $b): int => [$b['_muc'], $a['MaCauHoi']] <=> [$a['_muc'], $b['MaCauHoi']]);

// ---- Ảnh biểu đồ do trình duyệt vẽ (giống biểu đồ trên trang), nếu có ----
$anhPhanBo = null; $anhRong = 0; $anhCao = 0;
$du = (string) ($_POST['anh_phanbo'] ?? '');
if ($du !== '' && strlen($du) < 4000000 && preg_match('#^data:image/jpeg;base64,([A-Za-z0-9+/=]+)$#', $du, $mm)) {
    $nhiPhan = base64_decode($mm[1], true);
    $kt2 = $nhiPhan !== false && strncmp($nhiPhan, "\xFF\xD8", 2) === 0 ? @getimagesizefromstring($nhiPhan) : false;
    if ($kt2 && $kt2[0] > 0 && $kt2[1] > 0 && $kt2[0] <= 4000 && $kt2[1] <= 4000) {
        $anhPhanBo = $nhiPhan; $anhRong = (int) $kt2[0]; $anhCao = (int) $kt2[1];
    }
}

// ---- Đáp án của từng câu (chỉ khi xuất danh sách) ----
$dapAn = [];
if ($coDS && $cau) {
    $maDs = array_map(static fn (array $r): int => (int) $r['MaCauHoi'], $cau);
    foreach (array_chunk($maDs, 500) as $nhom) {
        $stD = $pdo->prepare(
            'SELECT MaCauHoi, NoiDungDapAn, LaDapAnDung FROM DAPAN WHERE MaCauHoi IN ('
            . implode(',', array_fill(0, count($nhom), '?')) . ') ORDER BY MaCauHoi, MaDapAn'
        );
        $stD->execute($nhom);
        foreach ($stD->fetchAll() as $d) {
            $dapAn[(int) $d['MaCauHoi']][] = $d;
        }
    }
}
// Trả về [4 phương án (A-D, rỗng nếu thiếu), chữ cái đáp án đúng, nội dung đáp án đúng]
$phuongAn = static function (int $ma) use ($dapAn): array {
    $ds = $dapAn[$ma] ?? [];
    $pa = ['', '', '', ''];
    $dungChu = ''; $dungND = '';
    foreach (array_slice($ds, 0, 4) as $i => $d) {
        $pa[$i] = (string) $d['NoiDungDapAn'];
        if ((int) $d['LaDapAnDung'] === 1) { $dungChu = chr(65 + $i); $dungND = $pa[$i]; }
    }
    return [$pa, $dungChu, $dungND];
};

// ---- Bản đồ phủ: số câu đang hiển thị của từng môn đang mở theo từng độ khó ----
$bando = [];
$oThieu = 0;
if ($coBD || $buoc === 'xemtruoc') {
    $sql = "SELECT m.MaMon, m.TenMon,
                   COALESCE(SUM(c.DoKho = 'Dễ'), 0)         AS de,
                   COALESCE(SUM(c.DoKho = 'Trung bình'), 0) AS tb,
                   COALESCE(SUM(c.DoKho = 'Khó'), 0)        AS kho
            FROM MONHOC m
            LEFT JOIN CAUHOI c ON c.MaMon = m.MaMon AND c.TrangThai = 'Hiển thị'
            WHERE m.TrangThai = 'Đang mở'" . ($monLoc > 0 ? ' AND m.MaMon = ?' : '') . '
            GROUP BY m.MaMon, m.TenMon
            ORDER BY m.TenMon';
    $stB = $pdo->prepare($sql);
    $stB->execute($monLoc > 0 ? [$monLoc] : []);
    foreach ($stB->fetchAll() as $m) {
        $dong = ['ten' => (string) $m['TenMon'], 'de' => (int) $m['de'], 'tb' => (int) $m['tb'], 'kho' => (int) $m['kho'], 'thieu' => 0];
        foreach (['de', 'tb', 'kho'] as $k) {
            $dong['thieu'] += max(0, $PHU_DU - $dong[$k]);
            if ($dong[$k] < $NGUONG_DO) { $oThieu++; }
        }
        $bando[] = $dong;
    }
    usort($bando, static fn (array $a, array $b): int => $b['thieu'] <=> $a['thieu']);   // môn thiếu nhiều nhất lên đầu
}
// Màu ô theo số câu: 0 đỏ, 1 vàng, 2 xanh nhạt, 3 xanh đậm (khớp lopOBando trong cauhoi.php)
$bacO = static function (int $n) use ($NGUONG_DO, $PHU_DU, $PHU_TOT): int {
    if ($n < $NGUONG_DO) { return 0; }
    if ($n < $PHU_DU)    { return 1; }
    if ($n < $PHU_TOT)   { return 2; }
    return 3;
};
$kieuXlsxO = [8, 9, 10, 11];                                   // kiểu ô Excel theo bậc
$mauPdfO   = ['#fddde2', '#fdecc4', '#d9f3e5', '#a9e5c8'];     // màu nền PDF theo bậc
$nhanBac   = [
    'Dưới ' . $NGUONG_DO . ' câu (thiếu nghiêm trọng)',
    $NGUONG_DO . ' – ' . ($PHU_DU - 1) . ' câu (chưa đủ ' . $PHU_DU . ')',
    $PHU_DU . ' – ' . ($PHU_TOT - 1) . ' câu (đủ)',
    $PHU_TOT . ' câu trở lên (dư)',
];

// ---- Chuỗi dùng chung ----
$bayGio  = date('d/m/Y H:i');
$ghiChuXuat = 'Xuất lúc ' . $bayGio . ' · Phạm vi: ' . $moTaPhamVi . ' · ' . $tong . ' câu hỏi';
$nguongChu  = 'Ngưỡng: Dễ khi tỉ lệ đúng ≥ ' . $NGUONG_DE . '%, Khó khi < ' . $NGUONG_KHO . '%, ở giữa là Trung bình. '
            . 'Chỉ xét câu có từ ' . $MIN_LUOT . ' lượt trả lời trở lên (hết giờ tính là sai).';
$nhanKhoang = [];
for ($i = 0; $i < 10; $i++) { $nhanKhoang[] = ($i * 10) . '–' . ($i * 10 + 10) . '%'; }
$mauKhoang = [];   // theo nhãn mà dữ liệu gợi ý cho từng khoảng
$goiYKhoang = [];
for ($i = 0; $i < 10; $i++) {
    if ($i * 10 >= $NGUONG_DE)            { $mauKhoang[] = '#22a06b'; $goiYKhoang[] = 'Dễ'; }
    elseif ($i * 10 + 10 <= $NGUONG_KHO)  { $mauKhoang[] = '#ef4444'; $goiYKhoang[] = 'Khó'; }
    else                                  { $mauKhoang[] = '#e3a93b'; $goiYKhoang[] = 'Trung bình'; }
}
// ---- Xem trước: trả JSON gọn cho hộp thoại (không tạo file) ----
if ($buoc === 'xemtruoc') {
    $dauDs = array_slice($cau, 0, 5);
    $dapDung = [];
    if ($dauDs) {
        $maD = array_map(static fn (array $r): int => (int) $r['MaCauHoi'], $dauDs);
        $stX = $pdo->prepare('SELECT MaCauHoi, NoiDungDapAn, LaDapAnDung FROM DAPAN WHERE MaCauHoi IN ('
            . implode(',', array_fill(0, count($maD), '?')) . ') ORDER BY MaCauHoi, MaDapAn');
        $stX->execute($maD);
        $viTri = [];
        foreach ($stX->fetchAll() as $d) {
            $k = (int) $d['MaCauHoi'];
            $viTri[$k] = ($viTri[$k] ?? -1) + 1;
            if ((int) $d['LaDapAnDung'] === 1 && $viTri[$k] < 4) {
                $dapDung[$k] = chr(65 + $viTri[$k]) . '. ' . mb_strimwidth((string) $d['NoiDungDapAn'], 0, 60, '…', 'UTF-8');
            }
        }
    }
    $rutGon = static fn (array $r): array => [
        'ma' => (int) $r['MaCauHoi'], 'mon' => (string) $r['TenMon'], 'doKho' => (string) $r['DoKho'],
        'nd' => mb_strimwidth((string) $r['NoiDung'], 0, 110, '…', 'UTF-8'),
        'dapAn' => $dapDung[(int) $r['MaCauHoi']] ?? '',
        'ty' => $r['_luot'] > 0 ? (int) round($r['_ty']) : null, 'dung' => $r['_dung'], 'luot' => $r['_luot'],
        'du' => $r['_du'], 'lech' => $r['_lech'], 'goiY' => $r['_goiy'],
    ];
    $bandoXem = [];
    foreach (array_slice($bando, 0, 60) as $m) {
        $bandoXem[] = ['ten' => $m['ten'], 'de' => $m['de'], 'tb' => $m['tb'], 'kho' => $m['kho'], 'thieu' => $m['thieu'],
                       'b' => [$bacO($m['de']), $bacO($m['tb']), $bacO($m['kho'])]];
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    exit(json_encode([
        'tong' => $tong, 'duDL' => $duDL, 'soLech' => $soLech, 'minLuot' => $MIN_LUOT,
        'nguongDe' => $NGUONG_DE, 'nguongKho' => $NGUONG_KHO, 'phuDu' => $PHU_DU, 'nguongDo' => $NGUONG_DO, 'phuTot' => $PHU_TOT,
        'phu' => $duDL . ' câu đủ ' . $MIN_LUOT . ' lượt · ' . ($tenMonLoc !== '' ? $tenMonLoc : 'mọi môn'),
        'nhan' => $nhanKhoang, 'so' => $phanBo, 'mau' => $mauKhoang,
        'cau' => array_map($rutGon, $dauDs),
        'lech' => array_map($rutGon, array_slice($lechDs, 0, 5)),
        'bando' => $bandoXem, 'soMon' => count($bando), 'oThieu' => $oThieu,
        'banDoLoc' => $monLoc > 0, 'tenMon' => $tenMonLoc,
    ], JSON_UNESCAPED_UNICODE));
}

$tenFile = 'ngan_hang_cau_hoi_' . date('Ymd_His');
$goiTen = ['danhsach' => 'danh sách câu hỏi', 'hieuchinh' => 'báo cáo hiệu chỉnh độ khó', 'bando' => 'bản đồ phủ'];
$moTaNoiDung = implode(', ', array_map(static fn (string $k): string => $goiTen[$k], array_values(array_intersect(
    ['danhsach', 'hieuchinh', 'bando'], $coBD ? array_merge($noiDung, ['bando']) : $noiDung))));
$ghiNhatKy = static function (string $dd) use ($moTaNoiDung, $moTaPhamVi, $tong): void {
    NhatKy::ghi('Xuất ngân hàng câu hỏi', null,
        $dd . ' · ' . $tong . ' câu · Nội dung: ' . $moTaNoiDung . ' · Phạm vi: ' . $moTaPhamVi . '.');
};

// =====================================================================================================
// EXCEL
// =====================================================================================================
if ($dinhDang === 'excel') {
    $sheets = [];

    if ($coDS) {
        $dong = [
            ['kieu' => 2,  'o' => ['Danh sách câu hỏi']],
            ['kieu' => 16, 'o' => [$ghiChuXuat]],
            ['kieu' => 16, 'o' => [$nguongChu]],
            ['kieu' => 6,  'o' => ['Mã', 'Môn học', 'Loại kỳ thi', 'Độ khó (đang gắn)', 'Nội dung câu hỏi',
                'Phương án A', 'Phương án B', 'Phương án C', 'Phương án D', 'Đáp án đúng', 'Giải thích',
                'Trạng thái', 'Số trận đã dùng', 'Lượt trả lời', 'Lượt đúng', 'Tỉ lệ đúng (%)',
                'Độ khó theo dữ liệu', 'Lệch độ khó']],
        ];
        foreach ($cau as $r) {
            [$pa, $dungChu] = $phuongAn((int) $r['MaCauHoi']);
            $dong[] = ['kieu' => 7, 'o' => [
                (int) $r['MaCauHoi'], (string) $r['TenMon'], (string) ($r['TenLoaiKT'] ?? 'Dùng chung (cả hai kỳ)'),
                (string) $r['DoKho'], (string) $r['NoiDung'],
                $pa[0], $pa[1], $pa[2], $pa[3], $dungChu, (string) ($r['GiaiThich'] ?? ''),
                (string) $r['TrangThai'], (int) $r['so_tran'], $r['_luot'], $r['_dung'],
                $r['_luot'] > 0 ? round($r['_ty'], 1) : null,
                $r['_du'] ? $r['_goiy'] : 'Chưa đủ dữ liệu',
                $r['_du'] ? ($r['_lech'] ? 'Có' : 'Không') : '—',
            ]];
        }
        $sheets[] = ['ten' => 'Câu hỏi', 'rongToiThieu' => 10, 'dong' => $dong];
    }

    if ($coHC) {
        $dong = [
            ['kieu' => 2,  'o' => ['Báo cáo hiệu chỉnh độ khó']],
            ['kieu' => 16, 'o' => [$ghiChuXuat]],
            ['kieu' => 16, 'o' => [$nguongChu]],
            ['kieu' => 0,  'o' => ['']],
            ['kieu' => 1,  'o' => ['1. Tổng quan']],
            ['kieu' => 6,  'o' => ['Chỉ tiêu', 'Số câu']],
            ['kieu' => 0,  'o' => ['Tổng số câu trong phạm vi', $tong]],
            ['kieu' => 0,  'o' => ['Câu đủ dữ liệu (từ ' . $MIN_LUOT . ' lượt trả lời)', $duDL]],
            ['kieu' => 0,  'o' => ['Câu chưa đủ dữ liệu', $tong - $duDL]],
            ['kieu' => 0,  'o' => ['Câu lệch độ khó (nhãn đang gắn không khớp tỉ lệ đúng thực tế)', $soLech]],
        ];
        foreach ($DO_KHO as $d) {
            $dong[] = ['kieu' => 0, 'o' => ['Đang gắn nhãn ' . $d, $theoNhan[$d]]];
        }
        $dong[] = ['kieu' => 0, 'o' => ['']];
        $dong[] = ['kieu' => 1, 'o' => ['2. Phân bố tỉ lệ đúng (mỗi câu hỏi tính một lần, chỉ câu đủ dữ liệu)']];
        if ($anhPhanBo !== null) {
            // Biểu đồ chụp từ trình duyệt: giống hệt biểu đồ trên trang
            $rongAnh = min(720, $anhRong);
            $dong[] = ['anh' => [['jpeg' => $anhPhanBo, 'rong' => $rongAnh, 'cao' => (int) round($anhCao * $rongAnh / $anhRong), 'x' => 0]]];
            $dong[] = ['kieu' => 6, 'o' => ['Khoảng tỉ lệ đúng', 'Số câu', 'Độ khó theo dữ liệu']];
            foreach ($phanBo as $i => $n) {
                $dong[] = ['kieu' => 0, 'o' => [$nhanKhoang[$i], $n, $goiYKhoang[$i]]];
            }
        } else {
            $dong[] = ['kieu' => 6, 'o' => ['Khoảng tỉ lệ đúng', 'Số câu', 'Độ khó theo dữ liệu', 'Biểu đồ']];
            $maxCot = max(1, max($phanBo));
            $kieuBar = ['Khó' => 13, 'Trung bình' => 14, 'Dễ' => 15];
            foreach ($phanBo as $i => $n) {
                $bar = $n > 0 ? str_repeat('█', max(1, (int) round($n * 30 / $maxCot))) : '';
                $dong[] = ['kieu' => 0, 'o' => [$nhanKhoang[$i], $n, $goiYKhoang[$i], ['g' => $bar, 's' => $kieuBar[$goiYKhoang[$i]]]]];
            }
        }
        $sheets[] = ['ten' => 'Hiệu chỉnh độ khó', 'rongToiThieu' => 12, 'dong' => $dong];

        $dong = [
            ['kieu' => 2,  'o' => ['Câu lệch độ khó']],
            ['kieu' => 16, 'o' => [$ghiChuXuat]],
            ['kieu' => 16, 'o' => ['Xếp từ lệch nhiều đến ít. "Mức lệch" là số điểm phần trăm cách khoảng đúng của nhãn đang gắn.']],
            ['kieu' => 6,  'o' => ['Mã', 'Nội dung câu hỏi', 'Môn học', 'Độ khó (đang gắn)', 'Tỉ lệ đúng (%)',
                'Lượt trả lời', 'Nên đổi thành', 'Mức lệch (điểm %)']],
        ];
        foreach ($lechDs as $r) {
            $dong[] = ['kieu' => 7, 'o' => [
                (int) $r['MaCauHoi'], (string) $r['NoiDung'], (string) $r['TenMon'], (string) $r['DoKho'],
                round($r['_ty'], 1), $r['_luot'], $r['_goiy'], round($r['_muc'], 1),
            ]];
        }
        if (!$lechDs) { $dong[] = ['kieu' => 0, 'o' => ['Không có câu nào lệch độ khó trong phạm vi này.']]; }
        $sheets[] = ['ten' => 'Câu lệch độ khó', 'rongToiThieu' => 10, 'dong' => $dong];
    }

    if ($coBD) {
        $dong = [
            ['kieu' => 2,  'o' => ['Bản đồ phủ câu hỏi']],
            ['kieu' => 16, 'o' => ['Xuất lúc ' . $bayGio . ' · ' . ($monLoc > 0 ? 'Môn đang lọc' : 'Tất cả môn đang mở')
                . ' · Chỉ đếm câu đang hiển thị. Mỗi môn cần tối thiểu ' . $PHU_DU . ' câu cho mỗi độ khó để ghép trận công bằng.']],
            ['kieu' => 6,  'o' => ['Môn học', 'Dễ', 'Trung bình', 'Khó', 'Số câu còn thiếu']],
        ];
        foreach ($bando as $m) {
            $dong[] = ['kieu' => 0, 'o' => [
                $m['ten'],
                ['g' => $m['de'],  's' => $kieuXlsxO[$bacO($m['de'])]],
                ['g' => $m['tb'],  's' => $kieuXlsxO[$bacO($m['tb'])]],
                ['g' => $m['kho'], 's' => $kieuXlsxO[$bacO($m['kho'])]],
                ['g' => $m['thieu'], 's' => 12],
            ]];
        }
        if (!$bando) { $dong[] = ['kieu' => 0, 'o' => ['Chưa có môn học nào đang mở.']]; }
        $dong[] = ['kieu' => 0, 'o' => ['']];
        $dong[] = ['kieu' => 1, 'o' => ['Chú thích màu (số câu mỗi ô)']];
        foreach ($nhanBac as $b => $chu) {
            $dong[] = ['kieu' => 0, 'o' => [['g' => '', 's' => $kieuXlsxO[$b]], $chu]];
        }
        $dong[] = ['kieu' => 0, 'o' => ['Số ô thiếu (dưới ' . $NGUONG_DO . ' câu): ' . $oThieu]];
        $sheets[] = ['ten' => 'Bản đồ phủ', 'rongToiThieu' => 12, 'dong' => $dong];
    }

    $ghiNhatKy('Excel');
    XlsxWriter::taiNhieuSheet($tenFile . '.xlsx', $sheets);
}

// =====================================================================================================
// PDF (A4 ngang)
// =====================================================================================================
$pdf = new PdfWriter(__DIR__ . '/../assets/fonts');
$pdf->khoTrang(true);
$daVe = false;

if ($coHC || $coBD) {
    $pdf->tieuDe($coHC ? 'Báo cáo hiệu chỉnh độ khó' : 'Bản đồ phủ câu hỏi', 18);
    $pdf->doan($ghiChuXuat, 9, false, '#5b6b8c');
    if ($coHC) { $pdf->doan($nguongChu, 9, false, '#5b6b8c'); }
    $pdf->khoang(6);

    if ($coHC) {
        $oSo = [
            ['Tổng số câu trong phạm vi', (string) $tong, ''],
            ['Câu đủ dữ liệu (từ ' . $MIN_LUOT . ' lượt)', (string) $duDL, ''],
            ['Câu lệch độ khó', (string) $soLech, ''],
        ];
        if ($coBD) { $oSo[] = ['Ô thiếu trên bản đồ phủ', (string) $oThieu, '']; }
        $pdf->oSoLieu($oSo);
        $pdf->tieuDe('Phân bố tỉ lệ đúng (số câu theo từng khoảng)', 12);
        if ($duDL > 0) {
            if ($anhPhanBo !== null) {
                $pdf->anhHang([$anhPhanBo], 215);   // biểu đồ chụp từ trình duyệt: giống hệt trên trang
            } else {
                $pdf->bieuDoCot($nhanKhoang, $phanBo, $mauKhoang, 120);
                $pdf->doan('Đỏ: Khó (dưới ' . $NGUONG_KHO . '% đúng)   Vàng: Trung bình   Xanh: Dễ (từ ' . $NGUONG_DE . '% đúng trở lên).', 8.5, false, '#5b6b8c');
            }
            $pdf->doan('Trục ngang là tỉ lệ đúng của câu, cột cao là số câu hỏi (không phải số người chơi).', 8.5, false, '#5b6b8c');
        } else {
            $pdf->doan('Chưa có câu nào đủ ' . $MIN_LUOT . ' lượt trả lời để vẽ biểu đồ.', 9);
        }
        $pdf->khoang(6);
    }

    if ($coBD) {
        $pdf->tieuDe('Bản đồ phủ câu hỏi', 12);
        $pdf->doan(($monLoc > 0 ? 'Môn đang lọc' : 'Tất cả môn đang mở') . ' · chỉ đếm câu đang hiển thị · mỗi môn cần tối thiểu '
            . $PHU_DU . ' câu cho mỗi độ khó. Môn thiếu nhiều nhất xếp trước.', 8.5, false, '#5b6b8c');
        if ($bando) {
            $dongB = []; $mauB = [];
            foreach ($bando as $i => $m) {
                $dongB[] = [$m['ten'], (string) $m['de'], (string) $m['tb'], (string) $m['kho'], (string) $m['thieu']];
                $mauB[$i] = [
                    1 => $mauPdfO[$bacO($m['de'])],
                    2 => $mauPdfO[$bacO($m['tb'])],
                    3 => $mauPdfO[$bacO($m['kho'])],
                ];
            }
            $pdf->bang(['Môn học', 'Dễ', 'Trung bình', 'Khó', 'Số câu còn thiếu'], $dongB, [5, 1.4, 1.4, 1.4, 2], [], $mauB, [1, 2, 3, 4]);
        } else {
            $pdf->doan('Chưa có môn học nào đang mở.', 9);
        }
        $pdf->doan('Màu ô: đỏ dưới ' . $NGUONG_DO . ' câu · vàng ' . $NGUONG_DO . '–' . ($PHU_DU - 1) . ' · xanh nhạt ' . $PHU_DU . '–' . ($PHU_TOT - 1)
            . ' · xanh đậm từ ' . $PHU_TOT . ' câu.', 8.5, false, '#5b6b8c');
    }

    if ($coHC) {
        $pdf->trangMoi();
        $pdf->tieuDe('Câu lệch độ khó (' . $soLech . ' câu)', 14);
        if ($lechDs) {
            $pdf->doan('Xếp từ lệch nhiều đến ít. Cột "Nên đổi thành" là nhãn mà tỉ lệ đúng thực tế gợi ý.'
                . ($soLech > $TOI_DA_LECH_PDF ? ' PDF chỉ liệt kê ' . $TOI_DA_LECH_PDF . ' câu đầu, xem đủ trong file Excel.' : ''), 8.5, false, '#5b6b8c');
            $dongL = [];
            foreach (array_slice($lechDs, 0, $TOI_DA_LECH_PDF) as $r) {
                $dongL[] = ['#' . $r['MaCauHoi'], mb_strimwidth((string) $r['NoiDung'], 0, 160, '…', 'UTF-8'), (string) $r['TenMon'],
                    (string) $r['DoKho'], round($r['_ty']) . '% (' . $r['_dung'] . '/' . $r['_luot'] . ')', (string) $r['_goiy']];
            }
            $pdf->bang(['Mã', 'Nội dung', 'Môn học', 'Đang gắn', 'Tỉ lệ đúng', 'Nên đổi thành'], $dongL, [1, 8, 2.4, 1.4, 2, 1.8]);
        } else {
            $pdf->doan('Không có câu nào lệch độ khó trong phạm vi này.', 9);
        }
    }
    $daVe = true;
}

if ($coDS) {
    if ($daVe) { $pdf->trangMoi(); }
    $pdf->tieuDe('Danh sách câu hỏi (' . $tong . ' câu)', 16);
    $pdf->doan($ghiChuXuat, 9, false, '#5b6b8c');
    $pdf->doan('Tỉ lệ đúng thực tế: số lượt đúng / tổng lượt trả lời; "→" là nhãn độ khó mà dữ liệu gợi ý khi câu đang lệch.', 8.5, false, '#5b6b8c');
    $dongC = [];
    foreach ($cau as $r) {
        [, $dungChu, $dungND] = $phuongAn((int) $r['MaCauHoi']);
        if ($r['_luot'] === 0)      { $tyChu = 'Chưa có lượt'; }
        elseif (!$r['_du'])         { $tyChu = round($r['_ty']) . '% (' . $r['_dung'] . '/' . $r['_luot'] . ') · chưa đủ dữ liệu'; }
        else                        { $tyChu = round($r['_ty']) . '% (' . $r['_dung'] . '/' . $r['_luot'] . ')' . ($r['_lech'] ? ' → ' . $r['_goiy'] : ''); }
        $dongC[] = [
            '#' . $r['MaCauHoi'],
            (string) $r['TenMon'] . ($r['TrangThai'] === 'Đã ẩn' ? ' (ẩn)' : ''),
            (string) $r['DoKho'],
            (string) $r['NoiDung'],
            $dungChu !== '' ? $dungChu . '. ' . $dungND : '',
            (string) ($r['GiaiThich'] ?? ''),
            $tyChu,
        ];
    }
    $pdf->bang(['Mã', 'Môn học', 'Độ khó', 'Nội dung', 'Đáp án đúng', 'Giải thích', 'Tỉ lệ đúng thực tế'], $dongC, [0.9, 1.8, 1.2, 6, 3, 4, 2.4]);
}

$ghiNhatKy('PDF');
$pdf->tai($tenFile . '.pdf');