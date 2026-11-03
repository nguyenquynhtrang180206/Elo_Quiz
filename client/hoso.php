<?php
// Hồ sơ cá nhân (C-13, C-14). Đặt tại: elo_quiz/client/hoso.php
// C-15 (biểu đồ biến động Elo) làm sau, xem chỗ TODO ở cuối trang.
require_once __DIR__ . '/../core/bootstrap.php';

$maND = Auth::requireClient(BASE_URL . '/client/login.php');
$pdo  = Database::pdo();

const AVATAR_TOI_DA = 2 * 1024 * 1024;       // 2 MB (mục 3.9)
const AVATAR_THU_MUC = 'uploads/avatars';    // đường dẫn lưu trong cột AnhDaiDien, tính từ thư mục gốc dự án

function layNguoiDung(PDO $pdo, int $maND)
{
    $st = $pdo->prepare('SELECT MaND, HoTen, Email, AnhDaiDien, Lop, Khoa FROM NGUOIDUNG WHERE MaND = ?');
    $st->execute([$maND]);
    return $st->fetch();
}

$nd = layNguoiDung($pdo, $maND);
if (!$nd) {                                   // tài khoản không còn tồn tại
    unset($_SESSION['nd_id']);
    redirect(BASE_URL . '/client/login.php');
}

$loi = [];
$hoTen = $nd['HoTen'];
$lop   = (string) $nd['Lop'];
$khoa  = (string) $nd['Khoa'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $hoTen = trim((string) ($_POST['ho_ten'] ?? ''));
    $lop   = trim((string) ($_POST['lop'] ?? ''));
    $khoa  = trim((string) ($_POST['khoa'] ?? ''));

    if ($hoTen === '') {
        $loi[] = 'Họ tên không được để trống.';
    } elseif (mb_strlen($hoTen) > 100) {
        $loi[] = 'Họ tên tối đa 100 ký tự.';
    }
    if (mb_strlen($lop) > 50)   { $loi[] = 'Lớp tối đa 50 ký tự.'; }
    if (mb_strlen($khoa) > 100) { $loi[] = 'Khoa tối đa 100 ký tự.'; }

    // ---- Ảnh đại diện (3.9): chỉ JPG/PNG, tối đa 2 MB, kiểm tra loại file thật ----
    $duongDanMoi = null;
    $tepTam = null;
    $duoi = null;
    $f = $_FILES['anh_dai_dien'] ?? null;

    if ($f && $f['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
            $loi[] = 'Ảnh quá lớn, tối đa 2 MB.';
        } elseif ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            $loi[] = 'Tải ảnh lên không thành công, hãy thử lại.';
        } elseif ($f['size'] > AVATAR_TOI_DA) {
            $loi[] = 'Ảnh quá lớn, tối đa 2 MB.';
        } else {
            // Đọc loại ảnh thật từ nội dung file, không tin tên file hay loại do trình duyệt gửi
            $thongTin = @getimagesize($f['tmp_name']);
            $kieu = $thongTin[2] ?? null;
            if ($kieu === IMAGETYPE_JPEG)     { $duoi = 'jpg'; }
            elseif ($kieu === IMAGETYPE_PNG)  { $duoi = 'png'; }
            else { $loi[] = 'Ảnh đại diện chỉ nhận file JPG hoặc PNG.'; }
            $tepTam = $f['tmp_name'];
        }
    }

    if (!$loi) {
        $anhCu = $nd['AnhDaiDien'];

        if ($tepTam !== null && $duoi !== null) {
            $thuMuc = rtrim($cfg['upload_dir'], '/\\') . '/avatars';
            if (!is_dir($thuMuc)) { @mkdir($thuMuc, 0775, true); }
            $ten = bin2hex(random_bytes(16)) . '.' . $duoi;       // tên ngẫu nhiên
            if (move_uploaded_file($tepTam, $thuMuc . '/' . $ten)) {
                $duongDanMoi = AVATAR_THU_MUC . '/' . $ten;
            } else {
                $loi[] = 'Không lưu được ảnh lên máy chủ. Kiểm tra quyền ghi thư mục uploads/avatars.';
            }
        }
    }

    if (!$loi) {
        $anhLuu = $duongDanMoi ?? $nd['AnhDaiDien'];
        $st = $pdo->prepare('UPDATE NGUOIDUNG SET HoTen = ?, Lop = ?, Khoa = ?, AnhDaiDien = ? WHERE MaND = ?');
        $st->execute([
            $hoTen,
            $lop === '' ? null : $lop,
            $khoa === '' ? null : $khoa,
            $anhLuu,
            $maND,
        ]);

        // Xóa ảnh cũ sau khi đã thay ảnh mới (chỉ xóa file nằm trong uploads/avatars)
        if ($duongDanMoi !== null && !empty($anhCu) && strpos($anhCu, AVATAR_THU_MUC . '/') === 0) {
            $tepCu = rtrim($cfg['upload_dir'], '/\\') . '/avatars/' . basename($anhCu);
            if (is_file($tepCu)) { @unlink($tepCu); }
        }

        $_SESSION['flash_hoso'] = 'Đã cập nhật hồ sơ.';
        redirect(BASE_URL . '/client/hoso.php');
    }
}

