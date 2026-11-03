<?php
// Bảng tin: xem, lọc theo môn, đăng bài (C-42, C-43). Đặt tại: elo_quiz/client/congdong.php
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/CongDongHelper.php';

$maND = Auth::clientId();   // khách chưa đăng nhập vẫn xem được; chỉ đăng bài mới cần đăng nhập
$pdo  = Database::pdo();
$monDangMo = CongDongHelper::monDangMo();

$loi = [];
$tieuDeBai = $noiDungBai = $monChon = '';

// ---- Đăng bài mới ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($maND === null) {
        redirect(BASE_URL . '/client/login.php');
    }
    csrf_check();
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

// ---- Danh sách bài đang hiển thị, lọc theo môn, phân trang 10 dòng (3.8) ----
$monLoc = (int) ($_GET['mon'] ?? 0);
$where  = "b.TrangThai = 'Hiển thị'";
$tham   = [];
if ($monLoc > 0) {
    $where .= ' AND b.MaMon = ?';
    $tham[] = $monLoc;
}

$st = $pdo->prepare("SELECT COUNT(*) FROM BAIDANG b WHERE $where");
$st->execute($tham);
$p = new Paginator((int) $st->fetchColumn(), 10);

$st = $pdo->prepare(
    "SELECT b.MaBaiDang, b.TieuDe, b.NoiDung, b.NgayDang, u.HoTen, m.TenMon,
            (SELECT COUNT(*) FROM BINHLUAN c
              WHERE c.MaBaiDang = b.MaBaiDang AND c.TrangThai = 'Hiển thị') AS SoBL
       FROM BAIDANG b
       JOIN NGUOIDUNG u ON u.MaND = b.MaND
       LEFT JOIN MONHOC m ON m.MaMon = b.MaMon
      WHERE $where
      ORDER BY b.NgayDang DESC, b.MaBaiDang DESC
      LIMIT " . $p->limit() . ' OFFSET ' . $p->offset()
);
$st->execute($tham);
$baiDang = $st->fetchAll();

$tieuDe = 'Cộng đồng';
$cssTrang = 'congdong';
$jsTrang  = 'congdong';
require __DIR__ . '/layout/header.php';
?>
<div class="container">
  <h2>Cộng đồng học tập</h2>

  <?php if ($thanhCong): ?><div class="thanhcong"><?= e($thanhCong) ?></div><?php endif; ?>
  <?php if ($loi): ?>
    <div class="loi"><?php foreach ($loi as $m): ?><div><?= e($m) ?></div><?php endforeach; ?></div>
  <?php endif; ?>

  <?php if ($maND === null): ?>
    <div class="card">
      Bạn đang xem với tư cách khách. <a href="<?= e(BASE_URL) ?>/client/login.php">Đăng nhập</a> để đăng bài và bình luận.
    </div>
  <?php else: ?>
  <div class="card">
    <h3>Đăng bài mới</h3>
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
  </div>
  <?php endif; ?>

  <form method="get" action="" class="loc">
    <label for="loc_mon">Lọc theo môn:</label>
    <select id="loc_mon" name="mon">
      <option value="0">Tất cả môn</option>
      <?php foreach ($monDangMo as $id => $ten): ?>
        <option value="<?= (int) $id ?>" <?= $id === $monLoc ? 'selected' : '' ?>><?= e($ten) ?></option>
      <?php endforeach; ?>
    </select>
    <noscript><button class="btn btn-phu" type="submit">Lọc</button></noscript>
  </form>

  <?php if (!$baiDang): ?>
    <div class="card">Chưa có bài đăng nào.</div>
  <?php endif; ?>

  <?php foreach ($baiDang as $b): ?>
    <div class="card bai">
      <h3><a href="<?= e(BASE_URL) ?>/client/baidang.php?id=<?= (int) $b['MaBaiDang'] ?>"><?= e($b['TieuDe']) ?></a></h3>
      <div class="meta">
        <?= e($b['HoTen']) ?> · <?= e(CongDongHelper::ngay($b['NgayDang'])) ?>
        <?php if ($b['TenMon']): ?> · <?= e($b['TenMon']) ?><?php endif; ?>
      </div>
      <p><?= nl2br(e(mb_strimwidth($b['NoiDung'], 0, 220, '…'))) ?></p>
      <a href="<?= e(BASE_URL) ?>/client/baidang.php?id=<?= (int) $b['MaBaiDang'] ?>">
        <?= (int) $b['SoBL'] ?> bình luận · Xem chi tiết
      </a>
    </div>
  <?php endforeach; ?>

  <?= $p->links() ?>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>