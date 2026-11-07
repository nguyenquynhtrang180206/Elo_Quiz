<?php
// Trang Cộng đồng: menu trái, danh sách bài giữa, banner phải. Đặt tại: elo_quiz/client/congdong.php
// Xem (C-42), đăng bài (C-43), thích bài. Khách chưa đăng nhập vẫn xem được.
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/CongDongHelper.php';

$maND = Auth::clientId();
$pdo  = Database::pdo();
$monDangMo = CongDongHelper::monDangMo();

// Bảng LUOTTHICH có thể chưa được tạo (chạy migration_luotthich.sql); chưa có thì ẩn nút thích
$coLike = true;
try {
    $pdo->query('SELECT 1 FROM LUOTTHICH LIMIT 1');
} catch (PDOException $ex) {
    $coLike = false;
}

$coLoai = CongDongHelper::coCotLoaiBai();

// ---- Bộ lọc: 6 chuyên mục dọc chuẩn giao diện Cyber Esports ----
$cacTab = [
    'moi'       => ['<svg viewBox="0 0 16 16" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 2.5l2.5 2.5-8 8H3v-2.5l8-8z"/><path d="M9.5 4l2.5 2.5"/></svg>', 'Bài đăng mới',      'Bài đăng mới nhất'],
    'noibat'    => [CongDongHelper::iconLua(19), 'Bài viết nổi bật',  'Bài viết nổi bật (Nhiều lượt thả lửa)'],
    'hot'       => ['<svg viewBox="0 0 16 16" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 7.5c0 3-2.5 5.5-5.5 5.5-1 0-2-.3-2.8-.7L2 13.5l1.2-3.1C2.5 9.6 2 8.6 2 7.5 2 4.5 4.5 2 7.5 2S14 4.5 14 7.5z"/><circle cx="5.5" cy="7.5" r="1" fill="currentColor"/><circle cx="8" cy="7.5" r="1" fill="currentColor"/><circle cx="10.5" cy="7.5" r="1" fill="currentColor"/></svg>', 'Thảo luận hot',     'Thảo luận hot trong 7 ngày'],
    'thanhvien' => ['<svg viewBox="0 0 16 16" width="16" height="16" fill="none" aria-hidden="true"><defs><linearGradient id="tabCrownGold" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#FFFFFF"/><stop offset="30%" stop-color="#FFF0A3"/><stop offset="70%" stop-color="#F59E0B"/><stop offset="100%" stop-color="#9A5B02"/></linearGradient></defs><path d="M2 13l-1-7 4 3.5L8 3l3 6.5 4-3.5-1 7H2z" fill="url(#tabCrownGold)"/><path d="M2 14.5h12v1.2H2z" fill="url(#tabCrownGold)"/><circle cx="8" cy="8.5" r="1.2" fill="#EF4444"/><circle cx="4.5" cy="10" r="1" fill="#38BDF8"/><circle cx="11.5" cy="10" r="1" fill="#38BDF8"/></svg>', 'Thành viên nổi bật', 'Bảng xếp hạng thành viên nổi bật'],
    'cuatoi'    => ['<svg viewBox="0 0 16 16" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 2.5a3.5 3.5 0 100 7 3.5 3.5 0 000-7z"/><path d="M2.5 14.5a5.5 5.5 0 0111 0"/></svg>', 'Bài của tôi',       'Bài viết của tôi'],
    'huyhieu'   => ['<svg viewBox="0 0 16 16" width="16" height="16" fill="none" aria-hidden="true"><defs><linearGradient id="tabShieldGold" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#FFFFFF"/><stop offset="30%" stop-color="#FFF0A3"/><stop offset="70%" stop-color="#F59E0B"/><stop offset="100%" stop-color="#9A5B02"/></linearGradient></defs><polygon points="8,1.5 14,4.5 14,9 8,14.5 2,9 2,4.5" stroke="#FDE047" stroke-width="1.5" stroke-linejoin="round"/><polygon points="8,4 11.5,8 8,12 4.5,8" fill="url(#tabShieldGold)"/><circle cx="8" cy="8" r="1.5" fill="#FFF"/></svg>', 'Huy hiệu là gì?',   'Hệ thống phân hạng huy hiệu Elo'],
];
$tab = (string) ($_GET['tab'] ?? 'moi');
if (!isset($cacTab[$tab])) {
    $tab = 'moi';
}
$monLoc = (int) ($_GET['mon'] ?? 0);
if (!isset($monDangMo[$monLoc])) {
    $monLoc = 0;
}
$loaiLoc = $coLoai && in_array((string) ($_GET['loai'] ?? ''), CongDongHelper::LOAI_BAI, true) ? (string) $_GET['loai'] : '';
$tuKhoa = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
// Tham số lọc giữ nguyên khi đổi tab, sang trang, thả lửa bài...
$thamLoc = ($monLoc > 0 ? ['mon' => $monLoc] : []) + ($loaiLoc !== '' ? ['loai' => $loaiLoc] : []) + ($tuKhoa !== '' ? ['q' => $tuKhoa] : []);
$thamTab = fn(string $t): string => '?' . http_build_query(['tab' => $t] + $thamLoc);

$loi = [];
$tieuDeBai = $noiDungBai = $monChon = '';
$loaiChon = CongDongHelper::LOAI_MAC_DINH;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if ($maND === null) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'yeuCauLogin' => true, 'redirect' => BASE_URL . '/client/login.php']);
            exit;
        }
        redirect(BASE_URL . '/client/login.php');
    }
    csrf_check();

    if (($_POST['hanh_dong'] ?? '') === 'thich') {
        // ---- Thả lửa / dập lửa một bài viết ----
        $maBai = (int) ($_POST['ma'] ?? 0);
        $st = $pdo->prepare("SELECT COUNT(*) FROM BAIDANG WHERE MaBaiDang = ? AND TrangThai = 'Hiển thị'");
        $st->execute([$maBai]);
        $daThich = false;
        if ($coLike && (int) $st->fetchColumn() === 1) {
            $st = $pdo->prepare('INSERT IGNORE INTO LUOTTHICH (MaBaiDang, MaND) VALUES (?, ?)');
            $st->execute([$maBai, $maND]);
            if ($st->rowCount() === 0) {
                $st = $pdo->prepare('DELETE FROM LUOTTHICH WHERE MaBaiDang = ? AND MaND = ?');
                $st->execute([$maBai, $maND]);
                $daThich = false;
            } else {
                $daThich = true;
            }
        }
        $stCount = $pdo->prepare('SELECT COUNT(*) FROM LUOTTHICH WHERE MaBaiDang = ?');
        $stCount->execute([$maBai]);
        $soThichMoi = (int) $stCount->fetchColumn();

        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => true,
                'daThich' => $daThich,
                'soThich' => $soThichMoi,
                'thongBao' => $daThich ? '🔥 Đã tiếp lửa bài viết!' : 'Đã bỏ thả lửa.'
            ]);
            exit;
        }

        $quayLai = ['tab' => isset($cacTab[$_POST['tab'] ?? '']) ? $_POST['tab'] : 'moi'];
        if ((int) ($_POST['mon'] ?? 0) > 0) {
            $quayLai['mon'] = (int) $_POST['mon'];
        }
        if ($coLoai && in_array((string) ($_POST['loai'] ?? ''), CongDongHelper::LOAI_BAI, true)) {
            $quayLai['loai'] = (string) $_POST['loai'];
        }
        $qGui = mb_substr(trim((string) ($_POST['q'] ?? '')), 0, 100);
        if ($qGui !== '') {
            $quayLai['q'] = $qGui;
        }
        if ((int) ($_POST['page'] ?? 1) > 1) {
            $quayLai['page'] = (int) $_POST['page'];
        }
        redirect(BASE_URL . '/client/congdong.php?' . http_build_query($quayLai) . '#bai-' . $maBai);
    }

    if (in_array($_POST['hanh_dong'] ?? '', ['an_bai', 'mo_bai', 'xoa_bai'], true)) {
        // ---- Chủ bài: ẩn / mở lại / xóa bài của mình (chỉ tác động lên bài của chính mình) ----
        $maBai = (int) ($_POST['ma'] ?? 0);
        switch ($_POST['hanh_dong']) {
            case 'an_bai':
                $ok  = CongDongHelper::anBai($pdo, $maBai, $maND);
                $msg = $ok ? 'Đã ẩn bài đăng. Bạn có thể mở lại trong mục "Bài của tôi".' : 'Không ẩn được bài này.';
                break;
            case 'mo_bai':
                $ok  = CongDongHelper::moLaiBai($pdo, $maBai, $maND);
                $msg = $ok ? 'Đã mở lại bài đăng.' : 'Không mở lại được: bài này do quản trị viên ẩn hoặc đã thay đổi trạng thái.';
                break;
            default:
                $ok  = CongDongHelper::xoaBai($pdo, $maBai, $maND);
                $msg = $ok ? 'Đã xóa bài đăng.' : 'Không xóa được bài này.';
        }
        CongDongHelper::flash($msg);
        $quayLai = ['tab' => 'cuatoi'];
        $qGui = mb_substr(trim((string) ($_POST['q'] ?? '')), 0, 100);
        if ($qGui !== '') {
            $quayLai['q'] = $qGui;
        }
        if ((int) ($_POST['mon'] ?? 0) > 0) {
            $quayLai['mon'] = (int) $_POST['mon'];
        }
        if ((int) ($_POST['page'] ?? 1) > 1) {
            $quayLai['page'] = (int) $_POST['page'];
        }
        redirect(BASE_URL . '/client/congdong.php?' . http_build_query($quayLai));
    }

    // ---- Đăng bài mới ----
    $tieuDeBai  = trim((string) ($_POST['tieu_de'] ?? ''));
    $noiDungBai = trim((string) ($_POST['noi_dung'] ?? ''));
    $monChon    = (string) ($_POST['mon'] ?? '');
    $loaiChon   = CongDongHelper::kiemTraLoai($_POST['loai'] ?? '');

    [$loi, $maMon] = CongDongHelper::kiemTraBai($tieuDeBai, $noiDungBai, $monChon, $monDangMo);

    if (!$loi) {
        if ($coLoai) {
            $st = $pdo->prepare('INSERT INTO BAIDANG (MaND, MaMon, TieuDe, NoiDung, LoaiBai) VALUES (?, ?, ?, ?, ?)');
            $st->execute([$maND, $maMon, $tieuDeBai, $noiDungBai, $loaiChon]);
        } else {
            $st = $pdo->prepare('INSERT INTO BAIDANG (MaND, MaMon, TieuDe, NoiDung) VALUES (?, ?, ?, ?)');
            $st->execute([$maND, $maMon, $tieuDeBai, $noiDungBai]);
        }
        CongDongHelper::flash('Đã đăng bài.');
        redirect(BASE_URL . '/client/congdong.php');
    }
}

