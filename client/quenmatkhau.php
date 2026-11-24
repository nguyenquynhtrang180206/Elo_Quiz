<?php
// =====================================================================
// KHÔI PHỤC MẬT KHẨU AN TOÀN (C-06) - QUIZUP ARENA
// Đặt tại: elo_quiz/client/quenmatkhau.php
// Bảo mật chuẩn OWASP:
// - Chống Brute-force ở cả bước gửi OTP lẫn bước nhập mã OTP 6 số (khóa 15 phút sau 5 lần sai).
// - So sánh mã OTP bằng hash_equals chống Timing Attack.
// - Chỉ kiểm tra lịch sử mật khẩu sau khi mã OTP hợp lệ (chống Password History Oracle).
// =====================================================================
require_once __DIR__ . '/../core/bootstrap.php';

if (Auth::clientId() !== null) {
    redirect(BASE_URL . '/client/index.php');
}

$pdo           = Database::pdo();
$loi           = [];
$daGuiLai      = false;
$coTheDangNhap = false;
$buoc          = 1; // 1: Nhập Email, 2: Nhập OTP & Mật khẩu mới, 3: Thành công

if (isset($_GET['doi_email'])) {
    unset($_SESSION['reset_flow']);
    redirect(BASE_URL . '/client/quenmatkhau.php');
}

if (!empty($_SESSION['reset_flow'])) {
    if (time() > ($_SESSION['reset_flow']['expire'] ?? 0)) {
        unset($_SESSION['reset_flow']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $loi[] = 'Mã bảo mật OTP đã hết hạn (quá 15 phút). Vui lòng yêu cầu cấp mã mới.';
        }
        $buoc = 1;
    } else {
        $buoc = 2;
    }
}

// Xử lý yêu cầu gửi lại mã OTP (cooldown 60s + RateLimiter)
if (isset($_GET['gui_lai']) && !empty($_SESSION['reset_flow'])) {
    $now      = time();
    $flow     = $_SESSION['reset_flow'];
    $lastSent = (int) ($flow['last_sent'] ?? 0);
    if ($now - $lastSent < 60) {
        $cho = 60 - ($now - $lastSent);
        $loi = ["Vui lòng đợi {$cho} giây nữa trước khi yêu cầu gửi lại mã OTP."];
    } else {
        $hanCheGui = RateLimiter::kiemTra('forgot_pw', $flow['email']);
        if ($hanCheGui['biKhoa']) {
            $loi = ['Bạn đã gửi yêu cầu quá nhiều lần. Vui lòng thử lại sau '
                . RateLimiter::dinhDangThoiGian($hanCheGui['thoiGianCon']) . '.'];
        } else {
            RateLimiter::ghiNhanThatBai('forgot_pw', $flow['email']);
            $otpMoi = (string) random_int(100000, 999999);
            $_SESSION['reset_flow']['otp']       = $otpMoi;
            $_SESSION['reset_flow']['expire']    = $now + 900; // 15 phút
            $_SESSION['reset_flow']['last_sent'] = $now;

            Mailer::guiOtpQuenMatKhau($flow['email'], $flow['hoTen'], $otpMoi);
            $loi      = [];
            $daGuiLai = true;
        }
    }
    $buoc = 2;
}

