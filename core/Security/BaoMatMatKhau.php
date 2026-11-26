<?php
// Băm mật khẩu (Argon2id / Bcrypt cost 12), dummy verify chống timing attack và kiểm tra độ mạnh.
class BaoMatMatKhau
{
    private const ARGON2_MEMORY_COST = 65536; // 64 MB
    private const ARGON2_TIME_COST   = 4;
    private const ARGON2_THREADS     = 2;
    private const BCRYPT_COST        = 12;

    // Dummy hash dùng verify giả lập khi tài khoản không tồn tại (chống timing attack)
    private const DUMMY_HASH = '$2y$12$e8wVf0fG0V5cR4yM3qH.eOG4O5F1f.F9Z6Z6Z6Z6Z6Z6Z6Z6Z6Z6.';

    // Danh sách mật khẩu phổ biến bị chặn
    private const MAT_KHAU_YEU = [
        '123456', '12345678', '123456789', '1234567890', 'password', 'Password',
        'Password123', 'Password@123', 'admin123', 'Admin123', 'Admin@123',
        'qwerty', 'qwertyuiop', '111111', '11111111', '123123', 'matkhau',
        'Matkhau123', 'Matkhau@123', 'iloveyou', 'sunshine', 'princess',
        'football', 'monkey', 'dragon', 'master', 'welcome', 'welcome123',
        'Eloquiz', 'Eloquiz123', 'Eloquiz@123', 'Quizup123', 'Quizup@123'
    ];

    /** Băm mật khẩu (ưu tiên Argon2id, fallback Bcrypt cost 12). */
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

    /** Xác thực mật khẩu với hash. */
    public static function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /** Verify giả lập khi tài khoản không tồn tại để cân bằng thời gian xử lý (chống timing attack). */
    public static function dummyVerify(string $password): void
    {
        password_verify($password, self::DUMMY_HASH);
    }

    /** Kiểm tra hash hiện tại có cần nâng cấp cấu hình mới hay không. */
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

    /** Nâng cấp hash trong CSDL nếu hash cũ đã lỗi thời. */
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
     * Kiểm tra độ mạnh mật khẩu (8-72 ký tự, thường/hoa/số/đặc biệt, không lặp/tuần tự/trùng tài khoản).
     * @return array Danh sách thông báo lỗi (rỗng nếu hợp lệ).
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

    /** Định dạng khoảng thời gian tiếng Việt. */
    public static function tinhKhoangCachThoiGian(string|int $time): string
    {
        $timestamp = is_numeric($time) ? (int) $time : strtotime($time);
        if (!$timestamp) return 'gần đây';
        $diff = max(0, time() - $timestamp);
        if ($diff < 60) return 'vài giây';
        if ($diff < 3600) return floor($diff / 60) . ' phút';
        if ($diff < 86400) return floor($diff / 3600) . ' giờ';
        if ($diff < 2592000) return floor($diff / 86400) . ' ngày';
        if ($diff < 31536000) {
            $thang = (int) floor($diff / 2592000);
            return $thang . ' tháng';
        }
        $nam = (int) floor($diff / 31536000);
        return $nam . ' năm';
    }

    /**
     * Kiểm tra mật khẩu mới với mật khẩu hiện tại và 3 mật khẩu gần nhất.
     * @return array|null Thông tin cảnh báo nếu trùng, hoặc null nếu hợp lệ.
     */
    public static function kiemTraLichSu(PDO $pdo, int $maND, string $matKhauMoi, ?array $nd = null): ?array
    {
        return self::kiemTraLichSuChung(
            $pdo,
            'nguoidung',
            'MaND',
            'lichsu_matkhau',
            $maND,
            $matKhauMoi,
            $nd,
            'mật khẩu bạn đang sử dụng',
            'Mật khẩu này bạn đã từng sử dụng gần đây'
        );
    }

    /** Kiểm tra lịch sử mật khẩu quản trị (hiện tại và 3 lần gần nhất). */
    public static function kiemTraLichSuAdmin(PDO $pdo, int $maAdmin, string $matKhauMoi, ?array $ad = null): ?array
    {
        return self::kiemTraLichSuChung(
            $pdo,
            'ADMIN',
            'MaAdmin',
            'LICHSU_MATKHAU_ADMIN',
            $maAdmin,
            $matKhauMoi,
            $ad,
            'mật khẩu quản trị đang sử dụng',
            'Mật khẩu quản trị này đã từng được sử dụng gần đây'
        );
    }

