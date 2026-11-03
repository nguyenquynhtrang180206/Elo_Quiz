<?php
// Bảng xếp hạng (C-36 đến C-39). Đặt tại: elo_quiz/client/bxh.php
// Ba phạm vi: theo môn (C-36), toàn trường (C-37), trong nhóm bạn bè (C-38).
// Ba kỳ: tất cả, tuần này, tháng này (C-39): kỳ tuần/tháng xếp theo tổng ThayDoi trong LICHSUELO của kỳ.
// Xem BXH không bắt buộc đăng nhập (C-12); riêng phạm vi bạn bè cần đăng nhập. Phân trang 10 dòng/trang (3.8).
require_once __DIR__ . '/../core/bootstrap.php';

$pdo  = Database::pdo();
$toi  = Auth::clientId();   // có thể null: khách vẫn xem được
$nMin = max(1, Config::int('SO_TRAN_TOI_THIEU_BXH', 10));

$dsMon = $pdo->query("SELECT MaMon, TenMon FROM MONHOC WHERE TrangThai = 'Đang mở' ORDER BY TenMon")->fetchAll();
$mon = (int) ($_GET['mon'] ?? 0);
$monHopLe = false;
foreach ($dsMon as $m) {
    if ((int) $m['MaMon'] === $mon) {
        $monHopLe = true;
    }
}
if (!$monHopLe) {
    $mon = $dsMon ? (int) $dsMon[0]['MaMon'] : 0;
}

$phamVi = (string) ($_GET['pv'] ?? 'mon');
if (!in_array($phamVi, ['mon', 'truong', 'banbe'], true)) {
    $phamVi = 'mon';
}
$ky = (string) ($_GET['ky'] ?? 'tong');
if (!in_array($ky, ['tong', 'tuan', 'thang'], true)) {
    $ky = 'tong';
}

// Mốc đầu kỳ: tuần bắt đầu từ thứ Hai, tháng bắt đầu từ ngày 1
$tuNgay = null;
if ($ky === 'tuan') {
    $tuNgay = date('Y-m-d 00:00:00', strtotime('monday this week'));
} elseif ($ky === 'thang') {
    $tuNgay = date('Y-m-01 00:00:00');
}

// Tập "tôi và bạn bè đã chấp nhận": cần 3 tham số là mã người dùng
$taiBanBe = '(SELECT MaNguoiNhan FROM BANBE WHERE MaNguoiGui = ? AND TrangThai = \'Đã chấp nhận\'
              UNION SELECT MaNguoiGui FROM BANBE WHERE MaNguoiNhan = ? AND TrangThai = \'Đã chấp nhận\'
              UNION SELECT ?)';

$canDangNhap = $phamVi === 'banbe' && $toi === null;
$khongCoMon  = $phamVi !== 'truong' && $mon === 0;
$core = null;
$p = [];
$kieu = '';   // quyết định các cột hiển thị

