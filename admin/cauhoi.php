<?php
// Danh sách ngân hàng câu hỏi, lọc, ẩn/hiện (UC13: A-17, A-20). Đặt tại: elo_quiz/admin/cauhoi.php
// Thêm/sửa câu hỏi nằm ở cauhoi_form.php. Không có chức năng xóa câu hỏi (A-20).
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/CauHoiHelper.php';
Auth::requireAdmin(BASE_URL . '/admin/login.php');   // A-04

$pdo = Database::pdo();

// ---- A-20: ẩn / hiện lại câu hỏi (kèm nhật ký A-40 trong cùng giao dịch) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $hd = (string) ($_POST['hanh_dong'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);

    if (($hd === 'an' || $hd === 'hien') && $id > 0) {
        $an = $hd === 'an';
        $pdo->beginTransaction();
        try {
            // Điều kiện theo trạng thái cũ: bấm lặp hoặc hai admin cùng bấm thì chỉ ghi nhật ký một lần
            $up = $pdo->prepare('UPDATE CAUHOI SET TrangThai = ? WHERE MaCauHoi = ? AND TrangThai = ?');
            $up->execute([$an ? 'Đã ẩn' : 'Hiển thị', $id, $an ? 'Hiển thị' : 'Đã ẩn']);
            if ($up->rowCount() === 1) {
                NhatKy::ghi($an ? 'Ẩn câu hỏi' : 'Hiện lại câu hỏi', 'CAUHOI #' . $id);
                $_SESSION['ch_flash'] = ['ok', $an ? 'Đã ẩn câu hỏi #' . $id . '.' : 'Đã hiện lại câu hỏi #' . $id . '.'];
            } else {
                $_SESSION['ch_flash'] = ['loi', 'Câu hỏi đã ở trạng thái này rồi hoặc không tồn tại.'];
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }
    }
    redirect((string) $_SERVER['REQUEST_URI']);   // ở lại đúng trang, giữ nguyên bộ lọc
}

$flash = $_SESSION['ch_flash'] ?? null;
unset($_SESSION['ch_flash']);

// ---- A-17: bộ lọc ----
$q    = trim((string) ($_GET['q'] ?? ''));
$mon  = (int) ($_GET['mon'] ?? 0);
$kt   = (string) ($_GET['kt'] ?? '');          // '' = mọi loại, 'chung' = dùng cho cả hai kỳ, số = mã loại
$dk   = (string) ($_GET['dk'] ?? '');
$tt   = (string) ($_GET['tt'] ?? '');
if (!in_array($dk, CauHoiHelper::DO_KHO, true)) { $dk = ''; }
if (!in_array($tt, ['Hiển thị', 'Đã ẩn'], true)) { $tt = ''; }
if ($kt !== 'chung' && !ctype_digit($kt)) { $kt = ''; }

$dsMon = $pdo->query("SELECT MaMon, TenMon FROM MONHOC WHERE TrangThai IN ('Đang mở','Đã ẩn') ORDER BY TenMon")->fetchAll();
$dsKT  = $pdo->query('SELECT MaLoaiKT, TenLoaiKT FROM LOAIKYTHI ORDER BY MaLoaiKT')->fetchAll();

$dieuKien = [];
$tham     = [];
if ($q !== '') {
    $dieuKien[] = 'c.NoiDung LIKE ?';
    $tham[]     = '%' . addcslashes($q, '\\%_') . '%';
}
if ($mon > 0)        { $dieuKien[] = 'c.MaMon = ?';        $tham[] = $mon; }
if ($kt === 'chung') { $dieuKien[] = 'c.MaLoaiKT IS NULL'; }
elseif ($kt !== '')  { $dieuKien[] = 'c.MaLoaiKT = ?';     $tham[] = (int) $kt; }
if ($dk !== '')      { $dieuKien[] = 'c.DoKho = ?';        $tham[] = $dk; }
if ($tt !== '')      { $dieuKien[] = 'c.TrangThai = ?';    $tham[] = $tt; }
$where = $dieuKien ? 'WHERE ' . implode(' AND ', $dieuKien) : '';

$st = $pdo->prepare("SELECT COUNT(*) FROM CAUHOI c $where");
$st->execute($tham);
$p = new Paginator((int) $st->fetchColumn(), 20);

$st = $pdo->prepare(
    "SELECT c.MaCauHoi, c.NoiDung, c.DoKho, c.TrangThai, m.TenMon, k.TenLoaiKT,
            EXISTS(SELECT 1 FROM CAUHOITRANDAU t WHERE t.MaCauHoi = c.MaCauHoi) AS da_dung
     FROM CAUHOI c
     JOIN MONHOC m ON m.MaMon = c.MaMon
     LEFT JOIN LOAIKYTHI k ON k.MaLoaiKT = c.MaLoaiKT
     $where
     ORDER BY c.MaCauHoi DESC
     LIMIT " . $p->limit() . ' OFFSET ' . $p->offset()
);
$st->execute($tham);
$ds = $st->fetchAll();

