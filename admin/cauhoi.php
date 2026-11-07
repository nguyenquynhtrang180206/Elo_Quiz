<?php
// Ngân hàng câu hỏi: thẻ số liệu, bản đồ phủ câu hỏi, hiệu chỉnh độ khó bằng dữ liệu thật,
// danh sách, lọc, ẩn/hiện một hoặc nhiều câu, xem nhanh (UC13: A-17, A-20, A-40).
// Đặt tại: elo_quiz/admin/cauhoi.php
// Thêm/sửa câu hỏi nằm ở cauhoi_form.php. Chi tiết một câu (ngăn "Xem nhanh") lấy qua cauhoi_api.php.
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/CauHoiHelper.php';
Auth::requireAdmin(BASE_URL . '/admin/login.php');   // A-04

$pdo = Database::pdo();

// ---- Ngưỡng dùng cho bản đồ phủ và hiệu chỉnh độ khó (đổi tại đây nếu cần) ----
$MIN_LUOT_MAC_DINH = 2;   // giá trị mặc định khi chưa có cấu hình
$MIN_LUOT_TOI_DA   = 200; // admin chỉnh được từ 1 đến ngần này (ô nhập ở thẻ hiệu chỉnh độ khó)
// Câu có ít hơn ngần này lượt trả lời thì chưa đủ dữ liệu để đánh giá độ khó; admin tự chỉnh, lưu trong CAUHINH (MIN_LUOT_DO_KHO)
$MIN_LUOT  = min($MIN_LUOT_TOI_DA, max(1, Config::int('MIN_LUOT_DO_KHO', $MIN_LUOT_MAC_DINH)));
$NGUONG_DE = 70;   // tỉ lệ đúng >= 70%  -> Dễ
$NGUONG_KHO = 40;  // tỉ lệ đúng < 40%   -> Khó; ở giữa là Trung bình
$PHU_DU    = 20;   // mỗi môn cần tối thiểu ngần này câu hiển thị cho mỗi mức độ khó
$PHU_TOT   = 40;   // từ mức này trở lên là ô xanh đậm
$NGUONG_DO = min($PHU_DU, max(1, Config::int('SO_CAU', 10)));   // dưới ngưỡng này (mặc định = số câu mỗi trận) là ô đỏ
$SO_MON_BANDO = 6; // số môn mỗi trang của bản đồ phủ

// Mức độ khó theo tỉ lệ trả lời đúng thực tế
$hangTheoTyLe = static function (int $dung, int $luot) use ($NGUONG_DE, $NGUONG_KHO): string {
    if ($dung * 100 >= $NGUONG_DE * $luot) { return 'Dễ'; }
    if ($dung * 100 < $NGUONG_KHO * $luot) { return 'Khó'; }
    return 'Trung bình';
};

// Xóa hẳn một câu hỏi, kể cả câu đã dùng trong trận: xóa luôn bài làm (CHITIETTRALOI), dòng câu trong trận
// (CAUHOITRANDAU) và đáp án. Điểm tổng, kết quả trận và lịch sử Elo đã lưu KHÔNG bị tính lại.
// Câu đang nằm trong trận chưa kết thúc thì không xóa (sẽ làm hỏng trận đang chơi).
// Gọi trong giao dịch của nơi gọi. Trả về [trạng thái 'ok'|'khoa'|'khong', số trận bị ảnh hưởng, nội dung câu].
$xoaMotCau = static function (PDO $pdo, int $ma): array {
    $st = $pdo->prepare('SELECT NoiDung FROM CAUHOI WHERE MaCauHoi = ? FOR UPDATE');
    $st->execute([$ma]);
    $nd = $st->fetchColumn();
    if ($nd === false) { return ['khong', 0, '']; }
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM CAUHOITRANDAU x JOIN TRANDAU t ON t.MaTran = x.MaTran
         WHERE x.MaCauHoi = ? AND t.TrangThai IN ('Đã ghép', 'Đang diễn ra')"
    );
    $st->execute([$ma]);
    if ((int) $st->fetchColumn() > 0) { return ['khoa', 0, (string) $nd]; }
    $st = $pdo->prepare('SELECT COUNT(DISTINCT MaTran) FROM CAUHOITRANDAU WHERE MaCauHoi = ?');
    $st->execute([$ma]);
    $soTran = (int) $st->fetchColumn();
    $pdo->prepare('DELETE FROM CHITIETTRALOI WHERE MaCauHoi = ?')->execute([$ma]);
    $pdo->prepare('DELETE FROM CAUHOITRANDAU WHERE MaCauHoi = ?')->execute([$ma]);
    $pdo->prepare('DELETE FROM DAPAN WHERE MaCauHoi = ?')->execute([$ma]);
    $pdo->prepare('DELETE FROM CAUHOI WHERE MaCauHoi = ?')->execute([$ma]);
    return ['ok', $soTran, (string) $nd];
};

