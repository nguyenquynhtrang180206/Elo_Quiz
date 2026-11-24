<?php
// =========================================================================
// CỔNG ĐĂNG NHẬP HỢP NHẤT (UNIFIED SINGLE SIGN-ON) & TỰ ĐỘNG PHÂN QUYỀN
// Đặt tại: elo_quiz/client/dangnhap.php
//
// Nguyên tắc hoạt động chuẩn thị trường:
// - Dùng chung 1 giao diện đăng nhập duy nhất cho toàn hệ thống (Sinh viên & Quản trị viên).
// - Hệ thống tự động nhận diện tài khoản nhập vào thuộc phân quyền nào:
//   + Nếu là Tài khoản Quản trị viên (nhập Tên đăng nhập hoặc Email Admin):
//     -> Kiểm tra thiết bị tin cậy / Gửi mã OTP 2FA ngay trên giao diện này -> Chuyển thẳng vào /admin/index.php
//   + Nếu là Tài khoản Sinh viên (nhập Email sinh viên):
//     -> Hỗ trợ Ghi nhớ đăng nhập 30 ngày -> Chuyển thẳng vào /client/index.php (hoặc Khảo sát)
// =========================================================================
require_once __DIR__ . '/../core/bootstrap.php';

// Nếu đang ở bước chờ nhập OTP 2FA của Admin thì ưu tiên xử lý 2FA
$dangCho2FA = !empty($_SESSION['admin_2fa_pending'])
    && time() <= (int) ($_SESSION['admin_2fa_pending']['expire'] ?? 0);

if (!$dangCho2FA) {
    // Nếu đã đăng nhập Admin hoặc Sinh viên thì điều hướng theo đúng phân quyền
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

// Xử lý yêu cầu gửi lại mã OTP 2FA cho Admin ngay trên giao diện hợp nhất
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

    // BƯỚC 2: XÁC THỰC MÃ OTP 2FA (DÀNH CHO TÀI KHOẢN QUẢN TRỊ VIÊN TRÊN THIẾT BỊ MỚI)
    if ($action === 'xac_thuc_2fa') {
        $otpNhap       = trim((string) ($_POST['otp'] ?? ''));
        $tinCayThietBi = !empty($_POST['tin_cay_thiet_bi']);
        $kq            = Auth::xacThucOtpAdmin2FA($otpNhap, $tinCayThietBi);

        if ($kq['ok']) {
            redirect(BASE_URL . '/admin/index.php');
        }
        $loi[]      = $kq['loi'];
        $dangCho2FA = empty($kq['hetHan']);
    }
    // BƯỚC 1: ĐĂNG NHẬP CHUNG & TỰ ĐỘNG PHÂN QUYỀN THEO TÀI KHOẢN
    else {
        $dinhDanhNhap = trim((string) ($_POST['email'] ?? ''));
        $matKhau      = (string) ($_POST['mat_khau'] ?? '');
        $ghiNho       = !empty($_POST['ghi_nho']);

        if ($dinhDanhNhap === '' || $matKhau === '') {
            $loi[] = 'Vui lòng nhập email (hoặc tên đăng nhập) và mật khẩu.';
        } else {
            // Tự động phân tích tài khoản thuộc phân quyền nào (Admin hay Sinh viên)
            $pdo = Database::pdo();
            $stC = $pdo->prepare('SELECT 1 FROM NGUOIDUNG WHERE Email = ? LIMIT 1');
            $stC->execute([strtolower($dinhDanhNhap)]);
            $laTaiKhoanClient = (bool) $stC->fetchColumn();
            $laTaiKhoanAdmin  = Auth::laDinhDanhAdmin($dinhDanhNhap);

            // Ưu tiên 1: Nếu khớp tài khoản Admin (và không trùng tài khoản sinh viên, hoặc thử xác thực Admin thành công)
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

            // Ưu tiên 2: Nếu chưa chuyển sang 2FA Admin và chưa có lỗi (hoặc là tài khoản Sinh viên)
            if (!$dangCho2FA && empty($loi)) {
                $kqClient = Auth::dangNhap('client', strtolower($dinhDanhNhap), $matKhau, ['remember' => $ghiNho]);
                if ($kqClient['ok']) {
                    $dich = (($kqClient['user']['TrangThaiKhaoSat'] ?? '') === 'Chưa làm')
                        ? '/client/khaosat.php'
                        : '/client/index.php';
                    redirect(BASE_URL . $dich);
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
      <!-- ==================== GIAO DIỆN XÁC THỰC OTP 2FA CHO QUẢN TRỊ VIÊN ==================== -->
      <div class="thongbao-2fa-box">
        <span class="icon-2fa">🛡️</span>
        <div>
          <?= $daGuiLaiOtp ? 'Đã gửi lại mã OTP mới' : 'Tài khoản Quản trị viên: Mã OTP 6 số đã được gửi' ?> tới
          <strong><?= e(Auth::cheEmail((string) ($flow2FA['email'] ?? ''))) ?></strong>
          (kiểm tra cả mục <span class="spam-tag">Spam</span>).
        </div>
      </div>

      <form method="post" action="" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="xac_thuc_2fa">

        <label for="otp">Mã xác thực OTP (6 chữ số từ Email)</label>
        <input type="text" id="otp" name="otp" maxlength="6" inputmode="numeric" pattern="[0-9]{6}"
               placeholder="Nhập 6 số OTP" required autofocus autocomplete="one-time-code"
               style="letter-spacing: 6px; font-weight: 800; text-align: center; font-size: 19px;">

        <div class="hang-mat-khau-header" style="margin-top: 4px; margin-bottom: 12px;">
          <span style="font-size: 12.5px; color: #94a3b8;">Chưa nhận được mã?</span>
          <a href="<?= e(BASE_URL) ?>/client/dangnhap.php?gui_lai_otp=1" class="link-quen-mk">Gửi lại mã OTP</a>
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

    <?php else: ?>
      <!-- ==================== GIAO DIỆN ĐĂNG NHẬP CHUNG (TỰ ĐỘNG PHÂN QUYỀN) ==================== -->
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
