<?php
// =========================================================================
// HỆ THỐNG XÁC THỰC & PHÂN QUYỀN ĐA TẦNG (MULTI-GUARD AUTHENTICATION)
// Đặt tại: core/Security/XacThuc.php
//
// Kiến trúc bảo mật chuẩn thị trường (OWASP ASVS Level 2):
// 1. Dual-Guard Isolation: Tách biệt phiên Client (nd_id) và Admin (admin_id).
// 2. Real-time Session Revocation: Kiểm tra trạng thái hoạt động thực tế (có Static Cache
//    1 lần/request), tự động thu hồi phiên ngay lập tức nếu tài khoản bị khóa.
// 3. Session Hardening (Admin): Khóa phiên theo chữ ký trình duyệt (User-Agent Fingerprint)
//    và tự động hết hạn khi treo máy quá 60 phút (Idle Timeout).
// 4. Adaptive 2FA & Trusted Device (Admin): Hỗ trợ xác thực OTP Email 2 lớp trên thiết bị lạ
//    kèm Cookie tin cậy thiết bị 7 ngày ký bằng HMAC-SHA256.
// 5. Remember Me (Client): Ghi nhớ đăng nhập 30 ngày bằng Cookie chữ ký HMAC-SHA256.
// =========================================================================

class XacThuc
{
    private const COOKIE_REMEMBER_CLIENT = 'qz_remember_client';
    private const COOKIE_TRUST_ADMIN     = 'qz_trust_admin';
    private const ADMIN_IDLE_TIMEOUT     = 3600;   // 60 phút không thao tác tự khóa phiên Admin
    private const REMEMBER_TTL_CLIENT    = 2592000; // 30 ngày
    private const TRUST_TTL_ADMIN        = 604800;  // 7 ngày

    /** Bộ nhớ đệm trạng thái xác thực trong 1 vòng đời HTTP Request (tránh truy vấn lặp). */
    private static bool $daKiemTraClient = false;
    private static bool $daKiemTraAdmin  = false;
    private static ?array $thongTinClient = null;
    private static ?array $thongTinAdmin  = null;

    // =====================================================================
    // 1. KIỂM TRA PHIÊN & PHÂN QUYỀN (CLIENT & ADMIN GUARDS)
    // =====================================================================

    public static function clientId(): ?int
    {
        if (!self::$daKiemTraClient) {
            self::$daKiemTraClient = true;
            self::dongBoPhienClient();
        }
        return isset($_SESSION['nd_id']) ? (int) $_SESSION['nd_id'] : null;
    }

