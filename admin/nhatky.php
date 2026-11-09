<?php
// Xem nhật ký quản trị (UC16: A-41, A-42; phân trang 6.7). Đặt tại: elo_quiz/admin/nhatky.php
// Chỉ xem: trang này không có thao tác sửa hoặc xóa nào và không nhận POST (A-42).
// Giao diện: dùng cùng khung (nền + sidebar) với trang Tổng quan, nên KHÔNG bật $giaoDienMoi.
require_once __DIR__ . '/../core/bootstrap.php';
Auth::requireAdmin(BASE_URL . '/admin/login.php');   // A-04

$pdo = Database::pdo();

// ---- Dữ liệu cho bộ lọc ----
$dsAdmin = $pdo->query('SELECT MaAdmin, HoTen, TenDangNhap FROM ADMIN ORDER BY HoTen')->fetchAll();
$dsHanhDong = $pdo->query('SELECT DISTINCT HanhDong FROM NHATKYQUANTRI ORDER BY HanhDong')->fetchAll(PDO::FETCH_COLUMN);

// ---- A-41: bộ lọc dựng ở NhatKy::dieuKien() (dùng chung với file xuất để hai nơi lọc giống hệt nhau) ----
$kq      = NhatKy::dieuKien($_GET);
$where   = $kq['where'];
$tham    = $kq['tham'];
$thamLoc = $kq['loc'];   // bộ lọc đã kiểm tra: dùng dựng link phân trang, link xuất và điền lại form
$loi     = $kq['loi'];
$q   = (string) ($thamLoc['q']   ?? '');
$ad  = (int)    ($thamLoc['ad']  ?? 0);
$hd  = (string) ($thamLoc['hd']  ?? '');
$tu  = (string) ($thamLoc['tu']  ?? '');
$den = (string) ($thamLoc['den'] ?? '');
$tt  = (string) ($thamLoc['tt']  ?? '');   // trạng thái: Thành công | Thất bại (đã kiểm tra trong NhatKy::dieuKien)

// Sắp xếp theo thời gian: mặc định mới nhất trước; ?sx=asc là cũ nhất trước. Chỉ nhận hai giá trị này.
$sx      = (($_GET['sx'] ?? '') === 'asc') ? 'asc' : 'desc';
$thamLink = $thamLoc + ($sx === 'asc' ? ['sx' => 'asc'] : []);   // dùng dựng link phân trang (giữ cả bộ lọc và chiều sắp xếp)

// ---- Xuất CSV: giữ nguyên bộ lọc đang chọn, chỉ đọc dữ liệu (không ghi gì vào nhật ký) ----
if (($_GET['xuat'] ?? '') === 'csv') {
    $st = $pdo->prepare(
        "SELECT n.ThoiGian, a.HoTen, a.TenDangNhap, n.HanhDong, n.DoiTuong, n.MoTa
         FROM NHATKYQUANTRI n
         JOIN ADMIN a ON a.MaAdmin = n.MaAdmin
         $where
         ORDER BY n.ThoiGian DESC, n.MaNhatKy DESC
         LIMIT 50000"
    );
    $st->execute($tham);

    // Ô bắt đầu bằng = + - @ có thể bị Excel hiểu là công thức: thêm dấu ' phía trước
    $an = static function (?string $s): string {
        $s = (string) $s;
        return ($s !== '' && strpbrk($s[0], "=+-@\t\r") !== false) ? "'" . $s : $s;
    };

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="nhatky_quantri_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // BOM để Excel đọc đúng tiếng Việt
    fputcsv($out, ['Thời gian', 'Quản trị viên', 'Tên đăng nhập', 'Hành động', 'Đối tượng', 'Mô tả'], ',', '"', '\\');
    foreach ($st as $r) {
        fputcsv($out, [
            date('d/m/Y H:i:s', strtotime($r['ThoiGian'])),
            $an($r['HoTen']), $an($r['TenDangNhap']), $an($r['HanhDong']), $an($r['DoiTuong']), $an($r['MoTa']),
        ], ',', '"', '\\');
    }
    fclose($out);
    exit;
}

