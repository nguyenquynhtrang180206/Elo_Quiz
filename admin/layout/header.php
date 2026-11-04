<?php
// Header của Site Admin (sidebar bên trái). Cách dùng ở mỗi trang:
//   require_once __DIR__ . '/../core/bootstrap.php';
//   Auth::requireAdmin();                  (mọi trang quản trị đều phải gọi - A-04)
//   $tieuDe = 'Tên trang'; $mucChon = 'nguoidung';   ($mucChon: mã mục sidebar đang mở)
//   $cssTrang = 'tenfile';   (nạp assets/css/admin/tenfile.css; có thể là mảng)
//   $jsTrang  = 'tenfile';   (nạp assets/js/admin/tenfile.js ở cuối trang; có thể là mảng)
//   require __DIR__ . '/layout/header.php';
//   ... nội dung ...
//   require __DIR__ . '/layout/footer.php';
$tieuDe  = $tieuDe  ?? 'Quản trị';
$mucChon = $mucChon ?? '';
$cssTrang = (array) ($cssTrang ?? []);
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
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($tieuDe) ?> - Quản trị</title>
  <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/style.css">
  <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/style_admin.css">
  <?php foreach ($cssTrang as $f): ?>
  <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/admin/<?= e($f) ?>.css">
  <?php endforeach; ?>
  <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/cosmic-fix.css">
  <script type="module" src="<?= e(BASE_URL) ?>/assets/js/effects/cosmic-bg.js"></script>
  <script src="<?= e(BASE_URL) ?>/assets/js/ui.js" defer></script>
</head>
<body>
<div class="admin-khung">
  <nav class="sidebar">
    <span class="logo">Quản trị</span>
    <?php foreach ($menu as $ma => [$ten, $duong]): ?>
      <a href="<?= e(BASE_URL . $duong) ?>"<?= $ma === $mucChon ? ' class="dang-chon"' : '' ?>><?= e($ten) ?></a>
    <?php endforeach; ?>
    <a href="<?= e(BASE_URL) ?>/client/index.php" class="tach">Về trang sinh viên</a>
    <a href="<?= e(BASE_URL) ?>/admin/dangxuat.php">Đăng xuất</a>
  </nav>
  <main class="admin-noidung">