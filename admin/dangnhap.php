<?php
// Đăng nhập quản trị (A-01, A-02, A-05). Đặt tại: elo_quiz/admin/dangnhap.php
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
        // Kiểm tra brute-force rate limit
        $hanChe = RateLimiter::kiemTra('login_admin', $tenDangNhap);
        if ($hanChe['biKhoa']) {
            $loi[] = 'Bạn đã nhập sai quá 5 lần liên tiếp. Để bảo mật tài khoản, vui lòng thử lại sau '
                . RateLimiter::dinhDangThoiGian($hanChe['thoiGianCon']) . '.';
        } else {
            // Chỉ tra trong bảng ADMIN: tài khoản sinh viên (bảng NGUOIDUNG) không đăng nhập được ở đây (A-05)
            $st = Database::pdo()->prepare(
                'SELECT MaAdmin, MatKhau, TrangThai FROM ADMIN WHERE TenDangNhap = ?'
            );
            $st->execute([$tenDangNhap]);
            $ad = $st->fetch();

            $hopLe = false;
            if ($ad) {
                $hopLe = PasswordSecurity::verify($matKhau, $ad['MatKhau']);
            } else {
                // Chống Timing Attack: Chạy verify giả lập để thời gian phản hồi cố định
                PasswordSecurity::dummyVerify($matKhau);
            }

            if (!$hopLe) {
                $soLan = RateLimiter::ghiNhanThatBai('login_admin', $tenDangNhap);
                $conLai = max(0, 5 - $soLan);
                if ($conLai > 0 && $conLai <= 2) {
                    $loi[] = 'Tên đăng nhập hoặc mật khẩu không đúng. Cảnh báo: Bạn còn ' . $conLai . ' lần thử trước khi bị khóa tạm thời 15 phút.';
                } elseif ($conLai === 0) {
                    $loi[] = 'Bạn đã nhập sai 5 lần. Tài khoản quản trị tạm thời bị khóa đăng nhập trong 15 phút.';
                } else {
                    $loi[] = 'Tên đăng nhập hoặc mật khẩu không đúng.';
                }
            } elseif ($ad['TrangThai'] === 'Bị khóa') {
                // A-01: tài khoản bị khóa không đăng nhập được
                $loi[] = 'Tài khoản quản trị này đã bị khóa.';
            } else {
                // Đăng nhập thành công: xóa lịch sử thử sai
                RateLimiter::xoa('login_admin', $tenDangNhap);

                // Tự động nâng cấp hash lên chuẩn mới (Argon2id) nếu cần
                PasswordSecurity::rehashIfNecessary(
                    Database::pdo(),
                    'ADMIN',
                    'MaAdmin',
                    (int) $ad['MaAdmin'],
                    $matKhau,
                    $ad['MatKhau']
                );

                Auth::regenerate();                       // chống session fixation
                $_SESSION['admin_id'] = (int) $ad['MaAdmin'];
                redirect(BASE_URL . '/admin/index.php');
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Đăng nhập quản trị - QUIZUP ARENA</title>
  <link rel="icon" type="image/svg+xml" href="<?= e(BASE_URL) ?>/assets/img/quizup-emblem.svg">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400;1,600&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/style.css?v=<?= (int) @filemtime(__DIR__ . '/../assets/style.css') ?>">
  <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/style_admin.css?v=<?= (int) @filemtime(__DIR__ . '/../assets/style_admin.css') ?>">
  <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/pw_security.css?v=<?= (int) @filemtime(__DIR__ . '/../assets/css/pw_security.css') ?>">
  <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/admin/dangnhap.css?v=<?= (int) @filemtime(__DIR__ . '/../assets/css/admin/dangnhap.css') ?>">
  <script type="module" src="<?= e(BASE_URL) ?>/assets/js/effects/cosmic-bg.js"></script>
</head>
<body class="admin-login-body">
<div class="container khung-login">
  <div class="card">
    <a class="admin-login-brand" href="<?= e(BASE_URL) ?>/client/index.php" aria-label="QUIZUP ARENA">
      <img src="<?= e(BASE_URL) ?>/assets/img/quizup-emblem.svg" alt="" width="52" height="52">
      <span class="admin-login-wordmark">
        <strong>QUIZUP</strong>
        <small>ARENA</small>
      </span>
    </a>
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
      <div class="pw-field-wrap">
        <input type="password" id="mat_khau" name="mat_khau" required placeholder="Nhập mật khẩu quản trị">
        <button type="button" class="btn-toggle-pw" aria-label="Hiện/ẩn mật khẩu" tabindex="-1">
          <svg class="icon-eye" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/><line class="eye-slash" x1="4" y1="4" x2="20" y2="20"/></svg>
        </button>
      </div>

      <p><button type="submit" class="btn">Đăng nhập</button></p>
    </form>
    <script src="<?= e(BASE_URL) ?>/assets/js/pw_helper.js"></script>

    <p style="margin-top:16px;text-align:center;font-size:14px">
      Bạn là sinh viên? <a href="<?= e(BASE_URL) ?>/client/dangnhap.php">Đăng nhập tại đây</a>
    </p>
  </div>
</div>
</body>
</html>
