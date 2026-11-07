<?php
// Thông báo cho quản trị viên (chuông ở trang Tổng quan). Đặt tại: core/ThongBaoAdmin.php
// Nguồn thông báo: CHỈ môn học do sinh viên đề xuất và đang chờ duyệt (MONHOC.TrangThai = 'Chờ duyệt').
// Bài đăng và bình luận của cộng đồng không còn tạo thông báo. Duyệt hoặc từ chối đề xuất thì thông báo tự biến mất.
// Trạng thái đã đọc lưu riêng cho từng admin ở bảng THONGBAO_ADMIN_DADOC (không có dòng = chưa đọc).
class ThongBaoAdmin
{
    public const LOAI_MON = 3;   // đề xuất môn học mới (giữ số 3 để không trùng dữ liệu "đã đọc" cũ của bài đăng/bình luận)

    private static ?bool $bangTonTai = null;

    public static function damBaoBang(): bool
    {
        if (self::$bangTonTai !== null) {
            return self::$bangTonTai;
        }
        try {
            Database::pdo()->exec("
                CREATE TABLE IF NOT EXISTS THONGBAO_ADMIN_DADOC (
                    MaAdmin    INT UNSIGNED NOT NULL,
                    Loai       TINYINT UNSIGNED NOT NULL COMMENT '3 = đề xuất môn học',
                    MaDoiTuong INT UNSIGNED NOT NULL COMMENT 'MaMon',
                    NgayDoc    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (MaAdmin, Loai, MaDoiTuong)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            // MONHOC chưa có thời điểm đề xuất: thêm cột (các đề xuất đang chờ sẽ lấy giờ lúc chạy lần đầu)
            $co = Database::pdo()->query("SHOW COLUMNS FROM MONHOC LIKE 'NgayDeXuat'")->fetch();
            if (!$co) {
                Database::pdo()->exec('ALTER TABLE MONHOC ADD COLUMN NgayDeXuat DATETIME NULL DEFAULT CURRENT_TIMESTAMP');
            }
            self::$bangTonTai = true;
        } catch (Throwable $e) {
            self::$bangTonTai = false;
        }
        return self::$bangTonTai;
    }

    // Truy vấn nguồn: các môn đang chờ duyệt (dùng chung cho đếm, liệt kê, đánh dấu đã đọc)
    private static function nguon(): string
    {
        return "SELECT 3 AS loai, m.MaMon AS ma, m.MaMon AS ma_bai,
                       COALESCE(m.NgayDeXuat, NOW()) AS tg,
                       COALESCE(u.HoTen, 'Sinh viên') AS ten, m.TenMon AS noidung
                  FROM MONHOC m LEFT JOIN NGUOIDUNG u ON u.MaND = m.MaNguoiDeXuat
                 WHERE m.TrangThai = 'Chờ duyệt'";
    }

    public static function laySoChuaDoc(PDO $pdo, int $maAdmin): int
    {
        if ($maAdmin <= 0 || !self::damBaoBang()) {
            return 0;
        }
        try {
            $st = $pdo->prepare(
                'SELECT COUNT(*) FROM (' . self::nguon() . ') x
                 LEFT JOIN THONGBAO_ADMIN_DADOC d ON d.MaAdmin = ? AND d.Loai = x.loai AND d.MaDoiTuong = x.ma
                 WHERE d.MaAdmin IS NULL'
            );
            $st->execute([$maAdmin]);
            return (int) $st->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    // Tổng số đề xuất đang chờ duyệt (đã đọc + chưa đọc)
    public static function demTatCa(PDO $pdo): int
    {
        try {
            return (int) $pdo->query('SELECT COUNT(*) FROM (' . self::nguon() . ') x')->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    public static function layDanhSach(PDO $pdo, int $maAdmin, int $limit = 5, int $offset = 0, bool $chiChuaDoc = false): array
    {
        if ($maAdmin <= 0 || !self::damBaoBang()) {
            return [];
        }
        try {
            $st = $pdo->prepare(
                'SELECT x.*, (d.MaAdmin IS NOT NULL) AS da_doc FROM (' . self::nguon() . ') x
                 LEFT JOIN THONGBAO_ADMIN_DADOC d ON d.MaAdmin = :ad AND d.Loai = x.loai AND d.MaDoiTuong = x.ma
                 ' . ($chiChuaDoc ? 'WHERE d.MaAdmin IS NULL' : '') . '
                 ORDER BY x.tg DESC, x.ma DESC
                 LIMIT :lim OFFSET :off'
            );
            $st->bindValue(':ad', $maAdmin, PDO::PARAM_INT);
            $st->bindValue(':lim', max(1, min(200, $limit)), PDO::PARAM_INT);
            $st->bindValue(':off', max(0, $offset), PDO::PARAM_INT);
            $st->execute();
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function danhDauDaDoc(PDO $pdo, int $maAdmin, int $loai, int $ma): bool
    {
        if ($maAdmin <= 0 || $ma <= 0 || !$loai === self::LOAI_MON || !self::damBaoBang()) {
            return false;
        }
        try {
            return $pdo->prepare('INSERT IGNORE INTO THONGBAO_ADMIN_DADOC (MaAdmin, Loai, MaDoiTuong) VALUES (?, ?, ?)')
                       ->execute([$maAdmin, $loai, $ma]);
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function danhDauTatCaDaDoc(PDO $pdo, int $maAdmin): bool
    {
        if ($maAdmin <= 0 || !self::damBaoBang()) {
            return false;
        }
        try {
            return $pdo->prepare(
                'INSERT IGNORE INTO THONGBAO_ADMIN_DADOC (MaAdmin, Loai, MaDoiTuong)
                 SELECT ?, x.loai, x.ma FROM (' . self::nguon() . ') x'
            )->execute([$maAdmin]);
        } catch (Throwable $e) {
            return false;
        }
    }

    // Bấm thông báo: mở trang Môn học và cuộn tới mục "Môn sinh viên đề xuất, chờ duyệt"
    public static function lienKet(int $maMon = 0): string
    {
        return BASE_URL . '/admin/monhoc.php#de-xuat';
    }
}