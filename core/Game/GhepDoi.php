<?php
// Ghép đôi hàng đợi và tạo trận đấu.
// Khóa dòng chờ theo MaND tăng dần để tránh deadlock khi nhiều người gọi cùng lúc (chạy được trên MariaDB).

class GhepDoi
{
    // Khoảng giây mỗi lần nới rộng ngưỡng Elo ghép đôi thêm 1 bậc
    public const BUOC_NOI_GIAY = 10;

    // Điều kiện câu hỏi hợp lệ cho (môn, loại kỳ thi)
    private const DK_CAU_HOP_LE =
        "c.MaMon = ? AND c.TrangThai = 'Hiển thị'
         AND (c.MaLoaiKT = ? OR c.MaLoaiKT IS NULL)
         AND (SELECT COUNT(*) FROM DAPAN d WHERE d.MaCauHoi = c.MaCauHoi) BETWEEN 2 AND 4
         AND (SELECT COUNT(*) FROM DAPAN d WHERE d.MaCauHoi = c.MaCauHoi AND d.LaDapAnDung = 1) = 1";

    private static array $demCache = [];

    public static function demCauHoi(PDO $pdo, int $maMon, int $maLoaiKT): int
    {
        $ck = $maMon . '|' . $maLoaiKT;
        if (isset(self::$demCache[$ck])) {
            return self::$demCache[$ck];
        }
        if ($maLoaiKT === 3 || $maLoaiKT === 0) {
            $sql = "SELECT COUNT(*) FROM CAUHOI c WHERE c.MaMon = ? AND c.TrangThai = 'Hiển thị'
                 AND (SELECT COUNT(*) FROM DAPAN d WHERE d.MaCauHoi = c.MaCauHoi) BETWEEN 2 AND 4
                 AND (SELECT COUNT(*) FROM DAPAN d WHERE d.MaCauHoi = c.MaCauHoi AND d.LaDapAnDung = 1) = 1";
            $st = $pdo->prepare($sql);
            $st->execute([$maMon]);
            $res = (int) $st->fetchColumn();
            self::$demCache[$ck] = $res;
            return $res;
        }
        $st = $pdo->prepare('SELECT COUNT(*) FROM CAUHOI c WHERE ' . self::DK_CAU_HOP_LE);
        $st->execute([$maMon, $maLoaiKT]);
        $res = (int) $st->fetchColumn();
        self::$demCache[$ck] = $res;
        return $res;
    }

