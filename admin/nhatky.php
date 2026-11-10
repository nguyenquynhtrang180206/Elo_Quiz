<?php
// Xem nhật ký quản trị (UC16: A-41, A-42; phân trang 6.7). Đặt tại: elo_quiz/admin/nhatky.php
// Chỉ xem: trang này không có thao tác sửa hoặc xóa nào và không nhận POST (A-42).
// Giao diện: dùng cùng khung (nền + sidebar) với trang Tổng quan, nên KHÔNG bật $giaoDienMoi.
require_once __DIR__ . '/../core/bootstrap.php';
Auth::requireAdmin(BASE_URL . '/admin/login.php');   // A-04

$pdo = Database::pdo();

// ---- Dữ liệu cho bộ lọc ----
$dsHanhDong = $pdo->query('SELECT DISTINCT HanhDong FROM NHATKYQUANTRI ORDER BY HanhDong')->fetchAll(PDO::FETCH_COLUMN);

// ---- A-41: bộ lọc dựng ở NhatKy::dieuKien() (dùng chung với file xuất để hai nơi lọc giống hệt nhau) ----
$kq      = NhatKy::dieuKien($_GET);
$where   = $kq['where'];
$tham    = $kq['tham'];
$thamLoc = $kq['loc'];   // bộ lọc đã kiểm tra: dùng dựng link phân trang, link xuất và điền lại form
$loi     = $kq['loi'];
$q   = (string) ($thamLoc['q']   ?? '');
$hd  = (string) ($thamLoc['hd']  ?? '');
$tu  = (string) ($thamLoc['tu']  ?? '');
$den = (string) ($thamLoc['den'] ?? '');
$tt  = (string) ($thamLoc['tt']  ?? '');   // trạng thái: Thành công | Thất bại (đã kiểm tra trong NhatKy::dieuKien)
$nhom = (string) ($thamLoc['nhom'] ?? '');   // nhóm hành động (chip lọc nhanh): mo | sua | xoa | khoa, đã kiểm tra trong NhatKy::dieuKien

// Sắp xếp theo thời gian: mặc định mới nhất trước; ?sx=asc là cũ nhất trước. Chỉ nhận hai giá trị này.
$sx      = (($_GET['sx'] ?? '') === 'asc') ? 'asc' : 'desc';
$thamLink = $thamLoc + ($sx === 'asc' ? ['sx' => 'asc'] : []);   // dùng dựng link phân trang (giữ cả bộ lọc và chiều sắp xếp)

// Xuất Excel/PDF: hộp thoại ở cuối trang, xử lý ở admin/nhatky_xuat.php (nhận lại bộ lọc đang chọn bằng các ô ẩn)

// ---- 4 thẻ số liệu (tính trên toàn bộ nhật ký, không phụ thuộc bộ lọc) ----
$mocHomNay = date('Y-m-d') . ' 00:00:00';
$moc7      = date('Y-m-d', strtotime('-6 days')) . ' 00:00:00';
$moc30     = date('Y-m-d', strtotime('-29 days')) . ' 00:00:00';
$moc60     = date('Y-m-d', strtotime('-59 days')) . ' 00:00:00';
$st = $pdo->prepare(
    "SELECT COUNT(*) AS tong,
            COALESCE(SUM(ThoiGian >= ?), 0) AS homnay,
            COALESCE(SUM(ThoiGian >= ?), 0) AS nay30,
            COALESCE(SUM(ThoiGian >= ? AND ThoiGian < ?), 0) AS truoc30,
            COALESCE(SUM(TrangThai = 'Thất bại' AND ThoiGian >= ?), 0) AS thatbai
     FROM NHATKYQUANTRI"
);
$st->execute([$mocHomNay, $moc30, $moc60, $moc30, $moc7]);
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

// ---- Dữ liệu cho biểu đồ "Nhịp hoạt động hôm nay" và khối "Cơ cấu hành động" (tính trên toàn bộ nhật ký, không phụ thuộc bộ lọc) ----
$nhip   = NhatKy::nhipTheoNgay($pdo, 7);   // mặc định 7 ngày gần nhất; đổi tuần/tháng hoặc lùi ngày thì JS gọi nhatky_nhip.php
$nhipTong = array_sum(array_column($nhip, 'tong'));
$nhipLoi  = array_sum(array_column($nhip, 'loi'));
$ngayCu   = date('Y-m-d', strtotime('-' . NhatKy::GIU_THANG . ' months'));   // nhật ký chỉ giữ GIU_THANG tháng nên không chọn xa hơn
$coCau  = NhatKy::coCau30Ngay($pdo);

