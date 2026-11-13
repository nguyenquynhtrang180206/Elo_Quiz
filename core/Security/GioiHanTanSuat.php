<?php
// Hệ thống chống dò mật khẩu Brute-force & Credential Stuffing (lưu trong CSDL).
// Khóa tạm thời 15 phút sau 5 lần nhập sai liên tiếp. Mọi lượt đăng nhập được ghi vào LICHSUDANGNHAP.
class GioiHanTanSuat
{
    private const SO_LAN_TOI_DA  = 5;
    private const THOI_GIAN_KHOA = 900; // 15 phút

    private static function layIP(): string
    {
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'), 0, 45);
    }

    private static function chuanHoa(string $identifier): string
    {
        return mb_substr(mb_strtolower(trim($identifier)), 0, 150);
    }

    private static function taoKhoa(string $action, string $identifier): string
    {
        return hash('sha256', $action . '|' . self::layIP() . '|' . self::chuanHoa($identifier));
    }

    /** Ghi một dòng lịch sử đăng nhập (không bao giờ làm gián đoạn luồng đăng nhập). */
    private static function ghiLichSu(string $action, string $identifier, string $ketQua): void
    {
        try {
            Database::pdo()->prepare(
                'INSERT INTO LICHSUDANGNHAP (HanhDong, DinhDanh, DiaChiIP, TrinhDuyet, KetQua) VALUES (?, ?, ?, ?, ?)'
            )->execute([
                $action, self::chuanHoa($identifier), self::layIP(),
                mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), $ketQua,
            ]);
        } catch (Throwable $e) {
            error_log('GioiHanTanSuat log: ' . $e->getMessage());
        }
    }

    private static function datLai(string $key): void
    {
        Database::pdo()->prepare('DELETE FROM GIOIHANDANGNHAP WHERE Khoa = ?')->execute([$key]);
    }

    /**
     * @return array ['biKhoa' => bool, 'thoiGianCon' => int, 'soLanSai' => int, 'conLai' => int]
     */
    public static function kiemTra(string $action, string $identifier): array
    {
        $key = self::taoKhoa($action, $identifier);
        $st  = Database::pdo()->prepare(
            'SELECT SoLanSai, TIMESTAMPDIFF(SECOND, LanCuoi, NOW()) AS DaQua FROM GIOIHANDANGNHAP WHERE Khoa = ?'
        );
        $st->execute([$key]);
        $r = $st->fetch();
        $tuDo = ['biKhoa' => false, 'thoiGianCon' => 0, 'soLanSai' => 0, 'conLai' => self::SO_LAN_TOI_DA];

        if (!$r) {
            return $tuDo;
        }
        $soLan = (int) $r['SoLanSai'];
        $daQua = (int) $r['DaQua'];

        if ($daQua > self::THOI_GIAN_KHOA) {   // quá 15 phút kể từ lần sai cuối: tự mở khóa
            self::datLai($key);
            return $tuDo;
        }
        if ($soLan >= self::SO_LAN_TOI_DA) {
            self::ghiLichSu($action, $identifier, 'Bị khóa');
            return ['biKhoa' => true, 'thoiGianCon' => self::THOI_GIAN_KHOA - $daQua, 'soLanSai' => $soLan, 'conLai' => 0];
        }
        return ['biKhoa' => false, 'thoiGianCon' => 0, 'soLanSai' => $soLan, 'conLai' => self::SO_LAN_TOI_DA - $soLan];
    }

    /** Ghi nhận 1 lần đăng nhập thất bại, trả về tổng số lần sai hiện tại. */
    public static function ghiNhanThatBai(string $action, string $identifier): int
    {
        $pdo = Database::pdo();
        $key = self::taoKhoa($action, $identifier);
        $pdo->prepare(
            'INSERT INTO GIOIHANDANGNHAP (Khoa, HanhDong, DinhDanh, DiaChiIP, SoLanSai, LanCuoi)
             VALUES (?, ?, ?, ?, 1, NOW())
             ON DUPLICATE KEY UPDATE SoLanSai = SoLanSai + 1, LanCuoi = NOW()'
        )->execute([$key, $action, self::chuanHoa($identifier), self::layIP()]);
        self::ghiLichSu($action, $identifier, 'Thất bại');

        $st = $pdo->prepare('SELECT SoLanSai FROM GIOIHANDANGNHAP WHERE Khoa = ?');
        $st->execute([$key]);
        return (int) $st->fetchColumn();
    }

    /** Đăng nhập thành công: xóa bộ đếm và ghi lịch sử. */
    public static function xoa(string $action, string $identifier): void
    {
        self::datLai(self::taoKhoa($action, $identifier));
        self::ghiLichSu($action, $identifier, 'Thành công');
    }

    public static function dinhDangThoiGian(int $giay): string
    {
        if ($giay <= 0) {
            return '0 giây';
        }
        $phut = (int) floor($giay / 60);
        $du   = $giay % 60;
        if ($phut === 0) {
            return $du . ' giây';
        }
        if ($du === 0) {
            return $phut . ' phút';
        }
        return $phut . ' phút ' . $du . ' giây';
    }
}

// Tương thích ngược: Định danh RateLimiter trỏ tới GioiHanTanSuat
if (!class_exists('RateLimiter', false)) {
    class_alias('GioiHanTanSuat', 'RateLimiter');
}