$tieuDe  = 'Ngân hàng câu hỏi';
$mucChon = 'cauhoi';
$cssTrang = 'cauhoi';
$jsTrang  = 'cauhoi';
require __DIR__ . '/layout/header.php';
?>
<h2>Ngân hàng câu hỏi</h2>

<?php if ($flash): ?>
  <div class="<?= $flash[0] === 'ok' ? 'thanhcong' : 'loi' ?>"><?= e($flash[1]) ?></div>
<?php endif; ?>

<form method="get" action="" class="card form-loc">
  <input type="text" name="q" value="<?= e($q) ?>" placeholder="Tìm trong nội dung câu hỏi" class="o-tim">
  <select name="mon">
    <option value="0">Mọi môn</option>
    <?php foreach ($dsMon as $m): ?>
      <option value="<?= (int) $m['MaMon'] ?>"<?= (int) $m['MaMon'] === $mon ? ' selected' : '' ?>><?= e($m['TenMon']) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="kt">
    <option value="">Mọi loại kỳ thi</option>
    <?php foreach ($dsKT as $k): ?>
      <option value="<?= (int) $k['MaLoaiKT'] ?>"<?= $kt === (string) $k['MaLoaiKT'] ? ' selected' : '' ?>><?= e($k['TenLoaiKT']) ?></option>
    <?php endforeach; ?>
    <option value="chung"<?= $kt === 'chung' ? ' selected' : '' ?>>Dùng cho cả hai kỳ</option>
  </select>
  <select name="dk">
    <option value="">Mọi độ khó</option>
    <?php foreach (CauHoiHelper::DO_KHO as $d): ?>
      <option value="<?= e($d) ?>"<?= $dk === $d ? ' selected' : '' ?>><?= e($d) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="tt">
    <option value="">Mọi trạng thái</option>
    <option value="Hiển thị"<?= $tt === 'Hiển thị' ? ' selected' : '' ?>>Hiển thị</option>
    <option value="Đã ẩn"<?= $tt === 'Đã ẩn' ? ' selected' : '' ?>>Đã ẩn</option>
  </select>
  <button type="submit" class="btn">Lọc</button>
  <a class="btn btn-phu" href="?">Xóa lọc</a>
  <a class="btn" href="<?= e(BASE_URL) ?>/admin/cauhoi_form.php<?= $mon > 0 ? '?mon=' . $mon : '' ?>">Thêm câu hỏi</a>
  <a class="btn btn-phu" href="<?= e(BASE_URL) ?>/admin/cauhoi_nhap.php">Nhập từ Word/Excel</a>
</form>

<div class="card">
  <?php if (!$ds): ?>
    <p class="nhan-phu">Không có câu hỏi nào khớp.</p>
  <?php else: ?>
    <table>
      <tr><th>Mã</th><th>Nội dung</th><th>Môn</th><th>Loại kỳ thi</th><th>Độ khó</th><th>Trạng thái</th><th></th></tr>
      <?php foreach ($ds as $r): ?>
        <tr>
          <td><?= (int) $r['MaCauHoi'] ?></td>
          <td><?= e(preg_replace('/^(.{90}).+$/su', '$1…', (string) $r['NoiDung'])) ?></td>
          <td><?= e($r['TenMon']) ?></td>
          <td><?= $r['TenLoaiKT'] !== null ? e($r['TenLoaiKT']) : '<span class="nhan-phu">Cả hai kỳ</span>' ?></td>
          <td><?= e($r['DoKho']) ?></td>
          <td><?= $r['TrangThai'] === 'Đã ẩn' ? '<span class="mau-do dam">Đã ẩn</span>' : 'Hiển thị' ?></td>
          <td class="khong-ngat">
            <a class="btn btn-phu" href="<?= e(BASE_URL) ?>/admin/cauhoi_form.php?id=<?= (int) $r['MaCauHoi'] ?>"><?= (int) $r['da_dung'] ? 'Xem' : 'Sửa' ?></a>
            <form method="post" action="" class="form-dong">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int) $r['MaCauHoi'] ?>">
              <?php if ($r['TrangThai'] === 'Hiển thị'): ?>
                <button type="submit" name="hanh_dong" value="an" class="btn btn-phu"
                        data-xac-nhan="Ẩn câu hỏi này? Câu sẽ không được chọn vào trận mới.">Ẩn</button>
              <?php else: ?>
                <button type="submit" name="hanh_dong" value="hien" class="btn">Hiện lại</button>
              <?php endif; ?>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
  <?= $p->links() ?>
  <p class="nhan-phu">Câu đã dùng trong trận đấu chỉ xem và ẩn/hiện, không sửa được để màn xem lại luôn đúng với lúc thi (A-21).</p>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>