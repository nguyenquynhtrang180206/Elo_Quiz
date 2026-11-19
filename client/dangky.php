<?php
// Đăng ký sinh viên có xác thực Email OTP (C-01, C-02, C-04). Đặt tại: elo_quiz/client/dangky.php
require_once __DIR__ . '/../core/bootstrap.php';

// Đã đăng nhập thì không cần đăng ký nữa
if (Auth::clientId() !== null) {
    redirect(BASE_URL . '/client/index.php');
}

$pdo      = Database::pdo();
$loi      = [];
$daGuiLai = false;
$buoc     = 1; // 1: Nhập thông tin, 2: Nhập mã OTP từ email
$hoTen    = '';
$email    = '';

// Quay lại bước 1 để sửa họ tên / đổi địa chỉ email khác
if (isset($_GET['sua_thong_tin']) || isset($_GET['doi_email'])) {
    if (!empty($_SESSION['register_flow'])) {
        $_SESSION['register_draft'] = [
            'hoTen' => (string) ($_SESSION['register_flow']['hoTen'] ?? ''),
            'email' => (string) ($_SESSION['register_flow']['email'] ?? ''),
        ];
    }
    unset($_SESSION['register_flow']);
    redirect(BASE_URL . '/client/dangky.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !empty($_SESSION['register_draft'])) {
    $hoTen = (string) ($_SESSION['register_draft']['hoTen'] ?? '');
    $email = (string) ($_SESSION['register_draft']['email'] ?? '');
    unset($_SESSION['register_draft']);
}

// Kiểm tra phiên xác thực OTP đăng ký đang dở
if (!empty($_SESSION['register_flow'])) {
    if (time() > ($_SESSION['register_flow']['expire'] ?? 0)) {
        unset($_SESSION['register_flow']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $loi[] = 'Mã bảo mật OTP đã hết hạn (quá 15 phút). Vui lòng nhập lại thông tin để nhận mã mới.';
        }
        $buoc = 1;
    } else {
        $buoc = 2;
    }
}

// Xử lý yêu cầu gửi lại mã OTP (chỉ khi đang ở bước 2)
if (isset($_GET['gui_lai']) && !empty($_SESSION['register_flow'])) {
    $flow     = $_SESSION['register_flow'];
    $now      = time();
    $lastSent = (int) ($flow['last_sent'] ?? 0);
    if ($now - $lastSent < 60) {
        $cho   = 60 - ($now - $lastSent);
        $loi   = ["Vui lòng đợi {$cho} giây nữa trước khi yêu cầu gửi lại mã OTP."];
    } else {
        $hanCheGui = RateLimiter::kiemTra('register_otp', $flow['email']);
        if ($hanCheGui['biKhoa']) {
            $loi = ['Bạn đã yêu cầu gửi mã OTP quá nhiều lần. Vui lòng thử lại sau '
                . RateLimiter::dinhDangThoiGian($hanCheGui['thoiGianCon']) . '.'];
        } else {
            RateLimiter::ghiNhanThatBai('register_otp', $flow['email']);
            $otpMoi = (string) random_int(100000, 999999);
            $_SESSION['register_flow']['otp']       = $otpMoi;
            $_SESSION['register_flow']['expire']    = $now + 900; // 15 phút
            $_SESSION['register_flow']['last_sent'] = $now;

            Mailer::guiOtpDangKy($flow['email'], $flow['hoTen'], $otpMoi);
            $loi      = [];
            $daGuiLai = true;
        }
    }
    $buoc = 2;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = trim((string) ($_POST['action'] ?? 'gui_otp'));

    // BƯỚC 1: KIỂM TRA THÔNG TIN & GỬI MÃ OTP VỀ EMAIL
    if ($action === 'gui_otp') {
        $loi     = [];
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
            $hanChe = RateLimiter::kiemTra('register_otp', $email);
            if ($hanChe['biKhoa']) {
                $loi[] = 'Bạn đã gửi yêu cầu đăng ký quá nhiều lần. Vui lòng thử lại sau '
                    . RateLimiter::dinhDangThoiGian($hanChe['thoiGianCon']) . '.';
            } else {
                // C-01: email không được trùng (kiểm tra trước khi gửi OTP)
                $st = $pdo->prepare('SELECT 1 FROM NGUOIDUNG WHERE Email = ?');
                $st->execute([$email]);
                if ($st->fetchColumn()) {
                    RateLimiter::ghiNhanThatBai('register_otp', $email);
                    $loi[] = 'Email này đã được đăng ký. Hãy đăng nhập hoặc dùng email khác.';
                } else {
                    RateLimiter::ghiNhanThatBai('register_otp', $email);
                    $otp = (string) random_int(100000, 999999);
                    $_SESSION['register_flow'] = [
                        'hoTen'       => $hoTen,
                        'email'       => $email,
                        'matKhauHash' => PasswordSecurity::hash($matKhau),
                        'otp'         => $otp,
                        'expire'      => time() + 900, // 15 phút
                        'last_sent'   => time(),
                    ];

                    Mailer::guiOtpDangKy($email, $hoTen, $otp);
                    $loi  = [];
                    $buoc = 2;
                }
            }
        }
    }

    // BƯỚC 2: XÁC THỰC MÃ OTP & TẠO TÀI KHOẢN
    elseif ($action === 'xac_nhan_otp') {
        $loi = [];
        if (empty($_SESSION['register_flow']) || time() > ($_SESSION['register_flow']['expire'] ?? 0)) {
            unset($_SESSION['register_flow']);
            $loi[] = 'Phiên xác thực đăng ký đã hết hạn. Vui lòng thử lại từ đầu.';
            $buoc  = 1;
        } else {
            $flow    = $_SESSION['register_flow'];
            $otpNhap = trim((string) ($_POST['otp'] ?? ''));

            $hanCheOtp = RateLimiter::kiemTra('register_verify', $flow['email']);
            if ($hanCheOtp['biKhoa']) {
                $loi[] = 'Bạn đã nhập sai mã OTP quá 5 lần. Vui lòng thử lại sau '
                    . RateLimiter::dinhDangThoiGian($hanCheOtp['thoiGianCon']) . '.';
            } elseif ($otpNhap === '') {
                $loi[] = 'Vui lòng nhập mã OTP 6 số từ email.';
            } elseif (!hash_equals((string) $flow['otp'], $otpNhap)) {
                $soLan  = RateLimiter::ghiNhanThatBai('register_verify', $flow['email']);
                $conLai = max(0, 5 - $soLan);
                if ($conLai > 0) {
                    $loi[] = "Mã OTP không chính xác. Bạn còn {$conLai} lần thử trước khi bị khóa tạm thời.";
                } else {
                    $loi[] = 'Bạn đã nhập sai mã OTP 5 lần. Vui lòng đợi 15 phút trước khi thử lại.';
                }
            }

            if (!$loi) {
                $st = $pdo->prepare('SELECT 1 FROM NGUOIDUNG WHERE Email = ?');
                $st->execute([$flow['email']]);
                if ($st->fetchColumn()) {
                    unset($_SESSION['register_flow']);
                    $loi[] = 'Email này đã được đăng ký. Hãy đăng nhập hoặc dùng email khác.';
                    $buoc  = 1;
                } else {
                    try {
                        // C-02: lưu mật khẩu đã băm cùng thời điểm đặt mật khẩu
                        $st = $pdo->prepare(
                            'INSERT INTO NGUOIDUNG (HoTen, Email, MatKhau, NgayDoiMatKhau) VALUES (?, ?, ?, NOW())'
                        );
                        $st->execute([$flow['hoTen'], $flow['email'], $flow['matKhauHash']]);
                        $maND = (int) $pdo->lastInsertId();

                        // Lưu bản ghi mật khẩu ban đầu vào LICHSU_MATKHAU
                        try {
                            $pdo->prepare('INSERT INTO LICHSU_MATKHAU (MaND, MatKhauHash) VALUES (?, ?)')
                                ->execute([$maND, $flow['matKhauHash']]);
                        } catch (Throwable) {
                            // Không làm gián đoạn luồng đăng ký nếu bảng lịch sử gặp sự cố
                        }

                        RateLimiter::xoa('register_otp', $flow['email']);
                        RateLimiter::xoa('register_verify', $flow['email']);
                        unset($_SESSION['register_flow'], $_SESSION['register_draft']);

                        // Đăng nhập luôn sau khi xác thực OTP & tạo tài khoản thành công
                        Auth::regenerate();
                        $_SESSION['nd_id'] = $maND;

                        // C-45: vừa đăng ký xong thì hiện khảo sát định hướng
                        redirect(BASE_URL . '/client/khaosat.php');
                    } catch (PDOException $ex) {
                        if ($ex->getCode() === '23000') {
                            unset($_SESSION['register_flow']);
                            $loi[] = 'Email này đã được đăng ký. Hãy đăng nhập hoặc dùng email khác.';
                            $buoc  = 1;
                        } else {
                            throw $ex;
                        }
                    }
                }
            } else {
                $buoc = 2;
            }
        }
    }
}

