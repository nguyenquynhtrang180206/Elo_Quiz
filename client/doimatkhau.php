<?php
// Đổi mật khẩu khi đã đăng nhập (C-05). Đặt tại: elo_quiz/client/doimatkhau.php
require_once __DIR__ . '/../core/bootstrap.php';

// Phải đăng nhập mới vào được (kiểm tra ở backend)
$maND = Auth::requireClient(BASE_URL . '/client/login.php');

$pdo       = Database::pdo();
$loi       = [];
$thanhCong = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $hienTai = (string) ($_POST['mat_khau_cu'] ?? '');
    $moi     = (string) ($_POST['mat_khau_moi'] ?? '');
    $xacNhan = (string) ($_POST['xac_nhan'] ?? '');

    $st = $pdo->prepare('SELECT MatKhau FROM NGUOIDUNG WHERE MaND = ?');
    $st->execute([$maND]);
    $bam = $st->fetchColumn();

    if ($bam === false) {
        // Tài khoản không còn tồn tại: kết thúc phiên
        unset($_SESSION['nd_id']);
        redirect(BASE_URL . '/client/login.php');
    }

    if ($hienTai === '' || $moi === '' || $xacNhan === '') {
        $loi[] = 'Vui lòng nhập đủ mật khẩu hiện tại, mật khẩu mới và xác nhận.';
    } else {
        if (!password_verify($hienTai, $bam)) {
            $loi[] = 'Mật khẩu hiện tại không đúng.';
        }
        if (strlen($moi) < 6) {
            $loi[] = 'Mật khẩu mới phải có ít nhất 6 ký tự.';
        } elseif ($moi !== $xacNhan) {
            $loi[] = 'Mật khẩu xác nhận không khớp.';
        } elseif ($moi === $hienTai) {
            $loi[] = 'Mật khẩu mới phải khác mật khẩu hiện tại.';
        }
    }

    if (!$loi) {
        // Băm mật khẩu mới rồi ghi đè lên mật khẩu cũ của chính tài khoản này
        $st = $pdo->prepare('UPDATE NGUOIDUNG SET MatKhau = ? WHERE MaND = ?');
        $st->execute([password_hash($moi, PASSWORD_DEFAULT), $maND]);

        session_regenerate_id(true);
        $thanhCong = true;
    }
}

$tieuDe = 'Đổi mật khẩu';
$cssTrang = 'doimatkhau';
require __DIR__ . '/layout/header.php';
?>
<div class="container khung-doimatkhau">
  <div class="card">
    <h2>Đổi mật khẩu</h2>

    <?php if ($thanhCong): ?>
      <div class="thanhcong">Đổi mật khẩu thành công.</div>
    <?php endif; ?>

    <?php if ($loi): ?>
      <div class="loi">
        <?php foreach ($loi as $m): ?><div><?= e($m) ?></div><?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="post" action="" novalidate>
      <?= csrf_field() ?>

      <label for="mat_khau_cu">Mật khẩu hiện tại</label>
      <input type="password" id="mat_khau_cu" name="mat_khau_cu" required>

      <label for="mat_khau_moi">Mật khẩu mới (ít nhất 6 ký tự)</label>
      <input type="password" id="mat_khau_moi" name="mat_khau_moi" required>

      <label for="xac_nhan">Nhập lại mật khẩu mới</label>
      <input type="password" id="xac_nhan" name="xac_nhan" required>

      <p><button type="submit" class="btn">Đổi mật khẩu</button></p>
    </form>
  </div>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>