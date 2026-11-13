<?php
// =====================================================================
// AJAX AUTHENTICATION HANDLER (ĐĂNG NHẬP, ĐĂNG KÝ, ĐỔI MẬT KHẨU)
// Đặt tại: elo_quiz/client/ajax_xacthuc.php
// =====================================================================
require_once __DIR__ . '/../core/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'loi' => ['Yêu cầu không hợp lệ.']]);
    exit;
}

// 1. Kiểm tra CSRF token
$guiCsrf = $_POST['csrf'] ?? '';
if (!is_string($guiCsrf) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $guiCsrf)) {
    echo json_encode([
        'ok' => false,
        'csrf_het_han' => true,
        'loi' => ['Phiên làm việc bảo mật đã hết hạn. Vui lòng tải lại trang và thử lại!']
    ]);
    exit;
}

$action = trim((string) ($_POST['action'] ?? ''));
$pdo = Database::pdo();

// =====================================================================
// HÀNH ĐỘNG 1: ĐĂNG NHẬP (LOGIN)
// =====================================================================
if ($action === 'login') {
    $email   = strtolower(trim((string) ($_POST['email'] ?? '')));
    $matKhau = (string) ($_POST['mat_khau'] ?? '');

    if ($email === '' || $matKhau === '') {
        echo json_encode(['ok' => false, 'loi' => ['Vui lòng nhập đầy đủ email và mật khẩu.']]);
        exit;
    }

    // Chống Brute-force: Tối đa 5 lần sai liên tiếp trong 15 phút
    $hanChe = RateLimiter::kiemTra('login_client', $email);
    if ($hanChe['biKhoa']) {
        echo json_encode([
            'ok' => false,
            'biKhoa' => true,
            'thoiGianCon' => $hanChe['thoiGianCon'],
            'loi' => ['Bạn đã nhập sai quá 5 lần liên tiếp. Tài khoản tạm khóa để bảo vệ, vui lòng thử lại sau '
                . RateLimiter::dinhDangThoiGian($hanChe['thoiGianCon']) . '.']
        ]);
        exit;
    }

    $st = $pdo->prepare('SELECT MaND, HoTen, MatKhau, TrangThai, TrangThaiKhaoSat FROM NGUOIDUNG WHERE Email = ?');
    $st->execute([$email]);
    $nd = $st->fetch();

    $hopLe = false;
    if ($nd) {
        $hopLe = PasswordSecurity::verify($matKhau, $nd['MatKhau']);
    } else {
        // Chống Timing Attack: dummy verification để hacker không đoán được email có tồn tại hay không
        PasswordSecurity::dummyVerify($matKhau);
    }

    if (!$hopLe) {
        $soLan = RateLimiter::ghiNhanThatBai('login_client', $email);
        $conLai = max(0, 5 - $soLan);
        $thongBaoLoi = 'Email hoặc mật khẩu không chính xác.';
        if ($conLai > 0 && $conLai <= 2) {
            $thongBaoLoi .= ' Cảnh báo: Bạn còn ' . $conLai . ' lần thử trước khi bị khóa tạm thời 15 phút.';
        } elseif ($conLai === 0) {
            $thongBaoLoi = 'Bạn đã nhập sai 5 lần liên tiếp. Để bảo mật tài khoản, quyền đăng nhập tạm khóa 15 phút.';
        }
        echo json_encode(['ok' => false, 'conLai' => $conLai, 'loi' => [$thongBaoLoi]]);
        exit;
    }

    if ($nd['TrangThai'] === 'Bị khóa') {
        echo json_encode(['ok' => false, 'loi' => ['Tài khoản của bạn đã bị khóa. Vui lòng liên hệ Quản trị viên để được hỗ trợ.']]);
        exit;
    }

    // Đăng nhập thành công -> Xóa bộ đếm sai
    RateLimiter::xoa('login_client', $email);

    // Tự động nâng cấp hash mật khẩu lên chuẩn mới nếu cần
    PasswordSecurity::rehashIfNecessary($pdo, 'NGUOIDUNG', 'MaND', (int) $nd['MaND'], $matKhau, $nd['MatKhau']);

    // Khởi tạo phiên an toàn
    Auth::regenerate();
    $_SESSION['nd_id'] = (int) $nd['MaND'];

    $redirectUrl = BASE_URL . '/client/index.php';
    if ($nd['TrangThaiKhaoSat'] === 'Chưa làm') {
        $redirectUrl = BASE_URL . '/client/khaosat.php';
    }

    echo json_encode([
        'ok' => true,
        'msg' => 'Đăng nhập thành công! Chào mừng ' . $nd['HoTen'] . ' trở lại vũ đài.',
        'hoTen' => $nd['HoTen'],
        'redirect' => $redirectUrl
    ]);
    exit;
}

