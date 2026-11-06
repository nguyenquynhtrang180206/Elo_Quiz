<?php
// Tìm trận: chọn môn trong danh sách, chọn loại kỳ thi, vào / hủy hàng đợi (C-16 đến C-19, C-22).
// Không thấy môn của mình thì được đề xuất môn mới để quản trị viên duyệt (cần chạy them_de_xuat_mon.sql).
// Mỗi lần trang chờ tải lại thì gọi GhepDoi::thuGhep() (C-20); ghép được hoặc đã có trận thì chuyển sang phong.php.
// Đặt tại: elo_quiz/client/timtran.php
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/GhepDoi.php';
require_once __DIR__ . '/../core/LoLChampions.php';

$maND = Auth::clientId();   // khách chưa đăng nhập vẫn xem được môn/kỳ thi; vào hàng đợi và đề xuất môn cần đăng nhập
$pdo  = Database::pdo();

$soCau      = max(1, Config::int('SO_CAU', 10));
$choToiDa   = max(1, Config::int('THOI_GIAN_CHO_GHEP_TOI_DA', 30));
$eloKhoiTao = Config::int('ELO_KHOI_TAO', 1000);
$choPhepBot = isset($_SESSION['cho_phep_bot']) ? (bool) $_SESSION['cho_phep_bot'] : true;
const TOI_DA_DE_XUAT_CHO = 3;   // mỗi người tối đa 3 đề xuất đang chờ duyệt, tránh gửi tràn

function flashTT(?string $loai = null, ?string $nd = null): ?array
{
    if ($loai !== null) {
        $_SESSION['flash_tt'] = ['loai' => $loai, 'nd' => (string) $nd];
        return null;
    }
    $m = $_SESSION['flash_tt'] ?? null;
    unset($_SESSION['flash_tt']);
    return $m;
}

// Bỏ dấu đ/Đ để so tên môn không phân biệt hoa thường và dấu (collation unicode_ci không gộp đ với d)
function chuanTen(string $s): string
{
    $s = trim(preg_replace('/\s+/u', ' ', $s));
    if (class_exists('Normalizer')) {
        $s = Normalizer::normalize($s, Normalizer::FORM_C) ?: $s;
    }
    return $s;
}
const SQL_TEN_KHONG_D = "REPLACE(REPLACE(TenMon, 'đ', 'd'), 'Đ', 'D')";

// ---- Dọn dòng chờ quá hạn (quá 90s không tương tác thì dọn dẹp hàng đợi tránh rác) ----
if ($maND !== null) {
    $st = $pdo->prepare('DELETE FROM HANGDOIGHEPDOI WHERE MaND = ? AND TIMESTAMPDIFF(SECOND, ThoiGianVaoHang, NOW()) > ?');
    $st->execute([$maND, max($choToiDa * 3, 90)]);
}

$mon = (int) ($_GET['mon'] ?? 0);
if ($mon === 0) {
    $stFirstMon = $pdo->query("SELECT MaMon FROM MONHOC WHERE TrangThai = 'Đang mở' ORDER BY TenMon LIMIT 1");
    $mon = (int) ($stFirstMon->fetchColumn() ?: 0);
}

