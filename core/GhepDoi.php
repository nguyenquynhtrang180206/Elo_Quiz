<?php
// Ghép đôi và tạo trận (C-20, C-21, C-23, C-30). Đặt tại: elo_quiz/core/GhepDoi.php
//
// thuGhep() được gọi mỗi lần trang chờ tải lại (polling). Ai gọi trước thì tìm đối thủ trong hàng đợi.
// Tìm đối thủ trước, rồi khóa dòng chờ của cả hai theo thứ tự mã tăng dần và kiểm tra lại cả hai còn đó.
// Hai người gọi cùng lúc thì chỉ một bên tạo được trận, bên kia thấy dòng đã bị xóa và bỏ qua,
// nên một người không thể vào hai trận. Không cần SKIP LOCKED, chạy được trên MariaDB.
require_once __DIR__ . '/DotBien.php';

class GhepDoi
{
    // Cứ chờ thêm bấy nhiêu giây thì ngưỡng Elo được nới thêm một lần bằng NGUONG_ELO_GHEP_DOI.
    // (Chưa có tham số trong CAUHINH; muốn chỉnh được thì thêm một dòng cấu hình rồi đọc ở đây.)
    public const BUOC_NOI_GIAY = 10;

    // Điều kiện câu hỏi hợp lệ cho (môn, loại kỳ thi), dùng chung cho đếm và chọn câu (C-18, C-23)
    private const DK_CAU_HOP_LE =
        "c.MaMon = ? AND c.TrangThai = 'Hiển thị'
         AND (c.MaLoaiKT = ? OR c.MaLoaiKT IS NULL)
         AND (SELECT COUNT(*) FROM DAPAN d WHERE d.MaCauHoi = c.MaCauHoi) BETWEEN 2 AND 4
         AND (SELECT COUNT(*) FROM DAPAN d WHERE d.MaCauHoi = c.MaCauHoi AND d.LaDapAnDung = 1) = 1";

    public static function demCauHoi(PDO $pdo, int $maMon, int $maLoaiKT): int
    {
        if ($maLoaiKT === 3 || $maLoaiKT === 0) {
            $sql = "SELECT COUNT(*) FROM CAUHOI c WHERE c.MaMon = ? AND c.TrangThai = 'Hiển thị'
                 AND (SELECT COUNT(*) FROM DAPAN d WHERE d.MaCauHoi = c.MaCauHoi) BETWEEN 2 AND 4
                 AND (SELECT COUNT(*) FROM DAPAN d WHERE d.MaCauHoi = c.MaCauHoi AND d.LaDapAnDung = 1) = 1";
            $st = $pdo->prepare($sql);
            $st->execute([$maMon]);
            return (int) $st->fetchColumn();
        }
        $st = $pdo->prepare('SELECT COUNT(*) FROM CAUHOI c WHERE ' . self::DK_CAU_HOP_LE);
        $st->execute([$maMon, $maLoaiKT]);
        return (int) $st->fetchColumn();
    }

    // Mã trận chưa kết thúc của người này, hoặc null
    public static function tranDangCo(PDO $pdo, int $maND): ?int
    {
        $st = $pdo->prepare(
            "SELECT t.MaTran FROM NGUOICHOITRANDAU n JOIN TRANDAU t ON t.MaTran = n.MaTran
              WHERE n.MaND = ? AND t.TrangThai IN ('Đã ghép', 'Đang diễn ra')
              ORDER BY t.MaTran DESC LIMIT 1"
        );
        $st->execute([$maND]);
        $v = $st->fetchColumn();
        return $v === false ? null : (int) $v;
    }

