<?php
// Thống kê khảo sát định hướng (UC10, luồng thay thế: A-35, A-36). Đặt tại: elo_quiz/admin/khaosat.php
require_once __DIR__ . '/../core/bootstrap.php';
Auth::requireAdmin(BASE_URL . '/admin/login.php');   // A-04

$pdo = Database::pdo();

// Cùng danh sách mã => nhãn với client/khaosat.php
$mucTieu = [
    'kiem_tra_kien_thuc' => 'Kiểm tra đánh giá kiến thức',
    'on_luyen_nhanh'     => 'Ôn luyện nhanh chóng',
    'ghi_nho_lau'        => 'Ghi nhớ lâu',
    'ap_luc_thoi_gian'   => 'Rèn luyện áp lực thời gian',
    'so_hoa_du_lieu'     => 'Số hóa dữ liệu học tập',
    'xoa_so_thi'         => 'Xóa bỏ tâm lý sợ thi cử',
];

// ---- A-36: tỷ lệ hoàn tất, bỏ qua (và chưa làm) ----
$theoTrangThai = ['Hoàn tất' => 0, 'Bỏ qua' => 0, 'Chưa làm' => 0];
foreach ($pdo->query('SELECT TrangThaiKhaoSat, COUNT(*) AS so FROM NGUOIDUNG GROUP BY TrangThaiKhaoSat') as $r) {
    $theoTrangThai[$r['TrangThaiKhaoSat']] = (int) $r['so'];
}
$tongNguoi = array_sum($theoTrangThai);

// ---- A-35: số lượt chọn từng mục tiêu ----
// Mục tiêu lưu trong một cột, phân cách bằng dấu phẩy (xem 4.1 và 7.1), nên tách chuỗi ở đây.
$luot = array_fill_keys(array_keys($mucTieu), 0);
$rows = $pdo->query(
    "SELECT MucTieuKhaoSat FROM NGUOIDUNG
     WHERE TrangThaiKhaoSat = 'Hoàn tất' AND MucTieuKhaoSat IS NOT NULL AND MucTieuKhaoSat <> ''"
)->fetchAll(PDO::FETCH_COLUMN);
foreach ($rows as $chuoi) {
    foreach (array_unique(array_map('trim', explode(',', (string) $chuoi))) as $ma) {
        if (isset($luot[$ma])) {
            $luot[$ma]++;
        }
    }
}
arsort($luot);
$soHoanTat = $theoTrangThai['Hoàn tất'];
$lonNhat   = max(1, ...array_values($luot));

$tieuDe  = 'Thống kê khảo sát';
$mucChon = 'khaosat';
$cssTrang = 'khaosat';
require __DIR__ . '/layout/header.php';
?>
<h2>Thống kê khảo sát</h2>

<h3>Tình trạng làm khảo sát</h3>
<div class="the-so">
  <?php foreach ($theoTrangThai as $ten => $so): ?>
    <div class="card">
      <div class="nhan-phu"><?= e($ten) ?></div>
      <div class="so-lon"><?= $so ?></div>
      <div class="nhan-phu"><?= $tongNguoi > 0 ? e((string) round($so * 100 / $tongNguoi, 1)) : 0 ?>% tổng số sinh viên</div>
    </div>
  <?php endforeach; ?>
</div>

<h3>Số lượt chọn từng mục tiêu</h3>
<div class="card">
  <?php if ($soHoanTat === 0): ?>
    <p class="nhan-phu">Chưa có sinh viên nào hoàn tất khảo sát.</p>
  <?php else: ?>
    <table>
      <tr><th>Mục tiêu</th><th>Lượt chọn</th><th></th><th>Trên số SV hoàn tất</th></tr>
      <?php foreach ($luot as $ma => $so): ?>
        <tr>
          <td><?= e($mucTieu[$ma]) ?></td>
          <td class="cot-so"><?= $so ?></td>
          <td class="cot-thanh"><div class="thanh"><span style="width:<?= round($so * 100 / $lonNhat) ?>%"></span></div></td>
          <td><?= e((string) round($so * 100 / $soHoanTat, 1)) ?>%</td>
        </tr>
      <?php endforeach; ?>
    </table>
    <p class="nhan-phu">Mỗi sinh viên được chọn nhiều mục tiêu nên tổng các tỷ lệ có thể lớn hơn 100%.</p>
  <?php endif; ?>
</div>

<p><a class="btn btn-phu" href="<?= e(BASE_URL) ?>/admin/index.php">Quay lại Tổng quan</a></p>
<?php require __DIR__ . '/layout/footer.php'; ?>