<?php
// Kiểm duyệt cộng đồng (UC15: A-32, A-33, A-34). Đặt tại: elo_quiz/admin/congdong.php
// Giao diện chia đôi: 4 biểu đồ ở đầu trang, danh sách bên trái, xem trước + xử lý bên phải.
// Hai tab: ?loai=bai (bài đăng), ?loai=bl (bình luận). Chỉ ẩn/hiện, không xóa cứng; mọi lần ẩn/hiện đều bắt buộc lý do và ghi nhật ký.
// A-33: bài sinh viên đã tự xóa ("Đã xóa") không được hiện lại. Admin chỉ chuyển qua lại Hiển thị <-> Bị ẩn.
// Logic ẩn/hiện dùng chung CongDongHelper::kiemDuyet (cùng trang Cộng đồng phía client và congdong_baidang.php).
require_once __DIR__ . '/../core/bootstrap.php';
// CongDongHelper, ThongBaoHelper, NhatKy, Paginator được bootstrap.php tự nạp (autoloader); KHÔNG require_once tay
// core/CongDongHelper.php hay core/ThongBaoHelper.php ở đây: dễ nạp trùng lớp và báo "Cannot declare class ThongBaoHelper".
Auth::requireAdmin(BASE_URL . '/admin/dangnhap.php');   // A-04

$pdo = Database::pdo();

// ---- Cấu hình nhỏ (sửa tại đây nếu muốn đổi) ----
$LY_DO_AN  = ['Spam / quảng cáo', 'Sai nội dung học thuật', 'Ngôn từ không phù hợp', 'Lộ đề thi / gian lận'];
$LY_DO_HIEN = ['Đã xem xét lại, nội dung phù hợp', 'Ẩn nhầm'];
$TU_NHAY_CAM = ['vcl', 'vkl', 'đéo', 'óc chó', 'mất dạy', 'đồ ngu', 'đm'];   // chỉ để GỢI Ý cho admin, không tự động ẩn

// ---- Tham số lọc ----
$loai = (string) ($_GET['loai'] ?? 'bai');
if (!in_array($loai, ['bai', 'bl'], true)) {
    $loai = 'bai';
}
$dsTT = ['Hiển thị', 'Bị ẩn', 'Đã xóa'];
$tt   = (string) ($_GET['tt'] ?? '');
if (!in_array($tt, $dsTT, true)) {
    $tt = '';
}
$q = trim((string) ($_GET['q'] ?? ''));

$dsMon = [];
foreach ($pdo->query('SELECT MaMon, TenMon FROM MONHOC ORDER BY TenMon')->fetchAll() as $m) {
    $dsMon[(int) $m['MaMon']] = (string) $m['TenMon'];
}
$mon = (int) ($_GET['mon'] ?? 0);
if (!isset($dsMon[$mon])) {
    $mon = 0;
}
$coLoaiBai = CongDongHelper::coCotLoaiBai();
$lb = (string) ($_GET['lb'] ?? '');
if (!$coLoaiBai || $loai !== 'bai' || !in_array($lb, CongDongHelper::LOAI_BAI, true)) {
    $lb = '';
}
$sx = (string) ($_GET['sx'] ?? 'moi');
if (!in_array($sx, $loai === 'bai' ? ['moi', 'cu', 'nhieu'] : ['moi', 'cu'], true)) {
    $sx = 'moi';
}
$chon = (int) ($_GET['chon'] ?? 0);

// ---- Xử lý ẩn / hiện (một mục hoặc hàng loạt) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $hd  = (string) ($_POST['hanh_dong'] ?? '');
    $ly  = (string) ($_POST['ly_do'] ?? '');
    // Gửi từ trang xem bài (congdong_baidang.php) thì sau khi xử lý quay lại đúng trang đó
    $veBai = (int) ($_POST['ve_bai'] ?? 0);
    $ids   = array_slice(array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])), static fn ($v) => $v > 0))), 0, 50);

    $giu = $_GET;
    unset($giu['kq'], $giu['n'], $giu['t']);
    if ($ids) {
        // Hàng loạt: cùng một lý do cho mọi mục; mục nào đã đổi trạng thái (hoặc không đúng loại) thì bị bỏ qua
        $ok = 0;
        $kq = 'ok';
        foreach ($ids as $i => $id) {
            $r = CongDongHelper::kiemDuyet($pdo, $hd, $id, $ly);
            if ($r === 'thieuly' || $r === 'sai') {
                $kq = $i === 0 ? $r : $kq;
                break;
            }
            $ok += $r === 'ok' ? 1 : 0;
        }
        $giu['kq'] = $kq === 'ok' ? 'hl' : $kq;
        if ($kq === 'ok') {
            $giu['n'] = $ok;
            $giu['t'] = count($ids);
        }
        unset($giu['chon']);
        redirect('congdong.php?' . http_build_query($giu));
    }

    $kq = CongDongHelper::kiemDuyet($pdo, $hd, (int) ($_POST['ma'] ?? 0), $ly);
    if ($veBai > 0) {
        redirect('congdong_baidang.php?' . http_build_query(['id' => $veBai] + ($kq === 'sai' ? [] : ['kq' => $kq])));
    }
    if ($kq !== 'sai') {
        $giu['kq'] = $kq;
    }
    redirect('congdong.php?' . http_build_query($giu));
}

