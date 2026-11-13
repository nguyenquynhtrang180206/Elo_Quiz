<?php
// Diễn biến trận đấu (C-23 đến C-31). Đặt tại: elo_quiz/core/ThiDau.php
// Mọi thao tác trong một trận đều khóa dòng TRANDAU trước (cùng một thứ tự khóa ở mọi nơi nên không deadlock),
// và mọi mốc thời gian lấy từ giờ máy chủ (NOW(3)); đồng hồ trên trình duyệt chỉ để hiển thị.
// Elo sau trận: xem core/Elo.php (C-32, C-33). Chưa làm: màn xem lại (C-34).
require_once __DIR__ . '/Elo.php';
require_once __DIR__ . '/GhepDoi.php';
require_once __DIR__ . '/DotBien.php';
require_once __DIR__ . '/LoLChampions.php';
class ThiDau
{
    public const DEM_NGUOC_GIAY = 3;   // đếm ngược trước khi câu 1 hiện, để hai người bắt đầu gần như cùng lúc

    private static function khoaTran(PDO $pdo, int $maTran): ?string
    {
        $st = $pdo->prepare('SELECT TrangThai FROM TRANDAU WHERE MaTran = ? FOR UPDATE');
        $st->execute([$maTran]);
        $v = $st->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    // Câu người này đang làm dở: đã được gửi (có ThoiDiemBatDau) nhưng chưa trả lời, chưa hết giờ
    private static function dongDangLam(PDO $pdo, int $maTran, int $maND): ?array
    {
        $st = $pdo->prepare(
            "SELECT c.MaCauHoi, c.ThuTu, c.ThoiGianQuyDinh, c.DiemCoBan, c.HeSoThuong,
                    TIMESTAMPDIFF(MICROSECOND, t.ThoiDiemBatDau, NOW(3)) / 1000000 AS DaDung
               FROM CHITIETTRALOI t
               JOIN CAUHOITRANDAU c ON c.MaTran = t.MaTran AND c.MaCauHoi = t.MaCauHoi
              WHERE t.MaTran = ? AND t.MaND = ? AND t.ThoiDiemBatDau IS NOT NULL AND t.TrangThai = 'Không làm'
              ORDER BY c.ThuTu DESC LIMIT 1"
        );
        $st->execute([$maTran, $maND]);
        $r = $st->fetch();
        return $r ?: null;
    }

    private static function capNhatDiem(PDO $pdo, int $maTran, int $maND): void
    {
        $pdo->prepare(
            'UPDATE NGUOICHOITRANDAU
                SET DiemSo = (SELECT COALESCE(SUM(DiemNhanDuoc), 0) FROM CHITIETTRALOI WHERE MaTran = ? AND MaND = ?)
              WHERE MaTran = ? AND MaND = ?'
        )->execute([$maTran, $maND, $maTran, $maND]);
    }

    private static function capNhatDiemCaHai(PDO $pdo, int $maTran): void
    {
        $st = $pdo->prepare('SELECT MaND FROM NGUOICHOITRANDAU WHERE MaTran = ?');
        $st->execute([$maTran]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $nd) {
            self::capNhatDiem($pdo, $maTran, (int) $nd);
        }
    }

    private static function chotHetGio(PDO $pdo, int $maTran, int $maND, int $maCau, int $thoiGian): void
    {
        $pdo->prepare(
            "UPDATE CHITIETTRALOI SET TrangThai = 'Hết giờ', ThoiGianTraLoi = ?, DiemNhanDuoc = 0
              WHERE MaTran = ? AND MaND = ? AND MaCauHoi = ? AND TrangThai = 'Không làm'"
        )->execute([$thoiGian, $maTran, $maND, $maCau]);
    }

    // Trận kết thúc sớm (xử thua, hủy): chốt các câu đang làm dở là hết giờ, tạo bù dòng "Không làm" cho
    // các câu chưa được gửi để màn xem lại không thiếu câu (ghi chú 4.3.2)
    private static function chotVaTaoBu(PDO $pdo, int $maTran): void
    {
        $pdo->prepare(
            "UPDATE CHITIETTRALOI t
               JOIN CAUHOITRANDAU c ON c.MaTran = t.MaTran AND c.MaCauHoi = t.MaCauHoi
                SET t.TrangThai = 'Hết giờ', t.ThoiGianTraLoi = c.ThoiGianQuyDinh
              WHERE t.MaTran = ? AND t.ThoiDiemBatDau IS NOT NULL AND t.TrangThai = 'Không làm'"
        )->execute([$maTran]);
        $pdo->prepare(
            'INSERT INTO CHITIETTRALOI (MaTran, MaND, MaCauHoi)
             SELECT n.MaTran, n.MaND, c.MaCauHoi
               FROM NGUOICHOITRANDAU n JOIN CAUHOITRANDAU c ON c.MaTran = n.MaTran
              WHERE n.MaTran = ?
                AND NOT EXISTS (SELECT 1 FROM CHITIETTRALOI t
                                 WHERE t.MaTran = n.MaTran AND t.MaND = n.MaND AND t.MaCauHoi = c.MaCauHoi)'
        )->execute([$maTran]);
    }

    // C-29, C-30, C-31: ai không có hoạt động quá thời gian cho phép thì bị xử thua;
    // cả hai cùng mất kết nối thì hủy trận, không đổi Elo
    private static function kiemTraBoTran(PDO $pdo, int $maTran): void
    {
        $han = max(1, Config::int('THOI_GIAN_CHO_PHEP_MAT_KET_NOI', 30));
        $st = $pdo->prepare(
            "SELECT MaND FROM NGUOICHOITRANDAU
              WHERE MaTran = ? AND TrangThaiThamGia = 'Đang chơi' AND LanHoatDongCuoi IS NOT NULL
                AND TIMESTAMPDIFF(SECOND, LanHoatDongCuoi, NOW(3)) > ?"
        );
        $st->execute([$maTran, $han]);
        $mat = $st->fetchAll(PDO::FETCH_COLUMN);
        if (!$mat) {
            return;
        }
        self::chotVaTaoBu($pdo, $maTran);
        self::capNhatDiemCaHai($pdo, $maTran);
        if (count($mat) >= 2) {
            $pdo->prepare("UPDATE TRANDAU SET TrangThai = 'Hủy', LyDoKetThuc = 'Hủy do lỗi hệ thống', ThoiGianKetThuc = NOW(3) WHERE MaTran = ?")
                ->execute([$maTran]);
            return;
        }
        $thua = (int) $mat[0];
        $pdo->prepare("UPDATE NGUOICHOITRANDAU SET TrangThaiThamGia = 'Bỏ trận', KetQua = 'Thua' WHERE MaTran = ? AND MaND = ?")
            ->execute([$maTran, $thua]);
        $pdo->prepare("UPDATE NGUOICHOITRANDAU SET TrangThaiThamGia = 'Đã xong', KetQua = 'Thắng' WHERE MaTran = ? AND MaND <> ?")
            ->execute([$maTran, $thua]);
        $pdo->prepare("UPDATE TRANDAU SET TrangThai = 'Kết thúc', LyDoKetThuc = 'Đối thủ bỏ trận (xử thua)', ThoiGianKetThuc = NOW(3) WHERE MaTran = ?")
            ->execute([$maTran]);
        Elo::capNhatSauTran($pdo, $maTran);
        self::congExpSauTran($pdo, $maTran);
    }

    // C-31: cả hai đã xong mọi câu thì kết thúc; điểm cao hơn thắng, bằng điểm thì hòa
    private static function ketThucNeuXong(PDO $pdo, int $maTran): void
    {
        $st = $pdo->prepare("SELECT COUNT(*) FROM NGUOICHOITRANDAU WHERE MaTran = ? AND TrangThaiThamGia <> 'Đã xong'");
        $st->execute([$maTran]);
        if ((int) $st->fetchColumn() > 0) {
            return;
        }
        $st = $pdo->prepare('SELECT MaND, DiemSo FROM NGUOICHOITRANDAU WHERE MaTran = ? ORDER BY MaND');
        $st->execute([$maTran]);
        $ds = $st->fetchAll();
        if (count($ds) !== 2) {
            return;
        }
        $a = (int) $ds[0]['DiemSo'];
        $b = (int) $ds[1]['DiemSo'];
        $kq = $a > $b ? ['Thắng', 'Thua'] : ($a < $b ? ['Thua', 'Thắng'] : ['Hòa', 'Hòa']);
        $up = $pdo->prepare('UPDATE NGUOICHOITRANDAU SET KetQua = ? WHERE MaTran = ? AND MaND = ?');
        $up->execute([$kq[0], $maTran, (int) $ds[0]['MaND']]);
        $up->execute([$kq[1], $maTran, (int) $ds[1]['MaND']]);
        $pdo->prepare("UPDATE TRANDAU SET TrangThai = 'Kết thúc', LyDoKetThuc = 'Hoàn thành', ThoiGianKetThuc = NOW(3) WHERE MaTran = ?")
            ->execute([$maTran]);
        Elo::capNhatSauTran($pdo, $maTran);
        self::congExpSauTran($pdo, $maTran);
    }

    // Cộng điểm thông thạo & EXP tiến hóa cho người chơi sau trận
    private static function congExpSauTran(PDO $pdo, int $maTran): void
    {
        try {
            $st = $pdo->prepare('SELECT MaND, KetQua FROM NGUOICHOITRANDAU WHERE MaTran = ?');
            $st->execute([$maTran]);
            $ds = $st->fetchAll();
            foreach ($ds as $r) {
                $nd = (int) $r['MaND'];
                if (GhepDoi::laBot($pdo, $nd)) {
                    continue;
                }
                $laThang = ($r['KetQua'] === 'Thắng');
                $exp = $laThang ? 160 : ($r['KetQua'] === 'Hòa' ? 100 : 60);
                $stT = $pdo->prepare('SELECT TuongChon FROM NGUOIDUNG WHERE MaND = ?');
                $stT->execute([$nd]);
                $tuongId = (string) ($stT->fetchColumn() ?: 'sunflower');   // tướng xuất chiến lưu trong CSDL
                LoLChampions::congEXP($pdo, $nd, $tuongId, $exp, $laThang);
            }
        } catch (Throwable $e) {
            error_log('Lỗi cộng EXP thông thạo: ' . $e->getMessage());
        }
    }

    // Đẩy tiến trình của một người: chốt câu quá hạn, gửi câu kế tiếp, đánh dấu xong khi hết câu (C-24, C-25)
    private static function tienTrinh(PDO $pdo, int $maTran, int $maND): void
    {
        $dungSai = max(0, Config::int('DUNG_SAI_DO_TRE', 1));
        $dong = self::dongDangLam($pdo, $maTran, $maND);
        if ($dong && (float) $dong['DaDung'] > (int) $dong['ThoiGianQuyDinh'] + $dungSai) {
            self::chotHetGio($pdo, $maTran, $maND, (int) $dong['MaCauHoi'], (int) $dong['ThoiGianQuyDinh']);
            self::capNhatDiem($pdo, $maTran, $maND);
            $dong = null;
        }
        if ($dong) {
            return;
        }
        // Chỉ gửi câu kế tiếp sau khi câu trước đã được chốt
        $st = $pdo->prepare(
            'SELECT c.MaCauHoi FROM CAUHOITRANDAU c
              WHERE c.MaTran = ?
                AND NOT EXISTS (SELECT 1 FROM CHITIETTRALOI t
                                 WHERE t.MaTran = c.MaTran AND t.MaND = ? AND t.MaCauHoi = c.MaCauHoi)
              ORDER BY c.ThuTu LIMIT 1'
        );
        $st->execute([$maTran, $maND]);
        $tiep = $st->fetchColumn();
        if ($tiep !== false) {
            $pdo->prepare('INSERT INTO CHITIETTRALOI (MaTran, MaND, MaCauHoi, ThoiDiemBatDau) VALUES (?, ?, ?, NOW(3))')
                ->execute([$maTran, $maND, (int) $tiep]);
            return;
        }
        $pdo->prepare("UPDATE NGUOICHOITRANDAU SET TrangThaiThamGia = 'Đã xong' WHERE MaTran = ? AND MaND = ? AND TrangThaiThamGia = 'Đang chơi'")
            ->execute([$maTran, $maND]);
        self::capNhatDiem($pdo, $maTran, $maND);
        self::ketThucNeuXong($pdo, $maTran);
    }

    // Trạng thái trận cho trình duyệt (polling): cũng là lúc ghi hoạt động cuối (C-29) và đẩy tiến trình của người gọi.
    // Không bao giờ có đáp án đúng trong kết quả trả về (C-27).
    public static function trangThai(PDO $pdo, int $maTran, int $maND): array
    {
        $pdo->beginTransaction();
        try {
            $tt = self::khoaTran($pdo, $maTran);
            $st = $pdo->prepare('SELECT TIMESTAMPDIFF(MICROSECOND, ThoiGianBatDau, NOW(3)) / 1000000 FROM TRANDAU WHERE MaTran = ?');
            $st->execute([$maTran]);
            $daTroi = $st->fetchColumn();
            $daTroi = $daTroi === null || $daTroi === false ? null : (float) $daTroi;

            if ($tt === 'Đang diễn ra') {
                self::kiemTraBoTran($pdo, $maTran);   // kiểm tra trước khi ghi hoạt động của chính mình
                $tt = self::khoaTran($pdo, $maTran);
            }
            if ($tt === 'Đang diễn ra') {
                $pdo->prepare("UPDATE NGUOICHOITRANDAU SET LanHoatDongCuoi = NOW(3) WHERE MaTran = ? AND MaND = ? AND TrangThaiThamGia = 'Đang chơi'")
                    ->execute([$maTran, $maND]);
                if ($daTroi !== null && $daTroi >= self::DEM_NGUOC_GIAY) {
                    self::tienTrinh($pdo, $maTran, $maND);
                    $tt = self::khoaTran($pdo, $maTran);
                }

                // Nếu đối thủ là Đấu Thần Máy (Bot AI), xử lý hoạt động và đáp án tự động của Bot
                $stBot = $pdo->prepare('SELECT MaND FROM NGUOICHOITRANDAU WHERE MaTran = ? AND MaND <> ?');
                $stBot->execute([$maTran, $maND]);
                $maDoiThu = (int) $stBot->fetchColumn();
                if ($maDoiThu && GhepDoi::laBot($pdo, $maDoiThu)) {
                    self::xuLyBot($pdo, $maTran, $maDoiThu, $daTroi);
                    $tt = self::khoaTran($pdo, $maTran);
                }
            }
            $kq = self::dungTrangThai($pdo, $maTran, $maND, (string) $tt, $daTroi);
            $pdo->commit();
            return $kq;
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $ex;
        }
    }

    private static function dungTrangThai(PDO $pdo, int $maTran, int $maND, string $tt, ?float $daTroi): array
    {
        $st = $pdo->prepare('SELECT COUNT(*) FROM CAUHOITRANDAU WHERE MaTran = ?');
        $st->execute([$maTran]);
        $tong = (int) $st->fetchColumn();

        $st = $pdo->prepare(
            "SELECT n.MaND, u.HoTen, n.DiemSo, n.TrangThaiThamGia,
                    (SELECT COUNT(*) FROM CHITIETTRALOI t
                      WHERE t.MaTran = n.MaTran AND t.MaND = n.MaND AND t.TrangThai IN ('Đã trả lời', 'Hết giờ')) AS DaXong,
                    (SELECT CASE WHEN t.TrangThai = 'Hết giờ' THEN 'hetgio' WHEN t.DiemNhanDuoc > 0 THEN 'dung' ELSE 'sai' END
                       FROM CHITIETTRALOI t JOIN CAUHOITRANDAU c ON c.MaTran = t.MaTran AND c.MaCauHoi = t.MaCauHoi
                      WHERE t.MaTran = n.MaTran AND t.MaND = n.MaND AND t.TrangThai IN ('Đã trả lời', 'Hết giờ')
                      ORDER BY c.ThuTu DESC LIMIT 1) AS GanNhat
               FROM NGUOICHOITRANDAU n JOIN NGUOIDUNG u ON u.MaND = n.MaND
              WHERE n.MaTran = ?"
        );
        $st->execute([$maTran]);
        $toi = null;
        $kia = null;
        foreach ($st->fetchAll() as $r) {
            $o = [
                'ten'     => $r['HoTen'],
                'diem'    => (int) $r['DiemSo'],
                'daXong'  => (int) $r['DaXong'],
                'xong'    => $r['TrangThaiThamGia'] !== 'Đang chơi',
                'ganNhat' => $r['GanNhat'],
            ];
            if ((int) $r['MaND'] === $maND) {
                $toi = $o;
            } else {
                $kia = $o;
            }
        }

        $cau = null;
        $demNguoc = 0;
        if ($tt === 'Đang diễn ra') {
            if ($daTroi !== null && $daTroi < self::DEM_NGUOC_GIAY) {
                $demNguoc = (int) ceil(self::DEM_NGUOC_GIAY - $daTroi);
            } elseif (($dong = self::dongDangLam($pdo, $maTran, $maND)) !== null) {
                $st = $pdo->prepare('SELECT NoiDung FROM CAUHOI WHERE MaCauHoi = ?');
                $st->execute([(int) $dong['MaCauHoi']]);
                $noiDung = (string) $st->fetchColumn();
                // Chỉ lấy mã và nội dung đáp án, không lấy LaDapAnDung
                $st = $pdo->prepare('SELECT MaDapAn, NoiDungDapAn FROM DAPAN WHERE MaCauHoi = ? ORDER BY MaDapAn');
                $st->execute([(int) $dong['MaCauHoi']]);
                $dapAn = [];
                foreach ($st->fetchAll() as $d) {
                    $dapAn[] = ['id' => (int) $d['MaDapAn'], 'noiDung' => $d['NoiDungDapAn']];
                }
                $gioiHan = (int) $dong['ThoiGianQuyDinh'];
                $cau = [
                    'maCau'    => (int) $dong['MaCauHoi'],
                    'thuTu'    => (int) $dong['ThuTu'],
                    'noiDung'  => $noiDung,
                    'dapAn'    => $dapAn,
                    'thoiGian' => $gioiHan,
                    'conLai'   => max(0.0, $gioiHan - (float) $dong['DaDung']),
                ];
            }
        }

        // Lấy thông tin Vòng Quay Đột Biến & Trạng thái Phá Rối đang hiệu lực
        $stTran = $pdo->prepare('SELECT DotBien, PhaRoi FROM TRANDAU WHERE MaTran = ?');
        $stTran->execute([$maTran]);
        $tranRow = $stTran->fetch() ?: [];
        $dotBienKey = (string) ($tranRow['DotBien'] ?? 'chuan');
        $dotBienInfo = DotBien::layDotBien($dotBienKey);

        $phaRoiBiDinh = null;
        if (!empty($tranRow['PhaRoi'])) {
            $pr = json_decode($tranRow['PhaRoi'], true);
            if (is_array($pr) && (int) ($pr['mucTieu'] ?? 0) === $maND) {
                $tgTroi = microtime(true) - (float) ($pr['thoiGian'] ?? 0);
                if ($tgTroi >= 0 && $tgTroi <= 4.0) {
                    $phaRoiBiDinh = [
                        'loai'     => $pr['loai'],
                        'conLai'   => max(0.1, round(4.0 - $tgTroi, 1)),
                        'nguoiBan' => (int) $pr['nguoiBan'],
                    ];
                }
            }
        }

        return [
            'tran'         => ['trangThai' => $tt, 'tongCau' => $tong],
            'toi'          => $toi,
            'doiThu'       => $kia,
            'demNguoc'     => $demNguoc,
            'cau'          => $cau,
            'dotBien'      => $dotBienInfo,
            'phaRoiBiDinh' => $phaRoiBiDinh,
        ];
    }

    // Tung chiêu Phá Rối / Sabotage nhắm vào đối thủ
    public static function phongChieuPhaRoi(PDO $pdo, int $maTran, int $maND, string $loai): array
    {
        $hopLe = ['loc_xoay', 'suong_mu', 'dong_bang'];
        if (!in_array($loai, $hopLe, true)) {
            return ['loi' => 'Chiêu phá rối không hợp lệ.'];
        }
        $pdo->beginTransaction();
        try {
            $tt = self::khoaTran($pdo, $maTran);
            if ($tt !== 'Đang diễn ra') {
                $pdo->commit();
                return ['loi' => 'Trận không còn diễn ra.'];
            }
            $st = $pdo->prepare('SELECT MaND FROM NGUOICHOITRANDAU WHERE MaTran = ? AND MaND <> ?');
            $st->execute([$maTran, $maND]);
            $mucTieu = (int) $st->fetchColumn();
            if (!$mucTieu) {
                $pdo->commit();
                return ['loi' => 'Không tìm thấy đối thủ.'];
            }
            $payload = json_encode([
                'nguoiBan' => $maND,
                'mucTieu'  => $mucTieu,
                'loai'     => $loai,
                'thoiGian' => microtime(true),
            ], JSON_UNESCAPED_UNICODE);
            $pdo->prepare('UPDATE TRANDAU SET PhaRoi = ? WHERE MaTran = ?')->execute([$payload, $maTran]);
            $pdo->commit();
            return ['thanhCong' => true, 'loai' => $loai];
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $ex;
        }
    }

    // Người chơi gửi đáp án (C-24, C-25, C-27, C-28). Trả về chỉ đúng/sai và điểm của câu vừa chốt.
    public static function traLoi(PDO $pdo, int $maTran, int $maND, int $maCau, int $maDapAn): array
    {
        $dungSai = max(0, Config::int('DUNG_SAI_DO_TRE', 1));
        $batDauTx = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $batDauTx = true;
        }
        try {
            if (self::khoaTran($pdo, $maTran) !== 'Đang diễn ra') {
                if ($batDauTx) $pdo->commit();
                return ['loi' => 'Trận không còn diễn ra.'];
            }
            $dong = self::dongDangLam($pdo, $maTran, $maND);
            if (!$dong || (int) $dong['MaCauHoi'] !== $maCau) {
                if ($batDauTx) $pdo->commit();
                return ['loi' => 'Câu hỏi này đã được chốt.'];
            }
            $gioiHan = (int) $dong['ThoiGianQuyDinh'];
            $daDung  = (float) $dong['DaDung'];

            // Đến muộn quá dung sai độ trễ hoặc gửi 0 (hết giờ): tính hết giờ, 0 điểm (bảo mật: không gửi đáp án đúng)
            if ($maDapAn === 0 || $daDung > $gioiHan + $dungSai) {
                self::chotHetGio($pdo, $maTran, $maND, $maCau, $gioiHan);
                self::capNhatDiem($pdo, $maTran, $maND);
                if ($batDauTx) $pdo->commit();
                return [
                    'hetGio' => true,
                    'dung'   => false,
                    'diem'   => 0,
                ];
            }

            // Đáp án phải thuộc đúng câu này
            $st = $pdo->prepare('SELECT LaDapAnDung FROM DAPAN WHERE MaDapAn = ? AND MaCauHoi = ?');
            $st->execute([$maDapAn, $maCau]);
            $la = $st->fetchColumn();
            if ($la === false) {
                if ($batDauTx) $pdo->commit();
                return ['loi' => 'Đáp án không hợp lệ.'];
            }

            // Kiểm tra Đột Biến hiện tại của trận đấu
            $stDB = $pdo->prepare('SELECT DotBien FROM TRANDAU WHERE MaTran = ?');
            $stDB->execute([$maTran]);
            $dotBienKey = (string) ($stDB->fetchColumn() ?: 'chuan');

            $conLai = max(0.0, $gioiHan - $daDung);
            $dung   = ((int) $la === 1);

            // C-28: đúng được điểm cơ bản + hệ số thưởng x (thời gian còn lại / thời gian câu), làm tròn xuống
            $diem = $dung ? (int) floor((int) $dong['DiemCoBan'] + (int) $dong['HeSoThuong'] * $conLai / $gioiHan) : 0;

            $pdo->prepare(
                "UPDATE CHITIETTRALOI
                    SET MaDapAn = ?, ThoiGianTraLoi = ?, DiemNhanDuoc = ?, TrangThai = 'Đã trả lời', ThoiDiemGui = NOW(3)
                  WHERE MaTran = ? AND MaND = ? AND MaCauHoi = ? AND TrangThai = 'Không làm'"
            )->execute([$maDapAn, round($daDung, 3), $diem, $maTran, $maND, $maCau]);
            self::capNhatDiem($pdo, $maTran, $maND);
            if ($batDauTx) $pdo->commit();
            return [
                'hetGio' => false,
                'dung'   => $dung,
                'diem'   => $diem,
            ];
        } catch (Throwable $ex) {
            if ($batDauTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $ex;
        }
    }

    // Người chơi chủ động chịu thua: người bấm thua, đối thủ thắng (xử lý như xử thua do bỏ trận, vẫn tính Elo).
    // Chỉ làm được khi trận đang diễn ra và người này chưa hoàn thành mọi câu.
    public static function chiuThua(PDO $pdo, int $maTran, int $maND): array
    {
        $pdo->beginTransaction();
        try {
            if (self::khoaTran($pdo, $maTran) !== 'Đang diễn ra') {
                $pdo->commit();
                return ['loi' => 'Trận không còn diễn ra.'];
            }
            // Nếu đã có người bị xử thua do mất kết nối thì để kết quả đó, không ghi đè
            self::kiemTraBoTran($pdo, $maTran);
            if (self::khoaTran($pdo, $maTran) !== 'Đang diễn ra') {
                $pdo->commit();
                return ['ketThuc' => true];
            }
            $st = $pdo->prepare('SELECT TrangThaiThamGia FROM NGUOICHOITRANDAU WHERE MaTran = ? AND MaND = ? FOR UPDATE');
            $st->execute([$maTran, $maND]);
            if ($st->fetchColumn() !== 'Đang chơi') {
                $pdo->commit();
                return ['loi' => 'Bạn đã hoàn thành mọi câu nên không thể chịu thua.'];
            }
            self::chotVaTaoBu($pdo, $maTran);
            self::capNhatDiemCaHai($pdo, $maTran);
            $pdo->prepare("UPDATE NGUOICHOITRANDAU SET TrangThaiThamGia = 'Bỏ trận', KetQua = 'Thua' WHERE MaTran = ? AND MaND = ?")
                ->execute([$maTran, $maND]);
            $pdo->prepare("UPDATE NGUOICHOITRANDAU SET TrangThaiThamGia = 'Đã xong', KetQua = 'Thắng' WHERE MaTran = ? AND MaND <> ?")
                ->execute([$maTran, $maND]);
            $pdo->prepare("UPDATE TRANDAU SET TrangThai = 'Kết thúc', LyDoKetThuc = 'Chịu thua', ThoiGianKetThuc = NOW(3) WHERE MaTran = ?")
                ->execute([$maTran]);
            Elo::capNhatSauTran($pdo, $maTran);
            self::congExpSauTran($pdo, $maTran);
            $pdo->commit();
            return ['ketThuc' => true];
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $ex;
        }
    }

    // A-26: Admin hủy trận chưa kết thúc (Đã ghép hoặc Đang diễn ra). Trả về false nếu trận đã kết thúc/hủy.
    // Gọi BÊN TRONG giao dịch của người gọi để việc hủy và ghi nhật ký quản trị nằm chung một giao dịch.
    // Khóa dòng TRANDAU trước (cùng thứ tự khóa như mọi thao tác khác) nên không xung đột với việc trận vừa kết thúc.
    // Không gọi Elo::capNhatSauTran: trận Hủy không đổi Elo.
    public static function huyBoiQuanTri(PDO $pdo, int $maTran): bool
    {
        $tt = self::khoaTran($pdo, $maTran);
        if ($tt !== 'Đã ghép' && $tt !== 'Đang diễn ra') {
            return false;
        }
        self::chotVaTaoBu($pdo, $maTran);
        self::capNhatDiemCaHai($pdo, $maTran);
        $pdo->prepare("UPDATE TRANDAU SET TrangThai = 'Hủy', LyDoKetThuc = 'Quản trị viên hủy', ThoiGianKetThuc = NOW(3) WHERE MaTran = ?")
            ->execute([$maTran]);
        return true;
    }

    // Tự động xử lý lượt chơi, tiến trình và đáp án của Đấu Thần Máy (Bot AI)
    public static function xuLyBot(PDO $pdo, int $maTran, int $botId, ?float $daTroi): void
    {
        // 1. Luôn cập nhật hoạt động của Bot để không bị xử thua do bỏ trận
        $pdo->prepare("UPDATE NGUOICHOITRANDAU SET LanHoatDongCuoi = NOW(3) WHERE MaTran = ? AND MaND = ? AND TrangThaiThamGia = 'Đang chơi'")
            ->execute([$maTran, $botId]);

        // 2. Nếu qua thời gian đếm ngược, đẩy tiến trình câu hỏi cho Bot
        if ($daTroi === null || $daTroi < self::DEM_NGUOC_GIAY) {
            return;
        }

        self::tienTrinh($pdo, $maTran, $botId);

        // 3. Kiểm tra câu Bot đang làm dở
        $dong = self::dongDangLam($pdo, $maTran, $botId);
        if (!$dong) {
            return;
        }

        // Thời gian suy nghĩ của Bot: 3.2s đến 5.8s. Nếu bị Phá Rối (đóng băng/mù), Bot bị trễ thêm 2.5s
        $daDung = (float) $dong['DaDung'];
        $stPR = $pdo->prepare('SELECT PhaRoi, DotBien FROM TRANDAU WHERE MaTran = ?');
        $stPR->execute([$maTran]);
        $rowTran = $stPR->fetch() ?: [];
        $dotBienKey = (string) ($rowTran['DotBien'] ?? 'chuan');
        $pr = !empty($rowTran['PhaRoi']) ? json_decode($rowTran['PhaRoi'], true) : null;
        $botBiPhaRoi = false;
        if (is_array($pr) && (int) ($pr['mucTieu'] ?? 0) === $botId) {
            $tgTroi = microtime(true) - (float) ($pr['thoiGian'] ?? 0);
            if ($tgTroi >= 0 && $tgTroi <= 4.0) {
                $botBiPhaRoi = true;
            }
        }

        $tgNghi = 3.2 + ((((int) $dong['MaCauHoi'] * 7 + $maTran * 3) % 25) / 10.0) + ($botBiPhaRoi ? 2.5 : 0.0);

        if ($daDung >= $tgNghi) {
            $maCau = (int) $dong['MaCauHoi'];
            // Tìm đáp án đúng
            $stD = $pdo->prepare('SELECT MaDapAn FROM DAPAN WHERE MaCauHoi = ? AND LaDapAnDung = 1 LIMIT 1');
            $stD->execute([$maCau]);
            $dapAnDung = (int) $stD->fetchColumn();

            // Tỉ lệ phản ứng thông minh của Bot khoảng 75%
            $seed = ((int) $dong['MaCauHoi'] * 13 + $maTran * 17) % 100;
            if ($seed < 75 && $dapAnDung > 0) {
                $dapAnChon = $dapAnDung;
            } else {
                // Chọn một đáp án sai ngẫu nhiên
                $stS = $pdo->prepare('SELECT MaDapAn FROM DAPAN WHERE MaCauHoi = ? AND LaDapAnDung = 0 ORDER BY RAND() LIMIT 1');
                $stS->execute([$maCau]);
                $dapAnChon = (int) $stS->fetchColumn() ?: $dapAnDung;
            }

            self::traLoi($pdo, $maTran, $botId, $maCau, $dapAnChon);
            self::capNhatDiem($pdo, $maTran, $botId);
            self::tienTrinh($pdo, $maTran, $botId);
        }
    }
}