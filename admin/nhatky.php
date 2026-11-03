<?php
// Xem nhật ký quản trị (UC16: A-41, A-42; phân trang 6.7). Đặt tại: elo_quiz/admin/nhatky.php
// Chỉ xem: trang này không có thao tác sửa hoặc xóa nào và không nhận POST (A-42).
require_once __DIR__ . '/../core/bootstrap.php';
Auth::requireAdmin(BASE_URL . '/admin/login.php');   // A-04

$pdo = Database::pdo();

// ---- Dữ liệu cho bộ lọc ----
$dsAdmin = $pdo->query('SELECT MaAdmin, HoTen, TenDangNhap FROM ADMIN ORDER BY HoTen')->fetchAll();
$dsHanhDong = $pdo->query('SELECT DISTINCT HanhDong FROM NHATKYQUANTRI ORDER BY HanhDong')->fetchAll(PDO::FETCH_COLUMN);

// ---- A-41: bộ lọc theo quản trị viên, hành động, đối tượng, khoảng thời gian ----
$ad  = (int) ($_GET['ad'] ?? 0);
$hd  = (string) ($_GET['hd'] ?? '');
$dt  = trim((string) ($_GET['dt'] ?? ''));
$tu  = (string) ($_GET['tu'] ?? '');
$den = (string) ($_GET['den'] ?? '');

// Ngày phải đúng dạng YYYY-MM-DD và là ngày có thật
$ngayHopLe = static function (string $s): bool {
    $d = DateTime::createFromFormat('Y-m-d', $s);
    return $d !== false && $d->format('Y-m-d') === $s;
};
if ($tu !== '' && !$ngayHopLe($tu))   { $tu = ''; }
if ($den !== '' && !$ngayHopLe($den)) { $den = ''; }
if (!in_array($hd, $dsHanhDong, true)) { $hd = ''; }

$loi = '';
if ($tu !== '' && $den !== '' && $tu > $den) {
    $loi = 'Ngày bắt đầu không được sau ngày kết thúc.';
    $tu = $den = '';
}

$dieuKien = [];
$tham     = [];
if ($ad > 0)    { $dieuKien[] = 'n.MaAdmin = ?';  $tham[] = $ad; }
if ($hd !== '') { $dieuKien[] = 'n.HanhDong = ?'; $tham[] = $hd; }
if ($dt !== '') {
    $dieuKien[] = 'n.DoiTuong LIKE ?';
    $tham[]     = '%' . addcslashes($dt, '\\%_') . '%';
}
if ($tu !== '') {
    $dieuKien[] = 'n.ThoiGian >= ?';
    $tham[]     = $tu . ' 00:00:00';
}
if ($den !== '') {   // đến hết ngày kết thúc: nhỏ hơn 00:00 của ngày kế tiếp
    $dieuKien[] = 'n.ThoiGian < ?';
    $tham[]     = (new DateTime($den))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
}
$where = $dieuKien ? 'WHERE ' . implode(' AND ', $dieuKien) : '';

// ---- Danh sách có phân trang (20 dòng/trang, có hiển thị tổng số) ----
$st = $pdo->prepare("SELECT COUNT(*) FROM NHATKYQUANTRI n $where");
$st->execute($tham);
$p = new Paginator((int) $st->fetchColumn(), 20);

$st = $pdo->prepare(
    "SELECT n.MaNhatKy, n.HanhDong, n.DoiTuong, n.ThoiGian, n.MoTa, a.HoTen, a.TenDangNhap
     FROM NHATKYQUANTRI n
     JOIN ADMIN a ON a.MaAdmin = n.MaAdmin
     $where
     ORDER BY n.ThoiGian DESC, n.MaNhatKy DESC
     LIMIT " . $p->limit() . ' OFFSET ' . $p->offset()
);
$st->execute($tham);
$ds = $st->fetchAll();

$tieuDe  = 'Nhật ký quản trị';
$mucChon = 'nhatky';
$cssTrang = 'nhatky';
require __DIR__ . '/layout/header.php';
?>
<h2>Nhật ký quản trị</h2>

<?php if ($loi): ?>
  <div class="loi"><?= e($loi) ?></div>
<?php endif; ?>

<form method="get" action="" class="card form-loc">
  <select name="ad">
    <option value="0">Mọi quản trị viên</option>
    <?php foreach ($dsAdmin as $a): ?>
      <option value="<?= (int) $a['MaAdmin'] ?>"<?= $ad === (int) $a['MaAdmin'] ? ' selected' : '' ?>><?= e($a['HoTen'] . ' (' . $a['TenDangNhap'] . ')') ?></option>
    <?php endforeach; ?>
  </select>
  <select name="hd">
    <option value="">Mọi hành động</option>
    <?php foreach ($dsHanhDong as $h): ?>
      <option value="<?= e($h) ?>"<?= $hd === $h ? ' selected' : '' ?>><?= e($h) ?></option>
    <?php endforeach; ?>
  </select>
  <input type="text" name="dt" value="<?= e($dt) ?>" placeholder="Đối tượng (ví dụ CAUHOI)" class="o-doituong">
  <label class="nhan-phu">Từ ngày <input type="date" name="tu" value="<?= e($tu) ?>"></label>
  <label class="nhan-phu">đến ngày <input type="date" name="den" value="<?= e($den) ?>"></label>
  <button type="submit" class="btn">Lọc</button>
  <a class="btn btn-phu" href="?">Xóa lọc</a>
</form>

<div class="card">
  <?php if (!$ds): ?>
    <p class="nhan-phu">Không có bản ghi nhật ký nào khớp.</p>
  <?php else: ?>
    <table>
      <tr><th>Thời gian</th><th>Quản trị viên</th><th>Hành động</th><th>Đối tượng</th><th>Mô tả</th></tr>
      <?php foreach ($ds as $r): ?>
        <tr>
          <td><?= e(date('d/m/Y H:i:s', strtotime($r['ThoiGian']))) ?></td>
          <td><?= e($r['HoTen']) ?> <span class="nhan-phu">(<?= e($r['TenDangNhap']) ?>)</span></td>
          <td><?= e($r['HanhDong']) ?></td>
          <td><?= e($r['DoiTuong']) ?></td>
          <td class="xuong-dong"><?= e($r['MoTa']) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
  <?= $p->links() ?>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>