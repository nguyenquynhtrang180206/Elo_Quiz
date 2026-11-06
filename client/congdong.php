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

// ---- Bộ lọc: tab và môn ----
$cacTab = [
    'moi'      => ['✏️', 'Bài đăng mới',      'Bài đăng mới nhất'],
    'noibat'   => ['🔥', 'Bài viết nổi bật',  'Bài viết nổi bật'],
    'hot'      => ['💬', 'Thảo luận hot',     'Thảo luận hot trong 7 ngày'],
    'thanhvien'=> ['⭐', 'Thành viên nổi bật', 'Thành viên nổi bật'],
];
// "Bài của tôi": chủ bài xem lại bài của mình, kể cả bài đang ẩn (để tự mở lại bài do mình ẩn)
if ($maND !== null) {
    $cacTab['cuatoi'] = ['📁', 'Bài của tôi', 'Bài của tôi'];
}
// Trang giải thích huy hiệu Elo: chỉ là nội dung tĩnh, không truy vấn bài đăng
$cacTab['huyhieu'] = ['🏅', 'Huy hiệu là gì?', 'Huy hiệu là gì?'];
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
// Tham số lọc giữ nguyên khi đổi tab, sang trang, thích bài...
$thamLoc = ($monLoc > 0 ? ['mon' => $monLoc] : []) + ($loaiLoc !== '' ? ['loai' => $loaiLoc] : []) + ($tuKhoa !== '' ? ['q' => $tuKhoa] : []);
$thamTab = fn(string $t): string => '?' . http_build_query(['tab' => $t] + $thamLoc);

