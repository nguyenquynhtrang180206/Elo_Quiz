<?php
// Chi tiết người dùng, lịch sử Elo, khóa/mở khóa (UC11: A-11, A-12, A-13, A-29).
// Đặt tại: elo_quiz/admin/nguoidung_chitiet.php   (mở bằng ?id=<MaND>)
// A-13: không có chức năng xóa người dùng; chỉ khóa để giữ nguyên lịch sử trận đấu.
require_once __DIR__ . '/../core/bootstrap.php';
Auth::requireAdmin(BASE_URL . '/admin/login.php');   // A-04

$pdo = Database::pdo();
$id  = (int) ($_GET['id'] ?? 0);

$st = $pdo->prepare('SELECT MaND, HoTen, Email, Lop, Khoa, TrangThai, NgayTao, TrangThaiKhaoSat FROM NGUOIDUNG WHERE MaND = ?');
$st->execute([$id]);
$nd = $st->fetch();

$tieuDe  = 'Chi tiết người dùng';
$mucChon = 'nguoidung';

if (!$nd) {
    http_response_code(404);
    $cssTrang = 'nguoidung_chitiet';
    $jsTrang  = 'nguoidung_chitiet';
    require __DIR__ . '/layout/header.php';
    echo '<h2>Chi tiết người dùng</h2><div class="card">Không tìm thấy người dùng này. '
        . '<a href="' . e(BASE_URL) . '/admin/nguoidung.php">Về danh sách</a></div>';
    require __DIR__ . '/layout/footer.php';
    exit;
}

// ---- A-12: khóa / mở khóa, cập nhật trạng thái và ghi nhật ký trong cùng một giao dịch (A-40) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $hanhDong = (string) ($_POST['hanh_dong'] ?? '');

    if ($hanhDong === 'khoa' || $hanhDong === 'mokhoa') {
        $khoa  = $hanhDong === 'khoa';
        $lyDo  = preg_replace('/^(.{300}).+$/su', '$1', trim((string) ($_POST['ly_do'] ?? '')));

        $pdo->beginTransaction();
        try {
            // Điều kiện theo trạng thái cũ: bấm lặp hoặc hai quản trị viên cùng bấm thì chỉ ghi nhật ký một lần
            $up = $pdo->prepare('UPDATE NGUOIDUNG SET TrangThai = ? WHERE MaND = ? AND TrangThai = ?');
            $up->execute([$khoa ? 'Bị khóa' : 'Hoạt động', $id, $khoa ? 'Hoạt động' : 'Bị khóa']);
            if ($up->rowCount() === 1) {
                NhatKy::ghi(
                    $khoa ? 'Khóa người dùng' : 'Mở khóa người dùng',
                    'NGUOIDUNG #' . $id,
                    $lyDo !== '' ? 'Lý do: ' . $lyDo : null
                );
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }
        redirect(BASE_URL . '/admin/nguoidung_chitiet.php?id=' . $id . '&kq=' . $hanhDong);
    }
}

$thongBao = ['khoa' => 'Đã khóa tài khoản.', 'mokhoa' => 'Đã mở khóa tài khoản.'][$_GET['kq'] ?? ''] ?? '';

// ---- A-11: Elo từng môn, số trận, tỷ lệ thắng ----
$st = $pdo->prepare(
    'SELECT e.MaMon, m.TenMon, e.DiemElo, e.SoTran, e.SoThang, e.SoThua, e.SoHoa
     FROM ELOMONHOC e JOIN MONHOC m ON m.MaMon = e.MaMon
     WHERE e.MaND = ? ORDER BY m.TenMon'
);
$st->execute([$id]);
$eloMon = $st->fetchAll();
$tongTran  = array_sum(array_column($eloMon, 'SoTran'));
$tongThang = array_sum(array_column($eloMon, 'SoThang'));

// ---- A-29: lịch sử Elo (lọc theo môn, phân trang 20 dòng) ----
$monLoc = (int) ($_GET['mon'] ?? 0);
if (!in_array($monLoc, array_map('intval', array_column($eloMon, 'MaMon')), true)) {
    $monLoc = 0;
}
$dk   = 'WHERE l.MaND = ?' . ($monLoc ? ' AND l.MaMon = ?' : '');
$tham = $monLoc ? [$id, $monLoc] : [$id];

$st = $pdo->prepare("SELECT COUNT(*) FROM LICHSUELO l $dk");
$st->execute($tham);
$p = new Paginator((int) $st->fetchColumn(), 20);

$st = $pdo->prepare(
    "SELECT l.ThoiGian, m.TenMon, l.EloTruoc, l.EloSau, l.ThayDoi, l.MaTran
     FROM LICHSUELO l JOIN MONHOC m ON m.MaMon = l.MaMon
     $dk ORDER BY l.ThoiGian DESC, l.MaLichSu DESC
     LIMIT " . $p->limit() . ' OFFSET ' . $p->offset()
);
$st->execute($tham);
$lichSu = $st->fetchAll();

