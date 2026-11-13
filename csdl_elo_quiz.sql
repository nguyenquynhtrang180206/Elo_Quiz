-- =====================================================================
-- CSDL CHUẨN: Website Thi Đấu Trắc Nghiệm 1 vs 1, Xếp Hạng Elo Theo Môn
-- Tên CSDL: elo_quiz
-- Chuẩn mã hóa: utf8mb4 / utf8mb4_unicode_ci
-- Yêu cầu tối thiểu: MySQL 8.0.16+ hoặc MariaDB 10.4+ (XAMPP 7.4 / 8.x)
-- =====================================================================

CREATE DATABASE IF NOT EXISTS elo_quiz
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE elo_quiz;

-- Tắt kiểm tra khóa ngoại để dọn dẹp sạch sẽ khi chạy lại file
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS
  LICHSUDANGNHAP,
  GIOIHANDANGNHAP,
  PHATAFK,
  THONGTHAOTUONG,
  PHONGTHACHDAU,
  THONGBAO_ADMIN_DADOC,
  THONGBAO,
  LUOTTHICH_BL,
  LUOTTHICH,
  BINHLUAN,
  BAIDANG,
  LICHSUELO,
  CHITIETTRALOI,
  ELOMONHOC,
  CAUHOITRANDAU,
  NGUOICHOITRANDAU,
  TRANDAU,
  HANGDOIGHEPDOI,
  DAPAN,
  CAUHOI,
  BANBE,
  NHATKYQUANTRI,
  CAUHINH,
  MONHOC,
  LOAIKYTHI,
  ADMIN,
  NGUOIDUNG;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- 1. BẢNG NGUOIDUNG (Tài khoản người chơi & thông tin hồ sơ)