// ---- AJAX: Bật / Tắt chế độ tự ghép máy khi đang chờ ----
if (isset($_GET['ajax_toggle_bot'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $current = isset($_SESSION['cho_phep_bot']) ? (bool) $_SESSION['cho_phep_bot'] : true;
    $_SESSION['cho_phep_bot'] = !$current;
    echo json_encode([
        'ok' => true,
        'choPhepBot' => $_SESSION['cho_phep_bot'],
        'cheDo' => $_SESSION['cho_phep_bot'] ? 'auto_bot' : 'pvp_only',
        'nhan' => $_SESSION['cho_phep_bot'] ? 'Tự ghép Máy sau 30s' : 'Chỉ đấu Người thật (PvP)'
    ]);
    exit;
}

// ---- AJAX: Khởi động lại bộ đếm 30s khi tìm tiếp PvP ----
if (isset($_GET['ajax_reset_timer']) || isset($_POST['ajax_reset_timer'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if ($maND !== null) {
        $pdo->prepare('UPDATE HANGDOIGHEPDOI SET ThoiGianVaoHang = NOW() WHERE MaND = ?')->execute([$maND]);
        echo json_encode(['ok' => true]);
        exit;
    }
    echo json_encode(['ok' => false]);
    exit;
}

// ---- AJAX Polling kiểm tra tìm trận mượt mà không load lại trang ----
if (isset($_GET['ajax_check'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if ($maND === null) {
        echo json_encode(['coTran' => false, 'dangCho' => false]);
        exit;
    }
    // 1. Kiểm tra đã có trận đang diễn ra chưa
    $maTran = GhepDoi::tranDangCo($pdo, $maND);
    if ($maTran !== null) {
        echo json_encode(['coTran' => true, 'maTran' => $maTran]);
        exit;
    }
    // 2. Kiểm tra còn trong hàng đợi không
    $st = $pdo->prepare('SELECT TIMESTAMPDIFF(SECOND, ThoiGianVaoHang, NOW()) AS DaCho FROM HANGDOIGHEPDOI WHERE MaND = ?');
    $st->execute([$maND]);
    $daCho = $st->fetchColumn();
    if ($daCho === false) {
        echo json_encode(['coTran' => false, 'dangCho' => false]);
        exit;
    }

    $choPhepBot = isset($_SESSION['cho_phep_bot']) ? (bool) $_SESSION['cho_phep_bot'] : true;

    // 3. Nếu là PvP thuần túy (không ghép bot) và đã đủ 30 giây:
    if (!$choPhepBot && (int) $daCho >= $choToiDa) {
        echo json_encode([
            'coTran' => false,
            'dangCho' => true,
            'pvpTimeout' => true,
            'daCho' => (int) $daCho,
            'choToiDa' => $choToiDa,
            'choPhepBot' => false
        ]);
        exit;
    }

    // 4. Thử ghép đôi (quét người thật, nếu choPhepBot=true và đủ 30s thì tự ghép Đấu Thần Máy)
    $maTranMoi = GhepDoi::thuGhep($pdo, $maND, $choPhepBot);
    if ($maTranMoi !== null) {
        echo json_encode(['coTran' => true, 'maTran' => $maTranMoi]);
        exit;
    }
    echo json_encode([
        'coTran' => false,
        'dangCho' => true,
        'pvpTimeout' => false,
        'daCho' => (int) $daCho,
        'choToiDa' => $choToiDa,
        'choPhepBot' => $choPhepBot
    ]);
    exit;
}

// ---- Xử lý thao tác (POST) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($maND === null) {
        redirect(BASE_URL . '/client/login.php');
    }
    csrf_check();
    $hanhDong = (string) ($_POST['hanh_dong'] ?? '');

    if ($hanhDong === 'chon_tuong') {
        $tuongId = trim((string) ($_POST['tuong_id'] ?? ''));
        if (isset(LoLChampions::DANH_SACH[$tuongId])) {
            $_SESSION['tuong_chon'] = $tuongId;
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true, 'tuong' => LoLChampions::layTuong($tuongId)]);
                exit;
            }
            flashTT('ok', 'Đã xuất chiến cùng ' . LoLChampions::layTuong($tuongId)['ten'] . '!');
        }
        redirect(BASE_URL . '/client/timtran.php' . ($mon ? '?mon=' . $mon : ''));
    }

    if ($hanhDong === 'huy') {
        $pdo->prepare('DELETE FROM HANGDOIGHEPDOI WHERE MaND = ?')->execute([$maND]);
        flashTT('ok', 'Đã hủy tìm trận.');
        redirect(BASE_URL . '/client/timtran.php');
    }

    if ($hanhDong === 'vao_ngay_ai') {
        $st = $pdo->prepare('SELECT MaMon, MaLoaiKT, EloTaiThoiDiemCho FROM HANGDOIGHEPDOI WHERE MaND = ?');
        $st->execute([$maND]);
        $row = $st->fetch();
        if ($row) {
            $maTran = GhepDoi::ghepVoiBot($pdo, $maND, (int) $row['MaMon'], (int) $row['MaLoaiKT'], (int) $row['EloTaiThoiDiemCho'], $soCau);
            if ($maTran) {
                redirect(BASE_URL . '/client/phong.php?tran=' . $maTran);
            }
        }
        redirect(BASE_URL . '/client/timtran.php');
    }

    if ($hanhDong === 'vao') {
        if (!empty($_POST['tuong_id']) && isset(LoLChampions::DANH_SACH[$_POST['tuong_id']])) {
            $_SESSION['tuong_chon'] = (string) $_POST['tuong_id'];
        }
        if (isset($_POST['che_do_ghep'])) {
            $_SESSION['cho_phep_bot'] = ($_POST['che_do_ghep'] !== 'pvp_only');
        }
        $maMon = (int) ($_POST['mon'] ?? 0);
        $maKT  = (int) ($_POST['loaikt'] ?? 0);
        $loi   = null;

        $st = $pdo->prepare("SELECT 1 FROM MONHOC WHERE MaMon = ? AND TrangThai = 'Đang mở'");
        $st->execute([$maMon]);
        $monHopLe = (bool) $st->fetchColumn();
        $st = $pdo->prepare('SELECT 1 FROM LOAIKYTHI WHERE MaLoaiKT = ?');
        $st->execute([$maKT]);
        $ktHopLe = (bool) $st->fetchColumn();

        if (!$monHopLe || !$ktHopLe) {
            $loi = 'Môn học hoặc loại kỳ thi không hợp lệ.';
        } elseif (GhepDoi::tranDangCo($pdo, $maND) !== null) {
            $loi = 'Bạn đang có một trận chưa kết thúc.';
        } elseif (GhepDoi::demCauHoi($pdo, $maMon, $maKT) < $soCau) {
            $loi = 'Môn này chưa đủ câu hỏi cho loại kỳ thi đã chọn, chưa thể tìm trận.';
        } else {
            // C-22: lần đầu chơi môn này thì tạo Elo khởi tạo
            $pdo->prepare('INSERT IGNORE INTO ELOMONHOC (MaND, MaMon, DiemElo) VALUES (?, ?, ?)')
                ->execute([$maND, $maMon, $eloKhoiTao]);
            $st = $pdo->prepare('SELECT DiemElo FROM ELOMONHOC WHERE MaND = ? AND MaMon = ?');
            $st->execute([$maND, $maMon]);
            $elo = (int) $st->fetchColumn();

            // Nếu người chơi chọn Đấu Ngay Với Máy (Bot AI)
            if (!empty($_POST['dau_bot'])) {
                $maTran = GhepDoi::ghepVoiBot($pdo, $maND, $maMon, $maKT, $elo, $soCau);
                if ($maTran) {
                    redirect(BASE_URL . '/client/phong.php?tran=' . $maTran);
                }
            }

            try {
                $pdo->beginTransaction();
                // C-21: MaND là duy nhất ở hàng đợi, nên mỗi người chỉ có một dòng chờ
                $pdo->prepare('INSERT INTO HANGDOIGHEPDOI (MaND, MaMon, MaLoaiKT, EloTaiThoiDiemCho) VALUES (?, ?, ?, ?)')
                    ->execute([$maND, $maMon, $maKT, $elo]);
                $pdo->commit();
            } catch (PDOException $ex) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($ex->getCode() === '23000') {
                    $loi = 'Bạn đang trong hàng đợi rồi.';
                } else {
                    throw $ex;
                }
            }
        }
        if ($loi !== null) {
            flashTT('loi', $loi);
            redirect(BASE_URL . '/client/timtran.php?mon=' . $maMon);
        }
        redirect(BASE_URL . '/client/timtran.php');
    }

    // Đề xuất môn mới: lưu ở trạng thái "Chờ duyệt", chỉ Admin mới duyệt cho hiện ra
    if ($hanhDong === 'dexuat') {
        $ten  = chuanTen((string) ($_POST['ten_mon'] ?? ''));
        $moTa = trim((string) ($_POST['mo_ta'] ?? ''));
        $loi  = null;

        if ($ten === '') {
            $loi = 'Hãy nhập tên môn học.';
        } elseif (mb_strlen($ten) > 150) {
            $loi = 'Tên môn tối đa 150 ký tự.';
        } elseif (mb_strlen($moTa) > 500) {
            $loi = 'Mô tả tối đa 500 ký tự.';
        } else {
            // Trùng tên với môn đã có (kể cả đang chờ duyệt hoặc đang ẩn)?
            $st = $pdo->prepare('SELECT TrangThai FROM MONHOC WHERE ' . SQL_TEN_KHONG_D . ' = ? LIMIT 1');
            $st->execute([str_replace(['đ', 'Đ'], ['d', 'D'], $ten)]);
            $trung = $st->fetchColumn();
            if ($trung === 'Đang mở') {
                $loi = 'Môn này đã có trong danh sách, hãy chọn ở ô "Môn học".';
            } elseif ($trung === 'Chờ duyệt') {
                $loi = 'Môn này đã được đề xuất và đang chờ quản trị viên duyệt.';
            } elseif ($trung !== false) {
                $loi = 'Môn này hiện đang tạm ẩn, chưa thể thi đấu.';
            } else {
                $st = $pdo->prepare("SELECT COUNT(*) FROM MONHOC WHERE MaNguoiDeXuat = ? AND TrangThai = 'Chờ duyệt'");
                $st->execute([$maND]);
                if ((int) $st->fetchColumn() >= TOI_DA_DE_XUAT_CHO) {
                    $loi = 'Bạn đang có ' . TOI_DA_DE_XUAT_CHO . ' đề xuất chờ duyệt, hãy đợi quản trị viên xử lý trước.';
                } else {
                    $pdo->prepare("INSERT INTO MONHOC (TenMon, MoTa, TrangThai, MaNguoiDeXuat) VALUES (?, ?, 'Chờ duyệt', ?)")
                        ->execute([$ten, $moTa === '' ? null : $moTa, $maND]);
                }
            }
        }
        flashTT($loi === null ? 'ok' : 'loi', $loi ?? 'Đã gửi đề xuất. Môn sẽ xuất hiện trong danh sách sau khi quản trị viên duyệt.');
        redirect(BASE_URL . '/client/timtran.php');
    }
    redirect(BASE_URL . '/client/timtran.php');
}

$thongBao = flashTT();

// ---- Đang chờ trong hàng đợi? ----
$dangCho = null;
if ($maND !== null) {
    $st = $pdo->prepare(
        'SELECT h.MaMon, m.TenMon, k.TenLoaiKT, h.EloTaiThoiDiemCho,
                TIMESTAMPDIFF(SECOND, h.ThoiGianVaoHang, NOW()) AS DaCho
           FROM HANGDOIGHEPDOI h
           JOIN MONHOC m ON m.MaMon = h.MaMon
           JOIN LOAIKYTHI k ON k.MaLoaiKT = h.MaLoaiKT
          WHERE h.MaND = ?'
    );
    $st->execute([$maND]);
    $dangCho = $st->fetch() ?: null;

    // Đang chờ thì thử ghép; ghép được (hoặc người kia vừa ghép mình) thì vào phòng
    if ($dangCho) {
        GhepDoi::thuGhep($pdo, $maND, $choPhepBot);
    }
    $maTranDangCo = GhepDoi::tranDangCo($pdo, $maND);
    if ($maTranDangCo !== null) {
        redirect(BASE_URL . '/client/phong.php?tran=' . $maTranDangCo);
    }
}

$tieuDe = 'Tìm trận';
$bodyClass = 'trang-timtran';
$cssTrang = 'timtran';
$jsTrang  = 'timtran';

