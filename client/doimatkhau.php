<?php
// Đổi mật khẩu khi đã đăng nhập (C-05). Đặt tại: elo_quiz/client/doimatkhau.php
require_once __DIR__ . '/../core/bootstrap.php';

// Phải đăng nhập mới vào được (kiểm tra ở backend)
$maND = Auth::requireClient(BASE_URL . '/client/dangnhap.php');

// Admin dùng khu sinh viên thì đổi mật khẩu ở trang đổi mật khẩu của quản trị (tài khoản liên kết không có mật khẩu đăng nhập)
if (Auth::laAdminSinhVien()) {
    redirect(BASE_URL . '/admin/doimatkhau.php');
}

$pdo       = Database::pdo();
$loi       = [];
$thanhCong = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $hienTai = (string) ($_POST['mat_khau_cu'] ?? '');
    $moi     = (string) ($_POST['mat_khau_moi'] ?? '');
    $xacNhan = (string) ($_POST['xac_nhan'] ?? '');

    $st = $pdo->prepare('SELECT MatKhau, Email, HoTen FROM NGUOIDUNG WHERE MaND = ?');
    $st->execute([$maND]);
    $nd = $st->fetch();

    if (!$nd) {
        // Tài khoản không còn tồn tại: kết thúc phiên
        unset($_SESSION['nd_id']);
        redirect(BASE_URL . '/client/dangnhap.php');
    }
    $bam = $nd['MatKhau'];

    if ($hienTai === '' || $moi === '' || $xacNhan === '') {
        $loi[] = 'Vui lòng nhập đủ mật khẩu hiện tại, mật khẩu mới và xác nhận.';
    } else {
        if (!PasswordSecurity::verify($hienTai, $bam)) {
            $loi[] = 'Mật khẩu hiện tại không đúng.';
        }
        if ($moi === $hienTai) {
            $loi[] = 'Mật khẩu mới phải khác mật khẩu hiện tại.';
        } elseif ($moi !== $xacNhan) {
            $loi[] = 'Mật khẩu xác nhận không khớp.';
        } else {
            $loiManh = PasswordSecurity::kiemTraDoManh($moi, ['email' => $nd['Email'], 'hoTen' => $nd['HoTen']]);
            if ($loiManh) {
                $loi = array_merge($loi, $loiManh);
            }
        }
    }

    if (!$loi) {
        // Băm mật khẩu mới bằng chuẩn siêu bảo mật (Argon2id hoặc Bcrypt cost 12)
        $st = $pdo->prepare('UPDATE NGUOIDUNG SET MatKhau = ? WHERE MaND = ?');
        $st->execute([PasswordSecurity::hash($moi), $maND]);

        session_regenerate_id(true);
        $thanhCong = true;
    }
}

$tieuDe = 'Đổi mật khẩu';
$cssTrang = 'doimatkhau';
require __DIR__ . '/layout/header.php';
?>
<link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/pw_security.css">
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

    <form method="post" action="" novalidate data-xacnhan="Bạn có chắc muốn đổi mật khẩu không?" data-xacnhan2="Sau khi đổi, bạn phải dùng mật khẩu mới ở các lần đăng nhập sau. Xác nhận đổi mật khẩu?" data-xacnhan2-nut="Đổi mật khẩu">
      <?= csrf_field() ?>

      <label for="mat_khau_cu">Mật khẩu hiện tại</label>
      <div class="pw-field-wrap">
        <input type="password" id="mat_khau_cu" name="mat_khau_cu" required placeholder="Nhập mật khẩu hiện tại">
        <button type="button" class="btn-toggle-pw" aria-label="Hiện/ẩn mật khẩu" tabindex="-1">
          <svg class="icon-eye" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/><line class="eye-slash" x1="4" y1="4" x2="20" y2="20"/></svg>
        </button>
      </div>

      <label for="mat_khau_moi">Mật khẩu mới</label>
      <div class="pw-field-wrap">
        <input type="password" id="mat_khau_moi" name="mat_khau_moi" required placeholder="Tối thiểu 8 ký tự, gồm hoa, thường, số, ký tự đặc biệt" oncopy="return false;" oncut="return false;">
        <button type="button" class="btn-toggle-pw" aria-label="Hiện/ẩn mật khẩu" tabindex="-1">
          <svg class="icon-eye" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/><line class="eye-slash" x1="4" y1="4" x2="20" y2="20"/></svg>
        </button>
      </div>

      <div class="pw-strength-container" id="pwMeterBox" style="display:none;">
        <div class="pw-strength-bar"><div class="pw-strength-fill" id="pwFill"></div></div>
        <div class="pw-strength-label" id="pwLabel">Độ mạnh mật khẩu: <span>Rất yếu</span></div>
      </div>

      <label for="xac_nhan">Nhập lại mật khẩu mới</label>
      <div class="pw-field-wrap">
        <input type="password" id="xac_nhan" name="xac_nhan" class="input-no-paste" required placeholder="Xác nhận lại mật khẩu mới (vui lòng gõ tay)" onpaste="return false;" oncopy="return false;" oncut="return false;" ondrop="return false;" autocomplete="new-password">
        <button type="button" class="btn-toggle-pw" aria-label="Hiện/ẩn mật khẩu" tabindex="-1">
          <svg class="icon-eye" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/><line class="eye-slash" x1="4" y1="4" x2="20" y2="20"/></svg>
        </button>
      </div>

      <ul class="pw-requirements-list" id="pwChecklist">
        <li data-rule="len">Ít nhất 8 ký tự (tối đa 72)</li>
        <li data-rule="case">Bao gồm cả chữ in hoa (A-Z) và chữ thường (a-z)</li>
        <li data-rule="num_sym">Bao gồm chữ số (0-9) và ký tự đặc biệt (!@#$...)</li>
        <li data-rule="diff">Khác mật khẩu hiện tại</li>
        <li data-rule="match">Mật khẩu xác nhận khớp với mật khẩu mới</li>
      </ul>

      <p><button type="submit" class="btn">Đổi mật khẩu</button></p>
    </form>
    <script src="<?= e(BASE_URL) ?>/assets/js/pw_helper.js"></script>
  </div>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>