<?php
// Xem một bài đăng và toàn bộ bình luận (kể cả đã ẩn) để kiểm duyệt (UC15: A-32, A-33, A-34).
// Đặt tại: elo_quiz/admin/congdong_baidang.php   (mở bằng ?id=<MaBaiDang>)
// Không dùng client/baidang.php vì trang đó cần đăng nhập sinh viên và chỉ hiện bài "Hiển thị".
// Việc ẩn/hiện được gửi sang congdong.php (cùng quy tắc: bắt buộc lý do, ghi nhật ký), rồi quay lại trang này.
require_once __DIR__ . '/../core/bootstrap.php';
Auth::requireAdmin(BASE_URL . '/admin/login.php');   // A-04

$pdo = Database::pdo();
$id  = (int) ($_GET['id'] ?? 0);

$st = $pdo->prepare(
    'SELECT b.MaBaiDang, b.TieuDe, b.NoiDung, b.NgayDang, b.TrangThai, n.MaND, n.HoTen, m.TenMon
       FROM BAIDANG b
       JOIN NGUOIDUNG n ON n.MaND = b.MaND
       LEFT JOIN MONHOC m ON m.MaMon = b.MaMon
      WHERE b.MaBaiDang = ?'
);
$st->execute([$id]);
$bai = $st->fetch();

$tieuDe  = 'Xem bài đăng';
$mucChon = 'congdong';

if (!$bai) {
    http_response_code(404);
    $cssTrang = 'congdong_baidang';
    require __DIR__ . '/layout/header.php';
    echo '<h2>Xem bài đăng</h2><div class="card">Không tìm thấy bài này. '
        . '<a href="' . e(BASE_URL) . '/admin/congdong.php">Về danh sách</a></div>';
    require __DIR__ . '/layout/footer.php';
    exit;
}

$thongBao = ['ok' => 'Đã cập nhật và ghi nhật ký.'][$_GET['kq'] ?? ''] ?? '';
$loiBao   = [
    'thieuly' => 'Hãy nhập lý do trước khi ẩn hoặc hiện lại.',
    'khong'   => 'Không thực hiện được: trạng thái đã thay đổi (hoặc bài đã bị sinh viên tự xóa). Hãy tải lại trang.',
][$_GET['kq'] ?? ''] ?? '';

// Bình luận của bài, mọi trạng thái, phân trang 20 dòng
$st = $pdo->prepare('SELECT COUNT(*) FROM BINHLUAN WHERE MaBaiDang = ?');
$st->execute([$id]);
$p = new Paginator((int) $st->fetchColumn(), 20);

$st = $pdo->prepare(
    'SELECT c.MaBinhLuan, c.NoiDung, c.NgayBinhLuan, c.TrangThai, n.MaND, n.HoTen
       FROM BINHLUAN c JOIN NGUOIDUNG n ON n.MaND = c.MaND
      WHERE c.MaBaiDang = ?
      ORDER BY c.NgayBinhLuan, c.MaBinhLuan
      LIMIT ' . $p->limit() . ' OFFSET ' . $p->offset()
);
$st->execute([$id]);
$binhLuan = $st->fetchAll();

$lopTT = ['Hiển thị' => 'tt-hien', 'Bị ẩn' => 'tt-an', 'Đã xóa' => 'tt-xoa'];

// Form ẩn/hiện dùng chung cho bài và bình luận; gửi sang congdong.php, xong quay lại trang này
function form_kiem_duyet(string $hanhDong, int $ma, int $veBai, string $nhan, bool $nutDo): void
{
    ?>
    <form method="post" action="<?= e(BASE_URL) ?>/admin/congdong.php" class="form-kiemduyet">
      <?= csrf_field() ?>
      <input type="hidden" name="hanh_dong" value="<?= e($hanhDong) ?>">
      <input type="hidden" name="ma" value="<?= $ma ?>">
      <input type="hidden" name="ve_bai" value="<?= $veBai ?>">
      <input type="text" name="ly_do" maxlength="300" required placeholder="Lý do (bắt buộc)" class="o-lydo">
      <button type="submit" class="btn<?= $nutDo ? ' btn-do' : '' ?>"><?= e($nhan) ?></button>
    </form>
    <?php
}

