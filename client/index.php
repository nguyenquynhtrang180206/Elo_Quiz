<?php
// Trang chủ (C-11, C-12). Đặt tại: elo_quiz/client/index.php
// Đăng nhập rồi: lời chào, nút Tìm trận, tóm tắt thành tích, trận gần đây, BXH rút gọn, bài đăng mới.
// Khách chưa đăng nhập vẫn xem được BXH rút gọn và bài đăng mới (C-12).
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/GhepDoi.php';
require_once __DIR__ . '/../core/CongDongHelper.php';

$pdo = Database::pdo();
$maND = Auth::clientId();

$hoTen = '';
$tranDangCo = null;
$tong = ['SoTran' => 0, 'SoThang' => 0, 'SoThua' => 0, 'SoHoa' => 0];
$eloCaoNhat = null;
$tranGanDay = [];
$monUaThich = 0;

if ($maND !== null) {
    $st = $pdo->prepare('SELECT HoTen FROM NGUOIDUNG WHERE MaND = ?');
    $st->execute([$maND]);
    $hoTen = (string) $st->fetchColumn();

    $tranDangCo = GhepDoi::tranDangCo($pdo, $maND);

    // Thành tích gộp mọi môn
    $st = $pdo->prepare(
        'SELECT COALESCE(SUM(SoTran), 0) AS SoTran, COALESCE(SUM(SoThang), 0) AS SoThang,
                COALESCE(SUM(SoThua), 0) AS SoThua, COALESCE(SUM(SoHoa), 0) AS SoHoa
           FROM ELOMONHOC WHERE MaND = ?'
    );
    $st->execute([$maND]);
    $tong = $st->fetch();

    // Môn có Elo cao nhất trong số môn đã đấu
    $st = $pdo->prepare(
        'SELECT m.TenMon, e.DiemElo FROM ELOMONHOC e JOIN MONHOC m ON m.MaMon = e.MaMon
          WHERE e.MaND = ? AND e.SoTran > 0 ORDER BY e.DiemElo DESC, e.SoTran DESC LIMIT 1'
    );
    $st->execute([$maND]);
    $eloCaoNhat = $st->fetch() ?: null;

    // Vài trận gần nhất đã xong hoặc đã hủy
    $st = $pdo->prepare(
        "SELECT t.MaTran, t.TrangThai, t.LyDoKetThuc, t.ThoiGianKetThuc, m.TenMon,
                n.DiemSo, n.KetQua, n.EloTruoc, n.EloSau, u.HoTen AS DoiThu, o.DiemSo AS DiemDoiThu
           FROM NGUOICHOITRANDAU n
           JOIN TRANDAU t ON t.MaTran = n.MaTran
           JOIN MONHOC m ON m.MaMon = t.MaMon
           LEFT JOIN NGUOICHOITRANDAU o ON o.MaTran = n.MaTran AND o.MaND <> n.MaND
           LEFT JOIN NGUOIDUNG u ON u.MaND = o.MaND
          WHERE n.MaND = ? AND t.TrangThai IN ('Kết thúc', 'Hủy')
          ORDER BY t.MaTran DESC LIMIT 5"
    );
    $st->execute([$maND]);
    $tranGanDay = $st->fetchAll();

    // Môn người này đấu nhiều nhất, dùng cho BXH rút gọn
    $st = $pdo->prepare('SELECT MaMon FROM ELOMONHOC WHERE MaND = ? AND SoTran > 0 ORDER BY SoTran DESC, MaMon LIMIT 1');
    $st->execute([$maND]);
    $monUaThich = (int) $st->fetchColumn();
}