// ---- A-20: ẩn / hiện câu hỏi (kèm nhật ký A-40 trong cùng giao dịch) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $hd = (string) ($_POST['hanh_dong'] ?? '');

    if (($hd === 'an' || $hd === 'hien') && (int) ($_POST['id'] ?? 0) > 0) {
        $id = (int) $_POST['id'];
        $an = $hd === 'an';
        $pdo->beginTransaction();
        try {
            // Điều kiện theo trạng thái cũ: bấm lặp hoặc hai admin cùng bấm thì chỉ ghi nhật ký một lần
            $up = $pdo->prepare('UPDATE CAUHOI SET TrangThai = ? WHERE MaCauHoi = ? AND TrangThai = ?');
            $up->execute([$an ? 'Đã ẩn' : 'Hiển thị', $id, $an ? 'Hiển thị' : 'Đã ẩn']);
            if ($up->rowCount() === 1) {
                NhatKy::ghi($an ? 'Ẩn câu hỏi' : 'Hiện lại câu hỏi', 'CAUHOI #' . $id);
                $_SESSION['ch_flash'] = ['ok', $an ? 'Đã ẩn câu hỏi #' . $id . '.' : 'Đã hiện lại câu hỏi #' . $id . '.'];
            } else {
                $_SESSION['ch_flash'] = ['loi', 'Câu hỏi đã ở trạng thái này rồi hoặc không tồn tại.'];
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }
    } elseif ($hd === 'doi_do_kho' && (int) ($_POST['id'] ?? 0) > 0) {
        // Đổi nhãn độ khó theo dữ liệu thật (nút "→ Dễ / Trung bình / Khó" ở thẻ hiệu chỉnh).
        // Số liệu được tính lại ngay trong giao dịch: chỉ đổi khi nhãn mới đúng bằng nhãn mà dữ liệu hiện tại gợi ý.
        $id  = (int) $_POST['id'];
        $moi = (string) ($_POST['dokho_moi'] ?? '');
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT DoKho FROM CAUHOI WHERE MaCauHoi = ? FOR UPDATE');
            $st->execute([$id]);
            $cu = $st->fetchColumn();
            if ($cu === false || !in_array($moi, CauHoiHelper::DO_KHO, true)) {
                $_SESSION['ch_flash'] = ['loi', 'Câu hỏi không tồn tại hoặc độ khó mới không hợp lệ.'];
            } else {
                // Cùng quy tắc với cauhoi_form.php: câu đang nằm trong trận chưa kết thúc thì tạm khóa
                $st = $pdo->prepare(
                    "SELECT COUNT(*) FROM CAUHOITRANDAU x JOIN TRANDAU t ON t.MaTran = x.MaTran
                     WHERE x.MaCauHoi = ? AND t.TrangThai IN ('Đã ghép', 'Đang diễn ra')"
                );
                $st->execute([$id]);
                if ((int) $st->fetchColumn() > 0) {
                    $_SESSION['ch_flash'] = ['loi', 'Câu hỏi #' . $id . ' đang nằm trong trận chưa kết thúc nên tạm thời chưa đổi được độ khó.'];
                } else {
                    $st = $pdo->prepare(
                        "SELECT COUNT(*) AS luot, COALESCE(SUM(d.LaDapAnDung = 1), 0) AS dung
                         FROM CHITIETTRALOI t LEFT JOIN DAPAN d ON d.MaDapAn = t.MaDapAn
                         WHERE t.MaCauHoi = ? AND t.TrangThai IN ('Đã trả lời', 'Hết giờ')"
                    );
                    $st->execute([$id]);
                    $so   = $st->fetch();
                    $luot = (int) $so['luot'];
                    $dung = (int) $so['dung'];
                    if ($luot < $MIN_LUOT || $cu === $moi || $hangTheoTyLe($dung, $luot) !== $moi) {
                        $_SESSION['ch_flash'] = ['loi', 'Số liệu của câu #' . $id . ' đã thay đổi nên gợi ý này không còn phù hợp. Hãy xem lại.'];
                    } else {
                        $pdo->prepare('UPDATE CAUHOI SET DoKho = ? WHERE MaCauHoi = ?')->execute([$moi, $id]);
                        NhatKy::ghi('Đổi độ khó câu hỏi', 'CAUHOI #' . $id,
                            'Độ khó ' . $cu . ' → ' . $moi . ' theo dữ liệu thật: đúng ' . round($dung * 100 / $luot) . '% trên ' . $luot . ' lượt trả lời.');
                        $_SESSION['ch_flash'] = ['ok', 'Đã đổi độ khó câu hỏi #' . $id . ' từ ' . $cu . ' sang ' . $moi . '.'];
                    }
                }
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $ex;
        }
    } elseif ($hd === 'doi_min_luot') {
        // Admin tự chỉnh số lượt trả lời tối thiểu để một câu được xét độ khó (lưu bền trong CAUHINH, có ghi nhật ký).
        $v = trim((string) ($_POST['min_luot'] ?? ''));
        if ($v === '' || !ctype_digit($v) || strlen($v) > 4 || (int) $v < 1 || (int) $v > $MIN_LUOT_TOI_DA) {
            $_SESSION['ch_flash'] = ['loi', 'Số lượt tối thiểu phải là số nguyên từ 1 đến ' . $MIN_LUOT_TOI_DA . '.'];
        } elseif ((int) $v === $MIN_LUOT) {
            $_SESSION['ch_flash'] = ['ok', 'Số lượt tối thiểu vẫn là ' . $MIN_LUOT . ', không có gì thay đổi.'];
        } else {
            $moi = (int) $v;
            $pdo->beginTransaction();
            try {
                $pdo->prepare(
                    'INSERT INTO CAUHINH (TenCauHinh, GiaTri, MoTa) VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE GiaTri = VALUES(GiaTri)'
                )->execute(['MIN_LUOT_DO_KHO', (string) $moi, 'Số lượt trả lời tối thiểu để một câu hỏi được xét độ khó theo dữ liệu thật (Ngân hàng câu hỏi)']);
                NhatKy::ghi('Đổi tham số hiệu chỉnh độ khó', 'CAUHINH MIN_LUOT_DO_KHO', 'Số lượt tối thiểu ' . $MIN_LUOT . ' → ' . $moi . '.');
                $pdo->commit();
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                throw $ex;
            }
            Config::clearCache();
            $_SESSION['ch_flash'] = ['ok', 'Đã đổi số lượt tối thiểu từ ' . $MIN_LUOT . ' thành ' . $moi . '. Biểu đồ và danh sách lệch độ khó đã tính lại.'];
        }
    } elseif ($hd === 'xoa' && (int) ($_POST['id'] ?? 0) > 0) {
        $id = (int) $_POST['id'];
        $pdo->beginTransaction();
        try {
            [$kq, $soTran, $nd] = $xoaMotCau($pdo, $id);
            if ($kq === 'khong') {
                $_SESSION['ch_flash'] = ['loi', 'Câu hỏi không tồn tại hoặc đã bị xóa.'];
            } elseif ($kq === 'khoa') {
                $_SESSION['ch_flash'] = ['loi', 'Câu hỏi #' . $id . ' đang nằm trong trận chưa kết thúc nên tạm thời chưa xóa được. Hãy chờ trận kết thúc.'];
            } else {
                // Câu đã mất khỏi CSDL nên chép nội dung vào nhật ký để còn truy vết (A-40)
                NhatKy::ghi('Xóa câu hỏi', 'CAUHOI #' . $id,
                    'Nội dung: ' . mb_substr($nd, 0, 300) . ($soTran > 0 ? '. Đã dùng trong ' . $soTran . ' trận, bài làm và dòng câu trong các trận đó cũng bị xóa.' : ''));
                $_SESSION['ch_flash'] = ['ok', 'Đã xóa câu hỏi #' . $id . '.' . ($soTran > 0 ? ' (kèm dữ liệu bài làm trong ' . $soTran . ' trận)' : '')];
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $_SESSION['ch_flash'] = ['loi', 'Không xóa được câu hỏi #' . $id . ' vì lỗi cơ sở dữ liệu. Hãy thử lại hoặc ẩn câu hỏi.'];
        }
    } elseif ($hd === 'xoa_nhieu' || $hd === 'xoa_mon') {
        // Xóa hàng loạt (các câu đã chọn, hoặc mọi câu của một môn). Mọi câu đều xóa được, kể cả câu đã dùng,
        // trừ câu đang nằm trong trận chưa kết thúc (được bỏ qua và báo lại).
        $maMonXoa = (int) ($_POST['mon_xoa'] ?? 0);
        $ids = [];
        if ($hd === 'xoa_nhieu') {
            $ids = array_filter((array) ($_POST['ids'] ?? []), 'is_scalar');
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $x): bool => $x > 0)));
            $ids = array_slice($ids, 0, 100);
        }
        if ($hd === 'xoa_nhieu' && !$ids) {
            $_SESSION['ch_flash'] = ['loi', 'Chưa chọn câu hỏi nào.'];
        } elseif ($hd === 'xoa_mon' && $maMonXoa <= 0) {
            $_SESSION['ch_flash'] = ['loi', 'Chưa chọn môn học.'];
        } else {
            $pdo->beginTransaction();
            try {
                if ($hd === 'xoa_mon') {
                    $st = $pdo->prepare('SELECT MaCauHoi FROM CAUHOI WHERE MaMon = ?');
                    $st->execute([$maMonXoa]);
                    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
                }
                $xoaDuoc = [];
                $biKhoa  = 0;
                $tranAnhHuong = 0;
                foreach ($ids as $ma) {
                    [$kq, $soTran] = $xoaMotCau($pdo, $ma);
                    if ($kq === 'ok') { $xoaDuoc[] = $ma; $tranAnhHuong += $soTran; }
                    elseif ($kq === 'khoa') { $biKhoa++; }
                }
                if ($xoaDuoc) {
                    $doiTuong = $hd === 'xoa_mon' ? 'MONHOC #' . $maMonXoa . ' (' . count($xoaDuoc) . ' câu)' : 'CAUHOI (' . count($xoaDuoc) . ' câu)';
                    NhatKy::ghi('Xóa câu hỏi', $doiTuong, mb_substr('Mã: #' . implode(', #', $xoaDuoc), 0, 400)
                        . ($tranAnhHuong > 0 ? '. Kèm dữ liệu bài làm của ' . $tranAnhHuong . ' lượt câu-trận.' : ''));
                    $_SESSION['ch_flash'] = ['ok', 'Đã xóa ' . count($xoaDuoc) . ' câu hỏi.'
                        . ($biKhoa ? ' ' . $biKhoa . ' câu đang nằm trong trận chưa kết thúc nên được giữ lại.' : '')];
                } else {
                    $_SESSION['ch_flash'] = ['loi', $biKhoa
                        ? 'Không xóa được câu nào: ' . $biKhoa . ' câu đang nằm trong trận chưa kết thúc.'
                        : 'Không có câu hỏi nào để xóa.'];
                }
                $pdo->commit();
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $_SESSION['ch_flash'] = ['loi', 'Không xóa được vì lỗi cơ sở dữ liệu, chưa có câu nào bị xóa. Hãy thử lại.'];
            }
        }
    } elseif ($hd === 'an_nhieu' || $hd === 'hien_nhieu') {
        // Thao tác nhiều câu cùng lúc (thanh "Đã chọn N câu hỏi"): tối đa 100 câu mỗi lần
        $an  = $hd === 'an_nhieu';
        $ids = array_filter((array) ($_POST['ids'] ?? []), 'is_scalar');
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $x): bool => $x > 0)));
        $ids = array_slice($ids, 0, 100);

        if (!$ids) {
            $_SESSION['ch_flash'] = ['loi', 'Chưa chọn câu hỏi nào.'];
        } else {
            $pdo->beginTransaction();
            try {
                $up = $pdo->prepare('UPDATE CAUHOI SET TrangThai = ? WHERE MaCauHoi = ? AND TrangThai = ?');
                $doi = [];
                foreach ($ids as $ma) {
                    $up->execute([$an ? 'Đã ẩn' : 'Hiển thị', $ma, $an ? 'Hiển thị' : 'Đã ẩn']);
                    if ($up->rowCount() === 1) {
                        $doi[] = $ma;
                    }
                }
                if ($doi) {
                    // Một dòng nhật ký cho cả lượt: số câu ở DoiTuong, danh sách mã ở MoTa
                    NhatKy::ghi($an ? 'Ẩn câu hỏi' : 'Hiện lại câu hỏi', 'CAUHOI (' . count($doi) . ' câu)', 'Mã: #' . implode(', #', $doi));
                    $_SESSION['ch_flash'] = ['ok', ($an ? 'Đã ẩn ' : 'Đã hiện lại ') . count($doi) . ' câu hỏi.'
                        . (count($doi) < count($ids) ? ' ' . (count($ids) - count($doi)) . ' câu đã ở trạng thái này nên được bỏ qua.' : '')];
                } else {
                    $_SESSION['ch_flash'] = ['loi', 'Các câu đã chọn đều đã ở trạng thái này rồi.'];
                }
                $pdo->commit();
            } catch (Throwable $ex) {
                $pdo->rollBack();
                throw $ex;
            }
        }
    }
    redirect((string) $_SERVER['REQUEST_URI']);   // ở lại đúng trang, giữ nguyên bộ lọc
}

