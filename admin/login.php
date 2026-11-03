<?php
// Đăng nhập quản trị (A-01, A-02, A-05). Đặt tại: elo_quiz/admin/login.php
require_once __DIR__ . '/../core/bootstrap.php';

// Đã đăng nhập (và tài khoản còn hoạt động) thì vào thẳng Dashboard
if (Auth::adminId() !== null) {
    redirect(BASE_URL . '/admin/index.php');
}

$loi         = [];
$tenDangNhap = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $tenDangNhap = trim((string) ($_POST['ten_dang_nhap'] ?? ''));
    $matKhau     = (string) ($_POST['mat_khau'] ?? '');

    if ($tenDangNhap === '' || $matKhau === '') {
        $loi[] = 'Vui lòng nhập tên đăng nhập và mật khẩu.';
    } else {
        // Chỉ tra trong bảng ADMIN: tài khoản sinh viên (bảng NGUOIDUNG) không đăng nhập được ở đây (A-05)
        $st = Database::pdo()->prepare(
            'SELECT MaAdmin, MatKhau, TrangThai FROM ADMIN WHERE TenDangNhap = ?'
        );
        $st->execute([$tenDangNhap]);
        $ad = $st->fetch();

        // Sai tên hay sai mật khẩu đều báo chung một câu
        if (!$ad || !password_verify($matKhau, $ad['MatKhau'])) {
            $loi[] = 'Tên đăng nhập hoặc mật khẩu không đúng.';
        } elseif ($ad['TrangThai'] === 'Bị khóa') {
            // A-01: tài khoản bị khóa không đăng nhập được
            $loi[] = 'Tài khoản quản trị này đã bị khóa.';
        } else {
            Auth::regenerate();                       // chống session fixation
            $_SESSION['admin_id'] = (int) $ad['MaAdmin'];
            redirect(BASE_URL . '/admin/index.php');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Đăng nhập quản trị - Đấu Trường Tri Thức</title>
  <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/style.css">
  <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/admin/login.css">
</head>
<body>
<div class="container khung-login">
  <div class="card">
    <h2>Đăng nhập quản trị</h2>

    <?php if ($loi): ?>
      <div class="loi">
        <?php foreach ($loi as $m): ?><div><?= e($m) ?></div><?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="post" action="" novalidate>
      <?= csrf_field() ?>

      <label for="ten_dang_nhap">Tên đăng nhập</label>
      <input type="text" id="ten_dang_nhap" name="ten_dang_nhap" maxlength="50" value="<?= e($tenDangNhap) ?>" required autofocus>

      <label for="mat_khau">Mật khẩu</label>
      <input type="password" id="mat_khau" name="mat_khau" required>

      <p><button type="submit" class="btn">Đăng nhập</button></p>
    </form>

    <p style="margin-top:16px;text-align:center;font-size:14px">
      Bạn là sinh viên? <a href="<?= e(BASE_URL) ?>/client/login.php">Đăng nhập tại đây</a>
    </p>
  </div>
</div>
</body>
</html>