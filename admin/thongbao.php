<?php
// Trang xem toàn bộ thông báo của admin (có phân trang, tab Tất cả / Chưa đọc). Đặt tại: admin/thongbao.php
require_once __DIR__ . '/../core/bootstrap.php';
$adminId = Auth::requireAdmin(BASE_URL . '/admin/dangnhap.php');   // A-04

$pdo = Database::pdo();
ThongBaoAdmin::damBaoBang();

$tab = (string) ($_GET['tab'] ?? 'tatca');
if (!in_array($tab, ['tatca', 'chuadoc'], true)) {
    $tab = 'tatca';
}

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if ((string) ($_POST['hanh_dong'] ?? '') === 'doc_het') {
        ThongBaoAdmin::danhDauTatCaDaDoc($pdo, $adminId);
        $msg = 'Đã đánh dấu tất cả thông báo là đã đọc.';
    }
}

$soChuaDoc = ThongBaoAdmin::laySoChuaDoc($pdo, $adminId);
$tongTatCa = ThongBaoAdmin::demTatCa($pdo);
$tongHienTai = $tab === 'chuadoc' ? $soChuaDoc : $tongTatCa;

$p  = new Paginator($tongHienTai, 20);
$ds = ThongBaoAdmin::layDanhSach($pdo, $adminId, $p->limit(), $p->offset(), $tab === 'chuadoc');

$tieuDe   = 'Thông báo';
$mucChon  = 'dashboard';
$cssTrang = 'thongbao';
require __DIR__ . '/layout/header.php';
?>
<div class="tbp" data-api="<?= e(BASE_URL) ?>/admin/thongbao_api.php" data-csrf="<?= e(csrf_token()) ?>">
  <div class="tbp-dau">
    <div>
      <h2>🔔 Thông báo</h2>
      <p>Sinh viên đề xuất môn học mới, đang chờ bạn duyệt.</p>
    </div>
    <div class="tbp-nut">
      <a class="tbp-ve" href="<?= e(BASE_URL) ?>/admin/index.php">← Về Tổng quan</a>
      <?php if ($soChuaDoc > 0): ?>
        <form method="post" action="?tab=<?= e($tab) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="hanh_dong" value="doc_het">
          <button type="submit" class="tbp-doc-het">✓ Đã đọc tất cả</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($msg): ?><div class="thanhcong"><?= e($msg) ?></div><?php endif; ?>

  <div class="tbp-tab">
    <a href="?tab=tatca" class="<?= $tab === 'tatca' ? 'chon' : '' ?>">Tất cả <span><?= $tongTatCa ?></span></a>
    <a href="?tab=chuadoc" class="<?= $tab === 'chuadoc' ? 'chon' : '' ?>">Chưa đọc <span class="<?= $soChuaDoc > 0 ? 'do' : '' ?>"><?= $soChuaDoc ?></span></a>
  </div>

  <?php if (!$ds): ?>
    <div class="tbp-trong">
      <div>🔕</div>
      <p><?= $tab === 'chuadoc' ? 'Bạn đã đọc hết thông báo rồi!' : 'Chưa có thông báo nào.' ?></p>
    </div>
  <?php else: ?>
    <div class="tbp-ds">
      <?php foreach ($ds as $t):
          $chuaDoc = (int) $t['da_doc'] === 0;
          $tu = preg_split('/\s+/', trim((string) $t['ten']));
          $chuCai = mb_strtoupper(mb_substr((string) end($tu), 0, 1)) ?: 'U';
          $nd = preg_replace('/^(.{120}).+$/su', '$1…', trim((string) $t['noidung'])); ?>
        <a class="tbp-muc <?= $chuaDoc ? 'chua-doc' : 'da-doc' ?>" href="<?= e(ThongBaoAdmin::lienKet((int) $t['ma_bai'])) ?>"
           data-loai="<?= (int) $t['loai'] ?>" data-ma="<?= (int) $t['ma'] ?>">
          <span class="tbp-ava"><?= e($chuCai) ?></span>
          <span class="tbp-nd">
            <span class="tbp-ten"><?= e($t['ten']) ?></span> đã đề xuất môn học:
            <span class="tbp-trich"><?= e($nd) ?></span>
          </span>
          <span class="tbp-tg"><?= e(ThongBaoHelper::tinhThoiGian((string) $t['tg'])) ?></span>
          <?php if ($chuaDoc): ?><span class="tbp-cham" title="Chưa đọc"></span><?php else: ?><span class="tbp-nhan">✓ Đã đọc</span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>
    <?= $p->links() ?>
  <?php endif; ?>
</div>

<script>
// Bấm thông báo chưa đọc: ghi đã đọc (keepalive) rồi link chuyển trang như bình thường
(function () {
  var goc = document.querySelector('.tbp');
  if (!goc) { return; }
  goc.addEventListener('click', function (e) {
    var muc = e.target.closest('.tbp-muc.chua-doc');
    if (!muc) { return; }
    var f = new FormData();
    f.append('csrf', goc.getAttribute('data-csrf'));
    f.append('action', 'doc_mot');
    f.append('loai', muc.getAttribute('data-loai'));
    f.append('ma', muc.getAttribute('data-ma'));
    try { fetch(goc.getAttribute('data-api'), { method: 'POST', body: f, keepalive: true }); } catch (err) {}
  });
})();
</script>
<?php require __DIR__ . '/layout/footer.php'; ?>