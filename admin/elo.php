<?php
// Elo và xếp hạng (UC11: A-28, A-29 qua link, A-30, A-31, A-46). Đặt tại: elo_quiz/admin/elo.php
// Ba tab: ?v=tra (tra cứu Elo), ?v=bxh (bảng xếp hạng, xuất Excel), ?v=bd (biến động Elo bất thường).
// A-31: trang này chỉ xem; không có chức năng điều chỉnh Elo thủ công, Elo chỉ đổi do kết quả trận.
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/XlsxWriter.php';
Auth::requireAdmin(BASE_URL . '/admin/dangnhap.php');   // A-04

$pdo = Database::pdo();
$v   = (string) ($_GET['v'] ?? 'tra');
if (!in_array($v, ['tra', 'bxh', 'bd'], true)) {
    $v = 'tra';
}
$monHoc = $pdo->query('SELECT MaMon, TenMon FROM MONHOC ORDER BY TenMon')->fetchAll();
$tenMon = array_column($monHoc, 'TenMon', 'MaMon');
$mon    = (int) ($_GET['mon'] ?? 0);
if ($mon !== 0 && !isset($tenMon[$mon])) {
    $mon = 0;
}

// Khai báo sẵn các biến chỉ được gán trong một tab, để phần HTML bên dưới luôn có giá trị
$q        = trim((string) ($_GET['q'] ?? ''));
$linkXuat = '';
$tenBxh   = '';
$nMin     = max(1, Config::int('SO_TRAN_TOI_THIEU_BXH', 10));
$nguong   = max(1, Config::int('NGUONG_BIEN_DONG_ELO', 150));
$gio      = max(1, Config::int('KHOANG_BIEN_DONG_ELO', 24));

$ty = static fn (int $thang, int $tran): string => $tran > 0 ? number_format($thang * 100 / $tran, 1) . '%' : '—';
$ds = [];
$p  = null;

// =====================================================================
// Tab 1 - A-28: tra cứu Elo theo người dùng và theo môn
// =====================================================================
if ($v === 'tra') {
    $dk  = [];
    $tham = [];
    if ($q !== '') {
        $like = '%' . addcslashes($q, '\\%_') . '%';
        $dk[] = '(n.HoTen LIKE ? OR n.Email LIKE ?)';
        array_push($tham, $like, $like);
    }
    if ($mon > 0) {
        $dk[]   = 'e.MaMon = ?';
        $tham[] = $mon;
    }
    $where = $dk ? 'WHERE ' . implode(' AND ', $dk) : '';

    $st = $pdo->prepare("SELECT COUNT(*) FROM ELOMONHOC e JOIN NGUOIDUNG n ON n.MaND = e.MaND $where");
    $st->execute($tham);
    $p = new Paginator((int) $st->fetchColumn(), 20);

    $st = $pdo->prepare(
        "SELECT n.MaND, n.HoTen, n.Email, m.TenMon, e.DiemElo, e.SoTran, e.SoThang, e.SoThua, e.SoHoa
           FROM ELOMONHOC e
           JOIN NGUOIDUNG n ON n.MaND = e.MaND
           JOIN MONHOC m ON m.MaMon = e.MaMon
           $where
          ORDER BY n.HoTen, m.TenMon
          LIMIT " . $p->limit() . ' OFFSET ' . $p->offset()
    );
    $st->execute($tham);
    $ds = $st->fetchAll();
}