    /**
     * Bốc câu hỏi cân bằng độ khó (Dễ -> Trung bình -> Khó), ưu tiên câu chưa làm trong 24h và cân bằng kỳ thi.
     */
    public static function bocCauHoiChuyenNghiep(PDO $pdo, int $maMon, int $maLoaiKT, int $soCau, array $danhSachNguoiChoi = []): array
    {
        $soCau = max(1, $soCau);

        // Thu thập câu hỏi hợp lệ trong môn
        if ($maLoaiKT === 3 || $maLoaiKT === 0) {
            $sql = "SELECT c.MaCauHoi, c.DoKho, COALESCE(c.MaLoaiKT, 1) AS MaLoaiKT
                    FROM CAUHOI c
                    WHERE c.MaMon = ? AND c.TrangThai = 'Hiển thị'
                      AND (SELECT COUNT(*) FROM DAPAN d WHERE d.MaCauHoi = c.MaCauHoi) BETWEEN 2 AND 4
                      AND (SELECT COUNT(*) FROM DAPAN d WHERE d.MaCauHoi = c.MaCauHoi AND d.LaDapAnDung = 1) = 1";
            $st = $pdo->prepare($sql);
            $st->execute([$maMon]);
        } else {
            $sql = "SELECT c.MaCauHoi, c.DoKho, COALESCE(c.MaLoaiKT, 1) AS MaLoaiKT
                    FROM CAUHOI c
                    WHERE " . self::DK_CAU_HOP_LE;
            $st = $pdo->prepare($sql);
            $st->execute([$maMon, $maLoaiKT]);
        }
        $tatCaCau = $st->fetchAll(PDO::FETCH_ASSOC);
        if (count($tatCaCau) < $soCau) {
            return [];
        }

        // Thu thập câu đã làm trong 24h gần đây để ưu tiên câu mới
        $cacCauDaLamGanDay = [];
        $ndHopLe = array_values(array_filter(array_map('intval', $danhSachNguoiChoi), fn($id) => $id > 0 && !self::laBot($pdo, $id)));
        if (!empty($ndHopLe)) {
            $inPlaceholders = implode(',', array_fill(0, count($ndHopLe), '?'));
            $sqlRecent = "SELECT ct.MaCauHoi
                          FROM CHITIETTRALOI ct
                          JOIN TRANDAU tr ON tr.MaTran = ct.MaTran
                          WHERE ct.MaND IN ($inPlaceholders)
                            AND tr.MaMon = ?
                            AND tr.TrangThai = 'Kết thúc'
                            AND tr.ThoiGianKetThuc >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                          ORDER BY tr.MaTran DESC
                          LIMIT " . ($soCau * 5);
            try {
                $stRecent = $pdo->prepare($sqlRecent);
                $stRecent->execute([...$ndHopLe, $maMon]);
                $cacCauDaLamGanDay = array_flip($stRecent->fetchAll(PDO::FETCH_COLUMN));
            } catch (Throwable $e) {}
        }

        // Phân nhóm theo độ khó và trạng thái fresh / recent
        $nhomDoKho = [
            'De'  => ['fresh' => [], 'recent' => []],
            'TB'  => ['fresh' => [], 'recent' => []],
            'Kho' => ['fresh' => [], 'recent' => []],
        ];

        foreach ($tatCaCau as $cau) {
            $maCau = (int) $cau['MaCauHoi'];
            $rawDk = trim((string) ($cau['DoKho'] ?? ''));
            $dkKey = 'TB';
            if ($rawDk === 'Dễ' || mb_strtolower($rawDk) === 'dễ') {
                $dkKey = 'De';
            } elseif ($rawDk === 'Khó' || mb_strtolower($rawDk) === 'khó') {
                $dkKey = 'Kho';
            }
            $isRecent = isset($cacCauDaLamGanDay[$maCau]);
            if ($isRecent) {
                $nhomDoKho[$dkKey]['recent'][] = $cau;
            } else {
                $nhomDoKho[$dkKey]['fresh'][] = $cau;
            }
        }

        // Xáo trộn ngẫu nhiên từng nhóm nhỏ
        foreach ($nhomDoKho as $k => &$nhom) {
            shuffle($nhom['fresh']);
            shuffle($nhom['recent']);
        }
        unset($nhom);

        // Phân bổ số câu mục tiêu cho từng độ khó
        if ($soCau >= 10) {
            $mucTieuDe = (int) round($soCau * 0.30);
            $mucTieuKho = (int) round($soCau * 0.30);
            $mucTieuTB = $soCau - $mucTieuDe - $mucTieuKho;
        } elseif ($soCau === 5) {
            $mucTieuDe = 2;
            $mucTieuTB = 2;
            $mucTieuKho = 1;
        } else {
            $mucTieuDe = max(1, (int) round($soCau * 0.35));
            $mucTieuKho = max(1, (int) round($soCau * 0.25));
            $mucTieuTB = max(1, $soCau - $mucTieuDe - $mucTieuKho);
            while ($mucTieuDe + $mucTieuTB + $mucTieuKho > $soCau) {
                if ($mucTieuDe > 1) $mucTieuDe--;
                elseif ($mucTieuTB > 1) $mucTieuTB--;
                else $mucTieuKho--;
            }
        }

        // Helper rút câu hỏi từ một nhóm độ khó (Ưu tiên fresh -> recent; Cân bằng Giữa kỳ & Cuối kỳ nếu chọn Cả 2)
        $rutCau = function (string $dkKey, int $soLuongCan) use (&$nhomDoKho, $maLoaiKT): array {
            $ketQua = [];
            if ($maLoaiKT === 3 && $soLuongCan > 1) {
                $pools = ['fresh', 'recent'];
                foreach ($pools as $p) {
                    if ($soLuongCan <= 0) break;
                    $kt1 = [];
                    $kt2 = [];
                    $ktKhac = [];
                    foreach ($nhomDoKho[$dkKey][$p] as $c) {
                        $kId = (int) ($c['MaLoaiKT'] ?? 0);
                        if ($kId === 1) $kt1[] = $c;
                        elseif ($kId === 2) $kt2[] = $c;
                        else $ktKhac[] = $c;
                    }
                    $nhomDoKho[$dkKey][$p] = [];
                    while ($soLuongCan > 0 && (!empty($kt1) || !empty($kt2) || !empty($ktKhac))) {
                        if (!empty($kt1) && $soLuongCan > 0) {
                            $ketQua[] = array_shift($kt1);
                            $soLuongCan--;
                        }
                        if (!empty($kt2) && $soLuongCan > 0) {
                            $ketQua[] = array_shift($kt2);
                            $soLuongCan--;
                        }
                        if (!empty($ktKhac) && $soLuongCan > 0) {
                            $ketQua[] = array_shift($ktKhac);
                            $soLuongCan--;
                        }
                    }
                    $nhomDoKho[$dkKey][$p] = array_merge($kt1, $kt2, $ktKhac);
                }
            } else {
                while ($soLuongCan > 0 && !empty($nhomDoKho[$dkKey]['fresh'])) {
                    $ketQua[] = array_shift($nhomDoKho[$dkKey]['fresh']);
                    $soLuongCan--;
                }
                while ($soLuongCan > 0 && !empty($nhomDoKho[$dkKey]['recent'])) {
                    $ketQua[] = array_shift($nhomDoKho[$dkKey]['recent']);
                    $soLuongCan--;
                }
            }
            return $ketQua;
        };

        // Bốc theo hạn ngạch
        $danhSachDe  = $rutCau('De', $mucTieuDe);
        $danhSachTB  = $rutCau('TB', $mucTieuTB);
        $danhSachKho = $rutCau('Kho', $mucTieuKho);

        // Bù từ độ khó khác nếu kho câu không đủ ở một mức độ khó
        $soConThieu = $soCau - (count($danhSachDe) + count($danhSachTB) + count($danhSachKho));
        if ($soConThieu > 0) {
            $thuTuBu = ['TB', 'De', 'Kho'];
            foreach ($thuTuBu as $dkKey) {
                if ($soConThieu <= 0) break;
                $buThem = $rutCau($dkKey, $soConThieu);
                if (!empty($buThem)) {
                    if ($dkKey === 'De') {
                        $danhSachDe = array_merge($danhSachDe, $buThem);
                    } elseif ($dkKey === 'Kho') {
                        $danhSachKho = array_merge($danhSachKho, $buThem);
                    } else {
                        $danhSachTB = array_merge($danhSachTB, $buThem);
                    }
                    $soConThieu -= count($buThem);
                }
            }
        }

        // Xáo trộn nội bộ trong từng độ khó trước khi ghép lại
        shuffle($danhSachDe);
        shuffle($danhSachTB);
        shuffle($danhSachKho);

        // Sắp xếp từ Dễ -> Trung bình -> Khó
        $danhSachCuoi = array_merge($danhSachDe, $danhSachTB, $danhSachKho);

        if (count($danhSachCuoi) > $soCau) {
            $danhSachCuoi = array_slice($danhSachCuoi, 0, $soCau);
        }

        if (count($danhSachCuoi) < $soCau) {
            return [];
        }

        return array_map(fn($item) => (int) $item['MaCauHoi'], $danhSachCuoi);
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
            // Đọc dòng chờ của mình (chưa khóa)
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

            // Tìm đối thủ cùng môn/kỳ thi trong ngưỡng Elo rộng hơn của hai bên, bỏ qua quá hạn chờ
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
                // Nếu hết thời gian chờ tối đa và cho phép Bot thì ghép với máy
                if ($choPhepBot && (int) $toi['DaCho'] >= $choToiDa) {
                    return self::ghepVoiBot($pdo, $maND, $maMon, $maKT, $elo, $soCau);
                }
                return null;
            }
            $maKia  = (int) $kia['MaND'];
            $eloKia = (int) $kia['EloTaiThoiDiemCho'];