function renderPvzTopBattle(): string {
    return '<div class="pvz-top-battle" aria-hidden="true">'
      . '<div class="pvz-plants-group">'
        . '<!-- Sunflower -->'
        . '<div class="pvz-unit pvz-sunflower" title="Sunflower">'
          . '<svg viewBox="0 0 100 100" class="pvz-plant-svg">'
            . '<path d="M50 70 L50 96" stroke="#22C55E" stroke-width="6" stroke-linecap="round"/>'
            . '<path d="M50 82 Q30 75 25 82 Q35 90 50 85" fill="#16A34A"/>'
            . '<path d="M50 82 Q70 75 75 82 Q65 90 50 85" fill="#16A34A"/>'
            . '<g fill="#FDE047" stroke="#EAB308" stroke-width="2">'
              . '<ellipse cx="50" cy="22" rx="10" ry="18"/>'
              . '<ellipse cx="50" cy="74" rx="10" ry="18"/>'
              . '<ellipse cx="24" cy="48" rx="18" ry="10"/>'
              . '<ellipse cx="76" cy="48" rx="18" ry="10"/>'
              . '<ellipse cx="32" cy="30" rx="11" ry="16" transform="rotate(-45 32 30)"/>'
              . '<ellipse cx="68" cy="30" rx="11" ry="16" transform="rotate(45 68 30)"/>'
              . '<ellipse cx="32" cy="66" rx="11" ry="16" transform="rotate(45 32 66)"/>'
              . '<ellipse cx="68" cy="66" rx="11" ry="16" transform="rotate(-45 68 66)"/>'
            . '</g>'
            . '<circle cx="50" cy="48" r="22" fill="#78350F" stroke="#451A03" stroke-width="2"/>'
            . '<ellipse cx="42" cy="45" rx="4" ry="7" fill="#000"/>'
            . '<circle cx="43" cy="43" r="2" fill="#FFF"/>'
            . '<ellipse cx="58" cy="45" rx="4" ry="7" fill="#000"/>'
            . '<circle cx="59" cy="43" r="2" fill="#FFF"/>'
            . '<path d="M42 54 Q50 62 58 54" stroke="#FFF" stroke-width="3" fill="none" stroke-linecap="round"/>'
            . '<circle cx="35" cy="52" r="3.5" fill="#F43F5E" opacity="0.75"/>'
            . '<circle cx="65" cy="52" r="3.5" fill="#F43F5E" opacity="0.75"/>'
          . '</svg>'
        . '</div>'
        . '<!-- Wall-nut -->'
        . '<div class="pvz-unit pvz-wallnut" title="Wall-nut">'
          . '<svg viewBox="0 0 100 100" class="pvz-plant-svg">'
            . '<path d="M50 15 C30 15, 20 40, 22 75 C24 96, 76 96, 78 75 C80 40, 70 15, 50 15 Z" fill="#B45309" stroke="#78350F" stroke-width="3"/>'
            . '<path d="M48 20 C34 20, 26 42, 28 72 C30 88, 70 88, 72 72 C74 42, 66 20, 48 20 Z" fill="#D97706"/>'
            . '<ellipse cx="45" cy="48" rx="6" ry="10" fill="#FFF" stroke="#451A03" stroke-width="1.5"/>'
            . '<circle cx="47" cy="48" r="4.5" fill="#000"/>'
            . '<circle cx="48" cy="46" r="1.5" fill="#FFF"/>'
            . '<ellipse cx="62" cy="48" rx="6" ry="10" fill="#FFF" stroke="#451A03" stroke-width="1.5"/>'
            . '<circle cx="64" cy="48" r="4.5" fill="#000"/>'
            . '<circle cx="65" cy="46" r="1.5" fill="#FFF"/>'
            . '<path d="M52 64 Q57 67 62 64" stroke="#451A03" stroke-width="2.5" fill="none" stroke-linecap="round"/>'
          . '</svg>'
        . '</div>'
        . '<!-- Peashooter -->'
        . '<div class="pvz-unit pvz-peashooter" title="Peashooter">'
          . '<svg viewBox="0 0 100 100" class="pvz-plant-svg">'
            . '<ellipse cx="38" cy="92" rx="14" ry="6" fill="#15803D"/>'
            . '<ellipse cx="62" cy="92" rx="14" ry="6" fill="#15803D"/>'
            . '<path d="M50 55 C48 70, 52 80, 50 94" stroke="#22C55E" stroke-width="7" stroke-linecap="round"/>'
            . '<path d="M32 40 Q18 30 22 20 Q34 24 35 34" fill="#22C55E"/>'
            . '<circle cx="46" cy="42" r="24" fill="#4ADE80" stroke="#16A34A" stroke-width="3"/>'
            . '<ellipse cx="72" cy="42" rx="12" ry="10" fill="#22C55E" stroke="#15803D" stroke-width="2.5"/>'
            . '<ellipse cx="74" cy="42" rx="6" ry="8" fill="#052E16"/>'
            . '<ellipse cx="48" cy="34" rx="5" ry="7" fill="#000"/>'
            . '<circle cx="49" cy="32" r="2.5" fill="#FFF"/>'
          . '</svg>'
        . '</div>'
        . '<!-- Snow Pea -->'
        . '<div class="pvz-unit pvz-snowpea" title="Snow Pea">'
          . '<svg viewBox="0 0 100 100" class="pvz-plant-svg">'
            . '<ellipse cx="38" cy="92" rx="14" ry="6" fill="#0284C7"/>'
            . '<ellipse cx="62" cy="92" rx="14" ry="6" fill="#0284C7"/>'
            . '<path d="M50 55 C48 70, 52 80, 50 94" stroke="#38BDF8" stroke-width="7" stroke-linecap="round"/>'
            . '<polygon points="30,35 15,22 26,20" fill="#E0F2FE" stroke="#38BDF8"/>'
            . '<polygon points="35,28 25,12 36,15" fill="#E0F2FE" stroke="#38BDF8"/>'
            . '<polygon points="44,22 40,6 48,14" fill="#E0F2FE" stroke="#38BDF8"/>'
            . '<circle cx="46" cy="42" r="24" fill="#7DD3FC" stroke="#0284C7" stroke-width="3"/>'
            . '<ellipse cx="72" cy="42" rx="12" ry="10" fill="#38BDF8" stroke="#0284C7" stroke-width="2.5"/>'
            . '<ellipse cx="74" cy="42" rx="6" ry="8" fill="#082F49"/>'
            . '<ellipse cx="48" cy="34" rx="5" ry="7" fill="#000"/>'
            . '<circle cx="49" cy="32" r="2.5" fill="#FFF"/>'
          . '</svg>'
        . '</div>'
      . '</div>'
      . '<!-- Flying Bullets -->'
      . '<div class="pvz-bullet pvz-bullet-green"></div>'
      . '<div class="pvz-bullet pvz-bullet-ice"></div>'
      . '<!-- Pea Hit Splat -->'
      . '<div class="pvz-hit-splat">'
        . '<svg viewBox="0 0 100 100" class="pvz-splat-svg">'
          . '<circle cx="50" cy="50" r="14" fill="#86EFAC"/>'
          . '<circle cx="30" cy="30" r="7" fill="#4ADE80"/>'
          . '<circle cx="70" cy="25" r="8" fill="#22C55E"/>'
          . '<circle cx="75" cy="65" r="9" fill="#86EFAC"/>'
          . '<circle cx="35" cy="75" r="6" fill="#4ADE80"/>'
        . '</svg>'
      . '</div>'
      . '<!-- Zombie Marching On Right Ledge -->'
      . '<div class="pvz-zombie-group">'
        . '<div class="pvz-zombie-host">'
          . '<div class="pvz-zombie-char">'
            . '<svg viewBox="0 0 100 100" class="pvz-zombie-svg" style="transform: scaleX(-1);">'
              . '<line x1="42" y1="75" x2="38" y2="96" stroke="#1E293B" stroke-width="5" stroke-linecap="round"/>'
              . '<line x1="56" y1="75" x2="62" y2="96" stroke="#1E293B" stroke-width="5" stroke-linecap="round"/>'
              . '<path d="M34 50 L66 50 L62 76 L36 76 Z" fill="#78350F" stroke="#451A03" stroke-width="2"/>'
              . '<polygon points="50,50 48,68 50,72 52,68" fill="#EF4444"/>'
              . '<path d="M48 54 L20 52 L12 56" stroke="#65A30D" stroke-width="5" stroke-linecap="round"/>'
              . '<path d="M35 24 C35 12, 65 10, 68 24 C70 36, 68 48, 52 48 C38 48, 35 38, 35 24 Z" fill="#84CC16" stroke="#4D7C0F" stroke-width="2.5"/>'
              . '<circle cx="44" cy="26" r="6.5" fill="#FFF" stroke="#000" stroke-width="1.5"/>'
              . '<circle cx="44" cy="26" r="2.5" fill="#000"/>'
              . '<circle cx="58" cy="24" r="8.5" fill="#FFF" stroke="#000" stroke-width="1.5"/>'
              . '<circle cx="57" cy="24" r="3.5" fill="#000"/>'
              . '<path d="M42 40 Q50 45 60 38" stroke="#365314" stroke-width="2" fill="none"/>'
              . '<rect x="46" y="38" width="3" height="4" fill="#FFF"/>'
              . '<rect x="52" y="37" width="3" height="4" fill="#FFF"/>'
              . '<g class="pvz-headgear-slot">'
                . '<polygon points="50,-8 34,16 66,16" fill="#F97316" stroke="#C2410C" stroke-width="2"/>'
                . '<ellipse cx="50" cy="16" rx="18" ry="4" fill="#EA580C"/>'
                . '<rect x="42" y="4" width="16" height="3" fill="#FFF" opacity="0.85"/>'
              . '</g>'
            . '</svg>'
          . '</div>'
        . '</div>'
      . '</div>'
    . '</div>';
}
require __DIR__ . '/layout/header.php';