// =====================================================================
// XỬ LÝ FORM GỬI LÊN
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = trim((string) ($_POST['action'] ?? ''));

    // BƯỚC 1: XÁC MINH EMAIL / TÀI KHOẢN & GỬI MÃ OTP VÀO EMAIL
    if ($action === 'xac_minh_email') {
        $loi   = [];
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));

        if ($email === '') {
            $loi[] = 'Vui lòng nhập địa chỉ email tài khoản của bạn.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
            $loi[] = 'Địa chỉ email không đúng định dạng.';
        } else {
            DbSchema::damBao($pdo);
            $hanChe = RateLimiter::kiemTra('forgot_pw', $email);
            if ($hanChe['biKhoa']) {
                $loi[] = 'Bạn đã gửi yêu cầu quá nhiều lần. Vui lòng thử lại sau '
                    . RateLimiter::dinhDangThoiGian($hanChe['thoiGianCon']) . '.';
            } else {
                // 1. Kiểm tra trong bảng NGUOIDUNG (Sinh viên)
                $st = $pdo->prepare('SELECT MaND, HoTen, Email, TrangThai FROM NGUOIDUNG WHERE Email = ?');
                $st->execute([$email]);
                $nd = $st->fetch(PDO::FETCH_ASSOC);

                // 2. Nếu không phải sinh viên, tự động kiểm tra trong bảng ADMIN (Quản trị viên)
                $ad = null;
                if (!$nd) {
                    $stAd = $pdo->prepare('SELECT MaAdmin, TenDangNhap, HoTen, Email, TrangThai FROM ADMIN WHERE LOWER(Email) = ? LIMIT 1');
                    $stAd->execute([$email]);
                    $ad = $stAd->fetch(PDO::FETCH_ASSOC);
                }

                if (!$nd && !$ad) {
                    RateLimiter::ghiNhanThatBai('forgot_pw', $email);
                    $loi[] = 'Không tìm thấy tài khoản nào gắn với email này.';
                } elseif (($nd && $nd['TrangThai'] === 'Bị khóa') || ($ad && $ad['TrangThai'] === 'Bị khóa')) {
                    $loi[] = 'Tài khoản này hiện đang bị khóa. Vui lòng liên hệ ban quản trị.';
                } else {
                    RateLimiter::xoa('forgot_pw', $email);
                    $otp = (string) random_int(100000, 999999);

                    if ($ad) {
                        $_SESSION['reset_flow'] = [
                            'loai'        => 'admin',
                            'maAdmin'     => (int) $ad['MaAdmin'],
                            'tenDangNhap' => (string) $ad['TenDangNhap'],
                            'hoTen'       => (string) $ad['HoTen'],
                            'email'       => (string) $ad['Email'],
                            'otp'         => $otp,
                            'expire'      => time() + 900,
                            'last_sent'   => time(),
                        ];
                        Mailer::guiOtpQuenMatKhauAdmin($ad['Email'], $ad['HoTen'], $ad['TenDangNhap'], $otp);
                    } else {
                        $_SESSION['reset_flow'] = [
                            'loai'      => 'client',
                            'maND'      => (int) $nd['MaND'],
                            'hoTen'     => (string) $nd['HoTen'],
                            'email'     => (string) $nd['Email'],
                            'otp'       => $otp,
                            'expire'    => time() + 900,
                            'last_sent' => time(),
                        ];
                        Mailer::guiOtpQuenMatKhau($nd['Email'], $nd['HoTen'], $otp);
                    }
                    $loi  = [];
                    $buoc = 2;
                }
            }
        }
    }

    // BƯỚC 2: XÁC THỰC MÃ OTP VÀ ĐẶT MẬT KHẨU MỚI
    elseif ($action === 'dat_lai_mat_khau') {
        $loi = [];
        if (empty($_SESSION['reset_flow']) || time() > ($_SESSION['reset_flow']['expire'] ?? 0)) {
            unset($_SESSION['reset_flow']);
            $loi[] = 'Phiên khôi phục đã hết hạn. Vui lòng thử lại từ đầu.';
            $buoc  = 1;
        } else {
            $otpNhap    = trim((string) ($_POST['otp'] ?? ''));
            $matKhauMoi = (string) ($_POST['mat_khau_moi'] ?? '');
            $xacNhan    = (string) ($_POST['xac_nhan'] ?? '');
            $flow       = $_SESSION['reset_flow'];

            $hanCheVerify = RateLimiter::kiemTra('forgot_pw_verify', $flow['email']);
            if ($hanCheVerify['biKhoa']) {
                $loi[] = 'Bạn đã nhập sai mã OTP quá 5 lần. Vui lòng thử lại sau '
                    . RateLimiter::dinhDangThoiGian($hanCheVerify['thoiGianCon']) . '.';
            } else {
                // 1. Kiểm tra OTP bằng hash_equals chống Timing Attack
                if ($otpNhap === '') {
                    $loi[] = 'Vui lòng nhập mã OTP 6 số từ email.';
                } elseif (!hash_equals((string) $flow['otp'], $otpNhap)) {
                    $soLan  = RateLimiter::ghiNhanThatBai('forgot_pw_verify', $flow['email']);
                    $conLai = max(0, 5 - $soLan);
                    if ($conLai > 0) {
                        $loi[] = "Mã OTP không chính xác. Bạn còn {$conLai} lần thử trước khi bị khóa tạm thời.";
                    } else {
                        $loi[] = 'Bạn đã nhập sai mã OTP 5 lần. Vui lòng đợi 15 phút trước khi thử lại.';
                    }
                }

                // 2. Kiểm tra mật khẩu mới (tự động nhận diện Admin hay Client)
                $laResetAdmin = (($flow['loai'] ?? 'client') === 'admin');
                if ($matKhauMoi === '') {
                    $loi[] = 'Vui lòng nhập mật khẩu mới.';
                } elseif ($matKhauMoi !== $xacNhan) {
                    $loi[] = 'Mật khẩu xác nhận không khớp.';
                } else {
                    $loiManh = PasswordSecurity::kiemTraDoManh($matKhauMoi, [
                        'tenDangNhap' => $flow['tenDangNhap'] ?? '',
                        'email'       => $flow['email'],
                        'hoTen'       => $flow['hoTen'],
                    ]);
                    if ($loiManh) {
                        $loi = array_merge($loi, $loiManh);
                    } elseif (!$loi) {
                        // Chỉ kiểm tra lịch sử mật khẩu sau khi OTP đã đúng (chống Password Oracle)
                        $canhBaoLichSu = $laResetAdmin
                            ? PasswordSecurity::kiemTraLichSuAdmin($pdo, (int) $flow['maAdmin'], $matKhauMoi)
                            : PasswordSecurity::kiemTraLichSu($pdo, (int) $flow['maND'], $matKhauMoi);
                        if ($canhBaoLichSu !== null) {
                            $loi[]         = $canhBaoLichSu['thongBao'];
                            $coTheDangNhap = !empty($canhBaoLichSu['coTheDangNhap']);
                        }
                    }
                }
            }

            // 3. Cập nhật vào CSDL nếu hợp lệ 100%
            if (!$loi) {
                $hashMoi = PasswordSecurity::hash($matKhauMoi);
                if (!empty($laResetAdmin)) {
                    PasswordSecurity::ghiNhanDoiMatKhauAdmin($pdo, (int) $flow['maAdmin'], $hashMoi);
                    NhatKy::ghi('Khôi phục mật khẩu qua OTP', 'ADMIN #' . $flow['maAdmin'], 'Email: ' . $flow['email'], 'Thành công', (int) $flow['maAdmin']);
                } else {
                    PasswordSecurity::ghiNhanDoiMatKhau($pdo, (int) $flow['maND'], $hashMoi);
                }
                RateLimiter::xoa('forgot_pw', $flow['email']);
                RateLimiter::xoa('forgot_pw_verify', $flow['email']);

                unset($_SESSION['reset_flow']);
                $buoc = 3;
            } else {
                $buoc = 2;
            }
        }
    }
}

