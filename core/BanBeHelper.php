<?php
// Xử lý kết bạn (C-41). Đặt tại: elo_quiz/core/BanBeHelper.php
//
// Bảng BANBE: mỗi cặp người dùng chỉ có MỘT dòng, bất kể ai gửi cho ai
// (chỉ mục duy nhất trên cặp CapNho/CapLon do MySQL tự tính từ LEAST/GREATEST).
//   - hủy lời mời: xóa dòng
//   - từ chối: giữ dòng, trạng thái "Từ chối"
//   - gửi lại sau khi bị từ chối: cập nhật dòng cũ (đổi chiều gửi nếu người gửi lại là phía kia)
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
        $st = Database::pdo()->prepare(
            "DELETE FROM BANBE WHERE MaNguoiGui = ? AND MaNguoiNhan = ? AND TrangThai = 'Chờ'"
        );
        $st->execute([$toi, $nguoiKia]);
        return $st->rowCount() > 0;
    }

    // Người nhận chấp nhận lời mời đang "Chờ"
    public static function chapNhan(int $toi, int $nguoiGui): bool
    {
        $st = Database::pdo()->prepare(
            "UPDATE BANBE SET TrangThai = 'Đã chấp nhận'
              WHERE MaNguoiGui = ? AND MaNguoiNhan = ? AND TrangThai = 'Chờ'"
        );
        $st->execute([$nguoiGui, $toi]);
        return $st->rowCount() > 0;
    }

    // Người nhận từ chối: giữ dòng, trạng thái "Từ chối"
    public static function tuChoi(int $toi, int $nguoiGui): bool
    {
        $st = Database::pdo()->prepare(
            "UPDATE BANBE SET TrangThai = 'Từ chối'
              WHERE MaNguoiGui = ? AND MaNguoiNhan = ? AND TrangThai = 'Chờ'"
        );
        $st->execute([$nguoiGui, $toi]);
        return $st->rowCount() > 0;
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