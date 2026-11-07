<?php
// Header của Site Admin (sidebar bên trái). Cách dùng ở mỗi trang:
//   require_once __DIR__ . '/../core/bootstrap.php';
//   Auth::requireAdmin();                  (mọi trang quản trị đều phải gọi - A-04)
//   $tieuDe = 'Tên trang'; $mucChon = 'nguoidung';   ($mucChon: mã mục sidebar đang mở)
//   $cssTrang = 'tenfile';   (nạp assets/css/admin/tenfile.css; có thể là mảng)
//   $jsTrang  = 'tenfile';   (nạp assets/js/admin/tenfile.js ở cuối trang; có thể là mảng)
//   $giaoDienMoi = true;     (TÙY CHỌN) dùng khung giao diện sáng mới: sidebar xanh + thanh trên + breadcrumb.
//                            Không khai báo thì trang giữ nguyên giao diện cũ, nên đổi dần từng trang.
//   require __DIR__ . '/layout/header.php';
//   ... nội dung ...
//   require __DIR__ . '/layout/footer.php';
$tieuDe  = $tieuDe  ?? 'Quản trị';
$mucChon = $mucChon ?? '';
$cssTrang = (array) ($cssTrang ?? []);
$giaoDienMoi = (bool) ($giaoDienMoi ?? false);
$menu = [
    'dashboard' => ['Tổng quan',          '/admin/index.php'],
    'nguoidung' => ['Người dùng',         '/admin/nguoidung.php'],
    'monhoc'    => ['Môn học, loại kỳ thi','/admin/monhoc.php'],
    'cauhoi'    => ['Ngân hàng câu hỏi',  '/admin/cauhoi.php'],
    'trandau'   => ['Trận đấu',           '/admin/trandau.php'],
    'elo'       => ['Elo và xếp hạng',    '/admin/elo.php'],
    'congdong'  => ['Kiểm duyệt cộng đồng','/admin/congdong.php'],
    'khaosat'   => ['Thống kê khảo sát',  '/admin/khaosat.php'],
    'cauhinh'   => ['Cấu hình hệ thống',  '/admin/cauhinh.php'],
    'nhatky'    => ['Nhật ký quản trị',   '/admin/nhatky.php'],
    'doimatkhau' => ['Đổi mật khẩu',       '/admin/doimatkhau.php'],
];

