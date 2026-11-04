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
$tab = (string) ($_GET['tab'] ?? 'moi');
if (!isset($cacTab[$tab])) {
    $tab = 'moi';
}
$monLoc = (int) ($_GET['mon'] ?? 0);
if (!isset($monDangMo[$monLoc])) {
    $monLoc = 0;
}
$tuKhoa = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
// Tham số lọc giữ nguyên khi đổi tab, sang trang, thích bài...
$thamLoc = ($monLoc > 0 ? ['mon' => $monLoc] : []) + ($tuKhoa !== '' ? ['q' => $tuKhoa] : []);
$thamTab = fn(string $t): string => '?' . http_build_query(['tab' => $t] + $thamLoc);

$loi = [];
$tieuDeBai = $noiDungBai = $monChon = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

    [$loi, $maMon] = CongDongHelper::kiemTraBai($tieuDeBai, $noiDungBai, $monChon, $monDangMo);

    if (!$loi) {
        $st = $pdo->prepare('INSERT INTO BAIDANG (MaND, MaMon, TieuDe, NoiDung) VALUES (?, ?, ?, ?)');
        $st->execute([$maND, $maMon, $tieuDeBai, $noiDungBai]);
        CongDongHelper::flash('Đã đăng bài.');
        redirect(BASE_URL . '/client/congdong.php');
    }
}

$thanhCong = CongDongHelper::flash();

$baiDang = [];
$thanhVien = [];
$p = null;

if ($tab === 'thanhvien') {
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
        "SELECT b.MaBaiDang, b.TieuDe, b.NoiDung, b.NgayDang, b.TrangThai, u.MaND, u.HoTen, u.AnhDaiDien, m.TenMon,
                TIMESTAMPDIFF(SECOND, b.NgayDang, NOW()) AS GiayTuDang,
                " . (CongDongHelper::coCotNguoiAn() ? 'b.NguoiAn' : 'NULL AS NguoiAn') . ",
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
}

