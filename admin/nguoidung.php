<?php
// Danh sách người dùng (UC11: A-11, A-45). Đặt tại: elo_quiz/admin/nguoidung.php
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/XlsxWriter.php';
Auth::requireAdmin(BASE_URL . '/admin/dangnhap.php');   // A-04

$pdo = Database::pdo();

// ---- Bộ lọc: từ khóa (họ tên, email, lớp, khoa) và trạng thái ----
$q  = trim((string) ($_GET['q'] ?? ''));
$tt = (string) ($_GET['tt'] ?? '');
if (!in_array($tt, ['Hoạt động', 'Bị khóa'], true)) {
    $tt = '';
}

$dieuKien = [];
$tham     = [];
$dieuKien[] = 'n.MaND NOT IN (SELECT MaND FROM ADMIN WHERE MaND IS NOT NULL)';   // không liệt kê tài khoản sinh viên liên kết của admin
if ($q !== '') {
    $like       = '%' . addcslashes($q, '\\%_') . '%';   // thoát % và _ để tìm đúng chữ người dùng gõ
    $dieuKien[] = '(n.HoTen LIKE ? OR n.Email LIKE ?)';
    array_push($tham, $like, $like);
}
if ($tt !== '') {
    $dieuKien[] = 'n.TrangThai = ?';
    $tham[]     = $tt;
}
$where = $dieuKien ? 'WHERE ' . implode(' AND ', $dieuKien) : '';

$cotChon = "n.MaND, n.HoTen, n.Email, n.TrangThai, n.NgayTao,
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

    $tieuDe = ['Họ tên', 'Email', 'Trạng thái'];
    foreach ($monHoc as $m) {
        $tieuDe[] = 'Elo - ' . $m['TenMon'];
    }
    $tieuDe[] = 'Số trận';

    $dong = [];
    foreach ($nguoi as $r) {
        $d = [$r['HoTen'], $r['Email'], $r['TrangThai']];
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
<div class="card adm-page-hero-card">
  <div class="adm-header-eyebrow">
    <span class="live-pulse"></span>
    <span>HỆ THỐNG QUẢN TRỊ NGƯỜI DÙNG</span>
  </div>
  <div class="adm-header-main">
    <h2 class="adm-title-pro">
      <svg class="title-svg-icon" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      <span>Danh sách người dùng</span>
    </h2>
    <div class="adm-header-badge-count">
      <span class="badge-num"><?= (int) $p->tong() ?></span>
      <span class="badge-txt">tài khoản</span>
    </div>
  </div>
  <p class="adm-subtitle-pro">
    Quản lý định danh người chơi, theo dõi trạng thái hoạt động và tra cứu điểm số Elo thời gian thực.
  </p>
</div>

<form method="get" action="" class="card form-loc">
  <input type="text" name="q" value="<?= e($q) ?>" placeholder="Tìm theo họ tên, email" class="o-tim">
  <select name="tt">
    <option value="">Mọi trạng thái</option>
    <option value="Hoạt động"<?= $tt === 'Hoạt động' ? ' selected' : '' ?>>Hoạt động</option>
    <option value="Bị khóa"<?= $tt === 'Bị khóa' ? ' selected' : '' ?>>Bị khóa</option>
  </select>
  <button type="submit" class="btn">Tìm</button>
  <a class="btn btn-phu" href="?">Xóa lọc</a>
  <a class="btn btn-phu" href="<?= e($linkXuat) ?>">Xuất Excel</a>
</form>

<div class="card khung-danh-sach">
  <?php if (!$ds): ?>
    <p class="nhan-phu">Không có người dùng nào khớp.</p>
  <?php else: ?>
    <table>
      <tr><th>Mã</th><th>Họ tên</th><th>Email</th><th>Số trận</th><th>Trạng thái</th><th>Ngày tạo</th></tr>
      <?php foreach ($ds as $r): ?>
        <tr>
          <td><?= (int) $r['MaND'] ?></td>
          <td><a href="<?= e(BASE_URL) ?>/admin/nguoidung_chitiet.php?id=<?= (int) $r['MaND'] ?>"><?= e($r['HoTen']) ?></a></td>
          <td><?= e($r['Email']) ?></td>
          <td><?= (int) $r['so_tran'] ?></td>
          <td><?= $r['TrangThai'] === 'Bị khóa' ? '<span class="tt-badge tt-khoa"><span class="badge-dot"></span> Bị khóa</span>' : '<span class="tt-badge tt-hoatdong"><span class="badge-dot"></span> Hoạt động</span>' ?></td>
          <td><?= e(date('d/m/Y H:i', strtotime($r['NgayTao']))) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
  <?= $p->links() ?>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>