// =====================================================================
// Tab 2 - bảng xếp hạng theo môn / toàn trường, xuất Excel (A-46)
// =====================================================================
if ($v === 'bxh') {
    if ($mon > 0) {
        // Theo môn: xếp theo Elo giảm dần, hòa Elo thì ai nhiều trận hơn xếp trước
        $from  = 'FROM ELOMONHOC e JOIN NGUOIDUNG n ON n.MaND = e.MaND WHERE e.MaMon = ?';
        $tham  = [$mon];
        $cot   = 'n.MaND, n.HoTen, n.Email, n.TrangThai, e.DiemElo AS elo, e.SoTran, e.SoThang';
        $order = 'ORDER BY e.DiemElo DESC, e.SoTran DESC, n.MaND';
        $sqlDem = "SELECT COUNT(*) $from";
    } else {
        // Toàn trường (C-37): Elo trung bình các môn đã đủ N trận; môn chưa đủ N trận không tính
        $from  = 'FROM ELOMONHOC e JOIN NGUOIDUNG n ON n.MaND = e.MaND WHERE e.SoTran >= ? GROUP BY n.MaND, n.HoTen, n.Email, n.TrangThai';
        $tham  = [$nMin];
        $cot   = 'n.MaND, n.HoTen, n.Email, n.TrangThai, ROUND(AVG(e.DiemElo)) AS elo, SUM(e.SoTran) AS SoTran, SUM(e.SoThang) AS SoThang, COUNT(*) AS so_mon';
        $order = 'ORDER BY AVG(e.DiemElo) DESC, SUM(e.SoTran) DESC, n.MaND';
        $sqlDem = "SELECT COUNT(*) FROM (SELECT n.MaND $from) x";
    }
    $tenBxh = $mon > 0 ? 'môn ' . $tenMon[$mon] : 'toàn trường';

    // A-46: xuất đúng bảng đang xem
    if (isset($_GET['xuat'])) {
        $st = $pdo->prepare("SELECT $cot $from $order LIMIT 10000");
        $st->execute($tham);
        $tieuDe = ['Hạng', 'Họ tên', 'Email', $mon > 0 ? 'Elo' : 'Elo trung bình', 'Số trận', 'Tỷ lệ thắng', 'Trạng thái'];
        $dong = [];
        foreach ($st->fetchAll() as $i => $r) {
            $dong[] = [$i + 1, $r['HoTen'], $r['Email'], (int) $r['elo'], (int) $r['SoTran'], $ty((int) $r['SoThang'], (int) $r['SoTran']), $r['TrangThai']];
        }
        XlsxWriter::tai('bxh_' . date('Ymd_His') . '.xlsx', 'BXH', $tieuDe, $dong);
    }

    $st = $pdo->prepare($sqlDem);
    $st->execute($tham);
    $p = new Paginator((int) $st->fetchColumn(), 20);

    $st = $pdo->prepare("SELECT $cot $from $order LIMIT " . $p->limit() . ' OFFSET ' . $p->offset());
    $st->execute($tham);
    $ds = $st->fetchAll();
    $linkXuat = '?' . http_build_query(array_merge(array_diff_key($_GET, ['page' => 1]), ['xuat' => 1]));
}

// =====================================================================
// Tab 3 - A-30: Elo tăng hoặc giảm quá ngưỡng trong khoảng thời gian cấu hình.
// Tính trực tiếp từ LICHSUELO khi truy vấn, không lưu cờ riêng.
// =====================================================================
if ($v === 'bd') {
    $tham   = [$gio, $nguong];
    $loc    = '';
    if ($mon > 0) {
        $loc    = 'AND l.MaMon = ?';
        $tham   = [$gio, $mon, $nguong];
    }
    $nhom = "FROM LICHSUELO l
              WHERE l.ThoiGian >= DATE_SUB(NOW(), INTERVAL ? HOUR) $loc
              GROUP BY l.MaND, l.MaMon
             HAVING ABS(SUM(l.ThayDoi)) > ?";

    $st = $pdo->prepare("SELECT COUNT(*) FROM (SELECT l.MaND $nhom) x");
    $st->execute($tham);
    $p = new Paginator((int) $st->fetchColumn(), 20);

    $st = $pdo->prepare(
        "SELECT t.MaND, n.HoTen, n.Email, n.TrangThai, m.TenMon, t.tong, t.so_tran, t.elo_dau, t.elo_cuoi
           FROM (SELECT l.MaND, l.MaMon, SUM(l.ThayDoi) AS tong, COUNT(*) AS so_tran,
                        (SELECT x.EloTruoc FROM LICHSUELO x WHERE x.MaND = l.MaND AND x.MaMon = l.MaMon
                            AND x.ThoiGian >= DATE_SUB(NOW(), INTERVAL ? HOUR)
                          ORDER BY x.ThoiGian, x.MaLichSu LIMIT 1) AS elo_dau,
                        (SELECT x.EloSau FROM LICHSUELO x WHERE x.MaND = l.MaND AND x.MaMon = l.MaMon
                            AND x.ThoiGian >= DATE_SUB(NOW(), INTERVAL ? HOUR)
                          ORDER BY x.ThoiGian DESC, x.MaLichSu DESC LIMIT 1) AS elo_cuoi
                   $nhom) t
           JOIN NGUOIDUNG n ON n.MaND = t.MaND
           JOIN MONHOC m ON m.MaMon = t.MaMon
          ORDER BY ABS(t.tong) DESC, t.MaND
          LIMIT " . $p->limit() . ' OFFSET ' . $p->offset()
    );
    $st->execute(array_merge([$gio, $gio], $tham));
    $ds = $st->fetchAll();
}

