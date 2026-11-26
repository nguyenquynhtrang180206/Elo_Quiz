<?php
// Khung giao diện quản trị (sidebar trái), yêu cầu Auth::requireAdmin() trước khi nạp.
$tieuDe   = $tieuDe  ?? 'Quản trị';
$mucChon  = $mucChon ?? '';
$cssTrang = (array) ($cssTrang ?? []);
$menu = [
    'dashboard' => ['Tổng quan',          '/admin/index.php'],
    'nguoidung' => ['Người dùng',         '/admin/nguoidung.php'],
    'monhoc'    => ['Môn học, loại kỳ thi','/admin/monhoc.php'],
    'cauhoi'    => ['Ngân hàng câu hỏi',  '/admin/cauhoi.php'],
    'trandau'   => ['Trận đấu',           '/admin/trandau.php'],
    'congdong'  => ['Kiểm duyệt cộng đồng','/admin/congdong.php'],
    'cauhinh'   => ['Cấu hình hệ thống',  '/admin/cauhinh.php'],
    'nhatky'    => ['Nhật ký quản trị',   '/admin/nhatky.php'],
    'doimatkhau' => ['Đổi mật khẩu',       '/admin/doimatkhau.php'],
];

// Biểu tượng SVG nội tuyến (khóa khớp với $menu).
$bieuTuong = [
    'dashboard'  => '<path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8"/><path d="M3 10a2 2 0 0 1 .709-1.528l7-5.999a2 2 0 0 1 2.582 0l7 5.999A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
    'nguoidung'  => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'monhoc'     => '<path d="M12 7v14"/><path d="M3 18a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h5a4 4 0 0 1 4 4 4 4 0 0 1 4-4h5a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1h-6a3 3 0 0 0-3 3 3 3 0 0 0-3-3z"/>',
    'cauhoi'     => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/><path d="M3 12a9 3 0 0 0 18 0"/>',
    'trandau'    => '<path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/>',
    'congdong'   => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
    'cauhinh'    => '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
    'nhatky'     => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
    'doimatkhau' => '<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
    'vesinhvien' => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" x2="3" y1="12" y2="12"/>',
    'dangxuat'   => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/>',
    'chuong'     => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
    'tim'        => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
    'xuong'      => '<path d="m6 9 6 6 6-6"/>',
];
$svg = static fn (string $k, int $px = 20): string =>
    '<svg class="bt" width="' . $px . '" height="' . $px . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
    . 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($bieuTuong[$k] ?? '') . '</svg>';

// Tên quản trị viên và số thông báo chưa đọc.
$maAdminKhung = (int) Auth::adminId();
if (!isset($tenAdmin)) {
    $stAd = Database::pdo()->prepare('SELECT HoTen FROM ADMIN WHERE MaAdmin = ?');
    $stAd->execute([$maAdminKhung]);
    $tenAdmin = (string) ($stAd->fetchColumn() ?: 'Admin');
}
if (!isset($soChuaDocAdmin)) {
    $soChuaDocAdmin = ThongBaoAdmin::laySoChuaDoc(Database::pdo(), $maAdminKhung);
}
$chuCaiAdmin = mb_strtoupper(mb_substr(trim($tenAdmin), 0, 1)) ?: 'A';
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($tieuDe) ?> - QUIZUP ARENA | Quản trị</title>
  <link rel="icon" type="image/svg+xml" href="<?= e(BASE_URL) ?>/assets/img/quizup-emblem.svg">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400;1,600&family=Inter:wght@400;500;600;700;800&family=Rajdhani:wght@600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/style.css?v=<?= (int) @filemtime(__DIR__ . '/../../assets/style.css') ?>">
  <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/style_admin.css?v=<?= (int) @filemtime(__DIR__ . '/../../assets/style_admin.css') ?>">
  <?php foreach ($cssTrang as $f): ?>
  <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/admin/<?= e($f) ?>.css?v=<?= (int) @filemtime(__DIR__ . '/../../assets/css/admin/' . $f . '.css') ?>">
  <?php endforeach; ?>
  <script type="module" src="<?= e(BASE_URL) ?>/assets/js/effects/cosmic-bg.js"></script>
  <script src="<?= e(BASE_URL) ?>/assets/js/ui.js" defer></script>
  <script>
    if (localStorage.getItem('quizora_sidebar_hidden') === '1') {
      document.documentElement.classList.add('sidebar-hidden');
    }
  </script>
  <script src="<?= e(BASE_URL) ?>/assets/js/admin/sidebar_toggle.js?v=<?= (int) @filemtime(__DIR__ . '/../../assets/js/admin/sidebar_toggle.js') ?>" defer></script>
</head>
<body class="admin-body">
<!-- Nút mở sidebar khi đang ẩn -->
<button type="button" class="sidebar-expand-btn" id="sidebarExpandBtn" title="Mở thanh điều hướng (Ctrl+B)" aria-label="Mở thanh điều hướng">
  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M9 3v18"/><path d="m13 15 3-3-3-3"/></svg>
  <span>Menu</span>
</button>
<!-- Nút mở menu trên di động -->
<button type="button" class="admin-hamburger" id="adminHamburger" aria-label="Mở menu điều hướng" aria-controls="adminSidebar" aria-expanded="false">
  <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="4" x2="20" y1="6" y2="6"/><line x1="4" x2="20" y1="12" y2="12"/><line x1="4" x2="20" y1="18" y2="18"/></svg>
</button>
<div class="admin-khung">
  <!-- Lớp nền mờ đóng menu trên di động -->
  <div class="sidebar-overlay" id="sidebarOverlay" aria-hidden="true"></div>
  <nav class="sidebar" id="adminSidebar" aria-label="Menu quản trị">
    <div class="logo">
      <img class="quizup-logo-emblem" src="<?= e(BASE_URL) ?>/assets/img/quizup-emblem.svg" alt="" width="44" height="44">
      <div class="quizup-wordmark">
        <strong>QUIZUP</strong>
        <small>ARENA</small>
      </div>
      <span class="logo-tag">Admin</span>
      <button type="button" class="sidebar-toggle-btn" id="sidebarCollapseBtn" title="Thu gọn thanh điều hướng (Ctrl+B)" aria-label="Thu gọn thanh điều hướng">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M9 3v18"/><path d="m14 9-3 3 3 3"/></svg>
      </button>
    </div>
    <div class="menu-chinh">
      <?php foreach ($menu as $ma => [$ten, $duong]): ?>
        <a href="<?= e(BASE_URL . $duong) ?>"<?= $ma === $mucChon ? ' class="dang-chon" aria-current="page"' : '' ?>>
          <?= $svg($ma, 18) ?>
          <span><?= e($ten) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
    <div class="menu-phu">
      <a href="<?= e(BASE_URL) ?>/admin/vesinhvien.php" class="tach"><?= $svg('vesinhvien', 18) ?><span>Về trang sinh viên</span></a>
      <a href="<?= e(BASE_URL) ?>/admin/dangxuat.php" data-xacnhan="Bạn có chắc muốn đăng xuất không?" data-xacnhan2="Phiên đăng nhập hiện tại sẽ kết thúc và bạn cần đăng nhập lại để tiếp tục. Xác nhận đăng xuất?" data-xacnhan2-nut="Đăng xuất"><?= $svg('dangxuat', 18) ?><span>Đăng xuất</span></a>
    </div>
  </nav>
  <main class="admin-noidung">