<?php
// Đổi mật khẩu quản trị khi đã đăng nhập (A-03; mật khẩu lưu dạng băm A-02). Đặt tại: elo_quiz/admin/doimatkhau.php
require_once __DIR__ . '/../core/bootstrap.php';
$maAdmin = Auth::requireAdmin(BASE_URL . '/admin/login.php');   // A-04

$pdo       = Database::pdo();
$loi       = [];
$thanhCong = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $hienTai = (string) ($_POST['mat_khau_cu'] ?? '');
    $moi     = (string) ($_POST['mat_khau_moi'] ?? '');
    $xacNhan = (string) ($_POST['xac_nhan'] ?? '');

    $st = $pdo->prepare('SELECT MatKhau FROM ADMIN WHERE MaAdmin = ?');
    $st->execute([$maAdmin]);
    $bam = $st->fetchColumn();

    if ($bam === false) {
        // Tài khoản không còn tồn tại: kết thúc phiên
        unset($_SESSION['admin_id']);
        redirect(BASE_URL . '/admin/login.php');
    }

    if ($hienTai === '' || $moi === '' || $xacNhan === '') {
        $loi[] = 'Vui lòng nhập đủ mật khẩu hiện tại, mật khẩu mới và xác nhận.';
    } else {
        if (!password_verify($hienTai, $bam)) {
            $loi[] = 'Mật khẩu hiện tại không đúng.';
        }
        if (strlen($moi) < 6) {
            $loi[] = 'Mật khẩu mới phải có ít nhất 6 ký tự.';
        } elseif (strlen($moi) > 72) {
            // bcrypt (PASSWORD_DEFAULT) chỉ dùng 72 byte đầu, nên chặn để mật khẩu nhập vào đúng bằng mật khẩu được băm
            $loi[] = 'Mật khẩu mới tối đa 72 ký tự.';
        } elseif ($moi !== $xacNhan) {
            $loi[] = 'Mật khẩu xác nhận không khớp.';
        } elseif ($moi === $hienTai) {
            $loi[] = 'Mật khẩu mới phải khác mật khẩu hiện tại.';
        }
    }

    if (!$loi) {
        // Băm mật khẩu mới rồi ghi đè lên mật khẩu cũ của chính tài khoản này.
        // Điều kiện MatKhau = giá trị vừa kiểm tra: nếu phiên khác vừa đổi mật khẩu thì không ghi đè nhầm.
        $st = $pdo->prepare('UPDATE ADMIN SET MatKhau = ? WHERE MaAdmin = ? AND MatKhau = ?');
        $st->execute([password_hash($moi, PASSWORD_DEFAULT), $maAdmin, $bam]);
        if ($st->rowCount() === 1) {
            Auth::regenerate();
            $thanhCong = true;
        } else {
            $loi[] = 'Mật khẩu vừa được thay đổi ở nơi khác. Hãy thử lại.';
        }
    }
}

$tieuDe  = 'Đổi mật khẩu';
$mucChon = 'doimatkhau';
$cssTrang = 'doimatkhau';
require __DIR__ . '/layout/header.php';
?>
<h2>Đổi mật khẩu</h2>

<div class="card khung-doimatkhau">
  <?php if ($thanhCong): ?>
    <div class="thanhcong">Đổi mật khẩu thành công.</div>
  <?php endif; ?>

  <?php if ($loi): ?>
    <div class="loi">
      <?php foreach ($loi as $m): ?><div><?= e($m) ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <form method="post" action="" novalidate autocomplete="off">
    <?= csrf_field() ?>

    <label for="mat_khau_cu">Mật khẩu hiện tại</label>
    <input type="password" id="mat_khau_cu" name="mat_khau_cu" required>

    <label for="mat_khau_moi">Mật khẩu mới (từ 6 đến 72 ký tự)</label>
    <input type="password" id="mat_khau_moi" name="mat_khau_moi" required>

    <label for="xac_nhan">Nhập lại mật khẩu mới</label>
    <input type="password" id="xac_nhan" name="xac_nhan" required>

    <p><button type="submit" class="btn">Đổi mật khẩu</button></p>
  </form>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>