// =====================================================================
// HÀNH ĐỘNG 2: ĐĂNG KÝ (REGISTER)
// =====================================================================
if ($action === 'register') {
    $hoTen   = trim((string) ($_POST['ho_ten'] ?? ''));
    $email   = strtolower(trim((string) ($_POST['email'] ?? '')));
    $matKhau = (string) ($_POST['mat_khau'] ?? '');
    $xacNhan = (string) ($_POST['xac_nhan'] ?? '');

    $loi = [];

    if ($hoTen === '') {
        $loi[] = 'Vui lòng nhập họ và tên của bạn.';
    } elseif (mb_strlen($hoTen) > 100) {
        $loi[] = 'Họ tên tối đa 100 ký tự.';
    }

    if ($email === '') {
        $loi[] = 'Vui lòng nhập địa chỉ email.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
        $loi[] = 'Địa chỉ email không đúng định dạng.';
    }

    if ($matKhau === '') {
        $loi[] = 'Vui lòng nhập mật khẩu.';
    } elseif ($matKhau !== $xacNhan) {
        $loi[] = 'Mật khẩu xác nhận không khớp.';
    } else {
        $loiManh = PasswordSecurity::kiemTraDoManh($matKhau, ['email' => $email, 'hoTen' => $hoTen]);
        if ($loiManh) {
            $loi = array_merge($loi, $loiManh);
        }
    }

    if (!empty($loi)) {
        echo json_encode(['ok' => false, 'loi' => $loi]);
        exit;
    }

    // Kiểm tra trùng email
    $st = $pdo->prepare('SELECT 1 FROM NGUOIDUNG WHERE Email = ?');
    $st->execute([$email]);
    if ($st->fetchColumn()) {
        echo json_encode(['ok' => false, 'loi' => ['Email này đã được sử dụng. Vui lòng đăng nhập hoặc dùng email khác.']]);
        exit;
    }

    try {
        $st = $pdo->prepare('INSERT INTO NGUOIDUNG (HoTen, Email, MatKhau) VALUES (?, ?, ?)');
        $st->execute([$hoTen, $email, PasswordSecurity::hash($matKhau)]);
        $maND = (int) $pdo->lastInsertId();

        // Tự động đăng nhập
        Auth::regenerate();
        $_SESSION['nd_id'] = $maND;

        echo json_encode([
            'ok' => true,
            'msg' => 'Tạo tài khoản thành công! Khởi hành khám phá vũ đài.',
            'hoTen' => $hoTen,
            'redirect' => BASE_URL . '/client/khaosat.php'
        ]);
        exit;
    } catch (PDOException $ex) {
        if ($ex->getCode() === '23000') {
            echo json_encode(['ok' => false, 'loi' => ['Email này đã được đăng ký bởi người khác.']]);
        } else {
            echo json_encode(['ok' => false, 'loi' => ['Đã xảy ra lỗi máy chủ trong quá trình đăng ký. Vui lòng thử lại sau.']]);
        }
        exit;
    }
}

// =====================================================================
// HÀNH ĐỘNG 3: ĐỔI MẬT KHẨU (CHANGE PASSWORD)
// =====================================================================
if ($action === 'doimatkhau') {
    $maND = Auth::clientId();
    if ($maND === null) {
        echo json_encode(['ok' => false, 'loi' => ['Bạn chưa đăng nhập. Vui lòng đăng nhập để thực hiện đổi mật khẩu.']]);
        exit;
    }

    if (Auth::laAdminSinhVien()) {
        echo json_encode(['ok' => false, 'loi' => ['Tài khoản Quản trị viên vui lòng đổi mật khẩu tại trang Quản trị.']]);
        exit;
    }

    $hienTai = (string) ($_POST['mat_khau_cu'] ?? '');
    $moi     = (string) ($_POST['mat_khau_moi'] ?? '');
    $xacNhan = (string) ($_POST['xac_nhan'] ?? '');

    $st = $pdo->prepare('SELECT MatKhau, Email, HoTen FROM NGUOIDUNG WHERE MaND = ?');
    $st->execute([$maND]);
    $nd = $st->fetch();

    if (!$nd) {
        unset($_SESSION['nd_id']);
        echo json_encode(['ok' => false, 'loi' => ['Tài khoản không tồn tại hoặc phiên đăng nhập đã hết hạn.']]);
        exit;
    }

    $loi = [];
    if ($hienTai === '' || $moi === '' || $xacNhan === '') {
        $loi[] = 'Vui lòng điền đầy đủ mật khẩu hiện tại, mật khẩu mới và xác nhận mật khẩu.';
    } else {
        if (!PasswordSecurity::verify($hienTai, $nd['MatKhau'])) {
            $loi[] = 'Mật khẩu hiện tại không chính xác.';
        }
        if ($moi === $hienTai) {
            $loi[] = 'Mật khẩu mới phải khác với mật khẩu hiện tại.';
        } elseif ($moi !== $xacNhan) {
            $loi[] = 'Mật khẩu xác nhận không khớp.';
        } else {
            $loiManh = PasswordSecurity::kiemTraDoManh($moi, ['email' => $nd['Email'], 'hoTen' => $nd['HoTen']]);
            if ($loiManh) {
                $loi = array_merge($loi, $loiManh);
            }
        }
    }

    if (!empty($loi)) {
        echo json_encode(['ok' => false, 'loi' => $loi]);
        exit;
    }

    // Cập nhật hash mật khẩu mới (Argon2id/Bcrypt 12)
    $st = $pdo->prepare('UPDATE NGUOIDUNG SET MatKhau = ? WHERE MaND = ?');
    $st->execute([PasswordSecurity::hash($moi), $maND]);

    session_regenerate_id(true);

    echo json_encode([
        'ok' => true,
        'msg' => 'Đổi mật khẩu thành công! Mật khẩu mới của bạn đã được cập nhật an toàn tuyệt đối.'
    ]);
    exit;
}

echo json_encode(['ok' => false, 'loi' => ['Hành động không được hỗ trợ.']]);
exit;