// ---- 4 thẻ số liệu (tính trên toàn bộ nhật ký, không phụ thuộc bộ lọc) ----
$mocHomNay = date('Y-m-d') . ' 00:00:00';
$moc7      = date('Y-m-d', strtotime('-6 days')) . ' 00:00:00';
$moc30     = date('Y-m-d', strtotime('-29 days')) . ' 00:00:00';
$moc60     = date('Y-m-d', strtotime('-59 days')) . ' 00:00:00';
$st = $pdo->prepare(
    "SELECT COUNT(*) AS tong,
            COALESCE(SUM(ThoiGian >= ?), 0) AS homnay,
            COUNT(DISTINCT CASE WHEN ThoiGian >= ? THEN MaAdmin END) AS adminhd,
            COALESCE(SUM(ThoiGian >= ?), 0) AS nay30,
            COALESCE(SUM(ThoiGian >= ? AND ThoiGian < ?), 0) AS truoc30,
            COALESCE(SUM(TrangThai = 'Thất bại' AND ThoiGian >= ?), 0) AS thatbai
     FROM NHATKYQUANTRI"
);
$st->execute([$mocHomNay, $moc7, $moc30, $moc60, $moc30, $moc7]);
$tk = array_map('intval', $st->fetch());

// Mức tăng/giảm: 30 ngày gần nhất so với 30 ngày liền trước đó (cùng cách tính với trang Tổng quan)
if ($tk['truoc30'] > 0) {
    $chenh = (int) round(($tk['nay30'] - $tk['truoc30']) * 100 / $tk['truoc30']);
    $chuTang = ($chenh > 0 ? '+' : '') . $chenh . '%';
} elseif ($tk['nay30'] > 0) {
    $chenh = 1;
    $chuTang = 'mới';
} else {
    $chenh = 0;
    $chuTang = '0%';
}
$muiTang = $chenh > 0 ? '↑' : ($chenh < 0 ? '↓' : '→');
$lopTang = $chenh > 0 ? 'len' : ($chenh < 0 ? 'xuong' : 'ngang');

$so = static fn (int $n): string => number_format($n, 0, ',', '.');

// ---- Danh sách có phân trang (20 dòng/trang, có hiển thị tổng số) ----
$st = $pdo->prepare("SELECT COUNT(*) FROM NHATKYQUANTRI n JOIN ADMIN a ON a.MaAdmin = n.MaAdmin $where");
$st->execute($tham);
$p = new Paginator((int) $st->fetchColumn(), 5);

$st = $pdo->prepare(
    "SELECT n.MaNhatKy, n.MaAdmin, n.HanhDong, n.DoiTuong, n.ThoiGian, n.MoTa, n.DiaChiIP, n.TrangThai, a.HoTen, a.TenDangNhap
     FROM NHATKYQUANTRI n
     JOIN ADMIN a ON a.MaAdmin = n.MaAdmin
     $where
     ORDER BY n.ThoiGian " . strtoupper($sx) . ', n.MaNhatKy ' . strtoupper($sx) . "
     LIMIT " . $p->limit() . ' OFFSET ' . $p->offset()
);
$st->execute($tham);
$ds = $st->fetchAll();

// ---- Biểu tượng (SVG nội tuyến, kiểu Lucide) ----
$bt = [
    'khien'   => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
    'tep'     => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/>',
    'lich'    => '<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/>',
    'nhom'    => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'xuhuong' => '<polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/>',
    'danhsach'=> '<path d="M3 12h.01"/><path d="M3 18h.01"/><path d="M3 6h.01"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M8 6h13"/>',
    'tai'     => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/>',
    'tim'     => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
    'canhbao' => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
    'dung'    => '<path d="M20 6 9 17l-5-5"/>',
    'cham'    => '<circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>',
    'sapxep'  => '<path d="m21 16-4 4-4-4"/><path d="M17 20V4"/><path d="m3 8 4-4 4 4"/><path d="M7 4v16"/>',
    'datlai'  => '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/>',
    // biểu tượng nhỏ trong nhãn hành động
    'sua'     => '<path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/><path d="m15 5 4 4"/>',
    'xoa'     => '<path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/>',
    'them'    => '<path d="M5 12h14"/><path d="M12 5v14"/>',
    'khoa'    => '<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
    'khac'    => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="1"/>',
];
$svg = static function (string $k, int $px = 20) use ($bt): string {
    return '<svg class="bt" width="' . $px . '" height="' . $px . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
         . 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $bt[$k] . '</svg>';
};