$thanhCong = CongDongHelper::flash();

$baiDang = [];
$eloMap = [];   // Elo theo người đăng, dùng cho huy hiệu cạnh tên
$thanhVien = [];
$p = null;

if ($tab === 'huyhieu') {
    // ---- Huy hiệu là gì?: không cần dữ liệu từ CSDL ----
} elseif ($tab === 'thanhvien') {
    // ---- Thành viên nổi bật: xếp theo hoạt động (bài đăng x3 + bình luận) ----
    $thanhVien = $pdo->query(
        "SELECT * FROM (
            SELECT u.MaND, u.HoTen, u.AnhDaiDien,
                   (SELECT COUNT(*) FROM BAIDANG b WHERE b.MaND = u.MaND AND b.TrangThai = 'Hiển thị') AS SoBai,
                   (SELECT COUNT(*) FROM BINHLUAN c WHERE c.MaND = u.MaND AND c.TrangThai = 'Hiển thị') AS SoBL,
                   (SELECT MAX(e.DiemElo) FROM ELOMONHOC e WHERE e.MaND = u.MaND AND e.SoTran > 0) AS EloCao
              FROM NGUOIDUNG u WHERE u.TrangThai = 'Hoạt động'
         ) t WHERE t.SoBai + t.SoBL > 0
          ORDER BY t.SoBai * 3 + t.SoBL DESC, t.EloCao DESC, t.MaND LIMIT 10"
    )->fetchAll();
} elseif ($tab === 'cuatoi' && $maND === null) {
    // Khách chưa đăng nhập bấm xem bài của tôi -> để trống, hiện nút đăng nhập
    $baiDang = [];
    $p = new Paginator(0, 10);
} else {
    // ---- Danh sách bài theo tab, lọc môn, phân trang 10 dòng ----
    $where = "b.TrangThai = 'Hiển thị'";
    $tham  = [];
    if ($tab === 'cuatoi') {
        // Bài của mình: gồm cả bài đang ẩn; bài "Đã xóa" thì không hiện
        $where = "b.MaND = :cn AND b.TrangThai IN ('Hiển thị', 'Bị ẩn')";
        $tham[':cn'] = (int) $maND;
    }
    if ($monLoc > 0) {
        $where .= ' AND b.MaMon = :mon';
        $tham[':mon'] = $monLoc;
    }
    if ($loaiLoc !== '') {
        $where .= ' AND b.LoaiBai = :loai';
        $tham[':loai'] = $loaiLoc;
    }
    if ($tuKhoa !== '') {
        // Tìm theo từ khóa trong tiêu đề hoặc nội dung
        $like = '%' . addcslashes($tuKhoa, '\\%_') . '%';
        $where .= ' AND (b.TieuDe LIKE :q1 OR b.NoiDung LIKE :q2)';
        $tham[':q1'] = $like;
        $tham[':q2'] = $like;
    }
    if ($tab === 'hot') {
        $where .= " AND EXISTS (SELECT 1 FROM BINHLUAN h WHERE h.MaBaiDang = b.MaBaiDang
                                 AND h.TrangThai = 'Hiển thị' AND h.NgayBinhLuan >= DATE_SUB(NOW(), INTERVAL 7 DAY))";
    }
    $thuTu = [
        'moi'    => 'b.NgayDang DESC, b.MaBaiDang DESC',
        'cuatoi' => 'b.NgayDang DESC, b.MaBaiDang DESC',
        'noibat' => '(SoThich * 2 + SoBL) DESC, b.NgayDang DESC, b.MaBaiDang DESC',
        'hot'    => 'SoBL7 DESC, SoBL DESC, b.NgayDang DESC, b.MaBaiDang DESC',
    ][$tab];

    $st = $pdo->prepare("SELECT COUNT(*) FROM BAIDANG b WHERE $where");
    $st->execute($tham);
    $p = new Paginator((int) $st->fetchColumn(), 10);

    $subThich = $coLike
        ? '(SELECT COUNT(*) FROM LUOTTHICH l WHERE l.MaBaiDang = b.MaBaiDang) AS SoThich,
           (SELECT COUNT(*) FROM LUOTTHICH l2 WHERE l2.MaBaiDang = b.MaBaiDang AND l2.MaND = :nd) AS DaThich'
        : '0 AS SoThich, 0 AS DaThich';
    $thamSel = $tham;
    if ($coLike) {
        $thamSel[':nd'] = (int) $maND;
    }

    $st = $pdo->prepare(
        "SELECT b.MaBaiDang, b.MaMon, b.TieuDe, b.NoiDung, b.NgayDang, b.TrangThai, u.MaND, u.HoTen, u.AnhDaiDien, m.TenMon,
                TIMESTAMPDIFF(SECOND, b.NgayDang, NOW()) AS GiayTuDang,
                " . (CongDongHelper::coCotNguoiAn() ? 'b.NguoiAn' : 'NULL AS NguoiAn') . ",
                " . ($coLoai ? 'b.LoaiBai' : "'" . CongDongHelper::LOAI_MAC_DINH . "' AS LoaiBai") . ",
                (SELECT COUNT(*) FROM BINHLUAN c WHERE c.MaBaiDang = b.MaBaiDang AND c.TrangThai = 'Hiển thị') AS SoBL,
                (SELECT COUNT(*) FROM BINHLUAN c7 WHERE c7.MaBaiDang = b.MaBaiDang AND c7.TrangThai = 'Hiển thị'
                    AND c7.NgayBinhLuan >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS SoBL7,
                $subThich
           FROM BAIDANG b
           JOIN NGUOIDUNG u ON u.MaND = b.MaND
           LEFT JOIN MONHOC m ON m.MaMon = b.MaMon
          WHERE $where
          ORDER BY $thuTu
          LIMIT " . $p->limit() . ' OFFSET ' . $p->offset()
    );
    $st->execute($thamSel);
    $baiDang = $st->fetchAll();
    $eloMap  = CongDongHelper::layElo($pdo, array_column($baiDang, 'MaND'));

    // Xem trước 2 bình luận cấp 1 mới nhất của mỗi bài (chỉ bình luận 'Hiển thị', không lấy trả lời lồng bên trong); phần còn lại xem trong trang bài
    $blXemTruoc = [];
    $stBL = $pdo->prepare(
        "SELECT c.NoiDung, c.NgayBinhLuan, u.HoTen, u.AnhDaiDien
           FROM BINHLUAN c JOIN NGUOIDUNG u ON u.MaND = c.MaND
          WHERE c.MaBaiDang = ? AND c.TrangThai = 'Hiển thị' AND c.MaBinhLuanCha IS NULL
          ORDER BY c.NgayBinhLuan DESC, c.MaBinhLuan DESC
          LIMIT 2"
    );
    foreach ($baiDang as $bx) {
        if ((int) $bx['SoBL'] > 0) {
            $stBL->execute([(int) $bx['MaBaiDang']]);
            $blXemTruoc[(int) $bx['MaBaiDang']] = array_reverse($stBL->fetchAll());   // cũ trước, mới sau
        }
    }
}

