<?php
// Giới hạn tần suất đăng nhập (khóa 15 phút sau 5 lần sai liên tiếp) và ghi LICHSUDANGNHAP.
class GioiHanTanSuat
{
    private const SO_LAN_TOI_DA  = 5;
    private const THOI_GIAN_KHOA = 900; // 15 phút
    private static bool $daTaoBang = false;

    private static function damBaoBang(): void
    {
        if (self::$daTaoBang) {
            return;
        }
        self::$daTaoBang = true;
        try {
            $pdo = Database::pdo();
            $pdo->exec("CREATE TABLE IF NOT EXISTS GIOIHANDANGNHAP (
                Khoa      CHAR(64)     NOT NULL,
                HanhDong  VARCHAR(30)  NOT NULL,
                DinhDanh  VARCHAR(150) NOT NULL,
                DiaChiIP  VARCHAR(45)  NOT NULL,
                SoLanSai  INT UNSIGNED NOT NULL DEFAULT 0,
                LanCuoi   DATETIME     NOT NULL,
                PRIMARY KEY (Khoa),
                KEY idx_ghdn_lancuoi (LanCuoi)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $pdo->exec("CREATE TABLE IF NOT EXISTS LICHSUDANGNHAP (
                MaLS        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                HanhDong    VARCHAR(30)  NOT NULL,
                DinhDanh    VARCHAR(150) NOT NULL,
                DiaChiIP    VARCHAR(45)  NOT NULL,
                TrinhDuyet  VARCHAR(255) NULL,
                KetQua      ENUM('Thành công','Thất bại','Bị khóa') NOT NULL,
                ThoiGian    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (MaLS),
                KEY idx_lsdn_dinhdanh (DinhDanh, ThoiGian),
                KEY idx_lsdn_thoigian (ThoiGian)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (Throwable $e) {
            error_log('GioiHanTanSuat::damBaoBang: ' . $e->getMessage());
        }
    }

    private static function laLoiThieuBang(PDOException $e): bool
    {
        return $e->getCode() === '42S02' || (int) ($e->errorInfo[1] ?? 0) === 1146;
    }

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
        try {
            Database::pdo()->prepare('DELETE FROM GIOIHANDANGNHAP WHERE Khoa = ?')->execute([$key]);
        } catch (PDOException $e) {
            if (self::laLoiThieuBang($e)) {
                self::damBaoBang();
                return;
            }
            error_log('GioiHanTanSuat::datLai: ' . $e->getMessage());
        }
    }

    /**
     * @return array ['biKhoa' => bool, 'thoiGianCon' => int, 'soLanSai' => int, 'conLai' => int]
     */
    public static function kiemTra(string $action, string $identifier): array
    {
        $key  = self::taoKhoa($action, $identifier);
        $tuDo = ['biKhoa' => false, 'thoiGianCon' => 0, 'soLanSai' => 0, 'conLai' => self::SO_LAN_TOI_DA];
        $sql  = 'SELECT SoLanSai, TIMESTAMPDIFF(SECOND, LanCuoi, NOW()) AS DaQua FROM GIOIHANDANGNHAP WHERE Khoa = ?';

        try {
            $st = Database::pdo()->prepare($sql);
            $st->execute([$key]);
            $r = $st->fetch();
        } catch (PDOException $e) {
            if (!self::laLoiThieuBang($e)) {
                error_log('GioiHanTanSuat::kiemTra: ' . $e->getMessage());
                return $tuDo;
            }
            self::damBaoBang();
            try {
                $st = Database::pdo()->prepare($sql);
                $st->execute([$key]);
                $r = $st->fetch();
            } catch (Throwable) {
                return $tuDo;
            }
        }

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
        $pdo    = Database::pdo();
        $key    = self::taoKhoa($action, $identifier);
        $sql    = 'INSERT INTO GIOIHANDANGNHAP (Khoa, HanhDong, DinhDanh, DiaChiIP, SoLanSai, LanCuoi)
                   VALUES (?, ?, ?, ?, 1, NOW())
                   ON DUPLICATE KEY UPDATE SoLanSai = SoLanSai + 1, LanCuoi = NOW()';
        $params = [$key, $action, self::chuanHoa($identifier), self::layIP()];

        try {
            $pdo->prepare($sql)->execute($params);
        } catch (PDOException $e) {
            if (!self::laLoiThieuBang($e)) {
                error_log('GioiHanTanSuat::ghiNhanThatBai: ' . $e->getMessage());
                return 1;
            }
            self::damBaoBang();
            try {
                $pdo->prepare($sql)->execute($params);
            } catch (Throwable) {
                return 1;
            }
        }
        self::ghiLichSu($action, $identifier, 'Thất bại');

        try {
            $st = $pdo->prepare('SELECT SoLanSai FROM GIOIHANDANGNHAP WHERE Khoa = ?');
            $st->execute([$key]);
            return (int) $st->fetchColumn();
        } catch (Throwable) {
            return 1;
        }
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

// Alias giữ tương thích tên lớp cũ
if (!class_exists('RateLimiter', false)) {
    class_alias('GioiHanTanSuat', 'RateLimiter');
}
