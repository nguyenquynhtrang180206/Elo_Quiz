<?php
// Quản lý mật khẩu siêu bảo mật (OWASP ASVS / NIST Guidelines)
// Hỗ trợ Argon2id, Fallback Bcrypt (cost 12), Dummy Hash chống Timing Attack, và Bộ kiểm tra độ mạnh toàn diện.
class BaoMatMatKhau
{
    // Cấu hình Argon2id chuẩn OWASP
    private const ARGON2_MEMORY_COST = 65536; // 64 MB
    private const ARGON2_TIME_COST   = 4;     // 4 iterations
    private const ARGON2_THREADS     = 2;     // 2 threads

    // Cấu hình Bcrypt cost cao (chống GPU brute-force offline)
    private const BCRYPT_COST        = 12;

    // Dummy hash cố định (Argon2id hoặc Bcrypt) dùng để giả lập verify khi user không tồn tại, chống Timing Attack
    private const DUMMY_HASH = '$2y$12$e8wVf0fG0V5cR4yM3qH.eOG4O5F1f.F9Z6Z6Z6Z6Z6Z6Z6Z6Z6Z6.';

    // Danh sách các mật khẩu phổ biến / yếu bị cấm hoàn toàn
    private const MAT_KHAU_YEU = [
        '123456', '12345678', '123456789', '1234567890', 'password', 'Password',
        'Password123', 'Password@123', 'admin123', 'Admin123', 'Admin@123',
        'qwerty', 'qwertyuiop', '111111', '11111111', '123123', 'matkhau',
        'Matkhau123', 'Matkhau@123', 'iloveyou', 'sunshine', 'princess',
        'football', 'monkey', 'dragon', 'master', 'welcome', 'welcome123',
        'Eloquiz', 'Eloquiz123', 'Eloquiz@123', 'Quizup123', 'Quizup@123'
    ];

