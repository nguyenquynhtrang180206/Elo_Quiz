<?php
// Chi tiết bài đăng, bình luận, xóa bài của mình (C-43, C-44). Đặt tại: elo_quiz/client/baidang.php
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/CongDongHelper.php';

$maND = Auth::clientId();   // khách chưa đăng nhập vẫn xem được bài; bình luận/xóa/sửa cần đăng nhập
$pdo  = Database::pdo();
$id   = (int) ($_GET['id'] ?? 0);

// C-44: chỉ thấy bài đang ở trạng thái "Hiển thị"
$st = $pdo->prepare(
    "SELECT b.MaBaiDang, b.MaND, b.TieuDe, b.NoiDung, b.NgayDang, u.HoTen, m.TenMon
       FROM BAIDANG b
       JOIN NGUOIDUNG u ON u.MaND = b.MaND
       LEFT JOIN MONHOC m ON m.MaMon = b.MaMon
      WHERE b.MaBaiDang = ? AND b.TrangThai = 'Hiển thị'"
);
$st->execute([$id]);
$bai = $st->fetch();

$loi = [];
$noiDungBL = '';

if ($bai && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($maND === null) {
        redirect(BASE_URL . '/client/login.php');
    }
    csrf_check();
    $hanhDong = (string) ($_POST['hanh_dong'] ?? '');

    if ($hanhDong === 'binhluan') {
        $noiDungBL = trim((string) ($_POST['noi_dung'] ?? ''));
        if ($noiDungBL === '') {
            $loi[] = 'Vui lòng nhập nội dung bình luận.';
        } elseif (mb_strlen($noiDungBL) > CongDongHelper::BINH_LUAN_TOI_DA) {
            $loi[] = 'Bình luận tối đa ' . CongDongHelper::BINH_LUAN_TOI_DA . ' ký tự.';
        } else {
            $ins = $pdo->prepare('INSERT INTO BINHLUAN (MaBaiDang, MaND, NoiDung) VALUES (?, ?, ?)');
            $ins->execute([$id, $maND, $noiDungBL]);

            // Quay về trang cuối để thấy bình luận vừa viết
            $dem = $pdo->prepare("SELECT COUNT(*) FROM BINHLUAN WHERE MaBaiDang = ? AND TrangThai = 'Hiển thị'");
            $dem->execute([$id]);
            $trangCuoi = max(1, (int) ceil(((int) $dem->fetchColumn()) / 10));
            redirect(BASE_URL . '/client/baidang.php?id=' . $id . '&page=' . $trangCuoi . '#form-bl');
        }
    } elseif ($hanhDong === 'xoa' && (int) $bai['MaND'] === $maND) {
        // C-43: xóa bài nghĩa là chuyển sang "Đã xóa", không xóa cứng; chỉ chủ bài, chỉ bài đang hiển thị
        $up = $pdo->prepare("UPDATE BAIDANG SET TrangThai = 'Đã xóa' WHERE MaBaiDang = ? AND MaND = ? AND TrangThai = 'Hiển thị'");
        $up->execute([$id, $maND]);
        CongDongHelper::flash('Đã xóa bài đăng.');
        redirect(BASE_URL . '/client/congdong.php');
    }
}

$thanhCong = CongDongHelper::flash();
$binhLuan = [];
$p = null;

if ($bai) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM BINHLUAN WHERE MaBaiDang = ? AND TrangThai = 'Hiển thị'");
    $st->execute([$id]);
    $p = new Paginator((int) $st->fetchColumn(), 10);

    $st = $pdo->prepare(
        "SELECT c.NoiDung, c.NgayBinhLuan, u.HoTen
           FROM BINHLUAN c JOIN NGUOIDUNG u ON u.MaND = c.MaND
          WHERE c.MaBaiDang = ? AND c.TrangThai = 'Hiển thị'
          ORDER BY c.NgayBinhLuan, c.MaBinhLuan
          LIMIT " . $p->limit() . ' OFFSET ' . $p->offset()
    );
    $st->execute([$id]);
    $binhLuan = $st->fetchAll();
}

$tieuDe = $bai ? $bai['TieuDe'] : 'Bài đăng';
$cssTrang = 'baidang';
$jsTrang  = 'baidang';
require __DIR__ . '/layout/header.php';
?>
<div class="container">
  <p><a href="<?= e(BASE_URL) ?>/client/congdong.php">&laquo; Về bảng tin</a></p>

  <?php if (!$bai): ?>
    <div class="card">Bài đăng không tồn tại hoặc không còn hiển thị.</div>
  <?php else: ?>
    <?php if ($thanhCong): ?><div class="thanhcong"><?= e($thanhCong) ?></div><?php endif; ?>

    <div class="card">
      <h2 class="tieu-de-bai"><?= e($bai['TieuDe']) ?></h2>
      <div class="meta">
        <?= e($bai['HoTen']) ?> · <?= e(CongDongHelper::ngay($bai['NgayDang'])) ?>
        <?php if ($bai['TenMon']): ?> · <?= e($bai['TenMon']) ?><?php endif; ?>
      </div>
      <p><?= nl2br(e($bai['NoiDung'])) ?></p>

      <?php if ((int) $bai['MaND'] === $maND): ?>
        <form method="post" action="?id=<?= (int) $id ?>" class="form-dong"
              data-xac-nhan="Xóa bài đăng này?">
          <?= csrf_field() ?>
          <input type="hidden" name="hanh_dong" value="xoa">
          <a class="btn btn-phu" href="<?= e(BASE_URL) ?>/client/suabai.php?id=<?= (int) $id ?>">Sửa bài</a>
          <button type="submit" class="btn btn-phu">Xóa bài</button>
        </form>
      <?php endif; ?>
    </div>

    <div class="card">
      <h3 class="tieu-de-binhluan">Bình luận (<?= (int) $p->tong() ?>)</h3>

      <?php if (!$binhLuan): ?>
        <p class="meta">Chưa có bình luận nào.</p>
      <?php endif; ?>
      <?php foreach ($binhLuan as $c): ?>
        <div class="binhluan">
          <div class="meta"><b><?= e($c['HoTen']) ?></b> · <?= e(CongDongHelper::ngay($c['NgayBinhLuan'])) ?></div>
          <div><?= nl2br(e($c['NoiDung'])) ?></div>
        </div>
      <?php endforeach; ?>

      <?= $p->links() ?>

      <div id="form-bl">
        <?php if ($loi): ?>
          <div class="loi"><?php foreach ($loi as $m): ?><div><?= e($m) ?></div><?php endforeach; ?></div>
        <?php endif; ?>
        <?php if ($maND === null): ?>
          <p class="meta"><a href="<?= e(BASE_URL) ?>/client/login.php">Đăng nhập</a> để viết bình luận.</p>
        <?php else: ?>
        <form method="post" action="?id=<?= (int) $id ?>" novalidate>
          <?= csrf_field() ?>
          <input type="hidden" name="hanh_dong" value="binhluan">
          <label for="noi_dung">Viết bình luận</label>
          <textarea id="noi_dung" name="noi_dung" maxlength="1000" required><?= e($noiDungBL) ?></textarea>
          <p><button type="submit" class="btn">Gửi bình luận</button></p>
        </form>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>