// Nhãn hành động: màu và biểu tượng theo từ khóa trong tên hành động (kiểm tra theo thứ tự)
$kieuHanhDong = static function (string $hd): array {
    $theo = [
        ['do',   'xoa',  ['Xóa', 'Hủy']],
        ['luc',  'them', ['Mở khóa', 'Hiện', 'Thêm', 'Import']],
        ['cam',  'khoa', ['Khóa', 'Ẩn']],
        ['xanh', 'sua',  ['Sửa', 'Đổi', 'Cập nhật']],
    ];
    foreach ($theo as [$lop, $icon, $tuKhoa]) {
        foreach ($tuKhoa as $k) {
            if (mb_stripos($hd, $k) !== false) {
                return [$lop, $icon];
            }
        }
    }
    return ['tim', 'khac'];
};

// Thanh phân trang kiểu ảnh thiết kế: ‹ 1 2 3 … ›  (giữ nguyên các tham số lọc)
$phanTrang = static function (Paginator $pg) use ($thamLink): string {
    if ($pg->soTrang() <= 1) {
        return '';
    }
    $url = static fn (int $n): string => '?' . http_build_query($thamLink + ['page' => $n]);
    $t  = $pg->trang();
    $sT = $pg->soTrang();
    $h  = '<nav class="nk-pt" aria-label="Phân trang">';
    $h .= $t > 1 ? '<a href="' . e($url($t - 1)) . '" aria-label="Trang trước">&lsaquo;</a>' : '<span class="tat">&lsaquo;</span>';
    $tu  = max(1, min($t - 1, $sT - 2));
    $den = min($sT, $tu + 2);
    for ($i = $tu; $i <= $den; $i++) {
        $h .= $i === $t ? '<strong aria-current="page">' . $i . '</strong>' : '<a href="' . e($url($i)) . '">' . $i . '</a>';
    }
    if ($den < $sT) {
        $h .= '<a href="' . e($url($den + 1)) . '" aria-label="Các trang sau">&hellip;</a>';
    }
    $h .= $t < $sT ? '<a href="' . e($url($t + 1)) . '" aria-label="Trang sau">&rsaquo;</a>' : '<span class="tat">&rsaquo;</span>';
    return $h . '</nav>';
};

$coLoc   = $thamLoc !== [];
$urlXuat = '?' . http_build_query($thamLoc + ['xuat' => 'csv']);
$urlSx   = '?' . http_build_query($thamLoc + ($sx === 'asc' ? [] : ['sx' => 'asc']));   // bấm tiêu đề cột Thời gian để đảo chiều
$tu1     = $ds ? $p->offset() + 1 : 0;
$den1    = $ds ? $p->offset() + count($ds) : 0;

