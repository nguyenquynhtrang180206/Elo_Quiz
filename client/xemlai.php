<?php
// Xem lại trận đã kết thúc: từng câu, đáp án đã chọn, đáp án đúng, giải thích, thời gian, điểm (C-34).
// Đặt tại: elo_quiz/client/xemlai.php?tran=ID
// Đáp án đúng và giải thích chỉ hiện khi trận đã kết thúc (C-27).
require_once __DIR__ . '/../core/bootstrap.php';

$maND   = Auth::requireClient(BASE_URL . '/client/login.php');
$pdo    = Database::pdo();
$maTran = (int) ($_GET['tran'] ?? 0);

// Chỉ người chơi của trận mới được xem
$st = $pdo->prepare(
    'SELECT t.TrangThai, t.LyDoKetThuc, m.TenMon, k.TenLoaiKT, n.DiemSo, n.KetQua, n.EloTruoc, n.EloSau
       FROM NGUOICHOITRANDAU n
       JOIN TRANDAU t ON t.MaTran = n.MaTran
       JOIN MONHOC m ON m.MaMon = t.MaMon
       JOIN LOAIKYTHI k ON k.MaLoaiKT = t.MaLoaiKT
      WHERE n.MaTran = ? AND n.MaND = ?'
);
$st->execute([$maTran, $maND]);
$tran = $st->fetch();
if (!$tran) {
    redirect(BASE_URL . '/client/lichsutran.php');
}
// Trận chưa kết thúc: không cho xem đáp án đúng, quay về phòng thi
if ($tran['TrangThai'] === 'Đã ghép' || $tran['TrangThai'] === 'Đang diễn ra') {
    redirect(BASE_URL . '/client/phong.php?tran=' . $maTran);
}

$st = $pdo->prepare(
    'SELECT n.MaND, u.HoTen, n.DiemSo FROM NGUOICHOITRANDAU n JOIN NGUOIDUNG u ON u.MaND = n.MaND
      WHERE n.MaTran = ? AND n.MaND <> ?'
);
$st->execute([$maTran, $maND]);
$doiThu = $st->fetch();

$cau = [];
$dapAn = [];
if ($tran['TrangThai'] === 'Kết thúc') {
    // Từng câu của trận cùng bài làm của chính người xem (nội dung câu đúng như lúc thi, A-21)
    $st = $pdo->prepare(
        'SELECT c.ThuTu, c.MaCauHoi, h.NoiDung, h.GiaiThich,
                t.MaDapAn, t.TrangThai, t.ThoiGianTraLoi, t.DiemNhanDuoc, c.ThoiGianQuyDinh
           FROM CAUHOITRANDAU c
           JOIN CAUHOI h ON h.MaCauHoi = c.MaCauHoi
           LEFT JOIN CHITIETTRALOI t ON t.MaTran = c.MaTran AND t.MaCauHoi = c.MaCauHoi AND t.MaND = ?
          WHERE c.MaTran = ?
          ORDER BY c.ThuTu'
    );
    $st->execute([$maND, $maTran]);
    $cau = $st->fetchAll();

    $st = $pdo->prepare(
        'SELECT d.MaCauHoi, d.MaDapAn, d.NoiDungDapAn, d.LaDapAnDung
           FROM DAPAN d JOIN CAUHOITRANDAU c ON c.MaCauHoi = d.MaCauHoi
          WHERE c.MaTran = ?
          ORDER BY d.MaCauHoi, d.MaDapAn'
    );
    $st->execute([$maTran]);
    foreach ($st->fetchAll() as $r) {
        $dapAn[(int) $r['MaCauHoi']][] = $r;
    }
}

$tieuDe = 'Xem lại trận';
$cssTrang = 'xemlai';
require __DIR__ . '/layout/header.php';
?>
<h2>Xem lại trận #<?= (int) $maTran ?></h2>
<div class="card">
  <p>Môn: <strong><?= e($tran['TenMon']) ?></strong> &middot; Kỳ thi: <strong><?= e($tran['TenLoaiKT']) ?></strong></p>
  <?php if ($tran['TrangThai'] === 'Hủy'): ?>
    <p class="loi">Trận đã bị hủy (<?= e($tran['LyDoKetThuc']) ?>). Elo không thay đổi và không có bài làm để xem lại.</p>
  <?php else: ?>
    <?php $kq = (string) $tran['KetQua']; ?>
    <p>Kết quả: <strong><?= e($kq) ?></strong> &middot; Lý do kết thúc: <?= e($tran['LyDoKetThuc']) ?></p>
    <p>Điểm: <strong><?= (int) $tran['DiemSo'] ?></strong> (bạn) &ndash;
       <strong><?= (int) ($doiThu['DiemSo'] ?? 0) ?></strong> (<?= e($doiThu['HoTen'] ?? '') ?>)</p>
    <?php if ($tran['EloSau'] !== null): ?>
      <?php $dElo = (int) $tran['EloSau'] - (int) $tran['EloTruoc']; ?>
      <p>Elo: <?= (int) $tran['EloTruoc'] ?> &rarr; <strong><?= (int) $tran['EloSau'] ?></strong>
         (<?= $dElo >= 0 ? '+' : '' ?><?= $dElo ?>)</p>
    <?php endif; ?>
  <?php endif; ?>
  <a class="btn btn-phu" href="<?= e(BASE_URL) ?>/client/lichsutran.php">Lịch sử trận</a>
  <a class="btn" href="<?= e(BASE_URL) ?>/client/timtran.php">Tìm trận mới</a>
</div>

<?php foreach ($cau as $c):
    $tt = (string) ($c['TrangThai'] ?? 'Không làm');
    $daChon = $c['MaDapAn'] === null ? null : (int) $c['MaDapAn'];
    $nhan = $tt === 'Đã trả lời'
        ? ((int) $c['DiemNhanDuoc'] > 0 ? 'Đúng' : 'Sai')
        : ($tt === 'Hết giờ' ? 'Hết giờ' : 'Không làm');
?>
  <div class="card">
    <h3>Câu <?= (int) $c['ThuTu'] ?>/<?= count($cau) ?> &ndash; <?= e($nhan) ?>
        (+<?= (int) $c['DiemNhanDuoc'] ?> điểm<?= $c['ThoiGianTraLoi'] !== null && $tt === 'Đã trả lời'
            ? ', ' . e(number_format((float) $c['ThoiGianTraLoi'], 1)) . ' giây' : '' ?>)</h3>
    <p><?= nl2br(e($c['NoiDung'])) ?></p>
    <?php foreach ($dapAn[(int) $c['MaCauHoi']] ?? [] as $i => $d):
        $laDung = (int) $d['LaDapAnDung'] === 1;
        $laChon = $daChon === (int) $d['MaDapAn'];
    ?>
      <p class="dap-an<?= $laDung ? ' dung' : ($laChon ? ' sai' : '') ?>">
        <?= e(chr(65 + $i)) ?>. <?= e($d['NoiDungDapAn']) ?>
        <?= $laDung ? ' &#10003; (đáp án đúng)' : '' ?><?= $laChon ? ' &larr; bạn chọn' : '' ?>
      </p>
    <?php endforeach; ?>
    <?php if ($daChon === null): ?>
      <p class="ghichu">Bạn không chọn đáp án nào ở câu này.</p>
    <?php endif; ?>
    <?php if (trim((string) $c['GiaiThich']) !== ''): ?>
      <p><strong>Giải thích:</strong> <?= nl2br(e($c['GiaiThich'])) ?></p>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
<?php require __DIR__ . '/layout/footer.php'; ?>