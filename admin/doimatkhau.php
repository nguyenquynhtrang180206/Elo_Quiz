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

$tieuDe      = 'Đổi mật khẩu';
$mucChon     = 'doimatkhau';
// Không bật $giaoDienMoi: giữ nền tối + sidebar như các trang khác, chỉ khối nội dung bên trong là nền trắng
$cssTrang    = 'doimatkhau';
$jsTrang     = ['doimatkhau'];       // nút hiện/ẩn mật khẩu + tick gợi ý theo thời gian thực (chỉ là giao diện, máy chủ vẫn kiểm tra lại)
require __DIR__ . '/layout/header.php';
?>
<div class="card dmk">
  <!-- Cột trái: ảnh ổ khóa + khẩu hiệu -->
  <aside class="dmk-trai">
    <img class="dmk-anh" src="<?= e(BASE_URL) ?>/assets/images/lock.jpg" alt="Bảo mật tài khoản">
    <p class="dmk-cau">“Bảo mật tài khoản<br>– Vững bước chinh phục!”</p>
    <span class="dmk-gach" aria-hidden="true"></span>
  </aside>

  <!-- Cột phải: tiêu đề + form -->
  <div class="dmk-phai">
    <div class="dmk-dau">
      <span class="dmk-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="30" height="30"><rect x="4" y="10" width="16" height="11" rx="2.5" fill="currentColor"/><path d="M8 10V7.5a4 4 0 0 1 8 0V10" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/><circle cx="12" cy="15" r="1.5" fill="#fff"/></svg>
      </span>
      <div>
        <h2>Đổi mật khẩu</h2>
        <p class="nhan-phu">Vui lòng nhập mật khẩu hiện tại và mật khẩu mới để bảo mật tài khoản của bạn.</p>
      </div>
    </div>

    <?php if ($thanhCong): ?>
      <div class="thanhcong">Đổi mật khẩu thành công.</div>
    <?php endif; ?>

    <?php if ($loi): ?>
      <div class="loi">
        <?php foreach ($loi as $m): ?><div><?= e($m) ?></div><?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form class="dmk-form" method="post" action="" novalidate autocomplete="off" data-xacnhan="Bạn có chắc muốn đổi mật khẩu không?" data-xacnhan2="Sau khi đổi, bạn phải dùng mật khẩu mới ở các lần đăng nhập sau. Xác nhận đổi mật khẩu?" data-xacnhan2-nut="Đổi mật khẩu">
      <?= csrf_field() ?>

      <?php
      // Ba ô nhập (name/id giữ nguyên như code gốc): [id, nhãn, gợi ý trong ô]
      $oNhap = [
          ['mat_khau_cu',   'Mật khẩu hiện tại',            'Mật khẩu hiện tại'],
          ['mat_khau_moi',  'Mật khẩu mới (từ 6 đến 72 ký tự)', 'Mật khẩu mới'],
          ['xac_nhan',      'Nhập lại mật khẩu mới',        'Xác nhận mật khẩu mới'],
      ];
      foreach ($oNhap as [$id, $nhan, $goiY]): ?>
        <label class="dmk-an" for="<?= e($id) ?>"><?= e($nhan) ?></label>
        <div class="dmk-o">
          <svg class="dmk-khoa" viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><rect x="4" y="10" width="16" height="11" rx="2.5" fill="currentColor"/><path d="M8 10V7.5a4 4 0 0 1 8 0V10" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/></svg>
          <input type="password" id="<?= e($id) ?>" name="<?= e($id) ?>" placeholder="<?= e($goiY) ?>" required>
          <button type="button" class="dmk-mat" aria-label="Hiện mật khẩu" aria-pressed="false">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/><line class="gach" x1="4" y1="4" x2="20" y2="20"/></svg>
          </button>
        </div>
      <?php endforeach; ?>

      <ul class="dmk-goiy" id="dmkGoiY">
        <li data-quy="dai">Mật khẩu mới có từ 6 đến 72 ký tự</li>
        <li data-quy="khac">Mật khẩu mới khác mật khẩu hiện tại</li>
        <li data-quy="khop">Mật khẩu xác nhận trùng với mật khẩu mới</li>
      </ul>

      <button type="submit" class="btn dmk-nut">
        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 0 1-15.5 6.2L3 16"/><path d="M3 21v-5h5"/><path d="M3 12a9 9 0 0 1 15.5-6.2L21 8"/><path d="M21 3v5h-5"/></svg>
        Đổi mật khẩu
      </button>
    </form>
  </div>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>