// Địa chỉ hiện tại (bộ lọc, trang danh sách, trang bản đồ) gửi kèm sang form để lưu xong quay về đúng chỗ cũ
$veQuery = rawurlencode((string) ($_SERVER['QUERY_STRING'] ?? ''));
$flash = $_SESSION['ch_flash'] ?? null;
unset($_SESSION['ch_flash']);

// ---- Mảnh SQL dùng chung ----
// tl: mỗi câu có bao nhiêu lượt trả lời (đã trả lời hoặc hết giờ) và bao nhiêu lượt đúng. Hết giờ tính là sai, "Không làm" bỏ qua.
$tlSub = "(SELECT t.MaCauHoi, COUNT(*) AS luot, SUM(d.LaDapAnDung = 1) AS dung
           FROM CHITIETTRALOI t LEFT JOIN DAPAN d ON d.MaDapAn = t.MaDapAn
           WHERE t.TrangThai IN ('Đã trả lời', 'Hết giờ') GROUP BY t.MaCauHoi)";
// u: các câu đã từng nằm trong một trận nào đó
$dungSub = '(SELECT DISTINCT MaCauHoi FROM CAUHOITRANDAU)';
$joinTL  = "LEFT JOIN $tlSub tl ON tl.MaCauHoi = c.MaCauHoi LEFT JOIN $dungSub u ON u.MaCauHoi = c.MaCauHoi";
// Câu "lệch độ khó": đủ lượt trả lời mà tỉ lệ đúng không nằm trong khoảng của nhãn đang gắn
$sqlLech = "(tl.luot >= $MIN_LUOT AND (
              (c.DoKho = 'Dễ' AND tl.dung * 100 < $NGUONG_DE * tl.luot)
           OR (c.DoKho = 'Khó' AND tl.dung * 100 >= $NGUONG_KHO * tl.luot)
           OR (c.DoKho = 'Trung bình' AND (tl.dung * 100 >= $NGUONG_DE * tl.luot OR tl.dung * 100 < $NGUONG_KHO * tl.luot))))";
// Mức lệch (điểm phần trăm so với khoảng đúng của nhãn): dùng để xếp câu lệch nhiều nhất lên đầu
$sqlMucLech = "CASE c.DoKho
                 WHEN 'Dễ'  THEN $NGUONG_DE - tl.dung * 100 / tl.luot
                 WHEN 'Khó' THEN tl.dung * 100 / tl.luot - $NGUONG_KHO
                 ELSE CASE WHEN tl.dung * 100 >= $NGUONG_DE * tl.luot
                           THEN tl.dung * 100 / tl.luot - $NGUONG_DE
                           ELSE $NGUONG_KHO - tl.dung * 100 / tl.luot END
               END";

// ---- Bốn thẻ số liệu đầu trang (toàn ngân hàng, không phụ thuộc bộ lọc) ----
$toanCuc = $pdo->query(
    "SELECT COUNT(*) AS tong,
            COALESCE(SUM(c.TrangThai = 'Đã ẩn'), 0) AS an,
            COALESCE(SUM($sqlLech), 0) AS lech
     FROM CAUHOI c LEFT JOIN $tlSub tl ON tl.MaCauHoi = c.MaCauHoi"
)->fetch();
$tongCau  = (int) $toanCuc['tong'];
$tongAn   = (int) $toanCuc['an'];
$tongLech = (int) $toanCuc['lech'];

// ---- Bản đồ phủ câu hỏi: số câu đang hiển thị của từng môn đang mở theo từng mức độ khó ----
$bando = $pdo->query(
    "SELECT m.MaMon, m.TenMon,
            COALESCE(SUM(c.DoKho = 'Dễ'), 0)         AS de,
            COALESCE(SUM(c.DoKho = 'Trung bình'), 0) AS tb,
            COALESCE(SUM(c.DoKho = 'Khó'), 0)        AS kho
     FROM MONHOC m
     LEFT JOIN CAUHOI c ON c.MaMon = m.MaMon AND c.TrangThai = 'Hiển thị'
     WHERE m.TrangThai = 'Đang mở'
     GROUP BY m.MaMon, m.TenMon
     ORDER BY m.TenMon"
)->fetchAll();
$oThieu = 0;
foreach ($bando as $i => $m) {
    $thieu = 0;
    foreach (['de', 'tb', 'kho'] as $k) {
        $bando[$i][$k] = (int) $m[$k];
        $thieu += max(0, $PHU_DU - $bando[$i][$k]);
        if ($bando[$i][$k] < $NGUONG_DO) { $oThieu++; }
    }
    $bando[$i]['thieu'] = $thieu;
}
usort($bando, static fn (array $a, array $b): int => $b['thieu'] <=> $a['thieu']);   // môn thiếu nhiều nhất lên đầu (giữ thứ tự tên khi bằng nhau)
$pBando = new Paginator(count($bando), $SO_MON_BANDO, 'bd');
$bandoTrang = array_slice($bando, $pBando->offset(), $pBando->limit());

