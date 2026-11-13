<?php
// Quản lý thông báo người dùng (Đặc biệt thông báo khi có người rep bài, bình luận, v.v.)
// Đặt tại: core/ThongBaoHelper.php
class ThongBaoHelper
{
    private static ?bool $bangTonTai = null;

    // Các loại thông báo do quản trị viên kiểm duyệt cộng đồng gửi tới chủ bài / chủ bình luận
    public const LOAI_QUAN_TRI = ['an_bai', 'hien_bai', 'an_bl', 'hien_bl'];
    public const TEN_QUAN_TRI  = 'Quản trị viên';

    // Thông báo từ quản trị viên (không phải người dùng nên MaNguoiGui = 0; giao diện hiện tên "Quản trị viên")
    public static function taoThongBaoQuanTri(
        PDO $pdo,
        int $maNguoiNhan,
        int $maBaiDang,
        ?int $maBinhLuan,
        string $loai,
        string $tieuDe,
        ?string $noiDung,
        string $lienKet
    ): bool {
        if (!in_array($loai, self::LOAI_QUAN_TRI, true)) {
            return false;
        }
        return self::taoThongBao($pdo, $maNguoiNhan, 0, $maBaiDang, $maBinhLuan, $loai, $tieuDe, $noiDung, $lienKet);
    }