$loi = [];
$tieuDeBai = $noiDungBai = $monChon = '';
$loaiChon = CongDongHelper::LOAI_MAC_DINH;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if ($maND === null) {
        redirect(BASE_URL . '/client/login.php');
    }
    csrf_check();

    if (($_POST['hanh_dong'] ?? '') === 'thich') {
        // ---- Thích / bỏ thích một bài ----
        $maBai = (int) ($_POST['ma'] ?? 0);
        $st = $pdo->prepare("SELECT COUNT(*) FROM BAIDANG WHERE MaBaiDang = ? AND TrangThai = 'Hiển thị'");
        $st->execute([$maBai]);
        if ($coLike && (int) $st->fetchColumn() === 1) {
            $st = $pdo->prepare('INSERT IGNORE INTO LUOTTHICH (MaBaiDang, MaND) VALUES (?, ?)');
            $st->execute([$maBai, $maND]);
            if ($st->rowCount() === 0) {
                $st = $pdo->prepare('DELETE FROM LUOTTHICH WHERE MaBaiDang = ? AND MaND = ?');
                $st->execute([$maBai, $maND]);
            }
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
}

$tieuDe = 'Cộng đồng';
$cssTrang = 'congdong';
$jsTrang  = 'congdong';
$bodyClass = 'trang-cd';
require __DIR__ . '/layout/header.php';
?>

<!-- ===== 1. COMMUNITY HERO BANNER ===== -->
<section class="cd-hero">
  <div class="cd-hero-aurora" aria-hidden="true"></div>
  <div class="cd-hero-grid" aria-hidden="true"></div>

  <!-- SÂN KHẤU CHIBI FAKER TƯƠNG TÁC (Má hồng, mắt to long lanh, ôm cúp vàng) -->
  <div class="cd-faker-stage" id="cd-faker-stage">
    <div class="cd-faker-aura" aria-hidden="true"></div>

    <!-- Khung Avatar Chibi Faker má hồng mắt to long lanh -->
    <div class="cd-hero-pet" id="cd-faker-btn" title="Chibi Faker Cầm Cúp Vô Địch">
      <span class="pet-corner tl" aria-hidden="true"></span>
      <span class="pet-corner tr" aria-hidden="true"></span>
      <span class="pet-corner bl" aria-hidden="true"></span>
      <span class="pet-corner br" aria-hidden="true"></span>
      <img src="<?= e(BASE_URL) ?>/assets/images/chibi-faker-avatar.jpg" alt="Chibi Faker Cầm Cúp Má Hồng">
      <span class="cd-pet-glow-ring" aria-hidden="true"></span>
    </div>

    <!-- Mini HUD Badge -->
    <div class="cd-hud-badge">
      <span class="cham-live"></span>
      <span>CỐ VẤN TINH THẦN</span>
    </div>
  </div>

  <div class="cd-hero-center">
    <div class="cd-live-pill">
      <span class="cd-live-ping" aria-hidden="true"></span>
      <span>QUIZUP ARENA &bull; DIỄN ĐÀN ĐẤU THẦN</span>
    </div>
    <h1 class="cd-hero-title">CỘNG ĐỒNG CHIẾN THẦN</h1>
    <div class="cd-hero-divider" aria-hidden="true"></div>
    <p class="cd-hero-sub">Giao lưu kinh nghiệm thi đấu, bí kíp leo rank &amp; thảo luận học thuật</p>
  </div>

  <div class="cd-hero-quote-box">
    <div class="cd-quote-header">
      <span class="cd-quote-sparkle" aria-hidden="true">✨</span>
      <span class="cd-quote-tag">TRẠM TIẾP SỨC</span>
    </div>
    <p class="cd-loi-dan">“Trao đổi học hỏi,<br>bứt phá đỉnh cao tri thức!”</p>
    <?php if ($maND !== null): ?>
      <a class="cd-quick-post-btn" href="#dang-bai" data-mo-form>✍️ Viết bài mới</a>
    <?php else: ?>
      <a class="cd-quick-post-btn" href="<?= e(BASE_URL) ?>/client/login.php">Đăng nhập để viết bài</a>
    <?php endif; ?>
  </div>
</section>

<div class="cd-bo-cuc">

  <!-- ===== Cột trái: bộ lọc + menu chuyên mục ===== -->
  <aside class="cd-trai">
    <?php if (!in_array($tab, ['thanhvien', 'huyhieu'], true)): ?>
    <div class="cd-hop-trai">
      <span class="pet-corner tl" aria-hidden="true"></span>
      <span class="pet-corner tr" aria-hidden="true"></span>
      <span class="pet-corner bl" aria-hidden="true"></span>
      <span class="pet-corner br" aria-hidden="true"></span>
      <div class="cd-hop-tieude">
        <span class="cd-hop-icon">⚡</span>
        <h3>Bộ lọc tìm kiếm</h3>
      </div>
      <form method="get" action="" class="cd-loc">
        <input type="hidden" name="tab" value="<?= e($tab) ?>">
        <label for="loc_mon">Theo môn học</label>
        <select id="loc_mon" name="mon">
          <option value="0">Tất cả môn</option>
          <?php foreach ($monDangMo as $id => $ten): ?>
            <option value="<?= (int) $id ?>" <?= $id === $monLoc ? 'selected' : '' ?>><?= e($ten) ?></option>
          <?php endforeach; ?>
        </select>

        <?php if ($coLoai): ?>
        <label for="loc_loai">Phân loại bài</label>
        <select id="loc_loai" name="loai">
          <option value="">Tất cả loại</option>
          <?php foreach (CongDongHelper::LOAI_BAI as $l): ?>
            <option value="<?= e($l) ?>" <?= $l === $loaiLoc ? 'selected' : '' ?>><?= e($l) ?></option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>

        <label for="tim_q">Từ khóa nội dung</label>
        <div class="cd-tim">
          <input type="search" id="tim_q" name="q" maxlength="100" value="<?= e($tuKhoa) ?>" placeholder="Tiêu đề hoặc nội dung...">
          <button class="btn btn-phu" type="submit">Tìm</button>
        </div>
        <?php if ($thamLoc): ?>
          <a class="cd-bo-loc" href="<?= e('?' . http_build_query(['tab' => $tab])) ?>">&times; Xóa bộ lọc</a>
        <?php endif; ?>
      </form>
    </div>
    <?php endif; ?>

    <div class="cd-hop-trai">
      <span class="pet-corner tl" aria-hidden="true"></span>
      <span class="pet-corner tr" aria-hidden="true"></span>
      <span class="pet-corner bl" aria-hidden="true"></span>
      <span class="pet-corner br" aria-hidden="true"></span>
      <div class="cd-hop-tieude">
        <span class="cd-hop-icon">🧭</span>
        <h3>Chuyên mục</h3>
      </div>
      <nav class="cd-menu" aria-label="Danh mục cộng đồng">
        <?php foreach ($cacTab as $khoa => [$icon, $nhan]): ?>
          <a class="cd-menu-muc<?= $khoa === $tab ? ' dang-chon' : '' ?>" href="<?= e($thamTab($khoa)) ?>"<?= $khoa === $tab ? ' aria-current="page"' : '' ?>>
            <span class="cd-menu-icon" aria-hidden="true"><?= $icon ?></span>
            <span class="cd-menu-text"><?= e($nhan) ?></span>
            <?php if ($khoa === $tab): ?><span class="cd-menu-active-dot" aria-hidden="true"></span><?php endif; ?>
          </a>
        <?php endforeach; ?>
      </nav>
    </div>

    <div class="cd-hop-trai cd-trai-tip">
      <span class="pet-corner tl" aria-hidden="true"></span>
      <span class="pet-corner tr" aria-hidden="true"></span>
      <span class="pet-corner bl" aria-hidden="true"></span>
      <span class="pet-corner br" aria-hidden="true"></span>
      <div class="cd-tip-faker-box">
        <img src="<?= e(BASE_URL) ?>/assets/images/chibi-faker-avatar.jpg" alt="Chibi Faker" class="cd-tip-faker-img">
        <div>
          <div class="cd-tip-badge">🏆 Faker Buff</div>
          <p class="cd-tip-desc">Gắn môn học vào bài viết để hiện chuẩn huy hiệu Elo từng môn!</p>
        </div>
      </div>
      <a href="<?= e(BASE_URL) ?>/client/timtran.php" class="cd-tip-btn">⚔️ Đấu Trận Ngay</a>
    </div>
  </aside>

  <!-- ===== Cột phải: nội dung (bố cục 2 cột: menu/lọc trái, nội dung phải) ===== -->
  <section class="cd-giua">
    <?php if ($thanhCong): ?><div class="thanhcong"><?= e($thanhCong) ?></div><?php endif; ?>
    <?php if ($loi): ?>
      <div class="loi"><?php foreach ($loi as $m): ?><div><?= e($m) ?></div><?php endforeach; ?></div>
    <?php endif; ?>

    <?php if ($maND !== null && $tab !== 'huyhieu'): ?>
    <details class="cd-dang" id="dang-bai"<?= $loi ? ' open' : '' ?>>
      <span class="pet-corner tl" aria-hidden="true"></span>
      <span class="pet-corner tr" aria-hidden="true"></span>
      <span class="pet-corner bl" aria-hidden="true"></span>
      <span class="pet-corner br" aria-hidden="true"></span>
      <summary><span class="cd-dang-icon">✍️</span> <strong>Chia sẻ bài viết mới lên Đấu Trường</strong> <span class="cd-dang-arrow">&rsaquo;</span></summary>
      <form method="post" action="" novalidate>
        <?= csrf_field() ?>
        <label for="tieu_de">Tiêu đề bài viết</label>
        <input type="text" id="tieu_de" name="tieu_de" maxlength="200" value="<?= e($tieuDeBai) ?>" placeholder="Nhập tiêu đề ngắn gọn, rõ ràng..." required>

        <div class="cd-dang-hang2">
          <div>
            <label for="mon">Môn học (tùy chọn)</label>
            <select id="mon" name="mon">
              <option value="">Không gắn môn</option>
              <?php foreach ($monDangMo as $id => $ten): ?>
                <option value="<?= (int) $id ?>" <?= (string) $id === $monChon ? 'selected' : '' ?>><?= e($ten) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <?php if ($coLoai): ?>
          <div>
            <label for="loai">Phân loại bài</label>
            <select id="loai" name="loai">
              <?php foreach (CongDongHelper::LOAI_BAI as $l): ?>
                <option value="<?= e($l) ?>" <?= $l === $loaiChon ? 'selected' : '' ?>><?= e($l) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
        </div>

        <label for="noi_dung">Nội dung chi tiết</label>
        <textarea id="noi_dung" name="noi_dung" maxlength="5000" placeholder="Viết chia sẻ, câu hỏi hoặc mẹo ôn thi của bạn ở đây..." required><?= e($noiDungBai) ?></textarea>

        <p class="cd-dang-submit"><button type="submit" class="btn">🚀 Đăng bài ngay</button></p>
      </form>
    </details>
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
          <span class="cd-hh-icon-big">🏅</span>
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
        <p class="cd-hh-ghichu">💡 <em>Mẹo: Rê chuột vào huy hiệu bất kỳ để xem chi tiết điểm Elo, tên bộ môn và số trận đã đấu.</em></p>
        <p class="cd-hh-actions"><a class="btn" href="<?= e(BASE_URL) ?>/client/timtran.php">⚔️ Tìm trận để thăng hạng ngay</a></p>
      </div>
    <?php elseif ($tab === 'thanhvien'): ?>
      <?php if (!$thanhVien): ?>
        <div class="cd-rong">Chưa có thành viên nào hoạt động trong cộng đồng.</div>
      <?php endif; ?>
      <?php foreach ($thanhVien as $i => $tv): ?>
        <article class="cd-the cd-thanhvien cd-rank-<?= $i + 1 ?>">
          <div class="cd-hang-badge cd-hang-<?= $i + 1 ?>">
            <?php if ($i === 0): ?>👑 #1<?php elseif ($i === 1): ?>🥈 #2<?php elseif ($i === 2): ?>🥉 #3<?php else: ?>#<?= $i + 1 ?><?php endif; ?>
          </div>
          <?= CongDongHelper::avatar((string) $tv['HoTen'], $tv['AnhDaiDien']) ?>
          <div class="cd-the-than">
            <div class="cd-ten"><?= e($tv['HoTen']) ?></div>
            <div class="cd-tk">
              <span class="cd-tk-pill">📝 <?= (int) $tv['SoBai'] ?> bài đăng</span>
              <span class="cd-tk-pill">💬 <?= (int) $tv['SoBL'] ?> bình luận</span>
              <?php if ($tv['EloCao'] !== null): ?><span class="cd-tk-pill cd-tk-elo">⚡ Elo cao nhất <?= number_format((int) $tv['EloCao']) ?></span><?php endif; ?>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    <?php else: ?>
      <?php if (!$baiDang): ?>
        <div class="cd-rong">
          <div class="cd-rong-icon">🪐</div>
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
        <article class="cd-the" id="bai-<?= (int) $b['MaBaiDang'] ?>">
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
              <span class="cd-chi-so">👍 <?= (int) $b['SoThich'] ?></span>
              <?php elseif ($coLike): ?>
              <form method="post" action="" class="cd-like-form">
                <?= csrf_field() ?>
                <input type="hidden" name="hanh_dong" value="thich">
                <input type="hidden" name="ma" value="<?= (int) $b['MaBaiDang'] ?>">
                <input type="hidden" name="tab" value="<?= e($tab) ?>">
                <input type="hidden" name="mon" value="<?= (int) $monLoc ?>">
                <input type="hidden" name="loai" value="<?= e($loaiLoc) ?>">
                <input type="hidden" name="q" value="<?= e($tuKhoa) ?>">
                <input type="hidden" name="page" value="<?= $p ? (int) $p->trang() : 1 ?>">
                <button type="submit" class="cd-chi-so cd-thich<?= (int) $b['DaThich'] > 0 ? ' da-thich' : '' ?>"
                        title="<?= (int) $b['DaThich'] > 0 ? 'Bỏ thích' : 'Thích bài này' ?>">
                  👍 <span><?= (int) $b['SoThich'] ?></span>
                </button>
              </form>
              <?php endif; ?>
              <?php if ($dangAn): ?>
              <span class="cd-chi-so">💬 <?= (int) $b['SoBL'] ?></span>
              <?php else: ?>
              <a class="cd-chi-so" href="<?= e($link) ?>#binh-luan" title="Xem thảo luận & bình luận">💬 <?= (int) $b['SoBL'] ?></a>
              <?php endif; ?>
            </div>

            <?php if ($tab === 'cuatoi'): ?>
            <!-- Thao tác của chủ bài: sửa (trong 15 phút), ẩn/mở lại và xóa (mọi lúc) -->
            <div class="cd-hanh-dong">
              <?php if (!$dangAn): ?>
                <?php if (CongDongHelper::conHanSua((int) $b['GiayTuDang'])): ?>
                  <a class="btn btn-phu" href="<?= e(BASE_URL) ?>/client/suabai.php?id=<?= (int) $b['MaBaiDang'] ?>">✏️ Sửa bài</a>
                <?php endif; ?>
                <?php if (CongDongHelper::coCotNguoiAn()): ?>
                  <form method="post" action="" data-xacnhan="Ẩn bài đăng này? Bạn có thể mở lại bất cứ lúc nào trong mục Bài của tôi.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="hanh_dong" value="an_bai">
                    <input type="hidden" name="ma" value="<?= (int) $b['MaBaiDang'] ?>">
                    <input type="hidden" name="q" value="<?= e($tuKhoa) ?>">
                    <input type="hidden" name="mon" value="<?= (int) $monLoc ?>">
                    <input type="hidden" name="page" value="<?= (int) $p->trang() ?>">
                    <button type="submit" class="btn btn-phu">👁️ Ẩn bài</button>
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
                  <button type="submit" class="btn btn-phu">🔓 Mở lại bài</button>
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
                <button type="submit" class="btn btn-phu btn-xoa">🗑️ Xóa bài</button>
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