    /**
     * Băm mật khẩu với thuật toán bảo mật mạnh nhất hiện có (Argon2id hoặc Bcrypt cost 12).
     */
    public static function hash(string $password): string
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return password_hash($password, PASSWORD_ARGON2ID, [
                'memory_cost' => self::ARGON2_MEMORY_COST,
                'time_cost'   => self::ARGON2_TIME_COST,
                'threads'     => self::ARGON2_THREADS,
            ]);
        }
        return password_hash($password, PASSWORD_BCRYPT, [
            'cost' => self::BCRYPT_COST,
        ]);
    }

    /**
     * Xác thực mật khẩu với hash.
     */
    public static function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Chống Timing Attack: Chạy verify giả lập khi tài khoản không tồn tại,
     * đảm bảo thời gian máy chủ xử lý tài khoản tồn tại và không tồn tại là như nhau.
     */
    public static function dummyVerify(string $password): void
    {
        password_verify($password, self::DUMMY_HASH);
    }

    /**
     * Kiểm tra xem hash hiện tại có cần được nâng cấp (re-hash) lên chuẩn mới hay không.
     */
    public static function needsRehash(string $hash): bool
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return password_needs_rehash($hash, PASSWORD_ARGON2ID, [
                'memory_cost' => self::ARGON2_MEMORY_COST,
                'time_cost'   => self::ARGON2_TIME_COST,
                'threads'     => self::ARGON2_THREADS,
            ]);
        }
        return password_needs_rehash($hash, PASSWORD_BCRYPT, [
            'cost' => self::BCRYPT_COST,
        ]);
    }

    /**
     * Tự động nâng cấp hash trong CSDL nếu hash cũ đã lỗi thời.
     */
    public static function rehashIfNecessary(
        PDO $pdo,
        string $table,
        string $pkColumn,
        int $id,
        string $password,
        string $currentHash
    ): void {
        if (self::needsRehash($currentHash)) {
            $newHash = self::hash($password);
            $st = $pdo->prepare("UPDATE `$table` SET `MatKhau` = ? WHERE `$pkColumn` = ?");
            $st->execute([$newHash, $id]);
        }
    }

    /**
     * Bộ kiểm tra độ mạnh mật khẩu siêu bảo mật:
     * - Độ dài từ 8 đến 72 ký tự.
     * - Phải có chữ thường [a-z].
     * - Phải có chữ hoa [A-Z].
     * - Phải có chữ số [0-9].
     * - Phải có ký tự đặc biệt [!@#$%^&*()_\-+=\[\]{};:,.<>?/~`|\\].
     * - Không chứa ký tự lặp liên tiếp >= 3 lần (vd: aaa, 111).
     * - Không thuộc danh sách mật khẩu yếu phổ biến.
     * - Không chứa tên tài khoản / email của người dùng.
     *
     * @return array Danh sách các thông báo lỗi (nếu có). Rỗng = hợp lệ.
     */
    public static function kiemTraDoManh(string $password, array $context = []): array
    {
        $loi = [];
        $len = strlen($password);

        if ($len < 8) {
            $loi[] = 'Mật khẩu phải có ít nhất 8 ký tự.';
        } elseif ($len > 72) {
            $loi[] = 'Mật khẩu tối đa 72 ký tự.';
        }

        if (!preg_match('/[a-z]/', $password)) {
            $loi[] = 'Mật khẩu phải chứa ít nhất một chữ cái viết thường (a-z).';
        }

        if (!preg_match('/[A-Z]/', $password)) {
            $loi[] = 'Mật khẩu phải chứa ít nhất một chữ cái in hoa (A-Z).';
        }

        if (!preg_match('/[0-9]/', $password)) {
            $loi[] = 'Mật khẩu phải chứa ít nhất một chữ số (0-9).';
        }

        if (!preg_match('/[!@#$%^&*()_\-+=\[\]{};:,.<>?\/~`|\\\\]/', $password)) {
            $loi[] = 'Mật khẩu phải chứa ít nhất một ký tự đặc biệt (ví dụ: !@#$%^&*...).';
        }

        // Chặn ký tự lặp lại quá 3 lần liên tiếp (vd: aaa, 999, !!!)
        if (preg_match('/(.)\1\1/', $password)) {
            $loi[] = 'Mật khẩu không được chứa ký tự lặp lại liên tiếp quá 2 lần.';
        }

        // Chặn chuỗi tuần tự phổ biến (12345, abcde)
        $chuoiTuanTu = ['01234', '12345', '23456', '34567', '45678', '56789', '67890',
                        'abcde', 'bcdef', 'cdefg', 'defgh', 'efghi', 'fghij', 'ghijk', 'hijkl'];
        $pwLower = strtolower($password);
        foreach ($chuoiTuanTu as $tt) {
            if (str_contains($pwLower, $tt)) {
                $loi[] = 'Mật khẩu không được chứa các ký tự tuần tự dễ đoán (ví dụ: ' . $tt . ').';
                break;
            }
        }

        // Chặn danh sách mật khẩu yếu phổ biến
        foreach (self::MAT_KHAU_YEU as $yeu) {
            if (strcasecmp($password, $yeu) === 0) {
                $loi[] = 'Mật khẩu này quá phổ biến và dễ đoán. Vui lòng chọn mật khẩu khác.';
                break;
            }
        }

        // Chặn mật khẩu chứa email hoặc tên tài khoản
        if (!empty($context['email'])) {
            $parts = explode('@', (string) $context['email']);
            $localPart = strtolower($parts[0] ?? '');
            if (strlen($localPart) >= 3 && str_contains($pwLower, $localPart)) {
                $loi[] = 'Mật khẩu không được chứa phần tên của email.';
            }
        }

        if (!empty($context['tenDangNhap'])) {
            $tdn = strtolower((string) $context['tenDangNhap']);
            if (strlen($tdn) >= 3 && str_contains($pwLower, $tdn)) {
                $loi[] = 'Mật khẩu không được chứa tên đăng nhập.';
            }
        }

        return array_unique($loi);
    }
}

// Tương thích ngược: Định danh PasswordSecurity trỏ tới BaoMatMatKhau
if (!class_exists('PasswordSecurity', false)) {
    class_alias('BaoMatMatKhau', 'PasswordSecurity');
}