// BXH rút gọn: môn người này đấu nhiều nhất; khách (hoặc chưa đấu) thì lấy môn có nhiều lượt đấu nhất
if ($monUaThich === 0) {
    $monUaThich = (int) $pdo->query(
        "SELECT e.MaMon FROM ELOMONHOC e JOIN MONHOC m ON m.MaMon = e.MaMon
          WHERE m.TrangThai = 'Đang mở' AND e.SoTran > 0
          GROUP BY e.MaMon ORDER BY SUM(e.SoTran) DESC, e.MaMon LIMIT 1"
    )->fetchColumn();
}
$tenMonBxh = '';
$bxh = [];
if ($monUaThich > 0) {
    $st = $pdo->prepare("SELECT TenMon FROM MONHOC WHERE MaMon = ? AND TrangThai = 'Đang mở'");
    $st->execute([$monUaThich]);
    $tenMonBxh = (string) $st->fetchColumn();
    if ($tenMonBxh !== '') {
        $st = $pdo->prepare(
            "SELECT r.* FROM (
                SELECT u.MaND, u.HoTen, e.DiemElo, e.SoTran, RANK() OVER (ORDER BY e.DiemElo DESC) AS Hang
                  FROM ELOMONHOC e JOIN NGUOIDUNG u ON u.MaND = e.MaND
                 WHERE e.MaMon = ? AND e.SoTran > 0 AND u.TrangThai = 'Hoạt động'
             ) r ORDER BY r.Hang, r.SoTran DESC, r.MaND LIMIT 5"
        );
        $st->execute([$monUaThich]);
        $bxh = $st->fetchAll();
    }
}

// Bài đăng mới (chỉ bài đang hiển thị)
$baiMoi = $pdo->query(
    "SELECT b.MaBaiDang, b.TieuDe, b.NoiDung, b.NgayDang, u.HoTen, m.TenMon
       FROM BAIDANG b JOIN NGUOIDUNG u ON u.MaND = b.MaND LEFT JOIN MONHOC m ON m.MaMon = b.MaMon
      WHERE b.TrangThai = 'Hiển thị'
      ORDER BY b.NgayDang DESC, b.MaBaiDang DESC LIMIT 3"
)->fetchAll();

$tyLe = (int) $tong['SoTran'] > 0 ? round($tong['SoThang'] * 100 / $tong['SoTran'], 1) : 0;

$tieuDe = 'Trang chủ';
$cssTrang = 'index';
require __DIR__ . '/layout/header.php';
?>
<div class="card">
  <?php if ($maND !== null): ?>
    <h2>Xin chào, <?= e($hoTen) ?>!</h2>
    <?php if ($tranDangCo !== null): ?>
      <p class="loi">Bạn đang có một trận chưa kết thúc.</p>
      <a class="btn" href="<?= e(BASE_URL) ?>/client/phong.php?tran=<?= (int) $tranDangCo ?>">Vào phòng thi đấu</a>
    <?php else: ?>
      <p>Sẵn sàng cho trận tiếp theo chưa?</p>
      <a class="btn" href="<?= e(BASE_URL) ?>/client/timtran.php">Tìm trận</a>
    <?php endif; ?>
  <?php else: ?>
    <h2>Chào mừng đến Đấu Trường Tri Thức</h2>
    <p>Thi đấu trắc nghiệm 1 vs 1 với sinh viên cùng môn, xếp hạng Elo theo từng môn học.</p>
    <a class="btn" href="<?= e(BASE_URL) ?>/client/register.php">Đăng ký</a>
    <a class="btn btn-phu" href="<?= e(BASE_URL) ?>/client/login.php">Đăng nhập</a>
  <?php endif; ?>
</div>

