<?php
// Header của Site Client. Cách dùng ở mỗi trang:
//   require_once __DIR__ . '/../core/bootstrap.php';   (đường dẫn tới core/bootstrap.php)
//   $tieuDe = 'Tên trang';
//   $cssTrang = 'tenfile';   (nạp assets/css/client/tenfile.css; có thể là mảng)
//   $jsTrang  = 'tenfile';   (nạp assets/js/client/tenfile.js ở cuối trang; có thể là mảng)
//   require __DIR__ . '/layout/header.php';
//   ... nội dung trang ...
//   require __DIR__ . '/layout/footer.php';
$tieuDe = $tieuDe ?? 'QUIZUP ARENA';
$daDangNhap = Auth::clientId() !== null;
$cssTrang = (array) ($cssTrang ?? []);
$trangHienTai = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$cur = fn(string $f): string => $f === $trangHienTai ? ' aria-current="page"' : '';
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($tieuDe) ?> - QUIZUP ARENA</title>
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 48 48'%3E%3Cpolygon points='24,2.5 39.2,8.8 45.5,24 39.2,39.2 24,45.5 8.8,39.2 2.5,24 8.8,8.8' fill='%23081436' stroke='%23D4AF37' stroke-width='2'/%3E%3Ccircle cx='24' cy='26' r='7.2' fill='none' stroke='%23F3DE8A' stroke-width='2.6'/%3E%3Cpath d='M26 28 L35.5 37.5 L37.5 35.5 L28 26 Z' fill='%23D4AF37'/%3E%3Cpath d='M17 18 L19.5 13.5 L24 16.5 L28.5 13.5 L31 18 Z' fill='%23D4AF37'/%3E%3C/svg%3E">
  <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/style.css?v=<?= (int) @filemtime(__DIR__ . '/../../assets/style.css') ?>">
  <?php foreach ($cssTrang as $f): ?>
  <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/client/<?= e($f) ?>.css?v=<?= (int) @filemtime(__DIR__ . '/../../assets/css/client/' . $f . '.css') ?>">
  <?php endforeach; ?>
  <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/cosmic-fix.css?v=<?= (int) @filemtime(__DIR__ . '/../../assets/css/cosmic-fix.css') ?>">
  <script type="module" src="<?= e(BASE_URL) ?>/assets/js/effects/cosmic-bg.js"></script>
  <script src="<?= e(BASE_URL) ?>/assets/js/ui.js" defer></script>