$tieuDe  = 'Elo và xếp hạng';
$mucChon = 'elo';
$cssTrang = 'elo';
require __DIR__ . '/layout/header.php';

$tabs = ['tra' => 'Tra cứu Elo', 'bxh' => 'Bảng xếp hạng', 'bd' => 'Biến động bất thường'];
?>
<div class="adm-page-header">
  <div class="adm-header-eyebrow">
    <span class="live-pulse"></span>
    <span>ELO RATING & LEADERBOARDS</span>
  </div>
  <div class="adm-header-main">
    <h2 class="adm-title-pro">
      <svg class="title-svg-icon" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="12" x2="12" y1="20" y2="10"/><line x1="18" x2="18" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="16"/></svg>
      <span>Elo và xếp hạng</span>
    </h2>
  </div>
  <p class="adm-subtitle-pro">
    Hệ thống phân cấp kỹ năng theo chuẩn quốc tế. Theo dõi bảng xếp hạng sinh viên và phát hiện biến động bất thường.
  </p>
</div>
<p class="elo-tabs">
  <?php foreach ($tabs as $k => $ten): ?>
    <a class="btn<?= $k === $v ? '' : ' btn-phu' ?>" href="?v=<?= e($k) ?>"><?= e($ten) ?></a>
  <?php endforeach; ?>
</p>
<p class="nhan-phu elo-note">Elo chỉ thay đổi do kết quả trận đấu; quản trị viên không điều chỉnh thủ công. Nghi ngờ gian lận thì khóa tài khoản ở mục Người dùng.</p>

<form method="get" action="" class="card form-loc elo-filter">
  <input type="hidden" name="v" value="<?= e($v) ?>">
  <?php if ($v === 'tra'): ?>
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Tìm theo họ tên hoặc email" class="o-tim">
  <?php endif; ?>
  <select name="mon">
    <option value="0"><?= $v === 'bxh' ? 'Toàn trường' : 'Mọi môn' ?></option>
    <?php foreach ($monHoc as $m): ?>
      <option value="<?= (int) $m['MaMon'] ?>"<?= $mon === (int) $m['MaMon'] ? ' selected' : '' ?>><?= e($m['TenMon']) ?></option>
    <?php endforeach; ?>
  </select>
  <button type="submit" class="btn">Lọc</button>
  <a class="btn btn-phu" href="?v=<?= e($v) ?>">Xóa lọc</a>
  <?php if ($v === 'bxh'): ?><a class="btn btn-phu" href="<?= e($linkXuat) ?>">Xuất Excel</a><?php endif; ?>
</form>

<?php if ($v === 'bxh'): ?>
  <p class="nhan-phu">
    BXH <?= e($tenBxh) ?>.
    <?php if ($mon === 0): ?>Elo trung bình các môn đã đủ <?= $nMin ?> trận (SO_TRAN_TOI_THIEU_BXH); môn chưa đủ thì không tính.<?php endif; ?>
  </p>