$kqGet    = (string) ($_GET['kq'] ?? '');
$thongBao = $kqGet === 'ok' ? 'Đã cập nhật, ghi nhật ký và gửi thông báo cho sinh viên.' : '';
if ($kqGet === 'hl') {
    $thongBao = 'Đã xử lý ' . (int) ($_GET['n'] ?? 0) . '/' . (int) ($_GET['t'] ?? 0)
        . ' mục đã chọn (mục đã đổi trạng thái hoặc sinh viên đã tự xóa sẽ được bỏ qua).';
}
$loiBao = [
    'thieuly' => 'Hãy nhập lý do trước khi ẩn hoặc hiện lại.',
    'khong'   => 'Không thực hiện được: trạng thái đã thay đổi (hoặc bài đã bị sinh viên tự xóa). Hãy kiểm tra lại danh sách.',
][$kqGet] ?? '';

// ---- Danh sách có phân trang (20 dòng/trang) ----
$dk   = [];
$tham = [];
if ($tt !== '') {
    $dk[]   = 'x.TrangThai = ?';
    $tham[] = $tt;
}
if ($q !== '') {
    $like = '%' . addcslashes($q, '\\%_') . '%';
    if ($loai === 'bai') {
        $dk[] = '(x.TieuDe LIKE ? OR x.NoiDung LIKE ? OR n.HoTen LIKE ?)';
    } else {
        $dk[] = '(x.NoiDung LIKE ? OR n.HoTen LIKE ? OR b.TieuDe LIKE ?)';
    }
    array_push($tham, $like, $like, $like);
}
if ($mon > 0) {
    $dk[]   = ($loai === 'bai' ? 'x.MaMon' : 'b.MaMon') . ' = ?';
    $tham[] = $mon;
}
if ($lb !== '') {
    $dk[]   = 'x.LoaiBai = ?';
    $tham[] = $lb;
}
$where = $dk ? 'WHERE ' . implode(' AND ', $dk) : '';

if ($loai === 'bai') {
    $cotPhu = ($coLoaiBai ? ', x.LoaiBai' : ', NULL AS LoaiBai') . (CongDongHelper::coCotNguoiAn() ? ', x.NguoiAn' : ', NULL AS NguoiAn');
    $from  = 'FROM BAIDANG x JOIN NGUOIDUNG n ON n.MaND = x.MaND LEFT JOIN MONHOC m ON m.MaMon = x.MaMon';
    $cot   = 'x.MaBaiDang AS ma, x.TieuDe, x.NoiDung, x.NgayDang AS ngay, x.TrangThai, n.MaND, n.HoTen, n.AnhDaiDien, m.TenMon' . $cotPhu . ',
             (SELECT COUNT(*) FROM BINHLUAN c WHERE c.MaBaiDang = x.MaBaiDang) AS so_bl';
    $order = [
        'moi'   => 'ORDER BY x.NgayDang DESC, x.MaBaiDang DESC',
        'cu'    => 'ORDER BY x.NgayDang ASC, x.MaBaiDang ASC',
        'nhieu' => 'ORDER BY so_bl DESC, x.NgayDang DESC',
    ][$sx];
} else {
    $cotPhu = CongDongHelper::coCotNguoiAnBL() ? ', x.NguoiAn' : ', NULL AS NguoiAn';
    $from  = 'FROM BINHLUAN x JOIN NGUOIDUNG n ON n.MaND = x.MaND JOIN BAIDANG b ON b.MaBaiDang = x.MaBaiDang';
    $cot   = 'x.MaBinhLuan AS ma, x.NoiDung, x.NgayBinhLuan AS ngay, x.TrangThai, n.MaND, n.HoTen, n.AnhDaiDien,
             b.MaBaiDang, b.TieuDe AS tieu_de_bai, b.TrangThai AS tt_bai' . $cotPhu;
    $order = [
        'moi' => 'ORDER BY x.NgayBinhLuan DESC, x.MaBinhLuan DESC',
        'cu'  => 'ORDER BY x.NgayBinhLuan ASC, x.MaBinhLuan ASC',
    ][$sx];
}

$st = $pdo->prepare("SELECT COUNT(*) $from $where");
$st->execute($tham);
$tongKQ = (int) $st->fetchColumn();
$p = new Paginator($tongKQ, 20);

$st = $pdo->prepare("SELECT $cot $from $where $order LIMIT " . $p->limit() . ' OFFSET ' . $p->offset());
$st->execute($tham);
$ds = $st->fetchAll();

$soBai = (int) $pdo->query('SELECT COUNT(*) FROM BAIDANG')->fetchColumn();
$soBL  = (int) $pdo->query('SELECT COUNT(*) FROM BINHLUAN')->fetchColumn();

// ---- Hàm dựng link giữ nguyên bộ lọc hiện tại ----
$thamUrl = ['loai' => $loai, 'tt' => $tt, 'q' => $q, 'mon' => $mon, 'lb' => $lb, 'sx' => $sx === 'moi' ? '' : $sx];
$lienKet = static function (array $doi) use ($thamUrl): string {
    $kq = array_filter(array_merge($thamUrl, $doi), static fn ($v) => $v !== '' && $v !== null && $v !== 0 && $v !== false);
    return '?' . http_build_query($kq);
};

// ---- Mục đang chọn (mặc định: mục đầu của trang) ----
$maChon = $chon;
if ($maChon <= 0 && $ds) {
    $maChon = (int) $ds[0]['ma'];
}
$viTri = null;
foreach ($ds as $i => $r) {
    if ((int) $r['ma'] === $maChon) {
        $viTri = $i;
        break;
    }
}

