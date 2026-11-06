<?php
// Trang Trung tâm Thông báo (client/thongbao.php)
require_once __DIR__ . '/../core/bootstrap.php';

$maND = Auth::clientId();
if ($maND === null) {
    redirect(BASE_URL . '/client/login.php');
}

$pdo = Database::pdo();
ThongBaoHelper::damBaoBang();

$tab = (string) ($_GET['tab'] ?? 'tatca');
if (!in_array($tab, ['tatca', 'chuadoc'], true)) {
    $tab = 'tatca';
}

$thongBaoMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $hanhDong = (string) ($_POST['hanh_dong'] ?? '');
    if ($hanhDong === 'doc_het') {
        ThongBaoHelper::danhDauTatCaDaDoc($pdo, $maND);
        $thongBaoMsg = 'Đã đánh dấu tất cả thông báo là đã đọc.';
    } elseif ($hanhDong === 'xoa_mot') {
        $maTB = (int) ($_POST['ma_tb'] ?? 0);
        if ($maTB > 0) {
            ThongBaoHelper::xoaThongBao($pdo, $maTB, $maND);
            $thongBaoMsg = 'Đã xóa thông báo.';
        }
    } elseif ($hanhDong === 'xoa_het') {
        ThongBaoHelper::xoaTatCa($pdo, $maND);
        $thongBaoMsg = 'Đã xóa tất cả thông báo.';
    }
}

// Đếm tổng số để phân trang
$dkWhere = "WHERE MaND = " . (int) $maND;
if ($tab === 'chuadoc') {
    $dkWhere .= " AND DaDoc = 0";
}
$tongTB = (int) $pdo->query("SELECT COUNT(*) FROM THONGBAO $dkWhere")->fetchColumn();
$soChuaDoc = ThongBaoHelper::laySoChuaDoc($pdo, $maND);

$p = new Paginator($tongTB, 15);
$danhSachTB = ThongBaoHelper::layDanhSach($pdo, $maND, $p->limit(), $p->offset(), $tab === 'chuadoc');

$tieuDe = 'Trung tâm Thông báo';
$cssTrang = 'thongbao';
require __DIR__ . '/layout/header.php';
?>

