<?php
// Quản lý phòng thách đấu 1v1 riêng tư (Custom Match / Mã PIN)
// Cho phép bạn bè nhập mã PIN hoặc click link để thi đấu trực tiếp.
class ThachDau
{
    private static bool $daKhoiTaoBang = false;

    /**
     * Tự động khởi tạo bảng PHONGTHACHDAU nếu chưa có trong CSDL.
     */
    public static function khoiTaoBang(PDO $pdo): void
    {
        if (self::$daKhoiTaoBang) {
            return;
        }
        $sql = "CREATE TABLE IF NOT EXISTS PHONGTHACHDAU (
            MaPhong      INT UNSIGNED NOT NULL AUTO_INCREMENT,
            MaPin        VARCHAR(10) NOT NULL,
            ChuPhongND   INT UNSIGNED NOT NULL,
            KhachND      INT UNSIGNED NULL,
            MaMon        INT UNSIGNED NOT NULL,
            MaLoaiKT     INT UNSIGNED NOT NULL,
            MaTran       INT UNSIGNED NULL,
            TrangThai    ENUM('Chờ đối thủ', 'Đã ghép', 'Đã hủy') NOT NULL DEFAULT 'Chờ đối thủ',
            NgayTao      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (MaPhong),
            UNIQUE KEY uq_phong_pin (MaPin),
            KEY idx_phong_chu (ChuPhongND),
            KEY idx_phong_trangthai (TrangThai, NgayTao)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        try {
            $pdo->exec($sql);
            self::$daKhoiTaoBang = true;
        } catch (Throwable $e) {
            // Bỏ qua nếu đã tồn tại
        }
    }

    /**
     * Sinh mã PIN 6 số ngẫu nhiên không trùng lặp.
     */
    private static function sinhMaPin(PDO $pdo): string
    {
        for ($i = 0; $i < 10; $i++) {
            $pin = (string) random_int(100000, 999999);
            $st = $pdo->prepare("SELECT 1 FROM PHONGTHACHDAU WHERE MaPin = ? AND TrangThai = 'Chờ đối thủ'");
            $st->execute([$pin]);
            if (!$st->fetchColumn()) {
                return $pin;
            }
        }
        return (string) random_int(100000, 999999);
    }

    /**
     * Tạo một phòng thách đấu mới.
     */
    public static function taoPhong(PDO $pdo, int $chuND, int $maMon, int $maLoaiKT): array
    {
        self::khoiTaoBang($pdo);

        // Hủy các phòng cũ đang chờ của người này
        $pdo->prepare("UPDATE PHONGTHACHDAU SET TrangThai = 'Đã hủy' WHERE ChuPhongND = ? AND TrangThai = 'Chờ đối thủ'")
            ->execute([$chuND]);

        $pin = self::sinhMaPin($pdo);

        $st = $pdo->prepare(
            "INSERT INTO PHONGTHACHDAU (MaPin, ChuPhongND, MaMon, MaLoaiKT, TrangThai, NgayTao)
             VALUES (?, ?, ?, ?, 'Chờ đối thủ', NOW())"
        );
        $st->execute([$pin, $chuND, $maMon, $maLoaiKT]);
        $maPhong = (int) $pdo->lastInsertId();

        return [
            'ok'      => true,
            'maPhong' => $maPhong,
            'maPin'   => $pin,
            'link'    => BASE_URL . '/client/timtran.php?phong=' . $pin,
            'maMon'   => $maMon,
            'maLoaiKT'=> $maLoaiKT,
        ];
    }