// ---- Danh sách có phân trang (20 dòng/trang, có hiển thị tổng số) ----
$st = $pdo->prepare("SELECT COUNT(*) FROM NHATKYQUANTRI n JOIN ADMIN a ON a.MaAdmin = n.MaAdmin $where");
$st->execute($tham);
$p = new Paginator((int) $st->fetchColumn(), 10);

$st = $pdo->prepare(
    "SELECT n.MaNhatKy, n.HanhDong, n.DoiTuong, n.ThoiGian, n.MoTa, CAST(n.TrangThai AS CHAR) AS TrangThai, n.DiaChiIP, a.HoTen, a.TenDangNhap
     FROM NHATKYQUANTRI n
     JOIN ADMIN a ON a.MaAdmin = n.MaAdmin
     $where
     ORDER BY n.ThoiGian " . strtoupper($sx) . ', n.MaNhatKy ' . strtoupper($sx) . "
     LIMIT " . $p->limit() . ' OFFSET ' . $p->offset()
);
$st->execute($tham);
$ds = $st->fetchAll();

// ---- Gom theo ngày cho dạng dòng thời gian ----
// Số hoạt động / số thất bại ở tiêu đề mỗi ngày tính trên CẢ NGÀY (theo bộ lọc đang chọn), không chỉ phần nằm trong trang này.
$tenThu   = ['Chủ Nhật', 'Thứ Hai', 'Thứ Ba', 'Thứ Tư', 'Thứ Năm', 'Thứ Sáu', 'Thứ Bảy'];
$nhomNgay = [];
foreach ($ds as $r) {
    $ts = strtotime($r['ThoiGian']);
    $kn = date('Y-m-d', $ts);
    if (!isset($nhomNgay[$kn])) {
        $nhomNgay[$kn] = ['nhan' => $tenThu[(int) date('w', $ts)] . ', ' . date('d/m/Y', $ts), 'tong' => 0, 'loi' => 0, 'dong' => []];
    }
    $nhomNgay[$kn]['dong'][] = $r;
}
if ($nhomNgay) {
    $cacNgay  = array_keys($nhomNgay);
    $whereNgay = ($where === '' ? 'WHERE' : $where . ' AND') . ' DATE(n.ThoiGian) IN (' . implode(',', array_fill(0, count($cacNgay), '?')) . ')';
    $st = $pdo->prepare(
        "SELECT DATE(n.ThoiGian) AS ngay, COUNT(*) AS tong, COALESCE(SUM(n.TrangThai = 'Thất bại'), 0) AS loi
         FROM NHATKYQUANTRI n
         JOIN ADMIN a ON a.MaAdmin = n.MaAdmin
         $whereNgay
         GROUP BY DATE(n.ThoiGian)"
    );
    $st->execute(array_merge($tham, $cacNgay));
    foreach ($st->fetchAll() as $r) {
        $nhomNgay[$r['ngay']]['tong'] = (int) $r['tong'];
        $nhomNgay[$r['ngay']]['loi']  = (int) $r['loi'];
    }
}

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

