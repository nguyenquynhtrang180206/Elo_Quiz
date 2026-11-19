<?php
// =====================================================================
// KHÔI PHỤC MẬT KHẨU QUẢN TRỊ VIÊN QUA EMAIL OTP - QUIZUP ARENA
// Đặt tại: elo_quiz/admin/quenmatkhau.php
// =====================================================================
require_once __DIR__ . '/../core/bootstrap.php';

// Nếu đã đăng nhập Admin thì vào thẳng Dashboard
if (Auth::adminId() !== null) {
    redirect(BASE_URL . '/admin/index.php');
}

$pdo            = Database::pdo();
$loi            = [];
$daGuiLai       = false;
$coTheDangNhap  = false;
$buoc           = 1; // 1: Tài khoản & Email, 2: OTP & Mật khẩu mới, 3: Hoàn tất

// Quay lại bước 1 để nhập tài khoản / email khác
if (isset($_GET['doi_email'])) {
    unset($_SESSION['admin_reset_flow']);
    redirect(BASE_URL . '/admin/quenmatkhau.php');
}

// Kiểm tra phiên khôi phục đang dở
if (!empty($_SESSION['admin_reset_flow'])) {
    if (time() > ($_SESSION['admin_reset_flow']['expire'] ?? 0)) {
        unset($_SESSION['admin_reset_flow']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $loi[] = 'Mã bảo mật OTP đã hết hạn (quá 15 phút). Vui lòng yêu cầu cấp mã mới.';
        }
        $buoc = 1;
    } else {
        $buoc = 2;
    }
}

// Xử lý yêu cầu gửi lại mã OTP (cooldown 60s)
if (isset($_GET['gui_lai']) && !empty($_SESSION['admin_reset_flow'])) {
    $now      = time();
    $flow     = $_SESSION['admin_reset_flow'];
    $lastSent = (int) ($flow['last_sent'] ?? 0);
    if ($now - $lastSent < 60) {
        $cho = 60 - ($now - $lastSent);
        $loi = ["Vui lòng đợi {$cho} giây nữa trước khi yêu cầu gửi lại mã OTP."];
    } else {
        $hanCheGui = RateLimiter::kiemTra('forgot_pw_admin', $flow['tenDangNhap']);
        if ($hanCheGui['biKhoa']) {
            $loi = ['Bạn đã gửi yêu cầu quá nhiều lần. Vui lòng thử lại sau '
                . RateLimiter::dinhDangThoiGian($hanCheGui['thoiGianCon']) . '.'];
        } else {
            RateLimiter::ghiNhanThatBai('forgot_pw_admin', $flow['tenDangNhap']);
            $otpMoi = (string) random_int(100000, 999999);
            $_SESSION['admin_reset_flow']['otp']       = $otpMoi;
            $_SESSION['admin_reset_flow']['expire']    = $now + 900;
            $_SESSION['admin_reset_flow']['last_sent'] = $now;

            Mailer::guiOtpQuenMatKhauAdmin($flow['email'], $flow['hoTen'], $flow['tenDangNhap'], $otpMoi);
            $loi      = [];
            $daGuiLai = true;
        }
    }
    $buoc = 2;
}

// Giá trị mặc định cho Bước 1 (tự lấy tên đăng nhập 'admin' & Email cấu hình trong ADMIN hoặc SMTP)
$tenDangNhap = trim((string) ($_POST['ten_dang_nhap'] ?? $_GET['ten_dang_nhap'] ?? 'admin'));
$emailMacDinh = (string) ($GLOBALS['APP_CONFIG']['smtp']['username'] ?? '');
if ($tenDangNhap !== '') {
    try {
        $stDef = $pdo->prepare('SELECT Email FROM ADMIN WHERE TenDangNhap = ?');
        $stDef->execute([$tenDangNhap]);
        $dbEmail = trim((string) ($stDef->fetchColumn() ?: ''));
        if ($dbEmail !== '') {
            $emailMacDinh = $dbEmail;
        }
    } catch (Throwable) {
        // Giữ fallback từ SMTP config nếu chưa kịp đọc DB
    }
}
$emailNhap = strtolower(trim((string) ($_POST['email'] ?? $emailMacDinh)));