$tieuDe   = 'Quên mật khẩu';
$cssTrang = 'quenmatkhau';
require __DIR__ . '/layout/header.php';
?>

<div class="container khung-quenmk">
  <div class="card">
    <h2>Quên mật khẩu</h2>
    <p class="quenmk-mo-ta">Lấy lại quyền truy cập vào tài khoản đấu trường của bạn.</p>

    <!-- Stepper Tiến trình tối giản -->
    <div class="quenmk-stepper">
      <div class="stepper-track">
        <div class="stepper-bar" style="width: <?= $buoc === 1 ? '0%' : ($buoc === 2 ? '50%' : '100%') ?>;"></div>
      </div>
      <div class="step-item <?= $buoc >= 1 ? 'active' : '' ?> <?= $buoc > 1 ? 'done' : '' ?>">
        <div class="step-num"><?= $buoc > 1 ? '✓' : '1' ?></div>
        <div class="step-label">Email</div>
      </div>
      <div class="step-item <?= $buoc >= 2 ? 'active' : '' ?> <?= $buoc > 2 ? 'done' : '' ?>">
        <div class="step-num"><?= $buoc > 2 ? '✓' : '2' ?></div>
        <div class="step-label">Mật khẩu mới</div>
      </div>
      <div class="step-item <?= $buoc === 3 ? 'active done' : '' ?>">
        <div class="step-num"><?= $buoc === 3 ? '✓' : '3' ?></div>
        <div class="step-label">Hoàn tất</div>
      </div>
    </div>

    <?php if ($loi): ?>
      <div class="loi">
        <?php foreach ($loi as $m): ?><div><?= e($m) ?></div><?php endforeach; ?>
        <?php if (!empty($coTheDangNhap)): ?>
          <div style="margin-top: 10px; text-align: center;">
            <a href="<?= e(BASE_URL) ?>/client/dangnhap.php?email=<?= urlencode($flow['email'] ?? '') ?>" class="btn" style="padding: 8px 16px; font-size: 13.5px; display: inline-block; width: auto; text-decoration: none;">
              🚀 Đăng nhập ngay bằng mật khẩu này
            </a>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <!-- ==================== BƯỚC 1: NHẬP EMAIL ==================== -->
    <?php if ($buoc === 1): ?>
      <form method="post" action="" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="xac_minh_email">

        <label for="email">Email tài khoản</label>
        <input type="email" id="email" name="email" maxlength="150" required
               placeholder="Nhập email bạn đã đăng ký" value="<?= e($_POST['email'] ?? '') ?>" autofocus>

        <p><button type="submit" class="btn">Nhận mã xác thực qua Email</button></p>
      </form>

    <!-- ==================== BƯỚC 2: NHẬP OTP TỪ EMAIL & ĐẶT MẬT KHẨU MỚI ==================== -->
    <?php elseif ($buoc === 2): ?>
      <?php $flow = $_SESSION['reset_flow'] ?? []; ?>

      <div class="thongbao-email-box">
        <span class="email-icon-pulse">✉️</span>
        <div>
          <?= $daGuiLai ? 'Đã gửi lại mã OTP mới' : 'Mã OTP đã được gửi' ?> tới: <strong><?= e($flow['email'] ?? '') ?></strong> (kiểm tra cả mục <span class="email-spam-hint">Spam</span>).
        </div>
      </div>

      <form method="post" action="" novalidate id="formResetPw">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="dat_lai_mat_khau">

        <label for="otp">Mã xác thực OTP (6 số từ email)</label>
        <input type="text" id="otp" name="otp" maxlength="6" inputmode="numeric" pattern="[0-9]{6}"
               placeholder="Nhập 6 số OTP" required autofocus autocomplete="off"
               style="letter-spacing: 5px; font-weight: 700; text-align: center; font-size: 18px;">

        <div class="hang-gui-lai">
          <span style="font-size: 12.5px; color: var(--mau-phu);">Chưa nhận được email?</span>
          <a href="<?= e(BASE_URL) ?>/client/quenmatkhau.php?gui_lai=1" class="link-gui-lai">Gửi lại mã OTP</a>
        </div>

        <label for="mat_khau_moi">Mật khẩu mới</label>
        <div class="pw-field-wrap">
          <input type="password" id="mat_khau_moi" name="mat_khau_moi" required
                 placeholder="Tối thiểu 8 ký tự, gồm hoa, thường, số, ký tự đặc biệt"
                 oncopy="return false;" oncut="return false;">
          <button type="button" class="btn-toggle-pw" aria-label="Hiện/ẩn mật khẩu" tabindex="-1">
            <svg class="icon-eye" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/><line class="eye-slash" x1="4" y1="4" x2="20" y2="20"/>
            </svg>
          </button>
        </div>

        <div class="pw-strength-container" id="pwMeterBox" style="display:none;">
          <div class="pw-strength-bar"><div class="pw-strength-fill" id="pwFill"></div></div>
          <div class="pw-strength-label" id="pwLabel">Độ mạnh mật khẩu: <span>Rất yếu</span></div>
        </div>

        <label for="xac_nhan">Nhập lại mật khẩu mới</label>
        <div class="pw-field-wrap">
          <input type="password" id="xac_nhan" name="xac_nhan" class="input-no-paste" required
                 placeholder="Xác nhận lại mật khẩu mới (vui lòng gõ tay)"
                 onpaste="return false;" oncopy="return false;" oncut="return false;" ondrop="return false;" autocomplete="new-password">
          <button type="button" class="btn-toggle-pw" aria-label="Hiện/ẩn mật khẩu" tabindex="-1">
            <svg class="icon-eye" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/><line class="eye-slash" x1="4" y1="4" x2="20" y2="20"/>
            </svg>
          </button>
        </div>

        <ul class="pw-requirements-list" id="pwChecklist">
          <li data-rule="len">Ít nhất 8 ký tự (tối đa 72)</li>
          <li data-rule="case">Bao gồm cả chữ in hoa (A-Z) và chữ thường (a-z)</li>
          <li data-rule="num_sym">Bao gồm chữ số (0-9) và ký tự đặc biệt (!@#$...)</li>
          <li data-rule="match">Mật khẩu xác nhận khớp với mật khẩu mới</li>
        </ul>

        <p><button type="submit" class="btn">Lưu mật khẩu mới</button></p>
        <p style="text-align: center; margin-top: 8px;">
          <a href="<?= e(BASE_URL) ?>/client/quenmatkhau.php?doi_email=1" style="font-size: 13.5px; color: var(--mau-phu);">
            ← Đổi địa chỉ email khác
          </a>
        </p>
      </form>
      <script src="<?= e(BASE_URL) ?>/assets/js/pw_helper.js"></script>

    <!-- ==================== BƯỚC 3: HOÀN TẤT THÀNH CÔNG ==================== -->
    <?php elseif ($buoc === 3): ?>
      <div class="thanhcong" style="text-align: center; padding: 20px 14px; margin-bottom: 16px;">
        <div style="font-size: 32px; margin-bottom: 8px;">✅</div>
        <h3 style="margin: 0 0 8px; color: #fff; font-size: 18px;">Đặt lại mật khẩu thành công!</h3>
        <p style="margin: 0 0 16px; font-size: 14px; color: var(--mau-chu);">
          Mật khẩu của bạn đã được cập nhật thành công với chuẩn mã hóa an toàn.<br>
          Tự động chuyển về trang đăng nhập sau <strong id="cdTimer" style="color: var(--cyan);">3</strong> giây...
        </p>
        <p><a href="<?= e(BASE_URL) ?>/client/dangnhap.php" class="btn">Đăng nhập ngay</a></p>
      </div>
      <script>
        var s = 3;
        var timerElem = document.getElementById('cdTimer');
        var itv = setInterval(function () {
          s--;
          if (timerElem) timerElem.textContent = s;
          if (s <= 0) {
            clearInterval(itv);
            window.location.href = "<?= e(BASE_URL) ?>/client/dangnhap.php";
          }
        }, 1000);
      </script>
    <?php endif; ?>

    <?php if ($buoc !== 3): ?>
      <p style="margin-top: 18px;">Đã nhớ mật khẩu? <a href="<?= e(BASE_URL) ?>/client/dangnhap.php">Đăng nhập</a></p>
      <p>Chưa có tài khoản? <a href="<?= e(BASE_URL) ?>/client/dangky.php">Đăng ký</a></p>
    <?php endif; ?>

  </div>
</div>

<?php require __DIR__ . '/layout/footer.php'; ?>