$cssTrang = 'congdong_baidang';
require __DIR__ . '/layout/header.php';
?>
<p><a href="<?= e(BASE_URL) ?>/admin/congdong.php?loai=bai">&laquo; Về danh sách bài đăng</a></p>
<h2><?= e($bai['TieuDe']) ?></h2>
<p><a href="<?= e(BASE_URL) ?>/client/baidang.php?id=<?= (int) $id ?>&amp;xemthu=1" target="_blank" rel="noopener">Xem như sinh viên &#8599;</a></p>

<?php if ($thongBao !== ''): ?><div class="thanhcong"><?= e($thongBao) ?></div><?php endif; ?>
<?php if ($loiBao !== ''): ?><div class="loi"><?= e($loiBao) ?></div><?php endif; ?>

<div class="card">
  <p class="nhan-phu">
    Bài #<?= (int) $bai['MaBaiDang'] ?> · đăng bởi
    <a href="<?= e(BASE_URL) ?>/admin/nguoidung_chitiet.php?id=<?= (int) $bai['MaND'] ?>"><?= e($bai['HoTen']) ?></a>
    · <?= e(date('d/m/Y H:i', strtotime($bai['NgayDang']))) ?>
    <?php if ($bai['TenMon']): ?>· môn <?= e($bai['TenMon']) ?><?php endif; ?>
    · <strong class="<?= e($lopTT[$bai['TrangThai']] ?? '') ?>"><?= e($bai['TrangThai']) ?></strong>
  </p>
  <div class="xuong-dong"><?= e($bai['NoiDung']) ?></div>

  <div class="khoi-thaotac">
    <?php if ($bai['TrangThai'] === 'Hiển thị'): ?>
      <?php form_kiem_duyet('an_bai', (int) $bai['MaBaiDang'], $id, 'Ẩn bài', true); ?>
    <?php elseif ($bai['TrangThai'] === 'Bị ẩn'): ?>
      <?php form_kiem_duyet('hien_bai', (int) $bai['MaBaiDang'], $id, 'Hiện lại bài', false); ?>
    <?php else: ?>
      <span class="nhan-phu">Sinh viên đã tự xóa bài này nên không hiện lại được.</span>
    <?php endif; ?>
  </div>
</div>

<h3>Bình luận (<?= $p->tong() ?>)</h3>
<div class="card">
  <?php if (!$binhLuan): ?>
    <p class="nhan-phu">Bài này chưa có bình luận.</p>
  <?php else: ?>
    <table>
      <tr><th>Người bình luận</th><th>Nội dung</th><th>Ngày</th><th>Trạng thái</th><th>Xử lý</th></tr>
      <?php foreach ($binhLuan as $c): $dangHien = $c['TrangThai'] === 'Hiển thị'; $daXoa = $c['TrangThai'] === 'Đã xóa'; ?>
        <tr>
          <td><a href="<?= e(BASE_URL) ?>/admin/nguoidung_chitiet.php?id=<?= (int) $c['MaND'] ?>"><?= e($c['HoTen']) ?></a></td>
          <td class="cot-noidung xuong-dong"><?= e($c['NoiDung']) ?></td>
          <td><?= e(date('d/m/Y H:i', strtotime($c['NgayBinhLuan']))) ?></td>
          <td><span class="dam <?= e($lopTT[$c['TrangThai']] ?? '') ?>"><?= e($c['TrangThai']) ?></span></td>
          <td class="cot-thaotac">
            <?php if ($daXoa): ?>
              <span class="nhan-phu">Sinh viên đã xóa</span>
            <?php else: ?>
              <?php form_kiem_duyet($dangHien ? 'an_bl' : 'hien_bl', (int) $c['MaBinhLuan'], $id, $dangHien ? 'Ẩn' : 'Hiện lại', $dangHien); ?>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
  <?= $p->links() ?>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>