    /**
     * Lấy thông tin phòng qua mã PIN.
     */
    public static function layThongTinPhong(PDO $pdo, string $pin): ?array
    {
        self::khoiTaoBang($pdo);
        $pinClean = trim($pin);
        $st = $pdo->prepare(
            "SELECT p.*, m.TenMon, l.TenLoaiKT, u.HoTen AS TenChuPhong
               FROM PHONGTHACHDAU p
               JOIN MONHOC m ON m.MaMon = p.MaMon
               JOIN LOAIKYTHI l ON l.MaLoaiKT = p.MaLoaiKT
               JOIN NGUOIDUNG u ON u.MaND = p.ChuPhongND
              WHERE p.MaPin = ? AND p.TrangThai = 'Chờ đối thủ'
                AND TIMESTAMPDIFF(MINUTE, p.NgayTao, NOW()) <= 60"
        );
        $st->execute([$pinClean]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /**
     * Khách nhập mã PIN để vào phòng thách đấu. Tự động tạo trận 1v1.
     */
    public static function vaoPhong(PDO $pdo, int $khachND, string $pin): array
    {
        self::khoiTaoBang($pdo);
        $pinClean = trim($pin);

        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare(
                "SELECT * FROM PHONGTHACHDAU WHERE MaPin = ? AND TrangThai = 'Chờ đối thủ' FOR UPDATE"
            );
            $st->execute([$pinClean]);
            $phong = $st->fetch(PDO::FETCH_ASSOC);

            if (!$phong) {
                $pdo->rollBack();
                return ['ok' => false, 'loi' => 'Mã phòng không tồn tại hoặc đã hết hạn.'];
            }

            if ((int) $phong['ChuPhongND'] === $khachND) {
                $pdo->rollBack();
                return ['ok' => false, 'loi' => 'Bạn là chủ phòng này, hãy gửi mã PIN cho bạn bè!'];
            }

            $chuND = (int) $phong['ChuPhongND'];
            $maMon = (int) $phong['MaMon'];
            $maKT  = (int) $phong['MaLoaiKT'];

            // Gọi GhepDoi để tạo trận 1v1 trực tiếp giữa 2 người chơi
            require_once __DIR__ . '/GhepDoi.php';
            $maTran = GhepDoi::taoTranTrucTiep($pdo, $chuND, $khachND, $maMon, $maKT);

            if ($maTran === null) {
                $pdo->rollBack();
                return ['ok' => false, 'loi' => 'Không thể khởi tạo trận đấu (chưa đủ câu hỏi môn này).'];
            }

            // Cập nhật trạng thái phòng
            $up = $pdo->prepare(
                "UPDATE PHONGTHACHDAU SET KhachND = ?, MaTran = ?, TrangThai = 'Đã ghép' WHERE MaPhong = ?"
            );
            $up->execute([$khachND, $maTran, (int) $phong['MaPhong']]);

            $pdo->commit();
            return [
                'ok'       => true,
                'maTran'   => $maTran,
                'phongUrl' => BASE_URL . '/client/phong.php?tran=' . $maTran
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'loi' => 'Lỗi hệ thống khi vào phòng: ' . $e->getMessage()];
        }
    }

    /**
     * Dành cho chủ phòng: Polling kiểm tra xem khách đã vào chưa.
     */
    public static function kiemTraPhongChu(PDO $pdo, int $chuND, string $pin): array
    {
        self::khoiTaoBang($pdo);
        $st = $pdo->prepare(
            "SELECT TrangThai, MaTran FROM PHONGTHACHDAU WHERE ChuPhongND = ? AND MaPin = ?"
        );
        $st->execute([$chuND, trim($pin)]);
        $r = $st->fetch(PDO::FETCH_ASSOC);

        if (!$r) {
            return ['tonTai' => false, 'daGhep' => false];
        }

        if ($r['TrangThai'] === 'Đã ghép' && !empty($r['MaTran'])) {
            return [
                'tonTai'   => true,
                'daGhep'   => true,
                'maTran'   => (int) $r['MaTran'],
                'phongUrl' => BASE_URL . '/client/phong.php?tran=' . (int) $r['MaTran']
            ];
        }

        return ['tonTai' => true, 'daGhep' => false];
    }

    /**
     * Chủ phòng hủy phòng thách đấu.
     */
    public static function huyPhong(PDO $pdo, int $chuND, ?string $pin = null): bool
    {
        self::khoiTaoBang($pdo);
        if ($pin !== null) {
            $st = $pdo->prepare("UPDATE PHONGTHACHDAU SET TrangThai = 'Đã hủy' WHERE ChuPhongND = ? AND MaPin = ?");
            return $st->execute([$chuND, trim($pin)]);
        }
        $st = $pdo->prepare("UPDATE PHONGTHACHDAU SET TrangThai = 'Đã hủy' WHERE ChuPhongND = ? AND TrangThai = 'Chờ đối thủ'");
        return $st->execute([$chuND]);
    }
}