// Nhãn hành động: màu và biểu tượng theo nhóm (NhatKy::NHOM, cùng nguồn với chip lọc và biểu đồ cơ cấu)
$kieuHanhDong = static function (string $hd): array {
    $khoa = NhatKy::nhomCuaHanhDong($hd);
    if ($khoa === null) {
        return ['tim', 'khac'];
    }
    return [NhatKy::NHOM[$khoa][1], ['xoa' => 'xoa', 'mo' => 'them', 'khoa' => 'khoa', 'sua' => 'sua', 'xuat' => 'tai'][$khoa]];
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
$urlNhom = static function (string $khoa) use ($thamLoc, $sx): string {   // '' = bỏ lọc nhóm
    $t = array_diff_key($thamLoc, ['nhom' => 1]) + ($khoa !== '' ? ['nhom' => $khoa] : []) + ($sx === 'asc' ? ['sx' => 'asc'] : []);
    return '?' . http_build_query($t);
};
$urlSx   = '?' . http_build_query($thamLoc + ($sx === 'asc' ? [] : ['sx' => 'asc']));   // bấm tiêu đề cột Thời gian để đảo chiều
$tu1     = $ds ? $p->offset() + 1 : 0;
$den1    = $ds ? $p->offset() + count($ds) : 0;

$tieuDe  = 'Nhật ký quản trị';
$mucChon = 'nhatky';
$cssTrang = ['thongke_xuat', 'nhatky'];   // thongke_xuat: giao diện hộp thoại Xuất dùng chung với trang Tổng quan; nhatky nạp sau để ghi đè phần riêng
$jsTrang  = 'nhatky';   // panel chi tiết bên phải + biểu đồ nhịp hoạt động
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

  <section class="nk-nhip" id="nkNhipKhung" data-url="<?= e(BASE_URL) ?>/admin/nhatky_nhip.php" data-homnay="<?= e(date('Y-m-d')) ?>" data-cu="<?= e($ngayCu) ?>" data-so="7">
    <div class="nk-nhip-dau">
      <h3>Hoạt động theo ngày</h3>
      <span class="nk-nhip-phu" id="nkNhipPhu">7 ngày · <?= e(date('d/m/Y', strtotime($nhip[0]['ngay']))) ?> – <?= e(date('d/m/Y', strtotime($nhip[count($nhip) - 1]['ngay']))) ?> · <?= $so($nhipTong) ?> hoạt động<?= $nhipLoi > 0 ? ' · ' . $so($nhipLoi) . ' thất bại' : '' ?></span>
      <div class="nk-nhip-ngay">
        <button type="button" id="nkNhip7" aria-pressed="true" title="7 ngày gần nhất">7 ngày</button>
        <button type="button" id="nkNhip30" aria-pressed="false" title="30 ngày gần nhất">30 ngày</button>
        <button type="button" id="nkNhipTruoc" aria-label="Lùi lại một khoảng" title="Lùi lại">&lsaquo;</button>
        <button type="button" id="nkNhipSau" aria-label="Tiến tới một khoảng" title="Tiến tới">&rsaquo;</button>
        <button type="button" id="nkNhipMoiNhat">Mới nhất</button>
      </div>
      <span class="nk-chuthich"><i class="nk-cd nk-cd-tao"></i>Thao tác<i class="nk-cd nk-cd-loi"></i>Thất bại</span>
    </div>
    <div class="nk-nhip-khung">
      <canvas id="nkNhip" data-nhip="<?= e(json_encode($nhip, JSON_UNESCAPED_UNICODE)) ?>" role="img" aria-label="Biểu đồ số thao tác theo từng ngày"></canvas>
    </div>
  </section>

  <section class="nk-ds">
    <div class="nk-ds-dau">
      <span class="nk-ds-icon"><?= $svg('danhsach', 22) ?></span>
      <h3>Danh sách nhật ký</h3>
      <button type="button" class="nk-nut nk-nut-phu nk-nut-xuat" id="nkMoXuat" aria-haspopup="dialog" title="Xuất nhật ký ra Excel hoặc PDF"><?= $svg('tai', 18) ?>Xuất Excel / PDF</button>
    </div>


    <form method="get" action="" class="nk-loc">
      <?php if ($sx === 'asc'): ?><input type="hidden" name="sx" value="asc"><?php endif; ?>
      <?php if ($nhom !== ''): ?><input type="hidden" name="nhom" value="<?= e($nhom) ?>"><?php endif; ?>
      <label class="nk-tim-o">
        <?= $svg('tim', 18) ?>
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="Tìm theo hành động, đối tượng, mô tả..." aria-label="Tìm kiếm" autocomplete="off">
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
      <label class="nk-ngay">Từ <input type="date" name="tu" value="<?= e($tu) ?>" onchange="this.form.submit()"></label>
      <label class="nk-ngay">đến <input type="date" name="den" value="<?= e($den) ?>" onchange="this.form.submit()"></label>
      <a class="nk-nut nk-nut-chinh" href="?"><?= $svg('datlai', 18) ?>Đặt lại</a>
    </form>

    <div class="nk-chips" role="group" aria-label="Lọc theo nhóm hành động">
      <a class="nk-chip<?= $nhom === '' ? ' on' : '' ?>" href="<?= e($urlNhom('')) ?>">Tất cả</a>
      <?php foreach (NhatKy::NHOM_HIEN_THI as $k): ?>
        <a class="nk-chip nk-chip-<?= e(NhatKy::NHOM[$k][1]) ?><?= $nhom === $k ? ' on' : '' ?>" href="<?= e($urlNhom($k)) ?>"><i class="nk-cham"></i><?= e(NhatKy::NHOM[$k][0]) ?></a>
      <?php endforeach; ?>
    </div>

    <div class="nk-luoi">
      <div class="nk-tl" id="nkDanhSach">
      <?php if (!$nhomNgay): ?>
        <p class="nk-trong"><?= $coLoc ? 'Không có bản ghi nhật ký nào khớp bộ lọc.' : 'Chưa có bản ghi nhật ký nào.' ?></p>
      <?php else: foreach ($nhomNgay as $nd): ?>
        <div class="nk-ngay-dau">
          <b><?= e($nd['nhan']) ?></b>
          <span><?= $so($nd['tong']) ?> hoạt động<?= $nd['loi'] > 0 ? ' · <em>' . $so($nd['loi']) . ' thất bại</em>' : '' ?></span>
        </div>
        <ol class="nk-dong">
        <?php foreach ($nd['dong'] as $r):
            [$lopHd, $iconHd] = $kieuHanhDong((string) $r['HanhDong']);
            $thatBai = ($r['TrangThai'] ?? '') === 'Thất bại';
            $coDT    = $r['DoiTuong'] !== null && $r['DoiTuong'] !== '';
            $coMT    = $r['MoTa'] !== null && $r['MoTa'] !== '';
            // Dữ liệu cho panel chi tiết (JS đọc bằng JSON.parse rồi gán bằng textContent nên không chèn HTML)
            $chiTiet = json_encode([
                'tg'   => date('d/m/Y H:i:s', strtotime($r['ThoiGian'])),
                'hd'   => (string) $r['HanhDong'],
                'dt'   => (string) $r['DoiTuong'],
                'mota' => (string) $r['MoTa'],
                'tt'   => (string) $r['TrangThai'],
                'ma'   => '#' . (int) $r['MaNhatKy'],
                'ip'   => (string) $r['DiaChiIP'],
                'ad'   => trim($r['HoTen'] . ' (' . $r['TenDangNhap'] . ')'),
            ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
          <li class="nk-muc">
            <span class="nk-gio"><?= e(date('H:i:s', strtotime($r['ThoiGian']))) ?></span>
            <span class="nk-vong nk-hd-<?= $lopHd ?>"><?= $svg($iconHd, 18) ?></span>
            <button type="button" class="nk-card<?= $thatBai ? ' loi' : '' ?>" data-nk="<?= e($chiTiet) ?>" aria-label="Xem chi tiết bản ghi <?= (int) $r['MaNhatKy'] ?>">
              <span class="nk-card-chinh">
                <span class="nk-card-tren">
                  <b><?= e($r['HanhDong']) ?></b>
                  <?php if ($coDT): ?><span class="nk-chip-dt"><?= e($r['DoiTuong']) ?></span><?php endif; ?>
                </span>
                <?php if ($coMT): ?>
                  <span class="nk-mota<?= $thatBai ? ' loi' : '' ?>" title="<?= e($r['MoTa']) ?>"><?= $thatBai ? $svg('canhbao', 14) : '' ?><?= e(mb_strimwidth((string) $r['MoTa'], 0, 90, '…', 'UTF-8')) ?></span>
                <?php endif; ?>
              </span>
              <?php if ($thatBai): ?>
                <span class="nk-tt nk-tt-loi"><?= $svg('canhbao', 14) ?>Thất bại</span>
              <?php else: ?>
                <span class="nk-tt nk-tt-ok"><?= $svg('dung', 14) ?>Thành công</span>
              <?php endif; ?>
            </button>
          </li>
        <?php endforeach; ?>
        </ol>
      <?php endforeach; endif; ?>
      </div>

      <aside class="nk-ben">
        <section class="nk-pn" id="nkPanel">
          <div class="nk-pn-dau"><h3>Chi tiết bản ghi</h3><span data-f="ma"></span></div>
          <p class="nk-pn-rong" id="nkPanelRong">Chọn một dòng trong danh sách để xem chi tiết.</p>
          <dl class="nk-hop-ds" id="nkPanelDs" hidden>
            <dt>Thời gian</dt><dd data-f="tg"></dd>
            <dt>Hành động</dt><dd data-f="hd"></dd>
            <dt>Đối tượng</dt><dd data-f="dt"></dd>
            <dt>Mô tả</dt><dd class="nk-hop-mota" data-f="mota"></dd>
            <dt>Quản trị viên</dt><dd data-f="ad"></dd>
            <dt>Địa chỉ IP</dt><dd data-f="ip"></dd>
            <dt>Trạng thái</dt><dd><span class="nk-tt" data-f="tt"></span></dd>
          </dl>
          <p class="nk-hop-chu">Nhật ký chỉ để xem, không thể sửa hoặc xóa. Hệ thống chỉ lưu <?= (int) NhatKy::GIU_THANG ?> tháng gần nhất.</p>
        </section>

        <section class="nk-pn">
          <div class="nk-pn-dau"><h3>Cơ cấu hành động</h3><span>30 ngày</span></div>
          <?php if (!$coCau): ?>
            <p class="nk-pn-rong">Chưa có hoạt động nào trong 30 ngày qua.</p>
          <?php else: foreach ($coCau as $c): ?>
            <div class="nk-cc">
              <div class="nk-cc-dong"><span><?= e($c['nhan']) ?></span><b><?= (int) $c['pt'] ?>%</b></div>
              <div class="nk-thanh" title="<?= e($so($c['so'])) ?> hoạt động"><i class="nk-thanh-<?= e($c['lop']) ?>" style="width:<?= max(2, (int) $c['pt']) ?>%"></i></div>
            </div>
          <?php endforeach; endif; ?>
        </section>
      </aside>
    </div>

    <div class="nk-chan">
      <span class="nk-tong">
        <?php if ($ds): ?>Hiển thị <?= $so($tu1) ?>–<?= $so($den1) ?> trong tổng số <?= $so($p->tong()) ?> bản ghi
        <?php else: ?>Tổng số 0 bản ghi<?php endif; ?>
      </span>
      <?= $phanTrang($p) ?>
    </div>
  </section>

</div>

<!-- Hộp thoại Xuất nhật ký (Excel / PDF): cùng giao diện với hộp thoại Xuất của trang Tổng quan (modal-nen, modal xuat-hop);
     cột trái chọn phạm vi, trường và định dạng, cột phải xem trước. Xử lý ở nhatky_xuat.php -->
<div class="modal-nen" id="nkXuatHop" hidden>
  <form class="modal xuat-hop nk-xuat-hop" id="nkXuatForm" method="post" action="<?= e(BASE_URL) ?>/admin/nhatky_xuat.php" role="dialog" aria-modal="true" aria-labelledby="nkXuatTieuDe">
    <h3 id="nkXuatTieuDe">Xuất nhật ký quản trị</h3>
    <?= csrf_field() ?>
    <?php foreach ($thamLoc as $k => $v): ?>
      <input type="hidden" name="<?= e((string) $k) ?>" value="<?= e((string) $v) ?>">
    <?php endforeach; ?>
    <?php if ($sx === 'asc'): ?><input type="hidden" name="sx" value="asc"><?php endif; ?>
    <div class="xuat-luoi">
      <div class="xuat-chonCot">
        <fieldset>
          <legend>Xuất phạm vi nào?</legend>
          <label class="xuat-chon"><input type="radio" name="pham_vi" value="loc" checked> Đang lọc <span class="xuat-phu">(<?= $so($p->tong()) ?> bản ghi, mọi trang)</span></label>
          <label class="xuat-chon"><input type="radio" name="pham_vi" value="tatca"> Toàn bộ nhật ký <span class="xuat-phu">(<?= $so($tk['tong']) ?> bản ghi)</span></label>
        </fieldset>
        <fieldset>
          <legend>Chọn trường cần xuất</legend>
          <?php foreach ([
              'tg' => 'Thời gian', 'hd' => 'Hành động', 'dt' => 'Đối tượng', 'mota' => 'Mô tả', 'tt' => 'Trạng thái',
          ] as $k => $ten): ?>
            <label class="xuat-chon"><input type="checkbox" name="truong[]" value="<?= e($k) ?>" checked> <?= e($ten) ?></label>
          <?php endforeach; ?>
          <div class="nk-xuat-nut">
            <button type="button" class="btn btn-phu xuat-tatca" id="nkChonHet">Chọn tất cả</button>
            <button type="button" class="btn btn-phu xuat-tatca" id="nkBoChon">Bỏ chọn</button>
          </div>
        </fieldset>
        <fieldset>
          <legend>Định dạng</legend>
          <label class="xuat-chon"><input type="radio" name="dinhdang" value="excel" checked> Excel (.xlsx)</label>
          <label class="xuat-chon"><input type="radio" name="dinhdang" value="pdf"> PDF</label>
          <label class="xuat-chon"><input type="radio" name="dinhdang" value="ca"> Cả hai (tải liên tiếp 2 file)</label>
        </fieldset>
      </div>
      <div class="xuat-xemCot">
        <div class="xuat-xemDau">Xem trước <small id="nkXemGhiChu"></small></div>
        <div class="xuat-xem" id="nkXem"><p class="xuat-ghichu">Đang tải...</p></div>
      </div>
    </div>
    <p class="xuat-loi" id="nkXuatLoi" hidden></p>
    <div class="hanh-dong">
      <button type="button" class="btn btn-phu" id="nkXuatHuy">Hủy</button>
      <button type="submit" class="btn" id="nkXuatOk">Xuất</button>
    </div>
  </form>
</div>
<script src="<?= e(BASE_URL) ?>/assets/vendor/chart.umd.js"></script>
<?php require __DIR__ . '/layout/footer.php'; ?>