// ---- Biểu đồ phân bố tỉ lệ đúng: 10 khoảng 0-10%, ..., 90-100% (chỉ câu đủ lượt trả lời; theo môn đang lọc nếu có) ----
$monBieuDo = (int) ($_GET['mon'] ?? 0);
$st = $pdo->prepare(
    "SELECT LEAST(FLOOR(tl.dung * 100 / tl.luot / 10), 9) AS khoang, COUNT(*) AS so
     FROM CAUHOI c JOIN $tlSub tl ON tl.MaCauHoi = c.MaCauHoi
     WHERE tl.luot >= $MIN_LUOT" . ($monBieuDo > 0 ? ' AND c.MaMon = ?' : '') . '
     GROUP BY khoang'
);
$st->execute($monBieuDo > 0 ? [$monBieuDo] : []);
$phanBo = array_fill(0, 10, 0);
foreach ($st->fetchAll() as $r) { $phanBo[(int) $r['khoang']] = (int) $r['so']; }
$tongDuDL = array_sum($phanBo);
$bieuDo = ['nhan' => [], 'so' => [], 'mau' => []];
for ($i = 0; $i < 10; $i++) {
    $bieuDo['nhan'][] = ($i * 10) . '–' . ($i * 10 + 10) . '%';
    $bieuDo['so'][]   = $phanBo[$i];
    $bieuDo['mau'][]  = $i * 10 >= $NGUONG_DE ? '#22a06b' : ($i * 10 + 10 <= $NGUONG_KHO ? '#ef4444' : '#e3a93b');
}

// ---- A-17: bộ lọc ----
$q    = trim((string) ($_GET['q'] ?? ''));
$mon  = (int) ($_GET['mon'] ?? 0);
$kt   = (string) ($_GET['kt'] ?? '');          // '' = mọi loại, 'chung' = dùng cho cả hai kỳ, số = mã loại
$dk   = (string) ($_GET['dk'] ?? '');
$tt   = (string) ($_GET['tt'] ?? '');
$loc  = (string) ($_GET['loc'] ?? '');         // '' | 'lech' (lệch độ khó) | 'chua' (chưa từng dùng trong trận)
if (!in_array($dk, CauHoiHelper::DO_KHO, true)) { $dk = ''; }
if (!in_array($tt, ['Hiển thị', 'Đã ẩn'], true)) { $tt = ''; }
if (!in_array($loc, ['lech', 'chua'], true)) { $loc = ''; }
if ($kt !== 'chung' && !ctype_digit($kt)) { $kt = ''; }

$dsMon = $pdo->query("SELECT MaMon, TenMon FROM MONHOC WHERE TrangThai IN ('Đang mở','Đã ẩn') ORDER BY TenMon")->fetchAll();
$dsKT  = $pdo->query('SELECT MaLoaiKT, TenLoaiKT FROM LOAIKYTHI ORDER BY MaLoaiKT')->fetchAll();

// Điều kiện "cơ sở" (tìm kiếm, môn, loại kỳ thi): dùng cho cả danh sách lẫn số đếm trên các chip
$dieuKien = [];
$tham     = [];
if ($q !== '') {
    $like = '%' . addcslashes($q, '\\%_') . '%';
    if (preg_match('/^#?(\d{1,9})$/', $q, $mm)) {   // gõ "127" hoặc "#127" thì khớp cả mã câu hỏi
        $dieuKien[] = '(c.NoiDung LIKE ? OR c.MaCauHoi = ?)';
        $tham[]     = $like;
        $tham[]     = (int) $mm[1];
    } else {
        $dieuKien[] = 'c.NoiDung LIKE ?';
        $tham[]     = $like;
    }
}
if ($mon > 0)        { $dieuKien[] = 'c.MaMon = ?';        $tham[] = $mon; }
if ($kt === 'chung') { $dieuKien[] = 'c.MaLoaiKT IS NULL'; }
elseif ($kt !== '')  { $dieuKien[] = 'c.MaLoaiKT = ?';     $tham[] = (int) $kt; }
$whereCoSo = $dieuKien ? 'WHERE ' . implode(' AND ', $dieuKien) : '';
$thamCoSo  = $tham;

// Số đếm trên chip: Dễ + Trung bình + Khó = Tất cả; "Đã ẩn", "Lệch độ khó", "Chưa từng dùng" là số riêng nằm trong các số đó
$st = $pdo->prepare(
    "SELECT COUNT(*) AS tong,
            COALESCE(SUM(c.DoKho = 'Dễ'), 0)         AS de,
            COALESCE(SUM(c.DoKho = 'Trung bình'), 0) AS tb,
            COALESCE(SUM(c.DoKho = 'Khó'), 0)        AS kho,
            COALESCE(SUM(c.TrangThai = 'Đã ẩn'), 0)  AS an,
            COALESCE(SUM($sqlLech), 0)               AS lech,
            COALESCE(SUM(u.MaCauHoi IS NULL), 0)     AS chua
     FROM CAUHOI c $joinTL $whereCoSo"
);
$st->execute($thamCoSo);
$dem = array_map('intval', $st->fetch());

// Thêm độ khó, trạng thái và chip đặc biệt cho danh sách
if ($dk !== '')       { $dieuKien[] = 'c.DoKho = ?';     $tham[] = $dk; }
if ($tt !== '')       { $dieuKien[] = 'c.TrangThai = ?'; $tham[] = $tt; }
if ($loc === 'lech')  { $dieuKien[] = $sqlLech; }
if ($loc === 'chua')  { $dieuKien[] = 'u.MaCauHoi IS NULL'; }
$where = $dieuKien ? 'WHERE ' . implode(' AND ', $dieuKien) : '';

$st = $pdo->prepare("SELECT COUNT(*) FROM CAUHOI c $joinTL $where");
$st->execute($tham);
$p = new Paginator((int) $st->fetchColumn(), 5);

// Số câu chưa dùng trong trận của môn đang lọc (cho nút "Xóa câu chưa dùng của môn này")
$soXoaDuocMon = 0;
$soCauMon = 0;
if ($mon > 0) {
    $st = $pdo->prepare('SELECT COUNT(*), COALESCE(SUM(u.MaCauHoi IS NULL), 0) FROM CAUHOI c LEFT JOIN ' . $dungSub . ' u ON u.MaCauHoi = c.MaCauHoi WHERE c.MaMon = ?');
    $st->execute([$mon]);
    [$soCauMon, $soXoaDuocMon] = array_map('intval', $st->fetch(PDO::FETCH_NUM));
}

$thuTu = $loc === 'lech' ? "($sqlMucLech) DESC, c.MaCauHoi DESC" : 'c.MaCauHoi DESC';
$st = $pdo->prepare(
    "SELECT c.MaCauHoi, c.MaMon, c.NoiDung, c.DoKho, c.TrangThai, m.TenMon,
            COALESCE(tl.luot, 0) AS luot, COALESCE(tl.dung, 0) AS dung,
            (SELECT COUNT(DISTINCT x.MaTran) FROM CAUHOITRANDAU x WHERE x.MaCauHoi = c.MaCauHoi) AS so_tran
     FROM CAUHOI c
     JOIN MONHOC m ON m.MaMon = c.MaMon
     $joinTL
     $where
     ORDER BY $thuTu
     LIMIT " . $p->limit() . ' OFFSET ' . $p->offset()
);
$st->execute($tham);
$ds = $st->fetchAll();

// ---- Hàm phụ cho phần hiển thị ----
// Link chip: giữ tìm kiếm, môn, loại kỳ thi; chỉ đổi độ khó/trạng thái/chip đặc biệt; về trang 1
$urlChip = static function (string $dkMoi, string $ttMoi, string $locMoi = '') use ($q, $mon, $kt): string {
    $tx = array_filter(['q' => $q, 'mon' => $mon > 0 ? $mon : '', 'kt' => $kt, 'dk' => $dkMoi, 'tt' => $ttMoi, 'loc' => $locMoi],
        static fn ($v): bool => $v !== '' && $v !== null);
    return '?' . http_build_query($tx) . '#chDanhSach';
};
// Link của một ô trên bản đồ phủ: lọc đúng môn + độ khó, chỉ câu đang hiển thị
$urlO = static fn (int $maMon, string $doKho): string =>
    '?' . http_build_query(['mon' => $maMon, 'dk' => $doKho, 'tt' => 'Hiển thị']) . '#chDanhSach';

