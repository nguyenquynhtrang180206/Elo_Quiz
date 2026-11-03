<?php
// Đăng nhập sinh viên (C-03, C-04). Đặt tại: elo_quiz/client/login.php
require_once __DIR__ . '/../core/bootstrap.php';

if (Auth::clientId() !== null) {
    redirect(BASE_URL . '/client/index.php');
}

$loi   = [];
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $email   = strtolower(trim((string) ($_POST['email'] ?? '')));
    $matKhau = (string) ($_POST['mat_khau'] ?? '');

    // C-04: báo lỗi khi thiếu dữ liệu
    if ($email === '' || $matKhau === '') {
        $loi[] = 'Vui lòng nhập email và mật khẩu.';
    } else {
        $st = Database::pdo()->prepare(
            'SELECT MaND, MatKhau, TrangThai, TrangThaiKhaoSat FROM NGUOIDUNG WHERE Email = ?'
        );
        $st->execute([$email]);
        $nd = $st->fetch();

        // Sai email hay sai mật khẩu đều báo chung một câu, để không lộ email nào đã đăng ký
        if (!$nd || !password_verify($matKhau, $nd['MatKhau'])) {
            $loi[] = 'Email hoặc mật khẩu không đúng.';
        } elseif ($nd['TrangThai'] === 'Bị khóa') {
            // C-03: tài khoản bị khóa không đăng nhập được
            $loi[] = 'Tài khoản của bạn đã bị khóa. Vui lòng liên hệ quản trị viên.';
        } else {
            Auth::regenerate();              // chống session fixation
            $_SESSION['nd_id'] = (int) $nd['MaND'];

            // C-45: chưa hoàn tất hoặc bỏ qua khảo sát thì hiện lại ở lần đăng nhập này
            if ($nd['TrangThaiKhaoSat'] === 'Chưa làm') {
                redirect(BASE_URL . '/client/khaosat.php');
            }
            redirect(BASE_URL . '/client/index.php');
        }
    }
}

$tieuDe = 'Đăng nhập';
$cssTrang = 'login';
require __DIR__ . '/layout/header.php';
?>
<div class="container khung-login">
  <div class="card">
    <h2>Đăng nhập</h2>

    <?php if ($loi): ?>
      <div class="loi">
        <?php foreach ($loi as $m): ?><div><?= e($m) ?></div><?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="post" action="" novalidate>
      <?= csrf_field() ?>

      <label for="email">Email</label>
      <input type="email" id="email" name="email" maxlength="150" value="<?= e($email) ?>" required>

      <label for="mat_khau">Mật khẩu</label>
      <input type="password" id="mat_khau" name="mat_khau" required>

      <p><button type="submit" class="btn">Đăng nhập</button></p>
    </form>

    <p>Chưa có tài khoản? <a href="<?= e(BASE_URL) ?>/client/register.php">Đăng ký</a></p>
    <p>Bạn là quản trị viên? <a href="<?= e(BASE_URL) ?>/admin/login.php">Đăng nhập quản trị</a></p>
    <!-- TODO (C-06): thêm liên kết "Quên mật khẩu" khi làm xong chức năng đặt lại mật khẩu -->
  </div>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>