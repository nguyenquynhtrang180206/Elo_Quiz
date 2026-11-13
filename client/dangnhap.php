<?php
// Đăng nhập sinh viên (C-03, C-04). Đặt tại: elo_quiz/client/dangnhap.php
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
        // Kiểm tra chống brute-force / dò mật khẩu
        $hanChe = RateLimiter::kiemTra('login_client', $email);
        if ($hanChe['biKhoa']) {
            $loi[] = 'Bạn đã nhập sai quá 5 lần liên tiếp. Để bảo mật tài khoản, vui lòng thử lại sau '
                . RateLimiter::dinhDangThoiGian($hanChe['thoiGianCon']) . '.';
        } else {
            $st = Database::pdo()->prepare(
                'SELECT MaND, MatKhau, TrangThai, TrangThaiKhaoSat FROM NGUOIDUNG WHERE Email = ?'
            );
            $st->execute([$email]);
            $nd = $st->fetch();

            $hopLe = false;
            if ($nd) {
                $hopLe = PasswordSecurity::verify($matKhau, $nd['MatKhau']);
            } else {
                // Chống Timing Attack: Chạy verify giả lập để thời gian phản hồi cố định
                PasswordSecurity::dummyVerify($matKhau);
            }

            if (!$hopLe) {
                $soLan = RateLimiter::ghiNhanThatBai('login_client', $email);
                $conLai = max(0, 5 - $soLan);
                if ($conLai > 0 && $conLai <= 2) {
                    $loi[] = 'Email hoặc mật khẩu không đúng. Cảnh báo: Bạn còn ' . $conLai . ' lần thử trước khi bị khóa tạm thời 15 phút.';
                } elseif ($conLai === 0) {
                    $loi[] = 'Bạn đã nhập sai 5 lần. Tài khoản tạm thời bị khóa đăng nhập trong 15 phút.';
                } else {
                    $loi[] = 'Email hoặc mật khẩu không đúng.';
                }
            } elseif ($nd['TrangThai'] === 'Bị khóa') {
                // C-03: tài khoản bị khóa không đăng nhập được
                $loi[] = 'Tài khoản của bạn đã bị khóa. Vui lòng liên hệ quản trị viên.';
            } else {
                // Đăng nhập thành công: xóa lịch sử thử sai
                RateLimiter::xoa('login_client', $email);

                // Tự động nâng cấp hash lên chuẩn mới (Argon2id) nếu cần
                PasswordSecurity::rehashIfNecessary(
                    Database::pdo(),
                    'NGUOIDUNG',
                    'MaND',
                    (int) $nd['MaND'],
                    $matKhau,
                    $nd['MatKhau']
                );

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
}

$tieuDe = 'Đăng nhập';
$cssTrang = 'dangnhap';
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
      <div class="pw-field-wrap">
        <input type="password" id="mat_khau" name="mat_khau" required placeholder="Nhập mật khẩu của bạn">
        <button type="button" class="btn-toggle-pw" aria-label="Hiện/ẩn mật khẩu" tabindex="-1">
          <svg class="icon-eye" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/><line class="eye-slash" x1="4" y1="4" x2="20" y2="20"/></svg>
        </button>
      </div>

      <p><button type="submit" class="btn">Đăng nhập</button></p>
    </form>
    <script src="<?= e(BASE_URL) ?>/assets/js/pw_helper.js"></script>

    <p>Chưa có tài khoản? <a href="<?= e(BASE_URL) ?>/client/dangky.php">Đăng ký</a></p>
    <p>Bạn là quản trị viên? <a href="<?= e(BASE_URL) ?>/admin/dangnhap.php">Đăng nhập quản trị</a></p>
    <!-- TODO (C-06): thêm liên kết "Quên mật khẩu" khi làm xong chức năng đặt lại mật khẩu -->
  </div>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>