// Thanh phân trang kiểu nút tròn: giữ nguyên mọi tham số GET khác, chỉ đổi tham số của chính nó
$veTrang = static function (int $trang, int $soTrang, string $tham, bool $muiTen = false): string {
    if ($soTrang <= 1) { return ''; }
    $url = static function (int $n) use ($tham): string {
        $tx = $_GET;
        $tx[$tham] = $n;
        return '?' . http_build_query($tx);
    };
    $hien = [1, $soTrang, $trang - 1, $trang, $trang + 1];
    if ($trang <= 3) { $hien[] = 2; $hien[] = 3; }
    $hien = array_values(array_unique(array_filter($hien, static fn (int $n): bool => $n >= 1 && $n <= $soTrang)));
    sort($hien);

    $h = '<nav class="ch-pt" aria-label="Phân trang">';
    if ($muiTen) {
        $h .= $trang > 1
            ? '<a class="ch-pt-nut" href="' . e($url($trang - 1)) . '" aria-label="Trang trước">&lsaquo;</a>'
            : '<span class="ch-pt-nut tat" aria-hidden="true">&lsaquo;</span>';
    }
    $truoc = 0;
    foreach ($hien as $n) {
        if ($truoc > 0 && $n - $truoc === 2) {
            $mid = $truoc + 1;
            $h .= '<a class="ch-pt-nut" href="' . e($url($mid)) . '">' . $mid . '</a>';
        } elseif ($truoc > 0 && $n - $truoc > 2) {
            $h .= '<span class="ch-pt-nut cham" aria-hidden="true">…</span>';
        }
        $h .= $n === $trang
            ? '<span class="ch-pt-nut dang" aria-current="page">' . $n . '</span>'
            : '<a class="ch-pt-nut" href="' . e($url($n)) . '">' . $n . '</a>';
        $truoc = $n;
    }
    if ($muiTen) {
        $h .= $trang < $soTrang
            ? '<a class="ch-pt-nut" href="' . e($url($trang + 1)) . '" aria-label="Trang sau">&rsaquo;</a>'
            : '<span class="ch-pt-nut tat" aria-hidden="true">&rsaquo;</span>';
    }
    return $h . '</nav>';
};

$so       = static fn (int $n): string => number_format($n, 0, ',', '.');   // 1248 -> 1.248
$lopDoKho = ['Dễ' => 'pill-de', 'Trung bình' => 'pill-tb', 'Khó' => 'pill-kho'];
$chuMon   = static fn (string $ten): string => mb_strtoupper(mb_substr(trim($ten), 0, 1)) ?: '?';
$cat      = static fn (string $s, int $n): string => (string) preg_replace('/^(.{' . $n . '}).+$/su', '$1…', $s);
$lopOBando = static function (int $n) use ($NGUONG_DO, $PHU_DU, $PHU_TOT): string {
    if ($n < $NGUONG_DO) { return 'o-do'; }
    if ($n < $PHU_DU)    { return 'o-vang'; }
    if ($n < $PHU_TOT)   { return 'o-xanh-nhat'; }
    return 'o-xanh';
};
$hangBando = [['Hạng Đồng · Dễ', 'Dễ', 'de'], ['Hạng Bạc · TB', 'Trung bình', 'tb'], ['Hạng Vàng · Khó', 'Khó', 'kho']];

// Khoảng tỉ lệ đúng "đúng chuẩn" của từng nhãn: [bắt đầu %, độ rộng %, chữ gợi ý]
$khoangDo = [
    'Dễ'         => [$NGUONG_DE, 100 - $NGUONG_DE, 'nên ≥ ' . $NGUONG_DE . '%'],
    'Trung bình' => [$NGUONG_KHO, $NGUONG_DE - $NGUONG_KHO, 'nên ' . $NGUONG_KHO . ' – ' . $NGUONG_DE . '%'],
    'Khó'        => [0, $NGUONG_KHO, 'nên < ' . $NGUONG_KHO . '%'],
];
$lopTyLe = static function (int $dung, int $luot) use ($NGUONG_DE, $NGUONG_KHO): string {   // màu chữ phần trăm theo vùng rơi vào
    if ($dung * 100 >= $NGUONG_DE * $luot) { return 'ty-de'; }
    if ($dung * 100 < $NGUONG_KHO * $luot) { return 'ty-kho'; }
    return 'ty-tb';
};

$soMonLocHien = 10;
$tenMonChon = 'Mọi môn';
foreach ($dsMon as $m) {
    if ((int) $m['MaMon'] === $mon) { $tenMonChon = (string) $m['TenMon']; }
}