if (!$canDangNhap && !$khongCoMon) {
    if ($ky === 'tong' && $phamVi === 'truong') {
        // C-37: Elo trung bình các môn đã đạt tối thiểu N trận; môn chưa đủ N không được tính
        $kieu = 'truong';
        $core = "SELECT u.MaND, u.HoTen, AVG(e.DiemElo) AS Diem, COUNT(*) AS SoMon,
                        SUM(e.SoTran) AS SoTran, SUM(e.SoThang) AS SoThang
                   FROM ELOMONHOC e JOIN NGUOIDUNG u ON u.MaND = e.MaND
                  WHERE e.SoTran >= ? AND u.TrangThai = 'Hoạt động'
                  GROUP BY u.MaND, u.HoTen";
        $p = [$nMin];
    } elseif ($ky === 'tong') {
        // C-36, C-38: theo môn, có thể giới hạn trong nhóm bạn bè
        $kieu = 'mon';
        $core = "SELECT u.MaND, u.HoTen, e.DiemElo AS Diem, e.SoTran, e.SoThang
                   FROM ELOMONHOC e JOIN NGUOIDUNG u ON u.MaND = e.MaND
                  WHERE e.MaMon = ? AND e.SoTran > 0 AND u.TrangThai = 'Hoạt động'";
        $p = [$mon];
        if ($phamVi === 'banbe') {
            $core .= " AND e.MaND IN $taiBanBe";
            array_push($p, $toi, $toi, $toi);
        }
    } else {
        // C-39: tổng mức thay đổi Elo trong kỳ; người không có trận trong kỳ không xuất hiện
        $kieu = 'ky';
        if ($phamVi === 'truong') {
            $core = "SELECT u.MaND, u.HoTen, SUM(l.ThayDoi) AS Diem, COUNT(*) AS SoTran,
                            (SELECT AVG(x.DiemElo) FROM ELOMONHOC x WHERE x.MaND = l.MaND AND x.SoTran >= ?) AS EloHienTai
                       FROM LICHSUELO l JOIN NGUOIDUNG u ON u.MaND = l.MaND
                      WHERE l.ThoiGian >= ? AND u.TrangThai = 'Hoạt động'
                      GROUP BY u.MaND, u.HoTen";
            $p = [$nMin, $tuNgay];
        } else {
            $core = "SELECT u.MaND, u.HoTen, SUM(l.ThayDoi) AS Diem, COUNT(*) AS SoTran, e.DiemElo AS EloHienTai
                       FROM LICHSUELO l
                       JOIN NGUOIDUNG u ON u.MaND = l.MaND
                       JOIN ELOMONHOC e ON e.MaND = l.MaND AND e.MaMon = l.MaMon
                      WHERE l.MaMon = ? AND l.ThoiGian >= ? AND u.TrangThai = 'Hoạt động'";
            $p = [$mon, $tuNgay];
            if ($phamVi === 'banbe') {
                $core .= " AND l.MaND IN $taiBanBe";
                array_push($p, $toi, $toi, $toi);
            }
            $core .= ' GROUP BY u.MaND, u.HoTen, e.DiemElo';
        }
    }
}

$ds = [];
$phanTrang = null;
if ($core !== null) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM ($core) d");
    $st->execute($p);
    $phanTrang = new Paginator((int) $st->fetchColumn(), 10);
    // Cùng điểm thì cùng hạng (RANK); trong cùng hạng ưu tiên người nhiều trận hơn
    $st = $pdo->prepare(
        "SELECT r.* FROM (SELECT d.*, RANK() OVER (ORDER BY d.Diem DESC) AS Hang FROM ($core) d) r
          ORDER BY r.Hang, r.SoTran DESC, r.MaND
          LIMIT " . $phanTrang->limit() . ' OFFSET ' . $phanTrang->offset()
    );
    $st->execute($p);
    $ds = $st->fetchAll();
}

$tenKy = ['tong' => 'Tất cả thời gian', 'tuan' => 'Tuần này (từ thứ Hai)', 'thang' => 'Tháng này'][$ky];
$tenMon = '';
foreach ($dsMon as $m) {
    if ((int) $m['MaMon'] === $mon) {
        $tenMon = $m['TenMon'];
    }
}

function dau(int $n): string
{
    return ($n > 0 ? '+' : '') . $n;
}

$tieuDe = 'Bảng xếp hạng';
$cssTrang = 'bxh';
require __DIR__ . '/layout/header.php';
?>
<h2>Bảng xếp hạng</h2>
<div class="card">
  <form method="get" action="">
    <label for="pv">Phạm vi</label>
    <select id="pv" name="pv">
      <option value="mon" <?= $phamVi === 'mon' ? 'selected' : '' ?>>Theo môn</option>
      <option value="truong" <?= $phamVi === 'truong' ? 'selected' : '' ?>>Toàn trường</option>
      <option value="banbe" <?= $phamVi === 'banbe' ? 'selected' : '' ?>>Bạn bè (theo môn)</option>
    </select>
    <label for="mon">Môn học (không dùng cho Toàn trường)</label>
    <select id="mon" name="mon">
      <?php foreach ($dsMon as $m): ?>
        <option value="<?= (int) $m['MaMon'] ?>" <?= (int) $m['MaMon'] === $mon ? 'selected' : '' ?>><?= e($m['TenMon']) ?></option>
      <?php endforeach; ?>
    </select>
    <label for="ky">Thời gian</label>
    <select id="ky" name="ky">
      <option value="tong" <?= $ky === 'tong' ? 'selected' : '' ?>>Tất cả</option>
      <option value="tuan" <?= $ky === 'tuan' ? 'selected' : '' ?>>Tuần này</option>
      <option value="thang" <?= $ky === 'thang' ? 'selected' : '' ?>>Tháng này</option>
    </select>
    <p><button type="submit" class="btn">Xem</button></p>
  </form>
