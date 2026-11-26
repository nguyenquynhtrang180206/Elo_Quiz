<?php
// Phòng thách đấu 1v1 riêng tư bằng mã PIN 6 số (tạo phòng, khôi phục khi F5, vào phòng, mời bạn bè).
class ThachDau
{
    public const THOI_GIAN_HET_HAN_GIAY = 600; // 10 phút hiệu lực mỗi mã PIN

    /**
     * Trích xuất mã PIN 6 chữ số sạch từ input người dùng hoặc đường link chia sẻ.
     */
    public static function chuanHoaPin(string $pin): string
    {
        $raw = trim($pin);
        if (preg_match('/(\d{6})/', $raw, $m)) {
            return $m[1];
        }
        return (string) preg_replace('/\D/', '', $raw);
    }

    /**
     * Sinh mã PIN 6 số ngẫu nhiên không trùng với phòng đang mở.
     */
    private static function sinhMaPin(PDO $pdo): string
    {
        for ($i = 0; $i < 12; $i++) {
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
     * Tạo phòng thách đấu PIN mới (kiểm tra môn, kỳ thi, số câu hỏi, rút khỏi hàng đợi công cộng).
     */
    public static function taoPhong(PDO $pdo, int $chuND, int $maMon, int $maLoaiKT): array
    {
        if ($chuND <= 0) {
            return ['ok' => false, 'loi' => 'Vui lòng đăng nhập để tạo phòng.'];
        }
        if ($maMon <= 0 || $maLoaiKT <= 0) {
            return ['ok' => false, 'loi' => 'Vui lòng chọn môn học và hạng mục kỳ thi trước khi tạo phòng.'];
        }

        // Kiểm tra môn học đang mở
        $stMon = $pdo->prepare("SELECT TenMon FROM MONHOC WHERE MaMon = ? AND TrangThai = 'Đang mở'");
        $stMon->execute([$maMon]);
        $tenMon = $stMon->fetchColumn();
        if ($tenMon === false) {
            return ['ok' => false, 'loi' => 'Môn học đã chọn không tồn tại hoặc đang tạm đóng.'];
        }

        // Kiểm tra loại kỳ thi
        $stKt = $pdo->prepare('SELECT TenLoaiKT FROM LOAIKYTHI WHERE MaLoaiKT = ?');
        $stKt->execute([$maLoaiKT]);
        $tenLoaiKT = $stKt->fetchColumn();
        if ($tenLoaiKT === false) {
            return ['ok' => false, 'loi' => 'Hạng mục kỳ thi không hợp lệ.'];
        }

        // Kiểm tra đủ câu hỏi cho môn và kỳ thi đã chọn
        $soCau = max(1, Config::int('SO_CAU', 10));
        if (GhepDoi::demCauHoi($pdo, $maMon, $maLoaiKT) < $soCau) {
            return [
                'ok'  => false,
                'loi' => "Môn {$tenMon} ({$tenLoaiKT}) hiện chưa đủ {$soCau} câu hỏi để mở phòng thách đấu."
            ];
        }

        // Rút khỏi hàng đợi ghép ngẫu nhiên nếu đang xếp hàng
        $pdo->prepare('DELETE FROM HANGDOIGHEPDOI WHERE MaND = ?')->execute([$chuND]);

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
            'ok'         => true,
            'maPhong'    => $maPhong,
            'pin'        => $pin,
            'maPin'      => $pin,
            'maMon'      => $maMon,
            'tenMon'     => (string) $tenMon,
            'maLoaiKT'   => $maLoaiKT,
            'tenLoaiKT'  => (string) $tenLoaiKT,
            'conLaiGiay' => self::THOI_GIAN_HET_HAN_GIAY,
            'link'       => BASE_URL . '/client/timtran.php?phong=' . $pin,
        ];
    }

    /**
     * Lấy phòng đang chờ còn hiệu lực của chủ phòng (giúp khôi phục trạng thái khi F5 hoặc đổi tướng).
     */
    public static function layPhongDangChoCuaChu(PDO $pdo, int $chuND): ?array
    {
        if ($chuND <= 0) {
            return null;
        }
        // Tự hủy các phòng đã quá hạn 10 phút
        $pdo->prepare(
            "UPDATE PHONGTHACHDAU
                SET TrangThai = 'Đã hủy'
              WHERE ChuPhongND = ? AND TrangThai = 'Chờ đối thủ'
                AND TIMESTAMPDIFF(SECOND, NgayTao, NOW()) >= ?"
        )->execute([$chuND, self::THOI_GIAN_HET_HAN_GIAY]);

        $st = $pdo->prepare(
            "SELECT p.MaPhong, p.MaPin, p.MaMon, p.MaLoaiKT, p.NgayTao,
                    m.TenMon, l.TenLoaiKT,
                    GREATEST(0, ? - TIMESTAMPDIFF(SECOND, p.NgayTao, NOW())) AS ConLaiGiay
               FROM PHONGTHACHDAU p
               JOIN MONHOC m ON m.MaMon = p.MaMon
               JOIN LOAIKYTHI l ON l.MaLoaiKT = p.MaLoaiKT
              WHERE p.ChuPhongND = ? AND p.TrangThai = 'Chờ đối thủ'
              ORDER BY p.MaPhong DESC
              LIMIT 1"
        );
        $st->execute([self::THOI_GIAN_HET_HAN_GIAY, $chuND]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /**
     * Lấy thông tin xem trước (Live Room Preview) của phòng theo mã PIN 6 số.
     */
    public static function layThongTinPhong(PDO $pdo, string $pin): ?array
    {
        $pinClean = self::chuanHoaPin($pin);
        if (strlen($pinClean) !== 6) {
            return null;
        }

        $st = $pdo->prepare(
            "SELECT p.MaPhong, p.MaPin, p.ChuPhongND, p.MaMon, p.MaLoaiKT, p.NgayTao,
                    m.TenMon, l.TenLoaiKT,
                    u.HoTen AS TenChuPhong, u.AnhDaiDien AS AnhChuPhong, u.TuongChon AS TuongChuPhong,
                    COALESCE((SELECT e.DiemElo FROM ELOMONHOC e WHERE e.MaND = p.ChuPhongND AND e.MaMon = p.MaMon LIMIT 1), 1000) AS EloChuPhong,
                    GREATEST(0, ? - TIMESTAMPDIFF(SECOND, p.NgayTao, NOW())) AS ConLaiGiay
               FROM PHONGTHACHDAU p
               JOIN MONHOC m ON m.MaMon = p.MaMon
               JOIN LOAIKYTHI l ON l.MaLoaiKT = p.MaLoaiKT
               JOIN NGUOIDUNG u ON u.MaND = p.ChuPhongND
              WHERE p.MaPin = ? AND p.TrangThai = 'Chờ đối thủ'
                AND TIMESTAMPDIFF(SECOND, p.NgayTao, NOW()) < ?
              LIMIT 1"
        );
        $st->execute([self::THOI_GIAN_HET_HAN_GIAY, $pinClean, self::THOI_GIAN_HET_HAN_GIAY]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            return null;
        }

        $r['EloChuPhong'] = (int) $r['EloChuPhong'];
        $r['ConLaiGiay']  = (int) $r['ConLaiGiay'];
        $r['SoCau']       = max(1, Config::int('SO_CAU', 10));
        $r['ThoiGianCau'] = max(5, Config::int('THOI_GIAN_MOI_CAU', 15));
        $r['AnhChuPhongUrl'] = !empty($r['AnhChuPhong'])
            ? BASE_URL . '/' . ltrim((string) $r['AnhChuPhong'], '/')
            : '';

        return $r;
    }

    /**
     * Khách nhập mã PIN (hoặc mở từ link mời) để vào phòng thách đấu. Tự động khởi tạo trận 1v1.
     */
    public static function vaoPhong(PDO $pdo, int $khachND, string $pin): array
    {
        $pinClean = self::chuanHoaPin($pin);
        if (strlen($pinClean) !== 6) {
            return ['ok' => false, 'loi' => 'Mã PIN phải gồm đủ 6 chữ số!'];
        }

        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare(
                "SELECT *, TIMESTAMPDIFF(SECOND, NgayTao, NOW()) AS DaTroi
                   FROM PHONGTHACHDAU
                  WHERE MaPin = ? AND TrangThai = 'Chờ đối thủ'
                  FOR UPDATE"
            );
            $st->execute([$pinClean]);
            $phong = $st->fetch(PDO::FETCH_ASSOC);

            if (!$phong) {
                $pdo->rollBack();
                return ['ok' => false, 'loi' => 'Mã PIN không tồn tại, đã có người vào hoặc phòng đã bị hủy.'];
            }

            if ((int) $phong['DaTroi'] >= self::THOI_GIAN_HET_HAN_GIAY) {
                $pdo->prepare("UPDATE PHONGTHACHDAU SET TrangThai = 'Đã hủy' WHERE MaPhong = ?")
                    ->execute([(int) $phong['MaPhong']]);
                $pdo->commit();
                return ['ok' => false, 'loi' => 'Mã phòng này đã hết hạn (quá 10 phút). Hãy nhờ bạn bè tạo mã mới!'];
            }

            $chuND = (int) $phong['ChuPhongND'];
            if ($chuND === $khachND) {
                $pdo->rollBack();
                return ['ok' => false, 'loi' => 'Bạn là chủ phòng này, hãy gửi mã PIN hoặc mời trực tiếp bạn bè!'];
            }

            $maMon = (int) $phong['MaMon'];
            $maKT  = (int) $phong['MaLoaiKT'];

            // Tạo trận 1v1 trực tiếp giữa Chủ phòng và Khách
            $maTran = GhepDoi::taoTranTrucTiep($pdo, $chuND, $khachND, $maMon, $maKT);
            if ($maTran === null) {
                $pdo->rollBack();
                return ['ok' => false, 'loi' => 'Không thể khởi tạo trận đấu (chưa đủ câu hỏi môn này).'];
            }

            // Cập nhật trạng thái phòng sang Đã ghép
            $up = $pdo->prepare(
                "UPDATE PHONGTHACHDAU SET KhachND = ?, MaTran = ?, TrangThai = 'Đã ghép' WHERE MaPhong = ?"
            );
            $up->execute([$khachND, $maTran, (int) $phong['MaPhong']]);

            $pdo->commit();
            return [
                'ok'       => true,
                'maTran'   => $maTran,
                'phongUrl' => BASE_URL . '/client/phong.php?tran=' . $maTran,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'loi' => 'Lỗi hệ thống khi vào phòng: ' . $e->getMessage()];
        }
    }

    /**
     * Dành cho chủ phòng: Polling kiểm tra xem bạn bè đã vào phòng chưa hoặc phòng đã hết hạn chưa.
     */
    public static function kiemTraPhongChu(PDO $pdo, int $chuND, string $pin): array
    {
        $pinClean = self::chuanHoaPin($pin);
        if ($chuND <= 0 || strlen($pinClean) !== 6) {
            return ['tonTai' => false, 'hetHan' => false, 'daGhep' => false, 'coTran' => false];
        }

        $st = $pdo->prepare(
            "SELECT p.MaPhong, p.TrangThai, p.MaTran, u.HoTen AS TenKhach,
                    GREATEST(0, ? - TIMESTAMPDIFF(SECOND, p.NgayTao, NOW())) AS ConLaiGiay
               FROM PHONGTHACHDAU p
               LEFT JOIN NGUOIDUNG u ON u.MaND = p.KhachND
              WHERE p.ChuPhongND = ? AND p.MaPin = ?
              ORDER BY p.MaPhong DESC
              LIMIT 1"
        );
        $st->execute([self::THOI_GIAN_HET_HAN_GIAY, $chuND, $pinClean]);
        $r = $st->fetch(PDO::FETCH_ASSOC);

        if (!$r || $r['TrangThai'] === 'Đã hủy') {
            return ['tonTai' => false, 'hetHan' => false, 'daGhep' => false, 'coTran' => false];
        }

        if ($r['TrangThai'] === 'Đã ghép' && !empty($r['MaTran'])) {
            return [
                'tonTai'   => true,
                'hetHan'   => false,
                'daGhep'   => true,
                'coTran'   => true,
                'maTran'   => (int) $r['MaTran'],
                'tenKhach' => (string) ($r['TenKhach'] ?? 'Đối thủ'),
                'phongUrl' => BASE_URL . '/client/phong.php?tran=' . (int) $r['MaTran'],
            ];
        }

        $conLai = (int) $r['ConLaiGiay'];
        if ($conLai <= 0) {
            $pdo->prepare("UPDATE PHONGTHACHDAU SET TrangThai = 'Đã hủy' WHERE MaPhong = ?")
                ->execute([(int) $r['MaPhong']]);
            return ['tonTai' => false, 'hetHan' => true, 'daGhep' => false, 'coTran' => false];
        }

        return [
            'tonTai'     => true,
            'hetHan'     => false,
            'daGhep'     => false,
            'coTran'     => false,
            'conLaiGiay' => $conLai,
        ];
    }

    /**
     * Mời bạn bè trực tiếp 1 chạm vào phòng PIN đang mở (bắn thông báo vào chuông của bạn bè).
     */
    public static function moiBanBe(PDO $pdo, int $chuND, int $banND, string $pin): array
    {
        $pinClean = self::chuanHoaPin($pin);
        if ($chuND <= 0 || $banND <= 0 || $chuND === $banND || strlen($pinClean) !== 6) {
            return ['ok' => false, 'loi' => 'Thông tin lời mời thách đấu không hợp lệ.'];
        }

        // Kiểm tra phòng đang mở và thuộc về chủ phòng
        $st = $pdo->prepare(
            "SELECT p.MaPhong, m.TenMon, l.TenLoaiKT
               FROM PHONGTHACHDAU p
               JOIN MONHOC m ON m.MaMon = p.MaMon
               JOIN LOAIKYTHI l ON l.MaLoaiKT = p.MaLoaiKT
              WHERE p.ChuPhongND = ? AND p.MaPin = ? AND p.TrangThai = 'Chờ đối thủ'
                AND TIMESTAMPDIFF(SECOND, p.NgayTao, NOW()) < ?
              LIMIT 1"
        );
        $st->execute([$chuND, $pinClean, self::THOI_GIAN_HET_HAN_GIAY]);
        $phong = $st->fetch(PDO::FETCH_ASSOC);
        if (!$phong) {
            return ['ok' => false, 'loi' => 'Phòng PIN không tồn tại hoặc đã hết hạn. Vui lòng tạo mã mới!'];
        }

        // Kiểm tra đã là bạn bè & tài khoản bạn bè còn hoạt động
        $stBan = $pdo->prepare(
            "SELECT u.HoTen
               FROM BANBE b
               JOIN NGUOIDUNG u ON u.MaND = ?
              WHERE b.CapNho = LEAST(?, ?) AND b.CapLon = GREATEST(?, ?)
                AND b.TrangThai = 'Đã chấp nhận' AND u.TrangThai = 'Hoạt động'
              LIMIT 1"
        );
        $stBan->execute([$banND, $chuND, $banND, $chuND, $banND]);
        $tenBan = $stBan->fetchColumn();
        if ($tenBan === false) {
            return ['ok' => false, 'loi' => 'Hai bạn chưa kết bạn hoặc tài khoản này không khả dụng.'];
        }

        // Xóa lời mời chưa đọc trước đó từ cùng người gửi để tránh spam trùng
        $pdo->prepare("DELETE FROM THONGBAO WHERE MaND = ? AND MaNguoiGui = ? AND Loai = 'thach_dau' AND DaDoc = 0")
            ->execute([$banND, $chuND]);

        $tieuDe = "đã thách đấu bạn 1v1 môn {$phong['TenMon']} ({$phong['TenLoaiKT']}) • PIN: {$pinClean}";
        $noiDung = 'Bấm vào thông báo này để xem kèo và vào phòng chiến ngay!';
        $lienKet = (defined('BASE_URL') ? BASE_URL : '') . '/client/timtran.php?phong=' . $pinClean;

        $guiOk = ThongBaoHelper::taoThongBao($pdo, $banND, $chuND, null, null, 'thach_dau', $tieuDe, $noiDung, $lienKet);
        if (!$guiOk) {
            return ['ok' => false, 'loi' => 'Không thể gửi thông báo tới bạn bè lúc này.'];
        }

        return [
            'ok'  => true,
            'msg' => 'Đã gửi lời thách đấu trực tiếp tới ' . $tenBan . '!'
        ];
    }

    /**
     * Chủ phòng hủy phòng thách đấu.
     */
    public static function huyPhong(PDO $pdo, int $chuND, ?string $pin = null): bool
    {
        if ($pin !== null && trim($pin) !== '') {
            $pinClean = self::chuanHoaPin($pin);
            $st = $pdo->prepare("UPDATE PHONGTHACHDAU SET TrangThai = 'Đã hủy' WHERE ChuPhongND = ? AND MaPin = ?");
            return $st->execute([$chuND, $pinClean]);
        }
        $st = $pdo->prepare("UPDATE PHONGTHACHDAU SET TrangThai = 'Đã hủy' WHERE ChuPhongND = ? AND TrangThai = 'Chờ đối thủ'");
        return $st->execute([$chuND]);
    }
}
