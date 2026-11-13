<?php
// Khảo sát định hướng sau khi đăng ký (C-45, C-46, C-47). Đặt tại: elo_quiz/client/khaosat.php
// Gồm 3 câu hỏi: mục tiêu (nhiều lựa chọn), lĩnh vực yêu thích (nhiều lựa chọn), trình độ tự đánh giá (một lựa chọn).
require_once __DIR__ . '/../core/bootstrap.php';

$maND = Auth::requireClient(BASE_URL . '/client/dangnhap.php');
$pdo  = Database::pdo();

// Câu 1 (C-45): mã lưu vào CSDL => nhãn hiển thị
$mucTieu = [
    'kiem_tra_kien_thuc' => 'Kiểm tra đánh giá kiến thức',
    'on_luyen_nhanh'     => 'Ôn luyện nhanh chóng',
    'ghi_nho_lau'        => 'Ghi nhớ lâu',
    'ap_luc_thoi_gian'   => 'Rèn luyện áp lực thời gian',
    'so_hoa_du_lieu'     => 'Số hóa dữ liệu học tập',
    'xoa_so_thi'         => 'Xóa bỏ tâm lý sợ thi cử',
];
// Câu 2: lĩnh vực yêu thích (phải trùng với admin/khaosat.php)
$linhVuc = [
    'toan_hoc'  => 'Toán học',
    'tin_hoc'   => 'Tin học',
    'khoa_hoc'  => 'Khoa học',
    'tieng_anh' => 'Tiếng Anh',
    'lich_su'   => 'Lịch sử',
];
// Câu 3: trình độ tự đánh giá (giá trị trùng với ENUM TrinhDoTuDanhGia)
$trinhDo = ['Mới bắt đầu', 'Trung bình', 'Khá', 'Nâng cao'];

// C-46: chỉ hiện bảng khi trạng thái là "Chưa làm"; Hoàn tất hoặc Bỏ qua thì không hiện lại
$st = $pdo->prepare('SELECT TrangThaiKhaoSat FROM NGUOIDUNG WHERE MaND = ?');
$st->execute([$maND]);
$trangThai = $st->fetchColumn();

if ($trangThai === false) {            // tài khoản không còn tồn tại
    unset($_SESSION['nd_id']);
    redirect(BASE_URL . '/client/dangnhap.php');
}
if ($trangThai !== 'Chưa làm') {
    redirect(BASE_URL . '/client/index.php');
}

$loi       = [];
$daChon    = [];   // mục tiêu đã chọn
$daChonLV  = [];   // lĩnh vực đã chọn
$daChonTD  = '';   // trình độ đã chọn

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $hanhDong = (string) ($_POST['hanh_dong'] ?? '');

    if ($hanhDong === 'boqua') {
        // Bỏ qua: không lưu câu trả lời nào, nhưng vẫn ghi thời điểm để thống kê theo ngày
        $up = $pdo->prepare(
            "UPDATE NGUOIDUNG
             SET TrangThaiKhaoSat = 'Bỏ qua', MucTieuKhaoSat = NULL,
                 LinhVucYeuThich = NULL, TrinhDoTuDanhGia = NULL, NgayKhaoSat = NOW()
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

        $guiLV = $_POST['linh_vuc'] ?? [];
        $guiLV = is_array($guiLV) ? $guiLV : [];
        $daChonLV = array_values(array_intersect(array_keys($linhVuc), array_map('strval', $guiLV)));

        $td = $_POST['trinh_do'] ?? '';
        $daChonTD = (is_string($td) && in_array($td, $trinhDo, true)) ? $td : '';

        // C-46: Hoàn tất yêu cầu trả lời đủ cả 3 câu
        if (!$daChon) {
            $loi[] = 'Câu 1: hãy chọn ít nhất một mục tiêu.';
        }
        if (!$daChonLV) {
            $loi[] = 'Câu 2: hãy chọn ít nhất một lĩnh vực yêu thích.';
        }
        if ($daChonTD === '') {
            $loi[] = 'Câu 3: hãy chọn trình độ của bạn.';
        }
        if (!$loi) {
            // C-47: lưu danh sách mã, phân cách bằng dấu phẩy
            $up = $pdo->prepare(
                "UPDATE NGUOIDUNG
                 SET TrangThaiKhaoSat = 'Hoàn tất', MucTieuKhaoSat = ?,
                     LinhVucYeuThich = ?, TrinhDoTuDanhGia = ?, NgayKhaoSat = NOW()
                 WHERE MaND = ? AND TrangThaiKhaoSat = 'Chưa làm'"
            );
            $up->execute([implode(',', $daChon), implode(',', $daChonLV), $daChonTD, $maND]);
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
    <h2>Khảo sát định hướng</h2>
    <p class="ghi-chu">Trả lời 3 câu hỏi ngắn để chúng tôi hoàn thiện trải nghiệm cho bạn.</p>

    <?php if ($loi): ?>
      <div class="loi">
        <?php foreach ($loi as $m): ?><div><?= e($m) ?></div><?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="post" action="">
      <?= csrf_field() ?>

      <h3 class="cau-hoi">1. Bạn dùng QUIZUP ARENA để làm gì? <small>(chọn một hoặc nhiều)</small></h3>
      <?php foreach ($mucTieu as $ma => $nhan): ?>
        <label class="the-muctieu">
          <input type="checkbox" name="muc_tieu[]" value="<?= e($ma) ?>"
                 <?= in_array($ma, $daChon, true) ? 'checked' : '' ?>>
          <?= e($nhan) ?>
        </label>
      <?php endforeach; ?>

      <h3 class="cau-hoi">2. Bạn yêu thích lĩnh vực nào? <small>(chọn một hoặc nhiều)</small></h3>
      <?php foreach ($linhVuc as $ma => $nhan): ?>
        <label class="the-muctieu">
          <input type="checkbox" name="linh_vuc[]" value="<?= e($ma) ?>"
                 <?= in_array($ma, $daChonLV, true) ? 'checked' : '' ?>>
          <?= e($nhan) ?>
        </label>
      <?php endforeach; ?>

      <h3 class="cau-hoi">3. Bạn tự đánh giá trình độ hiện tại của mình như thế nào?</h3>
      <?php foreach ($trinhDo as $nhan): ?>
        <label class="the-muctieu">
          <input type="radio" name="trinh_do" value="<?= e($nhan) ?>"
                 <?= $daChonTD === $nhan ? 'checked' : '' ?>>
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