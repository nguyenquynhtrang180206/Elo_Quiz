-- =====================================================================
-- CSDL: Website thi đấu trắc nghiệm 1 vs 1, xếp hạng Elo theo môn
-- Yêu cầu: MySQL 8.0.16+ hoặc MariaDB 10.4+ (XAMPP mới đều được)
--          (dùng cột sinh cho DAPAN và BANBE, CHECK constraint)
-- =====================================================================

CREATE DATABASE IF NOT EXISTS elo_quiz
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE elo_quiz;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS NHATKYQUANTRI, BINHLUAN, BAIDANG, BANBE, LICHSUELO, ELOMONHOC,
  CHITIETTRALOI, CAUHOITRANDAU, NGUOICHOITRANDAU, TRANDAU, HANGDOIGHEPDOI, DAPAN,
  CAUHOI, LOAIKYTHI, MONHOC, ADMIN, NGUOIDUNG, CAUHINH;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- 1. NGUOIDUNG
-- ---------------------------------------------------------------------
CREATE TABLE NGUOIDUNG (
  MaND              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  HoTen             VARCHAR(100) NOT NULL,
  Email             VARCHAR(150) NOT NULL,
  MatKhau           VARCHAR(255) NOT NULL COMMENT 'Lưu dạng băm (password_hash)',
  AnhDaiDien        VARCHAR(255) NULL COMMENT 'Đường dẫn file; NULL = ảnh mặc định',
  Lop               VARCHAR(50)  NULL,
  Khoa              VARCHAR(100) NULL,
  TrangThai         ENUM('Hoạt động','Bị khóa') NOT NULL DEFAULT 'Hoạt động',
  NgayTao           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  TrangThaiKhaoSat  ENUM('Chưa làm','Hoàn tất','Bỏ qua') NOT NULL DEFAULT 'Chưa làm',
  MucTieuKhaoSat    VARCHAR(255) NULL COMMENT 'Danh sách mã mục tiêu, phân tách bằng dấu phẩy',
  PRIMARY KEY (MaND),
  UNIQUE KEY uq_nguoidung_email (Email)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 2. ADMIN
-- ---------------------------------------------------------------------
CREATE TABLE ADMIN (
  MaAdmin       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  TenDangNhap   VARCHAR(50)  NOT NULL,
  MatKhau       VARCHAR(255) NOT NULL COMMENT 'Lưu dạng băm',
  HoTen         VARCHAR(100) NOT NULL,
  TrangThai     ENUM('Hoạt động','Bị khóa') NOT NULL DEFAULT 'Hoạt động',
  NgayTao       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (MaAdmin),
  UNIQUE KEY uq_admin_tendangnhap (TenDangNhap)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 3. MONHOC
-- ---------------------------------------------------------------------
CREATE TABLE MONHOC (
  MaMon      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  TenMon     VARCHAR(150) NOT NULL,
  MoTa       TEXT NULL,
  TrangThai  ENUM('Đang mở','Đã ẩn') NOT NULL DEFAULT 'Đang mở',
  PRIMARY KEY (MaMon)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 4. LOAIKYTHI
-- ---------------------------------------------------------------------
CREATE TABLE LOAIKYTHI (
  MaLoaiKT   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  TenLoaiKT  VARCHAR(50) NOT NULL,
  MoTa       TEXT NULL,
  PRIMARY KEY (MaLoaiKT)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 5. CAUHOI
-- MaLoaiKT NULL = câu hỏi dùng được cho cả hai loại kỳ thi
-- ---------------------------------------------------------------------
CREATE TABLE CAUHOI (
  MaCauHoi   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  MaMon      INT UNSIGNED NOT NULL,
  MaLoaiKT   INT UNSIGNED NULL,
  NoiDung    TEXT NOT NULL,
  GiaiThich  TEXT NULL COMMENT 'Chỉ hiển thị ở màn xem lại sau trận',
  DoKho      ENUM('Dễ','Trung bình','Khó') NOT NULL DEFAULT 'Trung bình',
  TrangThai  ENUM('Hiển thị','Đã ẩn') NOT NULL DEFAULT 'Hiển thị',
  PRIMARY KEY (MaCauHoi),
  KEY idx_cauhoi_chon (MaMon, MaLoaiKT, TrangThai),
  CONSTRAINT fk_cauhoi_mon     FOREIGN KEY (MaMon)    REFERENCES MONHOC (MaMon),
  CONSTRAINT fk_cauhoi_loaikt  FOREIGN KEY (MaLoaiKT) REFERENCES LOAIKYTHI (MaLoaiKT)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 6. DAPAN
-- - UNIQUE (MaCauHoi, MaDapAn): đích cho khóa ngoại ghép từ CHITIETTRALOI
-- - Cột sinh DapAnDungDuy: ép mỗi câu hỏi tối đa một đáp án đúng ngay ở CSDL
-- - Số đáp án 2..4 và đúng 1 đáp án đúng kiểm tra ở tầng ứng dụng
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
  CONSTRAINT fk_dapan_cauhoi FOREIGN KEY (MaCauHoi) REFERENCES CAUHOI (MaCauHoi),
  CONSTRAINT ck_dapan_la_dung CHECK (LaDapAnDung IN (0, 1))
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 7. HANGDOIGHEPDOI
-- Không có cột trạng thái: dòng bị xóa khi hủy, hết hạn chờ hoặc đã ghép
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
  CONSTRAINT fk_hangdoi_nd     FOREIGN KEY (MaND)     REFERENCES NGUOIDUNG (MaND),
  CONSTRAINT fk_hangdoi_mon    FOREIGN KEY (MaMon)    REFERENCES MONHOC (MaMon),
  CONSTRAINT fk_hangdoi_loaikt FOREIGN KEY (MaLoaiKT) REFERENCES LOAIKYTHI (MaLoaiKT)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 8. TRANDAU
-- ThoiGianBatDau: lúc câu 1 hiển thị cho cả hai người (NULL khi còn "Đã ghép")
-- ---------------------------------------------------------------------
CREATE TABLE TRANDAU (
  MaTran           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  MaMon            INT UNSIGNED NOT NULL,
  MaLoaiKT         INT UNSIGNED NOT NULL,
  ThoiGianBatDau   DATETIME(3) NULL,
  ThoiGianKetThuc  DATETIME(3) NULL,
  TrangThai        ENUM('Đã ghép','Đang diễn ra','Kết thúc','Hủy') NOT NULL DEFAULT 'Đã ghép',
  LyDoKetThuc      ENUM('Hoàn thành','Đối thủ bỏ trận (xử thua)','Hủy do lỗi hệ thống',
                        'Hủy do không vào phòng','Quản trị viên hủy','Chịu thua') NULL,
  PRIMARY KEY (MaTran),
  KEY idx_trandau_trangthai (TrangThai, ThoiGianBatDau),
  CONSTRAINT fk_trandau_mon    FOREIGN KEY (MaMon)    REFERENCES MONHOC (MaMon),
  CONSTRAINT fk_trandau_loaikt FOREIGN KEY (MaLoaiKT) REFERENCES LOAIKYTHI (MaLoaiKT)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 9. NGUOICHOITRANDAU (mỗi trận đúng 2 dòng - kiểm tra ở tầng ứng dụng)
-- ---------------------------------------------------------------------
CREATE TABLE NGUOICHOITRANDAU (
  MaTran            INT UNSIGNED NOT NULL,
  MaND              INT UNSIGNED NOT NULL,
  DiemSo            INT NOT NULL DEFAULT 0,
  KetQua            ENUM('Thắng','Thua','Hòa') NULL,
  EloTruoc          INT NULL,
  EloSau            INT NULL,
  TrangThaiThamGia  ENUM('Chưa vào','Đang chơi','Đã xong','Bỏ trận') NOT NULL DEFAULT 'Chưa vào',
  LanHoatDongCuoi   DATETIME(3) NULL COMMENT 'Cập nhật mỗi lần thiết bị gọi máy chủ (polling)',
  PRIMARY KEY (MaTran, MaND),
  KEY idx_nctd_nd_trangthai (MaND, TrangThaiThamGia),
  CONSTRAINT fk_nctd_tran FOREIGN KEY (MaTran) REFERENCES TRANDAU (MaTran),
  CONSTRAINT fk_nctd_nd   FOREIGN KEY (MaND)   REFERENCES NGUOIDUNG (MaND)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 10. CAUHOITRANDAU (DiemCoBan, HeSoThuong, ThoiGianQuyDinh: chụp lại lúc tạo trận)
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
  CONSTRAINT fk_cauhoitrandau_tran    FOREIGN KEY (MaTran)    REFERENCES TRANDAU (MaTran),
  CONSTRAINT fk_cauhoitrandau_cauhoi  FOREIGN KEY (MaCauHoi)  REFERENCES CAUHOI (MaCauHoi),
  CONSTRAINT ck_cauhoitrandau_gt CHECK (DiemCoBan >= 0 AND HeSoThuong >= 0 AND ThoiGianQuyDinh > 0)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 11. ELOMONHOC (tạo trước LICHSUELO và CHITIETTRALOI không phụ thuộc)
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
  CONSTRAINT fk_elo_nd  FOREIGN KEY (MaND)  REFERENCES NGUOIDUNG (MaND),
  CONSTRAINT fk_elo_mon FOREIGN KEY (MaMon) REFERENCES MONHOC (MaMon),
  CONSTRAINT ck_elo_sotran CHECK (SoTran = SoThang + SoThua + SoHoa)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 12. CHITIETTRALOI
-- Ba khóa ngoại: người chơi trong trận, câu hỏi trong trận, đáp án thuộc đúng câu
-- MaDapAn NULL = không trả lời / hết giờ
-- ---------------------------------------------------------------------
CREATE TABLE CHITIETTRALOI (
  MaTran          INT UNSIGNED NOT NULL,
  MaND            INT UNSIGNED NOT NULL,
  MaCauHoi        INT UNSIGNED NOT NULL,
  MaDapAn         INT UNSIGNED NULL,
  ThoiDiemBatDau  DATETIME(3) NULL COMMENT 'Lúc máy chủ gửi câu cho người chơi; NULL ở dòng tạo bù',
  ThoiGianTraLoi  DECIMAL(6,3) NULL COMMENT 'Giây đã dùng',
  DiemNhanDuoc    INT NOT NULL DEFAULT 0,
  TrangThai       ENUM('Đã trả lời','Hết giờ','Không làm') NOT NULL DEFAULT 'Không làm',
  ThoiDiemGui     DATETIME(3) NULL,
  PRIMARY KEY (MaTran, MaND, MaCauHoi),
  KEY idx_cttl_cauhoi (MaCauHoi),
  CONSTRAINT fk_cttl_nguoichoi FOREIGN KEY (MaTran, MaND)
    REFERENCES NGUOICHOITRANDAU (MaTran, MaND),
  CONSTRAINT fk_ctl_cauhoitran  FOREIGN KEY (MaTran, MaCauHoi)
    REFERENCES CAUHOITRANDAU (MaTran, MaCauHoi),
  CONSTRAINT fk_ctl_dapan       FOREIGN KEY (MaCauHoi, MaDapAn)
    REFERENCES DAPAN (MaCauHoi, MaDapAn)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 13. LICHSUELO (UNIQUE (MaND, MaMon, MaTran): mỗi người/trận chỉ tính Elo một lần)
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
  CONSTRAINT fk_lichsuelo_elo  FOREIGN KEY (MaND, MaMon) REFERENCES ELOMONHOC (MaND, MaMon),
  CONSTRAINT fk_lichsuelo_tran FOREIGN KEY (MaTran)      REFERENCES TRANDAU (MaTran)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 14. BAIDANG (MaMon NULL: bài không gắn môn)
-- ---------------------------------------------------------------------
CREATE TABLE BAIDANG (
  MaBaiDang  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  MaND       INT UNSIGNED NOT NULL,
  MaMon      INT UNSIGNED NULL,
  TieuDe     VARCHAR(200) NOT NULL,
  NoiDung    TEXT NOT NULL,
  NgayDang   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  TrangThai  ENUM('Hiển thị','Bị ẩn','Đã xóa') NOT NULL DEFAULT 'Hiển thị',
  PRIMARY KEY (MaBaiDang),
  KEY idx_baidang_mon (MaMon, TrangThai, NgayDang),
  KEY idx_baidang_nd (MaND),
  CONSTRAINT fk_baidang_nd  FOREIGN KEY (MaND)  REFERENCES NGUOIDUNG (MaND),
  CONSTRAINT fk_baidang_mon FOREIGN KEY (MaMon) REFERENCES MONHOC (MaMon)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 15. BINHLUAN
-- ---------------------------------------------------------------------
CREATE TABLE BINHLUAN (
  MaBinhLuan   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  MaBaiDang    INT UNSIGNED NOT NULL,
  MaND         INT UNSIGNED NOT NULL,
  NoiDung      TEXT NOT NULL,
  NgayBinhLuan DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  TrangThai    ENUM('Hiển thị','Bị ẩn') NOT NULL DEFAULT 'Hiển thị',
  PRIMARY KEY (MaBinhLuan),
  KEY idx_binhluan_baidang (MaBaiDang, TrangThai),
  KEY idx_binhluan_nd (MaND),
  CONSTRAINT fk_binhluan_baidang FOREIGN KEY (MaBaiDang) REFERENCES BAIDANG (MaBaiDang),
  CONSTRAINT fk_binhluan_nd      FOREIGN KEY (MaND)      REFERENCES NGUOIDUNG (MaND)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 16. BANBE
-- Mỗi cặp người dùng chỉ một dòng (chặn gửi chéo A->B khi B->A đã có)
-- bằng hai cột sinh (CapNho, CapLon) + chỉ mục duy nhất trên cặp đó.
-- Cách này chạy được cả trên MariaDB (XAMPP) lẫn MySQL 8.
-- Hai cột sinh do CSDL tự tính, ứng dụng không cần ghi vào.
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
  CONSTRAINT fk_banbe_gui  FOREIGN KEY (MaNguoiGui)  REFERENCES NGUOIDUNG (MaND),
  CONSTRAINT fk_banbe_nhan FOREIGN KEY (MaNguoiNhan) REFERENCES NGUOIDUNG (MaND),
  CONSTRAINT ck_banbe_khac CHECK (MaNguoiGui <> MaNguoiNhan)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 17. NHATKYQUANTRI (chỉ thêm, không sửa/xóa - kiểm soát ở tầng ứng dụng)
-- ---------------------------------------------------------------------
CREATE TABLE NHATKYQUANTRI (
  MaNhatKy   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  MaAdmin    INT UNSIGNED NOT NULL,
  HanhDong   VARCHAR(100) NOT NULL,
  DoiTuong   VARCHAR(100) NULL,
  ThoiGian   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  MoTa       TEXT NULL COMMENT 'Gồm lý do nếu thao tác yêu cầu',
  PRIMARY KEY (MaNhatKy),
  KEY idx_nhatky_admin_tg (MaAdmin, ThoiGian),
  KEY idx_nhatky_tg (ThoiGian),
  CONSTRAINT fk_nhatky_admin FOREIGN KEY (MaAdmin) REFERENCES ADMIN (MaAdmin)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 18. CAUHINH
-- ---------------------------------------------------------------------
CREATE TABLE CAUHINH (
  MaCauHinh   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  TenCauHinh  VARCHAR(60)  NOT NULL,
  GiaTri      VARCHAR(100) NOT NULL,
  MoTa        VARCHAR(255) NULL,
  PRIMARY KEY (MaCauHinh),
  UNIQUE KEY uq_cauhinh_ten (TenCauHinh)
) ENGINE=InnoDB;

-- =====================================================================
-- DỮ LIỆU KHỞI TẠO
-- =====================================================================
INSERT INTO LOAIKYTHI (TenLoaiKT, MoTa) VALUES
  ('Giữa kỳ',  'Câu hỏi ôn thi giữa kỳ'),
  ('Cuối kỳ',  'Câu hỏi ôn thi cuối kỳ');

INSERT INTO CAUHINH (TenCauHinh, GiaTri, MoTa) VALUES
  ('SO_CAU',                          '10',   'Số câu mỗi trận (chụp vào CAUHOITRANDAU lúc tạo trận)'),
  ('THOI_GIAN_MOI_CAU',               '15',   'Thời gian trả lời mỗi câu (giây)'),
  ('DIEM_CO_BAN',                     '100',  'Điểm cơ bản cho câu đúng'),
  ('HE_SO_THUONG',                    '50',   'Điểm thưởng tối đa theo tốc độ: HE_SO_THUONG x (thời gian còn lại / THOI_GIAN_MOI_CAU)'),
  ('K_NGUOI_MOI',                     '32',   'Hệ số K khi người chơi dưới ngưỡng số trận'),
  ('K_NGUOI_CU',                      '24',   'Hệ số K từ ngưỡng số trận trở lên'),
  ('NGUONG_SO_TRAN_DOI_K',            '30',   'Số trận để đổi từ K_NGUOI_MOI sang K_NGUOI_CU'),
  ('ELO_KHOI_TAO',                    '1000', 'Elo ban đầu của một môn'),
  ('NGUONG_ELO_GHEP_DOI',             '100',  'Chênh lệch Elo ban đầu khi ghép; nới dần theo thời gian chờ (đề xuất)'),
  ('THOI_GIAN_CHO_GHEP_TOI_DA',       '60',   'Quá thời gian này (giây) chưa ghép được thì rút khỏi hàng đợi (đề xuất)'),
  ('THOI_GIAN_CHO_VAO_PHONG',         '30',   'Sau khi ghép, quá thời gian này (giây) có người chưa vào phòng thì hủy trận (đề xuất)'),
  ('THOI_GIAN_CHO_PHEP_MAT_KET_NOI',  '30',   'Không có hoạt động quá thời gian này (giây) thì bị xử thua (đề xuất)'),
  ('DUNG_SAI_DO_TRE',                 '1',    'Đáp án đến muộn trong khoảng này (giây) vẫn được nhận (đề xuất)'),
  ('SO_TRAN_TOI_THIEU_BXH',           '10',   'N của BXH toàn trường: số trận tối thiểu của một môn (đề xuất)'),
  ('THOI_HAN_LIEN_KET_DAT_LAI',       '30',   'Hạn của liên kết đặt lại mật khẩu (phút) (đề xuất)'),
  ('NGUONG_TRAN_KEO_DAI',             '10',   'Trận quá thời gian này (phút) bị liệt kê là bất thường (đề xuất)'),
  ('NGUONG_BIEN_DONG_ELO',            '150',  'Elo tăng/giảm quá mức này (điểm) trong khoảng thời gian bên dưới thì bị liệt kê (đề xuất)'),
  ('KHOANG_BIEN_DONG_ELO',            '24',   'Khoảng thời gian xét biến động Elo (giờ) (đề xuất)');

-- Tài khoản quản trị tạo trực tiếp trong CSDL (xem 7.2.3). Ví dụ (thay <hash> bằng
-- kết quả password_hash() của PHP):
-- INSERT INTO ADMIN (TenDangNhap, MatKhau, HoTen) VALUES ('admin', '<hash>', 'Quản trị viên');



ALTER TABLE MONHOC
  MODIFY TrangThai ENUM('Đang mở','Đã ẩn','Chờ duyệt') NOT NULL DEFAULT 'Đang mở',
  ADD COLUMN MaNguoiDeXuat INT UNSIGNED NULL AFTER TrangThai,
  ADD CONSTRAINT fk_monhoc_nguoidexuat FOREIGN KEY (MaNguoiDeXuat) REFERENCES NGUOIDUNG (MaND);

  UPDATE ADMIN
SET MatKhau = '$2y$10$TeACaYhx5feO6rATfKBvZOxNiguhrOek2baislMlQTWetyVg9dia.',
    TrangThai = 'Hoạt động'
WHERE TenDangNhap = 'admin';


-- Tùy chọn: ghi lại lúc ghép trận để Admin phát hiện trận "Đã ghép" bị treo (A-25).
-- Chạy một lần trên CSDL elo_quiz. Không cần sửa code ghép đôi: giá trị tự điền lúc INSERT.
ALTER TABLE TRANDAU
  ADD COLUMN ThoiGianTao DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) AFTER MaLoaiKT;