    public static function adminId(): ?int
    {
        if (!self::$daKiemTraAdmin) {
            self::$daKiemTraAdmin = true;
            self::dongBoPhienAdmin();
        }
        return isset($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : null;
    }

    /** Đang là Admin dùng khu sinh viên (đã vào bằng nút "Về trang sinh viên"). */
    public static function laAdminSinhVien(): bool
    {
        return self::clientId() !== null && isset($_SESSION['nd_la_admin']);
    }

    /** Admin đang dùng khu sinh viên và phiên Admin còn hiệu lực: được quyền kiểm duyệt Cộng đồng. */
    public static function laQuanTriKiemDuyet(): bool
    {
        return self::laAdminSinhVien()
            && self::adminId() !== null
            && (int) $_SESSION['nd_la_admin'] === self::adminId();
    }

    /** Đặt ở đầu mọi trang cần đăng nhập của Sinh viên. */
    public static function requireClient(string $loginUrl = 'dangnhap.php'): int
    {
        $id = self::clientId();
        if ($id !== null) {
            return $id;
        }
        if (!empty($_SESSION['_client_bi_khoa'])) {
            unset($_SESSION['_client_bi_khoa']);
            $sep = str_contains($loginUrl, '?') ? '&' : '?';
            redirect($loginUrl . $sep . 'khoa=1');
        }
        redirect($loginUrl);
    }

    /** Đặt ở đầu mọi trang và thao tác của Quản trị viên (A-04). */
    public static function requireAdmin(string $loginUrl = ''): int
    {
        $id = self::adminId();
        if ($id !== null) {
            return $id;
        }
        $dich = ($loginUrl !== '' && $loginUrl !== 'dangnhap.php' && $loginUrl !== BASE_URL . '/admin/dangnhap.php')
            ? $loginUrl
            : BASE_URL . '/client/dangnhap.php';
        redirect($dich);
    }

    /** Làm mới Session ID chống tấn công Session Fixation. */
    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            session_regenerate_id(true);
        }
    }

    // =====================================================================
    // 2. ĐĂNG NHẬP HỢP NHẤT CHUẨN MULTI-GUARD (CLIENT & ADMIN + ADAPTIVE 2FA)
    // =====================================================================

    /**
     * Xử lý đăng nhập tập trung cho cả Client và Admin.
     *
     * @param string $guard    'client' hoặc 'admin'
     * @param string $dinhDanh Email (với Client) hoặc Tên đăng nhập / Email (với Admin)
     * @param string $matKhau  Mật khẩu thô người dùng nhập
     * @param array  $tuyChon  ['remember' => bool, 'yeuCau2FA' => bool]
     * @return array ['ok' => bool, 'can2FA' => bool, 'loi' => ?string, 'user' => ?array]
     */
    public static function dangNhap(string $guard, string $dinhDanh, string $matKhau, array $tuyChon = []): array
    {
        $laAdmin  = ($guard === 'admin');
        $action   = $laAdmin ? 'login_admin' : 'login_client';
        $dinhDanh = trim($dinhDanh);
        if (!$laAdmin) {
            $dinhDanh = strtolower($dinhDanh);
        }

        // 1. Kiểm tra giới hạn tần suất chống Brute-force
        $hanChe = RateLimiter::kiemTra($action, $dinhDanh);
        if ($hanChe['biKhoa']) {
            return [
                'ok'     => false,
                'can2FA' => false,
                'loi'    => 'Bạn đã nhập sai quá 5 lần liên tiếp. Để bảo mật tài khoản, vui lòng thử lại sau '
                    . RateLimiter::dinhDangThoiGian($hanChe['thoiGianCon']) . '.',
                'user'   => null,
            ];
        }

        $pdo = Database::pdo();

        // 2. Truy vấn tài khoản theo Guard (Admin cho phép đăng nhập bằng cả TenDangNhap hoặc Email)
        if ($laAdmin) {
            // Đảm bảo cột Email và NgayDoiMatKhau luôn tồn tại trong bảng ADMIN
            DbSchema::damBao($pdo);

            $st = $pdo->prepare(
                'SELECT MaAdmin, TenDangNhap, HoTen, Email, MatKhau, TrangThai
                   FROM ADMIN
                  WHERE TenDangNhap = ? OR (Email IS NOT NULL AND Email != "" AND LOWER(Email) = LOWER(?))
                  LIMIT 1'
            );
            $st->execute([$dinhDanh, $dinhDanh]);
        } else {
            $st = $pdo->prepare(
                'SELECT MaND, HoTen, Email, MatKhau, TrangThai
                   FROM NGUOIDUNG
                  WHERE Email = ?
                  LIMIT 1'
            );
            $st->execute([$dinhDanh]);
        }
        $user = $st->fetch(PDO::FETCH_ASSOC);

        // 3. Xác thực mật khẩu (kèm Dummy Verify chống Timing Attack khi tài khoản không tồn tại)
        $hopLe = false;
        if ($user) {
            $hopLe = PasswordSecurity::verify($matKhau, (string) $user['MatKhau']);
        } else {
            PasswordSecurity::dummyVerify($matKhau);
        }

        if (!$hopLe) {
            $soLan  = RateLimiter::ghiNhanThatBai($action, $dinhDanh);
            $conLai = max(0, 5 - $soLan);
            $tenLoi = $laAdmin ? 'Tài khoản hoặc mật khẩu quản trị không đúng.' : 'Email hoặc mật khẩu không đúng.';

            if ($conLai > 0 && $conLai <= 2) {
                $loi = "{$tenLoi} Cảnh báo: Bạn còn {$conLai} lần thử trước khi bị khóa tạm thời 15 phút.";
            } elseif ($conLai === 0) {
                $loi = 'Bạn đã nhập sai 5 lần. Tài khoản tạm thời bị khóa đăng nhập trong 15 phút.';
            } else {
                $loi = $tenLoi;
            }
            return ['ok' => false, 'can2FA' => false, 'loi' => $loi, 'user' => null];
        }

        // 4. Kiểm tra trạng thái khóa tài khoản
        if (($user['TrangThai'] ?? '') === 'Bị khóa') {
            $loi = $laAdmin
                ? 'Tài khoản quản trị này đã bị khóa.'
                : 'Tài khoản của bạn đã bị khóa. Vui lòng liên hệ quản trị viên.';
            return ['ok' => false, 'can2FA' => false, 'loi' => $loi, 'user' => null];
        }

        // 5. Xóa bộ đếm sai (cả 2 kênh) & Tự động nâng cấp chuẩn băm Argon2id nếu cần
        RateLimiter::xoa('login_client', $dinhDanh);
        RateLimiter::xoa('login_admin', $dinhDanh);
        $bang = $laAdmin ? 'ADMIN' : 'NGUOIDUNG';
        $cot  = $laAdmin ? 'MaAdmin' : 'MaND';
        $id   = (int) $user[$cot];
        PasswordSecurity::rehashIfNecessary($pdo, $bang, $cot, $id, $matKhau, (string) $user['MatKhau']);

        // 6. Nhánh Quản trị viên: Kiểm tra Adaptive 2FA (nếu bật yêu cầu 2FA và thiết bị chưa được tin cậy)
        if ($laAdmin) {
            $emailAdmin = trim((string) ($user['Email'] ?? ''));
            $yeuCau2FA  = (bool) ($tuyChon['yeuCau2FA'] ?? true);

            if ($yeuCau2FA && $emailAdmin !== '' && !self::laThietBiAdminTinCay($id, (string) $user['MatKhau'])) {
                $otp = (string) random_int(100000, 999999);
                $_SESSION['admin_2fa_pending'] = [
                    'maAdmin'     => $id,
                    'tenDangNhap' => (string) $user['TenDangNhap'],
                    'hoTen'       => (string) $user['HoTen'],
                    'email'       => $emailAdmin,
                    'matKhauHash' => (string) $user['MatKhau'],
                    'otp'         => $otp,
                    'expire'      => time() + 600, // 10 phút
                    'last_sent'   => time(),
                ];
                Mailer::guiOtpDangNhapAdmin($emailAdmin, (string) $user['HoTen'], (string) $user['TenDangNhap'], $otp);
                return ['ok' => false, 'can2FA' => true, 'loi' => null, 'user' => $user];
            }

            self::kichHoatPhienAdmin($id, (string) $user['MatKhau'], false);
            return ['ok' => true, 'can2FA' => false, 'loi' => null, 'user' => $user];
        }

        // 7. Nhánh Sinh viên (Client): Kích hoạt phiên & Ghi nhớ đăng nhập (nếu chọn)
        self::kichHoatPhienClient($id, (string) $user['MatKhau'], !empty($tuyChon['remember']));
        return ['ok' => true, 'can2FA' => false, 'loi' => null, 'user' => $user];
    }

    /**
     * Kiểm tra nhanh một định danh (TenDangNhap hoặc Email) có thuộc bảng ADMIN hay không.
     * Phục vụ cơ chế Unified Smart Login: nếu Admin đăng nhập nhầm ở cổng Client thì tự chuyển sang Guard Admin.
     */
    public static function laDinhDanhAdmin(string $dinhDanh): bool
    {
        $dinhDanh = trim($dinhDanh);
        if ($dinhDanh === '') {
            return false;
        }
        try {
            $pdo = Database::pdo();
            DbSchema::damBao($pdo);
            $st = $pdo->prepare(
                'SELECT 1 FROM ADMIN
                  WHERE TenDangNhap = ? OR (Email IS NOT NULL AND Email != "" AND LOWER(Email) = LOWER(?))
                  LIMIT 1'
            );
            $st->execute([$dinhDanh, $dinhDanh]);
            return (bool) $st->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Xác thực mã OTP Bước 2 (2FA) khi Admin đăng nhập trên thiết bị mới.
     */
    public static function xacThucOtpAdmin2FA(string $otpNhap, bool $tinCayThietBi = true): array
    {
        $flow = $_SESSION['admin_2fa_pending'] ?? null;
        if (!$flow || time() > (int) ($flow['expire'] ?? 0)) {
            unset($_SESSION['admin_2fa_pending']);
            return ['ok' => false, 'hetHan' => true, 'loi' => 'Phiên xác thực OTP đã hết hạn. Vui lòng đăng nhập lại.'];
        }

        $action = 'admin_2fa_verify';
        $idKey  = (string) $flow['tenDangNhap'];
        $hanChe = RateLimiter::kiemTra($action, $idKey);
        if ($hanChe['biKhoa']) {
            return [
                'ok'     => false,
                'hetHan' => false,
                'loi'    => 'Bạn đã nhập sai mã OTP quá 5 lần. Vui lòng thử lại sau '
                    . RateLimiter::dinhDangThoiGian($hanChe['thoiGianCon']) . '.',
            ];
        }

        $otpNhap = trim($otpNhap);
        if ($otpNhap === '') {
            return ['ok' => false, 'hetHan' => false, 'loi' => 'Vui lòng nhập mã OTP 6 số từ email.'];
        }

        if (!hash_equals((string) $flow['otp'], $otpNhap)) {
            $soLan  = RateLimiter::ghiNhanThatBai($action, $idKey);
            $conLai = max(0, 5 - $soLan);
            $loi    = $conLai > 0
                ? "Mã OTP không chính xác. Bạn còn {$conLai} lần thử."
                : 'Bạn đã nhập sai mã OTP 5 lần. Tài khoản tạm khóa xác thực trong 15 phút.';
            return ['ok' => false, 'hetHan' => false, 'loi' => $loi];
        }

        RateLimiter::xoa($action, $idKey);
        unset($_SESSION['admin_2fa_pending']);

        self::kichHoatPhienAdmin((int) $flow['maAdmin'], (string) $flow['matKhauHash'], $tinCayThietBi);
        return ['ok' => true, 'hetHan' => false, 'loi' => null];
    }

    /**
     * Gửi lại mã OTP 2FA cho Admin (cooldown 60 giây).
     */
    public static function guiLaiOtpAdmin2FA(): array
    {
        $flow = $_SESSION['admin_2fa_pending'] ?? null;
        if (!$flow || time() > (int) ($flow['expire'] ?? 0)) {
            unset($_SESSION['admin_2fa_pending']);
            return ['ok' => false, 'loi' => 'Phiên xác thực đã hết hạn. Vui lòng đăng nhập lại.'];
        }

        $now  = time();
        $last = (int) ($flow['last_sent'] ?? 0);
        if ($now - $last < 60) {
            $cho = 60 - ($now - $last);
            return ['ok' => false, 'loi' => "Vui lòng đợi {$cho} giây nữa trước khi yêu cầu gửi lại mã OTP."];
        }

        $hanChe = RateLimiter::kiemTra('admin_2fa_resend', (string) $flow['tenDangNhap']);
        if ($hanChe['biKhoa']) {
            return [
                'ok'  => false,
                'loi' => 'Bạn đã yêu cầu gửi lại mã quá nhiều lần. Vui lòng thử lại sau '
                    . RateLimiter::dinhDangThoiGian($hanChe['thoiGianCon']) . '.',
            ];
        }

        RateLimiter::ghiNhanThatBai('admin_2fa_resend', (string) $flow['tenDangNhap']);
        $otpMoi = (string) random_int(100000, 999999);
        $_SESSION['admin_2fa_pending']['otp']       = $otpMoi;
        $_SESSION['admin_2fa_pending']['expire']    = $now + 600;
        $_SESSION['admin_2fa_pending']['last_sent'] = $now;

        Mailer::guiOtpDangNhapAdmin((string) $flow['email'], (string) $flow['hoTen'], (string) $flow['tenDangNhap'], $otpMoi);
        return ['ok' => true, 'loi' => null];
    }

    /**
     * Đăng xuất an toàn theo từng phân vùng Guard ('client' hoặc 'admin').
     */
    public static function dangXuat(string $guard): void
    {
        if ($guard === 'admin') {
            unset($_SESSION['admin_id'], $_SESSION['admin_ua'], $_SESSION['admin_last_active'], $_SESSION['admin_2fa_pending']);
            if (isset($_SESSION['nd_la_admin'])) {
                unset($_SESSION['nd_id'], $_SESSION['nd_la_admin']);
            }
            self::$daKiemTraAdmin = false;
            self::$thongTinAdmin  = null;
        } else {
            unset($_SESSION['nd_id'], $_SESSION['nd_la_admin']);
            self::xoaCookie(self::COOKIE_REMEMBER_CLIENT);
            self::$daKiemTraClient = false;
            self::$thongTinClient  = null;
        }
        self::regenerate();
    }

    /** Ẩn bớt địa chỉ email khi hiển thị trên màn hình xác thực OTP (VD: bu***06@gmail.com). */
    public static function cheEmail(string $email): string
    {
        $parts = explode('@', trim($email));
        if (count($parts) !== 2) {
            return $email;
        }
        [$local, $domain] = $parts;
        $len = strlen($local);
        if ($len <= 3) {
            return substr($local, 0, 1) . '***@' . $domain;
        }
        return substr($local, 0, 2) . '***' . substr($local, -2) . '@' . $domain;
    }

    // =====================================================================
    // 3. CHUYỂN ADMIN SANG KHU SINH VIÊN
    // =====================================================================

    public static function vaoKhuSinhVien(PDO $pdo, int $maAdmin): void
    {
        $st = $pdo->prepare('SELECT MaND, HoTen FROM ADMIN WHERE MaAdmin = ?');
        $st->execute([$maAdmin]);
        $ad = $st->fetch();
        if (!$ad) {
            return;
        }
        $maND = $ad['MaND'] !== null ? (int) $ad['MaND'] : null;

        if ($maND === null) {
            $pdo->beginTransaction();
            try {
                $st = $pdo->prepare(
                    "INSERT INTO NGUOIDUNG (HoTen, Email, MatKhau) VALUES (?, ?, ?)"
                );
                $st->execute([
                    $ad['HoTen'],
                    sprintf('quantri-%d-%s@quantri.elo.local', $maAdmin, bin2hex(random_bytes(4))),
                    password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
                ]);
                $moi = (int) $pdo->lastInsertId();

                $st = $pdo->prepare('UPDATE ADMIN SET MaND = ? WHERE MaAdmin = ? AND MaND IS NULL');
                $st->execute([$moi, $maAdmin]);
                if ($st->rowCount() === 1) {
                    $pdo->commit();
                    $maND = $moi;
                } else {
                    $pdo->rollBack();
                    $st = $pdo->prepare('SELECT MaND FROM ADMIN WHERE MaAdmin = ?');
                    $st->execute([$maAdmin]);
                    $maND = (int) $st->fetchColumn();
                }
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $ex;
            }
        }

        self::regenerate();
        $_SESSION['nd_id']       = $maND;
        $_SESSION['nd_la_admin'] = $maAdmin;
        self::$daKiemTraClient   = false;
    }

    // =====================================================================
    // 4. CÁC HÀM NỘI BỘ: KIỂM TRA PHIÊN THỜI GIAN THỰC & COOKIE CHỮ KÝ HMAC
    // =====================================================================

    private static function kichHoatPhienAdmin(int $maAdmin, string $matKhauHash, bool $luuThietBiTinCay): void
    {
        self::regenerate();
        $_SESSION['admin_id']          = $maAdmin;
        $_SESSION['admin_ua']          = self::chuKyTrinhDuyet();
        $_SESSION['admin_last_active'] = time();
        self::$daKiemTraAdmin          = true;

        if ($luuThietBiTinCay) {
            self::datCookieToken(self::COOKIE_TRUST_ADMIN, 'adm_trust', $maAdmin, $matKhauHash, self::TRUST_TTL_ADMIN);
        }

        try {
            NhatKy::ghi('Đăng nhập quản trị', 'ADMIN #' . $maAdmin, 'Đăng nhập hệ thống quản trị thành công', 'Thành công', $maAdmin);
        } catch (Throwable) {
            // Không làm gián đoạn đăng nhập nếu bảng nhật ký chưa sẵn sàng
        }
    }

    private static function kichHoatPhienClient(int $maND, string $matKhauHash, bool $ghiNho): void
    {
        self::regenerate();
        $_SESSION['nd_id'] = $maND;
        unset($_SESSION['nd_la_admin'], $_SESSION['_client_bi_khoa']);
        self::$daKiemTraClient = true;

        if ($ghiNho) {
            self::datCookieToken(self::COOKIE_REMEMBER_CLIENT, 'cli_rem', $maND, $matKhauHash, self::REMEMBER_TTL_CLIENT);
        } else {
            self::xoaCookie(self::COOKIE_REMEMBER_CLIENT);
        }
    }

    /**
     * Đồng bộ & kiểm tra tính hợp lệ của phiên Admin trên mỗi request (chạy đúng 1 lần/request).
     */
    private static function dongBoPhienAdmin(): void
    {
        if (!isset($_SESSION['admin_id'])) {
            return;
        }

        // 1. Kiểm tra chữ ký trình duyệt (chống Session Hijacking)
        $uaHienTai = self::chuKyTrinhDuyet();
        if (!empty($_SESSION['admin_ua']) && !hash_equals((string) $_SESSION['admin_ua'], $uaHienTai)) {
            unset($_SESSION['admin_id'], $_SESSION['admin_ua'], $_SESSION['admin_last_active']);
            return;
        }

        // 2. Kiểm tra thời gian treo máy (Idle Timeout 60 phút)
        $now = time();
        if (!empty($_SESSION['admin_last_active']) && ($now - (int) $_SESSION['admin_last_active']) > self::ADMIN_IDLE_TIMEOUT) {
            unset($_SESSION['admin_id'], $_SESSION['admin_ua'], $_SESSION['admin_last_active']);
            return;
        }

        // 3. Kiểm tra trạng thái hoạt động thực tế trong bảng ADMIN
        $st = Database::pdo()->prepare('SELECT MaAdmin, HoTen, TrangThai FROM ADMIN WHERE MaAdmin = ?');
        $st->execute([(int) $_SESSION['admin_id']]);
        $ad = $st->fetch(PDO::FETCH_ASSOC);

        if (!$ad || ($ad['TrangThai'] ?? '') !== 'Hoạt động') {
            unset($_SESSION['admin_id'], $_SESSION['admin_ua'], $_SESSION['admin_last_active']);
            return;
        }

        $_SESSION['admin_ua']          = $uaHienTai;
        $_SESSION['admin_last_active'] = $now;
        self::$thongTinAdmin           = $ad;
    }

    /**
     * Đồng bộ & kiểm tra tính hợp lệ của phiên Client trên mỗi request (chạy đúng 1 lần/request).
     * Hỗ trợ tự động khôi phục từ Cookie "Ghi nhớ đăng nhập" và thu hồi phiên ngay nếu bị khóa.
     */
    private static function dongBoPhienClient(): void
    {
        $pdo = Database::pdo();

        // Trường hợp 1: Phiên sinh viên do Admin chuyển sang ("Về trang sinh viên")
        if (isset($_SESSION['nd_id']) && isset($_SESSION['nd_la_admin'])) {
            $st = $pdo->prepare('SELECT TrangThai, MaND FROM ADMIN WHERE MaAdmin = ?');
            $st->execute([(int) $_SESSION['nd_la_admin']]);
            $ad = $st->fetch(PDO::FETCH_ASSOC);
            if (!$ad || ($ad['TrangThai'] ?? '') !== 'Hoạt động') {
                unset($_SESSION['nd_id'], $_SESSION['nd_la_admin']);
                return;
            }
            if ((int) $ad['MaND'] !== (int) $_SESSION['nd_id']) {
                unset($_SESSION['nd_la_admin']);
            } else {
                return;
            }
        }

        // Trường hợp 2: Chưa có Session nhưng có Cookie "Ghi nhớ đăng nhập" hợp lệ
        if (!isset($_SESSION['nd_id']) && !empty($_COOKIE[self::COOKIE_REMEMBER_CLIENT])) {
            $maND = self::xacThucCookieToken(self::COOKIE_REMEMBER_CLIENT, 'cli_rem', 'NGUOIDUNG', 'MaND');
            if ($maND !== null) {
                self::regenerate();
                $_SESSION['nd_id'] = $maND;
            } else {
                self::xoaCookie(self::COOKIE_REMEMBER_CLIENT);
            }
        }

        // Trường hợp 3: Đã có $_SESSION['nd_id'] -> Kiểm tra tài khoản còn hoạt động hay đã bị khóa
        if (isset($_SESSION['nd_id'])) {
            $st = $pdo->prepare('SELECT MaND, HoTen, Email, TrangThai FROM NGUOIDUNG WHERE MaND = ?');
            $st->execute([(int) $_SESSION['nd_id']]);
            $nd = $st->fetch(PDO::FETCH_ASSOC);

            if (!$nd) {
                unset($_SESSION['nd_id'], $_SESSION['nd_la_admin']);
                self::xoaCookie(self::COOKIE_REMEMBER_CLIENT);
                return;
            }

            if (($nd['TrangThai'] ?? '') === 'Bị khóa') {
                // Đánh dấu thông báo khóa đã đọc và thu hồi phiên ngay lập tức
                try {
                    $pdo->prepare("UPDATE THONGBAO SET DaDoc = 1 WHERE MaND = ? AND Loai = 'khoa_tk'")
                        ->execute([(int) $nd['MaND']]);
                } catch (Throwable) {
                }
                unset($_SESSION['nd_id'], $_SESSION['nd_la_admin']);
                self::xoaCookie(self::COOKIE_REMEMBER_CLIENT);
                $_SESSION['_client_bi_khoa'] = true;
                return;
            }

            self::$thongTinClient = $nd;
        }
    }

    private static function chuKyTrinhDuyet(): string
    {
        return hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown_agent'));
    }

    private static function khoaBiMat(): string
    {
        $cfg = $GLOBALS['APP_CONFIG'] ?? [];
        return (string) ($cfg['app_secret'] ?? 'quizup-arena-hmac-secret-key-2026-v2');
    }

    /**
     * Tạo Cookie bảo mật có chữ ký HMAC-SHA256 gắn với Hash mật khẩu hiện tại và User-Agent.
     * Khi người dùng đổi mật khẩu, chữ ký tự động vô hiệu trên mọi thiết bị cũ!
     */
    private static function datCookieToken(string $tenCookie, string $loai, int $id, string $matKhauHash, int $ttl): void
    {
        if (headers_sent()) {
            return;
        }
        $hetHan  = time() + $ttl;
        $payload = "{$loai}|{$id}|{$hetHan}";
        $chuKy   = hash_hmac('sha256', $payload . '|' . $matKhauHash . '|' . self::chuKyTrinhDuyet(), self::khoaBiMat());
        $giaTri  = base64_encode("{$id}:{$hetHan}:{$chuKy}");

        setcookie($tenCookie, $giaTri, [
            'expires'  => $hetHan,
            'path'     => BASE_URL ?: '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        ]);
    }

    private static function xoaCookie(string $tenCookie): void
    {
        unset($_COOKIE[$tenCookie]);
        if (!headers_sent()) {
            setcookie($tenCookie, '', [
                'expires'  => time() - 3600,
                'path'     => BASE_URL ?: '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
            ]);
        }
    }

    private static function laThietBiAdminTinCay(int $maAdmin, string $matKhauHash): bool
    {
        $raw = (string) ($_COOKIE[self::COOKIE_TRUST_ADMIN] ?? '');
        if ($raw === '') {
            return false;
        }
        $decoded = base64_decode($raw, true);
        if (!is_string($decoded)) {
            return false;
        }
        $parts = explode(':', $decoded);
        if (count($parts) !== 3) {
            return false;
        }
        [$idStr, $hetHanStr, $chuKy] = $parts;
        $id     = (int) $idStr;
        $hetHan = (int) $hetHanStr;

        if ($id !== $maAdmin || $hetHan < time()) {
            return false;
        }
        $payload  = "adm_trust|{$id}|{$hetHan}";
        $kyVong   = hash_hmac('sha256', $payload . '|' . $matKhauHash . '|' . self::chuKyTrinhDuyet(), self::khoaBiMat());
        return hash_equals($kyVong, $chuKy);
    }

    private static function xacThucCookieToken(string $tenCookie, string $loai, string $bang, string $cotId): ?int
    {
        $raw = (string) ($_COOKIE[$tenCookie] ?? '');
        if ($raw === '') {
            return null;
        }
        $decoded = base64_decode($raw, true);
        if (!is_string($decoded)) {
            return null;
        }
        $parts = explode(':', $decoded);
        if (count($parts) !== 3) {
            return null;
        }
        [$idStr, $hetHanStr, $chuKy] = $parts;
        $id     = (int) $idStr;
        $hetHan = (int) $hetHanStr;

        if ($id <= 0 || $hetHan < time()) {
            return null;
        }

        $st = Database::pdo()->prepare("SELECT MatKhau, TrangThai FROM {$bang} WHERE {$cotId} = ?");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || ($row['TrangThai'] ?? '') !== 'Hoạt động') {
            return null;
        }

        $payload = "{$loai}|{$id}|{$hetHan}";
        $kyVong  = hash_hmac('sha256', $payload . '|' . $row['MatKhau'] . '|' . self::chuKyTrinhDuyet(), self::khoaBiMat());
        return hash_equals($kyVong, $chuKy) ? $id : null;
    }
}

// Tương thích ngược: Định danh Auth trỏ tới XacThuc
if (!class_exists('Auth', false)) {
    class_alias('XacThuc', 'Auth');
}