$tieuDe = 'Cộng đồng';
$cssTrang = 'congdong';
$jsTrang  = 'congdong';
require __DIR__ . '/layout/header.php';
?>
<div class="container">
  <div class="cd-bo-cuc">

    <!-- ===== Cột trái: menu ===== -->
    <aside class="cd-trai">
      <?php if ($tab !== 'thanhvien'): ?>
      <form method="get" action="" class="cd-loc">
        <input type="hidden" name="tab" value="<?= e($tab) ?>">
        <label for="loc_mon">Lọc theo môn</label>
        <select id="loc_mon" name="mon">
          <option value="0">Tất cả môn</option>
          <?php foreach ($monDangMo as $id => $ten): ?>
            <option value="<?= (int) $id ?>" <?= $id === $monLoc ? 'selected' : '' ?>><?= e($ten) ?></option>
          <?php endforeach; ?>
        </select>

        <label for="tim_q">Tìm theo từ khóa</label>
        <div class="cd-tim">
          <input type="search" id="tim_q" name="q" maxlength="100" value="<?= e($tuKhoa) ?>" placeholder="Tiêu đề hoặc nội dung...">
          <button class="btn btn-phu" type="submit">Tìm</button>
        </div>
        <?php if ($thamLoc): ?>
          <a class="cd-bo-loc" href="<?= e('?' . http_build_query(['tab' => $tab])) ?>">&times; Bỏ lọc</a>
        <?php endif; ?>
      </form>
      <?php endif; ?>

      <nav class="cd-menu" aria-label="Danh mục cộng đồng">
        <?php foreach ($cacTab as $khoa => [$icon, $nhan]): ?>
          <a class="cd-menu-muc<?= $khoa === $tab ? ' dang-chon' : '' ?>" href="<?= e($thamTab($khoa)) ?>"<?= $khoa === $tab ? ' aria-current="page"' : '' ?>>
            <span class="cd-menu-icon" aria-hidden="true"><?= $icon ?></span>
            <span><?= e($nhan) ?></span>
          </a>
        <?php endforeach; ?>
      </nav>
    </aside>

    <!-- ===== Cột phải: nội dung (bố cục 2 cột: menu/lọc trái, nội dung phải) ===== -->
    <section class="cd-giua">
      <?php if ($thanhCong): ?><div class="thanhcong"><?= e($thanhCong) ?></div><?php endif; ?>
      <?php if ($loi): ?>
        <div class="loi"><?php foreach ($loi as $m): ?><div><?= e($m) ?></div><?php endforeach; ?></div>
      <?php endif; ?>

      <?php if ($maND !== null): ?>
      <details class="cd-dang" id="dang-bai"<?= $loi ? ' open' : '' ?>>
        <summary>✍️ Viết bài mới</summary>
        <form method="post" action="" novalidate>
          <?= csrf_field() ?>
          <label for="tieu_de">Tiêu đề</label>
          <input type="text" id="tieu_de" name="tieu_de" maxlength="200" value="<?= e($tieuDeBai) ?>" required>

          <label for="mon">Môn học (không bắt buộc)</label>
          <select id="mon" name="mon">
            <option value="">Không gắn môn</option>
            <?php foreach ($monDangMo as $id => $ten): ?>
              <option value="<?= (int) $id ?>" <?= (string) $id === $monChon ? 'selected' : '' ?>><?= e($ten) ?></option>
            <?php endforeach; ?>
          </select>

          <label for="noi_dung">Nội dung</label>
          <textarea id="noi_dung" name="noi_dung" maxlength="5000" required><?= e($noiDungBai) ?></textarea>

          <p><button type="submit" class="btn">Đăng bài</button></p>
        </form>
      </details>
      <?php endif; ?>

      <header class="cd-giua-dau">
        <h2><?= e($cacTab[$tab][2]) ?></h2>
        <?php if ($p !== null && $p->trang() < $p->soTrang()): ?>
          <a class="cd-xem-them" href="<?= e('?' . http_build_query(['tab' => $tab] + $thamLoc + ['page' => $p->trang() + 1])) ?>">Xem thêm &rarr;</a>
        <?php endif; ?>
      </header>

      <?php if ($tab === 'thanhvien'): ?>
        <?php if (!$thanhVien): ?>
          <div class="cd-rong">Chưa có thành viên nào hoạt động trong cộng đồng.</div>
        <?php endif; ?>
        <?php foreach ($thanhVien as $i => $tv): ?>
          <article class="cd-the cd-thanhvien">
            <span class="cd-hang">#<?= $i + 1 ?></span>
            <?= CongDongHelper::avatar((string) $tv['HoTen'], $tv['AnhDaiDien']) ?>
            <div class="cd-the-than">
              <div class="cd-ten"><?= e($tv['HoTen']) ?></div>
              <div class="cd-tk">
                <span><?= (int) $tv['SoBai'] ?> bài đăng</span>
                <span><?= (int) $tv['SoBL'] ?> bình luận</span>
                <?php if ($tv['EloCao'] !== null): ?><span>Elo cao nhất <?= number_format((int) $tv['EloCao']) ?></span><?php endif; ?>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      <?php else: ?>
        <?php if (!$baiDang): ?>
          <div class="cd-rong">
            <?php if ($tuKhoa !== '' || $monLoc > 0): ?>
              Không có bài đăng nào phù hợp với bộ lọc.
            <?php elseif ($tab === 'hot'): ?>
              Tuần này chưa có thảo luận nào sôi nổi.
            <?php elseif ($tab === 'cuatoi'): ?>
              Bạn chưa có bài đăng nào.
            <?php else: ?>
              Chưa có bài đăng nào.
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php foreach ($baiDang as $b):
          $link = BASE_URL . '/client/baidang.php?id=' . (int) $b['MaBaiDang'];
          $dangAn = $b['TrangThai'] === 'Bị ẩn';
        ?>
          <article class="cd-the" id="bai-<?= (int) $b['MaBaiDang'] ?>">
            <?= CongDongHelper::avatar((string) $b['HoTen'], $b['AnhDaiDien']) ?>
            <div class="cd-the-than">
              <div class="cd-dong-tren">
                <span class="cd-ten"><?= e($b['HoTen']) ?></span>
                <span class="cd-gio">&middot; <?= e(CongDongHelper::truocDo($b['NgayDang'])) ?></span>
                <?php if ($b['TenMon']): ?><span class="cd-mon"><?= e($b['TenMon']) ?></span><?php endif; ?>
                <?php if ($dangAn): ?><span class="cd-an">Đang ẩn</span><?php endif; ?>
              </div>
              <h3 class="cd-tieu-de">
                <?php if ($dangAn): ?><?= e($b['TieuDe']) ?><?php else: ?><a href="<?= e($link) ?>"><?= e($b['TieuDe']) ?></a><?php endif; ?>
              </h3>
              <p class="cd-trich"><?= e(mb_strimwidth(preg_replace('/\s+/u', ' ', $b['NoiDung']), 0, 120, '…')) ?></p>
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
                  <input type="hidden" name="q" value="<?= e($tuKhoa) ?>">
                  <input type="hidden" name="page" value="<?= $p ? (int) $p->trang() : 1 ?>">
                  <button type="submit" class="cd-chi-so cd-thich<?= (int) $b['DaThich'] > 0 ? ' da-thich' : '' ?>"
                          title="<?= (int) $b['DaThich'] > 0 ? 'Bỏ thích' : 'Thích bài này' ?>">
                    👍 <?= (int) $b['SoThich'] ?>
                  </button>
                </form>
                <?php endif; ?>
                <?php if ($dangAn): ?>
                <span class="cd-chi-so">💬 <?= (int) $b['SoBL'] ?></span>
                <?php else: ?>
                <a class="cd-chi-so" href="<?= e($link) ?>#binh-luan" title="Xem bình luận">💬 <?= (int) $b['SoBL'] ?></a>
                <?php endif; ?>
              </div>

              <?php if ($tab === 'cuatoi'): ?>
              <!-- Thao tác của chủ bài: sửa (trong 15 phút), ẩn/mở lại và xóa (mọi lúc) -->
              <div class="cd-hanh-dong">
                <?php if (!$dangAn): ?>
                  <?php if (CongDongHelper::conHanSua((int) $b['GiayTuDang'])): ?>
                    <a class="btn btn-phu" href="<?= e(BASE_URL) ?>/client/suabai.php?id=<?= (int) $b['MaBaiDang'] ?>">Sửa bài</a>
                  <?php endif; ?>
                  <?php if (CongDongHelper::coCotNguoiAn()): ?>
                    <form method="post" action="" data-xacnhan="Ẩn bài đăng này? Bạn có thể mở lại bất cứ lúc nào trong mục Bài của tôi.">
                      <?= csrf_field() ?>
                      <input type="hidden" name="hanh_dong" value="an_bai">
                      <input type="hidden" name="ma" value="<?= (int) $b['MaBaiDang'] ?>">
                      <input type="hidden" name="q" value="<?= e($tuKhoa) ?>">
                      <input type="hidden" name="mon" value="<?= (int) $monLoc ?>">
                      <input type="hidden" name="page" value="<?= (int) $p->trang() ?>">
                      <button type="submit" class="btn btn-phu">Ẩn bài</button>
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
                    <button type="submit" class="btn btn-phu">Mở lại bài</button>
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
                  <button type="submit" class="btn btn-phu">Xóa bài</button>
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
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>