// =====================================================================
// XỬ LÝ FORM GỬI LÊN
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = trim((string) ($_POST['action'] ?? ''));

    // BƯỚC 1: XÁC MINH TÀI KHOẢN QUẢN TRỊ & GỬI MÃ OTP QUA EMAIL
    if ($action === 'xac_minh_admin') {
        $loi         = [];
        $tenDangNhap = trim((string) ($_POST['ten_dang_nhap'] ?? ''));
        $emailNhap   = strtolower(trim((string) ($_POST['email'] ?? '')));

        if ($tenDangNhap === '') {
            $loi[] = 'Vui lòng nhập tên đăng nhập quản trị.';
        }
        if ($emailNhap === '') {
            $loi[] = 'Vui lòng nhập địa chỉ email nhận mã OTP.';
        } elseif (!filter_var($emailNhap, FILTER_VALIDATE_EMAIL) || strlen($emailNhap) > 150) {
            $loi[] = 'Địa chỉ email không đúng định dạng.';
        }

        if (!$loi) {
            $hanChe = RateLimiter::kiemTra('forgot_pw_admin', $tenDangNhap);
            if ($hanChe['biKhoa']) {
                $loi[] = 'Bạn đã gửi yêu cầu quá nhiều lần. Vui lòng thử lại sau '
                    . RateLimiter::dinhDangThoiGian($hanChe['thoiGianCon']) . '.';
            } else {
                $st = $pdo->prepare('SELECT MaAdmin, TenDangNhap, HoTen, Email, TrangThai FROM ADMIN WHERE TenDangNhap = ?');
                $st->execute([$tenDangNhap]);
                $ad = $st->fetch();

                if (!$ad) {
                    RateLimiter::ghiNhanThatBai('forgot_pw_admin', $tenDangNhap);
                    $loi[] = 'Không tìm thấy tài khoản quản trị với tên đăng nhập này.';
                } elseif ($ad['TrangThai'] === 'Bị khóa') {
                    $loi[] = 'Tài khoản quản trị này hiện đang bị khóa. Không thể khôi phục mật khẩu.';
                } else {
                    RateLimiter::xoa('forgot_pw_admin', $tenDangNhap);

                    $otp = (string) random_int(100000, 999999);
                    $_SESSION['admin_reset_flow'] = [
                        'maAdmin'     => (int) $ad['MaAdmin'],
                        'tenDangNhap' => (string) $ad['TenDangNhap'],
                        'hoTen'       => (string) $ad['HoTen'],
                        'email'       => $emailNhap,
                        'otp'         => $otp,
                        'expire'      => time() + 900, // 15 phút
                        'last_sent'   => time(),
                    ];

                    Mailer::guiOtpQuenMatKhauAdmin($emailNhap, (string) $ad['HoTen'], (string) $ad['TenDangNhap'], $otp);
                    $loi  = [];
                    $buoc = 2;
                }
            }
        }
    }

    // BƯỚC 2: XÁC THỰC MÃ OTP & ĐẶT LẠI MẬT KHẨU QUẢN TRỊ
    elseif ($action === 'dat_lai_mat_khau_admin') {
        $loi = [];
        if (empty($_SESSION['admin_reset_flow']) || time() > ($_SESSION['admin_reset_flow']['expire'] ?? 0)) {
            unset($_SESSION['admin_reset_flow']);
            $loi[] = 'Phiên khôi phục đã hết hạn. Vui lòng thử lại từ đầu.';
            $buoc  = 1;
        } else {
            $flow       = $_SESSION['admin_reset_flow'];
            $otpNhap    = trim((string) ($_POST['otp'] ?? ''));
            $matKhauMoi = (string) ($_POST['mat_khau_moi'] ?? '');
            $xacNhan    = (string) ($_POST['xac_nhan'] ?? '');

            $hanCheVerify = RateLimiter::kiemTra('forgot_pw_admin_verify', $flow['tenDangNhap']);
            if ($hanCheVerify['biKhoa']) {
                $loi[] = 'Bạn đã nhập sai mã OTP quá 5 lần. Vui lòng thử lại sau '
                    . RateLimiter::dinhDangThoiGian($hanCheVerify['thoiGianCon']) . '.';
            } else {
                // 1. Kiểm tra OTP
                if ($otpNhap === '') {
                    $loi[] = 'Vui lòng nhập mã OTP 6 số từ email.';
                } elseif (!hash_equals((string) $flow['otp'], $otpNhap)) {
                    $soLan  = RateLimiter::ghiNhanThatBai('forgot_pw_admin_verify', $flow['tenDangNhap']);
                    $conLai = max(0, 5 - $soLan);
                    if ($conLai > 0) {
                        $loi[] = "Mã OTP không chính xác. Bạn còn {$conLai} lần thử trước khi bị khóa tạm thời.";
                    } else {
                        $loi[] = 'Bạn đã nhập sai mã OTP 5 lần. Vui lòng đợi 15 phút trước khi thử lại.';
                    }
                }

                // 2. Kiểm tra mật khẩu mới
                if ($matKhauMoi === '') {
                    $loi[] = 'Vui lòng nhập mật khẩu mới.';
                } elseif ($matKhauMoi !== $xacNhan) {
                    $loi[] = 'Mật khẩu xác nhận không khớp.';
                } else {
                    $loiManh = PasswordSecurity::kiemTraDoManh($matKhauMoi, [
                        'tenDangNhap' => $flow['tenDangNhap'],
                        'email'       => $flow['email'],
                        'hoTen'       => $flow['hoTen'],
                    ]);
                    if ($loiManh) {
                        $loi = array_merge($loi, $loiManh);
                    } elseif (!$loi) {
                        // Chỉ kiểm tra lịch sử mật khẩu sau khi OTP hợp lệ (tránh lộ thông tin qua Oracle)
                        $canhBaoLichSu = PasswordSecurity::kiemTraLichSuAdmin($pdo, (int) $flow['maAdmin'], $matKhauMoi);
                        if ($canhBaoLichSu !== null) {
                            $loi[]         = $canhBaoLichSu['thongBao'];
                            $coTheDangNhap = !empty($canhBaoLichSu['coTheDangNhap']);
                        }
                    }
                }
            }

            // 3. Lưu mật khẩu mới, ghi lịch sử mật khẩu & nhật ký quản trị
            if (!$loi) {
                $hashMoi = PasswordSecurity::hash($matKhauMoi);
                PasswordSecurity::ghiNhanDoiMatKhauAdmin($pdo, (int) $flow['maAdmin'], $hashMoi, $flow['email']);
                NhatKy::ghi(
                    'Khôi phục mật khẩu qua OTP',
                    'ADMIN #' . $flow['maAdmin'],
                    'Email nhận OTP: ' . $flow['email'],
                    'Thành công',
                    (int) $flow['maAdmin']
                );
                RateLimiter::xoa('forgot_pw_admin', $flow['tenDangNhap']);
                RateLimiter::xoa('forgot_pw_admin_verify', $flow['tenDangNhap']);

                $tenDangNhapVuaReset = $flow['tenDangNhap'];
                unset($_SESSION['admin_reset_flow']);
                $buoc = 3;
            } else {
                $buoc = 2;
            }
        }
    }
}