            // Khóa dòng chờ theo MaND tăng dần để tránh deadlock và không cần SKIP LOCKED trên MariaDB
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

            // Khóa NGUOIDUNG theo MaND tăng dần để đảm bảo không ai đang có trận dở dang
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

            $dsCau = self::bocCauHoiChuyenNghiep($pdo, $maMon, $maKT, $soCau, [$maND, $maKia]);
            if (count($dsCau) < $soCau) {
                $pdo->rollBack();
                return null;
            }

            // Tạo trận, lưu snapshot luật vào từng câu rồi xóa khỏi hàng đợi
            $dotBienKey  = DotBien::ngauNhien();
            $dotBienInfo = DotBien::layDotBien($dotBienKey);
            $pdo->prepare('INSERT INTO TRANDAU (MaMon, MaLoaiKT, DotBien) VALUES (?, ?, ?)')
                ->execute([$maMon, $maKT, $dotBienKey]);
            $maTran = (int) $pdo->lastInsertId();

            // LanHoatDongCuoi = mốc tạo trận để tính hạn vào phòng
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

    // Người chơi vào phòng: cả hai đã vào thì bắt đầu trận; quá hạn có người chưa vào thì hủy trận.
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
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $st = $pdo->prepare('SELECT TrangThai FROM TRANDAU WHERE MaTran = ? FOR UPDATE');
            $st->execute([$maTran]);
            $ok = false;
            if (in_array($st->fetchColumn(), ['Đã ghép', 'Đang diễn ra'], true)) {
                $pdo->prepare("UPDATE TRANDAU SET TrangThai = 'Hủy', LyDoKetThuc = ?, ThoiGianKetThuc = NOW(3) WHERE MaTran = ?")
                    ->execute([$lyDo, $maTran]);
                $ok = true;
            }
            if ($ownTx) {
                $pdo->commit();
            }
            return $ok;
        } catch (Throwable $ex) {
            if ($ownTx && $pdo->inTransaction()) {
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
        $pdo->prepare("INSERT INTO NGUOIDUNG (HoTen, Email, MatKhau, TrangThai) VALUES ('Zombie Máy AI [Buckethead]', 'bot@quizup.arena', ?, 'Hoạt động')")
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
            $dsCau = self::bocCauHoiChuyenNghiep($pdo, $maMon, $maKT, $soCau, [$maND]);
            if (count($dsCau) < $soCau) {
                $pdo->rollBack();
                return null;
            }

            $dotBienKey  = DotBien::ngauNhien();
            $dotBienInfo = DotBien::layDotBien($dotBienKey);
            $pdo->prepare('INSERT INTO TRANDAU (MaMon, MaLoaiKT, TrangThai, ThoiGianBatDau, DotBien) VALUES (?, ?, "Đang diễn ra", NOW(3), ?)')
                ->execute([$maMon, $maKT, $dotBienKey]);
            $maTran = (int) $pdo->lastInsertId();

            $diem = Config::int('DIEM_CO_BAN', 100);
            $heSo = Config::int('HE_SO_THUONG', 50);
            $tg   = $dotBienInfo['thoiGian'];

            $ins = $pdo->prepare('INSERT INTO NGUOICHOITRANDAU (MaTran, MaND, EloTruoc, LanHoatDongCuoi, TrangThaiThamGia) VALUES (?, ?, ?, NOW(3), "Đang chơi")');
            $ins->execute([$maTran, $maND, $elo]);
            $ins->execute([$maTran, $maBot, $eloBot]);

            $insQ = $pdo->prepare(
                'INSERT INTO CAUHOITRANDAU (MaTran, MaCauHoi, ThuTu, DiemCoBan, HeSoThuong, ThoiGianQuyDinh) VALUES (?, ?, ?, ?, ?, ?)'
            );
            foreach ($dsCau as $i => $maCau) {
                $insQ->execute([$maTran, (int) $maCau, $i + 1, $diem, $heSo, $tg]);
            }

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

    /** Đưa người chơi lại hàng đợi ưu tiên (lùi mốc chờ 30s) khi đối thủ AFK. */
    public static function vaoHangUuTien(PDO $pdo, int $maND, int $maMon, int $maLoaiKT, int $elo): void
    {
        $st = $pdo->prepare(
            'INSERT INTO HANGDOIGHEPDOI (MaND, MaMon, MaLoaiKT, EloTaiThoiDiemCho, ThoiGianVaoHang)
             VALUES (?, ?, ?, ?, DATE_SUB(NOW(), INTERVAL 30 SECOND))
             ON DUPLICATE KEY UPDATE
                MaMon = VALUES(MaMon),
                MaLoaiKT = VALUES(MaLoaiKT),
                EloTaiThoiDiemCho = VALUES(EloTaiThoiDiemCho),
                ThoiGianVaoHang = DATE_SUB(NOW(), INTERVAL 30 SECOND)'
        );
        $st->execute([$maND, $maMon, $maLoaiKT, $elo]);
    }

    /** Tạo trận đấu 1v1 trực tiếp giữa 2 người chơi (dùng cho phòng thách đấu). */
    public static function taoTranTrucTiep(PDO $pdo, int $nd1, int $nd2, int $maMon, int $maKT): ?int
    {
        $soCau = max(1, Config::int('SO_CAU', 10));

        // Hủy bất kỳ trận dở dang cũ nào của cả 2
        foreach ([$nd1, $nd2] as $nd) {
            $st = $pdo->prepare("SELECT t.MaTran FROM NGUOICHOITRANDAU n JOIN TRANDAU t ON t.MaTran = n.MaTran WHERE n.MaND = ? AND t.TrangThai IN ('Đã ghép', 'Đang diễn ra')");
            $st->execute([$nd]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $tranCu) {
                self::huyTran($pdo, (int) $tranCu, 'Hủy do không vào phòng');
            }
        }

        // Lấy Elo hiện tại của 2 người chơi môn này
        $st = $pdo->prepare('SELECT DiemElo FROM ELOMONHOC WHERE MaND = ? AND MaMon = ?');
        $st->execute([$nd1, $maMon]);
        $elo1 = (int) ($st->fetchColumn() ?: 1000);

        $st->execute([$nd2, $maMon]);
        $elo2 = (int) ($st->fetchColumn() ?: 1000);

        $dsCau = self::bocCauHoiChuyenNghiep($pdo, $maMon, $maKT, $soCau, [$nd1, $nd2]);
        if (count($dsCau) < $soCau) {
            return null;
        }

        $dotBienKey  = DotBien::ngauNhien();
        $dotBienInfo = DotBien::layDotBien($dotBienKey);

        // Tạo trận đấu: trạng thái ban đầu là "Đã ghép" để kích hoạt Ready Check
        $pdo->prepare('INSERT INTO TRANDAU (MaMon, MaLoaiKT, DotBien) VALUES (?, ?, ?)')
            ->execute([$maMon, $maKT, $dotBienKey]);
        $maTran = (int) $pdo->lastInsertId();

        $diem = Config::int('DIEM_CO_BAN', 100);
        $heSo = Config::int('HE_SO_THUONG', 50);
        $tg   = $dotBienInfo['thoiGian'];

        $ins = $pdo->prepare('INSERT INTO NGUOICHOITRANDAU (MaTran, MaND, EloTruoc, LanHoatDongCuoi, TrangThaiThamGia) VALUES (?, ?, ?, NOW(3), "Chưa vào")');
        $ins->execute([$maTran, $nd1, $elo1]);
        $ins->execute([$maTran, $nd2, $elo2]);

        $insQ = $pdo->prepare(
            'INSERT INTO CAUHOITRANDAU (MaTran, MaCauHoi, ThuTu, DiemCoBan, HeSoThuong, ThoiGianQuyDinh) VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($dsCau as $i => $maCau) {
            $insQ->execute([$maTran, (int) $maCau, $i + 1, $diem, $heSo, $tg]);
        }

        // Rút cả 2 khỏi hàng đợi công cộng nếu đang ở trong đó
        $pdo->prepare('DELETE FROM HANGDOIGHEPDOI WHERE MaND IN (?, ?)')->execute([$nd1, $nd2]);

        return $maTran;
    }
}