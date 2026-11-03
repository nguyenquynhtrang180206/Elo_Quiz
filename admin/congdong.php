<?php
// Kiểm duyệt cộng đồng (UC15: A-32, A-33, A-34). Đặt tại: elo_quiz/admin/congdong.php
// Hai tab: ?loai=bai (bài đăng), ?loai=bl (bình luận). Chỉ ẩn/hiện, không xóa cứng; mọi lần ẩn/hiện đều bắt buộc lý do và ghi nhật ký.
// A-33: bài sinh viên đã tự xóa ("Đã xóa") không được hiện lại. Admin chỉ chuyển qua lại Hiển thị <-> Bị ẩn.
require_once __DIR__ . '/../core/bootstrap.php';
Auth::requireAdmin(BASE_URL . '/admin/login.php');   // A-04

$pdo  = Database::pdo();
$loai = (string) ($_GET['loai'] ?? 'bai');
if (!in_array($loai, ['bai', 'bl'], true)) {
    $loai = 'bai';
}
$dsTT = $loai === 'bai' ? ['Hiển thị', 'Bị ẩn', 'Đã xóa'] : ['Hiển thị', 'Bị ẩn'];
$tt   = (string) ($_GET['tt'] ?? '');
if (!in_array($tt, $dsTT, true)) {
    $tt = '';
}
$q = trim((string) ($_GET['q'] ?? ''));

