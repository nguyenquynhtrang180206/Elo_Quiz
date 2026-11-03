<?php
// Đăng ký sinh viên (C-01, C-02, C-04). Đặt tại: elo_quiz/client/register.php
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
    } elseif (strlen($matKhau) < 6) {
        $loi[] = 'Mật khẩu phải có ít nhất 6 ký tự.';
    } elseif ($matKhau !== $xacNhan) {
        $loi[] = 'Mật khẩu xác nhận không khớp.';
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
                // C-02: chỉ lưu mật khẩu đã băm
                $st = $pdo->prepare(
                    'INSERT INTO NGUOIDUNG (HoTen, Email, MatKhau) VALUES (?, ?, ?)'
                );
                $st->execute([$hoTen, $email, password_hash($matKhau, PASSWORD_DEFAULT)]);
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
$cssTrang = 'register';
require __DIR__ . '/layout/header.php';
?>
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

      <label for="mat_khau">Mật khẩu (ít nhất 6 ký tự)</label>
      <input type="password" id="mat_khau" name="mat_khau" required>

      <label for="xac_nhan">Nhập lại mật khẩu</label>
      <input type="password" id="xac_nhan" name="xac_nhan" required>

      <p><button type="submit" class="btn">Đăng ký</button></p>
    </form>

    <p>Đã có tài khoản? <a href="<?= e(BASE_URL) ?>/client/login.php">Đăng nhập</a></p>
  </div>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>