    // Thử ghép cho $maND. Trả về mã trận mới nếu ghép được, null nếu chưa.
    // $choPhepBot: nếu false, không bao giờ ghép với máy, chỉ tìm người thật.
    public static function thuGhep(PDO $pdo, int $maND, bool $choPhepBot = true): ?int
    {
        $base     = max(1, Config::int('NGUONG_ELO_GHEP_DOI', 100));
        $choToiDa = max(1, Config::int('THOI_GIAN_CHO_GHEP_TOI_DA', 60));
        $soCau    = max(1, Config::int('SO_CAU', 10));
        $buoc     = self::BUOC_NOI_GIAY;

        try {
            // 1. Đọc dòng chờ của mình (chưa khóa)
            $st = $pdo->prepare(
                'SELECT MaMon, MaLoaiKT, EloTaiThoiDiemCho,
                        TIMESTAMPDIFF(SECOND, ThoiGianVaoHang, NOW()) AS DaCho
                   FROM HANGDOIGHEPDOI WHERE MaND = ?'
            );
            $st->execute([$maND]);
            $toi = $st->fetch();
            if (!$toi) {
                return null;
            }
            $maMon = (int) $toi['MaMon'];
            $maKT  = (int) $toi['MaLoaiKT'];
            $elo   = (int) $toi['EloTaiThoiDiemCho'];
            $nguong = $base * (1 + intdiv(max(0, (int) $toi['DaCho']), $buoc));

            // 2. Tìm đối thủ cùng môn, cùng loại kỳ thi, Elo gần nhau (lấy ngưỡng rộng hơn trong hai bên),
            //    bỏ qua dòng đã quá hạn chờ
            $st = $pdo->prepare(
                'SELECT h.MaND, h.EloTaiThoiDiemCho FROM HANGDOIGHEPDOI h
                  WHERE h.MaMon = ? AND h.MaLoaiKT = ? AND h.MaND <> ?
                    AND TIMESTAMPDIFF(SECOND, h.ThoiGianVaoHang, NOW()) <= ?
                    AND ABS(h.EloTaiThoiDiemCho - CAST(? AS SIGNED)) <=
                        GREATEST(CAST(? AS SIGNED),
                                 CAST(? AS SIGNED) * (1 + FLOOR(TIMESTAMPDIFF(SECOND, h.ThoiGianVaoHang, NOW()) / ?)))
                  ORDER BY ABS(h.EloTaiThoiDiemCho - CAST(? AS SIGNED)), h.ThoiGianVaoHang
                  LIMIT 1'
            );
            $st->execute([$maMon, $maKT, $maND, $choToiDa, $elo, $nguong, $base, $buoc, $elo]);
            $kia = $st->fetch();
            if (!$kia) {
                // Nếu người chơi cho phép ghép với máy (Bot AI) và thời gian chờ đạt mốc 30 giây:
                // Tự động ghép với Đấu Thần Máy để bắt đầu trận đấu
                if ($choPhepBot && (int) $toi['DaCho'] >= $choToiDa) {
                    return self::ghepVoiBot($pdo, $maND, $maMon, $maKT, $elo, $soCau);
                }
                return null;
            }
            $maKia  = (int) $kia['MaND'];
            $eloKia = (int) $kia['EloTaiThoiDiemCho'];

            // 2b. Khóa dòng chờ của cả hai theo thứ tự mã tăng dần (hai bên ghép nhau cùng lúc sẽ khóa cùng thứ tự
            //     nên không deadlock; ai khóa sau sẽ thấy dòng đã bị xóa và bỏ qua). Không dùng SKIP LOCKED
            //     để chạy được cả trên MariaDB của XAMPP.
            $pdo->beginTransaction();
            $st = $pdo->prepare(
                'SELECT MaND, MaMon, MaLoaiKT FROM HANGDOIGHEPDOI WHERE MaND IN (?, ?) ORDER BY MaND FOR UPDATE'
            );
            $st->execute([$maND, $maKia]);
            $khoa = $st->fetchAll();
            $conNguyen = count($khoa) === 2;
            foreach ($khoa as $r) {
                if ((int) $r['MaMon'] !== $maMon || (int) $r['MaLoaiKT'] !== $maKT) {
                    $conNguyen = false;
                }
            }
            if (!$conNguyen) {   // một trong hai đã được ghép, hủy tìm hoặc đổi môn
                $pdo->rollBack();
                return null;
            }

            // 3. C-21: khóa dòng người dùng theo thứ tự mã tăng dần, rồi chắc chắn không ai đang có trận
            $st = $pdo->prepare('SELECT MaND FROM NGUOIDUNG WHERE MaND IN (?, ?) ORDER BY MaND FOR UPDATE');
            $st->execute([$maND, $maKia]);
            $st = $pdo->prepare(
                "SELECT 1 FROM NGUOICHOITRANDAU n JOIN TRANDAU t ON t.MaTran = n.MaTran
                  WHERE n.MaND IN (?, ?) AND t.TrangThai IN ('Đã ghép', 'Đang diễn ra') LIMIT 1"
            );
            $st->execute([$maND, $maKia]);
            if ($st->fetchColumn()) {
                $pdo->rollBack();
                return null;
            }

            // 4. Chọn ngẫu nhiên đúng số câu hợp lệ (cả hai người dùng chung bộ câu, cùng thứ tự)
            if ($maKT === 3 || $maKT === 0) {
                $st = $pdo->prepare("SELECT c.MaCauHoi FROM CAUHOI c WHERE c.MaMon = ? AND c.TrangThai = 'Hiển thị'
                     AND (SELECT COUNT(*) FROM DAPAN d WHERE d.MaCauHoi = c.MaCauHoi) BETWEEN 2 AND 4
                     AND (SELECT COUNT(*) FROM DAPAN d WHERE d.MaCauHoi = c.MaCauHoi AND d.LaDapAnDung = 1) = 1
                     ORDER BY RAND() LIMIT " . $soCau);
                $st->execute([$maMon]);
            } else {
                $st = $pdo->prepare('SELECT c.MaCauHoi FROM CAUHOI c WHERE ' . self::DK_CAU_HOP_LE . ' ORDER BY RAND() LIMIT ' . $soCau);
                $st->execute([$maMon, $maKT]);
            }
            $dsCau = $st->fetchAll(PDO::FETCH_COLUMN);
            if (count($dsCau) < $soCau) {
                $pdo->rollBack();
                return null;
            }

            // 5. Tạo trận, hai người chơi, bộ câu hỏi (chụp luật chơi vào từng câu, C-28), rồi rút cả hai khỏi hàng đợi
            $dotBienKey  = DotBien::ngauNhien();
            $dotBienInfo = DotBien::layDotBien($dotBienKey);
            $pdo->prepare('INSERT INTO TRANDAU (MaMon, MaLoaiKT, DotBien) VALUES (?, ?, ?)')
                ->execute([$maMon, $maKT, $dotBienKey]);
            $maTran = (int) $pdo->lastInsertId();

            // LanHoatDongCuoi lúc tạo = mốc tạo trận; dùng để tính hạn vào phòng của người chưa vào (C-30)
            $ins = $pdo->prepare('INSERT INTO NGUOICHOITRANDAU (MaTran, MaND, EloTruoc, LanHoatDongCuoi) VALUES (?, ?, ?, NOW(3))');
            $ins->execute([$maTran, $maND, $elo]);
            $ins->execute([$maTran, $maKia, $eloKia]);

            $diem = Config::int('DIEM_CO_BAN', 100);
            $heSo = Config::int('HE_SO_THUONG', 50);
            $tg   = $dotBienInfo['thoiGian'];
            $ins = $pdo->prepare(
                'INSERT INTO CAUHOITRANDAU (MaTran, MaCauHoi, ThuTu, DiemCoBan, HeSoThuong, ThoiGianQuyDinh) VALUES (?, ?, ?, ?, ?, ?)'
            );
            foreach ($dsCau as $i => $maCau) {
                $ins->execute([$maTran, (int) $maCau, $i + 1, $diem, $heSo, $tg]);
            }

            $pdo->prepare('DELETE FROM HANGDOIGHEPDOI WHERE MaND IN (?, ?)')->execute([$maND, $maKia]);
            $pdo->commit();
            return $maTran;
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // 40001 / 1213: deadlock; 1205: hết thời gian chờ khóa. Bỏ qua, lần tải lại sau sẽ thử lại.
            if ($ex->getCode() === '40001' || in_array((int) ($ex->errorInfo[1] ?? 0), [1205, 1213], true)) {
                return null;
            }
            throw $ex;
        }
    }

    // Người chơi vào phòng (C-23, C-30). Gọi mỗi lần trang phòng tải lại.
    // Cả hai đã vào thì trận chuyển sang "Đang diễn ra"; có người quá hạn chưa vào thì hủy trận, không đổi Elo.
    public static function vaoPhong(PDO $pdo, int $maTran, int $maND): void
    {
        $hanVao = max(1, Config::int('THOI_GIAN_CHO_VAO_PHONG', 30));
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT TrangThai FROM TRANDAU WHERE MaTran = ? FOR UPDATE');
            $st->execute([$maTran]);
            $trangThai = $st->fetchColumn();

            if ($trangThai === 'Đã ghép' || $trangThai === 'Đang diễn ra') {
                $pdo->prepare(
                    "UPDATE NGUOICHOITRANDAU
                        SET LanHoatDongCuoi = NOW(3),
                            TrangThaiThamGia = IF(TrangThaiThamGia = 'Chưa vào', 'Đang chơi', TrangThaiThamGia)
                      WHERE MaTran = ? AND MaND = ?"
                )->execute([$maTran, $maND]);
            }

            if ($trangThai === 'Đã ghép') {
                $st = $pdo->prepare(
                    "SELECT COUNT(*) AS Chua,
                            COALESCE(SUM(TIMESTAMPDIFF(SECOND, LanHoatDongCuoi, NOW(3)) > ?), 0) AS QuaHan
                       FROM NGUOICHOITRANDAU WHERE MaTran = ? AND TrangThaiThamGia = 'Chưa vào'"
                );
                $st->execute([$hanVao, $maTran]);
                $r = $st->fetch();
                if ((int) $r['QuaHan'] > 0) {
                    $pdo->prepare("UPDATE TRANDAU SET TrangThai = 'Hủy', LyDoKetThuc = 'Hủy do không vào phòng', ThoiGianKetThuc = NOW(3) WHERE MaTran = ?")
                        ->execute([$maTran]);
                } elseif ((int) $r['Chua'] === 0) {
                    $pdo->prepare("UPDATE TRANDAU SET TrangThai = 'Đang diễn ra', ThoiGianBatDau = NOW(3) WHERE MaTran = ?")
                        ->execute([$maTran]);
                }
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $ex;
        }
    }

    // Hủy trận chưa kết thúc, không đổi Elo. $lyDo phải là một giá trị của LyDoKetThuc.
    public static function huyTran(PDO $pdo, int $maTran, string $lyDo): bool
    {
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT TrangThai FROM TRANDAU WHERE MaTran = ? FOR UPDATE');
            $st->execute([$maTran]);
            $ok = false;
            if (in_array($st->fetchColumn(), ['Đã ghép', 'Đang diễn ra'], true)) {
                $pdo->prepare("UPDATE TRANDAU SET TrangThai = 'Hủy', LyDoKetThuc = ?, ThoiGianKetThuc = NOW(3) WHERE MaTran = ?")
                    ->execute([$lyDo, $maTran]);
                $ok = true;
            }
            $pdo->commit();
            return $ok;
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $ex;
        }
    }