// ---- Xử lý ẩn / hiện ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $hd  = (string) ($_POST['hanh_dong'] ?? '');
    $ma  = (int) ($_POST['ma'] ?? 0);
    $ly  = preg_replace('/^(.{300}).+$/su', '$1', trim((string) ($_POST['ly_do'] ?? '')));
    $cauHinh = [
        // hành động => [bảng, cột khóa, trạng thái mới, trạng thái cũ bắt buộc, tên nhật ký, tiền tố đối tượng]
        'an_bai'  => ['BAIDANG', 'MaBaiDang', 'Bị ẩn',    'Hiển thị', 'Ẩn bài đăng',   'BAIDANG'],
        'hien_bai' => ['BAIDANG', 'MaBaiDang', 'Hiển thị', 'Bị ẩn',    'Hiện bài đăng',  'BAIDANG'],
        'an_bl'   => ['BINHLUAN', 'MaBinhLuan', 'Bị ẩn',    'Hiển thị', 'Ẩn bình luận',  'BINHLUAN'],
        'hien_bl' => ['BINHLUAN', 'MaBinhLuan', 'Hiển thị', 'Bị ẩn',    'Hiện bình luận', 'BINHLUAN'],
    ];
    // Gửi từ trang xem bài (congdong_baidang.php) thì sau khi xử lý quay lại đúng trang đó
    $veBai   = (int) ($_POST['ve_bai'] ?? 0);
    $quayLai = static fn (array $them): string => $veBai > 0
        ? 'congdong_baidang.php?' . http_build_query(['id' => $veBai] + $them)
        : 'congdong.php?' . http_build_query(array_merge($_GET, $them));
    if (!isset($cauHinh[$hd]) || $ma <= 0) {
        redirect($quayLai([]));
    }
    if ($ly === '') {
        redirect($quayLai(['kq' => 'thieuly']));
    }
    [$bang, $cotKhoa, $moi, $cu, $tenNk, $doiTuong] = $cauHinh[$hd];

    $pdo->beginTransaction();
    try {
        // Điều kiện theo trạng thái cũ: bài "Đã xóa" không khớp nên không hiện lại được (A-33);
        // bấm lặp hoặc hai quản trị viên cùng bấm thì chỉ ghi nhật ký một lần.
        $up = $pdo->prepare("UPDATE $bang SET TrangThai = ? WHERE $cotKhoa = ? AND TrangThai = ?");
        $up->execute([$moi, $ma, $cu]);
        $doi = $up->rowCount() === 1;
        if ($doi) {
            NhatKy::ghi($tenNk, $doiTuong . ' #' . $ma, 'Lý do: ' . $ly);
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $ex;
    }
    redirect($quayLai(['kq' => $doi ? 'ok' : 'khong']));
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
<h2>Kiểm duyệt cộng đồng</h2>
<p>
  <a class="btn<?= $loai === 'bai' ? '' : ' btn-phu' ?>" href="?loai=bai">Bài đăng</a>
  <a class="btn<?= $loai === 'bl' ? '' : ' btn-phu' ?>" href="?loai=bl">Bình luận</a>
</p>

<?php if ($thongBao !== ''): ?><div class="thanhcong"><?= e($thongBao) ?></div><?php endif; ?>
<?php if ($loiBao !== ''): ?><div class="loi"><?= e($loiBao) ?></div><?php endif; ?>

<form method="get" action="" class="card form-loc">
  <input type="hidden" name="loai" value="<?= e($loai) ?>">
  <input type="text" name="q" value="<?= e($q) ?>" placeholder="<?= $loai === 'bai' ? 'Tìm theo tiêu đề, nội dung, người đăng' : 'Tìm theo nội dung, người bình luận, tiêu đề bài' ?>" class="o-tim">
  <select name="tt">
    <option value="">Mọi trạng thái</option>
    <?php foreach ($dsTT as $v): ?>
      <option value="<?= e($v) ?>"<?= $tt === $v ? ' selected' : '' ?>><?= e($v) ?></option>
    <?php endforeach; ?>
  </select>
  <button type="submit" class="btn">Lọc</button>
  <a class="btn btn-phu" href="?loai=<?= e($loai) ?>">Xóa lọc</a>
</form>

<p class="nhan-phu">Chỉ ẩn/hiện lại, không xóa cứng. Bắt buộc nhập lý do, lý do được ghi vào nhật ký quản trị.<?= $loai === 'bai' ? ' Bài sinh viên đã tự xóa không hiện lại được.' : '' ?></p>

<div class="card">
  <?php if (!$ds): ?>
    <p class="nhan-phu">Không có mục nào khớp.</p>
  <?php else: ?>
    <table>
      <tr>
        <th>Mã</th>
        <th><?= $loai === 'bai' ? 'Bài đăng' : 'Bình luận' ?></th>
        <th>Người đăng</th><th>Ngày</th><th>Trạng thái</th><th>Xử lý</th>
      </tr>
      <?php foreach ($ds as $r):
          $hienNut = $r['TrangThai'] === 'Hiển thị' || $r['TrangThai'] === 'Bị ẩn';   // "Đã xóa" không có nút (A-33)
          $anBai   = $r['TrangThai'] === 'Hiển thị'; ?>
        <tr>
          <td><?= (int) $r['ma'] ?></td>
          <td class="cot-noidung">
            <?php if ($loai === 'bai'): ?>
              <strong><a href="<?= e(BASE_URL) ?>/client/baidang.php?id=<?= (int) $r['ma'] ?>&amp;xemthu=1" target="_blank" rel="noopener"><?= e($r['TieuDe']) ?></a></strong>
              <?php if ($r['TenMon']): ?><span class="nhan-phu"> · <?= e($r['TenMon']) ?></span><?php endif; ?>
              <details><summary class="nhan-phu"><?= e($cat($r['NoiDung'])) ?> (<?= (int) $r['so_bl'] ?> bình luận)</summary>
                <div class="xuong-dong"><?= e($r['NoiDung']) ?></div>
              </details>
            <?php else: ?>
              <details><summary><?= e($cat($r['NoiDung'])) ?></summary>
                <div class="xuong-dong"><?= e($r['NoiDung']) ?></div>
              </details>
              <span class="nhan-phu">Dưới bài <a href="<?= e(BASE_URL) ?>/admin/congdong_baidang.php?id=<?= (int) $r['MaBaiDang'] ?>">#<?= (int) $r['MaBaiDang'] ?>: <?= e($cat($r['tieu_de_bai'], 60)) ?></a> (<?= e($r['tt_bai']) ?>)</span>
            <?php endif; ?>
          </td>
          <td><a href="<?= e(BASE_URL) ?>/admin/nguoidung_chitiet.php?id=<?= (int) $r['MaND'] ?>"><?= e($r['HoTen']) ?></a></td>
          <td><?= e(date('d/m/Y H:i', strtotime($r['ngay']))) ?></td>
          <td><span class="dam <?= e($lopTT[$r['TrangThai']] ?? '') ?>"><?= e($r['TrangThai']) ?></span></td>
          <td class="cot-thaotac">
            <?php if ($hienNut): ?>
              <form method="post" action="" class="form-kiemduyet">
                <?= csrf_field() ?>
                <input type="hidden" name="hanh_dong" value="<?= ($anBai ? 'an_' : 'hien_') . $loai ?>">
                <input type="hidden" name="ma" value="<?= (int) $r['ma'] ?>">
                <input type="text" name="ly_do" maxlength="300" required placeholder="Lý do (bắt buộc)" class="o-lydo">
                <button type="submit" class="btn<?= $anBai ? ' btn-do' : '' ?>"><?= $anBai ? 'Ẩn' : 'Hiện lại' ?></button>
              </form>
            <?php else: ?>
              <span class="nhan-phu">Sinh viên đã xóa</span>
            <?php endif; ?>
            <?php if ($loai === 'bai'): ?>
              <div class="lien-ket-duyet"><a href="<?= e(BASE_URL) ?>/admin/congdong_baidang.php?id=<?= (int) $r['ma'] ?>">Kiểm duyệt bài và bình luận</a></div>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
  <?= $p->links() ?>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>