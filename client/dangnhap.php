<?php
// Cổng đăng nhập chung cho Sinh viên và Quản trị viên (tự động phân quyền)
require_once __DIR__ . '/../core/bootstrap.php';

// Ưu tiên xử lý nếu đang chờ OTP 2FA của Admin
$dangCho2FA = !empty($_SESSION['admin_2fa_pending'])
    && time() <= (int) ($_SESSION['admin_2fa_pending']['expire'] ?? 0);

if (!$dangCho2FA) {
    // Đã đăng nhập thì điều hướng theo đúng phân quyền
    if (Auth::adminId() !== null && !Auth::laAdminSinhVien()) {
        redirect(BASE_URL . '/admin/index.php');
    }
    if (Auth::clientId() !== null) {
        redirect(BASE_URL . '/client/index.php');
    }
}

// Hủy bước 2FA để quay lại đăng nhập tài khoản khác
if (isset($_GET['huy_2fa'])) {
    unset($_SESSION['admin_2fa_pending']);
    redirect(BASE_URL . '/client/dangnhap.php');
}

$loi          = [];
$daGuiLaiOtp  = false;
$dinhDanhNhap = trim((string) ($_GET['email'] ?? $_GET['ten_dang_nhap'] ?? ''));
$ghiNho       = true;

if (!$dangCho2FA && !empty($_SESSION['admin_2fa_pending'])) {
    unset($_SESSION['admin_2fa_pending']);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $loi[] = 'Mã xác thực OTP đã hết hạn. Vui lòng đăng nhập lại.';
    }
}

if (($_GET['khoa'] ?? '') === '1' || !empty($_SESSION['_client_bi_khoa'])) {
    unset($_SESSION['_client_bi_khoa']);
    $loi[] = 'Tài khoản của bạn đã bị khóa nên phiên làm việc đã kết thúc. Vui lòng liên hệ quản trị viên để được hỗ trợ.';
}

