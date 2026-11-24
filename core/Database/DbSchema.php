<?php
// Tự nâng cấp CSDL: đảm bảo mọi bảng/cột mà code dùng đều tồn tại.
// Chạy đúng 1 lần cho mỗi phiên bản (đánh dấu bằng CAUHINH.SCHEMA_VERSION), các request sau chỉ tốn 1 truy vấn nhẹ.
class DbSchema
{
    public const PHIEN_BAN = '8';
    private static bool $daKiemTra = false;

    public static function damBao(PDO $pdo): void
    {
        if (self::$daKiemTra) {
            return;
        }
        self::$daKiemTra = true;

        // Luôn đảm bảo bảng CAUHINH tồn tại trước khi đọc SCHEMA_VERSION
        self::thucThiAnToan($pdo, "CREATE TABLE IF NOT EXISTS CAUHINH (
            MaCauHinh   INT UNSIGNED NOT NULL AUTO_INCREMENT,
            TenCauHinh  VARCHAR(60)  NOT NULL,
            GiaTri      VARCHAR(100) NOT NULL,
            MoTa        VARCHAR(255) NULL,
            PRIMARY KEY (MaCauHinh),
            UNIQUE KEY uq_cauhinh_ten (TenCauHinh)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        try {
            $st = $pdo->query("SELECT GiaTri FROM CAUHINH WHERE TenCauHinh = 'SCHEMA_VERSION'");
            if ($st && (string) $st->fetchColumn() === self::PHIEN_BAN) {
                return;
            }
        } catch (Throwable) {
            // Tiếp tục chạy nangCap() ngay cả khi truy vấn CAUHINH lỗi
        }

        try {
            self::nangCap($pdo);
            $pdo->prepare(
                "INSERT INTO CAUHINH (TenCauHinh, GiaTri, MoTa) VALUES ('SCHEMA_VERSION', ?, 'Phiên bản cấu trúc CSDL chuẩn hóa')
                 ON DUPLICATE KEY UPDATE GiaTri = VALUES(GiaTri)"
            )->execute([self::PHIEN_BAN]);
        } catch (Throwable $e) {
            error_log('DbSchema: ' . $e->getMessage());
        }
    }

    /** Đảm bảo trực tiếp một cột bắt buộc tồn tại trong bảng (tự động sửa lỗi 42S22 tức thì). */
    public static function damBaoCot(PDO $pdo, string $bang, string $cot, string $dinhNghia = 'VARCHAR(255) NULL DEFAULT NULL'): void
    {
        self::themCot($pdo, $bang, $cot, $dinhNghia);
    }

    private static function coCot(PDO $pdo, string $bang, string $cot): bool
    {
        try {
            $st = $pdo->prepare(
                'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND LOWER(TABLE_NAME) = LOWER(?)
                    AND LOWER(COLUMN_NAME) = LOWER(?)'
            );
            $st->execute([$bang, $cot]);
            return (bool) $st->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    private static function themCot(PDO $pdo, string $bang, string $cot, string $dinhNghia): void
    {
        if (!self::coCot($pdo, $bang, $cot)) {
            // Tìm tên bảng thực tế trong DB để tránh lỗi phân biệt hoa/thường trên một số cấu hình MySQL
            $tenBangThuc = $bang;
            try {
                $st = $pdo->prepare(
                    'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
                      WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = LOWER(?) LIMIT 1'
                );
                $st->execute([$bang]);
                $found = $st->fetchColumn();
                if ($found) {
                    $tenBangThuc = (string) $found;
                }
            } catch (Throwable) {
            }
            self::thucThiAnToan($pdo, "ALTER TABLE `$tenBangThuc` ADD COLUMN `$cot` $dinhNghia");
        }
    }

    private static function thucThiAnToan(PDO $pdo, string $sql): void
    {
        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
            error_log('DbSchema DDL: ' . $e->getMessage());
        }
    }

    private static function nangCap(PDO $pdo): void
    {
        // 1. Cột mở rộng bảng TRANDAU
        self::themCot($pdo, 'TRANDAU', 'ThoiGianTao', 'DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)');
        self::themCot($pdo, 'TRANDAU', 'DotBien', "VARCHAR(30) NOT NULL DEFAULT 'chuan'");
        self::themCot($pdo, 'TRANDAU', 'PhaRoi', 'TEXT NULL');

        // 2. Cột mở rộng bảng NGUOIDUNG
        self::themCot($pdo, 'NGUOIDUNG', 'TuongChon', "VARCHAR(40) NOT NULL DEFAULT 'sunflower'");
        self::themCot($pdo, 'NGUOIDUNG', 'ChoPhepBot', 'TINYINT(1) NOT NULL DEFAULT 1');
        self::themCot($pdo, 'NGUOIDUNG', 'NgayDoiMatKhau', 'DATETIME NULL DEFAULT NULL');

        // 3. Cột mở rộng bảng MONHOC (hỗ trợ sinh viên đề xuất môn)
        self::themCot($pdo, 'MONHOC', 'MaNguoiDeXuat', 'INT UNSIGNED NULL');
        self::themCot($pdo, 'MONHOC', 'NgayDeXuat', 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP');
        self::thucThiAnToan($pdo, "ALTER TABLE MONHOC MODIFY TrangThai ENUM('Đang mở','Đã ẩn','Chờ duyệt') NOT NULL DEFAULT 'Đang mở'");

        // 4. Cột mở rộng bảng BAIDANG & BINHLUAN (phân loại bài đăng, ẩn bài/bình luận, phản hồi đa cấp)
        self::themCot($pdo, 'BAIDANG', 'LoaiBai', "ENUM('Thảo luận','Hỏi đáp','Chia sẻ mẹo','Tìm bạn ôn thi','Khác') NOT NULL DEFAULT 'Thảo luận'");
        self::themCot($pdo, 'BAIDANG', 'NguoiAn', "ENUM('Chủ bài','Quản trị') DEFAULT NULL");
        self::themCot($pdo, 'BAIDANG', 'AnhDinhKem', 'VARCHAR(255) NULL DEFAULT NULL');   // 1 ảnh đính kèm mỗi bài (đường dẫn tương đối từ gốc dự án)
        self::themCot($pdo, 'BINHLUAN', 'NguoiAn', "ENUM('Chủ bài','Quản trị') DEFAULT NULL");
        self::themCot($pdo, 'BINHLUAN', 'MaBinhLuanCha', 'INT UNSIGNED NULL DEFAULT NULL');
        self::themCot($pdo, 'BINHLUAN', 'AnhDinhKem', 'VARCHAR(255) NULL DEFAULT NULL');

        // 5. Cột mở rộng bảng NHATKYQUANTRI (IP và trạng thái thao tác)
        self::themCot($pdo, 'NHATKYQUANTRI', 'DiaChiIP', 'VARCHAR(45) NULL');
        self::themCot($pdo, 'NHATKYQUANTRI', 'TrangThai', "ENUM('Thành công','Thất bại') NOT NULL DEFAULT 'Thành công'");

        // 6. Bảng PHONGTHACHDAU (Thách đấu bạn bè 1v1 bằng mã PIN)
        self::thucThiAnToan($pdo, "CREATE TABLE IF NOT EXISTS PHONGTHACHDAU (
            MaPhong      INT UNSIGNED NOT NULL AUTO_INCREMENT,
            MaPin        VARCHAR(10)  NOT NULL,
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 7. Bảng THONGTHAOTUONG (EXP / Cấp độ tướng xuất chiến)
        self::thucThiAnToan($pdo, "CREATE TABLE IF NOT EXISTS THONGTHAOTUONG (
            MaND        INT UNSIGNED NOT NULL,
            TuongId     VARCHAR(40)  NOT NULL,
            CapDo       TINYINT UNSIGNED NOT NULL DEFAULT 1,
            DiemEXP     INT UNSIGNED NOT NULL DEFAULT 0,
            SoTranThang INT UNSIGNED NOT NULL DEFAULT 0,
            SoTranDau   INT UNSIGNED NOT NULL DEFAULT 0,
            CapNhatLuc  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (MaND, TuongId),
            CONSTRAINT fk_thongthao_nd FOREIGN KEY (MaND) REFERENCES NGUOIDUNG (MaND) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 8. Bảng PHATAFK (Quản lý án phạt cấm tìm trận AFK)
        self::thucThiAnToan($pdo, "CREATE TABLE IF NOT EXISTS PHATAFK (
            MaPhat    INT UNSIGNED NOT NULL AUTO_INCREMENT,
            MaND      INT UNSIGNED NOT NULL,
            LyDo      VARCHAR(255) NOT NULL,
            SoGiay    INT UNSIGNED NOT NULL,
            NgayTao   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            HetHan    DATETIME NOT NULL,
            DaGo      TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (MaPhat),
            KEY idx_phatafk_nd (MaND, HetHan),
            CONSTRAINT fk_phatafk_nd FOREIGN KEY (MaND) REFERENCES NGUOIDUNG (MaND) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 9. Bảng GIOIHANDANGNHAP (Chống dò mật khẩu Brute-force)
        self::thucThiAnToan($pdo, "CREATE TABLE IF NOT EXISTS GIOIHANDANGNHAP (
            Khoa      CHAR(64)     NOT NULL,
            HanhDong  VARCHAR(30)  NOT NULL,
            DinhDanh  VARCHAR(150) NOT NULL,
            DiaChiIP  VARCHAR(45)  NOT NULL,
            SoLanSai  INT UNSIGNED NOT NULL DEFAULT 0,
            LanCuoi   DATETIME     NOT NULL,
            PRIMARY KEY (Khoa),
            KEY idx_ghdn_lancuoi (LanCuoi)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 10. Bảng LICHSUDANGNHAP (Ghi nhận lịch sử đăng nhập)
        self::thucThiAnToan($pdo, "CREATE TABLE IF NOT EXISTS LICHSUDANGNHAP (
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

        // 11. Bảng LUOTTHICH & LUOTTHICH_BL (Cộng đồng)
        self::thucThiAnToan($pdo, "CREATE TABLE IF NOT EXISTS LUOTTHICH (
            MaBaiDang  INT UNSIGNED NOT NULL,
            MaND       INT UNSIGNED NOT NULL,
            NgayThich  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (MaBaiDang, MaND)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        self::thucThiAnToan($pdo, "CREATE TABLE IF NOT EXISTS LUOTTHICH_BL (
            MaBinhLuan INT UNSIGNED NOT NULL,
            MaND       INT UNSIGNED NOT NULL,
            NgayThich  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (MaBinhLuan, MaND)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 12. Bảng THONGBAO & THONGBAO_ADMIN_DADOC
        self::thucThiAnToan($pdo, "CREATE TABLE IF NOT EXISTS THONGBAO (
            MaTB        INT UNSIGNED NOT NULL AUTO_INCREMENT,
            MaND        INT UNSIGNED NOT NULL,
            MaNguoiGui  INT UNSIGNED NOT NULL,
            MaBaiDang   INT UNSIGNED NULL,
            MaBinhLuan  INT UNSIGNED NULL,
            Loai        VARCHAR(30) NOT NULL DEFAULT 'binhluan',
            TieuDe      VARCHAR(255) NOT NULL,
            NoiDung     VARCHAR(500) NULL,
            LienKet     VARCHAR(255) NOT NULL,
            DaDoc       TINYINT(1) NOT NULL DEFAULT 0,
            NgayTao     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (MaTB),
            KEY idx_thongbao_user (MaND, DaDoc, NgayTao)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        self::thucThiAnToan($pdo, "CREATE TABLE IF NOT EXISTS THONGBAO_ADMIN_DADOC (
            MaAdmin    INT UNSIGNED NOT NULL,
            Loai       TINYINT UNSIGNED NOT NULL,
            MaDoiTuong INT UNSIGNED NOT NULL,
            NgayDoc    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (MaAdmin, Loai, MaDoiTuong)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 13. Bảng LICHSU_MATKHAU (Lịch sử mật khẩu phục vụ kiểm tra chống tái sử dụng)
        self::thucThiAnToan($pdo, "CREATE TABLE IF NOT EXISTS LICHSU_MATKHAU (
            MaLS        INT UNSIGNED NOT NULL AUTO_INCREMENT,
            MaND        INT UNSIGNED NOT NULL,
            MatKhauHash VARCHAR(255) NOT NULL,
            NgayTao     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (MaLS),
            KEY idx_lsmk_nd (MaND),
            CONSTRAINT fk_lsmk_nd FOREIGN KEY (MaND) REFERENCES NGUOIDUNG (MaND) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // 14. Khởi tạo tài khoản Bot AI mặc định (nếu chưa có)
        self::thucThiAnToan($pdo, "INSERT INTO NGUOIDUNG (MaND, HoTen, Email, MatKhau, TrangThai)
            VALUES (999999, 'Zombie Máy AI [Buckethead]', 'bot@quizup.arena', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Hoạt động')
            ON DUPLICATE KEY UPDATE HoTen = VALUES(HoTen), TrangThai = 'Hoạt động'");

        // 15. Bổ sung Email, NgayDoiMatKhau cho bảng ADMIN & bảng lịch sử mật khẩu quản trị
        self::themCot($pdo, 'ADMIN', 'Email', 'VARCHAR(150) NULL DEFAULT NULL');
        self::themCot($pdo, 'ADMIN', 'NgayDoiMatKhau', 'DATETIME NULL DEFAULT NULL');
        self::thucThiAnToan($pdo, "UPDATE ADMIN SET Email = 'buithutrang149506@gmail.com' WHERE Email IS NULL OR Email = ''");

        self::thucThiAnToan($pdo, "CREATE TABLE IF NOT EXISTS LICHSU_MATKHAU_ADMIN (
            MaLS        INT UNSIGNED NOT NULL AUTO_INCREMENT,
            MaAdmin     INT UNSIGNED NOT NULL,
            MatKhauHash VARCHAR(255) NOT NULL,
            NgayTao     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (MaLS),
            KEY idx_lsmka_admin (MaAdmin)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }
}