$tuongChonId = $_SESSION['tuong_chon'] ?? 'sunflower';
$tuongHienTai = LoLChampions::layTuong($tuongChonId);
$tatCaTuong = LoLChampions::DANH_SACH;
?>

<div class="timtran-container">
  <?php if ($thongBao): ?>
    <div class="thong-bao-box <?= $thongBao['loai'] === 'ok' ? 'thanhcong' : 'loi' ?>">
      <span class="thong-bao-icon"><?= $thongBao['loai'] === 'ok' ? '✓' : '⚠️' ?></span>
      <span><?= e($thongBao['nd']) ?></span>
    </div>
  <?php endif; ?>

  <?php if ($dangCho): ?>
    <!-- HOLOGRAPHIC RADAR SCANNER SCREEN -->
    <div class="radar-card card" id="radarCard"
         data-ajax-url="<?= e(BASE_URL . '/client/timtran.php?ajax_check=1') ?>"
         data-toggle-bot-url="<?= e(BASE_URL . '/client/timtran.php?ajax_toggle_bot=1') ?>"
         data-reset-timer-url="<?= e(BASE_URL . '/client/timtran.php?ajax_reset_timer=1') ?>"
         data-phong-url="<?= e(BASE_URL . '/client/phong.php?tran=') ?>"
         data-cho-toi-da="<?= (int) $choToiDa ?>"
         data-cho-phep-bot="<?= $choPhepBot ? '1' : '0' ?>">
      <div class="pet-corner tl"></div><div class="pet-corner tr"></div>
      <div class="pet-corner bl"></div><div class="pet-corner br"></div>
      <div class="deck-top-glow-line"></div>
      <?= renderPvzTopBattle() ?>

      <!-- Match Found Flash Overlay -->
      <div class="match-found-overlay" id="matchFoundOverlay" style="display: none;">
        <div class="match-found-modal">
          <div class="match-found-crest">⚔️</div>
          <h3 class="match-found-title">ĐÃ TÌM THẤY ĐỐI THỦ!</h3>
          <p class="match-found-sub">Đấu trường sẵn sàng &middot; Đang tiến vào phòng thi...</p>
        </div>
      </div>

      <!-- PvP Timeout Overlay Modal khi hết 30s ở chế độ người thật -->
      <div class="pvp-timeout-overlay" id="pvpTimeoutOverlay" style="display: none;">
        <div class="pvp-timeout-card">
          <div class="pet-corner tl"></div><div class="pet-corner tr"></div>
          <div class="pet-corner bl"></div><div class="pet-corner br"></div>
          <div class="pvp-timeout-icon">⌛</div>
          <h3 class="pvp-timeout-title">HẾT 30 GIÂY TÌM KIẾM NGƯỜI THẬT</h3>
          <p class="pvp-timeout-desc">
            Hiện chưa có đối thủ người thật nào đang online cùng môn <strong class="text-cyan"><?= e($dangCho['TenMon']) ?></strong> ở mức Elo của bạn.
          </p>
          <div class="pvp-timeout-actions">
            <!-- Nút 1: Tìm tiếp 30s -->
            <button type="button" class="btn btn-pvp-action btn-pvp-retry" id="btnPvpRetry">
              <span class="btn-pvp-icon">⚔️</span>
              <div class="btn-pvp-text">
                <strong>TÌM TIẾP 30 GIÂY NỮA</strong>
                <small>Tiếp tục quét đối thủ người thật trên đấu trường</small>
              </div>
            </button>

            <!-- Nút 2: Đấu với máy ngay -->
            <form method="post" action="" class="form-pvp-action">
              <?= csrf_field() ?>
              <input type="hidden" name="hanh_dong" value="vao_ngay_ai">
              <button type="submit" class="btn btn-pvp-action btn-pvp-bot">
                <span class="btn-pvp-icon">⚡</span>
                <div class="btn-pvp-text">
                  <strong>ĐẤU VỚI ĐẤU THẦN MÁY (BOT)</strong>
                  <small>Vào phòng ngay lập tức với AI thông minh</small>
                </div>
              </button>
            </form>

            <!-- Nút 3: Rút khỏi hàng đợi -->
            <form method="post" action="" class="form-pvp-action">
              <?= csrf_field() ?>
              <input type="hidden" name="hanh_dong" value="huy">
              <button type="submit" class="btn btn-pvp-action btn-pvp-cancel">
                <span class="btn-pvp-icon">✕</span>
                <div class="btn-pvp-text">
                  <strong>RÚT KHỎI HÀNG ĐỢI</strong>
                  <small>Quay về bảng chọn môn thi đấu khác</small>
                </div>
              </button>
            </form>
          </div>
        </div>
      </div>

      <div class="radar-hud-header">
        <span class="radar-hud-tag">HỆ THỐNG GHÉP ĐỐI ESPORTS</span>
        <h2 class="radar-title">ĐANG TÌM KIẾM ĐỐI THỦ XỨNG TẦM</h2>
        <p class="radar-sub">Hệ thống đang rà soát dữ liệu người chơi tương đồng Elo trên toàn đấu trường &middot; Giới hạn 30s</p>
      </div>

      <div class="radar-core-stage">
        <!-- Circular Radar Scanner (Gọn gàng, cân đối 250px) -->
        <div class="radar-visual">
          <div class="radar-ring r1"></div>
          <div class="radar-ring r2"></div>
          <div class="radar-ring r3"></div>
          <div class="radar-crosshair-h"></div>
          <div class="radar-crosshair-v"></div>
          <div class="radar-sweep-beam"></div>
          
          <!-- Selected Champion Pedestal at Center -->
          <div class="radar-champ-center">
            <div class="champ-avatar-glow" style="border-color: <?= e($tuongHienTai['mauSac']) ?>; box-shadow: 0 0 25px <?= e($tuongHienTai['mauSac']) ?>88;">
              <img src="<?= e(BASE_URL . $tuongHienTai['avatar']) ?>" alt="<?= e($tuongHienTai['ten']) ?>" class="champ-radar-img">
            </div>
            <div class="champ-radar-name" style="color: <?= e($tuongHienTai['mauSac']) ?>;"><?= e($tuongHienTai['ten']) ?></div>
            <div class="champ-radar-badge"><?= e($tuongHienTai['vaiTro']) ?></div>
          </div>
        </div>

        <!-- Radar Stats Panel (Dễ nhìn, chữ to, ngang hàng, vừa 1 màn hình) -->
        <div class="radar-stats-panel">
          <!-- Hàng 1: Dải 3 thông số nằm ngang (Môn, Hạng mục, Elo) -->
          <div class="radar-match-info-strip">
            <div class="radar-mini-stat">
              <span class="mini-lbl">MÔN THI ĐẤU</span>
              <strong class="mini-val text-cyan"><?= e($dangCho['TenMon']) ?></strong>
            </div>
            <div class="radar-mini-stat">
              <span class="mini-lbl">HẠNG MỤC KỲ THI</span>
              <strong class="mini-val text-gold"><?= e($dangCho['TenLoaiKT']) ?></strong>
            </div>
            <div class="radar-mini-stat">
              <span class="mini-lbl">ELO HIỆN TẠI</span>
              <strong class="mini-val text-neon-blue"><?= (int) $dangCho['EloTaiThoiDiemCho'] ?> PTS</strong>
            </div>
          </div>

          <!-- Hàng 2: Đồng hồ đếm giờ & Nút đổi chế độ ghép (Side-by-side) -->
          <div class="radar-mid-row">
            <!-- Box đồng hồ 30s -->
            <div class="radar-stat-box highlight-timer">
              <div class="timer-header">
                <span class="stat-lbl">THỜI GIAN HÀNG CHỜ</span>
                <span class="est-badge" id="radarMaxBadge">GIỚI HẠN: 30 GIÂY</span>
              </div>
              <div class="timer-display-wrap">
                <span class="timer-clock-icon">⏱</span>
                <span class="stat-val timer-digits" id="dachoClock"><?= sprintf('%02d:%02d', intdiv((int)$dangCho['DaCho'], 60), (int)$dangCho['DaCho'] % 60) ?></span>
                <span class="timer-max-limit">/ 00:30</span>
                <input type="hidden" id="dachoRaw" value="<?= (int) $dangCho['DaCho'] ?>">
                <input type="hidden" id="choToiDaVal" value="<?= (int) $choToiDa ?>">
              </div>
              <div class="radar-timer-bar-wrap">
                <div class="radar-timer-bar-fill" id="radarTimerFill" style="width: <?= min(100, round(((int)$dangCho['DaCho'] / $choToiDa) * 100)) ?>%;"></div>
              </div>
            </div>

            <!-- Box chế độ tìm trận -->
            <div class="radar-mode-toggle-card">
              <button type="button" class="btn-radar-mode-toggle <?= $choPhepBot ? 'is-bot' : 'is-pvp' ?>" id="btnRadarModeToggle" title="Bấm để chuyển đổi giữa tự ghép máy hoặc chỉ đấu người thật">
                <div class="mode-toggle-top-row">
                  <span class="mode-live-sub">CHẾ ĐỘ TÌM TRẬN</span>
                  <span class="mode-live-btn-tag">BẤM ĐỔI ⇄</span>
                </div>
                <div class="mode-toggle-bottom-row">
                  <span class="mode-live-icon" id="modeLiveIcon"><?= $choPhepBot ? '🤖' : '⚔️' ?></span>
                  <strong class="mode-live-name" id="modeLiveName"><?= $choPhepBot ? 'Tự ghép Máy sau 30s' : 'Chỉ đấu Người thật (PvP)' ?></strong>
                </div>
              </button>
            </div>
          </div>

          <!-- Hàng 3: Live Radar Telemetry Log (Gọn gàng, tinh tế) -->
          <div class="radar-live-log" id="radarLiveLog">
            <div class="log-line active" id="logLine2"><span class="log-bullet pulse"></span> Đang quét đối thủ trong bán kính Elo ±100...</div>
            <div class="log-line mode-info" id="logLineMode">
              <span class="log-bullet" style="background:<?= $choPhepBot ? '#38BDF8' : '#F59E0B' ?>;"></span>
              <span id="logModeText"><?= $choPhepBot ? 'Tự động ghép Đấu Thần Máy nếu sau 30s không có người thật' : 'Chế độ PvP: Chỉ tìm người chơi thật, không ghép Máy' ?></span>
            </div>
            <div class="log-line quote" id="logLineQuote" style="display:none;"><span class="quote-icon">“</span><?= e($tuongHienTai['cauNoi']) ?><span class="quote-icon">”</span></div>
            <div class="log-line" id="logLine1" style="display:none;"><span class="log-bullet"></span> SẴN SÀNG</div>
          </div>

          <!-- Hàng 4: 2 Nút bấm hành động (Ngang hàng nhau, to rõ, cực đẹp) -->
          <div class="radar-action-btns">
            <form method="post" action="" class="form-instant-ai">
              <?= csrf_field() ?>
              <input type="hidden" name="hanh_dong" value="vao_ngay_ai">
              <button type="submit" class="btn btn-instant-ai-btn" title="Không cần chờ, vào thi đấu ngay với Bot AI">
                <span class="instant-ai-icon">⚡</span>
                <span class="instant-ai-text">ĐẤU VỚI MÁY NGAY (AI)</span>
              </button>
            </form>

            <form method="post" action="" class="form-huy-radar">
              <?= csrf_field() ?>
              <input type="hidden" name="hanh_dong" value="huy">
              <button type="submit" class="btn btn-cancel-queue" title="Hủy tìm trận và quay về bảng chọn môn">
                <span class="cancel-icon">✕</span>
                <span class="cancel-text">RÚT KHỎI HÀNG ĐỢI</span>
              </button>
            </form>
          </div>
        </div>
      </div>
    </div>

  <?php else: ?>
    <?php
    // ---- Danh sách môn đang mở (C-16): chọn trong ô thả xuống ----
    $dsMon = $pdo->query("SELECT MaMon, TenMon FROM MONHOC WHERE TrangThai = 'Đang mở' ORDER BY TenMon")->fetchAll();
    $monChon = null;
    foreach ($dsMon as $m) {
        if ((int) $m['MaMon'] === $mon) {
            $monChon = $m;
        }
    }
    $dsKT = [];
    if ($monChon) {
        foreach ($pdo->query('SELECT MaLoaiKT, TenLoaiKT FROM LOAIKYTHI ORDER BY MaLoaiKT')->fetchAll() as $k) {
            $k['SoCau'] = GhepDoi::demCauHoi($pdo, (int) $monChon['MaMon'], (int) $k['MaLoaiKT']);
            $dsKT[] = $k;
        }
    }
    ?>

    <!-- ==========================================================================
         1. VIP PRO ARENA MATCHMAKING DECK (KHUNG TÌM TRẬN GAME CHUYÊN NGHIỆP)
         ========================================================================== -->
    <div class="arena-config-section">
      <div class="arena-card vip-deck card">
        <div class="pet-corner tl"></div><div class="pet-corner tr"></div>
        <div class="pet-corner bl"></div><div class="pet-corner br"></div>
        <div class="deck-top-glow-line"></div>
        <?= renderPvzTopBattle() ?>
        <div class="deck-circuit-bg" aria-hidden="true"></div>

        <div class="vip-deck-body">
          <!-- CỘT TRÁI: BẢNG ĐIỀU KHIỂN TÌM TRẬN (60%) -->
          <div class="deck-left-console">
            <!-- Header -->
            <div class="deck-console-header">
              <div class="deck-emblem-badge" title="QUIZUP ARENA RANKED MATCHMAKING">
                <svg viewBox="0 0 44 44" width="42" height="42" fill="none">
                  <defs>
                    <linearGradient id="vipGoldGrad" x1="0" y1="0" x2="1" y2="1">
                      <stop offset="0%" stop-color="#FFF8E7"/>
                      <stop offset="35%" stop-color="#F3DE8A"/>
                      <stop offset="70%" stop-color="#D4AF37"/>
                      <stop offset="100%" stop-color="#8B6914"/>
                    </linearGradient>
                    <linearGradient id="vipCyanGrad" x1="0" y1="0" x2="0" y2="1">
                      <stop offset="0%" stop-color="#A5F3FC"/>
                      <stop offset="100%" stop-color="#00D9FF"/>
                    </linearGradient>
                  </defs>
                  <!-- Cyber Hexagon Shield -->
                  <polygon points="22,3 38,10 38,30 22,41 6,30 6,10" fill="rgba(8, 20, 56, 0.95)" stroke="url(#vipGoldGrad)" stroke-width="2.2"/>
                  <polygon points="22,8 34,13 34,28 22,36 10,28 10,13" fill="none" stroke="url(#vipCyanGrad)" stroke-width="1.2" stroke-dasharray="3 2"/>
                  <!-- Crossed Swords -->
                  <path d="M14 30 L30 14 M28 12 L32 16 M12 28 L16 32 M11 33 L15 29" stroke="url(#vipGoldGrad)" stroke-width="2.4" stroke-linecap="round"/>
                  <path d="M30 30 L14 14 M12 16 L16 12 M28 32 L32 28 M29 29 L33 33" stroke="url(#vipGoldGrad)" stroke-width="2.4" stroke-linecap="round"/>
                  <circle cx="22" cy="22" r="3.5" fill="url(#vipCyanGrad)" filter="drop-shadow(0 0 6px #00D9FF)"/>
                </svg>
              </div>
              <div class="deck-header-text">
                <div class="deck-rank-tag">
                  <span class="live-dot pulse-red"></span> ARENA RANKED MATCHMAKING &middot; KHỞI TRANH ĐẤU TRƯỜNG
                </div>
                <h2 class="arena-deck-title">VŨ ĐÀI GHÉP ĐÔI TRANH HÙNG</h2>
                <p class="arena-deck-sub">Hệ thống ghép cặp 1 vs 1 công bằng theo điểm Elo &middot; 10 câu hỏi kịch tính</p>
              </div>
            </div>

            <!-- Form Chọn Môn & Hạng Mục Kỳ Thi (Bố Cục Rộng Rãi, Chữ To Rõ Ràng, Không Rối Mắt) -->
            <div class="deck-form-stack">
              <!-- Bước 1: Chọn môn học -->
              <div class="deck-form-group">
                <div class="deck-group-header">
                  <span class="step-num-badge">BƯỚC 01</span>
                  <label for="mon" class="deck-group-title">CHỌN MÔN THI ĐẤU</label>
                  <?php if ($monChon): ?><span class="deck-group-status">✓ Đã chọn môn</span><?php endif; ?>
                </div>
                <form method="get" action="" class="form-arena-select" id="formChonMon">
                  <div class="vip-select-wrapper">
                    <span class="select-icon-svg">
                      <svg viewBox="0 0 24 24" width="24" height="24" fill="none">
                        <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" stroke="#00D9FF" stroke-width="2.2" stroke-linecap="round"/>
                        <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z" fill="rgba(0, 217, 255, 0.16)" stroke="#00D9FF" stroke-width="2.2"/>
                        <path d="M9 7h7M9 11h5" stroke="#F3DE8A" stroke-width="2" stroke-linecap="round"/>
                        <circle cx="16" cy="11" r="1.5" fill="#FDE047"/>
                      </svg>
                    </span>
                    <select name="mon" id="mon" class="cosmic-select vip-select">
                      <option value="">-- Bấm để chọn môn học thi đấu --</option>
                      <?php foreach ($dsMon as $m): ?>
                        <option value="<?= (int) $m['MaMon'] ?>" <?= (int) $m['MaMon'] === $mon ? 'selected' : '' ?>><?= e($m['TenMon']) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <span class="select-chevron-svg">
                      <svg viewBox="0 0 20 20" width="18" height="18" fill="none">
                        <path d="M5 7.5L10 12.5L15 7.5" stroke="#00D9FF" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/>
                      </svg>
                    </span>
                  </div>
                  <noscript><p><button type="submit" class="btn btn-phu">Xác nhận môn</button></p></noscript>
                </form>

                <!-- Gợi ý nhanh các môn -->
                <div class="quick-mon-pills-row">
                  <span class="quick-pick-lbl">Gợi ý nhanh:</span>
                  <?php $topMons = array_slice($dsMon, 0, 4); foreach ($topMons as $tm): ?>
                    <button type="button" class="quick-mon-tag <?= (int) $tm['MaMon'] === $mon ? 'is-active' : '' ?>" data-mon-id="<?= (int) $tm['MaMon'] ?>">
                      <?= e($tm['TenMon']) ?>
                    </button>
                  <?php endforeach; ?>
                </div>
              </div>

              <!-- Bước 2: Chọn loại kỳ thi -->
              <div class="deck-form-group">
                <div class="deck-group-header">
                  <span class="step-num-badge">BƯỚC 02</span>
                  <label for="loaikt" class="deck-group-title">HẠNG MỤC KỲ THI</label>
                  <?php if ($monChon): ?><span class="deck-group-status ready">✓ Sẵn sàng: Trận 10 câu &middot; Hạn chờ 30s</span><?php endif; ?>
                </div>
                <?php if ($monChon): ?>
                  <form method="post" action="" class="form-arena-queue" id="formVaoHang">
                    <?= csrf_field() ?>
                    <input type="hidden" name="hanh_dong" value="vao">
                    <input type="hidden" name="mon" value="<?= (int) $monChon['MaMon'] ?>">
                    <input type="hidden" name="tuong_id" value="<?= e($tuongChonId) ?>">

                    <div class="vip-select-wrapper">
                      <span class="select-icon-svg">
                        <svg viewBox="0 0 24 24" width="24" height="24" fill="none">
                          <path d="M6 4h12v4a6 6 0 0 1-12 0V4z" fill="rgba(212, 175, 55, 0.22)" stroke="#D4AF37" stroke-width="2.2"/>
                          <path d="M6 6H3a2 2 0 0 0-2 2v1a4 4 0 0 0 4 4h1" stroke="#F3DE8A" stroke-width="2"/>
                          <path d="M18 6h3a2 2 0 0 1 2 2v1a4 4 0 0 1-4 4h-1" stroke="#F3DE8A" stroke-width="2"/>
                          <path d="M12 14v4M8 20h8" stroke="#D4AF37" stroke-width="2.4" stroke-linecap="round"/>
                        </svg>
                      </span>
                      <select name="loaikt" id="loaikt" class="cosmic-select vip-select">
                        <?php foreach ($dsKT as $k): ?>
                          <option value="<?= (int) $k['MaLoaiKT'] ?>" <?= $k['SoCau'] < $soCau ? 'disabled' : '' ?>>
                            <?= e($k['TenLoaiKT']) ?> (<?= (int) $k['SoCau'] ?> câu khả dụng<?= $k['SoCau'] < $soCau ? ' - Chưa đủ ' . $soCau . ' câu' : '' ?>)
                          </option>
                        <?php endforeach; ?>
                      </select>
                      <span class="select-chevron-svg">
                        <svg viewBox="0 0 20 20" width="18" height="18" fill="none">
                          <path d="M5 7.5L10 12.5L15 7.5" stroke="#00D9FF" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                      </span>
                    </div>

                    <?php $coThe = array_filter($dsKT, fn($k) => $k['SoCau'] >= $soCau); ?>
                    <?php if (!$coThe): ?>
                      <div class="alert-box-warning">⚠️ Môn này chưa đủ <?= $soCau ?> câu hỏi để thi đấu.</div>
                    <?php endif; ?>
                  </form>
                <?php else: ?>
                  <div class="control-disabled-notice">
                    <span class="lock-icon">🔒</span> Vui lòng chọn môn học ở Bước 1 để mở khóa hạng mục kỳ thi
                  </div>
                <?php endif; ?>
              </div>
            </div>

            <!-- CHẾ ĐỘ GHÉP ĐỐI: DẠNG TAB CHUYỂN ĐỔI ESPORTS HIỆN ĐẠI (KHÔNG CÒN VÒNG TRÒN KÉP, RẤT THOÁNG) -->
            <div class="deck-mode-segment-wrap">
              <div class="mode-segment-label-row">
                <span class="mode-segment-title">CHẾ ĐỘ TÌM ĐỐI THỦ:</span>
                <span class="mode-segment-hint">Tối đa 30 giây</span>
              </div>
              <div class="mode-segmented-control">
                <label class="mode-seg-tab <?= $choPhepBot ? 'active' : '' ?>" id="optModeAutoBot">
                  <input type="radio" name="che_do_ghep" form="formVaoHang" value="auto_bot" <?= $choPhepBot ? 'checked' : '' ?> style="display:none;">
                  <span class="seg-icon">🤖</span>
                  <span class="seg-text">Tự ghép Máy sau 30s</span>
                  <span class="seg-badge">Khuyên dùng</span>
                </label>

                <label class="mode-seg-tab <?= !$choPhepBot ? 'active' : '' ?>" id="optModePvpOnly">
                  <input type="radio" name="che_do_ghep" form="formVaoHang" value="pvp_only" <?= !$choPhepBot ? 'checked' : '' ?> style="display:none;">
                  <span class="seg-icon">⚔️</span>
                  <span class="seg-text">Chỉ đấu Người thật (PvP 100%)</span>
                </label>
              </div>
            </div>

            <!-- HÀNG NÚT BẤM HÀNH ĐỘNG: TÌM TRẬN NHANH & ĐẤU MÁY (TO BẢN, ĐẲNG CẤP) -->
            <div class="deck-cta-block">
              <?php if ($monChon): ?>
                <?php if ($maND === null): ?>
                  <a class="btn-quick-match vip-pro-btn" href="<?= e(BASE_URL) ?>/client/login.php">
                    <span class="btn-light-sweep"></span>
                    <span>ĐĂNG NHẬP ĐỂ TÌM TRẬN →</span>
                  </a>
                <?php else: ?>
                  <?php $coThe = array_filter($dsKT, fn($k) => $k['SoCau'] >= $soCau); ?>
                  <div class="deck-buttons-row">
                    <button type="submit" form="formVaoHang" class="btn-quick-match vip-pro-btn" id="btnSubmitQuickMatch" <?= $coThe ? '' : 'disabled' ?>>
                      <span class="btn-light-sweep"></span>
                      <span class="btn-sword-svg">
                        <svg viewBox="0 0 24 24" width="24" height="24" fill="none">
                          <path d="M14.5 17.5L3 6V3h3l11.5 11.5M13 19l2 2 4-4-2-2" stroke="#081436" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>
                          <path d="M9.5 6.5L18 15" stroke="#081436" stroke-width="2" stroke-linecap="round"/>
                          <path d="M16 3h5v5l-4 4-5-5 4-4z" fill="#081436"/>
                        </svg>
                      </span>
                      <span>TÌM TRẬN NHANH (QUICK MATCH) →</span>
                    </button>

                    <button type="submit" form="formVaoHang" name="dau_bot" value="1" class="btn-direct-bot-pill" <?= $coThe ? '' : 'disabled' ?> title="Vào thi đấu ngay lập tức với Đấu Thần Máy">
                      <span>⚡ ĐẤU VỚI MÁY NGAY</span>
                    </button>
                  </div>
                <?php endif; ?>
              <?php else: ?>
                <button type="button" class="btn-quick-match vip-pro-btn disabled" disabled>
                  <span>HÃY CHỌN MÔN ĐỂ TÌM TRẬN</span>
                </button>
              <?php endif; ?>
            </div>
          </div>

          <!-- CỘT PHẢI: SÂN KHẤU HOLOGAM TƯỚNG 3D VIP PRO (GỌN GÀNG, SANG TRỌNG, KHÔNG RỐI MẮT) -->
          <div class="deck-right-stage" style="--champ-color: <?= e($tuongHienTai['mauSac']) ?>;">
            <!-- Vầng hào quang phát sáng phía sau -->
            <div class="stage-aura" style="background: radial-gradient(circle, <?= e($tuongHienTai['mauSac']) ?>55 0%, transparent 70%);"></div>

            <!-- Khung Tranh Hologram Tướng -->
            <div class="stage-champ-card">
              <div class="pet-corner tl"></div><div class="pet-corner tr"></div>
              <div class="pet-corner bl"></div><div class="pet-corner br"></div>

              <!-- Avatar Tướng To, Nét & Có Đèn Viền Đẹp -->
              <div class="stage-avatar-container">
                <img src="<?= e(BASE_URL . $tuongHienTai['avatar']) ?>" alt="<?= e($tuongHienTai['ten']) ?>" class="stage-champ-avatar">
              </div>

              <!-- Tên và Danh Hiệu Tướng -->
              <div class="stage-champ-caption">
                <span class="stage-champ-faction-pill <?= $tuongHienTai['phe'] === 'plant' ? 'is-plant' : 'is-zombie' ?>">
                  <?= $tuongHienTai['pheIcon'] ?> <?= e($tuongHienTai['pheTen']) ?>
                </span>
                <h3 class="stage-champ-name" style="color: <?= e($tuongHienTai['mauSac']) ?>;"><?= e($tuongHienTai['ten']) ?></h3>
                <span class="stage-champ-title"><?= e($tuongHienTai['danhHieu']) ?></span>
                <div class="stage-champ-buff-badge">
                  <span class="buff-icon">⚡</span>
                  <strong><?= e($tuongHienTai['tacDungMatch']) ?></strong>
                </div>
                <div class="stage-champ-role"><?= e($tuongHienTai['vaiTro']) ?> &middot; <?= e($tuongHienTai['monHoc']) ?></div>
              </div>

              <!-- Nút đổi Tướng -->
              <a href="#champSelectSection" class="btn-change-champ-stage" id="btnChangeChamp">
                <span>ĐỔI ĐẤU THẦN KHÁC ▾</span>
              </a>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ==========================================================================
         2. CHAMPION SELECTION SECTION (CHỌN ĐẤU THẦN PLANTS VS ZOMBIES 3D)
         ========================================================================== -->
    <div class="champ-select-section" id="champSelectSection">
      <div class="section-badge-wrap">
        <span class="section-badge">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" style="vertical-align: text-bottom; margin-right: 4px;">
            <path d="M14.5 17.5L3 6V3h3l11.5 11.5M13 19l2 2 4-4-2-2" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
            <path d="M9.5 6.5L18 15" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
          </svg>
          PLANTS VS ZOMBIES &middot; ĐẤU THẦN 3D BẢN MỆNH
        </span>
        <h2 class="section-title">CHỌN ĐẤU THẦN XUẤT TRẬN</h2>
        <p class="section-desc">Mỗi Đấu Thần sở hữu một năng lực chuyên biệt hỗ trợ tìm trận và chiến đấu. Chọn phe Cây Thủ 🌱 hoặc phe Thây Ma 🧟 để đồng hành!</p>

        <!-- Bộ Lọc Phe Phái -->
        <div class="champ-faction-filters">
          <button type="button" class="btn-faction-filter active" data-filter="all">TẤT CẢ (6 ĐẤU THẦN)</button>
          <button type="button" class="btn-faction-filter" data-filter="plant">🌱 PHE CÂY THỦ (3 CÂY)</button>
          <button type="button" class="btn-faction-filter" data-filter="zombie">🧟 PHE THÂY MA (3 ZOMBIE)</button>
        </div>
      </div>

      <div class="champ-grid">
        <?php foreach ($tatCaTuong as $cid => $c): ?>
          <?php $isChon = ($cid === $tuongChonId); ?>
          <div class="champ-card <?= $isChon ? 'is-selected' : '' ?> faction-<?= e($c['phe']) ?>" style="--champ-color: <?= e($c['mauSac']) ?>;" data-champ-id="<?= e($cid) ?>" data-faction="<?= e($c['phe']) ?>">
            <div class="pet-corner tl"></div><div class="pet-corner tr"></div>
            <div class="pet-corner bl"></div><div class="pet-corner br"></div>

            <div class="champ-card-inner">
              <!-- Avatar Circle 3D -->
              <div class="champ-avatar-wrap">
                <div class="champ-avatar-ring">
                  <img src="<?= e(BASE_URL . $c['avatar']) ?>" alt="<?= e($c['ten']) ?>" class="champ-avatar-img">
                </div>
                <?php if ($isChon): ?>
                  <span class="champ-status-badge selected-badge">✓ ĐÃ XUẤT TRẬN</span>
                <?php endif; ?>
              </div>

              <!-- Champ Info -->
              <div class="champ-details">
                <div class="champ-header-row">
                  <span class="champ-faction-tag <?= $c['phe'] === 'plant' ? 'is-plant' : 'is-zombie' ?>">
                    <?= $c['pheIcon'] ?> <?= e($c['pheTen']) ?>
                  </span>
                  <h3 class="champ-name"><?= e($c['ten']) ?></h3>
                  <span class="champ-title-tag"><?= e($c['danhHieu']) ?></span>
                </div>

                <!-- Buff Highlight -->
                <div class="champ-buff-showcase">
                  <div class="buff-showcase-item">
                    <span class="buff-lbl match-lbl">⚡ TÌM TRẬN:</span>
                    <span class="buff-val"><?= e($c['tacDungMatch']) ?></span>
                  </div>
                  <div class="buff-showcase-item">
                    <span class="buff-lbl arena-lbl">⚔️ TRONG PHÒNG:</span>
                    <span class="buff-val"><?= e($c['tacDungArena']) ?></span>
                  </div>
                </div>

                <div class="champ-role-row">
                  <span class="role-pill"><?= e($c['vaiTro']) ?></span>
                  <span class="subject-pill">
                    <svg viewBox="0 0 20 20" width="14" height="14" fill="none" style="vertical-align: text-bottom; margin-right: 3px;">
                      <path d="M3 16.5A1.5 1.5 0 0 1 4.5 15H17" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                      <path d="M4.5 2H17v16H4.5A1.5 1.5 0 0 1 3 16.5v-13A1.5 1.5 0 0 1 4.5 2z" stroke="currentColor" stroke-width="1.8"/>
                    </svg>
                    <?= e($c['monHoc']) ?>
                  </span>
                </div>

                <!-- Skill Badges -->
                <div class="champ-skills-box">
                  <div class="skill-item"><span class="key-badge">Q</span> <span class="skill-name"><?= e($c['chieuQ']) ?></span></div>
                  <div class="skill-item"><span class="key-badge ult-badge">R</span> <span class="skill-name"><?= e($c['chieuR']) ?></span></div>
                </div>

                <!-- Quote -->
                <p class="champ-quote">“<?= e($c['cauNoi']) ?>”</p>

                <!-- Action Button -->
                <form method="post" action="" class="form-chon-tuong">
                  <?= csrf_field() ?>
                  <input type="hidden" name="hanh_dong" value="chon_tuong">
                  <input type="hidden" name="tuong_id" value="<?= e($cid) ?>">
                  <?php if ($isChon): ?>
                    <button type="button" class="btn-select-champ active" disabled>
                      <span class="btn-glow-icon">★</span> ĐANG ĐỒNG HÀNH
                    </button>
                  <?php else: ?>
                    <button type="submit" class="btn-select-champ">
                      <span>XUẤT CHIẾN CÙNG ĐẤU THẦN NÀY →</span>
                    </button>
                  <?php endif; ?>
                </form>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- PROPOSE NEW SUBJECT (ĐỀ XUẤT MÔN) -->
    <?php
    $choDuyet = [];
    if ($maND !== null) {
        $st = $pdo->prepare("SELECT TenMon FROM MONHOC WHERE MaNguoiDeXuat = ? AND TrangThai = 'Chờ duyệt' ORDER BY MaMon");
        $st->execute([$maND]);
        $choDuyet = $st->fetchAll(PDO::FETCH_COLUMN);
    }
    ?>
    <div class="card propose-card">
      <div class="pet-corner tl"></div><div class="pet-corner tr"></div>
      <div class="pet-corner bl"></div><div class="pet-corner br"></div>

      <?php if ($maND === null): ?>
        <p class="propose-guest-text">Không thấy môn của bạn? <a href="<?= e(BASE_URL) ?>/client/login.php" class="link-gold">Đăng nhập</a> để đề xuất môn mới cho ban quản trị duyệt.</p>
      <?php else: ?>
        <details <?= $dsMon ? '' : 'open' ?> class="propose-details">
          <summary class="propose-summary">
            <span class="propose-icon">💡</span>
            <strong>Không thấy môn của bạn? Đề xuất môn thi đấu mới</strong>
            <span class="summary-arrow">▼</span>
          </summary>
          <form method="post" action="" class="propose-form">
            <?= csrf_field() ?>
            <input type="hidden" name="hanh_dong" value="dexuat">
            
            <div class="form-group-cosmic">
              <label for="ten_mon" class="cosmic-label">Tên môn học đề xuất</label>
              <input type="text" id="ten_mon" name="ten_mon" maxlength="150" placeholder="VD: Lập Trình Python, Hóa Học Hữu Cơ..." class="cosmic-input" required>
            </div>

            <div class="form-group-cosmic">
              <label for="mo_ta" class="cosmic-label">Mô tả chi tiết (không bắt buộc)</label>
              <textarea id="mo_ta" name="mo_ta" rows="2" maxlength="500" placeholder="Mô tả phạm vi kiến thức hoặc câu hỏi phù hợp..." class="cosmic-textarea"></textarea>
            </div>

            <div class="propose-actions">
              <button type="submit" class="btn btn-phu btn-propose">
                <span>GỬI ĐỀ XUẤT MÔN</span>
              </button>
              <span class="propose-hint">Môn được Quản trị viên duyệt sẽ tự động xuất hiện trên vũ đài thi đấu.</span>
            </div>
          </form>
        </details>
      <?php endif; ?>

      <?php if ($choDuyet): ?>
        <div class="pending-proposals">
          <span class="pending-lbl">Đề xuất đang chờ duyệt:</span>
          <span class="pending-list"><?= e(implode(', ', $choDuyet)) ?></span>
        </div>
      <?php endif; ?>
    </div>

  <?php endif; ?>