// Gửi lại mã OTP 2FA cho Admin
if (isset($_GET['gui_lai_otp']) && $dangCho2FA) {
    $kqGui = Auth::guiLaiOtpAdmin2FA();
    if ($kqGui['ok']) {
        $daGuiLaiOtp = true;
    } else {
        $loi[] = $kqGui['loi'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = trim((string) ($_POST['action'] ?? 'dang_nhap'));

    // Xác thực OTP 2FA cho tài khoản Quản trị viên
    if ($action === 'xac_thuc_2fa') {
        $otpNhap       = trim((string) ($_POST['otp'] ?? ''));
        $tinCayThietBi = !empty($_POST['tin_cay_thiet_bi']);
        $kq            = Auth::xacThucOtpAdmin2FA($otpNhap, $tinCayThietBi);

        if ($kq['ok']) {
            redirect(BASE_URL . '/admin/index.php');
        }
        $loi[]      = $kq['loi'];
        $dangCho2FA = empty($kq['hetHan']);
    } else {
        // Đăng nhập chung và tự động phân quyền
        $dinhDanhNhap = trim((string) ($_POST['email'] ?? ''));
        $matKhau      = (string) ($_POST['mat_khau'] ?? '');
        $ghiNho       = !empty($_POST['ghi_nho']);

        if ($dinhDanhNhap === '' || $matKhau === '') {
            $loi[] = 'Vui lòng nhập email (hoặc tên đăng nhập) và mật khẩu.';
        } else {
            $pdo = Database::pdo();
            $stC = $pdo->prepare('SELECT 1 FROM NGUOIDUNG WHERE Email = ? LIMIT 1');
            $stC->execute([strtolower($dinhDanhNhap)]);
            $laTaiKhoanClient = (bool) $stC->fetchColumn();
            $laTaiKhoanAdmin  = Auth::laDinhDanhAdmin($dinhDanhNhap);

            // Ưu tiên kiểm tra tài khoản Admin trước
            if ($laTaiKhoanAdmin) {
                $kqAdmin = Auth::dangNhap('admin', $dinhDanhNhap, $matKhau, ['yeuCau2FA' => true]);
                if ($kqAdmin['ok']) {
                    redirect(BASE_URL . '/admin/index.php');
                }
                if (!empty($kqAdmin['can2FA'])) {
                    $dangCho2FA = true;
                } elseif (!$laTaiKhoanClient) {
                    $loi[] = $kqAdmin['loi'];
                }
            }

            // Kiểm tra tài khoản Sinh viên nếu chưa chuyển sang 2FA Admin
            if (!$dangCho2FA && empty($loi)) {
                $kqClient = Auth::dangNhap('client', strtolower($dinhDanhNhap), $matKhau, ['remember' => $ghiNho]);
                if ($kqClient['ok']) {
                    redirect(BASE_URL . '/client/index.php');
                }
                $loi[] = $kqClient['loi'];
            }
        }
    }
}

$flow2FA  = $_SESSION['admin_2fa_pending'] ?? [];
$tieuDe   = $dangCho2FA ? 'Xác thực bảo mật 2 lớp' : 'Đăng nhập';
$cssTrang = 'dangnhap';
require __DIR__ . '/layout/header.php';
?>
<div class="container khung-login">
  <div class="card">
    <h2><?= $dangCho2FA ? 'Xác thực Quản trị (2FA)' : 'Đăng nhập' ?></h2>

    <?php if ($loi): ?>
      <div class="loi">
        <?php foreach ($loi as $m): ?><div><?= e($m) ?></div><?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($dangCho2FA): ?>
      <?php
        $now2FA          = time();
        $otpConLai2FA    = max(0, (int) ($flow2FA['expire'] ?? 0) - $now2FA);
        $guiLaiConLai2FA = max(0, 60 - ($now2FA - (int) ($flow2FA['last_sent'] ?? 0)));
      ?>
      <!-- Xác thực OTP 2FA cho Quản trị viên -->
      <div class="thongbao-2fa-box">
        <span class="icon-2fa">🛡️</span>
        <div>
          <div>
            <?= $daGuiLaiOtp ? 'Đã gửi lại mã OTP mới' : 'Tài khoản Quản trị viên: Mã OTP 6 số đã được gửi' ?> tới
            <strong><?= e(Auth::cheEmail((string) ($flow2FA['email'] ?? ''))) ?></strong>
            (kiểm tra cả mục <span class="spam-tag">Spam</span>).
          </div>
          <div class="otp-countdown-line" style="margin-top: 4px; font-size: 12.5px; color: #cbd5e1;">
            ⏳ Mã OTP có hiệu lực trong <strong id="otpExpireTimer" data-seconds="<?= $otpConLai2FA ?>" style="color: #FDE047;"><?= sprintf('%02d:%02d', intdiv($otpConLai2FA, 60), $otpConLai2FA % 60) ?></strong>
          </div>
        </div>
      </div>

      <form method="post" action="" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="xac_thuc_2fa">

        <div class="hang-mat-khau-header" style="margin-bottom: 6px;">
          <label for="otp" style="margin-bottom: 0;">Mã xác thực OTP (6 chữ số từ Email)</label>
          <span class="otp-badge-expire" id="otpExpireBadge" style="font-size: 12px; color: #38bdf8;">Còn hiệu lực: <b><?= sprintf('%02d:%02d', intdiv($otpConLai2FA, 60), $otpConLai2FA % 60) ?></b></span>
        </div>
        <input type="text" id="otp" name="otp" maxlength="6" inputmode="numeric" pattern="[0-9]{6}"
               placeholder="Nhập 6 số OTP" required autofocus autocomplete="one-time-code"
               style="letter-spacing: 6px; font-weight: 800; text-align: center; font-size: 19px;">

        <div class="hang-mat-khau-header" style="margin-top: 4px; margin-bottom: 12px;">
          <span style="font-size: 12.5px; color: #94a3b8;">Chưa nhận được mã?</span>
          <a href="<?= e(BASE_URL) ?>/client/dangnhap.php?gui_lai_otp=1"
             class="link-quen-mk<?= $guiLaiConLai2FA > 0 ? ' is-disabled' : '' ?>"
             id="btnGuiLaiOtp"
             data-cooldown="<?= $guiLaiConLai2FA ?>">
            <?= $guiLaiConLai2FA > 0 ? "Gửi lại mã sau ({$guiLaiConLai2FA}s)" : 'Gửi lại mã OTP' ?>
          </a>
        </div>

        <label class="hang-ghi-nho" for="tin_cay_thiet_bi">
          <input type="checkbox" id="tin_cay_thiet_bi" name="tin_cay_thiet_bi" value="1" checked>
          <span>Tin cậy trình duyệt này trong 7 ngày (không hỏi lại OTP)</span>
        </label>

        <p><button type="submit" class="btn">Xác nhận &amp; Vào trang Quản trị</button></p>
        <p style="text-align: center; margin-top: 12px;">
          <a href="<?= e(BASE_URL) ?>/client/dangnhap.php?huy_2fa=1" class="link-quen-mk">← Quay lại đăng nhập tài khoản khác</a>
        </p>
      </form>
      <script src="<?= e(BASE_URL) ?>/assets/js/pw_helper.js"></script>

    <?php else: ?>
      <!-- Form đăng nhập chung -->
      <form method="post" action="" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="dang_nhap">

        <label for="email">Email hoặc Tên đăng nhập</label>
        <input type="text" id="email" name="email" maxlength="150" value="<?= e($dinhDanhNhap) ?>"
               placeholder="Nhập email hoặc tên đăng nhập của bạn" required autofocus>

        <div class="hang-mat-khau-header">
          <label for="mat_khau">Mật khẩu</label>
          <a href="<?= e(BASE_URL) ?>/client/quenmatkhau.php" class="link-quen-mk" tabindex="3">Quên mật khẩu?</a>
        </div>
        <div class="pw-field-wrap">
          <input type="password" id="mat_khau" name="mat_khau" required placeholder="Nhập mật khẩu của bạn">
          <button type="button" class="btn-toggle-pw" aria-label="Hiện/ẩn mật khẩu" tabindex="-1">
            <svg class="icon-eye" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/><line class="eye-slash" x1="4" y1="4" x2="20" y2="20"/></svg>
          </button>
        </div>

        <label class="hang-ghi-nho" for="ghi_nho">
          <input type="checkbox" id="ghi_nho" name="ghi_nho" value="1"<?= $ghiNho ? ' checked' : '' ?>>
          <span>Ghi nhớ đăng nhập trên thiết bị này</span>
        </label>

        <p><button type="submit" class="btn">Đăng nhập</button></p>
      </form>
      <script src="<?= e(BASE_URL) ?>/assets/js/pw_helper.js"></script>

      <p>Chưa có tài khoản? <a href="<?= e(BASE_URL) ?>/client/dangky.php">Đăng ký ngay</a></p>
      <p>Quên mật khẩu? <a href="<?= e(BASE_URL) ?>/client/quenmatkhau.php">Khôi phục mật khẩu</a></p>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>
