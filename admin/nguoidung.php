<?php
// Danh sách người dùng (UC11: A-11, A-45). Đặt tại: elo_quiz/admin/nguoidung.php
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/XlsxWriter.php';
Auth::requireAdmin(BASE_URL . '/admin/login.php');   // A-04

$pdo = Database::pdo();

// ---- Bộ lọc: từ khóa (họ tên, email, lớp, khoa) và trạng thái ----
$q  = trim((string) ($_GET['q'] ?? ''));
$tt = (string) ($_GET['tt'] ?? '');
if (!in_array($tt, ['Hoạt động', 'Bị khóa'], true)) {
    $tt = '';
}

$dieuKien = [];
$tham     = [];
if ($q !== '') {
    $like       = '%' . addcslashes($q, '\\%_') . '%';   // thoát % và _ để tìm đúng chữ người dùng gõ
    $dieuKien[] = '(n.HoTen LIKE ? OR n.Email LIKE ? OR n.Lop LIKE ? OR n.Khoa LIKE ?)';
    array_push($tham, $like, $like, $like, $like);
}
if ($tt !== '') {
    $dieuKien[] = 'n.TrangThai = ?';
    $tham[]     = $tt;
}
$where = $dieuKien ? 'WHERE ' . implode(' AND ', $dieuKien) : '';

$cotChon = "n.MaND, n.HoTen, n.Email, n.Lop, n.Khoa, n.TrangThai, n.NgayTao,
            (SELECT COALESCE(SUM(e.SoTran), 0) FROM ELOMONHOC e WHERE e.MaND = n.MaND) AS so_tran";

// ---- A-45: xuất Excel theo đúng bộ lọc đang xem ----
if (isset($_GET['xuat'])) {
    $st = $pdo->prepare("SELECT $cotChon FROM NGUOIDUNG n $where ORDER BY n.NgayTao DESC, n.MaND DESC LIMIT 10000");
    $st->execute($tham);
    $nguoi = $st->fetchAll();

    $monHoc = $pdo->query('SELECT MaMon, TenMon FROM MONHOC ORDER BY MaMon')->fetchAll();

    $st = $pdo->prepare("SELECT e.MaND, e.MaMon, e.DiemElo FROM ELOMONHOC e JOIN NGUOIDUNG n ON n.MaND = e.MaND $where");
    $st->execute($tham);
    $elo = [];
    foreach ($st as $r) {
        $elo[(int) $r['MaND']][(int) $r['MaMon']] = (int) $r['DiemElo'];
    }

    $tieuDe = ['Họ tên', 'Email', 'Lớp', 'Khoa', 'Trạng thái'];
    foreach ($monHoc as $m) {
        $tieuDe[] = 'Elo - ' . $m['TenMon'];
    }
    $tieuDe[] = 'Số trận';

    $dong = [];
    foreach ($nguoi as $r) {
        $d = [$r['HoTen'], $r['Email'], $r['Lop'], $r['Khoa'], $r['TrangThai']];
        foreach ($monHoc as $m) {
            $d[] = $elo[(int) $r['MaND']][(int) $m['MaMon']] ?? null;   // chưa chơi môn nào thì để trống
        }
        $d[]    = (int) $r['so_tran'];
        $dong[] = $d;
    }
    XlsxWriter::tai('nguoidung_' . date('Ymd_His') . '.xlsx', 'Người dùng', $tieuDe, $dong);
}

// ---- Danh sách có phân trang (20 dòng/trang, có hiển thị tổng số) ----
$st = $pdo->prepare("SELECT COUNT(*) FROM NGUOIDUNG n $where");
$st->execute($tham);
$p = new Paginator((int) $st->fetchColumn(), 20);

$st = $pdo->prepare(
    "SELECT $cotChon FROM NGUOIDUNG n $where
     ORDER BY n.NgayTao DESC, n.MaND DESC
     LIMIT " . $p->limit() . ' OFFSET ' . $p->offset()
);
$st->execute($tham);
$ds = $st->fetchAll();

$linkXuat = '?' . http_build_query(array_merge(array_diff_key($_GET, ['page' => 1]), ['xuat' => 1]));

$tieuDe  = 'Người dùng';
$mucChon = 'nguoidung';
$cssTrang = 'nguoidung';
require __DIR__ . '/layout/header.php';
?>
<h2>Người dùng</h2>

<form method="get" action="" class="card form-loc">
  <input type="text" name="q" value="<?= e($q) ?>" placeholder="Tìm theo họ tên, email, lớp, khoa" class="o-tim">
  <select name="tt">
    <option value="">Mọi trạng thái</option>
    <option value="Hoạt động"<?= $tt === 'Hoạt động' ? ' selected' : '' ?>>Hoạt động</option>
    <option value="Bị khóa"<?= $tt === 'Bị khóa' ? ' selected' : '' ?>>Bị khóa</option>
  </select>
  <button type="submit" class="btn">Tìm</button>
  <a class="btn btn-phu" href="?">Xóa lọc</a>
  <a class="btn btn-phu" href="<?= e($linkXuat) ?>">Xuất Excel</a>
</form>

<div class="card">
  <?php if (!$ds): ?>
    <p class="nhan-phu">Không có người dùng nào khớp.</p>
  <?php else: ?>
    <table>
      <tr><th>Mã</th><th>Họ tên</th><th>Email</th><th>Lớp</th><th>Khoa</th><th>Số trận</th><th>Trạng thái</th><th>Ngày tạo</th></tr>
      <?php foreach ($ds as $r): ?>
        <tr>
          <td><?= (int) $r['MaND'] ?></td>
          <td><a href="<?= e(BASE_URL) ?>/admin/nguoidung_chitiet.php?id=<?= (int) $r['MaND'] ?>"><?= e($r['HoTen']) ?></a></td>
          <td><?= e($r['Email']) ?></td>
          <td><?= e($r['Lop']) ?></td>
          <td><?= e($r['Khoa']) ?></td>
          <td><?= (int) $r['so_tran'] ?></td>
          <td><?= $r['TrangThai'] === 'Bị khóa' ? '<span class="mau-do dam">Bị khóa</span>' : 'Hoạt động' ?></td>
          <td><?= e(date('d/m/Y H:i', strtotime($r['NgayTao']))) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
  <?= $p->links() ?>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>