$sel = null;
if ($maChon > 0) {
    if ($loai === 'bai') {
        $st = $pdo->prepare(
            'SELECT b.MaBaiDang AS ma, b.TieuDe, b.NoiDung, b.NgayDang AS ngay, b.TrangThai, b.MaMon, m.TenMon, n.MaND, n.HoTen'
            . ($coLoaiBai ? ', b.LoaiBai' : ', NULL AS LoaiBai') . (CongDongHelper::coCotNguoiAn() ? ', b.NguoiAn' : ', NULL AS NguoiAn')
            . ' FROM BAIDANG b JOIN NGUOIDUNG n ON n.MaND = b.MaND LEFT JOIN MONHOC m ON m.MaMon = b.MaMon WHERE b.MaBaiDang = ?'
        );
    } else {
        $st = $pdo->prepare(
            'SELECT c.MaBinhLuan AS ma, c.NoiDung, c.NgayBinhLuan AS ngay, c.TrangThai, n.MaND, n.HoTen, b.MaBaiDang,
                    b.TieuDe AS tieu_de_bai, b.NoiDung AS nd_bai, b.TrangThai AS tt_bai, b.MaMon, m.TenMon'
            . (CongDongHelper::coCotNguoiAnBL() ? ', c.NguoiAn' : ', NULL AS NguoiAn')
            . ' FROM BINHLUAN c JOIN NGUOIDUNG n ON n.MaND = c.MaND JOIN BAIDANG b ON b.MaBaiDang = c.MaBaiDang
                LEFT JOIN MONHOC m ON m.MaMon = b.MaMon WHERE c.MaBinhLuan = ?'
        );
    }
    $st->execute([$maChon]);
    $sel = $st->fetch() ?: null;
}

