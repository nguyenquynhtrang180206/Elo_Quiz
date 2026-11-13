<?php
// Tìm trận: chọn môn trong danh sách, chọn loại kỳ thi, vào / hủy hàng đợi (C-16 đến C-19, C-22).
// Không thấy môn của mình thì được đề xuất môn mới để quản trị viên duyệt (cần chạy them_de_xuat_mon.sql).
// Mỗi lần trang chờ tải lại thì gọi GhepDoi::thuGhep() (C-20); ghép được hoặc đã có trận thì chuyển sang phong.php.
// Đặt tại: elo_quiz/client/timtran.php
require_once __DIR__ . '/../core/bootstrap.php';
if (Auth::laAdminSinhVien()) {   // admin không tìm trận và không đấu trận
    redirect(BASE_URL . '/client/khong_dau_tran.php');
}
require_once __DIR__ . '/../core/GhepDoi.php';
require_once __DIR__ . '/../core/LoLChampions.php';
require_once __DIR__ . '/../core/CongDongHelper.php';

$maND = Auth::clientId();   // khách chưa đăng nhập vẫn xem được môn/kỳ thi; vào hàng đợi và đề xuất môn cần đăng nhập
$pdo  = Database::pdo();

// ---- Cấu hình cốt lõi cần cho cả AJAX polling & render trang ----
$choToiDa  = max(1, Config::int('THOI_GIAN_CHO_GHEP_TOI_DA', 60));
$nguongElo = max(1, Config::int('NGUONG_ELO_GHEP_DOI', 100));

// Tùy chọn tìm trận lưu bền vững trong NGUOIDUNG (session chỉ là bộ nhớ đệm)
if ($maND !== null && !isset($_SESSION['tuy_chon_nap'])) {
    $stTC = $pdo->prepare('SELECT TuongChon, ChoPhepBot FROM NGUOIDUNG WHERE MaND = ?');
    $stTC->execute([$maND]);
    if ($tc = $stTC->fetch()) {
        $_SESSION['tuong_chon']   = (string) $tc['TuongChon'];
        $_SESSION['cho_phep_bot'] = (bool) $tc['ChoPhepBot'];
    }
    $_SESSION['tuy_chon_nap'] = true;
}
function luuTuyChonTimTran(PDO $pdo, ?int $maND): void
{
    if ($maND === null) {
        return;
    }
    $pdo->prepare('UPDATE NGUOIDUNG SET TuongChon = ?, ChoPhepBot = ? WHERE MaND = ?')
        ->execute([(string) ($_SESSION['tuong_chon'] ?? 'sunflower'), !empty($_SESSION['cho_phep_bot']) ? 1 : 0, $maND]);
}
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

// ---- Dọn dòng chờ quá hạn (quá hạn chờ không tương tác thì dọn dẹp hàng đợi tránh rác) ----
if ($maND !== null) {
    $st = $pdo->prepare('DELETE FROM HANGDOIGHEPDOI WHERE MaND = ? AND ThoiGianVaoHang < DATE_SUB(NOW(), INTERVAL ? SECOND)');
    $st->execute([$maND, max($choToiDa * 3, 90)]);
}

$mon = (int) ($_GET['mon'] ?? 0);

