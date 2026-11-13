<?php
// Đăng ký sinh viên (C-01, C-02, C-04). Đặt tại: elo_quiz/client/dangky.php
require_once __DIR__ . '/../core/bootstrap.php';

// Đã đăng nhập thì không cần đăng ký nữa
if (Auth::clientId() !== null) {
    redirect(BASE_URL . '/client/index.php');
}

$loi    = [];
$hoTen  = '';
$email  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $hoTen   = trim((string) ($_POST['ho_ten'] ?? ''));
    $email   = strtolower(trim((string) ($_POST['email'] ?? '')));
    $matKhau = (string) ($_POST['mat_khau'] ?? '');
    $xacNhan = (string) ($_POST['xac_nhan'] ?? '');

    // C-04: báo lỗi dễ hiểu khi thiếu hoặc sai dữ liệu
    if ($hoTen === '') {
        $loi[] = 'Vui lòng nhập họ tên.';
    } elseif (mb_strlen($hoTen) > 100) {
        $loi[] = 'Họ tên tối đa 100 ký tự.';
    }

    if ($email === '') {
        $loi[] = 'Vui lòng nhập email.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
        $loi[] = 'Email không hợp lệ.';
    }

    if ($matKhau === '') {
        $loi[] = 'Vui lòng nhập mật khẩu.';
    } elseif ($matKhau !== $xacNhan) {
        $loi[] = 'Mật khẩu xác nhận không khớp.';
    } else {
        $loiMatKhau = PasswordSecurity::kiemTraDoManh($matKhau, ['email' => $email, 'hoTen' => $hoTen]);
        if ($loiMatKhau) {
            $loi = array_merge($loi, $loiMatKhau);
        }
    }

    if (!$loi) {
        $pdo = Database::pdo();

        // C-01: email không được trùng (kiểm tra trước để báo lỗi thân thiện)
        $st = $pdo->prepare('SELECT 1 FROM NGUOIDUNG WHERE Email = ?');
        $st->execute([$email]);
        if ($st->fetchColumn()) {
            $loi[] = 'Email này đã được đăng ký. Hãy đăng nhập hoặc dùng email khác.';
        } else {
            try {
                // C-02: lưu mật khẩu bằng thuật toán siêu bảo mật (Argon2id hoặc Bcrypt cost 12)
                $st = $pdo->prepare(
                    'INSERT INTO NGUOIDUNG (HoTen, Email, MatKhau) VALUES (?, ?, ?)'
                );
                $st->execute([$hoTen, $email, PasswordSecurity::hash($matKhau)]);
                $maND = (int) $pdo->lastInsertId();

                // Đăng nhập luôn sau khi đăng ký thành công
                Auth::regenerate();
                $_SESSION['nd_id'] = $maND;

                // C-45: vừa đăng ký xong thì hiện khảo sát định hướng
                redirect(BASE_URL . '/client/khaosat.php');
            } catch (PDOException $ex) {
                // 23000 = vi phạm UNIQUE: hai người đăng ký cùng email gần như cùng lúc
                if ($ex->getCode() === '23000') {
                    $loi[] = 'Email này đã được đăng ký. Hãy đăng nhập hoặc dùng email khác.';
                } else {
                    throw $ex;
                }
            }
        }
    }
}

$tieuDe = 'Đăng ký';
$cssTrang = 'dangky';
require __DIR__ . '/layout/header.php';
?>
<link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/pw_security.css">
<div class="container khung-register">
  <div class="card">
    <h2>Đăng ký tài khoản</h2>

    <?php if ($loi): ?>
      <div class="loi">
        <?php foreach ($loi as $m): ?><div><?= e($m) ?></div><?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="post" action="" novalidate>
      <?= csrf_field() ?>

      <label for="ho_ten">Họ tên</label>
      <input type="text" id="ho_ten" name="ho_ten" maxlength="100" value="<?= e($hoTen) ?>" required>

      <label for="email">Email</label>
      <input type="email" id="email" name="email" maxlength="150" value="<?= e($email) ?>" required>

      <label for="mat_khau">Mật khẩu</label>
      <div class="pw-field-wrap">
        <input type="password" id="mat_khau" name="mat_khau" required placeholder="Tối thiểu 8 ký tự, gồm hoa, thường, số, ký tự đặc biệt" oncopy="return false;" oncut="return false;">
        <button type="button" class="btn-toggle-pw" aria-label="Hiện/ẩn mật khẩu" tabindex="-1">
          <svg class="icon-eye" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/><line class="eye-slash" x1="4" y1="4" x2="20" y2="20"/></svg>
        </button>
      </div>

      <div class="pw-strength-container" id="pwMeterBox" style="display:none;">
        <div class="pw-strength-bar"><div class="pw-strength-fill" id="pwFill"></div></div>
        <div class="pw-strength-label" id="pwLabel">Độ mạnh mật khẩu: <span>Rất yếu</span></div>
      </div>

      <label for="xac_nhan">Nhập lại mật khẩu</label>
      <div class="pw-field-wrap">
        <input type="password" id="xac_nhan" name="xac_nhan" class="input-no-paste" required placeholder="Xác nhận lại mật khẩu (vui lòng gõ tay)" onpaste="return false;" oncopy="return false;" oncut="return false;" ondrop="return false;" autocomplete="new-password">
        <button type="button" class="btn-toggle-pw" aria-label="Hiện/ẩn mật khẩu" tabindex="-1">
          <svg class="icon-eye" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/><line class="eye-slash" x1="4" y1="4" x2="20" y2="20"/></svg>
        </button>
      </div>

      <ul class="pw-requirements-list" id="pwChecklist">
        <li data-rule="len">Ít nhất 8 ký tự (tối đa 72)</li>
        <li data-rule="case">Bao gồm cả chữ in hoa (A-Z) và chữ thường (a-z)</li>
        <li data-rule="num_sym">Bao gồm chữ số (0-9) và ký tự đặc biệt (!@#$...)</li>
        <li data-rule="match">Mật khẩu xác nhận khớp với mật khẩu mới</li>
      </ul>

      <p><button type="submit" class="btn">Đăng ký tài khoản</button></p>
    </form>
    <script src="<?= e(BASE_URL) ?>/assets/js/pw_helper.js"></script>

    <p>Đã có tài khoản? <a href="<?= e(BASE_URL) ?>/client/dangnhap.php">Đăng nhập</a></p>
  </div>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>
