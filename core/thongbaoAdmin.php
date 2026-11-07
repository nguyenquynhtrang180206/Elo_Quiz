<?php
// Thông báo cho quản trị viên (chuông ở trang Tổng quan). Đặt tại: core/ThongBaoAdmin.php
// Nguồn thông báo: bài đăng mới và bình luận mới của cộng đồng trong 30 ngày gần nhất (không tính mục đã xóa).
// Trạng thái đã đọc lưu riêng cho từng admin ở bảng THONGBAO_ADMIN_DADOC (không có dòng = chưa đọc).
class ThongBaoAdmin
{
    public const LOAI_BAI = 1;   // bài đăng mới
    public const LOAI_BL  = 2;   // bình luận mới

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
                    Loai       TINYINT UNSIGNED NOT NULL COMMENT '1 = bài đăng, 2 = bình luận',
                    MaDoiTuong INT UNSIGNED NOT NULL COMMENT 'MaBaiDang hoặc MaBinhLuan',
                    NgayDoc    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (MaAdmin, Loai, MaDoiTuong)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            self::$bangTonTai = true;
        } catch (Throwable $e) {
            self::$bangTonTai = false;
        }
        return self::$bangTonTai;
    }

    // Truy vấn gộp bài đăng + bình luận (dùng chung cho đếm, liệt kê, đánh dấu đã đọc)
    private static function nguon(): string
    {
        return "SELECT 1 AS loai, b.MaBaiDang AS ma, b.MaBaiDang AS ma_bai, b.NgayDang AS tg, u.HoTen AS ten, b.TieuDe AS noidung
                  FROM BAIDANG b JOIN NGUOIDUNG u ON u.MaND = b.MaND
                 WHERE b.TrangThai <> 'Đã xóa' AND b.NgayDang >= NOW() - INTERVAL 30 DAY
                UNION ALL
                SELECT 2, c.MaBinhLuan, c.MaBaiDang, c.NgayBinhLuan, u.HoTen, c.NoiDung
                  FROM BINHLUAN c JOIN NGUOIDUNG u ON u.MaND = c.MaND
                 WHERE c.TrangThai <> 'Đã xóa' AND c.NgayBinhLuan >= NOW() - INTERVAL 30 DAY";
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

    // Tổng số thông báo trong 30 ngày (đã đọc + chưa đọc)
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
        if ($maAdmin <= 0 || $ma <= 0 || !in_array($loai, [self::LOAI_BAI, self::LOAI_BL], true) || !self::damBaoBang()) {
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

    // Đường dẫn trang xử lý: cả bài đăng và bình luận đều mở trang chi tiết bài
    public static function lienKet(int $maBai): string
    {
        return BASE_URL . '/admin/congdong_baidang.php?id=' . $maBai;
    }
}