$flow = $_SESSION['admin_reset_flow'] ?? [];
$urlDangNhapLai = BASE_URL . '/admin/dangnhap.php'
    . (!empty($tenDangNhapVuaReset) ? '?ten_dang_nhap=' . urlencode($tenDangNhapVuaReset)
        : (!empty($flow['tenDangNhap']) ? '?ten_dang_nhap=' . urlencode($flow['tenDangNhap']) : ''));
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Quên mật khẩu quản trị - QUIZUP ARENA</title>
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
    <h2>Quên mật khẩu quản trị</h2>
    <p class="quenmk-mo-ta">Xác thực qua mã OTP Email để khôi phục quyền truy cập khu quản trị.</p>

    <!-- Stepper Tiến trình tối giản -->
    <div class="quenmk-stepper">
      <div class="stepper-track">
        <div class="stepper-bar" style="width: <?= $buoc === 1 ? '0%' : ($buoc === 2 ? '50%' : '100%') ?>;"></div>
      </div>
      <div class="step-item <?= $buoc >= 1 ? 'active' : '' ?> <?= $buoc > 1 ? 'done' : '' ?>">
        <div class="step-num"><?= $buoc > 1 ? '✓' : '1' ?></div>
        <div class="step-label">Tài khoản</div>
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
        <?php if ($coTheDangNhap): ?>
          <div style="margin-top: 10px; text-align: center;">
            <a href="<?= e($urlDangNhapLai) ?>" class="btn" style="padding: 9px 16px; font-size: 13.5px; display: inline-flex; width: auto; text-decoration: none; color: #fff !important;">
              🚀 Đăng nhập ngay bằng mật khẩu này
            </a>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <!-- ==================== BƯỚC 1: NHẬP TÊN ĐĂNG NHẬP & EMAIL ==================== -->
    <?php if ($buoc === 1): ?>
      <form method="post" action="" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="xac_minh_admin">

        <label for="ten_dang_nhap">Tên đăng nhập quản trị</label>
        <input type="text" id="ten_dang_nhap" name="ten_dang_nhap" maxlength="50"
               value="<?= e($tenDangNhap) ?>" required autofocus placeholder="Nhập tên đăng nhập (vd: admin)">

        <label for="email">Email nhận mã xác thực OTP</label>
        <input type="email" id="email" name="email" maxlength="150"
               value="<?= e($emailNhap) ?>" required placeholder="Nhập email nhận mã OTP">

        <p><button type="submit" class="btn">Gửi mã OTP xác thực</button></p>
      </form>

    <!-- ==================== BƯỚC 2: NHẬP OTP & ĐẶT MẬT KHẨU MỚI ==================== -->
    <?php elseif ($buoc === 2): ?>
      <div class="thongbao-email-box">
        <span class="email-icon-pulse">✉️</span>
        <div>
          <?= $daGuiLai ? 'Đã gửi lại mã OTP mới' : 'Mã OTP quản trị đã được gửi' ?> tới: <strong><?= e($flow['email'] ?? '') ?></strong> (kiểm tra cả mục <span class="email-spam-hint">Spam</span>).
        </div>
      </div>

      <form method="post" action="" novalidate id="formAdminResetPw">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="dat_lai_mat_khau_admin">

        <label for="otp">Mã xác thực OTP (6 số từ email)</label>
        <input type="text" id="otp" name="otp" maxlength="6" inputmode="numeric" pattern="[0-9]{6}"
               placeholder="Nhập 6 số OTP" required autofocus autocomplete="off"
               style="letter-spacing: 5px; font-weight: 700; text-align: center; font-size: 18px;">

        <div class="hang-gui-lai">
          <span style="font-size: 12.5px; color: #64748b;">Chưa nhận được email?</span>
          <a href="<?= e(BASE_URL) ?>/admin/quenmatkhau.php?gui_lai=1" class="link-gui-lai">Gửi lại mã OTP</a>
        </div>

        <label for="mat_khau_moi">Mật khẩu quản trị mới</label>
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

        <p><button type="submit" class="btn">Lưu mật khẩu quản trị mới</button></p>
        <p style="text-align: center; margin-top: 8px; font-size: 13.5px;">
          <a href="<?= e(BASE_URL) ?>/admin/quenmatkhau.php?doi_email=1">← Nhập lại tài khoản / Email khác</a>
        </p>
      </form>
      <script src="<?= e(BASE_URL) ?>/assets/js/pw_helper.js"></script>

    <!-- ==================== BƯỚC 3: HOÀN TẤT THÀNH CÔNG ==================== -->
    <?php elseif ($buoc === 3): ?>
      <div class="thanhcong" style="text-align: center;">
        <div style="font-size: 32px; margin-bottom: 8px;">✅</div>
        <h3 style="margin: 0 0 8px; color: #065f46; font-size: 18px;">Đặt lại mật khẩu quản trị thành công!</h3>
        <p style="margin: 0 0 16px; font-size: 13.5px; color: #047857; line-height: 1.5;">
          Mật khẩu mới đã được cập nhật và lưu lịch sử bảo mật.<br>
          Tự động chuyển về trang đăng nhập quản trị sau <strong id="cdTimer">3</strong> giây...
        </p>
        <p><a href="<?= e($urlDangNhapLai) ?>" class="btn" style="color: #fff !important; text-decoration: none;">Đăng nhập ngay</a></p>
      </div>
      <script>
        var s = 3;
        var timerElem = document.getElementById('cdTimer');
        var itv = setInterval(function () {
          s--;
          if (timerElem) timerElem.textContent = s;
          if (s <= 0) {
            clearInterval(itv);
            window.location.href = <?= json_encode($urlDangNhapLai) ?>;
          }
        }, 1000);
      </script>
    <?php endif; ?>

    <?php if ($buoc !== 3): ?>
      <p style="margin-top: 16px; text-align: center; font-size: 14px;">
        Đã nhớ mật khẩu? <a href="<?= e(BASE_URL) ?>/admin/dangnhap.php">Đăng nhập quản trị</a>
      </p>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
