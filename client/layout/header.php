<?php
// Header của Site Client. Cách dùng ở mỗi trang:
//   require_once __DIR__ . '/../core/bootstrap.php';   (đường dẫn tới core/bootstrap.php)
//   $tieuDe = 'Tên trang';
//   $cssTrang = 'tenfile';   (nạp assets/css/client/tenfile.css; có thể là mảng)
//   $jsTrang  = 'tenfile';   (nạp assets/js/client/tenfile.js ở cuối trang; có thể là mảng)
//   require __DIR__ . '/layout/header.php';
//   ... nội dung trang ...
//   require __DIR__ . '/layout/footer.php';
$tieuDe = $tieuDe ?? 'Đấu Trường Tri Thức';
$daDangNhap = Auth::clientId() !== null;
$cssTrang = (array) ($cssTrang ?? []);
<<<<<<< HEAD
$bodyClass = $bodyClass ?? '';
$trangHienTai = basename($_SERVER['SCRIPT_NAME'] ?? '');
// Gắn class dang-chon cho mục điều hướng của trang đang mở
$nav = static fn (string $f): string => $trangHienTai === $f ? ' class="dang-chon"' : '';
=======
$trangHienTai = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$cur = fn(string $f): string => $f === $trangHienTai ? ' aria-current="page"' : '';
>>>>>>> 0c4b402d2595452cd4eb107bb1a6a87102d9dbde
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($tieuDe) ?> - Đấu Trường Tri Thức</title>
  <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/style.css">
  <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/client/layout.css">
  <?php foreach ($cssTrang as $f): ?>
  <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/client/<?= e($f) ?>.css">
  <?php endforeach; ?>
  <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/cosmic-fix.css">
  <script type="module" src="<?= e(BASE_URL) ?>/assets/js/effects/cosmic-bg.js"></script>
  <script src="<?= e(BASE_URL) ?>/assets/js/ui.js" defer></script>
</head>
<body<?= $bodyClass !== '' ? ' class="' . e($bodyClass) . '"' : '' ?>>
<header class="topnav">
  <div class="container">
<<<<<<< HEAD
    <a class="logo" href="<?= e(BASE_URL) ?>/client/index.php"><span class="logo-tron">&#127942;</span><span>Đấu Trường<br>Tri Thức</span></a>
    <a<?= $nav('index.php') ?> href="<?= e(BASE_URL) ?>/client/index.php">Trang chủ</a>
    <a<?= $nav('timtran.php') ?> href="<?= e(BASE_URL) ?>/client/timtran.php">Tìm trận</a>
    <a<?= $nav('bxh.php') ?> href="<?= e(BASE_URL) ?>/client/bxh.php">Bảng xếp hạng</a>
    <a<?= $nav('congdong.php') ?> href="<?= e(BASE_URL) ?>/client/congdong.php">Cộng đồng</a>
=======
    <a class="logo" href="<?= e(BASE_URL) ?>/client/index.php">Đấu Trường Tri Thức</a>
    <a href="<?= e(BASE_URL) ?>/client/index.php"<?= $cur('index.php') ?>>Trang chủ</a>
    <a href="<?= e(BASE_URL) ?>/client/timtran.php"<?= $cur('timtran.php') ?>>Tìm trận</a>
    <a href="<?= e(BASE_URL) ?>/client/bxh.php"<?= $cur('bxh.php') ?>>Bảng xếp hạng</a>
    <a href="<?= e(BASE_URL) ?>/client/congdong.php"<?= $cur('congdong.php') ?>>Cộng đồng</a>
>>>>>>> 0c4b402d2595452cd4eb107bb1a6a87102d9dbde
    <span class="phai">
      <?php if ($daDangNhap): ?>
        <a href="<?= e(BASE_URL) ?>/client/lichsutran.php"<?= $cur('lichsutran.php') ?>>Lịch sử trận</a>
        <a href="<?= e(BASE_URL) ?>/client/hoso.php"<?= $cur('hoso.php') ?>>Hồ sơ</a>
        <a href="<?= e(BASE_URL) ?>/client/banbe.php"<?= $cur('banbe.php') ?>>Bạn bè</a>
        <a href="<?= e(BASE_URL) ?>/client/doimatkhau.php"<?= $cur('doimatkhau.php') ?>>Đổi mật khẩu</a>
        <a href="<?= e(BASE_URL) ?>/client/dangxuat.php"<?= $cur('dangxuat.php') ?>>Đăng xuất</a>
      <?php else: ?>
<<<<<<< HEAD
        <a class="nut-dn" href="<?= e(BASE_URL) ?>/client/login.php">Đăng nhập</a>
        <a class="nut-dk" href="<?= e(BASE_URL) ?>/client/register.php">Đăng ký</a>
=======
        <a href="<?= e(BASE_URL) ?>/client/login.php"<?= $cur('login.php') ?>>Đăng nhập</a>
        <a href="<?= e(BASE_URL) ?>/client/register.php"<?= $cur('register.php') ?>>Đăng ký</a>
>>>>>>> 0c4b402d2595452cd4eb107bb1a6a87102d9dbde
      <?php endif; ?>
    </span>
  </div>
</header>
<main class="container">