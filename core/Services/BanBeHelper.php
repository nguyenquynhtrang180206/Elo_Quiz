<?php
// Xử lý kết bạn (C-41). Đặt tại: elo_quiz/core/BanBeHelper.php
//
// Bảng BANBE: mỗi cặp người dùng chỉ có MỘT dòng, bất kể ai gửi cho ai
// (chỉ mục duy nhất trên cặp CapNho/CapLon do MySQL tự tính từ LEAST/GREATEST).
//   - hủy lời mời: xóa dòng
//   - từ chối: giữ dòng, trạng thái "Từ chối"
//   - gửi lại sau khi bị từ chối: cập nhật dòng cũ (đổi chiều gửi nếu người gửi lại là phía kia)
//   - hủy kết bạn: xóa dòng (hai bên có thể kết bạn lại từ đầu)
// Thông báo (bảng THONGBAO): người nhận được báo khi có lời mời; người gửi được báo khi lời mời được chấp nhận.
class BanBeHelper
{
    // Kết quả của guiLoiMoi()
    public const OK = 'ok';
    public const TU_CHINH = 'tuchinh';
    public const KHONG_TON_TAI = 'khongton';
    public const DA_LA_BAN = 'dalabanbe';
    public const DA_GUI = 'dagui';
    public const CO_CHO_XU_LY = 'cochoxuly';   // phía kia đã gửi cho mình, chưa xử lý
    public const XUNG_DOT = 'conflict';

    // Loại thông báo (cột THONGBAO.Loai)
    public const LOAI_LOI_MOI   = 'loi_moi_kb';
    public const LOAI_CHAP_NHAN = 'chap_nhan_kb';

    public const THONG_BAO = [
        self::OK            => 'Đã gửi lời mời kết bạn.',
        self::TU_CHINH      => 'Bạn không thể kết bạn với chính mình.',
        self::KHONG_TON_TAI => 'Không tìm thấy sinh viên này.',
        self::DA_LA_BAN     => 'Hai bạn đã là bạn bè.',
        self::DA_GUI        => 'Bạn đã gửi lời mời cho người này rồi.',
        self::CO_CHO_XU_LY  => 'Người này đã gửi lời mời cho bạn. Hãy chấp nhận ở mục "Lời mời đã nhận".',
        self::XUNG_DOT      => 'Có thay đổi xảy ra cùng lúc, vui lòng thử lại.',
    ];

    public static function guiLoiMoi(int $toi, int $nguoiKia): string
    {
        if ($toi === $nguoiKia) {
            return self::TU_CHINH;
        }
        $pdo = Database::pdo();

        $st = $pdo->prepare("SELECT 1 FROM NGUOIDUNG WHERE MaND = ? AND TrangThai = 'Hoạt động'");
        $st->execute([$nguoiKia]);
        if (!$st->fetchColumn()) {
            return self::KHONG_TON_TAI;
        }

        try {
            $pdo->beginTransaction();

            // Khóa dòng của cặp này (nếu có) để hai thao tác đồng thời không chen nhau
            $st = $pdo->prepare(
                'SELECT MaNguoiGui, TrangThai FROM BANBE
                  WHERE CapNho = LEAST(?, ?) AND CapLon = GREATEST(?, ?) FOR UPDATE'
            );
            $st->execute([$toi, $nguoiKia, $toi, $nguoiKia]);
            $dong = $st->fetch();

            if (!$dong) {
                $pdo->prepare('INSERT INTO BANBE (MaNguoiGui, MaNguoiNhan) VALUES (?, ?)')
                    ->execute([$toi, $nguoiKia]);
                $kq = self::OK;
            } elseif ($dong['TrangThai'] === 'Đã chấp nhận') {
                $kq = self::DA_LA_BAN;
            } elseif ($dong['TrangThai'] === 'Chờ') {
                $kq = ((int) $dong['MaNguoiGui'] === $toi) ? self::DA_GUI : self::CO_CHO_XU_LY;
            } else {
                // "Từ chối": gửi lại bằng cách cập nhật dòng cũ. Gán thẳng hai giá trị
                // (không viết SET Gui = Nhan, Nhan = Gui vì MySQL gán từ trái sang phải sẽ sai).
                $pdo->prepare(
                    "UPDATE BANBE SET MaNguoiGui = ?, MaNguoiNhan = ?, TrangThai = 'Chờ', NgayGui = NOW()
                      WHERE CapNho = LEAST(?, ?) AND CapLon = GREATEST(?, ?)"
                )->execute([$toi, $nguoiKia, $toi, $nguoiKia, $toi, $nguoiKia]);
                $kq = self::OK;
            }

            $pdo->commit();

            if ($kq === self::OK) {
                ThongBaoHelper::taoThongBaoBanBe(
                    $pdo, $nguoiKia, $toi, self::LOAI_LOI_MOI,
                    'đã gửi cho bạn lời mời kết bạn', self::lienKet('loimoi')
                );
            }
            return $kq;
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // 23000: vi phạm duy nhất (hai bên gửi cùng lúc); 40001: deadlock
            if (in_array($ex->getCode(), ['23000', '40001'], true)) {
                return self::XUNG_DOT;
            }
            throw $ex;
        }
    }