$tieuDe   = 'Ngân hàng câu hỏi';
$mucChon  = 'cauhoi';
$cssTrang = 'cauhoi';
$jsTrang  = 'cauhoi';
require __DIR__ . '/layout/header.php';
?>
<div class="ch-trang">

  <div class="ch-dau">
    <span class="ch-dau-icon" aria-hidden="true">
      <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/><path d="M3 12a9 3 0 0 0 18 0"/></svg>
    </span>
    <div class="ch-dau-chu">
      <h2>Ngân hàng câu hỏi</h2>
      <p>Quản lý câu hỏi, độ khó và sức khỏe của ngân hàng để trước khi ghép trận</p>
    </div>
    <div class="ch-dau-nut">
      <a class="ch-nut ch-nut-vien" href="<?= e(BASE_URL) ?>/admin/cauhoi_nhap.php">Nhập từ Word/Excel</a>
      <a class="ch-nut ch-nut-chinh" href="<?= e(BASE_URL) ?>/admin/cauhoi_form.php?<?= e(($mon > 0 ? 'mon=' . $mon . '&' : '') . 've=' . $veQuery) ?>">+ Thêm câu hỏi</a>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="<?= $flash[0] === 'ok' ? 'thanhcong' : 'loi' ?>" role="status"><?= e($flash[1]) ?></div>
  <?php endif; ?>

  <!-- Bốn thẻ số liệu -->
  <div class="ch-the-so">
    <div class="ch-the">
      <span class="ch-the-ico xanh" aria-hidden="true">?</span>
      <div><b class="ch-the-so-lon"><?= $so($tongCau) ?></b><small>Tổng số câu hỏi</small></div>
    </div>
    <div class="ch-the">
      <span class="ch-the-ico luc" aria-hidden="true">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
      </span>
      <div><b class="ch-the-so-lon"><?= $so($tongCau - $tongAn) ?></b><small>Đang hiển thị (<?= $so($tongAn) ?> đã ẩn)</small></div>
    </div>
    <div class="ch-the">
      <span class="ch-the-ico cam" aria-hidden="true">!</span>
      <div><b class="ch-the-so-lon"><?= $so($tongLech) ?></b><small>Câu lệch độ khó, nên xem lại</small></div>
    </div>
    <div class="ch-the">
      <span class="ch-the-ico do" aria-hidden="true">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M3 5h18L12 20z"/></svg>
      </span>
      <div><b class="ch-the-so-lon"><?= $so($oThieu) ?></b><small>Ô thiếu câu (dưới <?= $NGUONG_DO ?> câu mỗi hạng)</small></div>
    </div>
  </div>

  <div class="ch-hai-cot">

    <!-- Bản đồ phủ câu hỏi -->
    <section class="ch-khoi" aria-labelledby="chTieuDeBando">
      <h3 id="chTieuDeBando" class="ch-tieude">Bản đồ phủ câu hỏi <span class="ch-moi">MỚI</span></h3>
      <p class="ch-mota">Mỗi môn cần tối thiểu <b><?= $PHU_DU ?> câu / hạng</b> để ghép trận công bằng. Ô đỏ là chưa đủ, bấm vào ô để lọc và thêm câu hỏi.</p>
      <?php if (!$bando): ?>
        <p class="nhan-phu">Chưa có môn học nào đang mở.</p>
      <?php else: ?>
        <div class="ch-bando" role="table" aria-label="Số câu hỏi theo môn và hạng">
          <div class="ch-bando-dong ch-bando-dau" role="row">
            <span role="columnheader">Môn học</span>
            <?php foreach ($hangBando as $h): ?><span role="columnheader" class="giua"><?= e($h[0]) ?></span><?php endforeach; ?>
          </div>
          <?php foreach ($bandoTrang as $m): ?>
            <div class="ch-bando-dong" role="row">
              <a class="ch-bando-mon<?= (int) $m['MaMon'] === $mon ? ' dang-loc' : '' ?>" role="rowheader" href="<?= e('?mon=' . (int) $m['MaMon'] . '#chDanhSach') ?>" title="Lọc theo môn <?= e($m['TenMon']) ?>">
                <span class="ch-ico-mon m<?= (int) $m['MaMon'] % 6 ?>" aria-hidden="true"><?= e($chuMon((string) $m['TenMon'])) ?></span>
                <span class="ch-ten-mon"><?= e($m['TenMon']) ?></span>
              </a>
              <?php foreach ($hangBando as $h): $n = (int) $m[$h[2]]; ?>
                <span class="ch-o <?= $lopOBando($n) ?>" role="cell">
                  <a class="ch-o-xem" href="<?= e($urlO((int) $m['MaMon'], $h[1])) ?>" title="<?= e($m['TenMon'] . ' · ' . $h[1] . ': ' . $n . ' câu đang hiển thị') ?>"><?= $n ?></a>
                  <?php if ($n < $PHU_DU): ?>
                    <a class="ch-o-them" href="<?= e(BASE_URL) ?>/admin/cauhoi_form.php?<?= e(http_build_query(['mon' => (int) $m['MaMon'], 'dokho' => $h[1], 've' => (string) ($_SERVER['QUERY_STRING'] ?? '')])) ?>" title="Thêm câu hỏi <?= e($h[1]) ?> cho môn <?= e($m['TenMon']) ?>" aria-label="Thêm câu hỏi <?= e($h[1]) ?> cho môn <?= e($m['TenMon']) ?>">+</a>
                  <?php endif; ?>
                </span>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
        </div>
        <p class="ch-chuthich">
          <i class="o-do"></i> Dưới <?= $NGUONG_DO ?>
          <i class="o-vang"></i> <?= $NGUONG_DO ?> – <?= $PHU_DU - 1 ?>
          <i class="o-xanh-nhat"></i> <?= $PHU_DU ?> – <?= $PHU_TOT - 1 ?>
          <i class="o-xanh"></i> <?= $PHU_TOT ?> trở lên
        </p>
        <div class="ch-chan-khoi">
          <span class="nhan-phu">Môn <?= $pBando->offset() + 1 ?> – <?= $pBando->offset() + count($bandoTrang) ?> trong <?= count($bando) ?> môn · xếp theo môn thiếu câu nhất</span>
          <?= $veTrang($pBando->trang(), $pBando->soTrang(), 'bd', true) ?>
        </div>
      <?php endif; ?>
    </section>

    <!-- Hiệu chỉnh độ khó bằng dữ liệu thật -->
    <section class="ch-khoi" aria-labelledby="chTieuDeLech">
      <h3 id="chTieuDeLech" class="ch-tieude">Hiệu chỉnh độ khó bằng dữ liệu thật <span class="ch-moi">MỚI</span></h3>
      <p class="ch-mota">So tỉ lệ người chơi trả lời đúng với nhãn độ khó đang gắn. Dễ nên ≥ <?= $NGUONG_DE ?>% đúng, Khó nên &lt; <?= $NGUONG_KHO ?>%.</p>
      <form method="post" action="" class="ch-min-luot">
        <?= csrf_field() ?>
        <label for="chMinLuot">Chỉ xét câu có từ</label>
        <input type="number" id="chMinLuot" name="min_luot" value="<?= (int) $MIN_LUOT ?>" min="1" max="<?= (int) $MIN_LUOT_TOI_DA ?>" inputmode="numeric" required>
        <span>lượt trả lời</span>
        <button type="submit" name="hanh_dong" value="doi_min_luot" class="ch-nut ch-nut-nho ch-nut-vien">Áp dụng</button>
        <small class="nhan-phu">Số càng lớn thì kết quả càng đáng tin, nhưng ít câu được xét hơn.</small>
      </form>
      <div class="ch-bieudo">
        <div class="ch-bieudo-dau">
          <span class="ch-ghichu">
            <b>Phân bố tỉ lệ đúng</b>
            <button type="button" class="ch-ghichu-nut" aria-label="Cách đọc biểu đồ này" aria-describedby="chGiaiThichBieuDo" aria-expanded="false">i</button>
            <span class="ch-ghichu-nd" id="chGiaiThichBieuDo" role="tooltip">
              <b>Cách đọc biểu đồ</b>
              <span><em>Trục ngang</em> là tỉ lệ người chơi trả lời <b>đúng</b> một câu hỏi, chia thành các khoảng 10%. Càng sang phải thì câu càng dễ. <em>Trục dọc</em> là <b>số câu hỏi</b> rơi vào khoảng đó, không phải số người chơi.</span>
              <span>Ví dụ: cột &ldquo;50&rdquo; cao 8 nghĩa là có 8 câu hỏi mà khoảng 50&ndash;60% lượt trả lời là đúng.</span>
              <span>Màu cột là nhãn độ khó mà dữ liệu gợi ý: đỏ là Khó (dưới <?= $NGUONG_KHO ?>%), vàng là Trung bình, xanh là Dễ (từ <?= $NGUONG_DE ?>% trở lên). Biểu đồ <b>không</b> đọc nhãn admin đã gắn, nên đổi nhãn sẽ không làm cột di chuyển.</span>
              <span>Chỉ tính câu có từ <?= $MIN_LUOT ?> lượt trả lời trở lên. Câu ít lượt thì tỉ lệ chưa đáng tin.</span>
            </span>
          </span>
          <span class="nhan-phu"><?= $so($tongDuDL) ?> câu đủ <?= $MIN_LUOT ?> lượt<?= $mon > 0 ? ' · môn ' . e($tenMonChon) : ' · mọi môn' ?></span>
        </div>
        <?php if ($tongDuDL === 0): ?>
          <p class="ch-rong">Chưa có câu nào đủ <?= $MIN_LUOT ?> lượt trả lời để vẽ biểu đồ.</p>
        <?php else: ?>
          <div class="ch-bieudo-khung"><canvas id="chBieuDo" role="img" aria-label="Số câu hỏi theo khoảng tỉ lệ trả lời đúng"
               data-nhan="<?= e(json_encode($bieuDo['nhan'], JSON_UNESCAPED_UNICODE)) ?>"
               data-so="<?= e(json_encode($bieuDo['so'])) ?>"
               data-mau="<?= e(json_encode($bieuDo['mau'])) ?>"></canvas></div>
          <p class="ch-chuthich"><i class="o-do"></i> Khó (&lt; <?= $NGUONG_KHO ?>%) <i class="o-vang"></i> Trung bình <i class="o-xanh"></i> Dễ (≥ <?= $NGUONG_DE ?>%)</p>
        <?php endif; ?>
      </div>
      <div class="ch-chan-khoi">
        <span class="nhan-phu"><?= $so($tongLech) ?> câu lệch độ khó (chỉ xét câu từ <?= $MIN_LUOT ?> lượt trả lời)</span>
        <?php if ($tongLech > 0): ?>
          <a class="ch-xem-tat-ca" href="<?= e($urlChip('', '', 'lech')) ?>">Xem tất cả <?= $so($tongLech) ?> câu →</a>
        <?php endif; ?>
      </div>
    </section>

  </div>

  <!-- Bộ lọc + danh sách -->
  <section class="ch-khoi" id="chDanhSach" aria-label="Danh sách câu hỏi">
    <form method="get" action="" class="ch-loc" id="chLoc">
      <?php if ($dk !== ''): ?><input type="hidden" name="dk" value="<?= e($dk) ?>"><?php endif; ?>
      <?php if ($tt !== ''): ?><input type="hidden" name="tt" value="<?= e($tt) ?>"><?php endif; ?>
      <?php if ($loc !== ''): ?><input type="hidden" name="loc" value="<?= e($loc) ?>"><?php endif; ?>
      <label class="ch-o-tim">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="Tìm trong nội dung câu hỏi… (hoặc nhập mã, ví dụ #127)" aria-label="Tìm trong nội dung câu hỏi" autocomplete="off">
      </label>
      <div class="ch-chon-mon" id="chChonMon">
        <input type="hidden" name="mon" value="<?= $mon > 0 ? $mon : 0 ?>">
        <button type="button" class="ch-chon-mon-nut" aria-haspopup="listbox" aria-expanded="false">
          <span class="ch-chon-mon-ten"><?= e($tenMonChon) ?></span>
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
        </button>
        <div class="ch-chon-mon-hop" hidden>
          <input type="search" class="ch-chon-mon-tim" placeholder="Tìm môn học…" aria-label="Tìm môn học" autocomplete="off">
          <ul role="listbox">
            <li><button type="button" data-id="0" data-ten="Mọi môn"<?= $mon <= 0 ? ' class="dang-chon"' : '' ?>>Mọi môn</button></li>
            <?php foreach ($dsMon as $i => $m): ?>
              <li<?= $i >= $soMonLocHien ? ' data-them="1"' : '' ?>><button type="button" data-id="<?= (int) $m['MaMon'] ?>" data-ten="<?= e($m['TenMon']) ?>"<?= (int) $m['MaMon'] === $mon ? ' class="dang-chon"' : '' ?>><?= e($m['TenMon']) ?></button></li>
            <?php endforeach; ?>
          </ul>
          <?php if (count($dsMon) > $soMonLocHien): ?>
            <button type="button" class="ch-chon-mon-them">Xem thêm <?= count($dsMon) - $soMonLocHien ?> môn</button>
          <?php endif; ?>
          <p class="ch-chon-mon-rong" hidden>Không có môn nào khớp.</p>
        </div>
      </div>
      <select name="kt" aria-label="Loại kỳ thi">
        <option value="">Mọi loại kỳ thi</option>
        <?php foreach ($dsKT as $k): ?>
          <option value="<?= (int) $k['MaLoaiKT'] ?>"<?= $kt === (string) $k['MaLoaiKT'] ? ' selected' : '' ?>><?= e($k['TenLoaiKT']) ?></option>
        <?php endforeach; ?>
        <option value="chung"<?= $kt === 'chung' ? ' selected' : '' ?>>Dùng cho cả hai kỳ</option>
      </select>
      <button type="submit" class="ch-nut ch-nut-chinh">Lọc</button>
      <a class="ch-nut ch-nut-vien" href="?">Xóa lọc</a>
    </form>

    <?php if ($mon > 0 && $soCauMon > 0): ?>
      <form method="post" action="" class="ch-xoa-mon">
        <?= csrf_field() ?>
        <input type="hidden" name="mon_xoa" value="<?= $mon ?>">
        <span class="nhan-phu">Môn <b><?= e($tenMonChon) ?></b>: <?= $so($soCauMon) ?> câu, trong đó <?= $so($soCauMon - $soXoaDuocMon) ?> câu đã dùng trong trận.</span>
        <button type="submit" name="hanh_dong" value="xoa_mon" class="ch-nut ch-nut-nho ch-nut-do"
                data-xac-nhan="Xóa vĩnh viễn TOÀN BỘ <?= $soCauMon ?> câu hỏi của môn <?= e($tenMonChon) ?>?<?= $soCauMon - $soXoaDuocMon > 0 ? ' ' . ($soCauMon - $soXoaDuocMon) . ' câu đã dùng trong trận: bài làm và dòng câu trong các trận đó cũng bị xóa (điểm, Elo đã lưu không đổi).' : '' ?> Câu đang nằm trong trận chưa kết thúc được giữ lại. Không thể hoàn tác.">Xóa tất cả câu của môn này</button>
      </form>
    <?php endif; ?>

    <div class="ch-chips" role="group" aria-label="Lọc nhanh theo độ khó và trạng thái">
      <a class="ch-chip<?= $dk === '' && $tt === '' && $loc === '' ? ' dang-chon' : '' ?>" href="<?= e($urlChip('', '')) ?>">Tất cả <b><?= $so($dem['tong']) ?></b></a>
      <a class="ch-chip<?= $dk === 'Dễ' && $loc === '' ? ' dang-chon' : '' ?>" href="<?= e($urlChip('Dễ', '')) ?>">Dễ <b><?= $so($dem['de']) ?></b></a>
      <a class="ch-chip<?= $dk === 'Trung bình' && $loc === '' ? ' dang-chon' : '' ?>" href="<?= e($urlChip('Trung bình', '')) ?>">Trung bình <b><?= $so($dem['tb']) ?></b></a>
      <a class="ch-chip<?= $dk === 'Khó' && $loc === '' ? ' dang-chon' : '' ?>" href="<?= e($urlChip('Khó', '')) ?>">Khó <b><?= $so($dem['kho']) ?></b></a>
      <a class="ch-chip<?= $tt === 'Đã ẩn' && $dk === '' && $loc === '' ? ' dang-chon' : '' ?>" href="<?= e($urlChip('', 'Đã ẩn')) ?>">Đã ẩn <b><?= $so($dem['an']) ?></b></a>
      <a class="ch-chip ch-chip-cam<?= $loc === 'lech' ? ' dang-chon' : '' ?>" href="<?= e($urlChip('', '', 'lech')) ?>">Lệch độ khó <b><?= $so($dem['lech']) ?></b></a>
      <a class="ch-chip ch-chip-cam<?= $loc === 'chua' ? ' dang-chon' : '' ?>" href="<?= e($urlChip('', '', 'chua')) ?>">Chưa từng dùng <b><?= $so($dem['chua']) ?></b></a>
    </div>

    <?php if (!$ds): ?>
      <p class="nhan-phu ch-rong-ds">Không có câu hỏi nào khớp.</p>
    <?php else: ?>
      <div class="ch-cuon">
        <table class="ch-bang" id="chBang">
          <thead>
            <tr>
              <th class="ch-td-chon"><input type="checkbox" id="chChonTatCa" aria-label="Chọn tất cả câu hỏi trong trang này"></th>
              <th>Mã</th><th>Nội dung</th><th>Môn</th><th>Độ khó</th><th>Tỉ lệ đúng thực tế</th><th>Dùng</th><th>Trạng thái</th><th></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($ds as $r):
            $an   = $r['TrangThai'] === 'Đã ẩn';
            $luot = (int) $r['luot']; $dung = (int) $r['dung'];
            $du   = $luot >= $MIN_LUOT;
            $tyLe = $luot > 0 ? $dung * 100 / $luot : 0;
            $lech = $du && $hangTheoTyLe($dung, $luot) !== $r['DoKho'];
            [$vungTu, $vungRong, $chuNen] = $khoangDo[$r['DoKho']] ?? [0, 0, ''];
          ?>
            <tr id="cau-<?= (int) $r['MaCauHoi'] ?>" class="ch-dong<?= $an ? ' da-an' : '' ?><?= $lech ? ' dong-lech' : '' ?>" data-id="<?= (int) $r['MaCauHoi'] ?>" tabindex="0" title="Bấm để xem nhanh">
              <td class="ch-td-chon"><input type="checkbox" class="ch-chon" value="<?= (int) $r['MaCauHoi'] ?>" aria-label="Chọn câu hỏi #<?= (int) $r['MaCauHoi'] ?>"></td>
              <td class="ch-ma">#<?= (int) $r['MaCauHoi'] ?></td>
              <td class="ch-nd"><?= e($cat((string) $r['NoiDung'], 60)) ?></td>
              <td><span class="ch-mon-o"><span class="ch-ico-mon m<?= (int) $r['MaMon'] % 6 ?>" aria-hidden="true"><?= e($chuMon((string) $r['TenMon'])) ?></span><span class="ch-mon-ten"><?= e($r['TenMon']) ?></span></span></td>
              <td><span class="ch-pill <?= e($lopDoKho[$r['DoKho']] ?? '') ?>"><?= e($r['DoKho']) ?></span></td>
              <td class="ch-td-ty">
                <div class="ch-ty">
                  <span class="ch-ty-thanh" aria-hidden="true">
                    <i class="ch-ty-vung" style="left:<?= $vungTu ?>%;width:<?= $vungRong ?>%"></i>
                    <?php if ($du): ?>
                      <i class="ch-ty-day<?= $lech ? ' lech' : '' ?>" style="width:<?= number_format($tyLe, 2, '.', '') ?>%"></i>
                      <i class="ch-ty-nut<?= $lech ? ' lech' : '' ?>" style="left:<?= number_format($tyLe, 2, '.', '') ?>%"></i>
                    <?php endif; ?>
                  </span>
                  <span class="ch-ty-chu">
                    <?php if (!$du): ?>
                      <b class="ty-chua">Chưa đủ dữ liệu</b><small><?= $so($luot) ?> / <?= $MIN_LUOT ?> lượt</small>
                    <?php elseif ($lech): ?>
                      <b class="ty-lech"><?= (int) round($tyLe) ?>%</b><small><?= e($chuNen) ?></small>
                    <?php else: ?>
                      <b><?= (int) round($tyLe) ?>%</b><small>đúng khoảng <?= e($r['DoKho'] === 'Trung bình' ? 'TB' : $r['DoKho']) ?></small>
                    <?php endif; ?>
                  </span>
                </div>
              </td>
              <td class="ch-td-dung"><b><?= $so((int) $r['so_tran']) ?></b> trận</td>
              <td><span class="ch-pill <?= $an ? 'pill-an' : 'pill-hien' ?>"><?= $an ? 'Đã ẩn' : 'Hiển thị' ?></span></td>
              <td class="ch-td-tt khong-ngat">
                <a class="ch-nut ch-nut-nho ch-nut-vien" href="<?= e(BASE_URL) ?>/admin/cauhoi_form.php?id=<?= (int) $r['MaCauHoi'] ?>&amp;ve=<?= $veQuery ?>">Sửa</a>
                <form method="post" action="" class="form-dong">
                  <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?= (int) $r['MaCauHoi'] ?>">
                  <?php if (!$an): ?>
                    <button type="submit" name="hanh_dong" value="an" class="ch-nut ch-nut-nho ch-nut-vien"
                            data-xac-nhan="Ẩn câu hỏi này? Câu sẽ không được chọn vào trận mới.">Ẩn</button>
                  <?php else: ?>
                    <button type="submit" name="hanh_dong" value="hien" class="ch-nut ch-nut-nho ch-nut-chinh">Hiện lại</button>
                  <?php endif; ?>
                </form>
                <form method="post" action="" class="form-dong">
                  <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?= (int) $r['MaCauHoi'] ?>">
                  <button type="submit" name="hanh_dong" value="xoa" class="ch-nut ch-nut-nho ch-nut-do"
                          data-xac-nhan="Xóa vĩnh viễn câu hỏi #<?= (int) $r['MaCauHoi'] ?>?<?= (int) $r['so_tran'] > 0 ? ' Câu đã dùng trong ' . (int) $r['so_tran'] . ' trận: bài làm và dòng câu trong các trận đó cũng bị xóa (điểm, Elo đã lưu không đổi).' : '' ?> Không thể hoàn tác.">Xóa</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <div class="ch-chan-khoi ch-chan-ds">
      <span class="nhan-phu">
        <?php if ($p->tong() > 0): ?>
          Hiển thị <?= $so($p->offset() + 1) ?> – <?= $so($p->offset() + count($ds)) ?> trong <?= $so($p->tong()) ?> câu hỏi
        <?php else: ?>
          Không có câu hỏi nào
        <?php endif; ?>
      </span>
      <?= $veTrang($p->trang(), $p->soTrang(), 'page') ?>
    </div>
    <p class="nhan-phu ch-ghichu">Câu đã dùng trong trận vẫn sửa được để chữa lỗi (điểm và Elo của trận cũ không tính lại, mỗi lần sửa có ghi nhật ký). Xóa câu đã dùng sẽ xóa luôn bài làm của câu đó trong các trận cũ (điểm tổng và Elo đã lưu không đổi). Câu đang nằm trong trận chưa kết thúc tạm thời chỉ xem, không xóa được. Tỉ lệ đúng thực tế tính trên các lượt trả lời đã nộp hoặc hết giờ.</p>
  </section>

  <!-- Thanh nổi khi chọn nhiều câu (JS thêm các ô ids[] lúc gửi) -->
  <form method="post" action="" class="ch-thanhchon" id="chThanhChon" hidden>
    <?= csrf_field() ?>
    <span class="ch-thanhchon-so">Đã chọn <b id="chSoChon">0</b> câu hỏi</span>
    <button type="submit" name="hanh_dong" value="an_nhieu" class="ch-tc-nut">Ẩn đã chọn</button>
    <button type="submit" name="hanh_dong" value="hien_nhieu" class="ch-tc-nut">Hiện lại đã chọn</button>
    <button type="submit" name="hanh_dong" value="xoa_nhieu" class="ch-tc-nut ch-tc-do">Xóa đã chọn</button>
    <button type="button" class="ch-tc-bo" id="chBoChon">Bỏ chọn</button>
  </form>