    /**
     * Tự động đảm bảo bảng THONGBAO tồn tại trong CSDL
     */
    public static function damBaoBang(): bool
    {
        if (self::$bangTonTai !== null) {
            return self::$bangTonTai;
        }
        try {
            $pdo = Database::pdo();
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS THONGBAO (
                    MaTB        INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    MaND        INT UNSIGNED NOT NULL COMMENT 'Người nhận thông báo',
                    MaNguoiGui  INT UNSIGNED NOT NULL COMMENT 'Người thực hiện hành động',
                    MaBaiDang   INT UNSIGNED NULL COMMENT 'Bài viết liên quan (NULL với thông báo kết bạn)',
                    MaBinhLuan  INT UNSIGNED NULL COMMENT 'Bình luận liên quan nếu có',
                    Loai        VARCHAR(30) NOT NULL DEFAULT 'binhluan' COMMENT 'binhluan, rep_bl, loi_moi_kb, chap_nhan_kb, v.v.',
                    TieuDe      VARCHAR(255) NOT NULL,
                    NoiDung     VARCHAR(500) NULL,
                    LienKet     VARCHAR(255) NOT NULL,
                    DaDoc       TINYINT(1) NOT NULL DEFAULT 0,
                    NgayTao     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (MaTB),
                    KEY idx_thongbao_user (MaND, DaDoc, NgayTao),
                    KEY idx_thongbao_baidang (MaBaiDang)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
            self::$bangTonTai = true;
        } catch (Throwable $e) {
            self::$bangTonTai = false;
        }
        return self::$bangTonTai;
    }

    /**
     * Tạo một thông báo mới
     */
    public static function taoThongBao(
        PDO $pdo,
        int $maNguoiNhan,
        int $maNguoiGui,
        int $maBaiDang,
        ?int $maBinhLuan,
        string $loai,
        string $tieuDe,
        ?string $noiDung,
        string $lienKet
    ): bool {
        // Không gửi thông báo cho chính mình
        if ($maNguoiNhan <= 0 || $maNguoiNhan === $maNguoiGui) {
            return false;
        }
        if (!self::damBaoBang()) {
            return false;
        }
        try {
            $st = $pdo->prepare("
                INSERT INTO THONGBAO (MaND, MaNguoiGui, MaBaiDang, MaBinhLuan, Loai, TieuDe, NoiDung, LienKet, DaDoc, NgayTao)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, NOW())
            ");
            return $st->execute([
                $maNguoiNhan,
                $maNguoiGui,
                $maBaiDang,
                $maBinhLuan,
                $loai,
                mb_substr($tieuDe, 0, 255),
                $noiDung !== null ? mb_substr($noiDung, 0, 500) : null,
                mb_substr($lienKet, 0, 255)
            ]);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Thông báo về bạn bè (lời mời, chấp nhận). Không gắn với bài đăng nên MaBaiDang = NULL.
     * Xóa trước thông báo cùng loại, cùng cặp người còn chưa đọc để gửi đi gửi lại không bị trùng.
     */
    public static function taoThongBaoBanBe(
        PDO $pdo,
        int $maNguoiNhan,
        int $maNguoiGui,
        string $loai,
        string $tieuDe,
        string $lienKet
    ): bool {
        if ($maNguoiNhan <= 0 || $maNguoiNhan === $maNguoiGui || !self::damBaoBang()) {
            return false;
        }
        self::xoaThongBaoBanBe($pdo, $maNguoiNhan, $maNguoiGui, $loai, true);

        $sql = 'INSERT INTO THONGBAO (MaND, MaNguoiGui, MaBaiDang, MaBinhLuan, Loai, TieuDe, NoiDung, LienKet, DaDoc, NgayTao)
                VALUES (?, ?, NULL, NULL, ?, ?, NULL, ?, 0, NOW())';
        $tham = [$maNguoiNhan, $maNguoiGui, $loai, mb_substr($tieuDe, 0, 255), mb_substr($lienKet, 0, 255)];
        try {
            return $pdo->prepare($sql)->execute($tham);
        } catch (PDOException $e) {
            // Bảng THONGBAO tạo từ phiên bản cũ: MaBaiDang còn NOT NULL (lỗi MySQL 1048).
            // Cho phép NULL một lần rồi thử lại.
            if ((int) ($e->errorInfo[1] ?? 0) !== 1048) {
                return false;
            }
            try {
                $pdo->exec('ALTER TABLE THONGBAO MODIFY MaBaiDang INT UNSIGNED NULL');
                return $pdo->prepare($sql)->execute($tham);
            } catch (Throwable $e2) {
                return false;
            }
        }
    }

    /**
     * Xóa thông báo bạn bè (cùng loại, cùng cặp người gửi - nhận).
     * Dùng khi lời mời đã được xử lý hoặc không còn đúng nữa.
     */
    public static function xoaThongBaoBanBe(PDO $pdo, int $maNguoiNhan, int $maNguoiGui, string $loai, bool $chiChuaDoc = false): void
    {
        if ($maNguoiNhan <= 0 || $maNguoiGui <= 0 || !self::damBaoBang()) {
            return;
        }
        try {
            $sql = 'DELETE FROM THONGBAO WHERE MaND = ? AND MaNguoiGui = ? AND Loai = ?' . ($chiChuaDoc ? ' AND DaDoc = 0' : '');
            $pdo->prepare($sql)->execute([$maNguoiNhan, $maNguoiGui, $loai]);
        } catch (Throwable $e) {
            // thông báo chỉ là phụ, lỗi ở đây không được làm hỏng thao tác kết bạn
        }
    }

    /**
     * Lấy số lượng thông báo chưa đọc của người dùng
     */
    public static function laySoChuaDoc(PDO $pdo, int $maND): int
    {
        if ($maND <= 0 || !self::damBaoBang()) {
            return 0;
        }
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM THONGBAO WHERE MaND = ? AND DaDoc = 0");
            $st->execute([$maND]);
            return (int) $st->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * Lấy danh sách thông báo kèm thông tin người gửi
     */
    public static function layDanhSach(PDO $pdo, int $maND, int $limit = 10, int $offset = 0, ?bool $chiChuaDoc = null): array
    {
        if ($maND <= 0 || !self::damBaoBang()) {
            return [];
        }
        try {
            $where = "t.MaND = :mand";
            if ($chiChuaDoc === true) {
                $where .= " AND t.DaDoc = 0";
            }
            $st = $pdo->prepare("
                SELECT t.*, u.HoTen AS TenNguoiGui, u.AnhDaiDien AS AnhNguoiGui, b.TieuDe AS TieuDeBai
                  FROM THONGBAO t
                  LEFT JOIN NGUOIDUNG u ON u.MaND = t.MaNguoiGui
                  LEFT JOIN BAIDANG b ON b.MaBaiDang = t.MaBaiDang
                 WHERE $where
                 ORDER BY t.NgayTao DESC, t.MaTB DESC
                 LIMIT :limit OFFSET :offset
            ");
            $st->bindValue(':mand', $maND, PDO::PARAM_INT);
            $st->bindValue(':limit', max(1, min(100, $limit)), PDO::PARAM_INT);
            $st->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
            $st->execute();
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Đánh dấu 1 thông báo là đã đọc
     */
    public static function danhDauDaDoc(PDO $pdo, int $maTB, int $maND): bool
    {
        if ($maTB <= 0 || $maND <= 0 || !self::damBaoBang()) {
            return false;
        }
        try {
            $st = $pdo->prepare("UPDATE THONGBAO SET DaDoc = 1 WHERE MaTB = ? AND MaND = ?");
            return $st->execute([$maTB, $maND]);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Đánh dấu tất cả thông báo của người dùng là đã đọc
     */
    public static function danhDauTatCaDaDoc(PDO $pdo, int $maND): bool
    {
        if ($maND <= 0 || !self::damBaoBang()) {
            return false;
        }
        try {
            $st = $pdo->prepare("UPDATE THONGBAO SET DaDoc = 1 WHERE MaND = ? AND DaDoc = 0");
            return $st->execute([$maND]);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Xóa 1 thông báo
     */
    public static function xoaThongBao(PDO $pdo, int $maTB, int $maND): bool
    {
        if ($maTB <= 0 || $maND <= 0 || !self::damBaoBang()) {
            return false;
        }
        try {
            $st = $pdo->prepare("DELETE FROM THONGBAO WHERE MaTB = ? AND MaND = ?");
            return $st->execute([$maTB, $maND]);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Xóa tất cả thông báo của người dùng
     */
    public static function xoaTatCa(PDO $pdo, int $maND): bool
    {
        if ($maND <= 0 || !self::damBaoBang()) {
            return false;
        }
        try {
            $st = $pdo->prepare("DELETE FROM THONGBAO WHERE MaND = ?");
            return $st->execute([$maND]);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Định dạng khoảng thời gian tương đối theo tiếng Việt
     */
    public static function tinhThoiGian(string $datetime): string
    {
        $time = strtotime($datetime);
        if (!$time) return '';
        $diff = time() - $time;
        if ($diff < 5) return 'Vừa xong';
        if ($diff < 60) return $diff . ' giây trước';
        $min = (int) floor($diff / 60);
        if ($min < 60) return $min . ' phút trước';
        $hours = (int) floor($diff / 3600);
        if ($hours < 24) return $hours . ' giờ trước';
        $days = (int) floor($diff / 86400);
        if ($days === 1) return 'Hôm qua ' . date('H:i', $time);
        if ($days < 7) return $days . ' ngày trước';
        return date('d/m/Y H:i', $time);
    }
}