    // Người gửi hủy lời mời còn "Chờ": xóa dòng
    public static function huyLoiMoi(int $toi, int $nguoiKia): bool
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare(
            "DELETE FROM BANBE WHERE MaNguoiGui = ? AND MaNguoiNhan = ? AND TrangThai = 'Chờ'"
        );
        $st->execute([$toi, $nguoiKia]);
        if ($st->rowCount() < 1) {
            return false;
        }
        // Lời mời đã rút lại thì thông báo bên người nhận không còn đúng
        ThongBaoHelper::xoaThongBaoBanBe($pdo, $nguoiKia, $toi, self::LOAI_LOI_MOI);
        return true;
    }

    // Người nhận chấp nhận lời mời đang "Chờ"
    public static function chapNhan(int $toi, int $nguoiGui): bool
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare(
            "UPDATE BANBE SET TrangThai = 'Đã chấp nhận'
              WHERE MaNguoiGui = ? AND MaNguoiNhan = ? AND TrangThai = 'Chờ'"
        );
        $st->execute([$nguoiGui, $toi]);
        if ($st->rowCount() < 1) {
            return false;
        }
        ThongBaoHelper::xoaThongBaoBanBe($pdo, $toi, $nguoiGui, self::LOAI_LOI_MOI);
        ThongBaoHelper::taoThongBaoBanBe(
            $pdo, $nguoiGui, $toi, self::LOAI_CHAP_NHAN,
            'đã chấp nhận lời mời kết bạn của bạn', self::lienKet('danhsach')
        );
        return true;
    }

    // Người nhận từ chối: giữ dòng, trạng thái "Từ chối"
    public static function tuChoi(int $toi, int $nguoiGui): bool
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare(
            "UPDATE BANBE SET TrangThai = 'Từ chối'
              WHERE MaNguoiGui = ? AND MaNguoiNhan = ? AND TrangThai = 'Chờ'"
        );
        $st->execute([$nguoiGui, $toi]);
        if ($st->rowCount() < 1) {
            return false;
        }
        // Từ chối thì im lặng với người gửi, chỉ dọn thông báo lời mời của chính mình
        ThongBaoHelper::xoaThongBaoBanBe($pdo, $toi, $nguoiGui, self::LOAI_LOI_MOI);
        return true;
    }

    // Hủy kết bạn: xóa dòng (chỉ khi hai bên đang là bạn bè)
    public static function huyKetBan(int $toi, int $nguoiKia): bool
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare(
            "DELETE FROM BANBE
              WHERE CapNho = LEAST(?, ?) AND CapLon = GREATEST(?, ?) AND TrangThai = 'Đã chấp nhận'"
        );
        $st->execute([$toi, $nguoiKia, $toi, $nguoiKia]);
        if ($st->rowCount() < 1) {
            return false;
        }
        // Thông báo "đã chấp nhận" của cả hai chiều không còn đúng nữa
        ThongBaoHelper::xoaThongBaoBanBe($pdo, $nguoiKia, $toi, self::LOAI_CHAP_NHAN);
        ThongBaoHelper::xoaThongBaoBanBe($pdo, $toi, $nguoiKia, self::LOAI_CHAP_NHAN);
        return true;
    }

    // Số lời mời đang chờ mình xử lý (hiện trên menu)
    public static function demLoiMoiCho(int $maND): int
    {
        if ($maND <= 0) {
            return 0;
        }
        try {
            $st = Database::pdo()->prepare(
                "SELECT COUNT(*) FROM BANBE b JOIN NGUOIDUNG u ON u.MaND = b.MaNguoiGui
                  WHERE b.MaNguoiNhan = ? AND b.TrangThai = 'Chờ' AND u.TrangThai = 'Hoạt động'"
            );
            $st->execute([$maND]);
            return (int) $st->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    // Đường dẫn tới trang Bạn bè (kèm mốc #...) để gắn vào thông báo
    private static function lienKet(string $moc): string
    {
        return (defined('BASE_URL') ? BASE_URL : '') . '/client/banbe.php#' . $moc;
    }

    // Thông báo một lần: ['loai' => 'ok'|'loi', 'nd' => '...']
    public static function flash(?string $loai = null, ?string $noiDung = null): ?array
    {
        if ($loai !== null) {
            $_SESSION['flash_bb'] = ['loai' => $loai, 'nd' => (string) $noiDung];
            return null;
        }
        $m = $_SESSION['flash_bb'] ?? null;
        unset($_SESSION['flash_bb']);
        return $m;
    }
}