<div class="tb-page-container">
  <!-- Header trang thông báo -->
  <div class="tb-header-card">
    <div class="tb-header-left">
      <div class="tb-icon-badge" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="28" height="28" fill="none">
          <path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9" stroke="#00D9FF" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
          <path d="M13.73 21a2 2 0 01-3.46 0" stroke="#00D9FF" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </div>
      <div>
        <h1 class="tb-main-title">TRUNG TÂM THÔNG BÁO</h1>
        <p class="tb-sub-title">Cập nhật tương tác bài viết, phản hồi và tin tức từ cộng đồng QuizUp Arena</p>
      </div>
    </div>

    <!-- Nhóm nút hành động nhanh -->
    <div class="tb-header-actions">
      <?php if ($soChuaDoc > 0): ?>
        <form method="post" action="?tab=<?= e($tab) ?>" class="form-inline">
          <?= csrf_field() ?>
          <input type="hidden" name="hanh_dong" value="doc_het">
          <button type="submit" class="btn-tb-action btn-read-all" title="Đánh dấu tất cả thông báo là đã đọc">
            <span>✓ Đã đọc tất cả</span>
          </button>
        </form>
      <?php endif; ?>

      <?php if ($tongTB > 0): ?>
        <form method="post" action="?tab=<?= e($tab) ?>" class="form-inline" onsubmit="return confirm('Bạn có chắc muốn xóa tất cả thông báo?');">
          <?= csrf_field() ?>
          <input type="hidden" name="hanh_dong" value="xoa_het">
          <button type="submit" class="btn-tb-action btn-delete-all" title="Xóa toàn bộ thông báo">
            <span>🗑 Xóa tất cả</span>
          </button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($thongBaoMsg): ?>
    <div class="thanhcong" style="margin-bottom: 18px;"><?= e($thongBaoMsg) ?></div>
  <?php endif; ?>

  <!-- Bộ lọc Tabs: Tất cả & Chưa đọc -->
  <div class="tb-nav-tabs">
    <a href="?tab=tatca" class="tb-tab-btn <?= $tab === 'tatca' ? 'active' : '' ?>">
      <span>Tất cả thông báo</span>
      <span class="tb-tab-count"><?= $tongTB ?></span>
    </a>
    <a href="?tab=chuadoc" class="tb-tab-btn <?= $tab === 'chuadoc' ? 'active' : '' ?>">
      <span>Chưa đọc</span>
      <?php if ($soChuaDoc > 0): ?>
        <span class="tb-tab-count badge-unread"><?= $soChuaDoc ?></span>
      <?php else: ?>
        <span class="tb-tab-count">0</span>
      <?php endif; ?>
    </a>
  </div>

  <!-- Danh sách thông báo -->
  <div class="tb-list-wrapper">
    <?php if (!$danhSachTB): ?>
      <div class="tb-empty-state">
        <div class="tb-empty-icon">🔕</div>
        <h3>Không có thông báo nào</h3>
        <p><?= $tab === 'chuadoc' ? 'Bạn đã đọc hết tất cả thông báo rồi!' : 'Khi có người trả lời bài viết hoặc tương tác với bạn, thông báo sẽ xuất hiện tại đây.' ?></p>
        <a href="<?= e(BASE_URL) ?>/client/congdong.php" class="btn-tb-community">Khám phá Cộng đồng</a>
      </div>
    <?php else: ?>
      <div class="tb-items-list">
        <?php foreach ($danhSachTB as $item): 
          $chuaDoc = (int) $item['DaDoc'] === 0;
          $iconLoai = $item['Loai'] === 'rep_bl' ? '💬' : '📝';
          $letter = mb_strtoupper(mb_substr($item['TenNguoiGui'] ?? 'U', 0, 1));
        ?>
          <div class="tb-item-card <?= $chuaDoc ? 'is-unread' : 'is-read' ?>">
            <!-- Cột Avatar -->
            <div class="tb-item-avatar-col">
              <?php if (!empty($item['AnhNguoiGui'])): ?>
                <img src="<?= e(BASE_URL . '/' . ltrim($item['AnhNguoiGui'], '/')) ?>" alt="<?= e($item['TenNguoiGui']) ?>" class="tb-avatar-img">
              <?php else: ?>
                <div class="tb-avatar-placeholder">
                  <span><?= e($letter) ?></span>
                </div>
              <?php endif; ?>
              <span class="tb-type-mini-icon"><?= $iconLoai ?></span>
            </div>

            <!-- Cột Nội dung -->
            <div class="tb-item-content-col">
              <div class="tb-item-head">
                <a href="<?= e($item['LienKet']) ?>" class="tb-item-link" onclick="fetch('thongbao_api.php?action=doc_mot', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'ma_tb=<?= (int) $item['MaTB'] ?>' });">
                  <span class="tb-sender-name"><?= e($item['TenNguoiGui'] ?? 'Một thành viên') ?></span>
                  <span class="tb-action-title"><?= e($item['TieuDe']) ?></span>
                </a>
                <span class="tb-item-time"><?= e(ThongBaoHelper::tinhThoiGian($item['NgayTao'])) ?></span>
              </div>

              <?php if (!empty($item['NoiDung'])): ?>
                <div class="tb-item-snippet">&ldquo;<?= e($item['NoiDung']) ?>&rdquo;</div>
              <?php endif; ?>
            </div>

            <!-- Cột thao tác xóa -->
            <div class="tb-item-actions-col">
              <?php if ($chuaDoc): ?>
                <span class="tb-unread-dot" title="Chưa đọc"></span>
              <?php endif; ?>
              <form method="post" action="?tab=<?= e($tab) ?>" class="form-inline" onsubmit="return confirm('Xóa thông báo này?');">
                <?= csrf_field() ?>
                <input type="hidden" name="hanh_dong" value="xoa_mot">
                <input type="hidden" name="ma_tb" value="<?= (int) $item['MaTB'] ?>">
                <button type="submit" class="btn-tb-item-delete" title="Xóa thông báo này">✕</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="tb-pagination">
        <?= $p->links() ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/layout/footer.php'; ?>