$tieuDe  = 'Nhật ký quản trị';
$mucChon = 'nhatky';
$cssTrang = 'nhatky';
$jsTrang  = 'nhatky';   // hộp xem chi tiết (nút …)
require __DIR__ . '/layout/header.php';
?>
<div class="nk-khung">

  <section class="nk-hero">
    <span class="nk-hero-icon"><?= $svg('khien', 34) ?></span>
    <div class="nk-hero-chu">
      <h2>Nhật ký quản trị</h2>
      <p>Theo dõi các hoạt động quan trọng của quản trị viên trong hệ thống.</p>
    </div>
  </section>

  <div class="nk-the">
    <div class="nk-so nk-tim">
      <span class="nk-so-icon"><?= $svg('tep', 24) ?></span>
      <div class="nk-so-nd">
        <div class="nk-so-nhan">Tổng hoạt động</div>
        <div class="nk-so-lon"><?= e($so($tk['tong'])) ?></div>
        <div class="nk-so-phu"><span class="nk-tang <?= $lopTang ?>"><?= $muiTang ?> <?= e($chuTang) ?></span> so với 30 ngày trước đó</div>
      </div>
    </div>
    <div class="nk-so nk-xanh">
      <span class="nk-so-icon"><?= $svg('lich', 24) ?></span>
      <div class="nk-so-nd">
        <div class="nk-so-nhan">Hôm nay</div>
        <div class="nk-so-lon"><?= e($so($tk['homnay'])) ?></div>
        <div class="nk-so-phu">Hoạt động ghi nhận</div>
      </div>
    </div>
    <div class="nk-so nk-luc">
      <span class="nk-so-icon"><?= $svg('nhom', 24) ?></span>
      <div class="nk-so-nd">
        <div class="nk-so-nhan">Quản trị viên</div>
        <div class="nk-so-lon"><?= e($so($tk['adminhd'])) ?></div>
        <div class="nk-so-phu">Có hoạt động 7 ngày qua (trên tổng <?= count($dsAdmin) ?>)</div>
      </div>
    </div>
    <div class="nk-so nk-do">
      <span class="nk-so-icon"><?= $svg('canhbao', 24) ?></span>
      <div class="nk-so-nd">
        <div class="nk-so-nhan">Thao tác thất bại</div>
        <div class="nk-so-lon"><?= e($so($tk['thatbai'])) ?></div>
        <div class="nk-so-phu"><?= $tk['thatbai'] > 0 ? '<b class="nk-canhbao">Cần kiểm tra</b> · 7 ngày qua' : 'Không có lỗi trong 7 ngày qua' ?></div>
      </div>
    </div>
  </div>

  <?php if ($loi): ?>
    <div class="loi"><?= e($loi) ?></div>
  <?php endif; ?>

  <section class="nk-ds">
    <div class="nk-ds-dau">
      <span class="nk-ds-icon"><?= $svg('danhsach', 22) ?></span>
      <h3>Danh sách nhật ký</h3>
      <a class="nk-nut nk-nut-phu nk-nut-xuat" href="<?= e($urlXuat) ?>" title="Tải CSV theo bộ lọc đang chọn"><?= $svg('tai', 18) ?>Xuất CSV</a>
    </div>

    <form method="get" action="" class="nk-loc">
      <?php if ($sx === 'asc'): ?><input type="hidden" name="sx" value="asc"><?php endif; ?>
      <label class="nk-tim-o">
        <?= $svg('tim', 18) ?>
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="Tìm theo quản trị viên, hành động, đối tượng, mô tả..." aria-label="Tìm kiếm" autocomplete="off">
      </label>
      <select name="hd" aria-label="Hành động" onchange="this.form.submit()">
        <option value="">Tất cả hành động</option>
        <?php foreach ($dsHanhDong as $h): ?>
          <option value="<?= e($h) ?>"<?= $hd === $h ? ' selected' : '' ?>><?= e($h) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="tt" aria-label="Trạng thái" onchange="this.form.submit()">
        <option value="">Tất cả trạng thái</option>
        <?php foreach (['Thành công', 'Thất bại'] as $t): ?>
          <option value="<?= e($t) ?>"<?= $tt === $t ? ' selected' : '' ?>><?= e($t) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="ad" aria-label="Quản trị viên" onchange="this.form.submit()">
        <option value="0">Tất cả quản trị viên</option>
        <?php foreach ($dsAdmin as $a): ?>
          <option value="<?= (int) $a['MaAdmin'] ?>"<?= $ad === (int) $a['MaAdmin'] ? ' selected' : '' ?>><?= e($a['HoTen'] . ' (' . $a['TenDangNhap'] . ')') ?></option>
        <?php endforeach; ?>
      </select>
      <label class="nk-ngay">Từ <input type="date" name="tu" value="<?= e($tu) ?>" onchange="this.form.submit()"></label>
      <label class="nk-ngay">đến <input type="date" name="den" value="<?= e($den) ?>" onchange="this.form.submit()"></label>
      <a class="nk-nut nk-nut-chinh" href="?"><?= $svg('datlai', 18) ?>Đặt lại</a>
    </form>

    <div class="nk-cuon">
      <table class="nk-bang">
        <thead>
          <tr>
            <th><a class="nk-sx" href="<?= e($urlSx) ?>" title="<?= $sx === 'asc' ? 'Đang xếp cũ nhất trước, bấm để xếp mới nhất trước' : 'Đang xếp mới nhất trước, bấm để xếp cũ nhất trước' ?>">Thời gian <?= $svg('sapxep', 14) ?></a></th>
            <th>Quản trị viên</th><th>Hành động</th><th>Nội dung</th><th>Địa chỉ IP</th><th>Trạng thái</th><th class="nk-th-tt">Thao tác</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$ds): ?>
          <tr><td colspan="7" class="nk-trong"><?= $coLoc ? 'Không có bản ghi nhật ký nào khớp bộ lọc.' : 'Chưa có bản ghi nhật ký nào.' ?></td></tr>
        <?php else: foreach ($ds as $r):
            [$lopHd, $iconHd] = $kieuHanhDong((string) $r['HanhDong']);
            $chuCai   = mb_strtoupper(mb_substr(trim((string) $r['HoTen']), 0, 1)) ?: 'A';
            $thatBai  = ($r['TrangThai'] ?? '') === 'Thất bại';
            $coDT     = $r['DoiTuong'] !== null && $r['DoiTuong'] !== '';
            $coMT     = $r['MoTa'] !== null && $r['MoTa'] !== '';
            // Dữ liệu cho hộp chi tiết (JS đọc bằng JSON.parse rồi gán bằng textContent nên không chèn HTML)
            $chiTiet  = json_encode([
                'tg'   => date('d/m/Y H:i:s', strtotime($r['ThoiGian'])),
                'ten'  => (string) $r['HoTen'],
                'user' => (string) $r['TenDangNhap'],
                'hd'   => (string) $r['HanhDong'],
                'dt'   => (string) $r['DoiTuong'],
                'mota' => (string) $r['MoTa'],
                'ip'   => (string) $r['DiaChiIP'],
                'tt'   => (string) $r['TrangThai'],
                'ma'   => '#' . (int) $r['MaNhatKy'],
            ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
          <tr>
            <td class="nk-tg"><?= e(date('d/m/Y H:i:s', strtotime($r['ThoiGian']))) ?></td>
            <td>
              <div class="nk-nguoi">
                <span class="nk-ava nk-ava<?= (int) $r['MaAdmin'] % 5 ?>" aria-hidden="true"><?= e($chuCai) ?></span>
                <span class="nk-nguoi-ten"><b><?= e($r['HoTen']) ?></b><small><?= e($r['TenDangNhap']) ?></small></span>
              </div>
            </td>
            <td><span class="nk-hd nk-hd-<?= $lopHd ?>"><?= $svg($iconHd, 14) ?><?= e($r['HanhDong']) ?></span></td>
            <td class="nk-nd">
              <?php if ($coDT): ?><span class="nk-dt"><?= e($r['DoiTuong']) ?></span><?php endif; ?>
              <?php if ($coMT): ?><span class="nk-mota" title="<?= e($r['MoTa']) ?>"><?= e(mb_strimwidth((string) $r['MoTa'], 0, 90, '…', 'UTF-8')) ?></span><?php endif; ?>
              <?php if (!$coDT && !$coMT): ?><span class="nk-rong">—</span><?php endif; ?>
            </td>
            <td class="nk-ip"><?= $r['DiaChiIP'] !== null && $r['DiaChiIP'] !== '' ? e($r['DiaChiIP']) : '<span class="nk-rong">—</span>' ?></td>
            <td>
              <?php if ($thatBai): ?>
                <span class="nk-tt nk-tt-loi"><?= $svg('canhbao', 14) ?>Thất bại</span>
              <?php else: ?>
                <span class="nk-tt nk-tt-ok"><?= $svg('dung', 14) ?>Thành công</span>
              <?php endif; ?>
            </td>
            <td class="nk-td-tt">
              <button type="button" class="nk-more" data-nk="<?= e($chiTiet) ?>" aria-label="Xem chi tiết bản ghi <?= (int) $r['MaNhatKy'] ?>" title="Xem chi tiết"><?= $svg('cham', 18) ?></button>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <dialog class="nk-hop" id="nkChiTiet" aria-labelledby="nkHopTieuDe">
      <div class="nk-hop-nd">
        <div class="nk-hop-dau">
          <h3 id="nkHopTieuDe">Chi tiết nhật ký <span data-f="ma"></span></h3>
          <button type="button" class="nk-hop-dong" data-dong aria-label="Đóng">&times;</button>
        </div>
        <dl class="nk-hop-ds">
          <dt>Thời gian</dt><dd data-f="tg"></dd>
          <dt>Quản trị viên</dt><dd><span data-f="ten"></span> (<span data-f="user"></span>)</dd>
          <dt>Hành động</dt><dd data-f="hd"></dd>
          <dt>Đối tượng</dt><dd data-f="dt"></dd>
          <dt>Mô tả</dt><dd class="nk-hop-mota" data-f="mota"></dd>
          <dt>Địa chỉ IP</dt><dd data-f="ip"></dd>
          <dt>Trạng thái</dt><dd><span class="nk-tt" data-f="tt"></span></dd>
        </dl>
        <p class="nk-hop-chu">Nhật ký chỉ để xem, không thể sửa hoặc xóa.</p>
      </div>
    </dialog>

    <div class="nk-chan">
      <span class="nk-tong">
        <?php if ($ds): ?>Hiển thị <?= $so($tu1) ?>–<?= $so($den1) ?> trong tổng số <?= $so($p->tong()) ?> bản ghi
        <?php else: ?>Tổng số 0 bản ghi<?php endif; ?>
      </span>
      <?= $phanTrang($p) ?>
    </div>
  </section>

</div>
<?php require __DIR__ . '/layout/footer.php'; ?>