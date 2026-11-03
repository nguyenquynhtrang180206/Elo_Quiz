<?php
// Khảo sát định hướng sau khi đăng ký (C-45, C-46, C-47). Đặt tại: elo_quiz/client/khaosat.php
require_once __DIR__ . '/../core/bootstrap.php';

$maND = Auth::requireClient(BASE_URL . '/client/login.php');
$pdo  = Database::pdo();

// Danh sách mục tiêu (C-45): mã lưu vào CSDL => nhãn hiển thị
$mucTieu = [
    'kiem_tra_kien_thuc' => 'Kiểm tra đánh giá kiến thức',
    'on_luyen_nhanh'     => 'Ôn luyện nhanh chóng',
    'ghi_nho_lau'        => 'Ghi nhớ lâu',
    'ap_luc_thoi_gian'   => 'Rèn luyện áp lực thời gian',
    'so_hoa_du_lieu'     => 'Số hóa dữ liệu học tập',
    'xoa_so_thi'         => 'Xóa bỏ tâm lý sợ thi cử',
];

// C-46: chỉ hiện bảng khi trạng thái là "Chưa làm"; Hoàn tất hoặc Bỏ qua thì không hiện lại
$st = $pdo->prepare('SELECT TrangThaiKhaoSat FROM NGUOIDUNG WHERE MaND = ?');
$st->execute([$maND]);
$trangThai = $st->fetchColumn();

if ($trangThai === false) {            // tài khoản không còn tồn tại
    unset($_SESSION['nd_id']);
    redirect(BASE_URL . '/client/login.php');
}
if ($trangThai !== 'Chưa làm') {
    redirect(BASE_URL . '/client/index.php');
}

$loi   = [];
$daChon = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $hanhDong = (string) ($_POST['hanh_dong'] ?? '');

    if ($hanhDong === 'boqua') {
        // Bỏ qua: không lưu mục tiêu nào
        $up = $pdo->prepare(
            "UPDATE NGUOIDUNG SET TrangThaiKhaoSat = 'Bỏ qua', MucTieuKhaoSat = NULL
             WHERE MaND = ? AND TrangThaiKhaoSat = 'Chưa làm'"
        );
        $up->execute([$maND]);
        redirect(BASE_URL . '/client/index.php');
    }

    if ($hanhDong === 'hoantat') {
        // Chỉ nhận mã nằm trong danh sách cho phép, bỏ trùng
        $gui = $_POST['muc_tieu'] ?? [];
        $gui = is_array($gui) ? $gui : [];
        $daChon = array_values(array_intersect(array_keys($mucTieu), array_map('strval', $gui)));

        // C-46: Hoàn tất yêu cầu chọn ít nhất một mục
        if (!$daChon) {
            $loi[] = 'Hãy chọn ít nhất một mục tiêu, hoặc bấm "Bỏ qua".';
        } else {
            // C-47: lưu danh sách mã, phân cách bằng dấu phẩy
            $up = $pdo->prepare(
                "UPDATE NGUOIDUNG SET TrangThaiKhaoSat = 'Hoàn tất', MucTieuKhaoSat = ?
                 WHERE MaND = ? AND TrangThaiKhaoSat = 'Chưa làm'"
            );
            $up->execute([implode(',', $daChon), $maND]);
            redirect(BASE_URL . '/client/index.php');
        }
    }
}

$tieuDe = 'Khảo sát định hướng';
$cssTrang = 'khaosat';
require __DIR__ . '/layout/header.php';
?>
<div class="container khung-khaosat">
  <div class="card">
    <h2>Bạn muốn dùng Đấu Trường Tri Thức để làm gì?</h2>
    <p class="ghi-chu">Chọn một hoặc nhiều mục tiêu để chúng tôi hoàn thiện trải nghiệm cho bạn.</p>

    <?php if ($loi): ?>
      <div class="loi">
        <?php foreach ($loi as $m): ?><div><?= e($m) ?></div><?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="post" action="">
      <?= csrf_field() ?>

      <?php foreach ($mucTieu as $ma => $nhan): ?>
        <label class="the-muctieu">
          <input type="checkbox" name="muc_tieu[]" value="<?= e($ma) ?>"
                 <?= in_array($ma, $daChon, true) ? 'checked' : '' ?>>
          <?= e($nhan) ?>
        </label>
      <?php endforeach; ?>

      <div class="nut-khaosat">
        <button type="submit" name="hanh_dong" value="hoantat" class="btn">Hoàn tất</button>
        <button type="submit" name="hanh_dong" value="boqua" class="btn btn-phu">Bỏ qua</button>
      </div>
    </form>
  </div>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>