</head>
<body>
<header class="topnav">
  <div class="container">
    <a class="logo" href="<?= e(BASE_URL) ?>/client/index.php" title="QUIZUP ARENA - Đấu Trường Tri Thức Đỉnh Cao">
      <span class="logo-huy-hieu" aria-hidden="true">
        <svg class="logo-emblem-svg" viewBox="0 0 52 52" width="44" height="44" fill="none" xmlns="http://www.w3.org/2000/svg">
          <defs>
            <linearGradient id="luxGold" x1="0" y1="0" x2="1" y2="1">
              <stop offset="0%" stop-color="#FFF8E7"/>
              <stop offset="25%" stop-color="#F3DE8A"/>
              <stop offset="55%" stop-color="#D4AF37"/>
              <stop offset="85%" stop-color="#AA820A"/>
              <stop offset="100%" stop-color="#725304"/>
            </linearGradient>
            <linearGradient id="luxGoldLight" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stop-color="#FFFFFF"/>
              <stop offset="50%" stop-color="#FFEAA7"/>
              <stop offset="100%" stop-color="#D4AF37"/>
            </linearGradient>
            <linearGradient id="luxGoldDark" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stop-color="#B8860B"/>
              <stop offset="100%" stop-color="#5A4002"/>
            </linearGradient>
            <linearGradient id="luxPlate" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stop-color="#0E1E42"/>
              <stop offset="50%" stop-color="#08122A"/>
              <stop offset="100%" stop-color="#030714"/>
            </linearGradient>
            <radialGradient id="luxGlow" cx="50%" cy="50%" r="50%">
              <stop offset="0%" stop-color="#D4AF37" stop-opacity="0.32"/>
              <stop offset="60%" stop-color="#D4AF37" stop-opacity="0.08"/>
              <stop offset="100%" stop-color="#D4AF37" stop-opacity="0"/>
            </radialGradient>
          </defs>

          <!-- Quầng hào quang hoàng kim nhẹ phía sau -->
          <circle cx="26" cy="26" r="24" fill="url(#luxGlow)"/>

          <!-- Khiên Bát Giác Hoàng Gia (Royal Octagon Seal) -->
          <polygon points="26,2.5 42.6,9.4 49.5,26 42.6,42.6 26,49.5 9.4,42.6 2.5,26 9.4,9.4"
            fill="url(#luxPlate)" stroke="url(#luxGold)" stroke-width="1.8" stroke-linejoin="round"/>

          <!-- Vòng chỉ vàng nội viền tinh xảo (Inner Inlay Ring) -->
          <polygon points="26,5.8 40.3,11.7 46.2,26 40.3,40.3 26,46.2 11.7,40.3 5.8,26 11.7,11.7"
            fill="none" stroke="url(#luxGold)" stroke-width="0.75" stroke-dasharray="3 1.5" opacity="0.65"/>

          <!-- Vòng tròn tâm huy hiệu -->
          <circle cx="26" cy="26" r="15.5" fill="#050B1B" stroke="url(#luxGold)" stroke-width="0.8" opacity="0.9"/>

          <!-- 4 Kim cương tại 4 hướng chính (Cardinal Jewels) -->
          <polygon points="26,4.2 27.2,5.5 26,6.8 24.8,5.5" fill="#FFF8E7"/>
          <polygon points="47.8,26 46.5,27.2 45.2,26 46.5,24.8" fill="#FFF8E7"/>
          <polygon points="26,47.8 27.2,46.5 26,45.2 24.8,46.5" fill="#FFF8E7"/>
          <polygon points="4.2,26 5.5,27.2 6.8,26 5.5,24.8" fill="#FFF8E7"/>

          <!-- Cành Nguyệt Quế Hoàng Gia 2 bên (Royal Laurel Wreath) -->
          <path d="M12.5 29 C12 25 13.5 20 16 16.5" fill="none" stroke="url(#luxGold)" stroke-width="0.9" stroke-linecap="round"/>
          <path d="M12 28 C10.5 26.5 11 24.5 13 25 C13 26.5 12.8 27.5 12 28 Z" fill="url(#luxGold)"/>
          <path d="M12.8 23.5 C11.5 22 12.2 20.2 14.2 21 C14 22.3 13.6 23.1 12.8 23.5 Z" fill="url(#luxGold)"/>
          <path d="M14.5 19 C13.5 17.5 14.5 16 16.2 17 C15.8 18.2 15.3 18.8 14.5 19 Z" fill="url(#luxGold)"/>

          <path d="M39.5 29 C40 25 38.5 20 36 16.5" fill="none" stroke="url(#luxGold)" stroke-width="0.9" stroke-linecap="round"/>
          <path d="M40 28 C41.5 26.5 41 24.5 39 25 C39 26.5 39.2 27.5 40 28 Z" fill="url(#luxGold)"/>
          <path d="M39.2 23.5 C40.5 22 39.8 20.2 37.8 21 C38 22.3 38.4 23.1 39.2 23.5 Z" fill="url(#luxGold)"/>
          <path d="M37.5 19 C38.5 17.5 37.5 16 35.8 17 C36.2 18.2 36.7 18.8 37.5 19 Z" fill="url(#luxGold)"/>

          <!-- Vương miện Đế Vương (Imperial Diadem Crown) -->
          <path d="M19.5 18 L21.5 13.5 L26 16 L30.5 13.5 L32.5 18 Z" fill="url(#luxGoldLight)" stroke="url(#luxGoldDark)" stroke-width="0.6"/>
          <circle cx="26" cy="13.2" r="1.3" fill="#FFFFFF" stroke="#D4AF37" stroke-width="0.5"/>
          <circle cx="21.5" cy="12.8" r="0.9" fill="#F3DE8A"/>
          <circle cx="30.5" cy="12.8" r="0.9" fill="#F3DE8A"/>
          <rect x="20.5" y="17.8" width="11" height="1.6" rx="0.8" fill="url(#luxGoldDark)"/>
          <circle cx="26" cy="18.6" r="0.8" fill="#38BDF8"/>

          <!-- Monogram Q Quyền Lực (Chiseled Gold Monogram Q) -->
          <circle cx="26" cy="27" r="7.5" fill="none" stroke="url(#luxGoldDark)" stroke-width="2.8"/>
          <circle cx="26" cy="27" r="7.5" fill="none" stroke="url(#luxGoldLight)" stroke-width="2" stroke-dasharray="24 24" stroke-dashoffset="6"/>

          <!-- Đuôi Q vát cạnh vương trượng (Chiseled Diamond Tail) -->
          <polygon points="28,29 36.5,37.5 38.5,35.5 30,27" fill="url(#luxGoldLight)"/>
          <polygon points="28.8,30.5 37,38.8 35,40.8 26.8,32.5" fill="url(#luxGoldDark)"/>
          <polygon points="36.5,37.5 39.5,39 37.5,41 35,40" fill="#FFF8E7"/>

          <!-- Ngôi sao Kim Cương Trí Tuệ Trung Tâm (Diamond Star of Excellence) -->
          <path d="M26 23 L27.2 26 L30.2 27 L27.2 28 L26 31 L24.8 28 L21.8 27 L24.8 26 Z" fill="url(#luxGoldLight)"/>
          <circle cx="26" cy="27" r="1.1" fill="#FFFFFF"/>
        </svg>
      </span>
      <span class="logo-text">
        <span class="logo-brand">QUIZUP</span>
        <span class="logo-arena">
          <span class="logo-arena-line"></span>
          <span class="logo-arena-txt">ARENA</span>
          <span class="logo-arena-line"></span>
        </span>
      </span>
    </a>
    <nav class="nav-giua" aria-label="Điều hướng chính">
      <a href="<?= e(BASE_URL) ?>/client/index.php"<?= $cur('index.php') ?>>
        <svg class="nav-ico" viewBox="0 0 20 20" width="15" height="15" aria-hidden="true"><path d="M3 8.5L10 3l7 5.5v8a1 1 0 01-1 1h-4.5v-5h-3v5H4a1 1 0 01-1-1v-8z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><circle cx="10" cy="8.5" r="1.5" fill="var(--cyan)"/></svg>
        Trang chủ
      </a>
      <a href="<?= e(BASE_URL) ?>/client/timtran.php"<?= $cur('timtran.php') ?>>
        <svg class="nav-ico" viewBox="0 0 20 20" width="15" height="15" aria-hidden="true"><path d="M4 4l4.5 4.5M16 4l-4.5 4.5M6 14l-2 2M14 14l2 2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M7 7l6 6M13 7l-6 6" stroke="var(--cyan)" stroke-width="1.8" stroke-linecap="round"/><circle cx="10" cy="10" r="1.5" fill="#FFE27A"/></svg>
        Tìm trận
      </a>
      <a href="<?= e(BASE_URL) ?>/client/bxh.php"<?= $cur('bxh.php') ?>>
        <svg class="nav-ico" viewBox="0 0 20 20" width="15" height="15" aria-hidden="true"><path d="M6 3.5h8v4.5a4 4 0 01-8 0V3.5zM6 5.5H3.5a1.5 1.5 0 000 3H6M14 5.5h2.5a1.5 1.5 0 010 3H14M8 13.5h4v3H8zM6 16.5h8" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><polygon points="10,5 10.7,6.6 12.3,6.8 11.1,7.9 11.4,9.5 10,8.7 8.6,9.5 8.9,7.9 7.7,6.8 9.3,6.6" fill="#FFD166"/></svg>
        Bảng xếp hạng
      </a>
      <a href="<?= e(BASE_URL) ?>/client/congdong.php"<?= $cur('congdong.php') ?>>
        <svg class="nav-ico" viewBox="0 0 20 20" width="15" height="15" aria-hidden="true"><path d="M10 2.5l6 3v5c0 4-2.7 7-6 8-3.3-1-6-4-6-8v-5l6-3z" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M7 10.5a3 3 0 116 0" stroke="var(--cyan)" stroke-width="1.6" stroke-linecap="round"/><circle cx="10" cy="7.5" r="1.5" fill="#FFE27A"/></svg>
        Cộng đồng
      </a>
    </nav>
    <span class="phai">
      <?php if ($daDangNhap): ?>
        <?php
          $laNhomHoSo = in_array($trangHienTai, ['hoso.php', 'lichsutran.php', 'banbe.php', 'doimatkhau.php'], true);
        ?>
        <div class="nav-dropdown" data-dropdown>
          <a class="nav-drop-btn<?= $laNhomHoSo ? ' dang-chon' : '' ?>" href="<?= e(BASE_URL) ?>/client/hoso.php"<?= $cur('hoso.php') ?>>
            <svg class="nav-ico" viewBox="0 0 20 20" width="15" height="15" aria-hidden="true"><polygon points="10,2 17,6 17,14 10,18 3,14 3,6" fill="none" stroke="currentColor" stroke-width="1.6"/><circle cx="10" cy="8" r="2.4" fill="var(--cyan)"/><path d="M6.5 14.5c.8-1.8 2-2.5 3.5-2.5s2.7.7 3.5 2.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
            Hồ sơ
            <span class="nav-mui-ten" aria-hidden="true">▾</span>
          </a>
          <div class="nav-dropdown-menu">
            <a href="<?= e(BASE_URL) ?>/client/hoso.php"<?= $cur('hoso.php') ?>>
              <span class="menu-icon"><svg viewBox="0 0 20 20" width="16" height="16" aria-hidden="true"><rect x="2.5" y="3.5" width="15" height="13" rx="2.5" fill="none" stroke="currentColor" stroke-width="1.6"/><circle cx="7" cy="8.5" r="2" fill="var(--cyan)"/><line x1="11.5" y1="7.5" x2="15" y2="7.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><line x1="11.5" y1="10.5" x2="15" y2="10.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M4.5 13.5c.7-1.3 1.6-1.8 2.5-1.8s1.8.5 2.5 1.8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></span>
              Thông tin hồ sơ
            </a>
            <a href="<?= e(BASE_URL) ?>/client/lichsutran.php"<?= $cur('lichsutran.php') ?>>
              <span class="menu-icon"><svg viewBox="0 0 20 20" width="16" height="16" aria-hidden="true"><path d="M14.5 2.5l3 3-8 8-2.5.5.5-2.5 7-9zM5.5 2.5l-3 3 8 8 2.5.5-.5-2.5-7-9z" fill="none" stroke="currentColor" stroke-width="1.5"/><line x1="4.5" y1="15.5" x2="2" y2="18" stroke="var(--cyan)" stroke-width="2" stroke-linecap="round"/><line x1="15.5" y1="15.5" x2="18" y2="18" stroke="var(--cyan)" stroke-width="2" stroke-linecap="round"/></svg></span>
              Lịch sử trận
            </a>
            <a href="<?= e(BASE_URL) ?>/client/banbe.php"<?= $cur('banbe.php') ?>>
              <span class="menu-icon"><svg viewBox="0 0 20 20" width="16" height="16" aria-hidden="true"><circle cx="10" cy="6" r="2.8" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M5.5 14c.8-2.4 2.4-3.5 4.5-3.5s3.7 1.1 4.5 3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><circle cx="4" cy="8" r="2" fill="none" stroke="var(--cyan)" stroke-width="1.4"/><path d="M1.5 14.5c.5-1.5 1.4-2.2 2.5-2.2" stroke="var(--cyan)" stroke-width="1.4" stroke-linecap="round"/><circle cx="16" cy="8" r="2" fill="none" stroke="var(--cyan)" stroke-width="1.4"/><path d="M18.5 14.5c-.5-1.5-1.4-2.2-2.5-2.2" stroke="var(--cyan)" stroke-width="1.4" stroke-linecap="round"/></svg></span>
              Bạn bè
            </a>
            <a href="<?= e(BASE_URL) ?>/client/doimatkhau.php"<?= $cur('doimatkhau.php') ?>>
              <span class="menu-icon"><svg viewBox="0 0 20 20" width="16" height="16" aria-hidden="true"><path d="M10 2l6 2.5v5c0 4-2.5 7-6 8.5-3.5-1.5-6-4.5-6-8.5v-5L10 2z" fill="none" stroke="currentColor" stroke-width="1.6"/><rect x="7.5" y="8.5" width="5" height="4" rx="1" fill="var(--cyan)"/><path d="M8.5 8.5V7a1.5 1.5 0 013 0v1.5" fill="none" stroke="currentColor" stroke-width="1.4"/></svg></span>
              Đổi mật khẩu
            </a>
          </div>
        </div>
        <a class="nav-nut-phu" href="<?= e(BASE_URL) ?>/client/dangxuat.php"<?= $cur('dangxuat.php') ?>>
          <svg viewBox="0 0 20 20" width="14" height="14" aria-hidden="true"><path d="M7 3H4a1 1 0 00-1 1v12a1 1 0 001 1h3M12 6l4 4-4 4M16 10H7" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
          Đăng xuất
        </a>
      <?php else: ?>
        <a class="nav-nut-phu" href="<?= e(BASE_URL) ?>/client/login.php"<?= $cur('login.php') ?>>
          <svg viewBox="0 0 20 20" width="14" height="14" aria-hidden="true"><circle cx="10" cy="6.5" r="3.3" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M4 16.5c1.2-3 3.4-4.3 6-4.3s4.8 1.3 6 4.3" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
          Đăng nhập
        </a>
        <a class="nav-nut-chinh" href="<?= e(BASE_URL) ?>/client/register.php"<?= $cur('register.php') ?>>Đăng ký</a>
      <?php endif; ?>
    </span>
  </div>
</header>
<main class="container">