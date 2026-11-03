<?php
// Sửa bài đăng của chính mình (C-43). Đặt tại: elo_quiz/client/suabai.php
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/CongDongHelper.php';

$maND = Auth::requireClient(BASE_URL . '/client/login.php');
$pdo  = Database::pdo();
$id   = (int) ($_GET['id'] ?? 0);

// Chỉ sửa được bài của mình và đang "Hiển thị" (bài bị Admin ẩn thì chủ bài không tự mở lại được)
$st = $pdo->prepare(
    "SELECT b.MaBaiDang, b.MaMon, b.TieuDe, b.NoiDung, m.TenMon
       FROM BAIDANG b LEFT JOIN MONHOC m ON m.MaMon = b.MaMon
      WHERE b.MaBaiDang = ? AND b.MaND = ? AND b.TrangThai = 'Hiển thị'"
);
$st->execute([$id, $maND]);
$bai = $st->fetch();

$monDangMo = CongDongHelper::monDangMo();
// Bài đang gắn môn đã bị ẩn: vẫn giữ môn đó trong danh sách để không vô tình bỏ gắn
if ($bai && $bai['MaMon'] && !isset($monDangMo[(int) $bai['MaMon']])) {
    $monDangMo[(int) $bai['MaMon']] = $bai['TenMon'] . ' (đã ẩn)';
}

$loi = [];
$tieuDeBai  = $bai['TieuDe'] ?? '';
$noiDungBai = $bai['NoiDung'] ?? '';
$monChon    = $bai ? (string) ($bai['MaMon'] ?? '') : '';

if ($bai && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $tieuDeBai  = trim((string) ($_POST['tieu_de'] ?? ''));
    $noiDungBai = trim((string) ($_POST['noi_dung'] ?? ''));
    $monChon    = (string) ($_POST['mon'] ?? '');

    [$loi, $maMon] = CongDongHelper::kiemTraBai($tieuDeBai, $noiDungBai, $monChon, $monDangMo);

    if (!$loi) {
        $up = $pdo->prepare(
            "UPDATE BAIDANG SET TieuDe = ?, NoiDung = ?, MaMon = ?
              WHERE MaBaiDang = ? AND MaND = ? AND TrangThai = 'Hiển thị'"
        );
        $up->execute([$tieuDeBai, $noiDungBai, $maMon, $id, $maND]);
        CongDongHelper::flash('Đã cập nhật bài đăng.');
        redirect(BASE_URL . '/client/baidang.php?id=' . $id);
    }
}

$tieuDe = 'Sửa bài đăng';
$cssTrang = 'suabai';
require __DIR__ . '/layout/header.php';
?>
<div class="container khung-suabai">
  <?php if (!$bai): ?>
    <div class="card">Không tìm thấy bài đăng, hoặc bạn không có quyền sửa bài này.</div>
    <p><a href="<?= e(BASE_URL) ?>/client/congdong.php">&laquo; Về bảng tin</a></p>
  <?php else: ?>
    <div class="card">
      <h2 class="tieu-de-sua">Sửa bài đăng</h2>
      <?php if ($loi): ?>
        <div class="loi"><?php foreach ($loi as $m): ?><div><?= e($m) ?></div><?php endforeach; ?></div>
      <?php endif; ?>
      <form method="post" action="?id=<?= (int) $id ?>" novalidate>
        <?= csrf_field() ?>
        <label for="tieu_de">Tiêu đề</label>
        <input type="text" id="tieu_de" name="tieu_de" maxlength="200" value="<?= e($tieuDeBai) ?>" required>

        <label for="mon">Môn học (không bắt buộc)</label>
        <select id="mon" name="mon">
          <option value="">Không gắn môn</option>
          <?php foreach ($monDangMo as $mid => $ten): ?>
            <option value="<?= (int) $mid ?>" <?= (string) $mid === $monChon ? 'selected' : '' ?>><?= e($ten) ?></option>
          <?php endforeach; ?>
        </select>

        <label for="noi_dung">Nội dung</label>
        <textarea id="noi_dung" name="noi_dung" maxlength="5000" required><?= e($noiDungBai) ?></textarea>

        <p><button type="submit" class="btn">Lưu</button>
           <a class="btn btn-phu" href="<?= e(BASE_URL) ?>/client/baidang.php?id=<?= (int) $id ?>">Hủy</a></p>
      </form>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>