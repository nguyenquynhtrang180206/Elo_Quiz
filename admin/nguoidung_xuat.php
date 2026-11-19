<?php
// Xuất danh sách người dùng ra Excel hoặc PDF. Đặt tại: elo_quiz/admin/nguoidung_xuat.php
// Được gọi (POST, có CSRF) từ hộp thoại "Xuất" ở admin/nguoidung.php (cùng giao diện với hộp thoại Xuất của trang Tổng quan).
// Mỗi lần gọi trả MỘT file; khi người dùng chọn "Cả hai", trình duyệt (nguoidung_xuat.js) gọi lần lượt hai lần.
//
// Tham số:
//   pham_vi : loc = đang lọc (q, tt gửi kèm từ trang, dựng điều kiện giống hệt trang danh sách), tatca = toàn bộ người dùng.
//   truong[]: các trường (cột): ma, ten, email, tt, sotran, ngay, elo (elo = thêm một cột điểm Elo cho từng môn). Thứ tự cột luôn theo $COT.
//   noidung[]: ds = danh sách người dùng (bảng), tk = sơ đồ trạng thái tài khoản, ngay = sơ đồ tài khoản mới 30 ngày gần nhất.
//   dinhdang: excel | pdf.
//   anh_tk, anh_ngay: ảnh JPEG (data URL) của hai sơ đồ, do trình duyệt vẽ từ số liệu xem trước (cùng cách với admin/nhatky_xuat.php).
//   buoc=xemtruoc: không xuất file mà trả JSON để dựng khung "Xem trước" (số liệu và vài dòng đầu).
require_once __DIR__ . '/../core/bootstrap.php';
Auth::requireAdmin(BASE_URL . '/admin/dangnhap.php');   // A-04

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

// Ảnh dùng trong Excel, rộng $rong pixel (giữ tỷ lệ), cách mép trái $x pixel
function anh_theo_rong(?array $a, int $rong, int $x = 0): ?array
{
    if (!$a) { return null; }
    return ['jpeg' => $a['jpeg'], 'rong' => $rong, 'cao' => (int) round($a['cao'] * $rong / $a['rong']), 'x' => $x];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(BASE_URL . '/admin/nguoidung.php');
}
csrf_check();
@set_time_limit(120);

$TOI_DA_XLSX = 10000;   // số dòng tối đa của file Excel (vượt quá thì yêu cầu lọc bớt, tránh làm treo máy chủ)
$TOI_DA_PDF  = 3000;
$SO_DONG_XEM = 10;      // số dòng đầu đưa vào khung xem trước

// Các trường được phép xuất: khóa => [tiêu đề cột, tỉ lệ độ rộng cột trong PDF]
$COT = [
    'ma'     => ['Mã',         0.8],
    'ten'    => ['Họ tên',     2.6],
    'email'  => ['Email',      3.4],
    'tt'     => ['Trạng thái', 1.7],
    'sotran' => ['Số trận',    1.1],
    'ngay'   => ['Ngày tạo',   2.0],
];

$pdo = Database::pdo();

// ---- Tham số từ hộp thoại ----
$phamVi = (string) ($_POST['pham_vi'] ?? '');
if (!in_array($phamVi, ['loc', 'tatca'], true)) {
    loi_xuat('Hãy chọn phạm vi cần xuất.');
}
$buoc     = (string) ($_POST['buoc'] ?? '');
$dinhDang = (string) ($_POST['dinhdang'] ?? '');
if ($buoc === 'xemtruoc') { $dinhDang = 'excel'; }   // xem trước không cần định dạng
if (!in_array($dinhDang, ['excel', 'pdf'], true)) {
    loi_xuat('Hãy chọn định dạng Excel hoặc PDF.');
}
$truongGui = is_array($_POST['truong'] ?? null) ? array_map('strval', $_POST['truong']) : [];
$truong    = array_values(array_intersect(array_merge(array_keys($COT), ['elo']), $truongGui));   // giữ thứ tự cố định
$NOI_DUNG = ['ds' => 'Danh sách người dùng', 'tk' => 'Sơ đồ trạng thái tài khoản', 'ngay' => 'Sơ đồ tài khoản mới 30 ngày'];
$noiDung  = array_values(array_intersect(array_keys($NOI_DUNG), is_array($_POST['noidung'] ?? null) ? array_map('strval', $_POST['noidung']) : []));
$coDs     = in_array('ds', $noiDung, true);
$coTk     = in_array('tk', $noiDung, true);
$coNgay   = in_array('ngay', $noiDung, true);
if ($buoc !== 'xemtruoc') {
    if (!$noiDung)           { loi_xuat('Hãy chọn ít nhất một nội dung cần xuất.'); }
    if ($coDs && !$truong)   { loi_xuat('Hãy chọn ít nhất một trường cho danh sách người dùng.'); }
}
$coElo = in_array('elo', $truong, true);