</div>

<div class="card">
  <h3>
    <?= $phamVi === 'truong' ? 'Toàn trường' : ($phamVi === 'banbe' ? 'Nhóm bạn bè' : 'Theo môn') ?>
    <?= $phamVi !== 'truong' && $tenMon !== '' ? ' &ndash; ' . e($tenMon) : '' ?>
    &middot; <?= e($tenKy) ?>
  </h3>

  <?php if ($canDangNhap): ?>
    <p>Hãy <a href="<?= e(BASE_URL) ?>/client/login.php">đăng nhập</a> để xem bảng xếp hạng bạn bè.</p>
  <?php elseif ($khongCoMon): ?>
    <p>Chưa có môn học nào đang mở.</p>
  <?php elseif (!$ds): ?>
    <p>Chưa có dữ liệu xếp hạng cho lựa chọn này.</p>
  <?php else: ?>
    <table>
      <?php if ($kieu === 'truong'): ?>
        <tr><th>Hạng</th><th>Sinh viên</th><th>Elo trung bình</th><th>Số môn tính</th><th>Số trận</th><th>Tỷ lệ thắng</th></tr>
      <?php elseif ($kieu === 'mon'): ?>
        <tr><th>Hạng</th><th>Sinh viên</th><th>Elo</th><th>Số trận</th><th>Tỷ lệ thắng</th></tr>
      <?php else: ?>
        <tr><th>Hạng</th><th>Sinh viên</th><th>Thay đổi Elo trong kỳ</th><th>Số trận trong kỳ</th><th>Elo hiện tại</th></tr>
      <?php endif; ?>
      <?php foreach ($ds as $r):
          $laToi = $toi !== null && (int) $r['MaND'] === $toi;
          $tyLe = isset($r['SoThang']) && (int) $r['SoTran'] > 0 ? round($r['SoThang'] * 100 / $r['SoTran'], 1) : 0;
      ?>
        <tr<?= $laToi ? ' class="la-toi"' : '' ?>>
          <td><?= (int) $r['Hang'] ?></td>
          <td><?= e($r['HoTen']) ?><?= $laToi ? ' (bạn)' : '' ?></td>
          <?php if ($kieu === 'truong'): ?>
            <td><?= e((string) round((float) $r['Diem'], 1)) ?></td>
            <td><?= (int) $r['SoMon'] ?></td>
            <td><?= (int) $r['SoTran'] ?></td>
            <td><?= e((string) $tyLe) ?>%</td>
          <?php elseif ($kieu === 'mon'): ?>
            <td><?= (int) $r['Diem'] ?></td>
            <td><?= (int) $r['SoTran'] ?></td>
            <td><?= e((string) $tyLe) ?>%</td>
          <?php else: ?>
            <td><?= dau((int) $r['Diem']) ?></td>
            <td><?= (int) $r['SoTran'] ?></td>
            <td><?= $r['EloHienTai'] === null ? '&ndash;' : e((string) round((float) $r['EloHienTai'], $phamVi === 'truong' ? 1 : 0)) ?></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
    </table>
    <?= $phanTrang->links() ?>
  <?php endif; ?>

  <p class="ghi-chu">
    <?php if ($phamVi === 'truong'): ?>
      <?php if ($ky === 'tong'): ?>
        Elo trung bình chỉ tính các môn bạn đã đấu từ <?= (int) $nMin ?> trận trở lên; số trận và tỷ lệ thắng cũng chỉ tính trong các môn đó.
      <?php else: ?>
        Xếp theo tổng mức thay đổi Elo của mọi môn trong kỳ; Elo hiện tại là Elo trung bình các môn đã đủ <?= (int) $nMin ?> trận.
      <?php endif; ?>
    <?php elseif ($ky !== 'tong'): ?>
      Xếp theo tổng mức thay đổi Elo trong kỳ; người không có trận trong kỳ không hiện ở bảng này.
    <?php else: ?>
      Chỉ hiện người đã đấu ít nhất một trận ở môn này. Cùng Elo thì cùng hạng.
    <?php endif; ?>
  </p>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>