-- ---------------------------------------------------------------------
CREATE TABLE NGUOIDUNG (
  MaND              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  HoTen             VARCHAR(100) NOT NULL,
  Email             VARCHAR(150) NOT NULL,
  MatKhau           VARCHAR(255) NOT NULL COMMENT 'Lưu dạng băm (Argon2id hoặc Bcrypt)',
  AnhDaiDien        VARCHAR(255) NULL COMMENT 'Đường dẫn file; NULL = avatar chữ mặc định',
  Lop               VARCHAR(50)  NULL,
  Khoa              VARCHAR(100) NULL,
  TuongChon         VARCHAR(40)  NOT NULL DEFAULT 'sunflower' COMMENT 'Mã tướng/linh vật xuất chiến',
  ChoPhepBot        TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '1 = tự ghép Bot sau hạn chờ, 0 = chỉ ghép PvP',
  TrangThai         ENUM('Hoạt động','Bị khóa') NOT NULL DEFAULT 'Hoạt động',
  NgayTao           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  TrangThaiKhaoSat  ENUM('Chưa làm','Hoàn tất','Bỏ qua') NOT NULL DEFAULT 'Chưa làm',
  MucTieuKhaoSat    VARCHAR(255) NULL COMMENT 'Danh sách mã mục tiêu, phân tách bằng dấu phẩy',
  LinhVucYeuThich   VARCHAR(255) NULL COMMENT 'Danh sách mã lĩnh vực yêu thích',
  TrinhDoTuDanhGia  ENUM('Mới bắt đầu','Trung bình','Khá','Nâng cao') NULL,
  NgayKhaoSat       DATETIME NULL,
  PRIMARY KEY (MaND),
  UNIQUE KEY uq_nguoidung_email (Email),
  KEY idx_nguoidung_trangthai (TrangThai, NgayTao)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. BẢNG ADMIN (Tài khoản quản trị viên hệ thống)
-- ---------------------------------------------------------------------
CREATE TABLE ADMIN (
  MaAdmin       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  TenDangNhap   VARCHAR(50)  NOT NULL,
  MatKhau       VARCHAR(255) NOT NULL COMMENT 'Lưu dạng băm',
  HoTen         VARCHAR(100) NOT NULL,
  TrangThai     ENUM('Hoạt động','Bị khóa') NOT NULL DEFAULT 'Hoạt động',
  NgayTao       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  MaND          INT UNSIGNED NULL COMMENT 'Tài khoản sinh viên liên kết (nếu có)',
  PRIMARY KEY (MaAdmin),
  UNIQUE KEY uq_admin_tendangnhap (TenDangNhap),
  UNIQUE KEY uq_admin_mand (MaND),
  CONSTRAINT fk_admin_nd FOREIGN KEY (MaND) REFERENCES NGUOIDUNG (MaND) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. BẢNG MONHOC (Môn học thi đấu & đề xuất môn học)
-- ---------------------------------------------------------------------
CREATE TABLE MONHOC (
  MaMon           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  TenMon          VARCHAR(150) NOT NULL,
  MoTa            TEXT NULL,
  TrangThai       ENUM('Đang mở','Đã ẩn','Chờ duyệt') NOT NULL DEFAULT 'Đang mở',
  MaNguoiDeXuat   INT UNSIGNED NULL COMMENT 'MaND người đề xuất nếu là môn do sinh viên đề xuất',
  NgayDeXuat      DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (MaMon),
  KEY idx_monhoc_trangthai (TrangThai),
  CONSTRAINT fk_monhoc_nguoidexuat FOREIGN KEY (MaNguoiDeXuat) REFERENCES NGUOIDUNG (MaND) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. BẢNG LOAIKYTHI (Giữa kỳ, Cuối kỳ, Cả 2)
-- ---------------------------------------------------------------------
CREATE TABLE LOAIKYTHI (
  MaLoaiKT   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  TenLoaiKT  VARCHAR(50) NOT NULL,
  MoTa       TEXT NULL,
  PRIMARY KEY (MaLoaiKT)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. BẢNG CAUHOI (Ngân hàng câu hỏi trắc nghiệm)
-- ---------------------------------------------------------------------
CREATE TABLE CAUHOI (
  MaCauHoi   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  MaMon      INT UNSIGNED NOT NULL,
  MaLoaiKT   INT UNSIGNED NULL COMMENT 'NULL = dùng cho cả giữa kỳ và cuối kỳ',
  NoiDung    TEXT NOT NULL,
  GiaiThich  TEXT NULL COMMENT 'Hiển thị ở màn xem lại sau trận',
  DoKho      ENUM('Dễ','Trung bình','Khó') NOT NULL DEFAULT 'Trung bình',
  TrangThai  ENUM('Hiển thị','Đã ẩn') NOT NULL DEFAULT 'Hiển thị',
  PRIMARY KEY (MaCauHoi),
  KEY idx_cauhoi_chon (MaMon, MaLoaiKT, TrangThai),
  CONSTRAINT fk_cauhoi_mon     FOREIGN KEY (MaMon)    REFERENCES MONHOC (MaMon),
  CONSTRAINT fk_cauhoi_loaikt  FOREIGN KEY (MaLoaiKT) REFERENCES LOAIKYTHI (MaLoaiKT)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. BẢNG DAPAN (Đáp án của từng câu hỏi)
-- ---------------------------------------------------------------------
CREATE TABLE DAPAN (
  MaDapAn        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  MaCauHoi       INT UNSIGNED NOT NULL,
  NoiDungDapAn   TEXT NOT NULL,
  LaDapAnDung    TINYINT(1) NOT NULL DEFAULT 0,
  DapAnDungDuy   INT UNSIGNED
    GENERATED ALWAYS AS (IF(LaDapAnDung = 1, MaCauHoi, NULL)) STORED,
  PRIMARY KEY (MaDapAn),
  UNIQUE KEY uq_dapan_cauhoi_dapan (MaCauHoi, MaDapAn),
  UNIQUE KEY uq_dapan_mot_dung (DapAnDungDuy),
  CONSTRAINT fk_dapan_cauhoi FOREIGN KEY (MaCauHoi) REFERENCES CAUHOI (MaCauHoi) ON DELETE CASCADE,
  CONSTRAINT ck_dapan_la_dung CHECK (LaDapAnDung IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7. BẢNG HANGDOIGHEPDOI (Hàng đợi tìm trận trực tuyến)
-- ---------------------------------------------------------------------
CREATE TABLE HANGDOIGHEPDOI (
  MaHangDoi          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  MaND               INT UNSIGNED NOT NULL,
  MaMon              INT UNSIGNED NOT NULL,
  MaLoaiKT           INT UNSIGNED NOT NULL,
  EloTaiThoiDiemCho  INT NOT NULL,
  ThoiGianVaoHang    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (MaHangDoi),
  UNIQUE KEY uq_hangdoi_nd (MaND),
  KEY idx_hangdoi_ghep (MaMon, MaLoaiKT, EloTaiThoiDiemCho),
  CONSTRAINT fk_hangdoi_nd     FOREIGN KEY (MaND)     REFERENCES NGUOIDUNG (MaND) ON DELETE CASCADE,
  CONSTRAINT fk_hangdoi_mon    FOREIGN KEY (MaMon)    REFERENCES MONHOC (MaMon),
  CONSTRAINT fk_hangdoi_loaikt FOREIGN KEY (MaLoaiKT) REFERENCES LOAIKYTHI (MaLoaiKT)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 8. BẢNG TRANDAU (Trận đấu 1 vs 1)
-- ---------------------------------------------------------------------
CREATE TABLE TRANDAU (
  MaTran           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  MaMon            INT UNSIGNED NOT NULL,
  MaLoaiKT         INT UNSIGNED NOT NULL,
  ThoiGianTao      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  ThoiGianBatDau   DATETIME(3) NULL,
  ThoiGianKetThuc  DATETIME(3) NULL,
  TrangThai        ENUM('Đã ghép','Đang diễn ra','Kết thúc','Hủy') NOT NULL DEFAULT 'Đã ghép',
  LyDoKetThuc      ENUM('Hoàn thành','Đối thủ bỏ trận (xử thua)','Hủy do lỗi hệ thống',
                        'Hủy do không vào phòng','Quản trị viên hủy','Chịu thua') NULL,
  DotBien          VARCHAR(30) NOT NULL DEFAULT 'chuan' COMMENT 'Đột biến đấu trường (chuan, sieu_toc, dao_nguoc, jackpot, dot_tu)',
  PhaRoi           TEXT NULL COMMENT 'Dữ liệu kỹ năng phá rối thời gian thực dạng JSON',
  PRIMARY KEY (MaTran),
  KEY idx_trandau_trangthai (TrangThai, ThoiGianBatDau),
  KEY idx_trandau_taoluc (ThoiGianTao),
  CONSTRAINT fk_trandau_mon    FOREIGN KEY (MaMon)    REFERENCES MONHOC (MaMon),
  CONSTRAINT fk_trandau_loaikt FOREIGN KEY (MaLoaiKT) REFERENCES LOAIKYTHI (MaLoaiKT)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 9. BẢNG NGUOICHOITRANDAU (Hai người chơi trong mỗi trận đấu)
-- ---------------------------------------------------------------------
CREATE TABLE NGUOICHOITRANDAU (
  MaTran            INT UNSIGNED NOT NULL,
  MaND              INT UNSIGNED NOT NULL,
  DiemSo            INT NOT NULL DEFAULT 0,
  KetQua            ENUM('Thắng','Thua','Hòa') NULL,
  EloTruoc          INT NULL,
  EloSau            INT NULL,
  TrangThaiThamGia  ENUM('Chưa vào','Đang chơi','Đã xong','Bỏ trận') NOT NULL DEFAULT 'Chưa vào',
  LanHoatDongCuoi   DATETIME(3) NULL COMMENT 'Dùng cho Ready check & phát hiện mất kết nối',
  PRIMARY KEY (MaTran, MaND),
  KEY idx_nctd_nd_trangthai (MaND, TrangThaiThamGia),
  CONSTRAINT fk_nctd_tran FOREIGN KEY (MaTran) REFERENCES TRANDAU (MaTran) ON DELETE CASCADE,
  CONSTRAINT fk_nctd_nd   FOREIGN KEY (MaND)   REFERENCES NGUOIDUNG (MaND)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 10. BẢNG CAUHOITRANDAU (Bộ câu hỏi bốc ngẫu nhiên cho trận đấu)
-- ---------------------------------------------------------------------
CREATE TABLE CAUHOITRANDAU (
  MaTran           INT UNSIGNED NOT NULL,
  MaCauHoi         INT UNSIGNED NOT NULL,
  ThuTu            TINYINT UNSIGNED NOT NULL,
  DiemCoBan        INT NOT NULL,
  HeSoThuong       INT NOT NULL,
  ThoiGianQuyDinh  INT NOT NULL COMMENT 'Giây',
  PRIMARY KEY (MaTran, MaCauHoi),
  UNIQUE KEY uq_cauhoitrandau_thutu (MaTran, ThuTu),
  CONSTRAINT fk_cauhoitrandau_tran    FOREIGN KEY (MaTran)    REFERENCES TRANDAU (MaTran) ON DELETE CASCADE,
  CONSTRAINT fk_cauhoitrandau_cauhoi  FOREIGN KEY (MaCauHoi)  REFERENCES CAUHOI (MaCauHoi),
  CONSTRAINT ck_cauhoitrandau_gt CHECK (DiemCoBan >= 0 AND HeSoThuong >= 0 AND ThoiGianQuyDinh > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 11. BẢNG ELOMONHOC (Bảng điểm Elo theo từng môn của người chơi)
-- ---------------------------------------------------------------------
CREATE TABLE ELOMONHOC (
  MaND      INT UNSIGNED NOT NULL,
  MaMon     INT UNSIGNED NOT NULL,
  DiemElo   INT NOT NULL DEFAULT 1000,
  SoTran    INT UNSIGNED NOT NULL DEFAULT 0,
  SoThang   INT UNSIGNED NOT NULL DEFAULT 0,
  SoThua    INT UNSIGNED NOT NULL DEFAULT 0,
  SoHoa     INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (MaND, MaMon),
  KEY idx_elo_bxh (MaMon, DiemElo DESC),
  CONSTRAINT fk_elo_nd  FOREIGN KEY (MaND)  REFERENCES NGUOIDUNG (MaND) ON DELETE CASCADE,
  CONSTRAINT fk_elo_mon FOREIGN KEY (MaMon) REFERENCES MONHOC (MaMon),
  CONSTRAINT ck_elo_sotran CHECK (SoTran = SoThang + SoThua + SoHoa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 12. BẢNG CHITIETTRALOI (Chi tiết câu trả lời từng câu trong trận)
-- ---------------------------------------------------------------------
CREATE TABLE CHITIETTRALOI (
  MaTran          INT UNSIGNED NOT NULL,
  MaND            INT UNSIGNED NOT NULL,
  MaCauHoi        INT UNSIGNED NOT NULL,
  MaDapAn         INT UNSIGNED NULL,
  ThoiDiemBatDau  DATETIME(3) NULL,
  ThoiGianTraLoi  DECIMAL(6,3) NULL,
  DiemNhanDuoc    INT NOT NULL DEFAULT 0,
  TrangThai       ENUM('Đã trả lời','Hết giờ','Không làm') NOT NULL DEFAULT 'Không làm',
  ThoiDiemGui     DATETIME(3) NULL,
  PRIMARY KEY (MaTran, MaND, MaCauHoi),
  KEY idx_cttl_cauhoi (MaCauHoi),
  CONSTRAINT fk_cttl_nguoichoi FOREIGN KEY (MaTran, MaND)
    REFERENCES NGUOICHOITRANDAU (MaTran, MaND) ON DELETE CASCADE,
  CONSTRAINT fk_ctl_cauhoitran  FOREIGN KEY (MaTran, MaCauHoi)
    REFERENCES CAUHOITRANDAU (MaTran, MaCauHoi) ON DELETE CASCADE,
  CONSTRAINT fk_ctl_dapan       FOREIGN KEY (MaCauHoi, MaDapAn)
    REFERENCES DAPAN (MaCauHoi, MaDapAn)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 13. BẢNG LICHSUELO (Lịch sử biến động điểm Elo sau từng trận)
-- ---------------------------------------------------------------------
CREATE TABLE LICHSUELO (
  MaLichSu   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  MaND       INT UNSIGNED NOT NULL,
  MaMon      INT UNSIGNED NOT NULL,
  MaTran     INT UNSIGNED NOT NULL,
  EloTruoc   INT NOT NULL,
  EloSau     INT NOT NULL,
  ThayDoi    INT NOT NULL,
  ThoiGian   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (MaLichSu),
  UNIQUE KEY uq_lichsuelo_nd_mon_tran (MaND, MaMon, MaTran),
  KEY idx_lichsuelo_mon_tg (MaMon, ThoiGian),
  KEY idx_lichsuelo_nd_tg (MaND, ThoiGian),
  KEY idx_lichsuelo_tran (MaTran),
  CONSTRAINT fk_lichsuelo_elo  FOREIGN KEY (MaND, MaMon) REFERENCES ELOMONHOC (MaND, MaMon) ON DELETE CASCADE,
  CONSTRAINT fk_lichsuelo_tran FOREIGN KEY (MaTran)      REFERENCES TRANDAU (MaTran) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 14. BẢNG BAIDANG (Bài viết diễn đàn cộng đồng)
-- ---------------------------------------------------------------------
CREATE TABLE BAIDANG (
  MaBaiDang  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  MaND       INT UNSIGNED NOT NULL,
  MaMon      INT UNSIGNED NULL,
  TieuDe     VARCHAR(200) NOT NULL,
  NoiDung    TEXT NOT NULL,
  NgayDang   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  TrangThai  ENUM('Hiển thị','Bị ẩn','Đã xóa') NOT NULL DEFAULT 'Hiển thị',
  NguoiAn    ENUM('Chủ bài','Quản trị') NULL,
  LoaiBai    ENUM('Thảo luận','Hỏi đáp','Chia sẻ mẹo','Tìm bạn ôn thi') NOT NULL DEFAULT 'Thảo luận',
  PRIMARY KEY (MaBaiDang),
  KEY idx_baidang_mon (MaMon, TrangThai, NgayDang),
  KEY idx_baidang_loai (LoaiBai, TrangThai, NgayDang),
  KEY idx_baidang_nd (MaND),
  CONSTRAINT fk_baidang_nd  FOREIGN KEY (MaND)  REFERENCES NGUOIDUNG (MaND) ON DELETE CASCADE,
  CONSTRAINT fk_baidang_mon FOREIGN KEY (MaMon) REFERENCES MONHOC (MaMon) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 15. BẢNG BINHLUAN (Bình luận & phản hồi đa cấp bài viết)
-- ---------------------------------------------------------------------
CREATE TABLE BINHLUAN (
  MaBinhLuan     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  MaBaiDang      INT UNSIGNED NOT NULL,
  MaND           INT UNSIGNED NOT NULL,
  MaBinhLuanCha  INT UNSIGNED NULL DEFAULT NULL COMMENT 'NULL = bình luận gốc; khác NULL = phản hồi bình luận cha',
  NoiDung        TEXT NOT NULL,
  NgayBinhLuan   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  TrangThai      ENUM('Hiển thị','Bị ẩn','Đã xóa') NOT NULL DEFAULT 'Hiển thị',
  NguoiAn        ENUM('Chủ bình luận','Quản trị') NULL,
  PRIMARY KEY (MaBinhLuan),
  KEY idx_binhluan_baidang (MaBaiDang, TrangThai),
  KEY idx_binhluan_parent (MaBinhLuanCha),
  KEY idx_binhluan_nd (MaND),
  CONSTRAINT fk_binhluan_baidang FOREIGN KEY (MaBaiDang) REFERENCES BAIDANG (MaBaiDang) ON DELETE CASCADE,
  CONSTRAINT fk_binhluan_nd      FOREIGN KEY (MaND)      REFERENCES NGUOIDUNG (MaND) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 16. BẢNG LUOTTHICH (Lượt thích bài đăng)
-- ---------------------------------------------------------------------
CREATE TABLE LUOTTHICH (
  MaBaiDang  INT UNSIGNED NOT NULL,
  MaND       INT UNSIGNED NOT NULL,
  NgayThich  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (MaBaiDang, MaND),
  KEY idx_luotthich_nd (MaND),
  CONSTRAINT fk_luotthich_baidang FOREIGN KEY (MaBaiDang) REFERENCES BAIDANG (MaBaiDang) ON DELETE CASCADE,
  CONSTRAINT fk_luotthich_nd      FOREIGN KEY (MaND)      REFERENCES NGUOIDUNG (MaND) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 17. BẢNG LUOTTHICH_BL (Lượt thích bình luận)
-- ---------------------------------------------------------------------
CREATE TABLE LUOTTHICH_BL (
  MaBinhLuan INT UNSIGNED NOT NULL,
  MaND       INT UNSIGNED NOT NULL,
  NgayThich  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (MaBinhLuan, MaND),
  KEY idx_luotthichbl_nd (MaND),
  CONSTRAINT fk_luotthichbl_bl FOREIGN KEY (MaBinhLuan) REFERENCES BINHLUAN (MaBinhLuan) ON DELETE CASCADE,
  CONSTRAINT fk_luotthichbl_nd FOREIGN KEY (MaND)       REFERENCES NGUOIDUNG (MaND) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 18. BẢNG THONGBAO (Hệ thống thông báo người dùng)
-- ---------------------------------------------------------------------
CREATE TABLE THONGBAO (
  MaTB        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  MaND        INT UNSIGNED NOT NULL COMMENT 'Người nhận thông báo',
  MaNguoiGui  INT UNSIGNED NOT NULL COMMENT 'Người gây ra thông báo',
  MaBaiDang   INT UNSIGNED NULL,
  MaBinhLuan  INT UNSIGNED NULL,
  Loai        VARCHAR(30) NOT NULL DEFAULT 'binhluan',
  TieuDe      VARCHAR(255) NOT NULL,
  NoiDung     VARCHAR(500) NULL,
  LienKet     VARCHAR(255) NOT NULL,
  DaDoc       TINYINT(1) NOT NULL DEFAULT 0,
  NgayTao     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (MaTB),
  KEY idx_thongbao_user (MaND, DaDoc, NgayTao),
  KEY idx_thongbao_baidang (MaBaiDang),
  CONSTRAINT fk_thongbao_nd       FOREIGN KEY (MaND)       REFERENCES NGUOIDUNG (MaND) ON DELETE CASCADE,
  CONSTRAINT fk_thongbao_nguoigui FOREIGN KEY (MaNguoiGui) REFERENCES NGUOIDUNG (MaND) ON DELETE CASCADE,
  CONSTRAINT fk_thongbao_baidang  FOREIGN KEY (MaBaiDang)  REFERENCES BAIDANG (MaBaiDang) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 19. BẢNG THONGBAO_ADMIN_DADOC (Đánh dấu thông báo Admin đã đọc)
-- ---------------------------------------------------------------------
CREATE TABLE THONGBAO_ADMIN_DADOC (
  MaAdmin    INT UNSIGNED NOT NULL,
  Loai       TINYINT UNSIGNED NOT NULL COMMENT '3 = đề xuất môn học',
  MaDoiTuong INT UNSIGNED NOT NULL COMMENT 'ID đối tượng liên quan (MaMon)',
  NgayDoc    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (MaAdmin, Loai, MaDoiTuong),
  CONSTRAINT fk_tbadmin_admin FOREIGN KEY (MaAdmin) REFERENCES ADMIN (MaAdmin) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 20. BẢNG BANBE (Kết bạn giữa các người chơi)
-- ---------------------------------------------------------------------
CREATE TABLE BANBE (
  MaNguoiGui    INT UNSIGNED NOT NULL,
  MaNguoiNhan   INT UNSIGNED NOT NULL,
  NgayGui       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  TrangThai     ENUM('Chờ','Đã chấp nhận','Từ chối') NOT NULL DEFAULT 'Chờ',
  CapNho        INT UNSIGNED GENERATED ALWAYS AS (LEAST(MaNguoiGui, MaNguoiNhan)) STORED,
  CapLon        INT UNSIGNED GENERATED ALWAYS AS (GREATEST(MaNguoiGui, MaNguoiNhan)) STORED,
  PRIMARY KEY (MaNguoiGui, MaNguoiNhan),
  UNIQUE KEY uq_banbe_cap (CapNho, CapLon),
  KEY idx_banbe_nhan (MaNguoiNhan),
  CONSTRAINT fk_banbe_gui  FOREIGN KEY (MaNguoiGui)  REFERENCES NGUOIDUNG (MaND) ON DELETE CASCADE,
  CONSTRAINT fk_banbe_nhan FOREIGN KEY (MaNguoiNhan) REFERENCES NGUOIDUNG (MaND) ON DELETE CASCADE,
  CONSTRAINT ck_banbe_khac CHECK (MaNguoiGui <> MaNguoiNhan)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 21. BẢNG NHATKYQUANTRI (Nhật ký hành vi quản trị viên & bảo mật)
-- ---------------------------------------------------------------------
CREATE TABLE NHATKYQUANTRI (
  MaNhatKy   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  MaAdmin    INT UNSIGNED NOT NULL,
  HanhDong   VARCHAR(100) NOT NULL,
  DoiTuong   VARCHAR(100) NULL,
  ThoiGian   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  MoTa       TEXT NULL,
  DiaChiIP   VARCHAR(45) NULL,
  TrangThai  ENUM('Thành công','Thất bại') NOT NULL DEFAULT 'Thành công',
  PRIMARY KEY (MaNhatKy),
  KEY idx_nhatky_admin_tg (MaAdmin, ThoiGian),
  KEY idx_nhatky_tg (ThoiGian),
  KEY idx_nhatky_tt (TrangThai, ThoiGian),
  CONSTRAINT fk_nhatky_admin FOREIGN KEY (MaAdmin) REFERENCES ADMIN (MaAdmin) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 22. BẢNG CAUHINH (Cấu hình tham số hệ thống)
-- ---------------------------------------------------------------------
CREATE TABLE CAUHINH (
  MaCauHinh   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  TenCauHinh  VARCHAR(60)  NOT NULL,
  GiaTri      VARCHAR(100) NOT NULL,
  MoTa        VARCHAR(255) NULL,
  PRIMARY KEY (MaCauHinh),
  UNIQUE KEY uq_cauhinh_ten (TenCauHinh)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 23. BẢNG PHONGTHACHDAU (Phòng thi đấu bạn bè 1v1 bằng mã PIN 6 số)
-- ---------------------------------------------------------------------
CREATE TABLE PHONGTHACHDAU (
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
  KEY idx_phong_trangthai (TrangThai, NgayTao),
  CONSTRAINT fk_phong_chu FOREIGN KEY (ChuPhongND) REFERENCES NGUOIDUNG (MaND) ON DELETE CASCADE,
  CONSTRAINT fk_phong_khach FOREIGN KEY (KhachND) REFERENCES NGUOIDUNG (MaND) ON DELETE SET NULL,
  CONSTRAINT fk_phong_mon FOREIGN KEY (MaMon) REFERENCES MONHOC (MaMon),
  CONSTRAINT fk_phong_loaikt FOREIGN KEY (MaLoaiKT) REFERENCES LOAIKYTHI (MaLoaiKT),
  CONSTRAINT fk_phong_tran FOREIGN KEY (MaTran) REFERENCES TRANDAU (MaTran) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 24. BẢNG THONGTHAOTUONG (Cấp độ & Điểm EXP thông thạo tướng / linh vật)
-- ---------------------------------------------------------------------
CREATE TABLE THONGTHAOTUONG (
  MaND         INT UNSIGNED NOT NULL,
  TuongId      VARCHAR(40)  NOT NULL,
  CapDo        TINYINT UNSIGNED NOT NULL DEFAULT 1,
  DiemEXP      INT UNSIGNED NOT NULL DEFAULT 0,
  SoTranThang  INT UNSIGNED NOT NULL DEFAULT 0,
  SoTranDau    INT UNSIGNED NOT NULL DEFAULT 0,
  CapNhatLuc   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (MaND, TuongId),
  CONSTRAINT fk_thongthao_nd FOREIGN KEY (MaND) REFERENCES NGUOIDUNG (MaND) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 25. BẢNG PHATAFK (Quản lý án phạt cấm tìm trận khi bỏ hàng đợi / AFK)
-- ---------------------------------------------------------------------
CREATE TABLE PHATAFK (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 26. BẢNG GIOIHANDANGNHAP (Chống Brute-force mật khẩu theo IP + định danh)
-- ---------------------------------------------------------------------
CREATE TABLE GIOIHANDANGNHAP (
  Khoa      CHAR(64)     NOT NULL COMMENT 'SHA256 của action|IP|identifier',
  HanhDong  VARCHAR(30)  NOT NULL,
  DinhDanh  VARCHAR(150) NOT NULL,
  DiaChiIP  VARCHAR(45)  NOT NULL,
  SoLanSai  INT UNSIGNED NOT NULL DEFAULT 0,
  LanCuoi   DATETIME     NOT NULL,
  PRIMARY KEY (Khoa),
  KEY idx_ghdn_lancuoi (LanCuoi)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 27. BẢNG LICHSUDANGNHAP (Lịch sử đăng nhập & cảnh báo bảo mật)
-- ---------------------------------------------------------------------
CREATE TABLE LICHSUDANGNHAP (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- DỮ LIỆU CỐT LÕI KHỞI TẠO (BẮT BUỘC ĐỂ HỆ THỐNG HOẠT ĐỘNG)
-- =====================================================================

-- 1. Các loại kỳ thi mặc định
INSERT INTO LOAIKYTHI (MaLoaiKT, TenLoaiKT, MoTa) VALUES
  (1, 'Giữa kỳ',  'Câu hỏi ôn thi giữa kỳ'),
  (2, 'Cuối kỳ',  'Câu hỏi ôn thi cuối kỳ'),
  (3, 'Cả 2',     'Câu hỏi ôn thi cả giữa kỳ và cuối kỳ')
ON DUPLICATE KEY UPDATE TenLoaiKT = VALUES(TenLoaiKT);

-- 2. Cấu hình vận hành hệ thống (Admin có thể sửa tại admin/cauhinh.php)
INSERT INTO CAUHINH (TenCauHinh, GiaTri, MoTa) VALUES
  ('SO_CAU',                          '10',   'Số câu mỗi trận (chụp vào CAUHOITRANDAU lúc tạo trận)'),
  ('THOI_GIAN_MOI_CAU',               '15',   'Thời gian trả lời mỗi câu (giây)'),
  ('DIEM_CO_BAN',                     '100',  'Điểm cơ bản cho câu đúng'),
  ('HE_SO_THUONG',                    '50',   'Điểm thưởng tối đa theo tốc độ: HE_SO_THUONG x (còn lại / quy định)'),
  ('K_NGUOI_MOI',                     '32',   'Hệ số K khi người chơi dưới ngưỡng số trận'),
  ('K_NGUOI_CU',                      '24',   'Hệ số K từ ngưỡng số trận trở lên'),
  ('NGUONG_SO_TRAN_DOI_K',            '30',   'Số trận để đổi từ K_NGUOI_MOI sang K_NGUOI_CU'),
  ('ELO_KHOI_TAO',                    '1000', 'Elo ban đầu của một môn'),
  ('NGUONG_ELO_GHEP_DOI',             '100',  'Chênh lệch Elo ban đầu khi ghép; nới dần theo thời gian chờ'),
  ('THOI_GIAN_CHO_GHEP_TOI_DA',       '60',   'Quá thời gian này (giây) chưa ghép được thì rút khỏi hàng đợi'),
  ('THOI_GIAN_CHO_VAO_PHONG',         '30',   'Sau khi ghép, quá thời gian này (giây) có người chưa vào phòng thì hủy trận'),
  ('THOI_GIAN_CHO_PHEP_MAT_KET_NOI',  '30',   'Không có hoạt động quá thời gian này (giây) thì bị xử thua'),
  ('DUNG_SAI_DO_TRE',                 '1',    'Đáp án đến muộn trong khoảng này (giây) vẫn được nhận'),
  ('SO_TRAN_TOI_THIEU_BXH',           '10',   'Số trận tối thiểu của một môn để lên BXH toàn trường'),
  ('THOI_HAN_LIEN_KET_DAT_LAI',       '30',   'Hạn của liên kết đặt lại mật khẩu (phút)'),
  ('NGUONG_TRAN_KEO_DAI',             '10',   'Trận quá thời gian này (phút) bị liệt kê là bất thường'),
  ('NGUONG_BIEN_DONG_ELO',            '150',  'Elo tăng/giảm quá mức này trong khoảng thời gian bên dưới thì cảnh báo'),
  ('KHOANG_BIEN_DONG_ELO',            '24',   'Khoảng thời gian xét biến động Elo (giờ)'),
  ('SCHEMA_VERSION',                  '3',    'Phiên bản cấu trúc CSDL chuẩn hóa')
ON DUPLICATE KEY UPDATE GiaTri = VALUES(GiaTri), MoTa = VALUES(MoTa);

-- 3. Tài khoản Quản trị viên mặc định: admin / admin123
INSERT INTO ADMIN (MaAdmin, TenDangNhap, MatKhau, HoTen, TrangThai) VALUES
  (1, 'admin', '$2y$10$TeACaYhx5feO6rATfKBvZOxNiguhrOek2baislMlQTWetyVg9dia.', 'Quản trị viên', 'Hoạt động')
ON DUPLICATE KEY UPDATE HoTen = VALUES(HoTen), TrangThai = VALUES(TrangThai);

-- 4. Tài khoản Máy AI Đấu Thần dự phòng (dành cho chế độ tự ghép Bot): bot@quizup.arena
INSERT INTO NGUOIDUNG (MaND, HoTen, Email, MatKhau, TrangThai) VALUES
  (999999, 'Zombie Máy AI [Buckethead]', 'bot@quizup.arena', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Hoạt động')
ON DUPLICATE KEY UPDATE HoTen = VALUES(HoTen), TrangThai = 'Hoạt động';

-- =====================================================================
-- DỮ LIỆU MẪU MÔN HỌC, SINH VIÊN VÀ NGÂN HÀNG 60 CÂU HỎI
-- =====================================================================
SET FOREIGN_KEY_CHECKS = 0;

INSERT INTO MONHOC (MaMon, TenMon, MoTa, TrangThai) VALUES
  (1, 'Quản trị cơ sở dữ liệu', 'Môn CIT2521: SQL, giao dịch, chỉ mục, chuẩn hóa', 'Đang mở'),
  (2, 'Phân tích nghiệp vụ phần mềm', 'Môn PTNVPM: use case, yêu cầu, mô hình hóa', 'Đang mở'),
  (3, 'Lập trình PHP', 'PHP, PDO, session, bảo mật web cơ bản', 'Đang mở');

INSERT INTO ADMIN (TenDangNhap, MatKhau, HoTen, TrangThai) VALUES
  ('admin', '$2y$10$TeACaYhx5feO6rATfKBvZOxNiguhrOek2baislMlQTWetyVg9dia.', 'Quản trị viên', 'Hoạt động');

INSERT INTO NGUOIDUNG (HoTen, Email, MatKhau, Lop, Khoa, TrangThai, TrangThaiKhaoSat) VALUES
  ('Nguyễn Văn An', 'an@test.com', '$2y$10$5MOB9RsdS2KQ7u7XSozfRegxPg6munieu4rdL6h9RQKMYgbtH0RTy', 'CNTT01', 'Công nghệ thông tin', 'Hoạt động', 'Hoàn tất'),
  ('Trần Thị Bình', 'binh@test.com', '$2y$10$5MOB9RsdS2KQ7u7XSozfRegxPg6munieu4rdL6h9RQKMYgbtH0RTy', 'CNTT01', 'Công nghệ thông tin', 'Hoạt động', 'Hoàn tất'),
  ('Lê Minh Cường', 'cuong@test.com', '$2y$10$5MOB9RsdS2KQ7u7XSozfRegxPg6munieu4rdL6h9RQKMYgbtH0RTy', 'CNTT02', 'Công nghệ thông tin', 'Hoạt động', 'Hoàn tất'),
  ('Phạm Thu Dung', 'dung@test.com', '$2y$10$5MOB9RsdS2KQ7u7XSozfRegxPg6munieu4rdL6h9RQKMYgbtH0RTy', 'HTTT01', 'Hệ thống thông tin', 'Hoạt động', 'Hoàn tất'),
  ('Hoàng Gia Huy', 'huy@test.com', '$2y$10$5MOB9RsdS2KQ7u7XSozfRegxPg6munieu4rdL6h9RQKMYgbtH0RTy', 'HTTT01', 'Hệ thống thông tin', 'Hoạt động', 'Hoàn tất'),
  ('Vũ Khánh Linh', 'linh@test.com', '$2y$10$5MOB9RsdS2KQ7u7XSozfRegxPg6munieu4rdL6h9RQKMYgbtH0RTy', 'KTPM01', 'Kỹ thuật phần mềm', 'Hoạt động', 'Hoàn tất');

INSERT INTO CAUHOI (MaCauHoi, MaMon, MaLoaiKT, NoiDung, GiaiThich, DoKho, TrangThai) VALUES
  (1, 1, NULL, 'Lệnh SQL nào dùng để truy vấn dữ liệu từ bảng?', 'SELECT dùng để đọc dữ liệu; các lệnh còn lại thêm, sửa, xóa dữ liệu.', 'Dễ', 'Hiển thị'),
  (2, 1, NULL, 'Khóa chính (PRIMARY KEY) có đặc điểm nào?', 'Khóa chính định danh duy nhất mỗi dòng nên không trùng và không NULL.', 'Trung bình', 'Hiển thị'),
  (3, 1, NULL, 'Ràng buộc nào đảm bảo toàn vẹn tham chiếu giữa hai bảng?', 'FOREIGN KEY bắt giá trị phải tồn tại ở bảng cha.', 'Khó', 'Hiển thị'),
  (4, 1, NULL, 'Từ khóa nào loại bỏ các dòng trùng trong kết quả SELECT?', 'SELECT DISTINCT chỉ giữ các dòng khác nhau.', 'Dễ', 'Hiển thị'),
  (5, 1, NULL, 'Mệnh đề nào dùng để lọc các nhóm sau khi GROUP BY?', 'WHERE lọc dòng trước khi nhóm, HAVING lọc nhóm sau khi nhóm.', 'Trung bình', 'Hiển thị'),
  (6, 1, NULL, 'Trong tính chất ACID của giao dịch, chữ A là viết tắt của gì?', 'Atomicity: giao dịch hoặc thực hiện trọn vẹn, hoặc không thực hiện gì.', 'Khó', 'Hiển thị'),
  (7, 1, NULL, 'Lệnh nào hoàn tác các thay đổi của giao dịch hiện tại?', 'ROLLBACK hủy thay đổi chưa COMMIT.', 'Dễ', 'Hiển thị'),
  (8, 1, NULL, 'Mục đích chính của chỉ mục (INDEX) là gì?', 'Chỉ mục giúp tìm dòng nhanh hơn, đổi lại tốn thêm bộ nhớ và chậm khi ghi.', 'Trung bình', 'Hiển thị'),
  (9, 1, 1, 'Phép nối nào giữ lại cả các dòng không khớp của bảng bên trái?', 'LEFT JOIN trả mọi dòng bên trái, bên phải không khớp thì là NULL.', 'Khó', 'Hiển thị'),
  (10, 1, 1, 'Dạng chuẩn 1NF yêu cầu điều gì?', '1NF: giá trị nguyên tố; 2NF và 3NF xử lý phụ thuộc hàm.', 'Dễ', 'Hiển thị'),
  (11, 1, 1, 'Hàm nào đếm số dòng trong SQL?', 'COUNT(*) đếm số dòng, SUM cộng giá trị.', 'Trung bình', 'Hiển thị'),
  (12, 1, 1, 'Lệnh nào xóa bảng cùng toàn bộ cấu trúc của nó?', 'DROP TABLE xóa luôn định nghĩa bảng; DELETE và TRUNCATE chỉ xóa dữ liệu.', 'Khó', 'Hiển thị'),
  (13, 1, 1, 'TRUNCATE TABLE khác DELETE ở điểm nào?', 'TRUNCATE xóa sạch dữ liệu một lần, không lọc theo điều kiện.', 'Dễ', 'Hiển thị'),
  (14, 1, 1, 'Stored procedure là gì?', 'Procedure giúp tái sử dụng logic phía CSDL.', 'Trung bình', 'Hiển thị'),
  (15, 1, 2, 'Trigger là gì?', 'Trigger gắn với bảng và tự kích hoạt theo sự kiện.', 'Khó', 'Hiển thị'),
  (16, 1, 2, 'SELECT ... FOR UPDATE dùng để làm gì?', 'Các giao dịch khác phải chờ đến khi giao dịch này kết thúc.', 'Dễ', 'Hiển thị'),
  (17, 1, 2, 'Deadlock (khóa chết) là tình huống nào?', 'Giữ khóa theo thứ tự cố định giúp tránh deadlock.', 'Trung bình', 'Hiển thị'),
  (18, 1, 2, 'Công cụ nào thường dùng để sao lưu CSDL MySQL bằng dòng lệnh?', 'mysqldump xuất cấu trúc và dữ liệu ra file SQL.', 'Khó', 'Hiển thị'),
  (19, 1, 2, 'Kiểu dữ liệu nào phù hợp để lưu số tiền cần chính xác?', 'DECIMAL lưu số thập phân chính xác, FLOAT/DOUBLE có sai số làm tròn.', 'Dễ', 'Hiển thị'),
  (20, 1, 2, 'Mức cô lập giao dịch nào chặt nhất?', 'SERIALIZABLE chạy các giao dịch như thể tuần tự, ít lỗi đọc nhất nhưng chậm nhất.', 'Trung bình', 'Hiển thị'),
  (21, 2, NULL, 'Sơ đồ use case dùng để mô tả điều gì?', 'Use case cho biết ai dùng hệ thống để làm việc gì.', 'Dễ', 'Hiển thị'),
  (22, 2, NULL, 'Tác nhân (actor) trong use case là gì?', 'Actor có thể là người dùng hoặc hệ thống khác.', 'Trung bình', 'Hiển thị'),
  (23, 2, NULL, 'Yêu cầu "hệ thống phản hồi trong 1 giây" thuộc loại nào?', 'Phi chức năng mô tả chất lượng như tốc độ, bảo mật, độ tin cậy.', 'Khó', 'Hiển thị'),
  (24, 2, NULL, 'Quan hệ <<include>> giữa hai use case có nghĩa là gì?', 'Include là phần bắt buộc dùng lại; extend mới là tùy chọn.', 'Dễ', 'Hiển thị'),
  (25, 2, NULL, 'Quan hệ <<extend>> có nghĩa là gì?', 'Extend dành cho hành vi tùy chọn, có điều kiện.', 'Trung bình', 'Hiển thị'),
  (26, 2, NULL, 'Sơ đồ ERD dùng để làm gì?', 'ERD là nền tảng để thiết kế cơ sở dữ liệu.', 'Khó', 'Hiển thị'),
  (27, 2, NULL, 'Bản số 1 : n giữa A và B nghĩa là gì?', 'Ví dụ một môn học có nhiều câu hỏi.', 'Dễ', 'Hiển thị'),
  (28, 2, NULL, 'Một user story thường được viết theo mẫu nào?', 'Mẫu này nhấn mạnh người dùng và giá trị mang lại.', 'Trung bình', 'Hiển thị'),
  (29, 2, 1, 'Bên liên quan (stakeholder) là ai?', 'Stakeholder gồm người dùng, khách hàng, quản lý, đội phát triển...', 'Khó', 'Hiển thị'),
  (30, 2, 1, 'Kỹ thuật thu thập yêu cầu bằng cách hỏi trực tiếp người dùng là gì?', 'Phỏng vấn giúp làm rõ nhu cầu qua trao đổi trực tiếp.', 'Dễ', 'Hiển thị'),
  (31, 2, 1, 'Sơ đồ hoạt động (activity diagram) thường mô tả điều gì?', 'Activity diagram mô tả quy trình nghiệp vụ từng bước.', 'Trung bình', 'Hiển thị'),
  (32, 2, 1, 'Sơ đồ tuần tự (sequence diagram) nhấn mạnh điều gì?', 'Trục dọc của sơ đồ là thời gian.', 'Khó', 'Hiển thị'),
  (33, 2, 1, 'Tiền điều kiện (precondition) của use case là gì?', 'Ví dụ: sinh viên phải đăng nhập trước khi tìm trận.', 'Dễ', 'Hiển thị'),
  (34, 2, 1, 'Hậu điều kiện (postcondition) là gì?', 'Hậu điều kiện cho biết hệ thống đã thay đổi như thế nào.', 'Trung bình', 'Hiển thị'),
  (35, 2, 2, 'Luồng ngoại lệ trong mô tả use case dùng để làm gì?', 'Ví dụ: nhập sai mật khẩu, không đủ câu hỏi để tìm trận.', 'Khó', 'Hiển thị'),
  (36, 2, 2, 'Một yêu cầu phần mềm tốt cần có đặc điểm nào?', 'Yêu cầu đo lường được thì mới kiểm tra được hệ thống đã đáp ứng chưa.', 'Dễ', 'Hiển thị'),
  (37, 2, 2, 'Mô hình thác nước (waterfall) có đặc điểm nào?', 'Waterfall khó thay đổi yêu cầu giữa chừng.', 'Trung bình', 'Hiển thị'),
  (38, 2, 2, 'Trong Scrum, Sprint là gì?', 'Sprint thường kéo dài 1 đến 4 tuần.', 'Khó', 'Hiển thị'),
  (39, 2, 2, 'Product Backlog là gì?', 'Product Owner quản lý và sắp xếp Product Backlog.', 'Dễ', 'Hiển thị'),
  (40, 2, 2, 'Tài liệu SRS là gì?', 'SRS (Software Requirements Specification) ghi lại yêu cầu chức năng và phi chức năng.', 'Trung bình', 'Hiển thị'),
  (41, 3, NULL, 'PHP chủ yếu được thực thi ở phía nào?', 'Máy chủ chạy PHP rồi gửi HTML kết quả về trình duyệt.', 'Dễ', 'Hiển thị'),
  (42, 3, NULL, 'Biến trong PHP bắt đầu bằng ký tự nào?', 'Ví dụ: $ten = ''An'';', 'Trung bình', 'Hiển thị'),
  (43, 3, NULL, 'Toán tử nào dùng để nối chuỗi trong PHP?', 'Ví dụ: ''Xin '' . ''chào''.', 'Khó', 'Hiển thị'),
  (44, 3, NULL, 'Hàm nào dùng để băm mật khẩu trước khi lưu?', 'password_hash tạo băm có muối, an toàn hơn md5.', 'Dễ', 'Hiển thị'),
  (45, 3, NULL, 'Hàm nào kiểm tra mật khẩu người dùng nhập với chuỗi băm đã lưu?', 'password_verify so khớp mật khẩu với băm của password_hash.', 'Trung bình', 'Hiển thị'),
  (46, 3, NULL, 'Cách nào phòng chống SQL Injection hiệu quả nhất?', 'Prepared statement tách câu lệnh SQL khỏi dữ liệu.', 'Khó', 'Hiển thị'),
  (47, 3, NULL, 'Hàm nào dùng để chống XSS khi in dữ liệu ra HTML?', 'Hàm này đổi ký tự đặc biệt thành thực thể HTML.', 'Dễ', 'Hiển thị'),
  (48, 3, NULL, 'Dữ liệu từ form gửi bằng phương thức POST nằm ở đâu?', 'Form method="post" đưa dữ liệu vào $_POST.', 'Trung bình', 'Hiển thị'),
  (49, 3, 1, 'Hàm nào bắt đầu một phiên (session) trong PHP?', 'Phải gọi trước khi dùng $_SESSION.', 'Khó', 'Hiển thị'),
  (50, 3, 1, 'Làm thế nào để chuyển hướng sang trang khác trong PHP?', 'Nên gọi exit sau header để dừng xử lý.', 'Dễ', 'Hiển thị'),
  (51, 3, 1, 'Khác biệt giữa require và include là gì?', 'include chỉ cảnh báo còn require dừng chương trình.', 'Trung bình', 'Hiển thị'),
  (52, 3, 1, 'PDO trong PHP là gì?', 'PDO hỗ trợ MySQL, PostgreSQL, SQLite... bằng cùng một cách.', 'Khó', 'Hiển thị'),
  (53, 3, 1, 'Mảng kết hợp (associative array) trong PHP là gì?', 'Ví dụ: [''ten'' => ''An'', ''tuoi'' => 20].', 'Dễ', 'Hiển thị'),
  (54, 3, 1, 'Hàm nào đếm số phần tử của một mảng?', 'count($mang) trả về số phần tử.', 'Trung bình', 'Hiển thị'),
  (55, 3, 2, 'Từ khóa nào dùng để khai báo một lớp trong PHP?', 'Ví dụ: class NguoiDung { ... }.', 'Khó', 'Hiển thị'),
  (56, 3, 2, 'Từ khóa nào dùng để ném một ngoại lệ?', 'throw new Exception(''...''); catch bắt ngoại lệ.', 'Dễ', 'Hiển thị'),
  (57, 3, 2, 'Toán tử so sánh nào kiểm tra cả giá trị lẫn kiểu dữ liệu?', '=== trả về true chỉ khi giá trị và kiểu đều giống nhau.', 'Trung bình', 'Hiển thị'),
  (58, 3, 2, 'Hàm isset($x) trả về true khi nào?', 'Khác với empty(), isset chỉ kiểm tra tồn tại và khác NULL.', 'Khó', 'Hiển thị'),
  (59, 3, 2, 'Biến siêu toàn cục nào chứa thông tin file được tải lên?', 'Khi form có enctype multipart/form-data thì file nằm trong $_FILES.', 'Dễ', 'Hiển thị'),
  (60, 3, 2, 'Cách phổ biến để chống tấn công CSRF là gì?', 'Token chỉ trang của mình mới có nên trang giả mạo không gửi đúng được.', 'Trung bình', 'Hiển thị');

INSERT INTO DAPAN (MaCauHoi, NoiDungDapAn, LaDapAnDung) VALUES
  (1, 'UPDATE', 0),
  (1, 'DELETE', 0),
  (1, 'INSERT', 0),
  (1, 'SELECT', 1),
  (2, 'Giá trị duy nhất và không được NULL', 1),
  (2, 'Luôn là kiểu chuỗi', 0),
  (2, 'Được phép NULL nhưng không trùng', 0),
  (2, 'Có thể trùng nhưng không NULL', 0),
  (3, 'FOREIGN KEY', 1),
  (3, 'UNIQUE', 0),
  (3, 'CHECK', 0),
  (3, 'DEFAULT', 0),
  (4, 'ONLY', 0),
  (4, 'GROUP', 0),
  (4, 'DISTINCT', 1),
  (4, 'UNIQUE', 0),
  (5, 'ORDER BY', 0),
  (5, 'LIMIT', 0),
  (5, 'WHERE', 0),
  (5, 'HAVING', 1),
  (6, 'Accuracy', 0),
  (6, 'Availability', 0),
  (6, 'Atomicity (nguyên tử)', 1),
  (6, 'Authority', 0),
  (7, 'UNDO', 0),
  (7, 'COMMIT', 0),
  (7, 'ROLLBACK', 1),
  (7, 'SAVE', 0),
  (8, 'Tăng tốc độ tìm kiếm và truy vấn', 1),
  (8, 'Mã hóa dữ liệu', 0),
  (8, 'Giảm dung lượng dữ liệu', 0),
  (8, 'Tự động sao lưu dữ liệu', 0),
  (9, 'INNER JOIN', 0),
  (9, 'LEFT JOIN', 1),
  (9, 'CROSS JOIN', 0),
  (9, 'SELF JOIN', 0),
  (10, 'Bảng không có khóa ngoại', 0),
  (10, 'Không có phụ thuộc bắc cầu', 0),
  (10, 'Mỗi thuộc tính chỉ chứa giá trị nguyên tố, không đa trị hay lặp', 1),
  (10, 'Mọi thuộc tính phụ thuộc đầy đủ vào khóa chính', 0),
  (11, 'LENGTH()', 0),
  (11, 'COUNT()', 1),
  (11, 'SUM()', 0),
  (11, 'TOTAL()', 0),
  (12, 'REMOVE TABLE', 0),
  (12, 'DELETE FROM', 0),
  (12, 'DROP TABLE', 1),
  (12, 'TRUNCATE TABLE', 0),
  (13, 'Xóa toàn bộ dòng nhanh và không dùng được WHERE', 1),
  (13, 'Chỉ xóa các dòng bị trùng', 0),
  (13, 'Xóa luôn cấu trúc bảng', 0),
  (13, 'Cho phép hoàn tác trong mọi trường hợp', 0),
  (14, 'Một kiểu dữ liệu', 0),
  (14, 'Bản sao lưu của CSDL', 0),
  (14, 'Một loại chỉ mục', 0),
  (14, 'Tập lệnh SQL được lưu sẵn trong CSDL và gọi bằng tên', 1),
  (15, 'Công cụ phân quyền', 0),
  (15, 'Một dạng khóa ngoại', 0),
  (15, 'Lệnh dùng để sao lưu', 0),
  (15, 'Đoạn mã tự chạy khi có INSERT, UPDATE hoặc DELETE', 1),
  (16, 'Khóa các dòng được đọc trong giao dịch để tránh xung đột ghi', 1),
  (16, 'Cập nhật tự động các dòng được chọn', 0),
  (16, 'Tăng tốc truy vấn', 0),
  (16, 'Sao chép dòng sang bảng khác', 0),
  (17, 'Hai giao dịch chờ khóa của nhau nên không bên nào tiếp tục được', 1),
  (17, 'Một giao dịch chạy quá lâu', 0),
  (17, 'Mất kết nối đến máy chủ', 0),
  (17, 'Bảng bị đầy dung lượng', 0),
  (18, 'mysqlcheck', 0),
  (18, 'mysqladmin ping', 0),
  (18, 'mysqlshow', 0),
  (18, 'mysqldump', 1),
  (19, 'TEXT', 0),
  (19, 'DOUBLE', 0),
  (19, 'DECIMAL', 1),
  (19, 'FLOAT', 0),
  (20, 'READ COMMITTED', 0),
  (20, 'SERIALIZABLE', 1),
  (20, 'READ UNCOMMITTED', 0),
  (20, 'REPEATABLE READ', 0),
  (21, 'Cấu trúc các bảng dữ liệu', 0),
  (21, 'Thứ tự thông điệp theo thời gian', 0),
  (21, 'Các chức năng của hệ thống theo góc nhìn của tác nhân', 1),
  (21, 'Cách triển khai mã nguồn', 0),
  (22, 'Đối tượng bên ngoài tương tác với hệ thống', 1),
  (22, 'Một bảng trong CSDL', 0),
  (22, 'Một module của hệ thống', 0),
  (22, 'Một lớp trong mã nguồn', 0),
  (23, 'Yêu cầu phi chức năng (hiệu năng)', 1),
  (23, 'Yêu cầu chức năng', 0),
  (23, 'Yêu cầu về dữ liệu', 0),
  (23, 'Ràng buộc pháp lý', 0),
  (24, 'Use case gốc luôn gọi use case được include', 1),
  (24, 'Use case con chỉ chạy khi có điều kiện', 0),
  (24, 'Use case con kế thừa use case cha', 0),
  (24, 'Hai use case loại trừ nhau', 0),
  (25, 'Hai tác nhân dùng chung use case', 0),
  (25, 'Use case mở rộng chỉ chạy khi thỏa điều kiện', 1),
  (25, 'Use case luôn được gọi', 0),
  (25, 'Use case bị xóa khỏi hệ thống', 0),
  (26, 'Lập lịch dự án', 0),
  (26, 'Mô hình hóa các thực thể dữ liệu và quan hệ giữa chúng', 1),
  (26, 'Mô tả luồng công việc', 0),
  (26, 'Mô tả giao diện người dùng', 0),
  (27, 'Nhiều A liên kết nhiều B', 0),
  (27, 'Một A chỉ có đúng một B', 0),
  (27, 'A và B không liên quan', 0),
  (27, 'Một bản ghi của A liên kết nhiều bản ghi của B', 1),
  (28, 'Nếu <điều kiện> thì <kết quả>', 0),
  (28, 'Là một <vai trò>, tôi muốn <việc cần làm> để <lợi ích>', 1),
  (28, 'Hệ thống phải <hành động>', 0),
  (28, 'Bảng <tên> gồm các cột <danh sách>', 0),
  (29, 'Người có lợi ích hoặc bị ảnh hưởng bởi hệ thống', 1),
  (29, 'Chỉ lập trình viên của dự án', 0),
  (29, 'Máy chủ chạy hệ thống', 0),
  (29, 'Chỉ khách hàng trả tiền', 0),
  (30, 'Phỏng vấn', 1),
  (30, 'Quan sát tài liệu', 0),
  (30, 'Prototype', 0),
  (30, 'Brainstorm nhóm', 0),
  (31, 'Quan hệ giữa các bảng', 0),
  (31, 'Luồng công việc hoặc quy trình gồm các bước và rẽ nhánh', 1),
  (31, 'Cấu trúc thư mục dự án', 0),
  (31, 'Danh sách tác nhân', 0),
  (32, 'Thứ tự các thông điệp giữa các đối tượng theo thời gian', 1),
  (32, 'Chi phí dự án', 0),
  (32, 'Quan hệ giữa các bảng dữ liệu', 0),
  (32, 'Cấu trúc phân cấp tổ chức', 0),
  (33, 'Số lần use case được dùng', 0),
  (33, 'Danh sách lỗi có thể xảy ra', 0),
  (33, 'Kết quả sau khi use case chạy xong', 0),
  (33, 'Điều kiện phải đúng trước khi use case bắt đầu', 1),
  (34, 'Tên của tác nhân chính', 0),
  (34, 'Điều kiện để use case bắt đầu', 0),
  (34, 'Trạng thái hệ thống sau khi use case thực hiện thành công', 1),
  (34, 'Giao diện của use case', 0),
  (35, 'Liệt kê các tác nhân', 0),
  (35, 'Mô tả luồng chính thành công', 0),
  (35, 'Mô tả các tình huống lỗi hoặc khác thường', 1),
  (35, 'Liệt kê yêu cầu phi chức năng', 0),
  (36, 'Chứa chi tiết cài đặt mã nguồn', 0),
  (36, 'Rõ ràng, không mơ hồ và kiểm thử được', 1),
  (36, 'Viết càng ngắn càng tốt', 0),
  (36, 'Dùng nhiều thuật ngữ chuyên ngành', 0),
  (37, 'Các giai đoạn thực hiện tuần tự, xong giai đoạn này mới sang giai đoạn sau', 1),
  (37, 'Luôn bắt đầu từ lập trình', 0),
  (37, 'Không có giai đoạn kiểm thử', 0),
  (37, 'Lặp lại ngắn và liên tục nhận phản hồi', 0),
  (38, 'Một vai trò trong nhóm', 0),
  (38, 'Khoảng thời gian cố định để hoàn thành một phần công việc', 1),
  (38, 'Danh sách các lỗi', 0),
  (38, 'Một cuộc họp cuối dự án', 0),
  (39, 'Tài liệu thiết kế CSDL', 0),
  (39, 'Danh sách lỗi đã sửa', 0),
  (39, 'Biên bản họp nhóm', 0),
  (39, 'Danh sách yêu cầu của sản phẩm được sắp xếp theo mức ưu tiên', 1),
  (40, 'Kế hoạch kiểm thử', 0),
  (40, 'Báo cáo tài chính dự án', 0),
  (40, 'Tài liệu hướng dẫn cài đặt', 0),
  (40, 'Đặc tả yêu cầu phần mềm', 1),
  (41, 'Card mạng', 0),
  (41, 'Hệ điều hành của người dùng', 0),
  (41, 'Máy chủ (server-side)', 1),
  (41, 'Trình duyệt', 0),
  (42, 'Dấu $', 1),
  (42, 'Dấu @', 0),
  (42, 'Dấu &', 0),
  (42, 'Dấu #', 0),
  (43, 'Dấu phẩy (,)', 0),
  (43, 'Dấu gạch nối (-)', 0),
  (43, 'Dấu chấm (.)', 1),
  (43, 'Dấu cộng (+)', 0),
  (44, 'md5()', 0),
  (44, 'password_hash()', 1),
  (44, 'base64_encode()', 0),
  (44, 'strrev()', 0),
  (45, 'hash_equals()', 0),
  (45, 'password_verify()', 1),
  (45, 'strcmp()', 0),
  (45, 'password_check()', 0),
  (46, 'Ẩn thông báo lỗi', 0),
  (46, 'Dùng md5 cho dữ liệu nhập', 0),
  (46, 'Dùng prepared statement với tham số (PDO)', 1),
  (46, 'Giới hạn độ dài ô nhập', 0),
  (47, 'trim()', 0),
  (47, 'strtolower()', 0),
  (47, 'htmlspecialchars()', 1),
  (47, 'addslashes()', 0),
  (48, '$_COOKIE', 0),
  (48, '$_POST', 1),
  (48, '$_GET', 0),
  (48, '$_SERVER', 0),
  (49, 'session_open()', 0),
  (49, 'session_start()', 1),
  (49, 'begin_session()', 0),
  (49, 'start_session()', 0),
  (50, 'redirect_to(''trang.php'')', 0),
  (50, 'echo ''Location: trang.php''', 0),
  (50, 'goto(''trang.php'')', 0),
  (50, 'header(''Location: trang.php'')', 1),
  (51, 'Hai hàm hoàn toàn giống nhau', 0),
  (51, 'require chỉ dùng được với file .html', 0),
  (51, 'include dừng chương trình khi thiếu file', 0),
  (51, 'require gây lỗi nghiêm trọng và dừng nếu không tìm thấy file', 1),
  (52, 'Một thư viện tạo biểu đồ', 0),
  (52, 'Một framework giao diện', 0),
  (52, 'Một công cụ gửi email', 0),
  (52, 'Lớp truy cập CSDL thống nhất cho nhiều hệ quản trị', 1),
  (53, 'Mảng dùng cặp khóa => giá trị', 1),
  (53, 'Mảng chỉ chứa số nguyên', 0),
  (53, 'Mảng có đúng hai phần tử', 0),
  (53, 'Mảng không thể thay đổi', 0),
  (54, 'length()', 0),
  (54, 'count()', 1),
  (54, 'size()', 0),
  (54, 'total()', 0),
  (55, 'struct', 0),
  (55, 'class', 1),
  (55, 'object', 0),
  (55, 'module', 0),
  (56, 'catch', 0),
  (56, 'throw', 1),
  (56, 'error', 0),
  (56, 'raise', 0),
  (57, '===', 1),
  (57, '==', 0),
  (57, '=', 0),
  (57, '<=>', 0),
  (58, 'Biến $x có giá trị khác 0', 0),
  (58, 'Biến $x đã được khai báo và không phải NULL', 1),
  (58, 'Biến $x là chuỗi không rỗng', 0),
  (58, 'Biến $x là kiểu số', 0),
  (59, '$_REQUEST', 0),
  (59, '$_UPLOAD', 0),
  (59, '$_POST', 0),
  (59, '$_FILES', 1),
  (60, 'Mã hóa mật khẩu bằng md5', 0),
  (60, 'Chỉ cho phép phương thức GET', 0),
  (60, 'Tắt cookie của trình duyệt', 0),
  (60, 'Gắn token bí mật vào form và kiểm tra ở máy chủ', 1);















  -- Dữ liệu thử nghiệm: 2 môn, mỗi môn 12 câu (loại kỳ thi để trống = dùng cho cả Giữa kỳ và Cuối kỳ).
-- Chạy MỘT LẦN trên CSDL elo_quiz (cần đã có bảng và dữ liệu LOAIKYTHI, CAUHINH).
-- Muốn xóa sau khi thử: xem phần cuối file.
SET NAMES utf8mb4;

INSERT INTO MONHOC (TenMon, MoTa, TrangThai) VALUES ('Tin học cơ bản (thử nghiệm)', 'Môn mẫu để thử ghép đôi và thi đấu', 'Đang mở');
SET @mon1 = LAST_INSERT_ID();
INSERT INTO CAUHOI (MaMon, MaLoaiKT, NoiDung, GiaiThich, DoKho) VALUES (@mon1, NULL, '1 byte bằng bao nhiêu bit?', '1 byte gồm 8 bit.', 'Dễ');
SET @c = LAST_INSERT_ID();
INSERT INTO DAPAN (MaCauHoi, NoiDungDapAn, LaDapAnDung) VALUES (@c, '4', 0),(@c, '8', 1),(@c, '16', 0),(@c, '32', 0);
INSERT INTO CAUHOI (MaMon, MaLoaiKT, NoiDung, GiaiThich, DoKho) VALUES (@mon1, NULL, 'CPU là viết tắt của cụm từ nào?', 'CPU là bộ xử lý trung tâm.', 'Dễ');
SET @c = LAST_INSERT_ID();
INSERT INTO DAPAN (MaCauHoi, NoiDungDapAn, LaDapAnDung) VALUES (@c, 'Central Processing Unit', 1),(@c, 'Computer Personal Unit', 0),(@c, 'Central Program Utility', 0),(@c, 'Core Processing Update', 0);
INSERT INTO CAUHOI (MaMon, MaLoaiKT, NoiDung, GiaiThich, DoKho) VALUES (@mon1, NULL, 'Hệ nhị phân dùng những chữ số nào?', 'Hệ nhị phân chỉ có hai chữ số 0 và 1.', 'Dễ');
SET @c = LAST_INSERT_ID();
INSERT INTO DAPAN (MaCauHoi, NoiDungDapAn, LaDapAnDung) VALUES (@c, '0 đến 9', 0),(@c, '1 và 2', 0),(@c, '0 và 1', 1),(@c, '0, 1 và 2', 0);
INSERT INTO CAUHOI (MaMon, MaLoaiKT, NoiDung, GiaiThich, DoKho) VALUES (@mon1, NULL, 'Phần mở rộng phổ biến của tệp trang web là gì?', 'Trang web thường lưu ở tệp .html.', 'Dễ');
SET @c = LAST_INSERT_ID();
INSERT INTO DAPAN (MaCauHoi, NoiDungDapAn, LaDapAnDung) VALUES (@c, '.exe', 0),(@c, '.mp3', 0),(@c, '.docx', 0),(@c, '.html', 1);
INSERT INTO CAUHOI (MaMon, MaLoaiKT, NoiDung, GiaiThich, DoKho) VALUES (@mon1, NULL, 'HTTP là viết tắt của cụm từ nào?', 'HTTP là giao thức truyền siêu văn bản.', 'Trung bình');
SET @c = LAST_INSERT_ID();
INSERT INTO DAPAN (MaCauHoi, NoiDungDapAn, LaDapAnDung) VALUES (@c, 'HyperText Transfer Protocol', 1),(@c, 'High Transfer Text Program', 0),(@c, 'Host Text Transmission Path', 0),(@c, 'HyperText Transmission Process', 0);
INSERT INTO CAUHOI (MaMon, MaLoaiKT, NoiDung, GiaiThich, DoKho) VALUES (@mon1, NULL, 'Thiết bị nào sau đây là thiết bị nhập?', 'Bàn phím dùng để đưa dữ liệu vào máy tính.', 'Dễ');
SET @c = LAST_INSERT_ID();
INSERT INTO DAPAN (MaCauHoi, NoiDungDapAn, LaDapAnDung) VALUES (@c, 'Máy in', 0),(@c, 'Bàn phím', 1),(@c, 'Loa', 0),(@c, 'Màn hình', 0);
INSERT INTO CAUHOI (MaMon, MaLoaiKT, NoiDung, GiaiThich, DoKho) VALUES (@mon1, NULL, 'RAM có đặc điểm nào sau đây?', 'RAM là bộ nhớ tạm, mất dữ liệu khi tắt nguồn.', 'Trung bình');
SET @c = LAST_INSERT_ID();
INSERT INTO DAPAN (MaCauHoi, NoiDungDapAn, LaDapAnDung) VALUES (@c, 'Giữ dữ liệu khi tắt nguồn', 0),(@c, 'Chỉ để đọc, không ghi được', 0),(@c, 'Mất dữ liệu khi tắt nguồn', 1),(@c, 'Chỉ dùng để lưu ảnh', 0);
INSERT INTO CAUHOI (MaMon, MaLoaiKT, NoiDung, GiaiThich, DoKho) VALUES (@mon1, NULL, 'Ngôn ngữ nào dùng để truy vấn cơ sở dữ liệu quan hệ?', 'SQL là ngôn ngữ truy vấn có cấu trúc.', 'Dễ');
SET @c = LAST_INSERT_ID();
INSERT INTO DAPAN (MaCauHoi, NoiDungDapAn, LaDapAnDung) VALUES (@c, 'HTML', 0),(@c, 'CSS', 0),(@c, 'JSON', 0),(@c, 'SQL', 1);
INSERT INTO CAUHOI (MaMon, MaLoaiKT, NoiDung, GiaiThich, DoKho) VALUES (@mon1, NULL, 'Phím tắt sao chép trong Windows là gì?', 'Ctrl + C để sao chép, Ctrl + V để dán.', 'Dễ');
SET @c = LAST_INSERT_ID();
INSERT INTO DAPAN (MaCauHoi, NoiDungDapAn, LaDapAnDung) VALUES (@c, 'Ctrl + C', 1),(@c, 'Ctrl + V', 0),(@c, 'Ctrl + X', 0),(@c, 'Ctrl + Z', 0);
INSERT INTO CAUHOI (MaMon, MaLoaiKT, NoiDung, GiaiThich, DoKho) VALUES (@mon1, NULL, '1 KB bằng bao nhiêu byte (theo cách tính nhị phân)?', '1 KB = 1024 byte.', 'Trung bình');
SET @c = LAST_INSERT_ID();
INSERT INTO DAPAN (MaCauHoi, NoiDungDapAn, LaDapAnDung) VALUES (@c, '1000', 0),(@c, '1024', 1),(@c, '2048', 0),(@c, '512', 0);
INSERT INTO CAUHOI (MaMon, MaLoaiKT, NoiDung, GiaiThich, DoKho) VALUES (@mon1, NULL, 'Địa chỉ IPv4 gồm mấy nhóm số?', 'IPv4 gồm 4 nhóm số, ví dụ 192.168.1.1.', 'Trung bình');
SET @c = LAST_INSERT_ID();
INSERT INTO DAPAN (MaCauHoi, NoiDungDapAn, LaDapAnDung) VALUES (@c, '2', 0),(@c, '3', 0),(@c, '4', 1),(@c, '6', 0);
INSERT INTO CAUHOI (MaMon, MaLoaiKT, NoiDung, GiaiThich, DoKho) VALUES (@mon1, NULL, 'Hệ điều hành nào sau đây là mã nguồn mở?', 'Linux là hệ điều hành mã nguồn mở.', 'Dễ');
SET @c = LAST_INSERT_ID();
INSERT INTO DAPAN (MaCauHoi, NoiDungDapAn, LaDapAnDung) VALUES (@c, 'Windows', 0),(@c, 'macOS', 0),(@c, 'iOS', 0),(@c, 'Linux', 1);

INSERT INTO MONHOC (TenMon, MoTa, TrangThai) VALUES ('Toán cơ bản (thử nghiệm)', 'Môn mẫu để thử ghép đôi và thi đấu', 'Đang mở');
SET @mon2 = LAST_INSERT_ID();
INSERT INTO CAUHOI (MaMon, MaLoaiKT, NoiDung, GiaiThich, DoKho) VALUES (@mon2, NULL, '15 + 27 bằng bao nhiêu?', '15 + 27 = 42.', 'Dễ');
SET @c = LAST_INSERT_ID();
INSERT INTO DAPAN (MaCauHoi, NoiDungDapAn, LaDapAnDung) VALUES (@c, '42', 1),(@c, '41', 0),(@c, '43', 0),(@c, '52', 0);
INSERT INTO CAUHOI (MaMon, MaLoaiKT, NoiDung, GiaiThich, DoKho) VALUES (@mon2, NULL, '9 × 8 bằng bao nhiêu?', '9 × 8 = 72.', 'Dễ');
SET @c = LAST_INSERT_ID();
INSERT INTO DAPAN (MaCauHoi, NoiDungDapAn, LaDapAnDung) VALUES (@c, '63', 0),(@c, '81', 0),(@c, '72', 1),(@c, '64', 0);
INSERT INTO CAUHOI (MaMon, MaLoaiKT, NoiDung, GiaiThich, DoKho) VALUES (@mon2, NULL, 'Căn bậc hai của 144 là bao nhiêu?', '12 × 12 = 144.', 'Dễ');
SET @c = LAST_INSERT_ID();
INSERT INTO DAPAN (MaCauHoi, NoiDungDapAn, LaDapAnDung) VALUES (@c, '11', 0),(@c, '14', 0),(@c, '16', 0),(@c, '12', 1);
INSERT INTO CAUHOI (MaMon, MaLoaiKT, NoiDung, GiaiThich, DoKho) VALUES (@mon2, NULL, '100 − 37 bằng bao nhiêu?', '100 − 37 = 63.', 'Dễ');
SET @c = LAST_INSERT_ID();
INSERT INTO DAPAN (MaCauHoi, NoiDungDapAn, LaDapAnDung) VALUES (@c, '73', 0),(@c, '63', 1),(@c, '67', 0),(@c, '53', 0);
INSERT INTO CAUHOI (MaMon, MaLoaiKT, NoiDung, GiaiThich, DoKho) VALUES (@mon2, NULL, '2 mũ 5 bằng bao nhiêu?', '2^5 = 2 × 2 × 2 × 2 × 2 = 32.', 'Dễ');
SET @c = LAST_INSERT_ID();
INSERT INTO DAPAN (MaCauHoi, NoiDungDapAn, LaDapAnDung) VALUES (@c, '32', 1),(@c, '25', 0),(@c, '16', 0),(@c, '64', 0);
INSERT INTO CAUHOI (MaMon, MaLoaiKT, NoiDung, GiaiThich, DoKho) VALUES (@mon2, NULL, '25% của 80 là bao nhiêu?', '80 × 25 / 100 = 20.', 'Trung bình');
SET @c = LAST_INSERT_ID();
INSERT INTO DAPAN (MaCauHoi, NoiDungDapAn, LaDapAnDung) VALUES (@c, '25', 0),(@c, '40', 0),(@c, '20', 1),(@c, '16', 0);
INSERT INTO CAUHOI (MaMon, MaLoaiKT, NoiDung, GiaiThich, DoKho) VALUES (@mon2, NULL, 'Chu vi hình vuông cạnh 6 là bao nhiêu?', 'Chu vi = 4 × 6 = 24.', 'Dễ');
SET @c = LAST_INSERT_ID();
INSERT INTO DAPAN (MaCauHoi, NoiDungDapAn, LaDapAnDung) VALUES (@c, '12', 0),(@c, '36', 0),(@c, '18', 0),(@c, '24', 1);
INSERT INTO CAUHOI (MaMon, MaLoaiKT, NoiDung, GiaiThich, DoKho) VALUES (@mon2, NULL, 'Diện tích hình chữ nhật có chiều dài 7 và chiều rộng 5 là bao nhiêu?', 'Diện tích = 7 × 5 = 35.', 'Dễ');
SET @c = LAST_INSERT_ID();
INSERT INTO DAPAN (MaCauHoi, NoiDungDapAn, LaDapAnDung) VALUES (@c, '35', 1),(@c, '24', 0),(@c, '12', 0),(@c, '30', 0);
INSERT INTO CAUHOI (MaMon, MaLoaiKT, NoiDung, GiaiThich, DoKho) VALUES (@mon2, NULL, '3/4 + 1/4 bằng bao nhiêu?', '3/4 + 1/4 = 4/4 = 1.', 'Trung bình');
SET @c = LAST_INSERT_ID();
INSERT INTO DAPAN (MaCauHoi, NoiDungDapAn, LaDapAnDung) VALUES (@c, '4/8', 0),(@c, '1', 1),(@c, '3/16', 0),(@c, '1/2', 0);
INSERT INTO CAUHOI (MaMon, MaLoaiKT, NoiDung, GiaiThich, DoKho) VALUES (@mon2, NULL, 'Số nguyên tố nhỏ nhất là số nào?', '2 là số nguyên tố nhỏ nhất.', 'Trung bình');
SET @c = LAST_INSERT_ID();
INSERT INTO DAPAN (MaCauHoi, NoiDungDapAn, LaDapAnDung) VALUES (@c, '0', 0),(@c, '1', 0),(@c, '3', 0),(@c, '2', 1);
INSERT INTO CAUHOI (MaMon, MaLoaiKT, NoiDung, GiaiThich, DoKho) VALUES (@mon2, NULL, '7 × 6 − 10 bằng bao nhiêu?', '42 − 10 = 32.', 'Trung bình');
SET @c = LAST_INSERT_ID();
INSERT INTO DAPAN (MaCauHoi, NoiDungDapAn, LaDapAnDung) VALUES (@c, '32', 1),(@c, '42', 0),(@c, '36', 0),(@c, '30', 0);
INSERT INTO CAUHOI (MaMon, MaLoaiKT, NoiDung, GiaiThich, DoKho) VALUES (@mon2, NULL, 'Trung bình cộng của 4, 8 và 12 là bao nhiêu?', '(4 + 8 + 12) / 3 = 8.', 'Trung bình');
SET @c = LAST_INSERT_ID();
INSERT INTO DAPAN (MaCauHoi, NoiDungDapAn, LaDapAnDung) VALUES (@c, '6', 0),(@c, '12', 0),(@c, '8', 1),(@c, '10', 0);

-- XÓA dữ liệu thử nghiệm (chỉ chạy khi môn chưa có trận nào; đã có trận thì chỉ nên ẩn môn):
-- DELETE d FROM DAPAN d JOIN CAUHOI c ON c.MaCauHoi = d.MaCauHoi JOIN MONHOC m ON m.MaMon = c.MaMon WHERE m.TenMon LIKE '%(thử nghiệm)';
-- DELETE c FROM CAUHOI c JOIN MONHOC m ON m.MaMon = c.MaMon WHERE m.TenMon LIKE '%(thử nghiệm)';
-- DELETE FROM MONHOC WHERE TenMon LIKE '%(thử nghiệm)';

SET FOREIGN_KEY_CHECKS = 1;