// ---- Chỉ dùng cho khung giao diện mới ----
if ($giaoDienMoi) {
    // Biểu tượng (SVG nội tuyến, kiểu Lucide, không cần mạng). Khóa trùng với $menu.
    $bieuTuong = [
        'dashboard'  => '<path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8"/><path d="M3 10a2 2 0 0 1 .709-1.528l7-5.999a2 2 0 0 1 2.582 0l7 5.999A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        'nguoidung'  => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'monhoc'     => '<path d="M12 7v14"/><path d="M3 18a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h5a4 4 0 0 1 4 4 4 4 0 0 1 4-4h5a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1h-6a3 3 0 0 0-3 3 3 3 0 0 0-3-3z"/>',
        'cauhoi'     => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/><path d="M3 12a9 3 0 0 0 18 0"/>',
        'trandau'    => '<path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/>',
        'elo'        => '<line x1="12" x2="12" y1="20" y2="10"/><line x1="18" x2="18" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="16"/>',
        'congdong'   => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
        'khaosat'    => '<path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/>',
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
        . 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $bieuTuong[$k] . '</svg>';

    // Tên quản trị viên (cùng nguồn với trang Tổng quan: cột ADMIN.HoTen) và số thông báo chưa đọc của chuông
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
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($tieuDe) ?> - Quản trị</title>
  <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/style.css">
  <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/style_admin.css">
  <?php if ($giaoDienMoi): ?>
  <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/admin/khung.css">
  <?php endif; ?>
  <?php foreach ($cssTrang as $f): ?>
  <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/admin/<?= e($f) ?>.css?v=<?= (int) @filemtime(__DIR__ . '/../../assets/css/admin/' . $f . '.css') ?>">
  <?php endforeach; ?>
  <?php if (!$giaoDienMoi): ?>
  <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/cosmic-fix.css">
  <script type="module" src="<?= e(BASE_URL) ?>/assets/js/effects/cosmic-bg.js"></script>
  <?php endif; ?>
  <script src="<?= e(BASE_URL) ?>/assets/js/ui.js" defer></script>
</head>
<?php if (!$giaoDienMoi): ?>
<body>
<div class="admin-khung">
  <nav class="sidebar">
    <span class="logo">Quản trị</span>
    <?php foreach ($menu as $ma => [$ten, $duong]): ?>
      <a href="<?= e(BASE_URL . $duong) ?>"<?= $ma === $mucChon ? ' class="dang-chon"' : '' ?>><?= e($ten) ?></a>
    <?php endforeach; ?>
    <a href="<?= e(BASE_URL) ?>/admin/vesinhvien.php" class="tach">Về trang sinh viên</a>
    <a href="<?= e(BASE_URL) ?>/admin/dangxuat.php" data-xacnhan="Bạn có chắc muốn đăng xuất không?" data-xacnhan2="Phiên đăng nhập hiện tại sẽ kết thúc và bạn cần đăng nhập lại để tiếp tục. Xác nhận đăng xuất?" data-xacnhan2-nut="Đăng xuất">Đăng xuất</a>
  </nav>
  <main class="admin-noidung">
<?php else: ?>
<body class="admin-moi">
<div class="admin-khung">
  <nav class="sidebar" aria-label="Menu quản trị">
    <a class="logo" href="<?= e(BASE_URL) ?>/admin/index.php">
      <img src="<?= e(BASE_URL) ?>/assets/img/cup.svg" alt="" width="64" height="64">
      <b>Quizora</b>
      <small>Đấu Trường Tri Thức</small>
    </a>
    <div class="menu-chinh">
      <?php foreach ($menu as $ma => [$ten, $duong]): if ($ma === 'doimatkhau') { continue; } ?>
        <a href="<?= e(BASE_URL . $duong) ?>"<?= $ma === $mucChon ? ' class="dang-chon" aria-current="page"' : '' ?>><?= $svg($ma) ?><span><?= e($ten) ?></span></a>
      <?php endforeach; ?>
    </div>
    <div class="menu-phu">
      <a href="<?= e(BASE_URL) ?>/admin/doimatkhau.php"<?= $mucChon === 'doimatkhau' ? ' class="dang-chon" aria-current="page"' : '' ?>><?= $svg('doimatkhau', 18) ?><span>Đổi mật khẩu</span></a>
      <a href="<?= e(BASE_URL) ?>/admin/vesinhvien.php"><?= $svg('vesinhvien', 18) ?><span>Về trang sinh viên</span></a>
      <a href="<?= e(BASE_URL) ?>/admin/dangxuat.php" data-xacnhan="Bạn có chắc muốn đăng xuất không?" data-xacnhan2="Phiên đăng nhập hiện tại sẽ kết thúc và bạn cần đăng nhập lại để tiếp tục. Xác nhận đăng xuất?" data-xacnhan2-nut="Đăng xuất"><?= $svg('dangxuat', 18) ?><span>Đăng xuất</span></a>
    </div>
    <img class="sidebar-cup" src="<?= e(BASE_URL) ?>/assets/img/cup.svg" alt="" width="110" height="110">
  </nav>

  <div class="admin-chinh">
    <header class="thanh-tren">
      <!-- Ô tìm kiếm nhanh: có trong ảnh thiết kế nhưng code gốc chưa có chức năng tương ứng, hiện chỉ là giao diện -->
      <label class="tt-tim"><?= $svg('tim', 18) ?><input type="search" placeholder="Tìm kiếm nhanh..." aria-label="Tìm kiếm nhanh" autocomplete="off"></label>
      <div class="tt-phai">
        <!-- Chuông: đúng cấu trúc, id và API của trang Tổng quan (assets/js/admin/thongbao_admin.js) -->
        <div class="adm-tb" id="admTb" data-api="<?= e(BASE_URL) ?>/admin/thongbao_api.php" data-csrf="<?= e(csrf_token()) ?>">
          <button type="button" class="tt-chuong" id="admTbNut" aria-label="Thông báo" aria-expanded="false" title="Thông báo cộng đồng">
            <?= $svg('chuong', 24) ?><em id="admTbBadge"<?= $soChuaDocAdmin > 0 ? '' : ' hidden' ?>><?= $soChuaDocAdmin > 99 ? '99+' : $soChuaDocAdmin ?></em>
          </button>
          <div class="adm-tb-hop" id="admTbHop" hidden>
            <div class="adm-tb-dau">
              <span class="adm-tb-tieude">Thông báo <b id="admTbTag"><?= $soChuaDocAdmin ?> mới</b></span>
              <button type="button" class="adm-tb-docHet" id="admTbDocHet">✓ Đã đọc hết</button>
            </div>
            <div class="adm-tb-ds" id="admTbDs"><p class="adm-tb-trong">Đang tải...</p></div>
            <div class="adm-tb-chan">
              <a id="admTbTatCa" href="<?= e(BASE_URL) ?>/admin/thongbao.php">Tất cả thông báo</a>
            </div>
          </div>
        </div>
        <span class="tt-vach" aria-hidden="true"></span>
        <div class="tt-admin" title="Quản trị viên">
          <span class="tt-ava" aria-hidden="true"><?= e($chuCaiAdmin) ?></span>
          <b><?= e($tenAdmin) ?></b>
          <?= $svg('xuong', 16) ?>
        </div>
      </div>
    </header>

    <main class="admin-noidung">
      <nav class="duong-dan" aria-label="Đường dẫn">
        <a href="<?= e(BASE_URL) ?>/admin/index.php" title="Tổng quan" aria-label="Tổng quan"><?= $svg('dashboard', 18) ?></a>
        <span class="phan-cach">/</span>
        <span><?= e($tieuDe) ?></span>
      </nav>
<?php endif; ?>