</div>

<!-- Ngăn "Xem nhanh": JS đổ dữ liệu từ cauhoi_api.php -->
<div class="ch-nen" id="chNen"></div>
<aside class="ch-ngan" id="chNgan" role="dialog" aria-modal="true" aria-labelledby="chNganTieuDe" aria-hidden="true"
       data-api="<?= e(BASE_URL) ?>/admin/cauhoi_api.php" data-form="<?= e(BASE_URL) ?>/admin/cauhoi_form.php">
  <div class="ch-ngan-dau">
    <div>
      <small>Xem nhanh</small>
      <h3 id="chNganTieuDe">Câu hỏi</h3>
    </div>
    <button type="button" class="ch-ngan-dong" id="chNganDong" aria-label="Đóng">&times;</button>
  </div>

  <p class="ch-ngan-tai" id="chNganTai" hidden></p>

  <div class="ch-ngan-than" id="chNganThan" hidden>
    <div class="ch-ngan-meta" id="chMeta"></div>

    <h4 class="ch-nhan">Nội dung</h4>
    <p class="ch-ngan-nd" id="chNoiDung"></p>

    <h4 class="ch-nhan">Các đáp án</h4>
    <ul class="ch-da" id="chDapAn"></ul>

    <div id="chGiaiThichBox">
      <h4 class="ch-nhan">Giải thích</h4>
      <div class="ch-giaithich" id="chGiaiThich"></div>
    </div>

    <p class="ch-ngan-ghichu" id="chGhiChu" hidden></p>

    <div class="ch-ngan-chan">
      <a class="ch-nut ch-nut-chinh" id="chSua" href="#">Sửa câu hỏi</a>
      <form method="post" action="" class="form-dong">
        <?= csrf_field() ?>
        <input type="hidden" name="id" id="chAnId" value="">
        <button type="submit" name="hanh_dong" value="an" class="ch-nut ch-nut-xam" id="chAnNut">Ẩn</button>
        <button type="submit" name="hanh_dong" value="xoa" class="ch-nut ch-nut-do" id="chXoaNut" hidden
                data-xac-nhan="Xóa vĩnh viễn câu hỏi này? Không thể hoàn tác.">Xóa</button>
      </form>
    </div>
  </div>
</aside>
<script src="<?= e(BASE_URL) ?>/assets/vendor/chart.umd.js"></script>
<?php require __DIR__ . '/layout/footer.php'; ?>