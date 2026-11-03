<?php
// Dashboard và thống kê (UC10: A-06 đến A-10). Đặt tại: elo_quiz/admin/index.php
require_once __DIR__ . '/../core/bootstrap.php';
Auth::requireAdmin(BASE_URL . '/admin/login.php');   // A-04

$pdo = Database::pdo();

// Khoảng thời gian xem (ngày gần nhất): chỉ nhận 7, 14 hoặc 30
$ngay = (int) ($_GET['ngay'] ?? 14);
if (!in_array($ngay, [7, 14, 30], true)) {
    $ngay = 14;
}
$tuNgay = date('Y-m-d', strtotime('-' . ($ngay - 1) . ' days'));   // ngày đầu của khoảng
$moc    = $tuNgay . ' 00:00:00';

// ---- A-06: tổng số người dùng, môn học, câu hỏi, trận đấu ----
$tong = $pdo->query(
    'SELECT (SELECT COUNT(*) FROM NGUOIDUNG) AS nguoidung,
            (SELECT COUNT(*) FROM MONHOC)    AS monhoc,
            (SELECT COUNT(*) FROM CAUHOI)    AS cauhoi,
            (SELECT COUNT(*) FROM TRANDAU)   AS trandau'
)->fetch();

// ---- A-07: số trận theo trạng thái ----
$theoTrangThai = ['Đã ghép' => 0, 'Đang diễn ra' => 0, 'Kết thúc' => 0, 'Hủy' => 0];
foreach ($pdo->query('SELECT TrangThai, COUNT(*) AS so FROM TRANDAU GROUP BY TrangThai') as $r) {
    $theoTrangThai[$r['TrangThai']] = (int) $r['so'];
}

// ---- A-08: số trận và số người đăng ký mới theo ngày ----
// TRANDAU không có cột ngày tạo nên trận được tính vào ngày bắt đầu;
// trận bị hủy trước khi bắt đầu thì tính vào ngày kết thúc (hủy). Trận mới "Đã ghép" chưa có ngày nên chưa tính.
$bieuDo = [];
for ($i = $ngay - 1; $i >= 0; $i--) {
    $bieuDo[date('Y-m-d', strtotime("-$i days"))] = ['tran' => 0, 'dangky' => 0];
}

$st = $pdo->prepare(
    'SELECT DATE(COALESCE(ThoiGianBatDau, ThoiGianKetThuc)) AS ngay, COUNT(*) AS so
     FROM TRANDAU
     WHERE COALESCE(ThoiGianBatDau, ThoiGianKetThuc) >= ?
     GROUP BY ngay'
);
$st->execute([$moc]);
foreach ($st as $r) {
    if (isset($bieuDo[$r['ngay']])) {
        $bieuDo[$r['ngay']]['tran'] = (int) $r['so'];
    }
}

$st = $pdo->prepare(
    'SELECT DATE(NgayTao) AS ngay, COUNT(*) AS so FROM NGUOIDUNG WHERE NgayTao >= ? GROUP BY ngay'
);
$st->execute([$moc]);
foreach ($st as $r) {
    if (isset($bieuDo[$r['ngay']])) {
        $bieuDo[$r['ngay']]['dangky'] = (int) $r['so'];
    }
}

$lonNhatTran   = max(1, ...array_column($bieuDo, 'tran'));
$lonNhatDangKy = max(1, ...array_column($bieuDo, 'dangky'));

// ---- A-09: người dùng hoạt động = có tham gia (đã vào phòng) ít nhất một trận trong khoảng ----
$st = $pdo->prepare(
    "SELECT COUNT(DISTINCT n.MaND)
     FROM NGUOICHOITRANDAU n
     JOIN TRANDAU t ON t.MaTran = n.MaTran
     WHERE n.TrangThaiThamGia <> 'Chưa vào'
       AND COALESCE(t.ThoiGianBatDau, t.ThoiGianKetThuc) >= ?"
);
$st->execute([$moc]);
$hoatDong   = (int) $st->fetchColumn();
$tyLeHoatDong = (int) $tong['nguoidung'] > 0 ? round($hoatDong * 100 / (int) $tong['nguoidung'], 1) : 0;

// ---- A-10: 10 câu hỏi bị trả lời sai nhiều nhất (tính mọi trận) ----
// Sai = đã chọn một đáp án nhưng đáp án đó không đúng; câu hết giờ không tính là sai.
$caiSai = $pdo->query(
    "SELECT ch.MaCauHoi, ch.NoiDung, m.TenMon,
            SUM(d.LaDapAnDung = 0) AS so_sai,
            COUNT(*)               AS tong
     FROM CHITIETTRALOI c
     JOIN DAPAN  d  ON d.MaDapAn  = c.MaDapAn
     JOIN CAUHOI ch ON ch.MaCauHoi = c.MaCauHoi
     JOIN MONHOC m  ON m.MaMon    = ch.MaMon
     WHERE c.TrangThai = 'Đã trả lời'
     GROUP BY ch.MaCauHoi, ch.NoiDung, m.TenMon
     HAVING so_sai > 0
     ORDER BY so_sai DESC, tong ASC
     LIMIT 10"
)->fetchAll();