// ---- AJAX: Bật / Tắt chế độ tự ghép máy khi đang chờ ----
if (isset($_GET['ajax_toggle_bot'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $current = isset($_SESSION['cho_phep_bot']) ? (bool) $_SESSION['cho_phep_bot'] : true;
    $_SESSION['cho_phep_bot'] = !$current;
    luuTuyChonTimTran($pdo, $maND);
    echo json_encode([
        'ok' => true,
        'choPhepBot' => $_SESSION['cho_phep_bot'],
        'cheDo' => $_SESSION['cho_phep_bot'] ? 'auto_bot' : 'pvp_only',
        'nhan' => $_SESSION['cho_phep_bot'] ? ('Tự ghép Máy sau ' . $choToiDa . 's') : 'Chỉ đấu Người thật (PvP)'
    ]);
    exit;
}

// ---- AJAX: Khởi động lại bộ đếm khi tìm tiếp PvP ----
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

// ---- AJAX: Người chơi bấm chấp nhận Sẵn sàng (Ready Check 10s) ----
if (isset($_GET['ajax_ready_accept']) || isset($_POST['ajax_ready_accept'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $tranId = (int) ($_POST['tran'] ?? $_GET['tran'] ?? 0);
    if ($maND !== null && $tranId > 0) {
        $pdo->prepare("UPDATE NGUOICHOITRANDAU SET TrangThaiThamGia = 'Đang chơi', LanHoatDongCuoi = NOW(3) WHERE MaTran = ? AND MaND = ?")
            ->execute([$tranId, $maND]);
        echo json_encode(['ok' => true, 'toiSanSang' => true]);
        exit;
    }
    echo json_encode(['ok' => false]);
    exit;
}

// ---- AJAX: Tạo phòng thách đấu bạn bè 1v1 (Mã PIN) ----
if (isset($_GET['ajax_thachdau_tao']) || isset($_POST['ajax_thachdau_tao'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $maMon = (int) ($_POST['mon'] ?? $_GET['mon'] ?? 1);
    $maKT  = (int) ($_POST['loaikt'] ?? $_GET['loaikt'] ?? 2);
    if ($maND !== null) {
        $kq = ThachDau::taoPhong($pdo, $maND, $maMon, $maKT);
        echo json_encode($kq);
        exit;
    }
    echo json_encode(['ok' => false, 'loi' => 'Chưa đăng nhập.']);
    exit;
}

// ---- AJAX: Khách nhập mã PIN vào phòng thách đấu 1v1 ----
if (isset($_GET['ajax_thachdau_vao']) || isset($_POST['ajax_thachdau_vao'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $pin = trim((string) ($_POST['pin'] ?? $_GET['pin'] ?? ''));
    if ($maND !== null && $pin !== '') {
        $kq = ThachDau::vaoPhong($pdo, $maND, $pin);
        echo json_encode($kq);
        exit;
    }
    echo json_encode(['ok' => false, 'loi' => 'Vui lòng nhập mã PIN hợp lệ.']);
    exit;
}

// ---- AJAX: Chủ phòng kiểm tra khách vào phòng thách đấu ----
if (isset($_GET['ajax_thachdau_kiemtra'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $pin = trim((string) ($_GET['pin'] ?? ''));
    if ($maND !== null && $pin !== '') {
        $kq = ThachDau::kiemTraPhongChu($pdo, $maND, $pin);
        echo json_encode($kq);
        exit;
    }
    echo json_encode(['tonTai' => false, 'daGhep' => false]);
    exit;
}

// ---- AJAX: Hủy phòng thách đấu ----
if (isset($_GET['ajax_thachdau_huy']) || isset($_POST['ajax_thachdau_huy'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $pin = trim((string) ($_POST['pin'] ?? $_GET['pin'] ?? ''));
    if ($maND !== null) {
        ThachDau::huyPhong($pdo, $maND, $pin !== '' ? $pin : null);
        echo json_encode(['ok' => true]);
        exit;
    }
    echo json_encode(['ok' => false]);
    exit;
}

// ---- Dữ liệu chi tiết cho màn hình Chấp nhận trận & VS Showdown Esports ----
function layDuLieuShowdown(PDO $pdo, int $maTran, int $maND): array
{
    $stOpp = $pdo->prepare(
        "SELECT n.MaND, n.EloTruoc, n.TrangThaiThamGia, n.LanHoatDongCuoi, u.HoTen, u.AnhDaiDien,
                e.DiemElo AS EloMon,
                t.MaMon, t.MaLoaiKT, t.TrangThai AS TrangThaiTran,
                TIMESTAMPDIFF(SECOND, (SELECT MIN(x.LanHoatDongCuoi) FROM NGUOICHOITRANDAU x WHERE x.MaTran = t.MaTran), NOW(3)) AS GiayTuLucTao,
                m.TenMon, l.TenLoaiKT
           FROM NGUOICHOITRANDAU n
           JOIN TRANDAU t ON t.MaTran = n.MaTran
           JOIN NGUOIDUNG u ON u.MaND = n.MaND
           JOIN MONHOC m ON m.MaMon = t.MaMon
           JOIN LOAIKYTHI l ON l.MaLoaiKT = t.MaLoaiKT
           LEFT JOIN ELOMONHOC e ON e.MaND = n.MaND AND e.MaMon = t.MaMon
          WHERE n.MaTran = ?"
    );
    $stOpp->execute([$maTran]);
    $players = $stOpp->fetchAll();

    if (!$players) {
        return ['coTran' => false, 'dangCho' => false];
    }

    $toiPlayer = null;
    $doiThuPlayer = null;
    foreach ($players as $pRow) {
        if ((int) $pRow['MaND'] === $maND) {
            $toiPlayer = $pRow;
        } else {
            $doiThuPlayer = $pRow;
        }
    }

    $tuongChonId = $_SESSION['tuong_chon'] ?? 'sunflower';
    $tuongToi = LoLChampions::layTuong($tuongChonId);
    $thongThaoToi = LoLChampions::layThongThao($pdo, $maND, $tuongChonId);

    $tuongKeys = array_keys(LoLChampions::DANH_SACH);
    $doiThuTuongId = 'buckethead';
    $laBot = false;
    if ($doiThuPlayer) {
        $laBot = GhepDoi::laBot($pdo, (int) $doiThuPlayer['MaND']);
        if ($laBot) {
            if (mb_stripos($doiThuPlayer['HoTen'], 'Zomboss') !== false) {
                $doiThuTuongId = 'drzomboss';
            } elseif (mb_stripos($doiThuPlayer['HoTen'], 'Flag') !== false) {
                $doiThuTuongId = 'flagzombie';
            } elseif (mb_stripos($doiThuPlayer['HoTen'], 'Băng') !== false) {
                $doiThuTuongId = 'snowpea';
            } elseif (mb_stripos($doiThuPlayer['HoTen'], 'Hướng Dương') !== false) {
                $doiThuTuongId = 'sunflower';
            } elseif (mb_stripos($doiThuPlayer['HoTen'], 'Óc Chó') !== false) {
                $doiThuTuongId = 'wallnut';
            } else {
                $doiThuTuongId = 'buckethead';
            }
        } else {
            $otherKeys = array_values(array_filter($tuongKeys, fn($k) => $k !== $tuongChonId));
            $doiThuTuongId = !empty($otherKeys) ? $otherKeys[array_rand($otherKeys)] : 'buckethead';
        }
    }
    $tuongDoiThu = LoLChampions::layTuong($doiThuTuongId);
    $doiThuND = $doiThuPlayer ? (int) $doiThuPlayer['MaND'] : 0;
    $thongThaoDoiThu = LoLChampions::layThongThao($pdo, $doiThuND, $doiThuTuongId);

    $toiSanSang = ($toiPlayer && $toiPlayer['TrangThaiThamGia'] === 'Đang chơi');
    $doiThuSanSang = ($doiThuPlayer && ($doiThuPlayer['TrangThaiThamGia'] === 'Đang chơi' || $laBot));
    $trangThaiTran = $players[0]['TrangThaiTran'] ?? 'Đã ghép';

    $giayTuLucTao = max(0, (int) ($players[0]['GiayTuLucTao'] ?? 0));
    $conLai = max(0, 10 - $giayTuLucTao);

    // 1. Nếu cả 2 đều đã sẵn sàng: Bắt đầu trận đấu
    if ($toiSanSang && $doiThuSanSang) {
        if ($trangThaiTran === 'Đã ghép') {
            $pdo->prepare("UPDATE TRANDAU SET TrangThai = 'Đang diễn ra', ThoiGianBatDau = NOW(3) WHERE MaTran = ? AND TrangThai = 'Đã ghép'")
                ->execute([$maTran]);
            $trangThaiTran = 'Đang diễn ra';
        }
        return [
            'coTran'        => true,
            'readyCheck'    => false,
            'caHaiSanSang'  => true,
            'toiSanSang'    => true,
            'doiThuSanSang' => true,
            'maTran'        => $maTran,
            'phongUrl'      => BASE_URL . '/client/phong.php?tran=' . $maTran,
            'toi' => [
                'ten' => $toiPlayer ? $toiPlayer['HoTen'] : 'Bạn',
                'elo' => (int) ($toiPlayer['EloMon'] ?? $toiPlayer['EloTruoc'] ?? 1000),
                'tuongId' => $tuongToi['id'],
                'tuongTen' => $tuongToi['ten'],
                'tuongAvatar' => BASE_URL . $tuongToi['avatar'],
                'mau' => $tuongToi['mauSac'],
                'cauNoi' => $tuongToi['cauNoi'],
                'capDo' => $thongThaoToi['capDo'],
                'danhHieuCap' => $thongThaoToi['danhHieuCap'],
                'iconCap' => $thongThaoToi['iconCap'],
                'cssClass' => $thongThaoToi['cssClass'],
            ],
            'doiThu' => [
                'ten' => $doiThuPlayer ? $doiThuPlayer['HoTen'] : 'Đối thủ bí ẩn',
                'elo' => (int) ($doiThuPlayer['EloMon'] ?? $doiThuPlayer['EloTruoc'] ?? 1000),
                'tuongId' => $tuongDoiThu['id'],
                'tuongTen' => $tuongDoiThu['ten'],
                'tuongAvatar' => BASE_URL . $tuongDoiThu['avatar'],
                'mau' => $tuongDoiThu['mauSac'],
                'cauNoi' => $tuongDoiThu['cauNoi'],
                'capDo' => $thongThaoDoiThu['capDo'],
                'danhHieuCap' => $thongThaoDoiThu['danhHieuCap'],
                'iconCap' => $thongThaoDoiThu['iconCap'],
                'cssClass' => $thongThaoDoiThu['cssClass'],
            ],
            'tenMon' => $toiPlayer ? $toiPlayer['TenMon'] : '',
            'tenLoaiKT' => $toiPlayer ? $toiPlayer['TenLoaiKT'] : '',
        ];
    }

    // 2. Nếu đã quá thời gian 10s Ready Check mà chưa cả 2 cùng sẵn sàng (Phát hiện AFK)
    if ($giayTuLucTao >= 11 && $trangThaiTran === 'Đã ghép') {
        if (!$toiSanSang) {
            AfkPenalty::phat($maND, 180);
        }
        if (!$doiThuSanSang && !$laBot && $doiThuND > 0) {
            AfkPenalty::phat($doiThuND, 180);
        }

        // Đưa người đã bấm sẵn sàng trở lại hàng đợi ưu tiên
        if ($toiSanSang) {
            GhepDoi::vaoHangUuTien($pdo, $maND, (int) $players[0]['MaMon'], (int) $players[0]['MaLoaiKT'], (int) ($toiPlayer['EloMon'] ?? 1000));
        }

        GhepDoi::huyTran($pdo, $maTran, 'Hủy do không vào phòng');

        if (!$toiSanSang) {
            return [
                'coTran'       => false,
                'afkPhat'      => true,
                'thoiGianPhat' => 180,
                'thongBao'     => 'Bạn đã không xác nhận sẵn sàng đúng giờ! Tài khoản bị tạm khóa tìm trận trong 3 phút.'
            ];
        } else {
            return [
                'coTran'    => false,
                'dangCho'   => true,
                'doiThuAfk' => true,
                'thongBao'  => 'Đối thủ không sẵn sàng. Bạn đã được tự động đưa trở lại hàng đợi ưu tiên!'
            ];
        }
    }

    // 3. Vẫn đang trong thời gian 10s chờ cả 2 bấm xác nhận
    return [
        'coTran'        => true,
        'readyCheck'    => true,
        'caHaiSanSang'  => false,
        'conLai'        => $conLai,
        'toiSanSang'    => $toiSanSang,
        'doiThuSanSang' => $doiThuSanSang,
        'maTran'        => $maTran,
        'phongUrl'      => BASE_URL . '/client/phong.php?tran=' . $maTran,
        'toi' => [
            'ten' => $toiPlayer ? $toiPlayer['HoTen'] : 'Bạn',
            'elo' => (int) ($toiPlayer['EloMon'] ?? $toiPlayer['EloTruoc'] ?? 1000),
            'tuongId' => $tuongToi['id'],
            'tuongTen' => $tuongToi['ten'],
            'tuongAvatar' => BASE_URL . $tuongToi['avatar'],
            'mau' => $tuongToi['mauSac'],
            'cauNoi' => $tuongToi['cauNoi'],
            'capDo' => $thongThaoToi['capDo'],
            'danhHieuCap' => $thongThaoToi['danhHieuCap'],
            'iconCap' => $thongThaoToi['iconCap'],
            'cssClass' => $thongThaoToi['cssClass'],
        ],
        'doiThu' => [
            'ten' => $doiThuPlayer ? $doiThuPlayer['HoTen'] : 'Đối thủ bí ẩn',
            'elo' => (int) ($doiThuPlayer['EloMon'] ?? $doiThuPlayer['EloTruoc'] ?? 1000),
            'tuongId' => $tuongDoiThu['id'],
            'tuongTen' => $tuongDoiThu['ten'],
            'tuongAvatar' => BASE_URL . $tuongDoiThu['avatar'],
            'mau' => $tuongDoiThu['mauSac'],
            'cauNoi' => $tuongDoiThu['cauNoi'],
            'capDo' => $thongThaoDoiThu['capDo'],
            'danhHieuCap' => $thongThaoDoiThu['danhHieuCap'],
            'iconCap' => $thongThaoDoiThu['iconCap'],
            'cssClass' => $thongThaoDoiThu['cssClass'],
        ],
        'tenMon' => $toiPlayer ? $toiPlayer['TenMon'] : '',
        'tenLoaiKT' => $toiPlayer ? $toiPlayer['TenLoaiKT'] : '',
    ];
}

// ---- AJAX Polling kiểm tra tìm trận mượt mà không load lại trang ----
if (isset($_GET['ajax_check'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if ($maND === null) {
        echo json_encode(['coTran' => false, 'dangCho' => false]);
        exit;
    }

    // 0. Kiểm tra án phạt cấm tìm trận (AFK Penalty)
    $afk = AfkPenalty::kiemTra($maND);
    if ($afk['biPhat']) {
        echo json_encode([
            'coTran'   => false,
            'dangCho'  => false,
            'afkPhat'  => true,
            'afkCon'   => (int) $afk['conLai'],
            'thongBao' => 'Bạn đang bị phạt cấm tìm trận do không bấm sẵn sàng (AFK). Còn lại: ' . (int) $afk['conLai'] . 's.'
        ]);
        exit;
    }

    // 1. Kiểm tra đã có trận đang diễn ra chưa
    $maTran = GhepDoi::tranDangCo($pdo, $maND);
    if ($maTran !== null) {
        echo json_encode(layDuLieuShowdown($pdo, $maTran, $maND));
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

    // Tùy chọn tìm trận lưu bền vững trong NGUOIDUNG (session chỉ là bộ nhớ đệm)
if ($maND !== null && !isset($_SESSION['tuy_chon_nap'])) {
    $stTC = $pdo->prepare('SELECT TuongChon, ChoPhepBot FROM NGUOIDUNG WHERE MaND = ?');
    $stTC->execute([$maND]);
    if ($tc = $stTC->fetch()) {
        $_SESSION['tuong_chon']   = (string) $tc['TuongChon'];
        $_SESSION['cho_phep_bot'] = (bool) $tc['ChoPhepBot'];
    }
    $_SESSION['tuy_chon_nap'] = true;
}
function luuTuyChonTimTran(PDO $pdo, ?int $maND): void
{
    if ($maND === null) {
        return;
    }
    $pdo->prepare('UPDATE NGUOIDUNG SET TuongChon = ?, ChoPhepBot = ? WHERE MaND = ?')
        ->execute([(string) ($_SESSION['tuong_chon'] ?? 'sunflower'), !empty($_SESSION['cho_phep_bot']) ? 1 : 0, $maND]);
}
$choPhepBot = isset($_SESSION['cho_phep_bot']) ? (bool) $_SESSION['cho_phep_bot'] : true;

    // 3. Nếu là PvP thuần túy (không ghép bot) và đã đủ thời gian chờ tối đa:
    if (!$choPhepBot && (int) $daCho >= $choToiDa) {
        echo json_encode([
            'coTran' => false,
            'dangCho' => true,
            'pvpTimeout' => true,
            'daCho' => (int) $daCho,
            'choToiDa' => $choToiDa,
            'nguongElo' => $nguongElo,
            'choPhepBot' => false
        ]);
        exit;
    }

    // 4. Thử ghép đôi (quét người thật, nếu choPhepBot=true và đủ thời gian chờ thì tự ghép Đấu Thần Máy)
    $maTranMoi = GhepDoi::thuGhep($pdo, $maND, $choPhepBot);
    if ($maTranMoi !== null) {
        echo json_encode(layDuLieuShowdown($pdo, $maTranMoi, $maND));
        exit;
    }
    echo json_encode([
        'coTran' => false,
        'dangCho' => true,
        'pvpTimeout' => false,
        'daCho' => (int) $daCho,
        'choToiDa' => $choToiDa,
        'nguongElo' => $nguongElo,
        'choPhepBot' => $choPhepBot
    ]);
    exit;
}

// ---- Cấu hình luật chơi & tính điểm (Chỉ nạp khi không phải request AJAX polling) ----
$soCau             = max(1, Config::int('SO_CAU', 10));
$thoiGianMoiCau    = max(1, Config::int('THOI_GIAN_MOI_CAU', 15));
$diemCoBan         = Config::int('DIEM_CO_BAN', 10);
$heSoThuong        = Config::int('HE_SO_THUONG', 10);
$thoiGianVaoPhong  = max(1, Config::int('THOI_GIAN_CHO_VAO_PHONG', 15));
$thoiGianMatKetNoi = max(1, Config::int('THOI_GIAN_CHO_PHEP_MAT_KET_NOI', 30));
$dungSaiDoTre      = Config::int('DUNG_SAI_DO_TRE', 3);
$eloKhoiTao        = Config::int('ELO_KHOI_TAO', 1000);
$kMoi              = Config::int('K_NGUOI_MOI', 32);
$kCu               = Config::int('K_NGUOI_CU', 24);
$nguongSoTranDoiK  = Config::int('NGUONG_SO_TRAN_DOI_K', 30);
$bangHangElo       = CongDongHelper::bangHangElo();
$toiThieuTranHH    = CongDongHelper::HUY_HIEU_TOI_THIEU_TRAN;

// ---- Xử lý thao tác (POST) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($maND === null) {
        redirect(BASE_URL . '/client/dangnhap.php');
    }
    csrf_check();
    $hanhDong = (string) ($_POST['hanh_dong'] ?? '');

    if ($hanhDong === 'chon_tuong') {
        $tuongId = trim((string) ($_POST['tuong_id'] ?? ''));
        if (isset(LoLChampions::DANH_SACH[$tuongId])) {
            $_SESSION['tuong_chon'] = $tuongId;
            luuTuyChonTimTran($pdo, $maND);
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
        luuTuyChonTimTran($pdo, $maND);
        $maMon = (int) ($_POST['mon'] ?? 0);
        $maKT  = (int) ($_POST['loaikt'] ?? 0);
        $loi   = null;

        if ($maKT === 3) {
            try {
                $pdo->exec("INSERT IGNORE INTO LOAIKYTHI (MaLoaiKT, TenLoaiKT, MoTa) VALUES (3, 'Cả 2', 'Ôn tập bao gồm cả câu hỏi giữa kỳ và cuối kỳ')");
            } catch (Throwable $e) {}
        }

        $st = $pdo->prepare("SELECT 1 FROM MONHOC WHERE MaMon = ? AND TrangThai = 'Đang mở'");
        $st->execute([$maMon]);
        $monHopLe = (bool) $st->fetchColumn();
        $st = $pdo->prepare('SELECT 1 FROM LOAIKYTHI WHERE MaLoaiKT = ?');
        $st->execute([$maKT]);
        $ktHopLe = (bool) $st->fetchColumn();

        $afk = AfkPenalty::kiemTra($maND);
        if ($afk['biPhat']) {
            $loi = 'Bạn đang bị tạm khóa tìm trận do không bấm sẵn sàng (AFK). Vui lòng đợi ' . (int) $afk['conLai'] . ' giây nữa.';
        } elseif (!$monHopLe || !$ktHopLe) {
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
$thongThaoHienTai = LoLChampions::layThongThao($pdo, (int) $maND, $tuongChonId);
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
         data-nguong-elo="<?= (int) $nguongElo ?>"
         data-cho-phep-bot="<?= $choPhepBot ? '1' : '0' ?>">
      <div class="pet-corner tl"></div><div class="pet-corner tr"></div>
      <div class="pet-corner bl"></div><div class="pet-corner br"></div>
      <div class="deck-top-glow-line"></div>
      <?= renderPvzTopBattle() ?>

      <div class="radar-hud-header">
        <span class="radar-hud-tag">HỆ THỐNG GHÉP ĐỐI ESPORTS</span>
        <h2 class="radar-title">ĐANG TÌM KIẾM ĐỐI THỦ XỨNG TẦM</h2>
        <p class="radar-sub">Hệ thống đang rà soát dữ liệu người chơi tương đồng Elo trên toàn đấu trường &middot; Giới hạn <?= (int) $choToiDa ?>s</p>
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
          
          <!-- Selected Champion Pedestal at Center (Crisp, High-Tech, No Clipping) -->
          <div class="radar-champ-center" id="radarChampCenter">
            <div class="champ-avatar-glow" id="champAvatarGlow" style="border-color: <?= e($tuongHienTai['mauSac']) ?>; box-shadow: 0 0 25px <?= e($tuongHienTai['mauSac']) ?>88;">
              <img src="<?= e(BASE_URL . $tuongHienTai['avatar'] . '?v=' . (file_exists(__DIR__ . '/..' . $tuongHienTai['avatar']) ? filemtime(__DIR__ . '/..' . $tuongHienTai['avatar']) : '2')) ?>" alt="<?= e($tuongHienTai['ten']) ?>" class="champ-radar-img" id="champRadarImg">
            </div>
            <div class="champ-radar-name" style="color: <?= e($tuongHienTai['mauSac']) ?>;"><?= e($tuongHienTai['ten']) ?></div>
            <div class="champ-mastery-pedestal-pill <?= e($thongThaoHienTai['cssClass']) ?>">
              <?= $thongThaoHienTai['iconCap'] ?> Cấp <?= $thongThaoHienTai['capDo'] ?> &middot; <?= e($thongThaoHienTai['danhHieuCap']) ?>
            </div>
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

          <!-- Hàng 2: Khối trung tâm hàng chờ & Lựa chọn chế độ (Gom chung 1 thẻ chính) -->
          <div class="radar-queue-core-card">
            <!-- Hàng đồng hồ đếm giờ -->
            <div class="radar-timer-main-row">
              <div class="timer-display-left">
                <span class="timer-clock-icon">⏱</span>
                <span class="stat-val timer-digits" id="dachoClock"><?= sprintf('%02d:%02d', intdiv((int)$dangCho['DaCho'], 60), (int)$dangCho['DaCho'] % 60) ?></span>
                <span class="timer-max-limit" id="radarMaxLimit">/ <?= sprintf('%02d:%02d', intdiv((int)$choToiDa, 60), (int)$choToiDa % 60) ?></span>
                <input type="hidden" id="dachoRaw" value="<?= (int) $dangCho['DaCho'] ?>">
                <input type="hidden" id="choToiDaVal" value="<?= (int) $choToiDa ?>">
              </div>
              <span class="est-badge" id="radarMaxBadge">GIỚI HẠN: <?= (int) $choToiDa ?> GIÂY</span>
            </div>

            <!-- Thanh tiến trình thời gian -->
            <div class="radar-timer-bar-wrap">
              <div class="radar-timer-bar-fill" id="radarTimerFill" style="width: <?= min(100, round(((int)$dangCho['DaCho'] / $choToiDa) * 100)) ?>%;"></div>
            </div>

            <!-- TINH GỌN LỰA CHỌN CHẾ ĐỘ: Segmented Switch trực quan 1 chạm -->
            <div class="radar-mode-segmented-block">
              <div class="mode-seg-header">
                <span class="mode-seg-title">CHẾ ĐỘ GHÉP ĐỐI</span>
                <span class="mode-seg-hint">Chạm để chuyển chế độ</span>
              </div>
              <button type="button" class="btn-radar-mode-segmented <?= $choPhepBot ? 'is-bot' : 'is-pvp' ?>" id="btnRadarModeToggle" title="Bấm để chuyển đổi giữa tự ghép máy hoặc chỉ đấu người thật">
                <div class="seg-item seg-item-pvp">
                  <span class="seg-item-icon">⚔️</span>
                  <span class="seg-item-name">Chỉ đấu Người thật (PvP)</span>
                </div>
                <div class="seg-item seg-item-bot">
                  <span class="seg-item-icon">🤖</span>
                  <span class="seg-item-name">Tự ghép Máy sau <?= (int) $choToiDa ?>s</span>
                </div>
                <!-- Phần tử ẩn giữ tương thích với JS cập nhật tự động -->
                <span id="modeLiveIcon" style="display:none;"><?= $choPhepBot ? '🤖' : '⚔️' ?></span>
                <span id="modeLiveName" style="display:none;"><?= $choPhepBot ? ('Tự ghép Máy sau ' . $choToiDa . 's') : 'Chỉ đấu Người thật (PvP)' ?></span>
                <span id="logModeText" style="display:none;"></span>
              </button>
            </div>

            <!-- Trạng thái live radar (1 dòng duy nhất, thoáng đãng) -->
            <div class="radar-live-status-line">
              <span class="log-bullet pulse"></span>
              <span id="logLine2">Đang quét đối thủ trong bán kính Elo ±<?= (int) $nguongElo ?>...</span>
            </div>
          </div>

          <!-- Hàng 3: Hai nút hành động ở đáy (Cân xứng 50-50, rõ ràng) -->
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

          <!-- Hàng 4: Mẹo nhỏ tinh tế ở đáy (Không còn là hộp to chen ngang) -->
          <div class="radar-tip-subtle" title="Mẹo leo rank">
            <span class="tip-subtle-icon">💡</span>
            <span class="tip-subtle-text" id="radarTipContent">Trả lời đúng trong 3 giây đầu để giật trọn vẹn điểm thưởng tốc độ tối đa!</span>
          </div>
        </div>
      </div>
    </div>

    <!-- =====================================================================
         1. ESPORTS READY CHECK MODAL (10s COUNTDOWN & CONFIRMATION DIALOG)
         ===================================================================== -->
    <div class="ready-check-overlay" id="esportsReadyCheckModal" style="display: none;" aria-hidden="true">
      <div class="ready-check-backdrop"></div>
      <div class="ready-check-dialog">
        <div class="rc-scanner-beam"></div>
        
        <div class="rc-top-tag">
          <span class="live-dot pulse-red"></span>
          <span>MATCH READY &middot; ĐÃ TÌM THẤY ĐỐI THỦ XỨNG TẦM</span>
        </div>

        <h2 class="rc-title">CHẤP NHẬN TRANH HÙNG</h2>
        <p class="rc-sub" id="rcSubInfo">Môn: <strong class="text-cyan" id="rcSubjectName"><?= e($dangCho['TenMon'] ?? 'Đang tải...') ?></strong> &middot; Hạng mục: <strong class="text-gold" id="rcExamName"><?= e($dangCho['TenLoaiKT'] ?? 'Chính thức') ?></strong></p>

        <!-- SVG Countdown Ring -->
        <div class="rc-timer-box">
          <svg class="rc-timer-svg" viewBox="0 0 120 120">
            <circle class="rc-timer-track" cx="60" cy="60" r="52"></circle>
            <circle class="rc-timer-bar" id="rcTimerBar" cx="60" cy="60" r="52"></circle>
          </svg>
          <div class="rc-timer-center">
            <span class="rc-timer-digits" id="rcTimerDigits">10</span>
            <span class="rc-timer-lbl">GIÂY</span>
          </div>
        </div>

        <!-- 2 Players Ready Badges -->
        <div class="rc-players-status">
          <!-- You -->
          <div class="rc-player-item is-you" id="rcPlayerYou">
            <div class="rc-avatar-wrap">
              <img src="<?= e(BASE_URL . $tuongHienTai['avatar']) ?>" alt="Bạn" id="rcAvatarYou" class="rc-avatar-img">
              <div class="rc-confirmed-check" id="rcCheckYou">✓</div>
            </div>
            <div class="rc-player-desc">
              <strong class="rc-player-name" id="rcNameYou">Bạn</strong>
              <span class="rc-status-chip" id="rcStatusChipYou">Chờ bạn xác nhận...</span>
            </div>
          </div>

          <div class="rc-vs-badge">VS</div>

          <!-- Opponent -->
          <div class="rc-player-item is-opp" id="rcPlayerOpp">
            <div class="rc-avatar-wrap">
              <img src="<?= e(BASE_URL . '/assets/images/champions/buckethead.png') ?>" alt="Đối thủ" id="rcAvatarOpp" class="rc-avatar-img">
              <div class="rc-confirmed-check" id="rcCheckOpp">✓</div>
            </div>
            <div class="rc-player-desc">
              <strong class="rc-player-name" id="rcNameOpp">Đối thủ</strong>
              <span class="rc-status-chip" id="rcStatusChipOpp">Đang kết nối...</span>
            </div>
          </div>
        </div>

        <!-- Huge Accept Button -->
        <div class="rc-btn-container">
          <button type="button" class="btn-rc-accept pulse-anim" id="btnRcAccept">
            <span class="btn-rc-glare"></span>
            <span class="btn-rc-icon">⚔️</span>
            <span class="btn-rc-text" id="btnRcText">CHẤP NHẬN TRANH HÙNG (10s)</span>
          </button>
        </div>
      </div>
    </div>

    <!-- =====================================================================
         2. CINEMATIC VS SHOWDOWN MODAL (MÀN HÌNH ĐỐI ĐẦU ĐIỆN ẢNH 2.5s)
         ===================================================================== -->
    <div class="vs-showdown-overlay" id="esportsVsShowdownModal" style="display: none;" aria-hidden="true">
      <div class="vs-showdown-bg-effects">
        <div class="vs-laser-grid"></div>
        <div class="vs-energy-burst"></div>
      </div>

      <div class="vs-showdown-card">
        <!-- Top Header Strip -->
        <div class="vs-header-banner">
          <span class="vs-mode-pill">⚡ ĐỐI KHÁNG TRANH HÙNG 1 VS 1</span>
          <h3 class="vs-subject-title" id="vsSubjectTitle"><?= e($dangCho['TenMon'] ?? 'ĐẤU TRƯỜNG TRANH TÀI') ?></h3>
        </div>

        <!-- Showdown Stage: Blue (Left) vs Red (Right) -->
        <div class="vs-stage-container">
          <!-- Left Side: YOU (Blue) -->
          <div class="vs-fighter vs-side-blue" id="vsFighterYou">
            <div class="fighter-glow-ring ring-blue"></div>
            <div class="fighter-avatar-box">
              <img src="<?= e(BASE_URL . $tuongHienTai['avatar']) ?>" alt="Bạn" id="vsAvatarYou" class="fighter-avatar-img">
            </div>
            <div class="fighter-info-panel">
              <div class="fighter-faction-badge faction-blue">🛡️ BẠN &middot; CHIẾN TUYẾN XANH</div>
              <h2 class="fighter-name" id="vsNameYou">Bạn</h2>
              <div class="fighter-champ-badge" id="vsChampYou"><?= e($tuongHienTai['ten']) ?></div>
              <div class="fighter-mastery-tag <?= e($thongThaoHienTai['cssClass']) ?>" id="vsMasteryYou">
                <?= $thongThaoHienTai['iconCap'] ?> Cấp <?= $thongThaoHienTai['capDo'] ?> &middot; <?= e($thongThaoHienTai['danhHieuCap']) ?>
              </div>
              <div class="fighter-elo-tag" id="vsEloYou"><?= (int) ($dangCho['EloTaiThoiDiemCho'] ?? 1000) ?> ELO</div>
            </div>
          </div>

          <!-- Center Emblem: VS Sét Đánh Điện Tử -->
          <div class="vs-center-burst" id="vsCenterBurst">
            <div class="vs-electric-circle"></div>
            <div class="vs-letters-wrap">
              <span class="vs-letter-v">V</span>
              <span class="vs-letter-s">S</span>
            </div>
            <div class="vs-match-tag">CHIẾN ĐẤU!</div>
          </div>

          <!-- Right Side: OPPONENT (Red) -->
          <div class="vs-fighter vs-side-red" id="vsFighterOpp">
            <div class="fighter-glow-ring ring-red"></div>
            <div class="fighter-avatar-box">
              <img src="<?= e(BASE_URL . '/assets/images/champions/buckethead.png') ?>" alt="Đối thủ" id="vsAvatarOpp" class="fighter-avatar-img">
            </div>
            <div class="fighter-info-panel">
              <div class="fighter-faction-badge faction-red">⚔️ ĐỐI THỦ &middot; CHIẾN TUYẾN ĐỎ</div>
              <h2 class="fighter-name" id="vsNameOpp">Đối thủ bí ẩn</h2>
              <div class="fighter-champ-badge" id="vsChampOpp">Zombie Xô Sắt</div>
              <div class="fighter-mastery-tag mastery-cap-1" id="vsMasteryOpp">
                ⚔️ Cấp 1 &middot; Tập Sự
              </div>
              <div class="fighter-elo-tag" id="vsEloOpp">1,000 ELO</div>
            </div>
          </div>
        </div>

        <!-- Bottom Loading Status Bar -->
        <div class="vs-loading-status-bar">
          <div class="vs-loading-track">
            <div class="vs-loading-progress" id="vsLoadingProgress"></div>
          </div>
          <div class="vs-loading-caption">
            <span class="vs-caption-pulse"></span>
            <span>ĐANG KHÓA ĐẤU TRƯỜNG &amp; VÀO PHÒNG THI...</span>
          </div>
        </div>
      </div>
    </div>

    <!-- PvP Timeout Overlay Modal khi hết thời gian chờ ở chế độ người thật -->
    <div class="pvp-timeout-overlay" id="pvpTimeoutOverlay" style="display: none;">
      <div class="pvp-timeout-card">
        <div class="pet-corner tl"></div><div class="pet-corner tr"></div>
        <div class="pet-corner bl"></div><div class="pet-corner br"></div>
        <div class="pvp-timeout-icon">⌛</div>
        <h3 class="pvp-timeout-title">HẾT <?= (int) $choToiDa ?> GIÂY TÌM KIẾM NGƯỜI THẬT</h3>
        <p class="pvp-timeout-desc">
          Hiện chưa có đối thủ người thật nào đang online cùng môn <strong class="text-cyan"><?= e($dangCho['TenMon']) ?></strong> ở mức Elo của bạn.
        </p>
        <div class="pvp-timeout-actions">
          <!-- Nút 1: Tìm tiếp -->
          <button type="button" class="btn btn-pvp-action btn-pvp-retry" id="btnPvpRetry">
            <span class="btn-pvp-icon">⚔️</span>
            <div class="btn-pvp-text">
              <strong>TÌM TIẾP <?= (int) $choToiDa ?> GIÂY NỮA</strong>
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

  <?php else: ?>
    <?php
    // ---- Danh sách môn đang mở (C-16): chọn trong ô thả xuống ----
    $dsMon = $pdo->query("SELECT MaMon, TenMon FROM MONHOC WHERE TrangThai = 'Đang mở' ORDER BY TenMon")->fetchAll();
    $choDuyet = [];
    if ($maND !== null) {
        $st = $pdo->prepare("SELECT TenMon FROM MONHOC WHERE MaNguoiDeXuat = ? AND TrangThai = 'Chờ duyệt' ORDER BY MaMon");
        $st->execute([$maND]);
        $choDuyet = $st->fetchAll(PDO::FETCH_COLUMN);
    }
    $monChon = null;
    foreach ($dsMon as $m) {
        if ((int) $m['MaMon'] === $mon) {
            $monChon = $m;
        }
    }
    $dsKT = [];
    if ($monChon) {
        $rawKT = $pdo->query('SELECT MaLoaiKT, TenLoaiKT FROM LOAIKYTHI ORDER BY MaLoaiKT')->fetchAll();
        $hasBoth = false;
        foreach ($rawKT as $k) {
            if ((int) $k['MaLoaiKT'] === 3 || mb_stripos($k['TenLoaiKT'], 'cả') !== false) {
                $hasBoth = true;
            }
        }
        if (!$hasBoth) {
            try {
                $pdo->exec("INSERT INTO LOAIKYTHI (MaLoaiKT, TenLoaiKT, MoTa) VALUES (3, 'Cả 2', 'Ôn tập bao gồm cả câu hỏi giữa kỳ và cuối kỳ')");
                $rawKT = $pdo->query('SELECT MaLoaiKT, TenLoaiKT FROM LOAIKYTHI ORDER BY MaLoaiKT')->fetchAll();
            } catch (Throwable $e) {}
        }

        foreach ($rawKT as $k) {
            $ten = $k['TenLoaiKT'];
            if (mb_stripos($ten, 'giữa') !== false) {
                $ten = 'Giữa kì';
            } elseif (mb_stripos($ten, 'cuối') !== false) {
                $ten = 'Cuối kì';
            } elseif (mb_stripos($ten, 'cả') !== false) {
                $ten = 'Cả 2';
            }
            $k['TenHienThi'] = $ten;
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
                <p class="arena-deck-sub">Hệ thống ghép cặp 1 vs 1 công bằng theo điểm Elo &middot; <?= (int) $soCau ?> câu hỏi kịch tính (<?= (int) $thoiGianMoiCau ?>s/câu)</p>
              </div>

              <!-- Nút mở Cẩm nang Luật đấu & Cách tính điểm Cyber Esports -->
              <div class="deck-header-action">
                <button type="button" class="btn-cyber-rulebook" id="btnOpenRulebook" title="Xem Cẩm nang Luật đấu & Cách tính điểm">
                  <span class="rulebook-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none">
                      <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" stroke="#FDE047" stroke-width="2" stroke-linecap="round"/>
                      <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z" fill="rgba(245, 158, 11, 0.2)" stroke="#FDE047" stroke-width="2"/>
                      <path d="M9 7h7M9 11h5" stroke="#38BDF8" stroke-width="1.8" stroke-linecap="round"/>
                    </svg>
                  </span>
                  <span>CẨM NANG LUẬT ĐẤU</span>
                </button>
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
                      <option disabled class="opt-separator">────────────────────────</option>
                      <option value="__propose__" class="opt-propose-new">➕ Không có môn bạn cần? Đề xuất môn mới...</option>
                    </select>
                    <span class="select-chevron-svg">
                      <svg viewBox="0 0 20 20" width="18" height="18" fill="none">
                        <path d="M5 7.5L10 12.5L15 7.5" stroke="#00D9FF" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/>
                      </svg>
                    </span>
                  </div>
                  <noscript><p><button type="submit" class="btn btn-phu">Xác nhận môn</button></p></noscript>
                </form>

                <!-- Thanh Pill Gợi Ý Đề Xuất Môn Siêu Nổi Bật Chuẩn Cyber Gaming -->
                <div class="propose-quick-pill" id="proposeQuickPill" onclick="if(window.openProposeModal){window.openProposeModal();}else{var m=document.getElementById('cyberProposeModal');if(m){m.classList.add('is-open');m.style.setProperty('display','flex','important');}}" style="cursor: pointer;">
                  <div class="propose-pill-content">
                    <span class="propose-pill-glow-bulb" aria-hidden="true">💡</span>
                    <span class="propose-pill-text">Không tìm thấy môn sở trường của bạn?</span>
                  </div>
                  <button type="button" class="btn-propose-trigger" id="btnOpenProposeModal" title="Mở hộp thoại đề xuất môn học mới">
                    <span class="plus-icon">+</span>
                    <span>Đề xuất môn mới ngay</span>
                  </button>
                </div>

                <?php if (!empty($choDuyet)): ?>
                  <div class="pending-proposals-inline" title="Danh sách các môn bạn đã gửi đang chờ Ban Quản Trị phê duyệt">
                    <span class="pending-badge">
                      <svg viewBox="0 0 16 16" width="12" height="12" fill="none" style="vertical-align: -1px; margin-right: 4px;"><circle cx="8" cy="8" r="6.5" stroke="#FDE047" stroke-width="1.4"/><path d="M8 4.5v4l2.5 1.5" stroke="#FDE047" stroke-width="1.4" stroke-linecap="round"/></svg>
                      Đề xuất đang chờ duyệt (<?= count($choDuyet) ?>):
                    </span>
                    <div class="pending-tags">
                      <?php foreach ($choDuyet as $cm): ?>
                        <span class="pending-chip"><?= e($cm) ?></span>
                      <?php endforeach; ?>
                    </div>
                  </div>
                <?php endif; ?>
              </div>

              <!-- Bước 2: Chọn hạng mục kỳ thi (Xếp ngang: Giữa kì, Cuối kì, Cả 2 theo kiểu tích trắc nghiệm) -->
              <div class="deck-form-group">
                <div class="deck-group-header">
                  <span class="step-num-badge">BƯỚC 02</span>
                  <label class="deck-group-title">HẠNG MỤC KỲ THI</label>
                  <?php if ($monChon): ?><span class="deck-group-status ready">✓ Sẵn sàng: Trận <?= (int) $soCau ?> câu &middot; <?= (int) $thoiGianMoiCau ?>s/câu &middot; Hạn chờ <?= (int) $choToiDa ?>s</span><?php endif; ?>
                </div>
                <?php if ($monChon): ?>
                  <form method="post" action="" class="form-arena-queue" id="formVaoHang">
                    <?= csrf_field() ?>
                    <input type="hidden" name="hanh_dong" value="vao">
                    <input type="hidden" name="mon" value="<?= (int) $monChon['MaMon'] ?>">
                    <input type="hidden" name="tuong_id" value="<?= e($tuongChonId) ?>">

                    <?php 
                      // Mặc định chọn mục đầu tiên đủ câu hỏi
                      $firstEligible = null;
                      foreach ($dsKT as $k) {
                          if ($k['SoCau'] >= $soCau && $firstEligible === null) {
                              $firstEligible = (int) $k['MaLoaiKT'];
                          }
                      }
                      if ($firstEligible === null && !empty($dsKT)) {
                          $firstEligible = (int) $dsKT[0]['MaLoaiKT'];
                      }
                    ?>
                    <div class="exam-quiz-options">
                      <?php foreach ($dsKT as $k): ?>
                        <?php 
                          $duCau = ($k['SoCau'] >= $soCau);
                          $isDefChecked = ((int)$k['MaLoaiKT'] === $firstEligible);
                        ?>
                        <label class="exam-quiz-card <?= $isDefChecked ? 'is-selected' : '' ?> <?= !$duCau ? 'is-disabled' : '' ?>">
                          <input type="radio" name="loaikt" value="<?= (int) $k['MaLoaiKT'] ?>" <?= $isDefChecked ? 'checked' : '' ?> <?= !$duCau ? 'disabled' : '' ?> class="quiz-radio-input" style="display:none;">
                          <span class="quiz-radio-indicator" aria-hidden="true">
                            <span class="quiz-radio-dot"></span>
                          </span>
                          <div class="quiz-opt-info">
                            <span class="quiz-opt-title"><?= e($k['TenHienThi'] ?? $k['TenLoaiKT']) ?></span>
                            <span class="quiz-opt-sub <?= $duCau ? 'ready' : 'lack' ?>">
                              <?= (int) $k['SoCau'] ?> câu<?= $duCau ? ' khả dụng' : ' (Chưa đủ ' . $soCau . ')' ?>
                            </span>
                          </div>
                        </label>
                      <?php endforeach; ?>
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

              <!-- Bước 3: Chọn chế độ thi đấu chuyên nghiệp: Tách bạch rõ ràng Đấu Hạng PvP & Luyện Tập Solo AI -->
              <div class="deck-form-group deck-mode-group">
                <div class="deck-group-header">
                  <span class="step-num-badge">BƯỚC 03</span>
                  <label class="deck-group-title">CHẾ ĐỘ THI ĐẤU</label>
                  <span class="deck-group-status ready" id="modeSelectedStatusText">⚔️ Đấu Hạng PvP</span>
                </div>

                <div class="mode-cards-grid">
                  <!-- THẺ 1: ĐẤU HẠNG PVP (NGƯỜI THẬT) -->
                  <div class="mode-selection-card active" id="modeCardPvp" data-mode="pvp" role="button" tabindex="0" title="Bấm để chọn Đấu Hạng Người Thật">
                    <div class="mode-card-radio-indicator">
                      <span class="mode-radio-dot"></span>
                    </div>
                    <div class="mode-card-content">
                      <div class="mode-card-header">
                        <span class="mode-card-icon">⚔️</span>
                        <div class="mode-card-title-wrap">
                          <span class="mode-card-title">Đấu Hạng PvP</span>
                          <span class="mode-card-badge rank">Người thật 100%</span>
                        </div>
                      </div>
                      <p class="mode-card-desc">Ghép ngẫu nhiên với người chơi cùng bậc Elo trên toàn máy chủ.</p>
                    </div>
                  </div>

                  <!-- THẺ 2: LUYỆN TẬP VỚI MÁY (SOLO BOT) -->
                  <div class="mode-selection-card" id="modeCardBot" data-mode="bot" role="button" tabindex="0" title="Bấm để chọn Luyện Tập Với Máy">
                    <div class="mode-card-radio-indicator">
                      <span class="mode-radio-dot"></span>
                    </div>
                    <div class="mode-card-content">
                      <div class="mode-card-header">
                        <span class="mode-card-icon">🤖</span>
                        <div class="mode-card-title-wrap">
                          <span class="mode-card-title">Đấu Với Máy</span>
                          <span class="mode-card-badge instant">Vào trận ngay</span>
                        </div>
                      </div>
                      <p class="mode-card-desc">Luyện tập ngay tức thì với AI thông minh, không cần chờ đối thủ.</p>
                    </div>
                  </div>

                  <!-- THẺ 3: PHÒNG THÁCH ĐẤU BẠN BÈ (MÃ PIN) -->
                  <div class="mode-selection-card" id="modeCardPin" data-mode="pin" role="button" tabindex="0" title="Bấm để tạo phòng hoặc nhập mã PIN thách đấu bạn bè 1v1">
                    <div class="mode-card-radio-indicator">
                      <span class="mode-radio-dot"></span>
                    </div>
                    <div class="mode-card-content">
                      <div class="mode-card-header">
                        <span class="mode-card-icon">🔑</span>
                        <div class="mode-card-title-wrap">
                          <span class="mode-card-title">Thách Đấu PIN</span>
                          <span class="mode-card-badge pin">Solo Bạn Bè 1v1</span>
                        </div>
                      </div>
                      <p class="mode-card-desc">Tạo mã riêng hoặc nhập PIN 6 số để thách đấu trực tiếp bạn bè.</p>
                    </div>
                  </div>
                </div>

                <!-- BẢNG ĐIỀU KHIỂN THÁCH ĐẤU PIN (GỌN GÀNG, HIỆN KHI CHỌN CHẾ ĐỘ PIN) -->
                <div class="pin-challenge-drawer" id="pinChallengePanel" style="display: none;">
                  <div class="pin-drawer-tabs">
                    <button type="button" class="btn-pin-tab active" id="btnPinTabCreate">⚔️ TẠO PHÒNG MỚI</button>
                    <button type="button" class="btn-pin-tab" id="btnPinTabJoin">🔑 NHẬP MÃ PIN</button>
                  </div>
                  <!-- Tab Tạo Phòng -->
                  <div class="pin-tab-pane active" id="pinTabPaneCreate">
                    <div class="pin-create-initial" id="pinCreateInitial">
                      <p class="pin-hint">Tạo phòng riêng cho môn đã chọn. Gửi mã PIN hoặc Link cho bạn bè để bắt đầu trận đấu!</p>
                      <button type="button" class="btn-pin-action" id="btnActionCreateRoom">⚡ KHỞI TẠO PHÒNG NGAY</button>
                    </div>
                    <div class="pin-create-active" id="pinCreateActive" style="display: none;">
                      <div class="pin-code-showcase">
                        <span class="pin-label">MÃ PIN CỦA BẠN:</span>
                        <span class="pin-number" id="displayPinCode">------</span>
                        <div class="pin-copy-btns">
                          <button type="button" class="btn-copy-chip" id="btnCopyPinCode" title="Sao chép 6 số PIN">📋 Sao chép PIN</button>
                          <button type="button" class="btn-copy-chip" id="btnCopyPinLink" title="Sao chép liên kết vào phòng">🔗 Sao chép Link</button>
                        </div>
                      </div>
                      <div class="pin-wait-status">
                        <span class="pin-spin-ring"></span>
                        <span id="pinWaitMsg">Đang chờ bạn bè kết nối vào phòng...</span>
                      </div>
                      <button type="button" class="btn-cancel-pin-room" id="btnCancelPinRoom">✕ HỦY PHÒNG</button>
                    </div>
                  </div>
                  <!-- Tab Nhập PIN -->
                  <div class="pin-tab-pane" id="pinTabPaneJoin" style="display: none;">
                    <div class="pin-join-box">
                      <p class="pin-hint">Nhập mã PIN 6 số do bạn bè gửi để tham gia trận đấu ngay lập tức:</p>
                      <div class="pin-input-group">
                        <input type="text" id="inputPinCode" class="input-pin-digits" maxlength="6" placeholder="VD: 123456" autocomplete="off">
                        <button type="button" class="btn-pin-action" id="btnActionJoinRoom">VÀO PHÒNG CHIẾN ⚔️</button>
                      </div>
                      <div class="pin-error-notice" id="pinJoinErrorNotice" style="display: none;"></div>
                    </div>
                  </div>
                </div>

                <!-- HIDDEN INPUTS ĐỒNG BỘ VỚI FORM VÀO HÀNG -->
                <input type="hidden" name="che_do_ghep" id="inputCheDoGhep" form="formVaoHang" value="<?= $choPhepBot ? 'auto_bot' : 'pvp_only' ?>">
                <input type="hidden" name="dau_bot" id="inputDauBot" form="formVaoHang" value="">
              </div>

            <!-- NÚT BẤM HÀNH ĐỘNG DUY NHẤT: BẮT ĐẦU TÌM ĐỐI THỦ HOẶC VÀO ĐẤU MÁY (1 NÚT TO BẢN, RÕ RÀNG) -->
            <div class="deck-cta-block">
              <?php if ($monChon): ?>
                <?php if ($maND === null): ?>
                  <a class="btn-quick-match vip-pro-btn" href="<?= e(BASE_URL) ?>/client/dangnhap.php">
                    <span class="btn-light-sweep"></span>
                    <span>ĐĂNG NHẬP ĐỂ THI ĐẤU →</span>
                  </a>
                <?php else: ?>
                  <?php $coThe = array_filter($dsKT, fn($k) => $k['SoCau'] >= $soCau); ?>
                  <div class="deck-single-action-row">
                    <button type="submit" form="formVaoHang" class="btn-quick-match vip-pro-btn" id="btnSubmitQuickMatch" <?= $coThe ? '' : 'disabled' ?>>
                      <span class="btn-light-sweep"></span>
                      <span class="btn-sword-svg" id="ctaBtnIcon">
                        <svg viewBox="0 0 24 24" width="24" height="24" fill="none">
                          <path d="M14.5 17.5L3 6V3h3l11.5 11.5M13 19l2 2 4-4-2-2" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>
                          <path d="M9.5 6.5L18 15" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                          <path d="M16 3h5v5l-4 4-5-5 4-4z" fill="currentColor"/>
                        </svg>
                      </span>
                      <span id="ctaBtnText">BẮT ĐẦU TÌM ĐỐI THỦ (PVP) →</span>
                    </button>
                  </div>
                <?php endif; ?>
              <?php else: ?>
                <button type="button" class="btn-quick-match vip-pro-btn disabled" disabled>
                  <span>HÃY CHỌN MÔN ĐỂ BẮT ĐẦU</span>
                </button>
              <?php endif; ?>

              <!-- Dòng gợi ý xem Cẩm nang Luật chơi & Cách tính điểm -->
              <div class="deck-rule-sublink-bar">
                <span class="rule-hint-icon">📖</span>
                <span>Chưa rõ quy chế tính điểm &amp; Elo?</span>
                <button type="button" class="btn-link-rulebook" id="btnOpenRulebookSub">Xem Cẩm nang Luật đấu &rarr;</button>
              </div>
            </div>
          </div>

          <!-- CỘT PHẢI: SÂN KHẤU HOLOGAM TƯỚNG 3D VIP PRO (GỌN GÀNG, SANG TRỌNG, KHÔNG RỐI MẮT) -->
          <div class="deck-right-stage" style="--champ-color: <?= e($tuongHienTai['mauSac']) ?>; --champ-rgb: <?= e($tuongHienTai['mauRgb'] ?? '251, 191, 36') ?>;">
            <!-- Vầng hào quang dịu nhẹ phía sau -->
            <div class="stage-aura" style="background: radial-gradient(circle, rgba(<?= e($tuongHienTai['mauRgb'] ?? '251, 191, 36') ?>, 0.14) 0%, transparent 70%);"></div>

            <!-- Khung Tranh Hologram Tướng -->
            <div class="stage-champ-card">
              <div class="pet-corner tl"></div><div class="pet-corner tr"></div>
              <div class="pet-corner bl"></div><div class="pet-corner br"></div>

              <!-- Avatar Tướng To, Nét & Có Đèn Viền Đẹp -->
              <div class="stage-avatar-container">
                <img src="<?= e(BASE_URL . $tuongHienTai['avatar'] . '?v=' . (file_exists(__DIR__ . '/..' . $tuongHienTai['avatar']) ? filemtime(__DIR__ . '/..' . $tuongHienTai['avatar']) : '2')) ?>" alt="<?= e($tuongHienTai['ten']) ?>" class="stage-champ-avatar">
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

                <!-- Cây Thông Thạo & EXP Tiến Hóa -->
                <div class="stage-champ-mastery-box">
                  <div class="sc-mastery-top">
                    <span class="sc-mastery-pill <?= e($thongThaoHienTai['cssClass']) ?>">
                      <?= $thongThaoHienTai['iconCap'] ?> Cấp <?= $thongThaoHienTai['capDo'] ?> &middot; <?= e($thongThaoHienTai['danhHieuCap']) ?>
                    </span>
                    <span class="sc-exp-val"><?= (int) $thongThaoHienTai['diemEXP'] ?> / <?= (int) $thongThaoHienTai['expKeMoc'] ?> EXP</span>
                  </div>
                  <div class="sc-exp-track">
                    <div class="sc-exp-fill <?= e($thongThaoHienTai['cssClass']) ?>" style="width: <?= (int) $thongThaoHienTai['phanTram'] ?>%;"></div>
                  </div>
                </div>
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
          <div class="champ-card <?= $isChon ? 'is-selected' : '' ?> faction-<?= e($c['phe']) ?>" style="--champ-color: <?= e($c['mauSac']) ?>; --champ-rgb: <?= e($c['mauRgb'] ?? '251, 191, 36') ?>;" data-champ-id="<?= e($cid) ?>" data-faction="<?= e($c['phe']) ?>">
            <div class="champ-card-inner">
              <!-- Avatar Circle 3D Nét Đẹp -->
              <div class="champ-avatar-wrap">
                <div class="champ-avatar-ring">
                  <img src="<?= e(BASE_URL . $c['avatar'] . '?v=' . (file_exists(__DIR__ . '/..' . $c['avatar']) ? filemtime(__DIR__ . '/..' . $c['avatar']) : '2')) ?>" alt="<?= e($c['ten']) ?>" class="champ-avatar-img">
                </div>
                <?php if ($isChon): ?>
                  <span class="champ-status-badge selected-badge">✓ ĐÃ CHỌN</span>
                <?php endif; ?>
              </div>

              <!-- Thông tin Đấu Thần Tinh Gọn, Khóa Cứng Vị Trí Đồng Bộ -->
              <div class="champ-details">
                <div class="champ-header-block">
                  <div class="champ-header-meta">
                    <span class="champ-faction-tag <?= $c['phe'] === 'plant' ? 'is-plant' : 'is-zombie' ?>">
                      <?= $c['pheIcon'] ?> <?= e($c['pheTen']) ?>
                    </span>
                    <span class="champ-title-tag" title="<?= e($c['danhHieu']) ?>"><?= e($c['danhHieu']) ?></span>
                  </div>
                  <h3 class="champ-name" title="<?= e($c['ten']) ?>"><?= e($c['ten']) ?></h3>
                </div>

                <!-- Năng lực Đặc Biệt (Buff Cốt Lõi - Chiều Cao Cố Định) -->
                <div class="champ-buff-showcase">
                  <div class="buff-showcase-item" title="⚡ Tìm trận: <?= e($c['tacDungMatch']) ?>">
                    <span class="buff-lbl match-lbl">⚡ TÌM TRẬN:</span>
                    <span class="buff-val"><?= e($c['tacDungMatch']) ?></span>
                  </div>
                  <div class="buff-showcase-item" title="⚔️ Trong phòng: <?= e($c['tacDungArena']) ?>">
                    <span class="buff-lbl arena-lbl">⚔️ TRONG PHÒNG:</span>
                    <span class="buff-val"><?= e($c['tacDungArena']) ?></span>
                  </div>
                </div>

                <!-- Nút Bấm Xuất Trận - Khóa Đáy Đồng Bộ -->
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
                      <span>CHỌN CHIẾN BINH NÀY</span>
                    </button>
                  <?php endif; ?>
                </form>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

  <?php endif; ?>
</div>

<!-- =========================================================================
     CYBER RULEBOOK MODAL (CẨM NANG LUẬT ĐẤU & CÁCH TÍNH ĐIỂM CHUẨN ADMIN)
     Thiết kế Ultra-Sleek No-Scroll: Header ngang gọn gàng, bố cục 2 cột, vừa vặn 1 màn hình
     ========================================================================= -->
<div id="cyberRulebookModal" class="cyber-rulebook-modal" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="rulebookModalTitle">
  <div class="cyber-modal-backdrop" id="rulebookModalBackdrop"></div>
  <div class="cyber-modal-dialog rulebook-dialog">
    <!-- 4 Gilded Gaming Brackets (Góc Dát Vàng Đẳng Cấp) -->
    <div class="pet-corner tl"></div>
    <div class="pet-corner tr"></div>
    <div class="pet-corner bl"></div>
    <div class="pet-corner br"></div>

    <!-- 1. Sleek Compact Header Bar (Ngang hàng, không chiếm chiều dọc) -->
    <div class="rulebook-compact-header">
      <div class="rb-header-left">
        <span class="rb-header-icon" aria-hidden="true">📖</span>
        <div class="rb-header-titles">
          <div class="rb-header-sub">QUIZUP ARENA &bull; BỘ QUY CHẾ ĐẤU TRƯỜNG CHÍNH THỨC</div>
          <h3 class="rb-header-main" id="rulebookModalTitle">CẨM NANG LUẬT ĐẤU &amp; CÁCH TÍNH ĐIỂM</h3>
        </div>
      </div>
      <div class="rb-header-chips">
        <span class="h-chip"><i class="chip-dot dot-cyan"></i> Live Admin</span>
        <span class="h-chip"><i class="chip-dot dot-gold"></i> Thưởng tốc độ +<?= (int) $heSoThuong ?>đ</span>
        <span class="h-chip"><i class="chip-dot dot-red"></i> Chống AFK</span>
      </div>
      <button type="button" class="btn-close-cyber-modal rb-header-close" id="btnCloseRulebookModal" aria-label="Đóng Cẩm nang">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
      </button>
    </div>

    <!-- 2. Compact 3-Tab Bar -->
    <div class="rulebook-tabs-nav" role="tablist">
      <button type="button" class="rulebook-tab-btn active" data-tab="tab-the-thuc" role="tab" aria-selected="true">
        <span class="tab-btn-icon">⚔️</span>
        <span class="tab-btn-title">1. THỂ THỨC 1 VS 1 &bull; 4 BƯỚC TRANH HÙNG</span>
      </button>
      <button type="button" class="rulebook-tab-btn" data-tab="tab-tinh-diem" role="tab" aria-selected="false">
        <span class="tab-btn-icon">⚡</span>
        <span class="tab-btn-title">2. CÔNG THỨC &bull; MÔ PHỎNG ĐIỂM TỐC ĐỘ</span>
      </button>
      <button type="button" class="rulebook-tab-btn" data-tab="tab-elo-rank" role="tab" aria-selected="false">
        <span class="tab-btn-icon">🏆</span>
        <span class="tab-btn-title">3. BẬC HUY HIỆU ELO &bull; BÙA LỢI PvZ</span>
      </button>
    </div>

    <!-- 3. Tab Contents Body (Được tối ưu Side-by-Side 2 cột để không cần cuộn) -->
    <div class="rulebook-tabs-body">
      <!-- TAB 1: THỂ THỨC 1 VS 1 (3 TRỤ CỘT RÕ RÀNG, DỄ NHÌN, KHÔNG RỐI MẮT) -->
      <div class="rulebook-tab-pane active" id="tab-the-thuc" role="tabpanel">
        <div class="rule-pillars-3">
          <!-- Trụ 1: Quy mô & Thời gian -->
          <div class="rule-pillar-card">
            <div class="pillar-header">
              <span class="pillar-icon">🎯</span>
              <div>
                <h4 class="pillar-title">QUY MÔ &amp; THỜI GIAN</h4>
                <span class="pillar-sub">Quy chuẩn 1 trận tranh tài</span>
              </div>
            </div>
            <div class="pillar-metrics">
              <div class="metric-pill">
                <span class="m-val text-cyan"><?= (int) $soCau ?></span>
                <span class="m-lbl">Câu hỏi trắc nghiệm</span>
              </div>
              <div class="metric-pill">
                <span class="m-val text-gold"><?= (int) $thoiGianMoiCau ?>s</span>
                <span class="m-lbl">Thời gian mỗi câu</span>
              </div>
            </div>
            <ul class="pillar-bullets">
              <li>Câu hỏi bốc ngẫu nhiên từ ngân hàng đã duyệt, không trùng lặp.</li>
              <li>Đồng hồ đếm ngược độc lập từng câu, hết <?= (int) $thoiGianMoiCau ?>s tự động chốt đáp án.</li>
              <li>Trả lời đúng càng nhanh thưởng càng nhiều điểm bứt phá.</li>
            </ul>
          </div>

          <!-- Trụ 2: Ghép cặp Elo -->
          <div class="rule-pillar-card">
            <div class="pillar-header">
              <span class="pillar-icon">⚖️</span>
              <div>
                <h4 class="pillar-title">GHÉP CẶP CÂN TRÌNH</h4>
                <span class="pillar-sub">Thuật toán xếp cặp công bằng</span>
              </div>
            </div>
            <div class="pillar-metrics">
              <div class="metric-pill">
                <span class="m-val text-cyan">&plusmn;<?= (int) $nguongElo ?></span>
                <span class="m-lbl">Chênh lệch Elo tối đa</span>
              </div>
              <div class="metric-pill">
                <span class="m-val text-gold"><?= (int) $choToiDa ?>s</span>
                <span class="m-lbl">Hạn mức chờ ghép</span>
              </div>
            </div>
            <ul class="pillar-bullets">
              <li>Hệ thống ưu tiên tìm người chơi có điểm Elo tương đương.</li>
              <li>Nếu quá <?= (int) $choToiDa ?>s chưa có đối thủ, tự động đề xuất <strong>Đấu Thần Máy (Bot AI)</strong>.</li>
              <li>Bạn có thể chọn chỉ đấu người thật hoặc cho phép tự ghép máy.</li>
            </ul>
          </div>

          <!-- Trụ 3: Bảo vệ phòng & Chống AFK -->
          <div class="rule-pillar-card">
            <div class="pillar-header">
              <span class="pillar-icon">🛡️</span>
              <div>
                <h4 class="pillar-title">CHỐNG AFK &amp; BỎ CUỘC</h4>
                <span class="pillar-sub">Bảo vệ an toàn điểm số</span>
              </div>
            </div>
            <div class="pillar-metrics">
              <div class="metric-pill">
                <span class="m-val text-cyan"><?= (int) $thoiGianVaoPhong ?>s</span>
                <span class="m-lbl">Giới hạn vào phòng</span>
              </div>
              <div class="metric-pill">
                <span class="m-val text-gold"><?= (int) $thoiGianMatKetNoi ?>s</span>
                <span class="m-lbl">Chờ kết nối lại</span>
              </div>
            </div>
            <ul class="pillar-bullets">
              <li>Đối thủ không vào phòng trong <?= (int) $thoiGianVaoPhong ?>s ➔ Hủy phòng, <strong>bảo toàn 100% Elo</strong>.</li>
              <li>Thoát trình duyệt hoặc mất kết nối quá <?= (int) $thoiGianMatKetNoi ?>s ➔ <strong>Tự động xử thua</strong>.</li>
              <li>Chống hack, gian lận qua hệ thống xác thực trực tiếp trên server.</li>
            </ul>
          </div>
        </div>
      </div>

      <!-- TAB 2: CÁCH TÍNH ĐIỂM TỐC ĐỘ (2 CỘT SONG SONG: TRÁI CÔNG THỨC + SLIDER, PHẢI BẢNG ĐIỂM) -->
      <div class="rulebook-tab-pane" id="tab-tinh-diem" role="tabpanel">
        <div class="rule-tab2-grid">
          <!-- Cột Trái: 3 Khối Công thức Mini + Live Simulator Kéo Thả -->
          <div class="tab2-left-col">
            <!-- 3 Khối Công Thức Tinh Gọn -->
            <div class="formula-cards-row-compact">
              <div class="eq-card-mini card-base">
                <span class="eq-tag-mini">ĐIỂM CƠ BẢN</span>
                <div class="eq-val-mini">+<?= (int) $diemCoBan ?> đ</div>
                <div class="eq-sub-mini">Chắc chắn nhận khi trả lời đúng</div>
              </div>
              <div class="eq-op-mini">+</div>
              <div class="eq-card-mini card-bonus">
                <span class="eq-tag-mini tag-gold">THƯỞNG TỐC ĐỘ</span>
                <div class="eq-val-mini">0 ~ +<?= (int) $heSoThuong ?> đ</div>
                <div class="eq-sub-mini">⌊ <?= (int) $heSoThuong ?> &times; (Còn lại / <?= (int) $thoiGianMoiCau ?>s) ⌋</div>
              </div>
              <div class="eq-op-mini">=</div>
              <div class="eq-card-mini card-total">
                <span class="eq-tag-mini tag-cyan">TỔNG NHẬN / CÂU</span>
                <div class="eq-val-mini text-cyan"><?= (int) ($diemCoBan + $heSoThuong) ?> đ <small>max</small></div>
                <div class="eq-sub-mini">Tối đa <strong><?= (int) ($soCau * ($diemCoBan + $heSoThuong)) ?>đ</strong> / <?= (int) $soCau ?> câu</div>
              </div>
            </div>

            <!-- Live Simulator Kéo Thả Trực Tiếp -->
            <div class="speed-calc-box-compact">
              <div class="calc-top-row">
                <div class="calc-top-title">
                  <span class="calc-ico">⚡</span>
                  <strong>MÔ PHỎNG ĐIỂM THƯỞNG LIVE:</strong>
                </div>
                <span class="calc-rate-badge badge-godlike" id="calcSpeedRating">⚡ 1.0s: THẦN TỐC (MAX ĐIỂM)</span>
              </div>

              <div class="calc-slider-compact">
                <div class="slider-row-compact">
                  <span>Kéo thử thời gian bấm đúng:</span>
                  <strong id="sliderSecDisplay" class="text-gold">1.0 giây</strong>
                </div>
                <input type="range" id="rulebookSpeedSlider" min="0" max="<?= (int) $thoiGianMoiCau ?>" step="0.5" value="1" class="cyber-range-slider"
                       data-base="<?= (int) $diemCoBan ?>" data-bonus="<?= (int) $heSoThuong ?>" data-maxsec="<?= (int) $thoiGianMoiCau ?>">
                <div class="slider-ticks-compact">
                  <span>0s (Vừa hiện)</span>
                  <span><?= round($thoiGianMoiCau / 2, 1) ?>s</span>
                  <span><?= (int) $thoiGianMoiCau ?>s (Hết giờ)</span>
                </div>
              </div>

              <!-- Công Thức Nhảy Số Trực Tiếp -->
              <div class="calc-dyn-compact">
                <span class="dyn-tag">Công thức:</span>
                <code id="calcLiveEquation"><?= (int) $diemCoBan ?> + ⌊ <?= (int) $heSoThuong ?> &times; (<?= (int) $thoiGianMoiCau ?> - 1.0) / <?= (int) $thoiGianMoiCau ?> ⌋ = <?= (int) $diemCoBan + (int) floor($heSoThuong * ($thoiGianMoiCau - 1) / $thoiGianMoiCau) ?> điểm</code>
              </div>

              <!-- Kết Quả 3 Hộp Nằm Ngang -->
              <div class="calc-res-strip">
                <div class="res-box">
                  <span class="res-lbl">Còn lại:</span>
                  <strong class="res-val" id="calcRemainSec"><?= (int) $thoiGianMoiCau - 1 ?>s</strong>
                </div>
                <div class="res-box">
                  <span class="res-lbl">Thưởng:</span>
                  <strong class="res-val text-gold" id="calcBonusPts">+<?= (int) floor($heSoThuong * ($thoiGianMoiCau - 1) / $thoiGianMoiCau) ?> đ</strong>
                </div>
                <div class="res-box total-box">
                  <span class="res-lbl">TỔNG ĐIỂM:</span>
                  <strong class="res-val text-cyan" id="calcTotalPts"><?= (int) $diemCoBan + (int) floor($heSoThuong * ($thoiGianMoiCau - 1) / $thoiGianMoiCau) ?> ĐIỂM</strong>
                </div>
              </div>
            </div>
          </div>

          <!-- Cột Phải: Bảng Điểm Tham Khảo & Mẹo Leo Rank -->
          <div class="tab2-right-col">
            <div class="sample-table-card">
              <div class="table-caption-mini">BẢNG VÍ DỤ TÍNH ĐIỂM MỖI CÂU THAM KHẢO</div>
              <table class="rulebook-table-compact">
                <thead>
                  <tr>
                    <th>Tốc độ</th>
                    <th>Thời gian</th>
                    <th>Cơ bản</th>
                    <th>Thưởng</th>
                    <th>Tổng nhận</th>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <td><span class="speed-pill speed-god">⚡ SIÊU TỐC</span></td>
                    <td>1.0s</td>
                    <td>+<?= (int) $diemCoBan ?></td>
                    <td>+<?= (int) floor($heSoThuong * ($thoiGianMoiCau - 1) / $thoiGianMoiCau) ?></td>
                    <td><strong class="pts-glow-gold"><?= (int) $diemCoBan + (int) floor($heSoThuong * ($thoiGianMoiCau - 1) / $thoiGianMoiCau) ?> đ</strong></td>
                  </tr>
                  <tr>
                    <td><span class="speed-pill speed-fast">🏃 NHANH</span></td>
                    <td>5.0s</td>
                    <td>+<?= (int) $diemCoBan ?></td>
                    <td>+<?= (int) floor($heSoThuong * max(0, $thoiGianMoiCau - 5) / $thoiGianMoiCau) ?></td>
                    <td><strong class="pts-glow-cyan"><?= (int) $diemCoBan + (int) floor($heSoThuong * max(0, $thoiGianMoiCau - 5) / $thoiGianMoiCau) ?> đ</strong></td>
                  </tr>
                  <tr>
                    <td><span class="speed-pill speed-mid">⏱️ VỪA</span></td>
                    <td>10.0s</td>
                    <td>+<?= (int) $diemCoBan ?></td>
                    <td>+<?= (int) floor($heSoThuong * max(0, $thoiGianMoiCau - 10) / $thoiGianMoiCau) ?></td>
                    <td><strong><?= (int) $diemCoBan + (int) floor($heSoThuong * max(0, $thoiGianMoiCau - 10) / $thoiGianMoiCau) ?> đ</strong></td>
                  </tr>
                  <tr>
                    <td><span class="speed-pill speed-slow">🐢 SÁT NÚT</span></td>
                    <td><?= (int) $thoiGianMoiCau ?>s</td>
                    <td>+<?= (int) $diemCoBan ?></td>
                    <td>+0</td>
                    <td><strong><?= (int) $diemCoBan ?> đ</strong></td>
                  </tr>
                  <tr>
                    <td><span class="speed-pill speed-fail">❌ SAI</span></td>
                    <td>Bất kỳ</td>
                    <td>0</td>
                    <td>0</td>
                    <td><strong class="text-red">0 đ</strong></td>
                  </tr>
                </tbody>
              </table>
            </div>

            <!-- Pro-Tip Card -->
            <div class="pro-tip-box">
              <span class="pro-tip-ico">💡</span>
              <div class="pro-tip-content">
                <strong>CHIẾN THUẬT VÀNG LEO RANK:</strong>
                <p>Bấm chọn đáp án đúng trong <strong>2 giây đầu</strong> để giữ điểm thưởng từ <strong>+9đ ~ +10đ</strong> trọn vẹn mỗi câu. 10 câu có thể giành tối đa <strong><?= (int) ($soCau * ($diemCoBan + $heSoThuong)) ?> điểm</strong> bỏ xa đối thủ!</p>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- TAB 3: BẬC ELO & BÙA LỢI ĐẤU THẦN PvZ (2 CỘT SONG SONG: TRÁI ELO + 4 HẠNG, PHẢI 6 TƯỚNG PvZ) -->
      <div class="rulebook-tab-pane" id="tab-elo-rank" role="tabpanel">
        <div class="rule-tab3-grid">
          <!-- Cột Trái: Bậc Elo & 4 Phân Hạng Huy Hiệu -->
          <div class="tab3-left-col">
            <!-- Elo Info Strip -->
            <div class="elo-kpi-compact">
              <div class="kpi-item">
                <span class="kpi-lbl">ELO KHỞI TẠO</span>
                <strong class="kpi-val text-gold"><?= number_format($eloKhoiTao) ?> ELO</strong>
              </div>
              <div class="kpi-divider"></div>
              <div class="kpi-item">
                <span class="kpi-lbl">K TÂN THỦ (&lt; <?= (int) $nguongSoTranDoiK ?> TRẬN)</span>
                <strong class="kpi-val text-cyan">K = <?= (int) $kMoi ?></strong>
              </div>
              <div class="kpi-divider"></div>
              <div class="kpi-item">
                <span class="kpi-lbl">K KỲ CỰU (&ge; <?= (int) $nguongSoTranDoiK ?> TRẬN)</span>
                <strong class="kpi-val text-cyan">K = <?= (int) $kCu ?></strong>
              </div>
            </div>

            <!-- 4 Thẻ Hạng Elo Dạng List Dọc Gọn Gàng -->
            <div class="rank-list-compact">
              <div class="rank-list-header">4 PHÂN HẠNG HUY HIỆU ELO (TỐI THIỂU <?= (int) $toiThieuTranHH ?> TRẬN HIỂN THỊ)</div>
              <?php foreach ($bangHangElo as $h): ?>
                <div class="rank-item-compact <?= e($h['css']) ?>">
                  <div class="rank-ico"><?= $h['icon'] ?></div>
                  <div class="rank-info">
                    <strong class="rank-title"><?= e($h['ten']) ?></strong>
                    <span class="rank-range">Elo <?= e($h['khoang']) ?></span>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- Cột Phải: Bùa Lợi Đấu Thần PvZ Có Bộ Lọc -->
          <div class="tab3-right-col">
            <!-- Bộ Lọc Phe Phái -->
            <div class="pvz-filter-bar-compact">
              <span class="pvz-bar-lbl">BÙA LỢI ĐẤU THẦN PvZ:</span>
              <div class="pvz-btns-group">
                <button type="button" class="pvz-modal-filter-btn active" data-faction="all">TẤT CẢ (6)</button>
                <button type="button" class="pvz-modal-filter-btn" data-faction="plant">🌱 CÂY (3)</button>
                <button type="button" class="pvz-modal-filter-btn" data-faction="zombie">🧟 ZOMBIE (3)</button>
              </div>
            </div>

            <!-- Lưới 6 Thẻ Đấu Thần 2 Cột x 3 Hàng Tinh Gọn -->
            <div class="pvz-cards-grid-compact">
              <?php foreach (LoLChampions::DANH_SACH as $c): ?>
                <div class="pvz-card-compact pvz-buff-card" data-champ-faction="<?= e($c['phe']) ?>" style="--c-rgb: <?= e($c['mauRgb'] ?? '251, 191, 36') ?>; --c-hex: <?= e($c['mauSac']) ?>;">
                  <div class="pvz-card-avatar-wrap">
                    <img src="<?= e(BASE_URL . $c['avatar']) ?>" alt="<?= e($c['ten']) ?>" class="pvz-card-avatar">
                  </div>
                  <div class="pvz-card-body">
                    <div class="pvz-card-headline">
                      <strong class="pvz-card-name" style="color: <?= e($c['mauSac']) ?>;"><?= e($c['ten']) ?></strong>
                      <span class="pvz-faction-badge <?= $c['phe'] === 'plant' ? 'is-plant' : 'is-zombie' ?>"><?= $c['pheIcon'] ?> <?= e($c['pheTen']) ?></span>
                    </div>
                    <div class="pvz-card-effect" title="<?= e($c['tacDungMatch']) ?>">
                      <span class="ef-tag tag-match">⚡ Tìm trận:</span>
                      <span class="ef-desc"><?= e($c['tacDungMatch']) ?></span>
                    </div>
                    <div class="pvz-card-effect" title="<?= e($c['tacDungArena']) ?>">
                      <span class="ef-tag tag-arena">⚔️ Đấu trường:</span>
                      <span class="ef-desc"><?= e($c['tacDungArena']) ?></span>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- 4. Ultra-Sleek Bottom Action Bar (Cực Gọn, Không Chiếm Chỗ) -->
    <div class="rulebook-compact-footer">
      <div class="rb-footer-hint">
        <span>💡 Bấm <strong>ESC</strong> hoặc nhấp ngoài để đóng &bull; Thông số đồng bộ Live thời gian thực</span>
      </div>
      <button type="button" class="btn-rulebook-close-primary-compact" id="btnRulebookUnderstood">
        <span>ĐÃ HIỂU LUẬT &bull; CHIẾN NGAY &rarr;</span>
      </button>
    </div>
  </div>
</div>
</div>

<!-- =========================================================================
     CYBER PROPOSE SUBJECT MODAL (HỘP THOẠI NỔI GAMING ĐỀ XUẤT MÔN)
     ========================================================================= -->
<div id="cyberProposeModal" class="cyber-propose-modal" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="proposeModalTitle">
  <div class="cyber-modal-backdrop" id="proposeModalBackdrop"></div>
  <div class="cyber-modal-dialog">
    <!-- 4 Gilded Gaming Brackets (Góc Dát Vàng) -->
    <div class="pet-corner tl"></div>
    <div class="pet-corner tr"></div>
    <div class="pet-corner bl"></div>
    <div class="pet-corner br"></div>

    <button type="button" class="btn-close-cyber-modal" id="btnCloseProposeModal" aria-label="Đóng hộp thoại">
      <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
    </button>

    <!-- Modal Header -->
    <div class="cyber-modal-header">
      <div class="cyber-modal-crest">
        <span class="crest-icon">💡</span>
        <div class="crest-ring"></div>
      </div>
      <div class="cyber-modal-subtag">VŨ ĐÀI TRI THỨC &bull; TRƯNG CẦU SĨ TỬ</div>
      <h3 class="cyber-modal-title" id="proposeModalTitle">ĐỀ XUẤT MÔN THI ĐẤU MỚI</h3>
      <p class="cyber-modal-desc">Chưa tìm thấy môn sở trường? Hãy gửi tên môn để Hội đồng Quản trị thẩm định và mở rộng sàn đấu tranh hạng Elo cho toàn thể sĩ tử!</p>
    </div>

    <?php if ($maND === null): ?>
      <!-- Khách chưa đăng nhập: hướng dẫn đăng nhập -->
      <div class="cyber-modal-guest-box" style="text-align: center; padding: 16px 12px 10px;">
        <div style="font-size: 2.2rem; margin-bottom: 8px;">🔒</div>
        <h4 style="color: #FDE047; font-size: 1.15rem; margin-bottom: 8px; font-weight: 800;">BẠN CẦN ĐĂNG NHẬP</h4>
        <p style="color: #94A3B8; font-size: 0.88rem; line-height: 1.5; margin-bottom: 22px;">
          Để đề xuất môn thi đấu mới và nhận thông báo khi Ban Quản Trị phê duyệt mở phòng, vui lòng đăng nhập vào tài khoản Sĩ tử.
        </p>
        <div class="modal-footer-actions" style="justify-content: center;">
          <button type="button" class="btn-modal-cancel" id="btnCancelProposeGuest" onclick="window.closeProposeModal ? window.closeProposeModal() : (document.getElementById('cyberProposeModal').style.display='none')">HỦY BỎ</button>
          <a href="<?= e(BASE_URL) ?>/client/dangnhap.php" class="btn-modal-submit" style="text-decoration: none;">
            <span>ĐĂNG NHẬP NGAY &rarr;</span>
          </a>
        </div>
      </div>
    <?php else: ?>
      <!-- Modal Form dành cho Sĩ tử đã đăng nhập -->
      <form method="post" action="" class="cyber-modal-form" id="formCyberPropose">
        <?= csrf_field() ?>
        <input type="hidden" name="hanh_dong" value="dexuat">

        <div class="modal-form-group">
          <label for="modal_ten_mon" class="modal-form-label">
            <span class="label-star">★</span> TÊN MÔN HỌC ĐỀ XUẤT <span class="required-mark">(Bắt buộc)</span>
          </label>
          <div class="modal-input-wrap">
            <input type="text" id="modal_ten_mon" name="ten_mon" maxlength="150" class="modal-cosmic-input" placeholder="VD: Lập Trình Python, Triết Học Mác - Lênin, Hóa Hữu Cơ..." required autocomplete="off">
          </div>
          <span class="modal-field-hint">Tối đa 150 ký tự &bull; Không trùng tên với môn đã có trên sàn đấu.</span>
        </div>

        <div class="modal-form-group">
          <label for="modal_mo_ta" class="modal-form-label">
            <span class="label-icon">📝</span> MÔ TẢ PHẠM VI HOẶC KIẾN THỨC <span class="optional-mark">(Tùy chọn)</span>
          </label>
          <div class="modal-textarea-wrap">
            <textarea id="modal_mo_ta" name="mo_ta" rows="3" maxlength="500" class="modal-cosmic-textarea" placeholder="Gợi ý phạm vi kiến thức câu hỏi, giáo trình hoặc đối tượng phù hợp..."></textarea>
          </div>
        </div>

        <?php if (!empty($choDuyet)): ?>
          <div class="modal-pending-box">
            <span class="pending-lead">⏳ Bạn đang có <?= count($choDuyet) ?>/<?= TOI_DA_DE_XUAT_CHO ?> đề xuất chờ duyệt:</span>
            <div class="pending-item-list">
              <?php foreach ($choDuyet as $cm): ?>
                <span class="pending-item-pill"><?= e($cm) ?></span>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <div class="modal-footer-actions">
          <button type="button" class="btn-modal-cancel" id="btnCancelProposeModal" onclick="window.closeProposeModal ? window.closeProposeModal() : (document.getElementById('cyberProposeModal').style.display='none')">HỦY BỎ</button>
          <button type="submit" class="btn-modal-submit">
            <span class="btn-glow-orbit"></span>
            <span class="btn-submit-icon">🚀</span>
            <span>GỬI ĐỀ XUẤT NGAY</span>
          </button>
        </div>
      </form>
    <?php endif; ?>
  </div>
</div>


<?php require __DIR__ . '/layout/footer.php'; ?>