$binhLuanBai = [];
$conBL = false;
$lichSu = [];
$soAnTacGia = 0;
$eloTxt = '';
if ($sel) {
    if ($loai === 'bai') {
        $st = $pdo->prepare(
            'SELECT c.MaBinhLuan, c.NoiDung, c.NgayBinhLuan, c.TrangThai, n.MaND, n.HoTen
               FROM BINHLUAN c JOIN NGUOIDUNG n ON n.MaND = c.MaND
              WHERE c.MaBaiDang = ? ORDER BY c.NgayBinhLuan, c.MaBinhLuan LIMIT 31'
        );
        $st->execute([$maChon]);
        $binhLuanBai = $st->fetchAll();
        $conBL = count($binhLuanBai) > 30;
        $binhLuanBai = array_slice($binhLuanBai, 0, 30);
    }
    // Lịch sử kiểm duyệt của chính mục này (đọc từ nhật ký quản trị)
    $st = $pdo->prepare(
        'SELECT n.HanhDong, n.MoTa, n.ThoiGian, a.HoTen FROM NHATKYQUANTRI n JOIN ADMIN a ON a.MaAdmin = n.MaAdmin
          WHERE n.DoiTuong = ? ORDER BY n.ThoiGian DESC, n.MaNhatKy DESC LIMIT 5'
    );
    $st->execute([($loai === 'bai' ? 'BAIDANG #' : 'BINHLUAN #') . $maChon]);
    $lichSu = $st->fetchAll();

    // Số nội dung của người này đang bị ẩn (bài + bình luận)
    $sqlA = "SELECT (SELECT COUNT(*) FROM BAIDANG WHERE MaND = ? AND TrangThai = 'Bị ẩn'"
        . (CongDongHelper::coCotNguoiAn() ? " AND NguoiAn = 'Quản trị'" : '') . ")
        + (SELECT COUNT(*) FROM BINHLUAN WHERE MaND = ? AND TrangThai = 'Bị ẩn'"
        . (CongDongHelper::coCotNguoiAnBL() ? " AND NguoiAn = 'Quản trị'" : '') . ')';
    $st = $pdo->prepare($sqlA);
    $st->execute([(int) $sel['MaND'], (int) $sel['MaND']]);
    $soAnTacGia = (int) $st->fetchColumn();

    // Elo của người đăng (môn của bài; bài không gắn môn thì lấy môn cao nhất), chỉ khi đã đủ số trận tối thiểu
    $eloNguoi = CongDongHelper::layElo($pdo, [(int) $sel['MaND']])[(int) $sel['MaND']] ?? [];
    if ($eloNguoi) {
        $maMonSel = $sel['MaMon'] !== null ? (int) $sel['MaMon'] : null;
        $d = null;
        if ($maMonSel !== null) {
            $d = $eloNguoi[$maMonSel] ?? null;
        } else {
            foreach ($eloNguoi as $x) {
                if ($d === null || $x['elo'] > $d['elo']) {
                    $d = $x;
                }
            }
        }
        if ($d !== null) {
            $eloTxt = 'Elo ' . $d['mon'] . ': ' . number_format($d['elo']);
        }
    }
}

// ---- Gợi ý từ ngữ nhạy cảm (chỉ gợi ý, admin tự quyết) ----
$mauNhayCam = '/(?<![\p{L}\p{N}])(' . implode('|', array_map(static fn ($t) => preg_quote($t, '/'), $TU_NHAY_CAM)) . ')(?![\p{L}\p{N}])/iu';
$coTuNhay = static fn (string $s): bool => $TU_NHAY_CAM && preg_match($mauNhayCam, $s) === 1;

// ---- Dữ liệu 4 biểu đồ ----
$bieuDo = ['ngay' => [], 'bai' => [], 'bl' => [], 'an' => [], 'hien' => [], 'trangThai' => [0, 0, 0], 'tongMuc' => 0,
          'phanLoai' => ['tieuDe' => '', 'nhan' => [], 'so' => []]];
$mocNgay = [];
for ($i = 6; $i >= 0; $i--) {
    $t = strtotime("-$i day");
    $mocNgay[date('Y-m-d', $t)] = date('d/m', $t);
}
$tuNgay = (string) array_key_first($mocNgay);
$demTheoNgay = static function (PDO $pdo, string $sql, string $tuNgay): array {
    try {
        $st = $pdo->prepare($sql);
        $st->execute([$tuNgay]);
        return $st->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Throwable $ex) {
        return [];
    }
};
$b7  = $demTheoNgay($pdo, 'SELECT DATE(NgayDang) d, COUNT(*) FROM BAIDANG WHERE NgayDang >= ? GROUP BY d', $tuNgay);
$l7  = $demTheoNgay($pdo, 'SELECT DATE(NgayBinhLuan) d, COUNT(*) FROM BINHLUAN WHERE NgayBinhLuan >= ? GROUP BY d', $tuNgay);
$an7 = $demTheoNgay($pdo, "SELECT DATE(ThoiGian) d, COUNT(*) FROM NHATKYQUANTRI WHERE ThoiGian >= ? AND HanhDong IN ('Ẩn bài đăng', 'Ẩn bình luận') GROUP BY d", $tuNgay);
$hi7 = $demTheoNgay($pdo, "SELECT DATE(ThoiGian) d, COUNT(*) FROM NHATKYQUANTRI WHERE ThoiGian >= ? AND HanhDong IN ('Hiện bài đăng', 'Hiện bình luận') GROUP BY d", $tuNgay);
foreach ($mocNgay as $khoa => $nhan) {
    $bieuDo['ngay'][] = $nhan;
    $bieuDo['bai'][]  = (int) ($b7[$khoa] ?? 0);
    $bieuDo['bl'][]   = (int) ($l7[$khoa] ?? 0);
    $bieuDo['an'][]   = (int) ($an7[$khoa] ?? 0);
    $bieuDo['hien'][] = (int) ($hi7[$khoa] ?? 0);
}
foreach (['BAIDANG', 'BINHLUAN'] as $bang) {
    foreach ($pdo->query("SELECT TrangThai, COUNT(*) FROM $bang GROUP BY TrangThai")->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
        $vt = array_search($k, $dsTT, true);
        if ($vt !== false) {
            $bieuDo['trangThai'][$vt] += (int) $v;
        }
    }
}
$bieuDo['tongMuc'] = array_sum($bieuDo['trangThai']);
if ($coLoaiBai) {
    $bieuDo['phanLoai']['tieuDe'] = 'Bài đăng theo loại';
    $rows = $pdo->query("SELECT LoaiBai, COUNT(*) FROM BAIDANG WHERE TrangThai <> 'Đã xóa' GROUP BY LoaiBai ORDER BY 2 DESC")->fetchAll(PDO::FETCH_KEY_PAIR);
} else {
    $bieuDo['phanLoai']['tieuDe'] = 'Bài đăng theo môn';
    $rows = $pdo->query(
        "SELECT COALESCE(m.TenMon, 'Chưa phân loại') AS ten, COUNT(*) FROM BAIDANG x LEFT JOIN MONHOC m ON m.MaMon = x.MaMon
          WHERE x.TrangThai <> 'Đã xóa' GROUP BY ten ORDER BY 2 DESC LIMIT 5"
    )->fetchAll(PDO::FETCH_KEY_PAIR);
}
foreach ($rows as $k => $v) {
    $bieuDo['phanLoai']['nhan'][] = (string) $k;
    $bieuDo['phanLoai']['so'][]   = (int) $v;
}

// ---- Hàm hiển thị ----
$cat = static fn (string $s, int $n = 150): string => mb_strlen($s) > $n ? mb_substr($s, 0, $n) . '…' : $s;
$chuCai = static function (string $hoTen): string {
    // Lấy chữ/số đầu của từ cuối; tên kết thúc bằng emoji thì lùi về các từ trước, không có thì lấy chữ/số đầu tiên trong tên
    $tu = preg_split('/\s+/u', trim($hoTen)) ?: [];
    for ($i = count($tu) - 1; $i >= 0; $i--) {
        if (preg_match('/[\p{L}\p{N}]/u', (string) $tu[$i], $m)) {
            return mb_strtoupper($m[0]);
        }
    }
    return '?';
};

$lopTT  = ['Hiển thị' => 'cdm-tt-hien', 'Bị ẩn' => 'cdm-tt-an', 'Đã xóa' => 'cdm-tt-xoa'];
$nhanTT = static function (array $r): string {
    return $r['TrangThai'] . ($r['TrangThai'] === 'Bị ẩn' && !empty($r['NguoiAn']) ? ' · ' . $r['NguoiAn'] : '')
        . ($r['TrangThai'] === 'Đã xóa' ? ' · Sinh viên' : '');
};
$lopLoai = ['Thảo luận' => 'cdm-l1', 'Hỏi đáp' => 'cdm-l2', 'Chia sẻ mẹo' => 'cdm-l3', 'Tìm bạn ôn thi' => 'cdm-l4', 'Khác' => 'cdm-l5'];

// Liên kết "bỏ qua, xem mục tiếp"
$lkTiep = null;
if ($viTri !== null && isset($ds[$viTri + 1])) {
    $lkTiep = $lienKet(['chon' => (int) $ds[$viTri + 1]['ma'], 'page' => $p->trang()]);
} elseif ($p->trang() < $p->soTrang()) {
    $lkTiep = $lienKet(['page' => $p->trang() + 1]);
}

$tieuDe   = 'Kiểm duyệt cộng đồng';
$mucChon  = 'congdong';
$cssTrang = 'congdong';
$jsTrang  = 'congdong';
require __DIR__ . '/layout/header.php';

// Phân trang: không giữ tham số chon trong link trang
$maChonHienTai = $maChon;
unset($_GET['chon'], $_GET['kq'], $_GET['n'], $_GET['t']);
?>
<div class="cdm">
  <header class="cdm-dau">
    <div class="cdm-icon" aria-hidden="true">✓</div>
    <div class="cdm-tieude">
      <h2>Kiểm duyệt cộng đồng</h2>
      <p>Xử lý bài đăng và bình luận của sinh viên ngay trên một màn hình</p>
    </div>
    <div class="cdm-dau-phai">
      <a class="cdm-nut" href="<?= e(BASE_URL) ?>/client/congdong.php" target="_blank" rel="noopener">↗ Xem trang Cộng đồng</a>
    </div>
  </header>

  <!-- 4 biểu đồ -->
  <script type="application/json" id="du-lieu-congdong"><?= json_encode($bieuDo, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
  <section class="cdm-bd">
    <div class="cdm-bd-the"><h5>Hoạt động 7 ngày qua</h5><small>Bài đăng và bình luận mới mỗi ngày</small><div class="cdm-cv"><canvas id="bdHoatDong"></canvas></div></div>
    <div class="cdm-bd-the"><h5>Trạng thái nội dung</h5><small>Bài đăng + bình luận</small><div class="cdm-cv"><canvas id="bdTrangThai"></canvas></div></div>
    <div class="cdm-bd-the"><h5><?= e($bieuDo['phanLoai']['tieuDe']) ?></h5><small>Không tính bài đã xóa</small><div class="cdm-cv"><canvas id="bdPhanLoai"></canvas></div></div>
    <div class="cdm-bd-the"><h5>Thao tác kiểm duyệt</h5><small>Ẩn và hiện lại, 7 ngày</small><div class="cdm-cv"><canvas id="bdThaoTac"></canvas></div></div>
  </section>

  <?php if ($thongBao !== ''): ?><div class="thanhcong"><?= e($thongBao) ?></div><?php endif; ?>
  <?php if ($loiBao !== ''): ?><div class="loi"><?= e($loiBao) ?></div><?php endif; ?>

  <!-- Tab + tìm kiếm + bộ lọc -->
  <?php $soLocBat = ($tt !== '' ? 1 : 0) + ($mon > 0 ? 1 : 0) + ($lb !== '' ? 1 : 0) + ($sx !== 'moi' ? 1 : 0); ?>
  <div class="cdm-boloc" id="cdmBoLoc">
  <form method="get" action="" class="cdm-loc" id="cdmFormLoc">
    <input type="hidden" name="loai" value="<?= e($loai) ?>">
    <div class="cdm-tab">
      <a class="<?= $loai === 'bai' ? 'on' : '' ?>" href="<?= e($lienKet(['loai' => 'bai', 'lb' => '', 'sx' => ''])) ?>">Bài đăng<i><?= $soBai ?></i></a>
      <a class="<?= $loai === 'bl' ? 'on' : '' ?>" href="<?= e($lienKet(['loai' => 'bl', 'lb' => '', 'sx' => ''])) ?>">Bình luận<i><?= $soBL ?></i></a>
    </div>
    <input type="search" name="q" value="<?= e($q) ?>" class="cdm-tim" maxlength="100"
           placeholder="<?= $loai === 'bai' ? 'Tìm theo tiêu đề, nội dung, người đăng…' : 'Tìm theo nội dung, người bình luận, tiêu đề bài…' ?>">
    <div class="cdm-loc-phu">
    <select name="mon" data-tu-gui aria-label="Lọc theo môn">
      <option value="0">Môn: Tất cả</option>
      <?php foreach ($dsMon as $maM => $tenM): ?>
        <option value="<?= (int) $maM ?>"<?= $mon === $maM ? ' selected' : '' ?>><?= e($tenM) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="tt" data-tu-gui aria-label="Lọc theo trạng thái">
      <option value="">Trạng thái: Tất cả</option>
      <?php foreach ($dsTT as $v): ?><option value="<?= e($v) ?>"<?= $tt === $v ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
    </select>
    <?php if ($loai === 'bai' && $coLoaiBai): ?>
    <select name="lb" data-tu-gui aria-label="Lọc theo loại bài">
      <option value="">Loại bài: Tất cả</option>
      <?php foreach (CongDongHelper::LOAI_BAI as $v): ?><option value="<?= e($v) ?>"<?= $lb === $v ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
    </select>
    <?php endif; ?>
    <select name="sx" data-tu-gui aria-label="Sắp xếp">
      <option value="moi"<?= $sx === 'moi' ? ' selected' : '' ?>>Mới nhất</option>
      <option value="cu"<?= $sx === 'cu' ? ' selected' : '' ?>>Cũ nhất</option>
      <?php if ($loai === 'bai'): ?><option value="nhieu"<?= $sx === 'nhieu' ? ' selected' : '' ?>>Nhiều bình luận nhất</option><?php endif; ?>
    </select>
    <?php if ($q !== '' || $tt !== '' || $mon > 0 || $lb !== '' || $sx !== 'moi'): ?>
      <a class="cdm-xoaloc" href="?loai=<?= e($loai) ?>">✕ Xóa lọc</a>
    <?php endif; ?>
    </div>
    <button type="submit" class="cdm-nut cdm-nut-chinh cdm-nut-tim">Tìm</button>
    <!-- Chỉ hiện trên điện thoại: mở / đóng cụm ô lọc (Môn, Trạng thái, Loại bài, Sắp xếp) -->
    <button type="button" class="cdm-nut cdm-nut-loc" id="cdmNutLoc" aria-expanded="false" aria-controls="cdmBoLoc">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 5h18M6 12h12M10 19h4"/></svg>
      Bộ lọc<?php if ($soLocBat > 0): ?><i class="cdm-loc-dem"><?= $soLocBat ?></i><?php endif; ?>
    </button>
  </form>

  </div>

  <div class="cdm-chia">
    <!-- ===== BÊN TRÁI: DANH SÁCH ===== -->
    <section class="cdm-ds">
      <div class="cdm-ds-dau">
        <label class="cdm-cb"><input type="checkbox" id="cdmChonTatCa"<?= $ds ? '' : ' disabled' ?>><span></span></label>
        <span><span class="cdm-chu-chon">Chọn tất cả · </span><?= count($ds) ?> / <?= $tongKQ ?> mục</span>
        <span class="cdm-ds-trang">Trang <?= $p->trang() ?> / <?= $p->soTrang() ?></span>
        <?php if ($ds): ?><button type="button" class="cdm-nut cdm-nut-nho cdm-nut-chon" id="cdmCheDoChon" aria-pressed="false">Chọn</button><?php endif; ?>
      </div>

      <!-- Thanh xử lý hàng loạt: hiện khi có mục được tích; dùng cùng một lý do cho mọi mục -->
      <form method="post" action="" id="formHangLoat" class="cdm-hl" hidden>
        <?= csrf_field() ?>
        <strong><span id="cdmSoChon">0</span> mục đã chọn</strong>
        <input type="text" name="ly_do" maxlength="300" required placeholder="Lý do chung (bắt buộc, gửi cho từng sinh viên)">
        <button type="submit" name="hanh_dong" value="an_<?= e($loai) ?>" class="cdm-nut cdm-nut-do">Ẩn đã chọn</button>
        <button type="submit" name="hanh_dong" value="hien_<?= e($loai) ?>" class="cdm-nut">Hiện lại đã chọn</button>
      </form>

      <?php if (!$ds): ?>
        <p class="cdm-trong">Không có mục nào khớp.</p>
      <?php else: ?>
        <?php foreach ($ds as $r):
            $ma = (int) $r['ma'];
            $daXoa = $r['TrangThai'] === 'Đã xóa';
            $nhayCam = $loai === 'bl' && $coTuNhay((string) $r['NoiDung']);
            ?>
          <div class="cdm-it<?= $ma === $maChonHienTai ? ' chon' : '' ?>">
            <label class="cdm-cb"><input type="checkbox" name="ids[]" value="<?= $ma ?>" form="formHangLoat" class="cdm-chon-hang"<?= $daXoa ? ' disabled' : '' ?>><span></span></label>
            <a class="cdm-it-lk" href="<?= e($lienKet(['chon' => $ma, 'page' => $p->trang()])) ?>">
              <span class="cdm-av"><?= e($chuCai((string) $r['HoTen'])) ?><?php if (!empty($r['AnhDaiDien'])): ?><img class="cdm-av-anh" src="<?= e(BASE_URL . '/' . ltrim((string) $r['AnhDaiDien'], '/')) ?>" alt="" loading="lazy" onerror="this.remove()"><?php endif; ?></span>
              <span class="cdm-it-nd">
                <?php if ($loai === 'bai'): ?>
                  <strong><?= e($r['TieuDe']) ?></strong>
                  <em><?= e($cat($r['NoiDung'])) ?></em>
                <?php else: ?>
                  <strong><?= e($cat($r['NoiDung'], 110)) ?></strong>
                  <em>Dưới bài #<?= (int) $r['MaBaiDang'] ?> · <?= e($cat($r['tieu_de_bai'], 60)) ?></em>
                <?php endif; ?>
                <span class="cdm-meta">
                  <?php if ($loai === 'bai'): ?>
                    <?php if (!empty($r['LoaiBai'])): ?><span class="cdm-tag <?= e($lopLoai[$r['LoaiBai']] ?? 'cdm-l1') ?>"><?= e($r['LoaiBai']) ?></span><?php endif; ?>
                    <?php if (!empty($r['TenMon'])): ?><span class="cdm-tag cdm-l-mon"><?= e($r['TenMon']) ?></span><?php endif; ?>
                  <?php endif; ?>
                  <span><?= e($r['HoTen']) ?> · <?= e(CongDongHelper::truocDo((string) $r['ngay'])) ?></span>
                  <?php if ($loai === 'bai'): ?><span>💬 <?= (int) $r['so_bl'] ?></span><?php endif; ?>
                  <?php if ($nhayCam): ?><span class="cdm-canh-bao">● Từ ngữ khiếm nhã?</span><?php endif; ?>
                  <span class="cdm-tt <?= e($lopTT[$r['TrangThai']] ?? '') ?>"><?= e($nhanTT($r)) ?></span>
                </span>
              </span>
            </a>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
      <div class="cdm-phantrang"><?= $p->links() ?></div>
    </section>

    <!-- ===== BÊN PHẢI: XEM TRƯỚC + XỬ LÝ ===== -->
    <aside class="cdm-pv" id="cdmPv">
      <?php if (!$sel): ?>
        <p class="cdm-trong">Chọn một mục ở bên trái để xem nội dung và xử lý.</p>
      <?php else:
          $tt0 = (string) $sel['TrangThai'];
          $hd0 = $tt0 === 'Hiển thị' ? 'an_' . $loai : ($tt0 === 'Bị ẩn' ? 'hien_' . $loai : '');
          $tenLoaiMuc = $loai === 'bai' ? 'bài đăng' : 'bình luận';
          ?>
        <div class="cdm-pv-tag">
          <?php if ($loai === 'bai'): ?>
            <?php if (!empty($sel['LoaiBai'])): ?><span class="cdm-tag <?= e($lopLoai[$sel['LoaiBai']] ?? 'cdm-l1') ?>"><?= e($sel['LoaiBai']) ?></span><?php endif; ?>
          <?php endif; ?>
          <?php if (!empty($sel['TenMon'])): ?><span class="cdm-tag cdm-l-mon"><?= e($sel['TenMon']) ?></span><?php endif; ?>
          <span class="cdm-tt <?= e($lopTT[$tt0] ?? '') ?>"><?= e($nhanTT($sel)) ?></span>
          <a class="cdm-pv-lk" target="_blank" rel="noopener"
             href="<?= e(BASE_URL) ?>/client/baidang.php?id=<?= (int) ($loai === 'bai' ? $sel['ma'] : $sel['MaBaiDang']) ?>&amp;xemthu=1">Mở ở trang sinh viên ↗</a>
        </div>

        <?php if ($loai === 'bai'): ?>
          <h3><?= e($sel['TieuDe']) ?></h3>
        <?php else: ?>
          <h3>Bình luận #<?= (int) $sel['ma'] ?></h3>
        <?php endif; ?>

        <div class="cdm-nguoi">
          <span class="cdm-av"><?= e($chuCai((string) $sel['HoTen'])) ?></span>
          <div>
            <a href="<?= e(BASE_URL) ?>/admin/nguoidung_chitiet.php?id=<?= (int) $sel['MaND'] ?>"><b><?= e($sel['HoTen']) ?></b></a>
            <small>K<?= (int) $sel['MaND'] ?> · <?= e(CongDongHelper::ngay((string) $sel['ngay'])) ?><?= $eloTxt !== '' ? ' · ' . e($eloTxt) : '' ?>
              · <?= $soAnTacGia > 0 ? $soAnTacGia . ' nội dung đang bị quản trị ẩn' : 'chưa có nội dung nào bị quản trị ẩn' ?></small>
          </div>
        </div>

        <div class="cdm-noidung"><?= e($sel['NoiDung']) ?></div>
        <?php if ($loai === 'bl' && $coTuNhay((string) $sel['NoiDung'])): ?><p class="cdm-canh-bao">● Gợi ý: có từ ngữ có thể không phù hợp, hãy xem xét kỹ.</p><?php endif; ?>

        <?php if ($loai === 'bl'): ?>
          <div class="cdm-muc">Bài chứa bình luận này</div>
          <div class="cdm-bai-goc">
            <strong><?= e($sel['tieu_de_bai']) ?></strong>
            <span class="cdm-tt <?= e($lopTT[$sel['tt_bai']] ?? '') ?>"><?= e($sel['tt_bai']) ?></span>
            <p><?= e($cat((string) $sel['nd_bai'], 200)) ?></p>
            <a href="<?= e(BASE_URL) ?>/admin/congdong_baidang.php?id=<?= (int) $sel['MaBaiDang'] ?>">Xem cả bài và mọi bình luận →</a>
          </div>
        <?php else: ?>
          <div class="cdm-muc">Bình luận (<?= $conBL ? '30+' : count($binhLuanBai) ?>) — ẩn riêng từng bình luận</div>
          <?php if (!$binhLuanBai): ?>
            <p class="cdm-trong cdm-nho">Bài này chưa có bình luận.</p>
          <?php endif; ?>
          <?php foreach ($binhLuanBai as $c):
              $cHien = $c['TrangThai'] === 'Hiển thị';
              $cAn   = $c['TrangThai'] === 'Bị ẩn';
              $cNhay = $coTuNhay((string) $c['NoiDung']);
              ?>
            <div class="cdm-bl<?= $cHien ? '' : ' mo' ?>">
              <span class="cdm-av cdm-av-nho"><?= e($chuCai((string) $c['HoTen'])) ?></span>
              <div class="cdm-bl-nd">
                <b><?= e($c['HoTen']) ?></b> <?= e($cat((string) $c['NoiDung'], 160)) ?>
                <?php if ($cNhay): ?><em class="cdm-canh-bao">● Từ ngữ khiếm nhã?</em><?php endif; ?>
                <?php if (!$cHien): ?><em class="cdm-tt <?= e($lopTT[$c['TrangThai']] ?? '') ?>"><?= e($c['TrangThai']) ?></em><?php endif; ?>
              </div>
              <?php if ($cHien || $cAn): ?>
                <button type="button" class="cdm-nut cdm-nut-nho<?= $cHien && $cNhay ? ' cdm-nut-do' : '' ?>" data-doi-muc-tieu
                        data-hd="<?= $cHien ? 'an_bl' : 'hien_bl' ?>" data-ma="<?= (int) $c['MaBinhLuan'] ?>"
                        data-tieude="<?= e(($cHien ? 'Ẩn' : 'Hiện lại') . ' bình luận của ' . $c['HoTen']) ?>"
                        data-nut="<?= $cHien ? 'Ẩn bình luận' : 'Hiện lại bình luận' ?>" data-do="<?= $cHien ? '1' : '0' ?>"><?= $cHien ? 'Ẩn' : 'Hiện lại' ?></button>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
          <?php if ($conBL): ?>
            <p class="cdm-nho"><a href="<?= e(BASE_URL) ?>/admin/congdong_baidang.php?id=<?= (int) $sel['ma'] ?>">Còn nhiều bình luận nữa — xem tất cả →</a></p>
          <?php endif; ?>
        <?php endif; ?>

        <!-- Khung xử lý: mặc định nhắm vào mục đang chọn; bấm "Ẩn" ở một bình luận thì chuyển mục tiêu sang bình luận đó -->
        <?php if ($hd0 !== ''): ?>
          <form method="post" action="" class="cdm-xuly<?= $hd0 === 'an_' . $loai ? '' : ' la-hien' ?>" id="cdmFormXuLy"
                data-hd0="<?= e($hd0) ?>" data-ma0="<?= (int) $sel['ma'] ?>"
                data-tieude0="<?= e(($hd0 === 'an_' . $loai ? 'Ẩn ' : 'Hiện lại ') . $tenLoaiMuc . ' này') ?>"
                data-nut0="<?= e(($hd0 === 'an_' . $loai ? 'Ẩn ' : 'Hiện lại ') . $tenLoaiMuc) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="hanh_dong" value="<?= e($hd0) ?>" id="cdmHd">
            <input type="hidden" name="ma" value="<?= (int) $sel['ma'] ?>" id="cdmMa">
            <div class="cdm-xuly-dau"><b id="cdmXlTieuDe"><?= e(($hd0 === 'an_' . $loai ? 'Ẩn ' : 'Hiện lại ') . $tenLoaiMuc . ' này') ?></b>
              <span>— lý do bắt buộc, sinh viên sẽ nhận thông báo</span></div>
            <div class="cdm-chips" data-nhom="an"<?= $hd0 === 'an_' . $loai ? '' : ' hidden' ?>>
              <?php foreach ($LY_DO_AN as $l): ?><button type="button" class="cdm-chip" data-ly="<?= e($l) ?>"><?= e($l) ?></button><?php endforeach; ?>
              <button type="button" class="cdm-chip" data-ly="">Khác…</button>
            </div>
            <div class="cdm-chips" data-nhom="hien"<?= $hd0 === 'an_' . $loai ? ' hidden' : '' ?>>
              <?php foreach ($LY_DO_HIEN as $l): ?><button type="button" class="cdm-chip" data-ly="<?= e($l) ?>"><?= e($l) ?></button><?php endforeach; ?>
              <button type="button" class="cdm-chip" data-ly="">Khác…</button>
            </div>
            <textarea name="ly_do" id="cdmLyDo" maxlength="300" required rows="2" placeholder="Nhập lý do (bắt buộc, tối đa 300 ký tự)…"></textarea>
            <div class="cdm-xuly-nut">
              <button type="submit" class="cdm-nut <?= $hd0 === 'an_' . $loai ? 'cdm-nut-do' : 'cdm-nut-chinh' ?>" id="cdmNutGui"><?= e(($hd0 === 'an_' . $loai ? 'Ẩn ' : 'Hiện lại ') . $tenLoaiMuc) ?></button>
              <button type="button" class="cdm-nut" id="cdmNutVeMuc" hidden title="Quay về xử lý cả bài đăng thay vì bình luận đang chọn">✕ Bỏ chọn bình luận</button>
              <?php if ($lkTiep !== null): ?><a class="cdm-nut" id="cdmBoQua" href="<?= e($lkTiep) ?>">Bỏ qua, xem mục tiếp →</a><?php endif; ?>
              <span class="cdm-phim">Phím tắt: H nhập lý do · S bỏ qua · Ctrl+Enter gửi</span>
            </div>
          </form>
        <?php else: ?>
          <p class="cdm-nho">Sinh viên đã tự xóa <?= e($tenLoaiMuc) ?> này nên admin không hiện lại được.
            <?php if ($lkTiep !== null): ?><a id="cdmBoQua" href="<?= e($lkTiep) ?>">Xem mục tiếp →</a><?php endif; ?></p>
        <?php endif; ?>

        <div class="cdm-muc">Lịch sử kiểm duyệt</div>
        <?php if (!$lichSu): ?>
          <p class="cdm-nho">Chưa có lần ẩn / hiện lại nào. Hành động sẽ được ghi vào Nhật ký quản trị.</p>
        <?php else: foreach ($lichSu as $h): ?>
          <p class="cdm-ls"><b><?= e($h['HanhDong']) ?></b> · <?= e($h['HoTen']) ?> · <?= e(CongDongHelper::ngay((string) $h['ThoiGian'])) ?><br>
            <span><?= e((string) $h['MoTa']) ?></span></p>
        <?php endforeach; endif; ?>
      <?php endif; ?>
    </aside>
  </div>
</div>
<script src="<?= e(BASE_URL) ?>/assets/vendor/chart.umd.js"></script>
<?php require __DIR__ . '/layout/footer.php'; ?>