$tieuDe = 'Đăng ký';
$cssTrang = 'dangky';
require __DIR__ . '/layout/header.php';
?>
<div class="container khung-register">
  <div class="card">
    <h2>Đăng ký tài khoản</h2>
    <p class="quenmk-mo-ta">Tạo tài khoản và xác thực Email để bước vào Đấu Trường Tri Thức.</p>

    <!-- Stepper Tiến trình tối giản -->
    <div class="quenmk-stepper">
      <div class="stepper-track">
        <div class="stepper-bar" style="width: <?= $buoc === 1 ? '0%' : '50%' ?>;"></div>
      </div>
      <div class="step-item active <?= $buoc > 1 ? 'done' : '' ?>">
        <div class="step-num"><?= $buoc > 1 ? '✓' : '1' ?></div>
        <div class="step-label">Thông tin</div>
      </div>
      <div class="step-item <?= $buoc >= 2 ? 'active' : '' ?>">
        <div class="step-num">2</div>
        <div class="step-label">Mã OTP</div>
      </div>
      <div class="step-item">
        <div class="step-num">3</div>
        <div class="step-label">Hoàn tất</div>
      </div>
    </div>

    <?php if ($loi): ?>
      <div class="loi">
        <?php foreach ($loi as $m): ?><div><?= e($m) ?></div><?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($buoc === 1): ?>
      <!-- ==================== BƯỚC 1: NHẬP THÔNG TIN ==================== -->
      <form method="post" action="" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="gui_otp">

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

        <p><button type="submit" class="btn">Nhận mã xác thực qua Email</button></p>
      </form>
      <script src="<?= e(BASE_URL) ?>/assets/js/pw_helper.js"></script>

    <?php else: ?>
      <!-- ==================== BƯỚC 2: XÁC THỰC MÃ OTP ==================== -->
      <?php $flow = $_SESSION['register_flow'] ?? []; ?>

      <div class="thongbao-email-box">
        <span class="email-icon-pulse">✉️</span>
        <div>
          <?= $daGuiLai ? 'Đã gửi lại mã OTP mới' : 'Mã OTP kích hoạt tài khoản đã được gửi' ?> tới: <strong><?= e($flow['email'] ?? '') ?></strong> (kiểm tra cả mục <span class="email-spam-hint">Spam</span>).
        </div>
      </div>

      <form method="post" action="" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="xac_nhan_otp">

        <label for="otp">Mã xác thực OTP (6 số từ email)</label>
        <input type="text" id="otp" name="otp" maxlength="6" inputmode="numeric" pattern="[0-9]{6}"
               placeholder="Nhập 6 số OTP" required autofocus autocomplete="off"
               style="letter-spacing: 5px; font-weight: 700; text-align: center; font-size: 18px;">

        <div class="hang-gui-lai">
          <span style="font-size: 12.5px; color: var(--mau-phu);">Chưa nhận được email?</span>
          <a href="<?= e(BASE_URL) ?>/client/dangky.php?gui_lai=1" class="link-gui-lai">Gửi lại mã OTP</a>
        </div>

        <p><button type="submit" class="btn">Xác nhận OTP &amp; Hoàn tất đăng ký</button></p>
        <p style="text-align: center; margin-top: 8px;">
          <a href="<?= e(BASE_URL) ?>/client/dangky.php?sua_thong_tin=1" style="font-size: 13.5px; color: var(--mau-phu);">
            ← Sửa thông tin / Đổi email khác
          </a>
        </p>
      </form>
    <?php endif; ?>

    <p style="margin-top: 18px;">Đã có tài khoản? <a href="<?= e(BASE_URL) ?>/client/dangnhap.php">Đăng nhập</a> &bull; <a href="<?= e(BASE_URL) ?>/client/quenmatkhau.php" style="color:#00D9FF;">Quên mật khẩu?</a></p>
  </div>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>