    // Kiểm tra một người dùng có phải là Bot Đấu Thần Máy hay không
    public static function laBot(PDO $pdo, int $maND): bool
    {
        $st = $pdo->prepare("SELECT 1 FROM NGUOIDUNG WHERE MaND = ? AND Email = 'bot@quizup.arena'");
        $st->execute([$maND]);
        return (bool) $st->fetchColumn();
    }

    // Lấy hoặc khởi tạo tài khoản Bot Đấu Thần Máy
    public static function layMaBot(PDO $pdo): int
    {
        $st = $pdo->prepare("SELECT MaND FROM NGUOIDUNG WHERE Email = 'bot@quizup.arena'");
        $st->execute();
        $id = $st->fetchColumn();
        if ($id) {
            return (int) $id;
        }
        $hash = password_hash('bot_arena_2026', PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO NGUOIDUNG (HoTen, Email, MatKhau, TrangThai, Lop, Khoa) VALUES ('Zombie Máy AI [Buckethead]', 'bot@quizup.arena', ?, 'Hoạt động', 'AI Arena', 'Đấu Thần PvZ Arena')")
            ->execute([$hash]);
        return (int) $pdo->lastInsertId();
    }

    // Tạo trận đối kháng với Đấu Thần Máy (Bot AI)
    public static function ghepVoiBot(PDO $pdo, int $maND, int $maMon, int $maKT, int $elo, int $soCau): ?int
    {
        $maBot = self::layMaBot($pdo);
        if ($maND === $maBot) {
            return null;
        }

        // Chọn ngẫu nhiên tên Tướng Bot cho sống động
        $dsTuongNames = [
            'Zombie Máy AI [Buckethead]',
            'Tiến Sĩ Zomboss [AI Thông Minh]',
            'Chiến Binh Flag Zombie [AI]',
            'Cây Đậu Băng [AI Thách Đấu]',
            'Hoa Hướng Dương [AI Vui Vẻ]',
            'Quả Óc Chó [AI Kiên Cường]'
        ];
        $tenBot = $dsTuongNames[array_rand($dsTuongNames)];
        $pdo->prepare("UPDATE NGUOIDUNG SET HoTen = ? WHERE MaND = ?")->execute([$tenBot, $maBot]);

        $eloBot = max(800, $elo + rand(-35, 45));
        $pdo->prepare('INSERT IGNORE INTO ELOMONHOC (MaND, MaMon, DiemElo) VALUES (?, ?, ?)')
            ->execute([$maBot, $maMon, $eloBot]);

        // Hủy bất kỳ trận cũ dở dang của bot nếu có
        $st = $pdo->prepare("SELECT t.MaTran FROM NGUOICHOITRANDAU n JOIN TRANDAU t ON t.MaTran = n.MaTran WHERE n.MaND = ? AND t.TrangThai IN ('Đã ghép', 'Đang diễn ra')");
        $st->execute([$maBot]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $tranCu) {
            self::huyTran($pdo, (int) $tranCu, 'Hủy do ghép trận mới');
        }

        $pdo->beginTransaction();
        try {
            // Chọn ngẫu nhiên câu hỏi
            if ($maKT === 3 || $maKT === 0) {
                $st = $pdo->prepare("SELECT c.MaCauHoi FROM CAUHOI c WHERE c.MaMon = ? AND c.TrangThai = 'Hiển thị'
                     AND (SELECT COUNT(*) FROM DAPAN d WHERE d.MaCauHoi = c.MaCauHoi) BETWEEN 2 AND 4
                     AND (SELECT COUNT(*) FROM DAPAN d WHERE d.MaCauHoi = c.MaCauHoi AND d.LaDapAnDung = 1) = 1
                     ORDER BY RAND() LIMIT " . $soCau);
                $st->execute([$maMon]);
            } else {
                $st = $pdo->prepare('SELECT c.MaCauHoi FROM CAUHOI c WHERE ' . self::DK_CAU_HOP_LE . ' ORDER BY RAND() LIMIT ' . $soCau);
                $st->execute([$maMon, $maKT]);
            }
            $dsCau = $st->fetchAll(PDO::FETCH_COLUMN);
            if (count($dsCau) < $soCau) {
                $pdo->rollBack();
                return null;
            }

            // Tạo trận
            $dotBienKey  = DotBien::ngauNhien();
            $dotBienInfo = DotBien::layDotBien($dotBienKey);
            $pdo->prepare('INSERT INTO TRANDAU (MaMon, MaLoaiKT, TrangThai, ThoiGianBatDau, DotBien) VALUES (?, ?, "Đang diễn ra", NOW(3), ?)')
                ->execute([$maMon, $maKT, $dotBienKey]);
            $maTran = (int) $pdo->lastInsertId();

            $diem = Config::int('DIEM_CO_BAN', 100);
            $heSo = Config::int('HE_SO_THUONG', 50);
            $tg   = $dotBienInfo['thoiGian'];

            // Người chơi: trạng thái đang chơi
            $ins = $pdo->prepare('INSERT INTO NGUOICHOITRANDAU (MaTran, MaND, EloTruoc, LanHoatDongCuoi, TrangThaiThamGia) VALUES (?, ?, ?, NOW(3), "Đang chơi")');
            $ins->execute([$maTran, $maND, $elo]);
            // Bot: trạng thái đang chơi
            $ins->execute([$maTran, $maBot, $eloBot]);

            // Thêm các câu hỏi
            $insQ = $pdo->prepare(
                'INSERT INTO CAUHOITRANDAU (MaTran, MaCauHoi, ThuTu, DiemCoBan, HeSoThuong, ThoiGianQuyDinh) VALUES (?, ?, ?, ?, ?, ?)'
            );
            foreach ($dsCau as $i => $maCau) {
                $insQ->execute([$maTran, (int) $maCau, $i + 1, $diem, $heSo, $tg]);
            }

            // Xóa người chơi khỏi hàng đợi
            $pdo->prepare('DELETE FROM HANGDOIGHEPDOI WHERE MaND = ?')->execute([$maND]);
            $pdo->commit();

            return $maTran;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return null;
        }
    }
}