// ---- Thống kê thời gian thực cho Hero Banner Cộng Đồng Đẳng Cấp ----
$stStats = $pdo->query("
    SELECT 
        (SELECT COUNT(*) FROM BAIDANG WHERE TrangThai = 'Hiển thị') AS TongBai,
        (SELECT COUNT(*) FROM BINHLUAN WHERE TrangThai = 'Hiển thị') AS TongBL,
        " . ($coLike ? "(SELECT COUNT(*) FROM LUOTTHICH) AS TongThich," : "0 AS TongThich,") . "
        (SELECT COUNT(DISTINCT MaND) FROM (SELECT MaND FROM BAIDANG UNION SELECT MaND FROM BINHLUAN) t) AS TongSiTu
")->fetch(PDO::FETCH_ASSOC);

$tongBaiCD   = (int) ($stStats['TongBai'] ?? 0);
$tongBLCD    = (int) ($stStats['TongBL'] ?? 0);
$tongThichCD = (int) ($stStats['TongThich'] ?? 0);
$tongSiTuCD  = (int) ($stStats['TongSiTu'] ?? 0);

// Tìm Ngôi sao tri thức tuần (Top 1 đóng góp bài đăng + bình luận)
$topMvp = $pdo->query("
    SELECT * FROM (
        SELECT u.MaND, u.HoTen, u.AnhDaiDien,
               (SELECT COUNT(*) FROM BAIDANG b WHERE b.MaND = u.MaND AND b.TrangThai = 'Hiển thị') AS SoBai,
               (SELECT COUNT(*) FROM BINHLUAN c WHERE c.MaND = u.MaND AND c.TrangThai = 'Hiển thị') AS SoBL,
               (SELECT MAX(e.DiemElo) FROM ELOMONHOC e WHERE e.MaND = u.MaND AND e.SoTran > 0) AS EloCao,
               (SELECT m.TenMon FROM ELOMONHOC e JOIN MONHOC m ON m.MaMon = e.MaMon WHERE e.MaND = u.MaND AND e.SoTran > 0 ORDER BY e.DiemElo DESC LIMIT 1) AS TenMonGanh
          FROM NGUOIDUNG u WHERE u.TrangThai = 'Hoạt động'
    ) t WHERE t.SoBai + t.SoBL > 0
     ORDER BY t.SoBai * 3 + t.SoBL DESC, t.EloCao DESC, t.MaND LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

$tieuDe = 'Cộng đồng';
$cssTrang = 'congdong';
$jsTrang  = 'congdong';
$bodyClass = 'trang-cd';
require __DIR__ . '/layout/header.php';
?>

<!-- Reusable Cyber Gradients for Community SVG Icons -->
<svg width="0" height="0" style="position: absolute; width: 0; height: 0; overflow: hidden; pointer-events: none;" aria-hidden="true" focusable="false">
  <defs>
    <linearGradient id="cdGradGold" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0%" stop-color="#FFFFFF"/>
      <stop offset="30%" stop-color="#FFF0A3"/>
      <stop offset="70%" stop-color="#F59E0B"/>
      <stop offset="100%" stop-color="#9A5B02"/>
    </linearGradient>
    <linearGradient id="cdGradCyan" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0%" stop-color="#E0F2FE"/>
      <stop offset="50%" stop-color="#38BDF8"/>
      <stop offset="100%" stop-color="#0284C7"/>
    </linearGradient>
    <linearGradient id="cdGradFlame" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0%" stop-color="#FEF08A"/>
      <stop offset="45%" stop-color="#F97316"/>
      <stop offset="100%" stop-color="#EF4444"/>
    </linearGradient>
    <linearGradient id="cdGradRuby" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0%" stop-color="#FDA4AF"/>
      <stop offset="100%" stop-color="#E11D48"/>
    </linearGradient>
  </defs>
</svg>

<!-- =====================================================================
     HERO BANNER CỘNG ĐỒNG CHIẾN THẦN ELO (ĐỒNG BỘ 100% ESPORTS CÁC TRANG)
     ===================================================================== -->
<section class="cd-hero-banner" id="cd-hero-banner" aria-label="Đại sảnh Tri thức Cộng đồng">
  <!-- 4 góc dát vàng phong cách Cyber Esports -->
  <span class="pet-corner tl" aria-hidden="true"></span>
  <span class="pet-corner tr" aria-hidden="true"></span>
  <span class="pet-corner bl" aria-hidden="true"></span>
  <span class="pet-corner br" aria-hidden="true"></span>

  <!-- Hiệu ứng vầng sáng vũ trụ & lưới cyber 3D -->
  <div class="cd-hero-aurora" aria-hidden="true"></div>
  <div class="cd-hero-grid" aria-hidden="true"></div>

  <div class="cd-hero-container">
    <!-- CỘT TRÁI: TIÊU ĐỀ, PHỤ ĐỀ & CÁC NÚT HÀNH ĐỘNG -->
    <div class="cd-hero-left">
      <!-- Badge Live Status -->
      <div class="cd-hero-badge">
        <span class="cham-live" aria-hidden="true"></span>
        <span class="cd-badge-text">ĐẠI SẢNH TRI THỨC • CỘNG ĐỒNG SĨ TỬ ELO</span>
      </div>

      <!-- Tiêu đề lớn sang trọng -->
      <h1 class="cd-hero-heading">
        <span class="cd-head-gold">KẾT NỐI SĨ TỬ</span>
        <span class="cd-head-cyan">CHIA SẺ ĐỈNH CAO</span>
      </h1>

      <p class="cd-hero-desc">
        Hỏi xoáy đáp xoay đề thi hóc búa, chia sẻ bí kíp ôn thi thực chiến và thả lửa <?= CongDongHelper::iconLua(15) ?> tiếp sức đồng đội cùng bứt phá thứ hạng Elo!
      </p>

      <!-- 4 Thẻ chip chỉ số thời gian thực chuẩn Esports -->
      <div class="cd-hero-chips">
        <div class="cd-hchip chip-posts" title="Tổng số bài viết đang mở trong cộng đồng">
          <span class="hchip-icon" aria-hidden="true">
            <svg viewBox="0 0 16 16" width="13" height="13" fill="none"><path d="M13 2H3a1 1 0 00-1 1v10a1 1 0 001 1h10a1 1 0 001-1V3a1 1 0 00-1-1z" stroke="#38BDF8" stroke-width="1.6"/><path d="M5 5.5h6M5 8h6M5 10.5h4" stroke="#BAE6FD" stroke-width="1.4" stroke-linecap="round"/></svg>
          </span>
          <strong><?= number_format($tongBaiCD) ?></strong>
          <span>BÀI VIẾT</span>
        </div>
        <div class="cd-hchip chip-comments" title="Tổng lượt thảo luận & lời giải">
          <span class="hchip-icon" aria-hidden="true">
            <svg viewBox="0 0 16 16" width="13" height="13" fill="none"><path d="M14 8c0 3.3-2.7 6-6 6a5.9 5.9 0 01-2.7-.6L2 14l.6-3.3A5.9 5.9 0 012 8c0-3.3 2.7-6 6-6s6 2.7 6 6z" stroke="#00D9FF" stroke-width="1.5"/></svg>
          </span>
          <strong><?= number_format($tongBLCD) ?></strong>
          <span>BÌNH LUẬN</span>
        </div>
        <div class="cd-hchip chip-fire" title="Tổng lượt thả lửa bài viết">
          <span class="hchip-icon" aria-hidden="true">
            <?= CongDongHelper::iconLua(16) ?>
          </span>
          <strong><?= number_format($tongThichCD) ?></strong>
          <span>LƯỢT THẢ LỬA</span>
        </div>
        <div class="cd-hchip chip-warriors" title="Số thành viên đã tham gia tranh luận & đóng góp">
          <span class="hchip-icon" aria-hidden="true">
            <svg viewBox="0 0 16 16" width="13" height="13" fill="none"><path d="M8 1.5l5.5 2.8v4.2c0 3.5-2.4 6-5.5 7-3.1-1-5.5-3.5-5.5-7V4.3L8 1.5z" stroke="#FDE047" stroke-width="1.5"/><circle cx="8" cy="7" r="2" fill="#38BDF8"/></svg>
          </span>
          <strong><?= number_format($tongSiTuCD) ?></strong>
          <span>CHIẾN THẦN</span>
        </div>
      </div>

      <!-- Nhóm nút hành động nhanh phong cách Game Esports -->
      <div class="cd-hero-actions">
        <button type="button" class="btn-hero-post" id="btnHeroDangBai">
          <span class="icon-pen" aria-hidden="true">
            <svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 2.5l2.5 2.5-8 8H3v-2.5l8-8z"/></svg>
          </span>
          <span>Viết bài ngay</span>
        </button>
        <a href="<?= e($thamTab('noibat')) ?>" class="btn-hero-highlight">
          <span class="icon-fire" aria-hidden="true">
            <?= CongDongHelper::iconLua(17) ?>
          </span>
          <span>Bài viết nổi bật</span>
        </a>
        <a href="<?= e($thamTab('hot')) ?>" class="btn-hero-ghost">
          <svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 3h12a1 1 0 011 1v7a1 1 0 01-1 1H5l-3 3V4a1 1 0 011-1z"/></svg>
          <span>Thảo luận hot</span>
        </a>
      </div>
    </div>



    <!-- CỘT PHẢI: BẢNG VINH DANH NGÔI SAO TRI THỨC TUẦN (WEEKLY MVP VIP PRO - TO RỘNG ĐẲNG CẤP) -->
    <div class="cd-hero-right">
      <div class="cd-mvp-card is-expanded" id="cdMvpCard">
        <!-- 4 Gilded Gaming Brackets (Góc Dát Vàng Đẳng Cấp) -->
        <span class="mvp-corner tl" aria-hidden="true"></span>
        <span class="mvp-corner tr" aria-hidden="true"></span>
        <span class="mvp-corner bl" aria-hidden="true"></span>
        <span class="mvp-corner br" aria-hidden="true"></span>

        <!-- Header: Vương Miện Hoàng Kim 3D + Tag MVP Tuần + Rank Badge -->
        <div class="cd-mvp-header">
          <div class="cd-mvp-tag">
            <span class="cd-mvp-crown-icon" aria-hidden="true">
              <svg viewBox="0 0 32 32" class="cd-mvp-crown-epic" aria-hidden="true">
                <defs>
                  <linearGradient id="epicCrownGrad" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stop-color="#FFFFFF"/>
                    <stop offset="25%" stop-color="#FFF0A3"/>
                    <stop offset="65%" stop-color="#F59E0B"/>
                    <stop offset="100%" stop-color="#9A5B02"/>
                  </linearGradient>
                  <linearGradient id="epicCrownGlow" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0%" stop-color="#FDE047"/>
                    <stop offset="100%" stop-color="#D97706"/>
                  </linearGradient>
                </defs>
                <!-- Laurel Wings Behind -->
                <path d="M4 18c0-5 3-9 7-11-1.5 3-1.5 7 0 10-3 0-5.5-1.5-7 1zm24 0c0-5-3-9-7-11 1.5 3 1.5 7 0 10 3 0 5.5-1.5 7 1z" fill="url(#epicCrownGlow)" opacity="0.65"/>
                <!-- 5-Point Crown Body -->
                <path d="M5 21L3 8l6.5 6L16 4l6.5 10L29 8l-2 13H5z" fill="url(#epicCrownGrad)"/>
                <!-- Crown Base Band -->
                <path d="M5 23h22v3H5z" fill="url(#epicCrownGrad)"/>
                <!-- Embedded Jewels (Rubies & Sapphires) -->
                <circle cx="16" cy="13" r="1.8" fill="#EF4444" stroke="#FFF" stroke-width="0.6"/>
                <circle cx="10" cy="16" r="1.3" fill="#38BDF8" stroke="#FFF" stroke-width="0.5"/>
                <circle cx="22" cy="16" r="1.3" fill="#38BDF8" stroke="#FFF" stroke-width="0.5"/>
                <circle cx="9" cy="24.5" r="1" fill="#FFF"/>
                <circle cx="16" cy="24.5" r="1.2" fill="#EF4444"/>
                <circle cx="23" cy="24.5" r="1" fill="#FFF"/>
              </svg>
            </span>
            <span class="cd-mvp-title-txt">NGÔI SAO TRI THỨC TUẦN</span>
          </div>
          <div class="cd-mvp-header-right">
            <span class="cd-mvp-rank-pill" title="Xếp hạng #1 Bảng vàng cống hiến">
              <svg viewBox="0 0 16 16" width="11" height="11" fill="#FDE047" aria-hidden="true"><path d="M3.6 1.5h8.8l1.6 3-6 10-6-10 1.6-3z"/></svg>
              <span>#1 BẢNG VÀNG</span>
            </span>
            <div class="cd-mvp-live-pulse" title="Vinh danh thời gian thực">
              <span class="cham-live" aria-hidden="true"></span>
              <span>TOP 1 MVP</span>
            </div>
          </div>
        </div>

        <?php if ($topMvp): ?>
        <!-- Thân thẻ: Avatar vương giả lớn + Thông tin chiến thần mở rộng -->
        <div class="cd-mvp-body">
          <div class="cd-mvp-avatar-wrap">
            <div class="cd-mvp-aura" aria-hidden="true"></div>
            <div class="cd-mvp-avatar-frame">
              <?php if (!empty($topMvp['AnhDaiDien'])): ?>
                <img src="<?= e(BASE_URL . '/' . $topMvp['AnhDaiDien']) ?>" alt="<?= e($topMvp['HoTen']) ?>" class="cd-mvp-avatar">
              <?php else: ?>
                <div class="cd-mvp-avatar-placeholder">
                  <span class="avatar-letter"><?= e(mb_strtoupper(mb_substr($topMvp['HoTen'] ?? 'S', 0, 1))) ?></span>
                  <span class="avatar-flare" aria-hidden="true"></span>
                </div>
              <?php endif; ?>
            </div>
            <!-- 3D Champion Seal Medal -->
            <div class="cd-mvp-medal" title="Chiến Thần Đóng Góp Quán Quân" aria-hidden="true">
              <svg viewBox="0 0 28 28" class="cd-mvp-seal-svg" aria-hidden="true">
                <defs>
                  <linearGradient id="sealGold" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0%" stop-color="#FFFDF5"/>
                    <stop offset="35%" stop-color="#FDE047"/>
                    <stop offset="70%" stop-color="#D4AF37"/>
                    <stop offset="100%" stop-color="#78350F"/>
                  </linearGradient>
                  <linearGradient id="sealRibbon" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0%" stop-color="#EF4444"/>
                    <stop offset="100%" stop-color="#991B1B"/>
                  </linearGradient>
                </defs>
                <!-- Ribbons -->
                <path d="M9 20l-3 7 6-2.5 3 2.5-1-7H9zm10 0l3 7-6-2.5-3 2.5 1-7h5z" fill="url(#sealRibbon)"/>
                <!-- Gold Coin -->
                <circle cx="14" cy="13" r="11" fill="url(#sealGold)" stroke="#FFF8E7" stroke-width="1.2"/>
                <circle cx="14" cy="13" r="9" fill="#0A183D" stroke="#D4AF37" stroke-width="0.8"/>
                <!-- Star Inscription -->
                <path d="M14 6l1.8 3.6 4 .6-2.9 2.8.7 4-3.6-1.9-3.6 1.9.7-4-2.9-2.8 4-.6z" fill="url(#sealGold)"/>
              </svg>
            </div>
          </div>

          <div class="cd-mvp-info">
            <div class="cd-mvp-name-row">
              <h4 class="cd-mvp-name" title="<?= e($topMvp['HoTen']) ?>"><?= e($topMvp['HoTen']) ?></h4>
              <span class="cd-mvp-pro-badge" title="Chiến thần bảng vàng tuần này">
                <svg viewBox="0 0 12 12" width="10" height="10" fill="#FDE047"><polygon points="6,0.5 7.6,4.2 11.5,4.5 8.5,7.1 9.4,11 6,8.9 2.6,11 3.5,7.1 0.5,4.5 4.4,4.2"/></svg>
                CHIẾN THẦN
              </span>
            </div>
            
            <div class="cd-mvp-tier-capsule" title="Môn sở trường và Điểm Elo xếp hạng cao nhất">
              <span class="tier-gem" aria-hidden="true">
                <svg viewBox="0 0 16 16" width="12" height="12"><polygon points="8,1 15,8 8,15 1,8" fill="#00D9FF" stroke="#8AF5FF" stroke-width="1"/><polygon points="8,4 12,8 8,12 4,8" fill="#FFF"/></svg>
              </span>
              <span class="tier-mon"><?= e($topMvp['TenMonGanh'] ?? 'Toàn diện') ?></span>
              <span class="tier-sep" aria-hidden="true">&bull;</span>
              <span class="tier-elo"><strong><?= number_format($topMvp['EloCao'] ?? 1000) ?></strong> ELO</span>
            </div>

            <!-- 3 Gaming HUD Stat Capsules -->
            <div class="cd-mvp-stats-grid">
              <div class="mvp-stat-box stat-posts" title="Tổng số bài viết cống hiến">
                <div class="stat-box-icon">
                  <svg viewBox="0 0 20 20" width="13" height="13" fill="none"><path d="M4 3h12a2 2 0 012 2v10a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2z" stroke="#38BDF8" stroke-width="1.8"/><path d="M7 7h6M7 10h6M7 13h4" stroke="#BAE6FD" stroke-width="1.6" stroke-linecap="round"/><circle cx="15" cy="5" r="1.5" fill="#38BDF8"/></svg>
                </div>
                <div class="stat-box-data">
                  <strong><?= (int) $topMvp['SoBai'] ?></strong>
                  <span>Bài viết</span>
                </div>
              </div>

              <div class="mvp-stat-box stat-comments" title="Tổng số bình luận & lời giải đáp">
                <div class="stat-box-icon">
                  <svg viewBox="0 0 20 20" width="13" height="13" fill="none"><path d="M3 4h14a2 2 0 012 2v7a2 2 0 01-2 2H7l-4 3V6a2 2 0 012-2z" stroke="#FDE047" stroke-width="1.8"/><circle cx="7" cy="9.5" r="1.2" fill="#FDE047"/><circle cx="10" cy="9.5" r="1.2" fill="#FDE047"/><circle cx="13" cy="9.5" r="1.2" fill="#FDE047"/></svg>
                </div>
                <div class="stat-box-data">
                  <strong><?= (int) $topMvp['SoBL'] ?></strong>
                  <span>Lời giải</span>
                </div>
              </div>

              <div class="mvp-stat-box stat-score" title="Tổng điểm cống hiến (Bài viết x3 + Bình luận)">
                <div class="stat-box-icon">
                  <svg viewBox="0 0 20 20" width="13" height="13" fill="none"><path d="M10 2l2.4 4.9 5.4.8-3.9 3.8.9 5.4L10 14.3 5.2 16.9l.9-5.4-3.9-3.8 5.4-.8L10 2z" stroke="#F43F5E" stroke-width="1.6" fill="rgba(244, 63, 94, 0.25)"/></svg>
                </div>
                <div class="stat-box-data">
                  <strong><?= (int) ($topMvp['SoBai'] * 3 + $topMvp['SoBL']) ?></strong>
                  <span>Điểm TOP</span>
                </div>
              </div>
            </div>
          </div>
        </div>
        <?php else: ?>
        <div class="cd-mvp-empty">
          <span class="cd-mvp-empty-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="#FDE047"><path d="M12 2l3 6.5 7 1-5 5 1.2 7-6.2-3.3-6.2 3.3 1.2-7-5-5 7-1z"/></svg>
          </span>
          <p class="cd-mvp-empty-txt">Vị trí Top 1 đang mở! Hãy đăng bài và giải đáp để được vinh danh tuần này!</p>
        </div>
        <?php endif; ?>

        <!-- Footer: Lời khuyên & Nút xem BXH Thành Viên -->
        <div class="cd-mvp-footer">
          <span class="cd-mvp-motto">&ldquo;Chia sẻ tri thức &ndash; Bứt phá giới hạn!&rdquo;</span>
          <a href="<?= e($thamTab('thanhvien')) ?>" class="cd-mvp-link" title="Xem Bảng xếp hạng thành viên nổi bật">
            <span>BẢNG VINH DANH</span>
            <svg viewBox="0 0 14 14" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4.5 2.5l4.5 4.5-4.5 4.5"/></svg>
          </a>
        </div>
      </div>
    </div>
  </div>
</section>

<div class="cd-bo-cuc">

  <!-- ===== CỘT TRÁI: BỘ LỌC TÌM KIẾM & 6 CHUYÊN MỤC DỌC (CHUẨN XÁC THEO HÌNH 1) ===== -->
  <aside class="cd-trai">
    <div class="cd-hop-trai cd-sidebar-panel">
      <span class="pet-corner tl" aria-hidden="true"></span>
      <span class="pet-corner tr" aria-hidden="true"></span>
      <span class="pet-corner bl" aria-hidden="true"></span>
      <span class="pet-corner br" aria-hidden="true"></span>

      <!-- 1. Bộ lọc tìm kiếm trên cùng của Sidebar -->
      <form method="get" action="" class="cd-loc-sidebar" id="formLocCD">
        <input type="hidden" name="tab" value="<?= e($tab) ?>">

        <!-- Chọn môn học (mặc định Tất cả môn) -->
        <div class="cd-filter-item">
          <select id="loc_mon" name="mon" class="cd-dropdown-input">
            <option value="0">Tất cả môn</option>
            <?php foreach ($monDangMo as $id => $ten): ?>
              <option value="<?= (int) $id ?>" <?= $id === $monLoc ? 'selected' : '' ?>><?= e($ten) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Lọc theo loại bài -->
        <div class="cd-filter-item">
          <label for="loc_loai" class="cd-filter-label">Lọc theo loại bài</label>
          <select id="loc_loai" name="loai" class="cd-dropdown-input">
            <option value="">Tất cả loại</option>
            <?php foreach (CongDongHelper::LOAI_BAI as $l): ?>
              <option value="<?= e($l) ?>" <?= $l === $loaiLoc ? 'selected' : '' ?>><?= e($l) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Tìm theo từ khóa -->
        <div class="cd-filter-item">
          <label for="tim_q" class="cd-filter-label">Tìm theo từ khóa</label>
          <div class="cd-tim-bar">
            <input type="search" id="tim_q" name="q" maxlength="100" value="<?= e($tuKhoa) ?>" placeholder="Tiêu đề hoặc nội..." class="cd-tim-input">
            <button class="btn-tim-action" type="submit">Tìm</button>
          </div>
        </div>

        <?php if ($thamLoc): ?>
          <div class="cd-clear-filter-wrap">
            <a class="cd-bo-loc" href="<?= e('?' . http_build_query(['tab' => $tab])) ?>">&times; Xóa bộ lọc</a>
          </div>
        <?php endif; ?>
      </form>

      <!-- 2. Danh sách 6 nút chuyên mục dọc (Chuẩn xác như hình 1) -->
      <nav class="cd-menu-list" aria-label="Danh mục cộng đồng">
        <?php foreach ($cacTab as $khoa => [$icon, $nhan]): ?>
          <a class="cd-tab-btn<?= $khoa === $tab ? ' active' : '' ?>" href="<?= e($thamTab($khoa)) ?>"<?= $khoa === $tab ? ' aria-current="page"' : '' ?>>
            <span class="cd-tab-icon" aria-hidden="true"><?= $icon ?></span>
            <span class="cd-tab-name"><?= e($nhan) ?></span>
          </a>
        <?php endforeach; ?>
      </nav>
    </div>

    <!-- Hộp Chibi Faker Cố Vấn Tinh Thần -->
    <div class="cd-hop-trai cd-trai-tip">
      <span class="pet-corner tl" aria-hidden="true"></span>
      <span class="pet-corner tr" aria-hidden="true"></span>
      <span class="pet-corner bl" aria-hidden="true"></span>
      <span class="pet-corner br" aria-hidden="true"></span>
      <div class="cd-tip-faker-box">
        <img src="<?= e(BASE_URL) ?>/assets/images/chibi-faker-avatar.jpg?v=2" alt="Chibi Faker" class="cd-tip-faker-img" id="cd-faker-btn" title="Chibi Faker Cầm Cúp Má Hồng">
        <div>
          <div class="cd-tip-badge">
            <svg viewBox="0 0 16 16" width="13" height="13" fill="#FDE047" style="vertical-align: -2px; margin-right: 4px;" aria-hidden="true"><path d="M3.5 2h9v4a4.5 4.5 0 01-9 0V2zM3.5 3.5H2a1.5 1.5 0 000 3h1.5M12.5 3.5H14a1.5 1.5 0 010 3h-1.5M8 10.5v3M5.5 13.5h5"/></svg>
            CỐ VẤN TRI THỨC
          </div>
          <p class="cd-tip-desc">Thả lửa <?= CongDongHelper::iconLua(15) ?> cho bài viết chất lượng để cùng đẩy lên Bảng Tin Nổi Bật nhé!</p>
        </div>
      </div>
      <a href="<?= e(BASE_URL) ?>/client/timtran.php" class="cd-tip-btn">
        <svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="vertical-align: -2px; margin-right: 4px;" aria-hidden="true"><path d="M2.5 13.5l4-4M13.5 2.5l-4 4M2.5 2.5l11 11"/></svg>
        Đấu Trận Ngay
      </a>
    </div>
  </aside>

  <!-- ===== CỘT PHẢI: FORM VIẾT BÀI MỚI & BẢNG TIN (CHUẨN THEO HÌNH 2) ===== -->
  <section class="cd-giua">
    <?php if ($thanhCong): ?><div class="thanhcong"><?= e($thanhCong) ?></div><?php endif; ?>
    <?php if ($loi): ?>
      <div class="loi"><?php foreach ($loi as $m): ?><div><?= e($m) ?></div><?php endforeach; ?></div>
    <?php endif; ?>

    <!-- KHUNG VIẾT BÀI MỚI CHUẨN ĐẸP (THU GỌN MẶC ĐỊNH, BẤM VÀO MỚI TUNG RA) -->
    <?php if (!in_array($tab, ['thanhvien', 'huyhieu'], true)): ?>
    <?php $moFormSan = !empty($loi) || $tieuDeBai !== '' || $noiDungBai !== ''; ?>
    <div class="cd-hop-trai cd-write-card<?= $moFormSan ? ' is-open' : '' ?>" id="khung-dang-bai">
      <span class="pet-corner tl" aria-hidden="true"></span>
      <span class="pet-corner tr" aria-hidden="true"></span>
      <span class="pet-corner bl" aria-hidden="true"></span>
      <span class="pet-corner br" aria-hidden="true"></span>

      <!-- Thanh tiêu đề thu gọn: click vào để mở rộng / thu gọn form -->
      <div class="cd-write-header" id="cd-write-toggle" role="button" tabindex="0" aria-expanded="<?= $moFormSan ? 'true' : 'false' ?>" title="Bấm để mở rộng hoặc thu gọn form viết bài">
        <div class="cd-write-header-left">
          <span class="cd-write-icon" aria-hidden="true">
            <svg viewBox="0 0 20 20" width="18" height="18" fill="none" stroke="#FDE047" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2.5l3.5 3.5-10 10H4v-3.5l10-10z"/><path d="M11.5 5l3.5 3.5"/></svg>
          </span>
          <div class="cd-write-header-titles">
            <h3 class="cd-write-title">Viết bài mới</h3>
            <span class="cd-write-subtitle">Chia sẻ thắc mắc, bí kíp hoặc thảo luận cùng cộng đồng...</span>
          </div>
        </div>
        <div class="cd-write-header-right">
          <button type="button" class="btn-toggle-write" aria-label="Mở rộng / Thu gọn form">
            <span class="toggle-text"><?= $moFormSan ? 'Thu gọn' : 'Viết bài' ?></span>
            <span class="toggle-arrow" aria-hidden="true">▼</span>
          </button>
        </div>
      </div>

      <!-- Phần thân form: mặc định thu gọn, bấm thì tung ra -->
      <div class="cd-write-body" id="cd-write-body">
        <?php if ($maND !== null): ?>
          <form method="post" action="" novalidate id="formDangBai" class="cd-write-form">
            <?= csrf_field() ?>
            
            <div class="cd-write-field">
              <label for="tieu_de" class="cd-write-label">Tiêu đề</label>
              <input type="text" id="tieu_de" name="tieu_de" maxlength="200" value="<?= e($tieuDeBai) ?>" placeholder="Tiêu đề bài viết..." class="cd-write-input" required>
            </div>

            <div class="cd-write-field cd-mon-picker-field">
              <div class="cd-mon-field-header">
                <label class="cd-write-label">Môn học (không bắt buộc)</label>
                <span class="cd-mon-current-label">Đang chọn: <strong id="monSelectedDisplay"><?= $monChon !== '' && isset($monDangMo[(int)$monChon]) ? e($monDangMo[(int)$monChon]) : 'Không gắn môn' ?></strong></span>
              </div>
              <input type="hidden" id="mon" name="mon" value="<?= e($monChon) ?>">

              <!-- Khung tìm kiếm nhanh tên môn học (Tối ưu cho 20+ môn) -->
              <div class="cd-mon-search-bar">
                <span class="mon-search-icon" aria-hidden="true">
                  <svg viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="#38BDF8" stroke-width="2" stroke-linecap="round"><circle cx="7" cy="7" r="4.5"/><path d="M10.5 10.5L14 14"/></svg>
                </span>
                <input type="text" id="monSearchInput" class="cd-mon-search-input" placeholder="Tìm kiếm nhanh tên môn học..." autocomplete="off">
                <button type="button" id="monClearSearch" class="cd-mon-clear-btn" aria-label="Xóa tìm kiếm" style="display: none;">&times;</button>
              </div>

              <!-- Lưới các thẻ môn học: mặc định hiện 5 môn đầu + Không gắn môn -->
              <div class="cd-mon-grid" id="cdMonGrid">
                <button type="button" class="cd-mon-pill<?= $monChon === '' ? ' is-selected' : '' ?>" data-mon-id="" data-mon-ten="Không gắn môn">
                  <span class="mon-pill-icon">
                    <svg viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="8" cy="8" r="6.5"/><path d="M1.5 8h13M8 1.5a10 10 0 010 13M8 1.5a10 10 0 000 13"/></svg>
                  </span>
                  <span class="mon-pill-name">Không gắn môn</span>
                </button>
                <?php 
                  $monIdx = 0;
                  foreach ($monDangMo as $id => $ten): 
                    $monIdx++;
                    $laChon = (string) $id === (string) $monChon;
                    $laExtra = $monIdx > 5 && !$laChon;
                ?>
                  <button type="button" class="cd-mon-pill<?= $laExtra ? ' is-extra' : '' ?><?= $laChon ? ' is-selected' : '' ?>" data-mon-id="<?= (int) $id ?>" data-mon-ten="<?= e($ten) ?>"<?= $laExtra ? ' style="display: none;"' : '' ?>>
                    <span class="mon-pill-icon">
                      <svg viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M2.5 3.5A2 2 0 014.5 2H14v11H4.5A2 2 0 002.5 15V3.5z"/><path d="M5.5 5.5h6M5.5 8.5h4"/></svg>
                    </span>
                    <span class="mon-pill-name"><?= e($ten) ?></span>
                  </button>
                <?php endforeach; ?>
                <div class="cd-mon-no-match" id="monNoMatch" style="display: none;">Không tìm thấy môn học nào khớp với từ khóa.</div>
              </div>

              <?php if (count($monDangMo) > 5): ?>
              <div class="cd-mon-xem-them-wrap">
                <button type="button" class="btn-xem-them-mon" id="btnXemThemMon">
                  <span class="xem-them-text">Xem thêm +<?= count($monDangMo) - 5 ?> môn khác</span>
                  <span class="xem-them-arrow" aria-hidden="true">▼</span>
                </button>
              </div>
              <?php endif; ?>
            </div>

            <div class="cd-write-field">
              <label for="loai" class="cd-write-label">Loại bài</label>
              <select id="loai" name="loai" class="cd-write-select">
                <?php foreach (CongDongHelper::LOAI_BAI as $l): ?>
                  <option value="<?= e($l) ?>" <?= $l === $loaiChon ? 'selected' : '' ?>><?= e($l) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="cd-write-field">
              <label for="noi_dung" class="cd-write-label">Nội dung</label>
              <textarea id="noi_dung" name="noi_dung" rows="4" maxlength="5000" placeholder="Viết chia sẻ, câu hỏi hoặc kinh nghiệm ôn thi của bạn ở đây..." class="cd-write-textarea" required><?= e($noiDungBai) ?></textarea>
            </div>

            <div class="cd-write-actions">
              <button type="submit" class="btn-dang-bai-gradient">
                <svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px; margin-right: 5px;"><path d="M14 2L7 9M14 2L9.5 14l-2.5-5-5-2.5L14 2z"/></svg>
                <span>Đăng bài</span>
              </button>
            </div>
          </form>
        <?php else: ?>
          <div class="cd-guest-box">
            <p class="cd-guest-msg">Đăng nhập tài khoản để viết bài thảo luận, chia sẻ bí kíp và thả lửa bài viết cùng cộng đồng!</p>
            <a href="<?= e(BASE_URL) ?>/client/login.php" class="btn-dang-bai-gradient">Đăng nhập để viết bài &rarr;</a>
          </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <header class="cd-giua-dau">
      <div class="cd-giua-dau-left">
        <span class="cd-dau-decor"></span>
        <h2><?= e($cacTab[$tab][2]) ?></h2>
      </div>
      <?php if ($p !== null && $p->trang() < $p->soTrang()): ?>
        <a class="cd-xem-them" href="<?= e('?' . http_build_query(['tab' => $tab] + $thamLoc + ['page' => $p->trang() + 1])) ?>">Xem trang kế &rarr;</a>
      <?php endif; ?>
    </header>

    <?php if ($tab === 'huyhieu'): ?>
      <div class="cd-hh-trang">
        <span class="pet-corner tl" aria-hidden="true"></span>
        <span class="pet-corner tr" aria-hidden="true"></span>
        <span class="pet-corner bl" aria-hidden="true"></span>
        <span class="pet-corner br" aria-hidden="true"></span>
        <div class="cd-hh-banner">
          <span class="cd-hh-icon-big">
            <svg viewBox="0 0 32 32" width="36" height="36" fill="none" aria-hidden="true"><defs><linearGradient id="hhBannerGold" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#FFFFFF"/><stop offset="30%" stop-color="#FFF0A3"/><stop offset="70%" stop-color="#F59E0B"/><stop offset="100%" stop-color="#9A5B02"/></linearGradient></defs><circle cx="16" cy="16" r="14" fill="rgba(253, 224, 71, 0.2)" stroke="#FDE047" stroke-width="2"/><polygon points="16,6 18.5,12 25,12.5 20,17 21.5,23.5 16,20 10.5,23.5 12,17 7,12.5 13.5,12" fill="url(#hhBannerGold)"/><circle cx="16" cy="15" r="2.5" fill="#EF4444"/></svg>
          </span>
          <div>
            <h3 class="cd-hh-tieude">Hệ Thống Phân Hạng Huy Hiệu Elo</h3>
            <p class="cd-hh-gt">Huy hiệu xuất hiện cạnh tên người dùng trong bài đăng và bình luận, phản ánh chính xác trình độ và đẳng cấp xếp hạng Elo của bạn ở từng bộ môn thi đấu.</p>
          </div>
        </div>

        <h4 class="cd-hh-muc-tieude">Bảng chuẩn các hạng Đấu Thần</h4>
        <ul class="cd-hh-bang">
          <?php foreach (CongDongHelper::bangHangElo() as $h): ?>
            <li class="cd-hh-item <?= e($h['css']) ?>">
              <span class="cd-elo <?= e($h['css']) ?>"><?= $h['icon'] ?> <?= e($h['ten']) ?></span>
              <span class="cd-hh-khoang">Elo <?= e($h['khoang']) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>

        <h4 class="cd-hh-muc-tieude">Quy tắc kích hoạt huy hiệu</h4>
        <ul class="cd-hh-luat">
          <li>Bạn đã hoàn tất từ <strong><?= (int) CongDongHelper::HUY_HIEU_TOI_THIEU_TRAN ?> trận đấu</strong> trở lên ở môn tương ứng. Chưa đủ số trận hệ thống sẽ không hiển thị huy hiệu.</li>
          <li>Bài đăng hoặc bình luận có gắn môn: huy hiệu tính chuẩn xác theo thứ hạng môn đó.</li>
          <li>Bài đăng hoặc bình luận không gắn môn: hệ thống ưu tiên huy hiệu môn bạn đạt Elo cao nhất.</li>
          <li>Hạng được cập nhật tự động sau mỗi trận thắng hoặc thua trên Đấu Trường.</li>
        </ul>
        <p class="cd-hh-ghichu">
          <svg viewBox="0 0 16 16" width="14" height="14" fill="#FDE047" style="vertical-align: -2px; margin-right: 4px;" aria-hidden="true"><path d="M8 1.5a4.5 4.5 0 00-4.5 4.5c0 1.8 1.1 3.3 2.7 4v1.5h3.6V10c1.6-.7 2.7-2.2 2.7-4A4.5 4.5 0 008 1.5zM6 13h4v1H6z"/></svg>
          <em>Mẹo: Rê chuột vào huy hiệu bất kỳ để xem chi tiết điểm Elo, tên bộ môn và số trận đã đấu.</em>
        </p>
        <p class="cd-hh-actions">
          <a class="btn" href="<?= e(BASE_URL) ?>/client/timtran.php">
            <svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="vertical-align: -2px; margin-right: 4px;" aria-hidden="true"><path d="M2.5 13.5l4-4M13.5 2.5l-4 4M2.5 2.5l11 11"/></svg>
            Tìm trận để thăng hạng ngay
          </a>
        </p>
      </div>
    <?php elseif ($tab === 'thanhvien'): ?>
      <?php if (!$thanhVien): ?>
        <div class="cd-rong">Chưa có thành viên nào hoạt động trong cộng đồng.</div>
      <?php endif; ?>
      <?php foreach ($thanhVien as $i => $tv): ?>
        <article class="cd-the cd-thanhvien cd-rank-<?= $i + 1 ?>">
          <div class="cd-hang-badge cd-hang-<?= $i + 1 ?>">
            <?php if ($i === 0): ?>
              <svg viewBox="0 0 16 16" width="14" height="14" fill="#FDE047" style="vertical-align: -2px; margin-right: 2px;" aria-hidden="true"><path d="M2 13l-1-7 4 3.5L8 3l3 6.5 4-3.5-1 7H2z"/><path d="M2 14h12v1H2z"/></svg> #1
            <?php elseif ($i === 1): ?>
              <svg viewBox="0 0 16 16" width="13" height="13" fill="#E2E8F0" style="vertical-align: -2px; margin-right: 2px;" aria-hidden="true"><circle cx="8" cy="8" r="6" stroke="#94A3B8" stroke-width="1.5"/></svg> #2
            <?php elseif ($i === 2): ?>
              <svg viewBox="0 0 16 16" width="13" height="13" fill="#F59E0B" style="vertical-align: -2px; margin-right: 2px;" aria-hidden="true"><circle cx="8" cy="8" r="6" stroke="#D97706" stroke-width="1.5"/></svg> #3
            <?php else: ?>
              #<?= $i + 1 ?>
            <?php endif; ?>
          </div>
          <?= CongDongHelper::avatar((string) $tv['HoTen'], $tv['AnhDaiDien']) ?>
          <div class="cd-the-than">
            <div class="cd-ten"><?= e($tv['HoTen']) ?></div>
            <div class="cd-tk">
              <span class="cd-tk-pill">
                <svg viewBox="0 0 16 16" width="12" height="12" fill="none" stroke="#38BDF8" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -1.5px; margin-right: 3px;" aria-hidden="true"><path d="M11 2.5l2.5 2.5-8 8H3v-2.5l8-8z"/></svg>
                <?= (int) $tv['SoBai'] ?> bài đăng
              </span>
              <span class="cd-tk-pill">
                <svg viewBox="0 0 16 16" width="12" height="12" fill="none" stroke="#00D9FF" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -1.5px; margin-right: 3px;" aria-hidden="true"><path d="M14 7.5c0 3-2.5 5.5-5.5 5.5-1 0-2-.3-2.8-.7L2 13.5l1.2-3.1C2.5 9.6 2 8.6 2 7.5 2 4.5 4.5 2 7.5 2S14 4.5 14 7.5z"/></svg>
                <?= (int) $tv['SoBL'] ?> bình luận
              </span>
              <?php if ($tv['EloCao'] !== null): ?>
                <span class="cd-tk-pill cd-tk-elo">
                  <svg viewBox="0 0 16 16" width="12" height="12" fill="#FDE047" style="vertical-align: -1.5px; margin-right: 3px;" aria-hidden="true"><polygon points="8.5,1 3,9 8,9 7.5,15 13,7 8,7"/></svg>
                  Elo cao nhất <?= number_format((int) $tv['EloCao']) ?>
                </span>
              <?php endif; ?>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    <?php else: ?>
      <?php if ($tab === 'cuatoi' && $maND === null): ?>
        <div class="cd-rong">
          <div class="cd-rong-icon">
            <svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="#FDE047" stroke-width="1.8" aria-hidden="true"><rect x="4" y="10" width="16" height="11" rx="2" stroke="#FDE047"/><path d="M8 10V7a4 4 0 118 0v3" stroke="#38BDF8"/><circle cx="12" cy="15" r="1.5" fill="#FDE047"/></svg>
          </div>
          <p>Bạn cần đăng nhập tài khoản để quản lý bài viết của mình.</p>
          <a class="btn btn-dang-bai-gradient" href="<?= e(BASE_URL) ?>/client/login.php?back=<?= urlencode(BASE_URL . '/client/congdong.php?tab=cuatoi') ?>" style="margin-top: 10px;">Đăng nhập ngay →</a>
        </div>
      <?php elseif (!$baiDang): ?>
        <div class="cd-rong">
          <div class="cd-rong-icon">
            <svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="#FDE047" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke-dasharray="3 3"/><path d="M12 2a15.3 15.3 0 014 10 15.3 15.3 0 01-4 10 15.3 15.3 0 01-4-10 15.3 15.3 0 014-10z"/><circle cx="12" cy="12" r="2.5" fill="#38BDF8"/></svg>
          </div>
          <?php if ($tuKhoa !== '' || $monLoc > 0 || $loaiLoc !== ''): ?>
            <p>Không có bài đăng nào phù hợp với bộ lọc hiện tại.</p>
            <a class="cd-bo-loc" href="<?= e('?' . http_build_query(['tab' => $tab])) ?>">Xóa bộ lọc để xem tất cả</a>
          <?php elseif ($tab === 'hot'): ?>
            <p>Tuần này chưa có cuộc thảo luận sôi nổi nào. Hãy là người mở đầu chủ đề mới!</p>
          <?php elseif ($tab === 'cuatoi'): ?>
            <p>Bạn chưa có bài đăng nào. Hãy chia sẻ câu hỏi hoặc kinh nghiệm ôn thi của bạn nhé!</p>
          <?php else: ?>
            <p>Chưa có bài đăng nào trên Đấu Trường. Hãy là người đầu tiên tạo thảo luận!</p>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php foreach ($baiDang as $b):
        $link = BASE_URL . '/client/baidang.php?id=' . (int) $b['MaBaiDang'];
        $dangAn = $b['TrangThai'] === 'Bị ẩn';
      ?>
        <article class="cd-the<?= $dangAn ? '' : ' cd-the-bam' ?>" id="bai-<?= (int) $b['MaBaiDang'] ?>"<?= $dangAn ? '' : ' data-href="' . e($link) . '#binh-luan"' ?>>
          <span class="pet-corner tl" aria-hidden="true"></span>
          <span class="pet-corner tr" aria-hidden="true"></span>
          <?= CongDongHelper::avatar((string) $b['HoTen'], $b['AnhDaiDien']) ?>
          <div class="cd-the-than">
            <div class="cd-dong-tren">
              <span class="cd-ten"><?= e($b['HoTen']) ?></span>
              <?= CongDongHelper::huyHieuElo($eloMap[(int) $b['MaND']] ?? [], $b['MaMon'] !== null ? (int) $b['MaMon'] : null) ?>
              <span class="cd-gio">&middot; <?= e(CongDongHelper::truocDo($b['NgayDang'])) ?></span>
              <?php if ($b['TenMon']): ?><span class="cd-mon"><?= e($b['TenMon']) ?></span><?php endif; ?>
              <?= CongDongHelper::nhanLoai($b['LoaiBai']) ?>
              <?php if ($dangAn): ?><span class="cd-an">Đang ẩn</span><?php endif; ?>
            </div>
            <h3 class="cd-tieu-de">
              <?php if ($dangAn): ?><?= e($b['TieuDe']) ?><?php else: ?><a href="<?= e($link) ?>"><?= e($b['TieuDe']) ?></a><?php endif; ?>
            </h3>
            <p class="cd-trich"><?= e(mb_strimwidth(preg_replace('/\s+/u', ' ', $b['NoiDung']), 0, 140, '…')) ?></p>
            <div class="cd-so-lieu">
              <?php if ($dangAn): ?>
                <span class="cd-chi-so cd-tha-lua-static" title="Lượt thả lửa">
                  <?= CongDongHelper::iconLua(18) ?>
                  <span class="flame-count"><?= (int) $b['SoThich'] ?></span>
                </span>
              <?php elseif ($coLike): ?>
                <form method="post" action="" class="cd-like-form" data-bai-id="<?= (int) $b['MaBaiDang'] ?>">
                  <?= csrf_field() ?>
                  <input type="hidden" name="hanh_dong" value="thich">
                  <input type="hidden" name="ma" value="<?= (int) $b['MaBaiDang'] ?>">
                  <input type="hidden" name="tab" value="<?= e($tab) ?>">
                  <input type="hidden" name="mon" value="<?= (int) $monLoc ?>">
                  <input type="hidden" name="loai" value="<?= e($loaiLoc) ?>">
                  <input type="hidden" name="q" value="<?= e($tuKhoa) ?>">
                  <input type="hidden" name="page" value="<?= $p ? (int) $p->trang() : 1 ?>">
                  <button type="submit" class="cd-chi-so cd-tha-lua<?= (int) $b['DaThich'] > 0 ? ' da-tha-lua' : '' ?>"
                          title="<?= (int) $b['DaThich'] > 0 ? 'Dập lửa (Bỏ thả lửa)' : 'Thả lửa bài viết này' ?>">
                    <span class="flame-icon">
                      <?= CongDongHelper::iconLua(18) ?>
                    </span>
                    <span class="flame-count"><?= (int) $b['SoThich'] ?></span>
                  </button>
                </form>
              <?php endif; ?>
              <?php if ($dangAn): ?>
                <span class="cd-chi-so">
                  <svg viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -1.5px; margin-right: 3px;" aria-hidden="true"><path d="M14 7.5c0 3-2.5 5.5-5.5 5.5-1 0-2-.3-2.8-.7L2 13.5l1.2-3.1C2.5 9.6 2 8.6 2 7.5 2 4.5 4.5 2 7.5 2S14 4.5 14 7.5z"/></svg>
                  <?= (int) $b['SoBL'] ?>
                </span>
              <?php else: ?>
                <a class="cd-chi-so" href="<?= e($link) ?>#binh-luan" title="Xem thảo luận & bình luận">
                  <svg viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -1.5px; margin-right: 3px;" aria-hidden="true"><path d="M14 7.5c0 3-2.5 5.5-5.5 5.5-1 0-2-.3-2.8-.7L2 13.5l1.2-3.1C2.5 9.6 2 8.6 2 7.5 2 4.5 4.5 2 7.5 2S14 4.5 14 7.5z"/></svg>
                  <?= (int) $b['SoBL'] ?>
                </a>
              <?php endif; ?>
            </div>

            <?php if (!$dangAn && !empty($blXemTruoc[(int) $b['MaBaiDang']])): ?>
              <div class="cd-bl-xem">
                <?php foreach ($blXemTruoc[(int) $b['MaBaiDang']] as $bl): ?>
                  <div class="cd-bl-xem-muc">
                    <?= CongDongHelper::avatar((string) $bl['HoTen'], $bl['AnhDaiDien'], 'cd-av cd-av-nho') ?>
                    <div class="cd-bl-xem-nd">
                      <span class="cd-bl-xem-ten"><?= e($bl['HoTen']) ?></span>
                      <span class="cd-gio">&middot; <?= e(CongDongHelper::truocDo($bl['NgayBinhLuan'])) ?></span>
                      <p><?= e(mb_strimwidth(preg_replace('/\s+/u', ' ', $bl['NoiDung']), 0, 120, '…')) ?></p>
                    </div>
                  </div>
                <?php endforeach; ?>
                <?php if ((int) $b['SoBL'] > count($blXemTruoc[(int) $b['MaBaiDang']])): ?>
                  <a class="cd-bl-xem-them" href="<?= e($link) ?>#binh-luan">Xem tất cả <?= (int) $b['SoBL'] ?> bình luận &rsaquo;</a>
                <?php endif; ?>
              </div>
            <?php endif; ?>

            <?php if ($tab === 'cuatoi'): ?>
            <!-- Thao tác của chủ bài: sửa (trong 15 phút), ẩn/mở lại và xóa (mọi lúc) -->
            <div class="cd-hanh-dong">
              <?php if (!$dangAn): ?>
                <?php if (CongDongHelper::conHanSua((int) $b['GiayTuDang'])): ?>
                  <a class="btn btn-phu" href="<?= e(BASE_URL) ?>/client/suabai.php?id=<?= (int) $b['MaBaiDang'] ?>">
                    <svg viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" style="vertical-align: -1.5px; margin-right: 3px;" aria-hidden="true"><path d="M11 2.5l2.5 2.5-8 8H3v-2.5l8-8z"/></svg>
                    Sửa bài
                  </a>
                <?php endif; ?>
                <?php if (CongDongHelper::coCotNguoiAn()): ?>
                  <form method="post" action="" data-xacnhan="Ẩn bài đăng này? Bạn có thể mở lại bất cứ lúc nào trong mục Bài của tôi.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="hanh_dong" value="an_bai">
                    <input type="hidden" name="ma" value="<?= (int) $b['MaBaiDang'] ?>">
                    <input type="hidden" name="q" value="<?= e($tuKhoa) ?>">
                    <input type="hidden" name="mon" value="<?= (int) $monLoc ?>">
                    <input type="hidden" name="page" value="<?= (int) $p->trang() ?>">
                    <button type="submit" class="btn btn-phu">
                      <svg viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.8" style="vertical-align: -1.5px; margin-right: 3px;" aria-hidden="true"><path d="M2 8s2.5-4.5 6-4.5S14 8 14 8s-2.5 4.5-6 4.5S2 8 2 8z"/><circle cx="8" cy="8" r="2"/></svg>
                      Ẩn bài
                    </button>
                  </form>
                <?php endif; ?>
              <?php elseif ($b['NguoiAn'] === 'Chủ bài'): ?>
                <form method="post" action="">
                  <?= csrf_field() ?>
                  <input type="hidden" name="hanh_dong" value="mo_bai">
                  <input type="hidden" name="ma" value="<?= (int) $b['MaBaiDang'] ?>">
                  <input type="hidden" name="q" value="<?= e($tuKhoa) ?>">
                  <input type="hidden" name="mon" value="<?= (int) $monLoc ?>">
                  <input type="hidden" name="page" value="<?= (int) $p->trang() ?>">
                  <button type="submit" class="btn btn-phu">
                    <svg viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.8" style="vertical-align: -1.5px; margin-right: 3px;" aria-hidden="true"><rect x="3" y="7" width="10" height="7" rx="1.5"/><path d="M5.5 7V4.5a2.5 2.5 0 015 0V7"/></svg>
                    Mở lại bài
                  </button>
                </form>
              <?php else: ?>
                <span class="cd-ghi-chu">Bài này do quản trị viên ẩn, bạn không thể mở lại.</span>
              <?php endif; ?>
              <form method="post" action="" data-xacnhan="Xóa bài đăng này? Bài đã xóa không thể khôi phục.">
                <?= csrf_field() ?>
                <input type="hidden" name="hanh_dong" value="xoa_bai">
                <input type="hidden" name="ma" value="<?= (int) $b['MaBaiDang'] ?>">
                <input type="hidden" name="q" value="<?= e($tuKhoa) ?>">
                <input type="hidden" name="mon" value="<?= (int) $monLoc ?>">
                <input type="hidden" name="page" value="<?= (int) $p->trang() ?>">
                <button type="submit" class="btn btn-phu btn-xoa">
                  <svg viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" style="vertical-align: -1.5px; margin-right: 3px;" aria-hidden="true"><path d="M3 4.5h10M6 4.5V3a1 1 0 011-1h2a1 1 0 011 1v1.5M4.5 4.5v8.5a1.5 1.5 0 001.5 1.5h4a1.5 1.5 0 001.5-1.5V4.5"/></svg>
                  Xóa bài
                </button>
              </form>
            </div>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>

      <?= $p->links() ?>
    <?php endif; ?>
  </section>

</div>
<?php require __DIR__ . '/layout/footer.php'; ?>