</div>

<!-- MATCH FOUND MODAL (LOL STYLE) -->
<div id="matchFoundModal" class="match-found-modal" style="display: none;">
  <div class="match-found-backdrop"></div>
  <div class="match-found-dialog">
    <div class="pet-corner tl"></div><div class="pet-corner tr"></div>
    <div class="pet-corner bl"></div><div class="pet-corner br"></div>

    <div class="modal-gold-crest">⚔️</div>
    <div class="modal-sub-tag">ESPORTS ARENA &middot; ĐÃ TÌM THẤY ĐỐI THỦ</div>
    <h2 class="modal-main-title">TRẬN ĐẤU SẴN SÀNG!</h2>
    <p class="modal-desc">Đấu Thần đối phương đã tiến vào sàn đấu. Bấm chấp thuận để bắt đầu cuộc chiến!</p>

    <!-- Ring Countdown Timer -->
    <div class="accept-countdown-box">
      <svg class="countdown-svg" viewBox="0 0 100 100">
        <circle class="countdown-bg-circle" cx="50" cy="50" r="42"></circle>
        <circle class="countdown-bar-circle" cx="50" cy="50" r="42" id="countdownCircle"></circle>
      </svg>
      <div class="countdown-num" id="countdownSec">10</div>
    </div>

    <!-- Match Accept Button -->
    <button type="button" class="btn-accept-match" id="btnAcceptMatch">
      <span class="accept-btn-glow"></span>
      <span class="accept-btn-text">CHẤP THUẬN (ACCEPT)</span>
    </button>
  </div>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>