    /** Logic dùng chung kiểm tra mật khẩu hiện tại và 3 mật khẩu gần nhất. */
    private static function kiemTraLichSuChung(
        PDO $pdo,
        string $bangTaiKhoan,
        string $cotId,
        string $bangLichSu,
        int $id,
        string $matKhauMoi,
        ?array $dongHienTai,
        string $nhanHienTai,
        string $nhanLichSu
    ): ?array {
        if ($dongHienTai === null) {
            $st = $pdo->prepare("SELECT MatKhau, NgayDoiMatKhau, NgayTao FROM {$bangTaiKhoan} WHERE {$cotId} = ?");
            $st->execute([$id]);
            $dongHienTai = $st->fetch(PDO::FETCH_ASSOC);
        }

        if (!$dongHienTai || empty($dongHienTai['MatKhau'])) {
            return null;
        }

        // Trùng mật khẩu hiện tại
        if (self::verify($matKhauMoi, $dongHienTai['MatKhau'])) {
            $thoiDiem   = $dongHienTai['NgayDoiMatKhau'] ?: ($dongHienTai['NgayTao'] ?: date('Y-m-d H:i:s'));
            $khoangCach = self::tinhKhoangCachThoiGian($thoiDiem);
            $ngayStr    = date('d/m/Y', strtotime($thoiDiem));

            return [
                'loai'          => 'hien_tai',
                'ngay'          => $ngayStr,
                'khoangCach'    => $khoangCach,
                'thongBao'      => "Mật khẩu mới không được trùng với {$nhanHienTai} (được đặt từ ngày {$ngayStr} - cách đây {$khoangCach}). Có vẻ bạn vẫn nhớ mật khẩu này? Bạn có thể đăng nhập ngay.",
                'coTheDangNhap' => true,
            ];
        }

        // Trùng 3 mật khẩu gần nhất trong lịch sử
        try {
            $st = $pdo->prepare(
                "SELECT MatKhauHash, NgayTao FROM {$bangLichSu} WHERE {$cotId} = ? ORDER BY NgayTao DESC, MaLS DESC LIMIT 3"
            );
            $st->execute([$id]);
            $lichSu = $st->fetchAll(PDO::FETCH_ASSOC);

            foreach ($lichSu as $ls) {
                if (self::verify($matKhauMoi, $ls['MatKhauHash'])) {
                    $khoangCach = self::tinhKhoangCachThoiGian($ls['NgayTao']);
                    $ngayStr    = date('d/m/Y', strtotime($ls['NgayTao']));

                    return [
                        'loai'          => 'lich_su',
                        'ngay'          => $ngayStr,
                        'khoangCach'    => $khoangCach,
                        'thongBao'      => "{$nhanLichSu} (ngày {$ngayStr} - cách đây {$khoangCach}). Vì an toàn bảo mật, vui lòng không sử dụng lại 3 mật khẩu gần nhất.",
                        'coTheDangNhap' => false,
                    ];
                }
            }
        } catch (Throwable) {
            // Bỏ qua lỗi nếu bảng lịch sử chưa sẵn sàng
        }

        return null;
    }

    /** Ghi nhận đổi mật khẩu cho sinh viên. */
    public static function ghiNhanDoiMatKhau(PDO $pdo, int $maND, string $hashMoi): void
    {
        self::ghiNhanDoiMatKhauChung($pdo, 'nguoidung', 'MaND', 'lichsu_matkhau', $maND, $hashMoi);
    }

    /** Ghi nhận đổi/khôi phục mật khẩu cho quản trị viên (kèm cập nhật Email nếu có). */
    public static function ghiNhanDoiMatKhauAdmin(PDO $pdo, int $maAdmin, string $hashMoi, ?string $emailCapNhat = null): void
    {
        self::ghiNhanDoiMatKhauChung($pdo, 'ADMIN', 'MaAdmin', 'LICHSU_MATKHAU_ADMIN', $maAdmin, $hashMoi, $emailCapNhat);
    }

    /** Lưu hash mật khẩu cũ vào lịch sử (tối đa 5 bản ghi) và cập nhật mật khẩu mới. */
    private static function ghiNhanDoiMatKhauChung(
        PDO $pdo,
        string $bangTaiKhoan,
        string $cotId,
        string $bangLichSu,
        int $id,
        string $hashMoi,
        ?string $emailCapNhat = null
    ): void {
        try {
            $st = $pdo->prepare("SELECT MatKhau, NgayDoiMatKhau, NgayTao FROM {$bangTaiKhoan} WHERE {$cotId} = ?");
            $st->execute([$id]);
            $cur = $st->fetch(PDO::FETCH_ASSOC);

            if ($cur && !empty($cur['MatKhau'])) {
                $ngayCu = $cur['NgayDoiMatKhau'] ?: ($cur['NgayTao'] ?: date('Y-m-d H:i:s'));
                $ins = $pdo->prepare("INSERT INTO {$bangLichSu} ({$cotId}, MatKhauHash, NgayTao) VALUES (?, ?, ?)");
                $ins->execute([$id, $cur['MatKhau'], $ngayCu]);

                // Giữ tối đa 5 bản ghi gần nhất trong lịch sử
                $del = $pdo->prepare(
                    "DELETE FROM {$bangLichSu}
                      WHERE {$cotId} = ? AND MaLS NOT IN (
                          SELECT MaLS FROM (
                              SELECT MaLS FROM {$bangLichSu} WHERE {$cotId} = ? ORDER BY NgayTao DESC, MaLS DESC LIMIT 5
                          ) t
                      )"
                );
                $del->execute([$id, $id]);
            }
        } catch (Throwable) {
            // Bỏ qua lỗi lưu lịch sử nếu có sự cố
        }

        if ($emailCapNhat !== null && $emailCapNhat !== '') {
            $up = $pdo->prepare("UPDATE {$bangTaiKhoan} SET MatKhau = ?, NgayDoiMatKhau = NOW(), Email = ? WHERE {$cotId} = ?");
            $up->execute([$hashMoi, $emailCapNhat, $id]);
        } else {
            $up = $pdo->prepare("UPDATE {$bangTaiKhoan} SET MatKhau = ?, NgayDoiMatKhau = NOW() WHERE {$cotId} = ?");
            $up->execute([$hashMoi, $id]);
        }
    }
}

// Alias giữ tương thích tên lớp cũ
if (!class_exists('PasswordSecurity', false)) {
    class_alias('BaoMatMatKhau', 'PasswordSecurity');
}
