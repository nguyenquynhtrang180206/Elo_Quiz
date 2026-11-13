<?php
// Kiểm duyệt cộng đồng (UC15: A-32, A-33, A-34). Đặt tại: elo_quiz/admin/congdong.php
// Hai tab: ?loai=bai (bài đăng), ?loai=bl (bình luận). Chỉ ẩn/hiện, không xóa cứng; mọi lần ẩn/hiện đều bắt buộc lý do và ghi nhật ký.
// A-33: bài sinh viên đã tự xóa ("Đã xóa") không được hiện lại. Admin chỉ chuyển qua lại Hiển thị <-> Bị ẩn.
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/CongDongHelper.php';
require_once __DIR__ . '/../core/ThongBaoHelper.php';
Auth::requireAdmin(BASE_URL . '/admin/dangnhap.php');   // A-04

$pdo  = Database::pdo();
$loai = (string) ($_GET['loai'] ?? 'bai');
if (!in_array($loai, ['bai', 'bl'], true)) {
    $loai = 'bai';
}
$dsTT = ['Hiển thị', 'Bị ẩn', 'Đã xóa'];
$tt   = (string) ($_GET['tt'] ?? '');
if (!in_array($tt, $dsTT, true)) {
    $tt = '';
}
$q = trim((string) ($_GET['q'] ?? ''));

// ---- Xử lý ẩn / hiện (logic dùng chung với trang Cộng đồng: CongDongHelper::kiemDuyet) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    // Gửi từ trang xem bài (congdong_baidang.php) thì sau khi xử lý quay lại đúng trang đó
    $veBai   = (int) ($_POST['ve_bai'] ?? 0);
    $quayLai = static fn (array $them): string => $veBai > 0
        ? 'congdong_baidang.php?' . http_build_query(['id' => $veBai] + $them)
        : 'congdong.php?' . http_build_query(array_merge($_GET, $them));
    $kq = CongDongHelper::kiemDuyet($pdo, (string) ($_POST['hanh_dong'] ?? ''), (int) ($_POST['ma'] ?? 0), (string) ($_POST['ly_do'] ?? ''));
    redirect($quayLai($kq === 'sai' ? [] : ['kq' => $kq]));
}

$thongBao = ['ok' => 'Đã cập nhật và ghi nhật ký.'][$_GET['kq'] ?? ''] ?? '';
$loiBao   = [
    'thieuly' => 'Hãy nhập lý do trước khi ẩn hoặc hiện lại.',
    'khong'   => 'Không thực hiện được: trạng thái đã thay đổi (hoặc bài đã bị sinh viên tự xóa). Hãy kiểm tra lại danh sách.',
][$_GET['kq'] ?? ''] ?? '';

// ---- Danh sách có phân trang (20 dòng/trang) ----
$dk   = [];
$tham = [];
if ($tt !== '') {
    $dk[]   = 'x.TrangThai = ?';
    $tham[] = $tt;
}
if ($q !== '') {
    $like = '%' . addcslashes($q, '\\%_') . '%';
    if ($loai === 'bai') {
        $dk[] = '(x.TieuDe LIKE ? OR x.NoiDung LIKE ? OR n.HoTen LIKE ?)';
        array_push($tham, $like, $like, $like);
    } else {
        $dk[] = '(x.NoiDung LIKE ? OR n.HoTen LIKE ? OR b.TieuDe LIKE ?)';
        array_push($tham, $like, $like, $like);
    }
}
$where = $dk ? 'WHERE ' . implode(' AND ', $dk) : '';

if ($loai === 'bai') {
    $from = 'FROM BAIDANG x JOIN NGUOIDUNG n ON n.MaND = x.MaND LEFT JOIN MONHOC m ON m.MaMon = x.MaMon';
    $cot  = 'x.MaBaiDang AS ma, x.TieuDe, x.NoiDung, x.NgayDang AS ngay, x.TrangThai, n.MaND, n.HoTen, m.TenMon,
             (SELECT COUNT(*) FROM BINHLUAN c WHERE c.MaBaiDang = x.MaBaiDang) AS so_bl';
    $order = 'ORDER BY x.NgayDang DESC, x.MaBaiDang DESC';
} else {
    $from = 'FROM BINHLUAN x JOIN NGUOIDUNG n ON n.MaND = x.MaND JOIN BAIDANG b ON b.MaBaiDang = x.MaBaiDang';
    $cot  = 'x.MaBinhLuan AS ma, x.NoiDung, x.NgayBinhLuan AS ngay, x.TrangThai, n.MaND, n.HoTen,
             b.MaBaiDang, b.TieuDe AS tieu_de_bai, b.TrangThai AS tt_bai';
    $order = 'ORDER BY x.NgayBinhLuan DESC, x.MaBinhLuan DESC';
}

$st = $pdo->prepare("SELECT COUNT(*) $from $where");
$st->execute($tham);
$p = new Paginator((int) $st->fetchColumn(), 20);

$st = $pdo->prepare("SELECT $cot $from $where $order LIMIT " . $p->limit() . ' OFFSET ' . $p->offset());
$st->execute($tham);
$ds = $st->fetchAll();

$lopTT = ['Hiển thị' => 'tt-hien', 'Bị ẩn' => 'tt-an', 'Đã xóa' => 'tt-xoa'];
$cat   = static fn (string $s, int $n = 160): string => mb_strlen($s) > $n ? mb_substr($s, 0, $n) . '…' : $s;

