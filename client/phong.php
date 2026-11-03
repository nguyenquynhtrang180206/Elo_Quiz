<?php
// Phòng thi đấu: chờ cả hai vào phòng, thi đấu (câu hỏi, đồng hồ, điểm), kết quả sơ bộ (C-23 đến C-31).
// Đặt tại: elo_quiz/client/phong.php
// Elo sau trận do core/Elo.php tính (C-32, C-33). Xem lại từng câu: client/xemlai.php (C-34).
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/GhepDoi.php';

$maND   = Auth::requireClient(BASE_URL . '/client/login.php');
$pdo    = Database::pdo();
$maTran = (int) ($_GET['tran'] ?? 0);

// Chỉ người chơi của trận mới được vào phòng
$st = $pdo->prepare('SELECT 1 FROM NGUOICHOITRANDAU WHERE MaTran = ? AND MaND = ?');
$st->execute([$maTran, $maND]);
if (!$st->fetchColumn()) {
    redirect(BASE_URL . '/client/timtran.php');
}

GhepDoi::vaoPhong($pdo, $maTran, $maND);

$st = $pdo->prepare(
    'SELECT t.TrangThai, t.LyDoKetThuc, m.TenMon, k.TenLoaiKT
       FROM TRANDAU t JOIN MONHOC m ON m.MaMon = t.MaMon JOIN LOAIKYTHI k ON k.MaLoaiKT = t.MaLoaiKT
      WHERE t.MaTran = ?'
);
$st->execute([$maTran]);
$tran = $st->fetch();

$st = $pdo->prepare(
    'SELECT n.MaND, u.HoTen, n.EloTruoc, n.EloSau, n.TrangThaiThamGia, n.DiemSo, n.KetQua
       FROM NGUOICHOITRANDAU n JOIN NGUOIDUNG u ON u.MaND = n.MaND
      WHERE n.MaTran = ?'
);
$st->execute([$maTran]);
$toi = $doiThu = null;
foreach ($st->fetchAll() as $r) {
    if ((int) $r['MaND'] === $maND) {
        $toi = $r;
    } else {
        $doiThu = $r;
    }
}

$tieuDe = 'Phòng thi đấu';
$cssTrang = 'phong';
$jsTrang  = 'phong';
require __DIR__ . '/layout/header.php';
?>
<h2>Phòng thi đấu</h2>
<div class="card">
  <p>Môn: <strong><?= e($tran['TenMon']) ?></strong> &middot; Kỳ thi: <strong><?= e($tran['TenLoaiKT']) ?></strong></p>
  <p>Đối thủ: <strong><?= e($doiThu['HoTen']) ?></strong> (Elo <?= (int) $doiThu['EloTruoc'] ?>)</p>
</div>

<?php if ($tran['TrangThai'] === 'Đã ghép'): ?>
  <div class="card" data-tai-lai="1500">
    <p>Đã ghép được đối thủ. Đang chờ
       <?= $doiThu['TrangThaiThamGia'] === 'Chưa vào' ? 'đối thủ vào phòng' : 'trận bắt đầu' ?>...</p>
  </div>

<?php elseif ($tran['TrangThai'] === 'Đang diễn ra'): ?>
  <div class="card" id="thi"
       data-api="<?= e(BASE_URL . '/client/api_thi.php?tran=' . $maTran) ?>"
       data-csrf="<?= e(csrf_token()) ?>">
    <p id="tiendo"></p>
    <div id="khung"><p>Đang tải...</p></div>
    <p class="khoi-chiu-thua"><button type="button" id="nutChiuThua" class="btn btn-phu">Chịu thua</button></p>
  </div>

<?php elseif ($tran['TrangThai'] === 'Hủy'): ?>
  <div class="card">
    <p class="loi">Trận đã bị hủy (<?= e($tran['LyDoKetThuc']) ?>). Elo không thay đổi.</p>
    <a class="btn" href="<?= e(BASE_URL) ?>/client/timtran.php">Tìm trận mới</a>
  </div>

<?php else: ?>
  <?php $kq = (string) $toi['KetQua']; ?>
  <div class="card">
    <h3><?= $kq === 'Thắng' ? 'Bạn thắng!' : ($kq === 'Thua' ? 'Bạn thua.' : 'Hòa.') ?></h3>
    <p>Điểm: <strong><?= (int) $toi['DiemSo'] ?></strong> (bạn) &ndash;
       <strong><?= (int) $doiThu['DiemSo'] ?></strong> (<?= e($doiThu['HoTen']) ?>)</p>
    <p>Lý do kết thúc: <?= e($tran['LyDoKetThuc']) ?></p>
    <?php if ($toi['EloSau'] !== null): ?>
      <?php $dEloToi = (int) $toi['EloSau'] - (int) $toi['EloTruoc']; ?>
      <p>Elo của bạn: <?= (int) $toi['EloTruoc'] ?> &rarr; <strong><?= (int) $toi['EloSau'] ?></strong>
         (<?= $dEloToi >= 0 ? '+' : '' ?><?= $dEloToi ?>)</p>
    <?php endif; ?>
    <a class="btn" href="<?= e(BASE_URL) ?>/client/xemlai.php?tran=<?= (int) $maTran ?>">Xem lại từng câu</a>
    <a class="btn" href="<?= e(BASE_URL) ?>/client/timtran.php">Tìm trận mới</a>
    <a class="btn btn-phu" href="<?= e(BASE_URL) ?>/client/index.php">Về trang chủ</a>
  </div>
<?php endif; ?>
<?php require __DIR__ . '/layout/footer.php'; ?>