$thanhCong = $_SESSION['flash_hoso'] ?? null;
unset($_SESSION['flash_hoso']);

// ---- C-14: Elo từng môn, số trận, thắng/thua/hòa, tỷ lệ thắng ----
$st = $pdo->prepare(
    'SELECT m.TenMon, e.DiemElo, e.SoTran, e.SoThang, e.SoThua, e.SoHoa
       FROM ELOMONHOC e
       JOIN MONHOC m ON m.MaMon = e.MaMon
      WHERE e.MaND = ?
      ORDER BY e.DiemElo DESC, m.TenMon'
);
$st->execute([$maND]);
$elo = $st->fetchAll();

$tieuDe = 'Hồ sơ cá nhân';
$cssTrang = 'hoso';
require __DIR__ . '/layout/header.php';
?>
<div class="container">
  <h2>Hồ sơ cá nhân</h2>

  <?php if ($thanhCong): ?><div class="thanhcong"><?= e($thanhCong) ?></div><?php endif; ?>
  <?php if ($loi): ?>
    <div class="loi"><?php foreach ($loi as $m): ?><div><?= e($m) ?></div><?php endforeach; ?></div>
  <?php endif; ?>

  <div class="hoso-luoi">
    <div class="card">
      <h3>Thông tin</h3>
      <?php if (!empty($nd['AnhDaiDien'])): ?>
        <img class="avatar" src="<?= e(BASE_URL . '/' . $nd['AnhDaiDien']) ?>" alt="Ảnh đại diện">
      <?php else: ?>
        <div class="avatar-mac-dinh"><?= e(mb_strtoupper(mb_substr($nd['HoTen'], 0, 1))) ?></div>
      <?php endif; ?>

      <form method="post" action="" enctype="multipart/form-data" novalidate>
        <?= csrf_field() ?>

        <label for="anh_dai_dien">Ảnh đại diện</label>
        <input type="file" id="anh_dai_dien" name="anh_dai_dien" accept="image/jpeg,image/png">
        <div class="ghichu">Chỉ nhận JPG hoặc PNG, tối đa 2 MB. Để trống nếu không đổi ảnh.</div>

        <label for="ho_ten">Họ tên</label>
        <input type="text" id="ho_ten" name="ho_ten" maxlength="100" value="<?= e($hoTen) ?>" required>

        <label for="email">Email</label>
        <input type="email" id="email" value="<?= e($nd['Email']) ?>" readonly>

        <label for="lop">Lớp</label>
        <input type="text" id="lop" name="lop" maxlength="50" value="<?= e($lop) ?>">

        <label for="khoa">Khoa</label>
        <input type="text" id="khoa" name="khoa" maxlength="100" value="<?= e($khoa) ?>">

        <p><button type="submit" class="btn">Lưu thay đổi</button>
           <a class="btn btn-phu" href="<?= e(BASE_URL) ?>/client/doimatkhau.php">Đổi mật khẩu</a></p>
      </form>
    </div>

    <div class="card">
      <h3>Elo theo môn</h3>
      <?php if (!$elo): ?>
        <p class="ghichu">Bạn chưa đấu trận nào. Elo sẽ xuất hiện sau trận đầu tiên.</p>
      <?php else: ?>
        <table>
          <tr><th>Môn</th><th>Elo</th><th>Trận</th><th>Thắng</th><th>Thua</th><th>Hòa</th><th>Tỷ lệ thắng</th></tr>
          <?php foreach ($elo as $r):
              $tyLe = ((int) $r['SoTran']) > 0 ? round($r['SoThang'] * 100 / $r['SoTran'], 1) : 0;
          ?>
            <tr>
              <td><?= e($r['TenMon']) ?></td>
              <td><?= (int) $r['DiemElo'] ?></td>
              <td><?= (int) $r['SoTran'] ?></td>
              <td><?= (int) $r['SoThang'] ?></td>
              <td><?= (int) $r['SoThua'] ?></td>
              <td><?= (int) $r['SoHoa'] ?></td>
              <td><?= e((string) $tyLe) ?>%</td>
            </tr>
          <?php endforeach; ?>
        </table>
      <?php endif; ?>
      <p class="ghichu">Elo chỉ thay đổi theo kết quả trận đấu, không chỉnh tay được.</p>
      <!-- TODO (C-15): biểu đồ biến động Elo theo môn, lấy dữ liệu từ LICHSUELO -->
    </div>
  </div>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>