$cssTrang = 'nguoidung_chitiet';
$jsTrang  = 'nguoidung_chitiet';
require __DIR__ . '/layout/header.php';
?>
<p><a href="<?= e(BASE_URL) ?>/admin/nguoidung.php">&laquo; Danh sách người dùng</a></p>
<h2><?= e($nd['HoTen']) ?></h2>

<?php if ($thongBao !== ''): ?><div class="thanhcong"><?= e($thongBao) ?></div><?php endif; ?>

<div class="card">
  <table>
    <tr><th class="cot-nhan">Mã người dùng</th><td><?= (int) $nd['MaND'] ?></td></tr>
    <tr><th>Email</th><td><?= e($nd['Email']) ?></td></tr>
    <tr><th>Lớp</th><td><?= e($nd['Lop']) ?></td></tr>
    <tr><th>Khoa</th><td><?= e($nd['Khoa']) ?></td></tr>
    <tr><th>Ngày tạo</th><td><?= e(date('d/m/Y H:i', strtotime($nd['NgayTao']))) ?></td></tr>
    <tr><th>Khảo sát</th><td><?= e($nd['TrangThaiKhaoSat']) ?></td></tr>
    <tr><th>Trạng thái</th>
      <td><?= $nd['TrangThai'] === 'Bị khóa' ? '<span class="mau-do dam">Bị khóa</span>' : 'Hoạt động' ?></td></tr>
  </table>

  <form method="post" action="" class="form-khoa">
    <?= csrf_field() ?>
    <label for="ly_do">Lý do (không bắt buộc, được ghi vào nhật ký)</label>
    <input type="text" id="ly_do" name="ly_do" maxlength="300">
    <p>
      <?php if ($nd['TrangThai'] === 'Hoạt động'): ?>
        <button type="submit" name="hanh_dong" value="khoa" class="btn btn-do"
                data-xac-nhan="Khóa tài khoản này? Người dùng sẽ không đăng nhập được nữa.">Khóa tài khoản</button>
      <?php else: ?>
        <button type="submit" name="hanh_dong" value="mokhoa" class="btn"
                data-xac-nhan="Mở khóa tài khoản này?">Mở khóa tài khoản</button>
      <?php endif; ?>
    </p>
  </form>
</div>

<h3>Elo từng môn</h3>
<div class="card">
  <?php if (!$eloMon): ?>
    <p class="nhan-phu">Người dùng này chưa thi đấu môn nào.</p>
  <?php else: ?>
    <table>
      <tr><th>Môn</th><th>Elo</th><th>Số trận</th><th>Thắng</th><th>Thua</th><th>Hòa</th><th>Tỷ lệ thắng</th></tr>
      <?php foreach ($eloMon as $r): ?>
        <tr>
          <td><?= e($r['TenMon']) ?></td>
          <td><strong><?= (int) $r['DiemElo'] ?></strong></td>
          <td><?= (int) $r['SoTran'] ?></td>
          <td><?= (int) $r['SoThang'] ?></td>
          <td><?= (int) $r['SoThua'] ?></td>
          <td><?= (int) $r['SoHoa'] ?></td>
          <td><?= (int) $r['SoTran'] > 0 ? e((string) round((int) $r['SoThang'] * 100 / (int) $r['SoTran'], 1)) . '%' : '-' ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
    <p class="nhan-phu">Tổng cộng: <?= (int) $tongTran ?> trận, tỷ lệ thắng
      <?= $tongTran > 0 ? e((string) round($tongThang * 100 / $tongTran, 1)) . '%' : '-' ?>.
      Elo chỉ thay đổi theo kết quả trận, quản trị viên không chỉnh tay được.</p>
  <?php endif; ?>
</div>

<h3>Lịch sử Elo</h3>
<form method="get" action="" class="card form-lichsu">
  <input type="hidden" name="id" value="<?= (int) $id ?>">
  <select name="mon">
    <option value="0">Mọi môn</option>
    <?php foreach ($eloMon as $r): ?>
      <option value="<?= (int) $r['MaMon'] ?>"<?= (int) $r['MaMon'] === $monLoc ? ' selected' : '' ?>><?= e($r['TenMon']) ?></option>
    <?php endforeach; ?>
  </select>
  <button type="submit" class="btn">Lọc</button>
</form>
<div class="card">
  <?php if (!$lichSu): ?>
    <p class="nhan-phu">Chưa có lịch sử Elo.</p>
  <?php else: ?>
    <table>
      <tr><th>Thời gian</th><th>Môn</th><th>Elo trước</th><th>Elo sau</th><th>Thay đổi</th><th>Trận</th></tr>
      <?php foreach ($lichSu as $r): ?>
        <tr>
          <td><?= e(date('d/m/Y H:i', strtotime($r['ThoiGian']))) ?></td>
          <td><?= e($r['TenMon']) ?></td>
          <td><?= (int) $r['EloTruoc'] ?></td>
          <td><?= (int) $r['EloSau'] ?></td>
          <td class="dam <?= (int) $r['ThayDoi'] >= 0 ? 'mau-xanh' : 'mau-do' ?>"><?= sprintf('%+d', (int) $r['ThayDoi']) ?></td>
          <td>#<?= (int) $r['MaTran'] ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
  <?= $p->links() ?>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>