<div class="luoi">
  <?php if ($maND !== null): ?>
    <div class="card">
      <h3>Thành tích của bạn</h3>
      <?php if ((int) $tong['SoTran'] === 0): ?>
        <p>Bạn chưa đấu trận nào. Hãy bấm Tìm trận để bắt đầu.</p>
      <?php else: ?>
        <div class="so-lieu">
          <div><strong><?= (int) $tong['SoTran'] ?></strong>trận</div>
          <div><strong><?= (int) $tong['SoThang'] ?>/<?= (int) $tong['SoThua'] ?>/<?= (int) $tong['SoHoa'] ?></strong>thắng/thua/hòa</div>
          <div><strong><?= e((string) $tyLe) ?>%</strong>tỷ lệ thắng</div>
        </div>
        <?php if ($eloCaoNhat): ?>
          <p>Elo cao nhất: <strong><?= (int) $eloCaoNhat['DiemElo'] ?></strong> (<?= e($eloCaoNhat['TenMon']) ?>)</p>
        <?php endif; ?>
      <?php endif; ?>
      <a href="<?= e(BASE_URL) ?>/client/hoso.php">Xem Elo từng môn</a>
    </div>

    <div class="card">
      <h3>Trận gần đây</h3>
      <?php if (!$tranGanDay): ?>
        <p>Chưa có trận nào.</p>
      <?php else: ?>
        <table>
          <tr><th>Môn</th><th>Đối thủ</th><th>Kết quả</th><th>Elo</th><th></th></tr>
          <?php foreach ($tranGanDay as $r): ?>
            <tr>
              <td><?= e($r['TenMon']) ?></td>
              <td><?= e($r['DoiThu']) ?></td>
              <td>
                <?php if ($r['TrangThai'] === 'Hủy'): ?>
                  Hủy
                <?php else: ?>
                  <?= e($r['KetQua']) ?> (<?= (int) $r['DiemSo'] ?>&ndash;<?= (int) $r['DiemDoiThu'] ?>)
                <?php endif; ?>
              </td>
              <td>
                <?php if ($r['EloSau'] !== null): $d = (int) $r['EloSau'] - (int) $r['EloTruoc']; ?>
                  <?= $d >= 0 ? '+' : '' ?><?= $d ?>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($r['TrangThai'] === 'Kết thúc'): ?>
                  <a href="<?= e(BASE_URL) ?>/client/xemlai.php?tran=<?= (int) $r['MaTran'] ?>">Xem lại</a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </table>
      <?php endif; ?>
      <p><a href="<?= e(BASE_URL) ?>/client/lichsutran.php">Xem tất cả lịch sử</a></p>
    </div>
  <?php endif; ?>

  <div class="card">
    <h3>Bảng xếp hạng<?= $tenMonBxh !== '' ? ' &ndash; ' . e($tenMonBxh) : '' ?></h3>
    <?php if (!$bxh): ?>
      <p>Chưa có dữ liệu xếp hạng.</p>
    <?php else: ?>
      <table>
        <tr><th>Hạng</th><th>Sinh viên</th><th>Elo</th><th>Trận</th></tr>
        <?php foreach ($bxh as $r): $laToi = $maND !== null && (int) $r['MaND'] === $maND; ?>
          <tr<?= $laToi ? ' class="la-toi"' : '' ?>>
            <td><?= (int) $r['Hang'] ?></td>
            <td><?= e($r['HoTen']) ?><?= $laToi ? ' (bạn)' : '' ?></td>
            <td><?= (int) $r['DiemElo'] ?></td>
            <td><?= (int) $r['SoTran'] ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
    <p><a href="<?= e(BASE_URL) ?>/client/bxh.php<?= $monUaThich > 0 ? '?mon=' . (int) $monUaThich : '' ?>">Xem bảng xếp hạng đầy đủ</a></p>
  </div>
</div>

<div class="card">
  <h3>Bài đăng mới</h3>
  <?php if (!$baiMoi): ?>
    <p>Chưa có bài đăng nào.</p>
  <?php endif; ?>
  <?php foreach ($baiMoi as $b): ?>
    <div class="bai-moi">
      <a href="<?= e(BASE_URL) ?>/client/baidang.php?id=<?= (int) $b['MaBaiDang'] ?>"><strong><?= e($b['TieuDe']) ?></strong></a>
      <div class="meta">
        <?= e($b['HoTen']) ?> &middot; <?= e(CongDongHelper::ngay($b['NgayDang'])) ?><?= $b['TenMon'] ? ' &middot; ' . e($b['TenMon']) : '' ?>
      </div>
      <div><?= e(mb_strimwidth($b['NoiDung'], 0, 140, '…')) ?></div>
    </div>
  <?php endforeach; ?>
  <a href="<?= e(BASE_URL) ?>/client/congdong.php">Vào cộng đồng</a>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>