$tieuDe  = 'Kiểm duyệt cộng đồng';
$mucChon = 'congdong';
$cssTrang = 'congdong';
require __DIR__ . '/layout/header.php';
?>
<div class="congdong-page">
  <div class="congdong-main">
    <header class="congdong-header">
      <div class="congdong-icon" aria-hidden="true">✓</div>
      <div class="congdong-headline">
        <h2>Kiểm duyệt cộng đồng</h2>
        <p>Quản lý bài đăng và bình luận của cộng đồng sinh viên</p>
      </div>
    </header>

    <div class="congdong-tabs">
      <a class="btn<?= $loai === 'bai' ? '' : ' btn-phu' ?>" href="?loai=bai">Bài đăng</a>
      <a class="btn<?= $loai === 'bl' ? '' : ' btn-phu' ?>" href="?loai=bl">Bình luận</a>
    </div>

    <?php if ($thongBao !== ''): ?><div class="thanhcong"><?= e($thongBao) ?></div><?php endif; ?>
    <?php if ($loiBao !== ''): ?><div class="loi"><?= e($loiBao) ?></div><?php endif; ?>

    <form method="get" action="" class="form-loc congdong-form-loc">
      <input type="hidden" name="loai" value="<?= e($loai) ?>">
      <input type="text" name="q" value="<?= e($q) ?>" placeholder="<?= $loai === 'bai' ? 'Tìm kiếm theo tiêu đề, nội dung, người đăng...' : 'Tìm kiếm theo nội dung, người bình luận, tiêu đề bài...' ?>" class="o-tim">
      <select name="tt">
        <option value="">Tất cả trạng thái</option>
        <?php foreach ($dsTT as $v): ?>
          <option value="<?= e($v) ?>"<?= $tt === $v ? ' selected' : '' ?>><?= e($v) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn">Tìm kiếm</button>
      <a class="btn btn-phu" href="?loai=<?= e($loai) ?>">Xóa lọc</a>
    </form>

    <div class="congdong-card card">
      <?php if (!$ds): ?>
        <p class="nhan-phu">Không có mục nào khớp.</p>
      <?php else: ?>
        <table class="congdong-table">
          <thead>
            <tr>
              <th class="stt-col">#</th>
              <th><?= $loai === 'bai' ? 'Tiêu đề bài đăng' : 'Nội dung bình luận' ?></th>
              <th>Người đăng</th>
              <th>Danh mục</th>
              <th>Thời gian</th>
              <th>Trạng thái</th>
              <th>Thao tác</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($ds as $idx => $r):
                $hienNut = $r['TrangThai'] === 'Hiển thị' || $r['TrangThai'] === 'Bị ẩn';
                $anBai   = $r['TrangThai'] === 'Hiển thị';
                $danhMuc = $loai === 'bai' ? ($r['TenMon'] ?: 'Chưa phân loại') : 'Chia sẻ';
                $ngay = date('d/m/Y', strtotime($r['ngay'])) . '<br>' . date('H:i', strtotime($r['ngay']));
                ?>
              <tr>
                <td class="stt-col"><?= (int) ($idx + 1) ?></td>
                <td class="cot-noidung">
                  <?php if ($loai === 'bai'): ?>
                    <strong><a href="<?= e(BASE_URL) ?>/client/baidang.php?id=<?= (int) $r['ma'] ?>&amp;xemthu=1" target="_blank" rel="noopener"><?= e($r['TieuDe']) ?></a></strong>
                    <div class="desc"><?= e($cat($r['NoiDung'])) ?></div>
                  <?php else: ?>
                    <strong><a href="<?= e(BASE_URL) ?>/admin/congdong_baidang.php?id=<?= (int) $r['MaBaiDang'] ?>"><?= e($cat($r['NoiDung'])) ?></a></strong>
                    <div class="desc">Dưới bài <span>#<?= (int) $r['MaBaiDang'] ?></span></div>
                  <?php endif; ?>
                </td>
                <td class="nguoi-dang">
                  <span class="avatar-dot"><?= e(mb_substr(trim((string) $r['HoTen']), 0, 1) ?: 'A') ?></span>
                  <div>
                    <strong><?= e($r['HoTen']) ?></strong>
                    <small>K<?= (int) $r['MaND'] ?></small>
                  </div>
                </td>
                <td><span class="tag tag-<?= strtolower(str_replace(' ', '-', $danhMuc)) ?>"><?= e($danhMuc) ?></span></td>
                <td class="ngay-col"><?= $ngay ?></td>
                <td><span class="status <?= e($lopTT[$r['TrangThai']] ?? '') ?>"><?= e($r['TrangThai']) ?></span></td>
                <td class="cot-thaotac">
                  <?php if ($hienNut): ?>
                    <form method="post" action="" class="form-kiemduyet">
                      <?= csrf_field() ?>
                      <input type="hidden" name="hanh_dong" value="<?= ($anBai ? 'an_' : 'hien_') . $loai ?>">
                      <input type="hidden" name="ma" value="<?= (int) $r['ma'] ?>">
                      <input type="text" name="ly_do" maxlength="300" required placeholder="Lý do" class="o-lydo">
                      <button type="submit" class="btn<?= $anBai ? ' btn-do' : '' ?>"><?= $anBai ? 'Ẩn' : 'Hiện lại' ?></button>
                    </form>
                  <?php else: ?>
                    <span class="nhan-phu">Đã xóa</span>
                  <?php endif; ?>
                  <?php if ($loai === 'bai'): ?>
                    <div class="lien-ket-duyet"><a href="<?= e(BASE_URL) ?>/admin/congdong_baidang.php?id=<?= (int) $r['ma'] ?>">Chi tiết</a></div>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
      <div class="congdong-pager"><?= $p->links() ?></div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>