// ---- Điều kiện lọc: dựng giống hệt admin/nguoidung.php ----
$dieuKien   = [];
$tham       = [];
$dieuKien[] = 'n.MaND NOT IN (SELECT MaND FROM ADMIN WHERE MaND IS NOT NULL)';   // không liệt kê tài khoản sinh viên liên kết của admin
if ($phamVi === 'loc') {
    $q  = trim((string) ($_POST['q'] ?? ''));
    $tt = (string) ($_POST['tt'] ?? '');
    if ($q !== '') {
        $like       = '%' . addcslashes($q, '\\%_') . '%';
        $dieuKien[] = '(n.HoTen LIKE ? OR n.Email LIKE ?)';
        array_push($tham, $like, $like);
    }
    if (in_array($tt, ['Hoạt động', 'Bị khóa'], true)) {
        $dieuKien[] = 'n.TrangThai = ?';
        $tham[]     = $tt;
    }
}
$where = 'WHERE ' . implode(' AND ', $dieuKien);

try {
    // Tổng số và số theo trạng thái trong phạm vi đang xuất
    $st = $pdo->prepare("SELECT COUNT(*) AS tong,
                                COALESCE(SUM(n.TrangThai = 'Hoạt động'), 0) AS hoat_dong,
                                COALESCE(SUM(n.TrangThai = 'Bị khóa'), 0)   AS bi_khoa
                         FROM NGUOIDUNG n $where");
    $st->execute($tham);
    $tk   = $st->fetch();
    $tong = (int) $tk['tong'];

    // Tài khoản mới mỗi ngày trong 30 ngày gần nhất, trong phạm vi đang xuất (ngày không có ai đăng ký vẫn hiện 0)
    $st = $pdo->prepare("SELECT DATE(n.NgayTao) AS ngay, COUNT(*) AS so FROM NGUOIDUNG n $where
                         AND n.NgayTao >= CURDATE() - INTERVAL 29 DAY GROUP BY DATE(n.NgayTao)");
    $st->execute($tham);
    $theoNgayDb = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    $theoNgay = [];
    for ($i = 29; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i day"));
        $theoNgay[] = ['nhan' => date('d/m', strtotime($d)), 'so' => (int) ($theoNgayDb[$d] ?? 0)];
    }

    $monHoc = $pdo->query('SELECT MaMon, TenMon FROM MONHOC ORDER BY MaMon')->fetchAll();

    // Lấy dữ liệu (xem trước: vài dòng đầu; xuất file: tối đa giới hạn + 1 để biết có vượt không)
    $gioiHan = $buoc === 'xemtruoc' ? $SO_DONG_XEM : (($dinhDang === 'pdf' ? $TOI_DA_PDF : $TOI_DA_XLSX) + 1);
    $st = $pdo->prepare(
        "SELECT n.MaND, n.HoTen, n.Email, n.TrangThai, n.NgayTao,
                (SELECT COALESCE(SUM(e.SoTran), 0) FROM ELOMONHOC e WHERE e.MaND = n.MaND) AS so_tran
         FROM NGUOIDUNG n $where
         ORDER BY n.NgayTao DESC, n.MaND DESC
         LIMIT " . (int) $gioiHan
    );
    $st->execute($tham);
    $nguoi = $st->fetchAll();

    // Điểm Elo theo môn của đúng những người đã lấy
    $elo = [];
    if ($nguoi && ($coElo || $buoc === 'xemtruoc')) {
        $ids = array_map(static fn (array $r): int => (int) $r['MaND'], $nguoi);
        $st  = $pdo->prepare('SELECT MaND, MaMon, DiemElo FROM ELOMONHOC WHERE MaND IN (' . implode(',', $ids) . ')');
        $st->execute();
        foreach ($st as $r) {
            $elo[(int) $r['MaND']][(int) $r['MaMon']] = (int) $r['DiemElo'];
        }
    }

    // ---- Xem trước: trả JSON ----
    if ($buoc === 'xemtruoc') {
        $dong = [];
        foreach ($nguoi as $r) {
            $e = [];
            foreach ($monHoc as $m) {
                $e[(string) $m['MaMon']] = $elo[(int) $r['MaND']][(int) $m['MaMon']] ?? null;
            }
            $dong[] = [
                'ma'     => (int) $r['MaND'],
                'ten'    => (string) $r['HoTen'],
                'email'  => (string) $r['Email'],
                'tt'     => (string) $r['TrangThai'],
                'sotran' => (int) $r['so_tran'],
                'ngay'   => date('d/m/Y H:i', strtotime((string) $r['NgayTao'])),
                'elo'    => $e,
            ];
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode([
            'tong'      => $tong,
            'hoatDong'  => (int) $tk['hoat_dong'],
            'biKhoa'    => (int) $tk['bi_khoa'],
            'phamVi'    => $phamVi === 'loc' ? 'đang lọc' : 'toàn bộ người dùng',
            'theoNgay'  => $theoNgay,
            'mon'       => array_map(static fn (array $m): array => ['ma' => (string) $m['MaMon'], 'ten' => (string) $m['TenMon']], $monHoc),
            'dong'      => $dong,
            'toiDaXlsx' => $TOI_DA_XLSX,
            'toiDaPdf'  => $TOI_DA_PDF,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    // ---- Xuất file ----
    if ($tong === 0) {
        loi_xuat('Không có người dùng nào trong phạm vi đã chọn.');
    }
    $gh = $dinhDang === 'pdf' ? $TOI_DA_PDF : $TOI_DA_XLSX;
    if ($coDs && $tong > $gh) {
        loi_xuat('Có ' . number_format($tong, 0, ',', '.') . ' người dùng, vượt giới hạn ' . number_format($gh, 0, ',', '.')
            . ' dòng của file ' . ($dinhDang === 'pdf' ? 'PDF' : 'Excel') . '. Hãy lọc bớt rồi xuất lại.');
    }

    $tieuDe = [];
    foreach ($truong as $k) {
        if ($k === 'elo') {
            foreach ($monHoc as $m) { $tieuDe[] = 'Elo - ' . $m['TenMon']; }
        } else {
            $tieuDe[] = $COT[$k][0];
        }
    }
    $dong = [];
    foreach ($nguoi as $r) {
        $d = [];
        foreach ($truong as $k) {
            switch ($k) {
                case 'ma':     $d[] = (int) $r['MaND']; break;
                case 'ten':    $d[] = (string) $r['HoTen']; break;
                case 'email':  $d[] = (string) $r['Email']; break;
                case 'tt':     $d[] = (string) $r['TrangThai']; break;
                case 'sotran': $d[] = (int) $r['so_tran']; break;
                case 'ngay':   $d[] = date('d/m/Y H:i', strtotime((string) $r['NgayTao'])); break;
                case 'elo':
                    foreach ($monHoc as $m) {
                        $d[] = $elo[(int) $r['MaND']][(int) $m['MaMon']] ?? null;   // chưa chơi môn nào thì để trống
                    }
                    break;
            }
        }
        $dong[] = $d;
    }

    $tenFile = 'nguoidung_' . date('Ymd_His');

    $anhTk   = $coTk   ? anh_bieu_do('anh_tk')   : null;
    $anhNgay = $coNgay ? anh_bieu_do('anh_ngay') : null;
    $luc     = 'Phạm vi: ' . ($phamVi === 'loc' ? 'đang lọc' : 'toàn bộ người dùng') . ' · Xuất lúc ' . date('d/m/Y H:i');
    $hoatDong = (int) $tk['hoat_dong'];
    $biKhoa   = (int) $tk['bi_khoa'];
    $tongNhom = max(1, $hoatDong + $biKhoa);

    if ($dinhDang === 'excel') {
        $ghiChu = static fn (string $t): array => ['kieu' => 0, 'o' => [$t]];
        $sheets = [];
        if ($coTk || $coNgay) {
            $h = [['kieu' => 2, 'o' => ['Người dùng - Sơ đồ']], $ghiChu($luc)];
            if ($coTk) {
                $h[] = ['kieu' => 0, 'o' => []];
                $h[] = ['kieu' => 2, 'o' => ['Trạng thái tài khoản']];
                if ($anhTk) { $h[] = ['anh' => [anh_theo_rong($anhTk, 320)]]; }
                $h[] = ['kieu' => 6, 'o' => ['Trạng thái', 'Số lượng', 'Tỷ lệ']];
                $h[] = ['kieu' => 0, 'o' => ['Hoạt động', $hoatDong, round($hoatDong * 100 / $tongNhom) . '%']];
                $h[] = ['kieu' => 0, 'o' => ['Bị khóa', $biKhoa, round($biKhoa * 100 / $tongNhom) . '%']];
            }
            if ($coNgay) {
                $h[] = ['kieu' => 0, 'o' => []];
                $h[] = ['kieu' => 2, 'o' => ['Tài khoản mới 30 ngày gần nhất']];
                if ($anhNgay) { $h[] = ['anh' => [anh_theo_rong($anhNgay, 720)]]; }
                $h[] = ['kieu' => 6, 'o' => ['Ngày', 'Tài khoản mới']];
                foreach ($theoNgay as $r) { $h[] = ['kieu' => 0, 'o' => [$r['nhan'], $r['so']]]; }
            }
            $sheets[] = ['ten' => 'Sơ đồ', 'rongToiThieu' => 14, 'dong' => $h];
        }
        if ($coDs) {
            $h = [['kieu' => 2, 'o' => ['Người dùng - Danh sách']], $ghiChu($luc), ['kieu' => 0, 'o' => []], ['kieu' => 6, 'o' => $tieuDe]];
            foreach ($dong as $d) { $h[] = ['kieu' => 0, 'o' => $d]; }
            $sheets[] = ['ten' => 'Danh sách', 'rongToiThieu' => 12, 'dong' => $h];
        }
        XlsxWriter::taiNhieuSheet($tenFile . '.xlsx', $sheets);
    }

    // PDF: nhiều cột thì dùng A4 ngang
    $pdf = new PdfWriter(__DIR__ . '/../assets/fonts');
    $pdf->khoTrang($coDs && count($tieuDe) > 6);
    $pdf->tieuDe($coDs ? 'Danh sách người dùng' : 'Thống kê người dùng', 20);
    $pdf->doan($luc, 10, false, '#5b6b8c');
    if ($phamVi === 'loc') {
        $loc = [];
        if (($q ?? '') !== '') { $loc[] = 'từ khóa "' . $q . '"'; }
        if (in_array($tt ?? '', ['Hoạt động', 'Bị khóa'], true)) { $loc[] = 'trạng thái ' . $tt; }
        $pdf->doan('Bộ lọc: ' . ($loc ? implode(', ', $loc) : 'không'), 9, false, '#5b6b8c');
    }
    $pdf->khoang(8);
    $pdf->oSoLieu([
        ['Tổng người dùng', number_format($tong, 0, ',', '.'), ''],
        ['Hoạt động',       number_format($hoatDong, 0, ',', '.'), ''],
        ['Bị khóa',         number_format($biKhoa, 0, ',', '.'), ''],
    ]);
    $anhPdf = array_values(array_filter([$anhTk['jpeg'] ?? null, $anhNgay['jpeg'] ?? null]));
    if ($anhPdf) { $pdf->anhHang($anhPdf, 230); }
    if (!$coDs) { $pdf->tai($tenFile . '.pdf'); }

    $tyLe = [];
    $canGiua = [];
    $mauO = [];
    $viTriTT = null;
    foreach ($truong as $k) {
        if ($k === 'elo') {
            foreach ($monHoc as $m) { $canGiua[] = count($tyLe); $tyLe[] = 1.4; }
        } else {
            if ($k === 'tt') { $viTriTT = count($tyLe); }
            if (in_array($k, ['ma', 'sotran', 'tt'], true)) { $canGiua[] = count($tyLe); }
            $tyLe[] = $COT[$k][1];
        }
    }
    $dongPdf = [];
    foreach ($dong as $i => $d) {
        $o = [];
        foreach ($d as $v) { $o[] = $v === null ? '—' : (string) $v; }
        $dongPdf[] = $o;
        if ($viTriTT !== null) {
            $mauO[$i] = [$viTriTT => ($d[$viTriTT] ?? '') === 'Bị khóa' ? '#fde3e6' : '#dcf5ea'];   // tô nền ô trạng thái
        }
    }
    $pdf->khoang(6);
    $pdf->bang($tieuDe, $dongPdf, $tyLe, [], $mauO, $canGiua);
    $pdf->tai($tenFile . '.pdf');
} catch (Throwable $ex) {
    loi_xuat('Không tạo được file xuất: ' . $ex->getMessage(), 500);
}