<?php elseif ($v === 'bd'): ?>
  <p class="nhan-phu">
    Người có Elo (theo từng môn) tăng hoặc giảm quá <?= $nguong ?> điểm trong <?= $gio ?> giờ gần nhất
    (NGUONG_BIEN_DONG_ELO, KHOANG_BIEN_DONG_ELO). Danh sách được tính khi mở trang, không lưu cờ riêng.
  </p>
<?php endif; ?>

<div class="card elo-results">
  <?php if (!$ds): ?>
    <p class="nhan-phu">Không có dữ liệu khớp.</p>
  <?php elseif ($v === 'tra'): ?>
    <table>
      <tr><th>Người dùng</th><th>Môn</th><th>Elo</th><th>Số trận</th><th>Thắng</th><th>Thua</th><th>Hòa</th><th>Tỷ lệ thắng</th></tr>
      <?php foreach ($ds as $r): ?>
        <tr>
          <td><a href="<?= e(BASE_URL) ?>/admin/nguoidung_chitiet.php?id=<?= (int) $r['MaND'] ?>"><?= e($r['HoTen']) ?></a><br><span class="nhan-phu"><?= e($r['Email']) ?></span></td>
          <td><?= e($r['TenMon']) ?></td>
          <td><strong><?= (int) $r['DiemElo'] ?></strong></td>
          <td><?= (int) $r['SoTran'] ?></td>
          <td><?= (int) $r['SoThang'] ?></td>
          <td><?= (int) $r['SoThua'] ?></td>
          <td><?= (int) $r['SoHoa'] ?></td>
          <td><?= e($ty((int) $r['SoThang'], (int) $r['SoTran'])) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
    <p class="nhan-phu">Lịch sử Elo chi tiết của từng người xem ở trang chi tiết người dùng (bấm vào tên).</p>
  <?php elseif ($v === 'bxh'): ?>
    <table>
      <tr><th>Hạng</th><th>Người dùng</th><th><?= $mon > 0 ? 'Elo' : 'Elo trung bình' ?></th><th>Số trận</th><th>Tỷ lệ thắng</th><th>Trạng thái</th></tr>
      <?php foreach ($ds as $i => $r): ?>
        <tr>
          <td><?= $p->offset() + $i + 1 ?></td>
          <td><a href="<?= e(BASE_URL) ?>/admin/nguoidung_chitiet.php?id=<?= (int) $r['MaND'] ?>"><?= e($r['HoTen']) ?></a><br><span class="nhan-phu"><?= e($r['Email']) ?></span></td>
          <td><strong><?= (int) $r['elo'] ?></strong></td>
          <td><?= (int) $r['SoTran'] ?></td>
          <td><?= e($ty((int) $r['SoThang'], (int) $r['SoTran'])) ?></td>
          <td><?= $r['TrangThai'] === 'Bị khóa' ? '<span class="mau-do dam">Bị khóa</span>' : 'Hoạt động' ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php else: ?>
    <table>
      <tr><th>Người dùng</th><th>Môn</th><th>Elo đầu kỳ</th><th>Elo cuối kỳ</th><th>Thay đổi</th><th>Số trận trong kỳ</th><th>Trạng thái</th></tr>
      <?php foreach ($ds as $r): $t = (int) $r['tong']; ?>
        <tr>
          <td><a href="<?= e(BASE_URL) ?>/admin/nguoidung_chitiet.php?id=<?= (int) $r['MaND'] ?>"><?= e($r['HoTen']) ?></a><br><span class="nhan-phu"><?= e($r['Email']) ?></span></td>
          <td><?= e($r['TenMon']) ?></td>
          <td><?= (int) $r['elo_dau'] ?></td>
          <td><?= (int) $r['elo_cuoi'] ?></td>
          <td><strong class="<?= $t > 0 ? 'mau-xanh' : 'mau-do' ?>"><?= $t > 0 ? '+' . $t : (string) $t ?></strong></td>
          <td><?= (int) $r['so_tran'] ?></td>
          <td><?= $r['TrangThai'] === 'Bị khóa' ? '<span class="mau-do dam">Bị khóa</span>' : 'Hoạt động' ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
  <?php if ($p) echo $p->links(); ?>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>