$tieuDe  = 'Tổng quan';
$mucChon = 'dashboard';
$cssTrang = 'index';
require __DIR__ . '/layout/header.php';
?>
<h2>Tổng quan</h2>

<form method="get" action="" class="card form-ngay">
  <label for="ngay">Khoảng thời gian xem:</label>
  <select id="ngay" name="ngay">
    <?php foreach ([7, 14, 30] as $n): ?>
      <option value="<?= $n ?>"<?= $n === $ngay ? ' selected' : '' ?>><?= $n ?> ngày gần nhất</option>
    <?php endforeach; ?>
  </select>
  <button type="submit" class="btn">Xem</button>
  <span class="nhan-phu">Áp dụng cho biểu đồ và người dùng hoạt động.</span>
</form>

<div class="the-so">
  <div class="card"><div class="nhan-phu">Người dùng</div><div class="so-lon"><?= (int) $tong['nguoidung'] ?></div></div>
  <div class="card"><div class="nhan-phu">Môn học</div><div class="so-lon"><?= (int) $tong['monhoc'] ?></div></div>
  <div class="card"><div class="nhan-phu">Câu hỏi</div><div class="so-lon"><?= (int) $tong['cauhoi'] ?></div></div>
  <div class="card"><div class="nhan-phu">Trận đấu</div><div class="so-lon"><?= (int) $tong['trandau'] ?></div></div>
</div>

<h3>Trận đấu theo trạng thái</h3>
<div class="the-so">
  <?php foreach ($theoTrangThai as $ten => $so): ?>
    <div class="card"><div class="nhan-phu"><?= e($ten) ?></div><div class="so-lon"><?= $so ?></div></div>
  <?php endforeach; ?>
</div>

<h3>Trận đấu và người đăng ký mới theo ngày (<?= $ngay ?> ngày gần nhất)</h3>
<div class="card">
  <table>
    <tr><th>Ngày</th><th>Số trận</th><th></th><th>Đăng ký mới</th><th></th></tr>
    <?php foreach ($bieuDo as $d => $so): ?>
      <tr>
        <td><?= e(date('d/m', strtotime($d))) ?></td>
        <td class="cot-so"><?= $so['tran'] ?></td>
        <td class="cot-thanh"><div class="thanh"><span style="width:<?= round($so['tran'] * 100 / $lonNhatTran) ?>%"></span></div></td>
        <td class="cot-so"><?= $so['dangky'] ?></td>
        <td class="cot-thanh"><div class="thanh xanh"><span style="width:<?= round($so['dangky'] * 100 / $lonNhatDangKy) ?>%"></span></div></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<h3>Người dùng hoạt động</h3>
<div class="card">
  <span class="so-lon"><?= $hoatDong ?></span>
  <span class="nhan-phu">người có tham gia trận trong <?= $ngay ?> ngày gần nhất (<?= e((string) $tyLeHoatDong) ?>% tổng số người dùng)</span>
</div>

<h3>10 câu hỏi bị trả lời sai nhiều nhất</h3>
<div class="card">
  <?php if (!$caiSai): ?>
    <p class="nhan-phu">Chưa có dữ liệu.</p>
  <?php else: ?>
    <table>
      <tr><th>Câu hỏi</th><th>Môn</th><th>Lượt sai</th><th>Lượt trả lời</th><th>Tỷ lệ sai</th></tr>
      <?php foreach ($caiSai as $r): ?>
        <tr>
          <td><?= e(preg_replace('/^(.{90}).+$/su', '$1…', (string) $r['NoiDung'])) ?></td>
          <td><?= e($r['TenMon']) ?></td>
          <td><?= (int) $r['so_sai'] ?></td>
          <td><?= (int) $r['tong'] ?></td>
          <td><?= e((string) round((int) $r['so_sai'] * 100 / (int) $r['tong'], 1)) ?>%</td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<p><a class="btn btn-phu" href="<?= e(BASE_URL) ?>/admin/nguoidung.php">Người dùng</a></p>
<p><a class="btn btn-phu" href="<?= e(BASE_URL) ?>/admin/khaosat.php">Xem thống kê khảo sát</a></p>
<?php require __DIR__ . '/layout/footer.php'; ?>