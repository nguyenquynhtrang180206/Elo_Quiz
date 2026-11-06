<?php
// Lịch sử các trận đã đấu của chính người dùng (C-40), phân trang 10 dòng/trang (3.8).
// Đặt tại: elo_quiz/client/lichsutran.php
require_once __DIR__ . '/../core/bootstrap.php';

$maND = Auth::requireClient(BASE_URL . '/client/login.php');
$pdo  = Database::pdo();

$st = $pdo->prepare('SELECT COUNT(*) FROM NGUOICHOITRANDAU WHERE MaND = ?');
$st->execute([$maND]);
$p = new Paginator((int) $st->fetchColumn(), 10);

$st = $pdo->prepare(
    'SELECT t.MaTran, t.TrangThai, t.LyDoKetThuc, t.ThoiGianBatDau, t.ThoiGianKetThuc,
            m.TenMon, k.TenLoaiKT,
            n.DiemSo, n.KetQua, n.EloTruoc, n.EloSau,
            u.HoTen AS DoiThu, o.DiemSo AS DiemDoiThu
       FROM NGUOICHOITRANDAU n
       JOIN TRANDAU t ON t.MaTran = n.MaTran
       JOIN MONHOC m ON m.MaMon = t.MaMon
       JOIN LOAIKYTHI k ON k.MaLoaiKT = t.MaLoaiKT
       LEFT JOIN NGUOICHOITRANDAU o ON o.MaTran = n.MaTran AND o.MaND <> n.MaND
       LEFT JOIN NGUOIDUNG u ON u.MaND = o.MaND
      WHERE n.MaND = ?
      ORDER BY t.MaTran DESC
      LIMIT ' . $p->limit() . ' OFFSET ' . $p->offset()
);
$st->execute([$maND]);
$ds = $st->fetchAll();

$tieuDe = 'Lịch sử trận đấu';
$cssTrang = 'lichsutran';
require __DIR__ . '/layout/header.php';
?>
<h2>Lịch sử trận đấu</h2>
<div class="card">
  <?php if (!$ds): ?>
    <p>Bạn chưa đấu trận nào.</p>
    <a class="btn" href="<?= e(BASE_URL) ?>/client/timtran.php">Tìm trận</a>
  <?php else: ?>
    <table>
      <tr><th>Thời gian</th><th>Môn / kỳ thi</th><th>Đối thủ</th><th>Điểm</th><th>Kết quả</th><th>Elo</th><th></th></tr>
      <?php foreach ($ds as $r):
          $moc = $r['ThoiGianKetThuc'] ?? $r['ThoiGianBatDau'];
          $dangChoi = $r['TrangThai'] === 'Đã ghép' || $r['TrangThai'] === 'Đang diễn ra';
      ?>
        <tr>
          <td><?= $moc ? e(date('d/m/Y H:i', strtotime((string) $moc))) : '' ?></td>
          <td><?= e($r['TenMon']) ?><br><small><?= e($r['TenLoaiKT']) ?></small></td>
          <td><?= e($r['DoiThu']) ?></td>
          <td><?= $dangChoi || $r['TrangThai'] === 'Hủy' ? '' : (int) $r['DiemSo'] . ' &ndash; ' . (int) $r['DiemDoiThu'] ?></td>
          <td>
            <?php if ($r['TrangThai'] === 'Hủy'): ?>
              Hủy (<?= e($r['LyDoKetThuc']) ?>)
            <?php elseif ($dangChoi): ?>
              Đang diễn ra
            <?php else: ?>
              <?= e($r['KetQua']) ?>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($r['EloSau'] !== null): $d = (int) $r['EloSau'] - (int) $r['EloTruoc']; ?>
              <?= (int) $r['EloSau'] ?> (<?= $d >= 0 ? '+' : '' ?><?= $d ?>)
            <?php endif; ?>
          </td>
          <td>
            <?php if ($dangChoi): ?>
              <a href="<?= e(BASE_URL) ?>/client/phong.php?tran=<?= (int) $r['MaTran'] ?>">Vào phòng</a>
            <?php else: ?>
              <span class="ghichu">Hoàn thành</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
    <?= $p->links() ?>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>