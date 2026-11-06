<?php
// Phòng thi đấu: chờ cả hai vào phòng, thi đấu (câu hỏi, đồng hồ, điểm), kết quả sơ bộ (C-23 đến C-31).
// Đặt tại: elo_quiz/client/phong.php
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/GhepDoi.php';
require_once __DIR__ . '/../core/LoLChampions.php';
require_once __DIR__ . '/../core/ThiDau.php';

$maND   = Auth::requireClient(BASE_URL . '/client/login.php');
$pdo    = Database::pdo();
$maTran = (int) ($_GET['tran'] ?? 0);

// Chỉ người chơi của trận mới được vào phòng
$st = $pdo->prepare('SELECT 1 FROM NGUOICHOITRANDAU WHERE MaTran = ? AND MaND = ?');
$st->execute([$maTran, $maND]);
if (!$st->fetchColumn()) {
    redirect(BASE_URL . '/client/timtran.php');
}

GhepDoi::vaoPhong($pdo, $maTran, $maND);

$st = $pdo->prepare(
    'SELECT t.TrangThai, t.LyDoKetThuc, m.TenMon, k.TenLoaiKT
       FROM TRANDAU t JOIN MONHOC m ON m.MaMon = t.MaMon JOIN LOAIKYTHI k ON k.MaLoaiKT = t.MaLoaiKT
      WHERE t.MaTran = ?'
);
$st->execute([$maTran]);
$tran = $st->fetch();

$st = $pdo->prepare(
    'SELECT n.MaND, u.HoTen, n.EloTruoc, n.EloSau, n.TrangThaiThamGia, n.DiemSo, n.KetQua
       FROM NGUOICHOITRANDAU n JOIN NGUOIDUNG u ON u.MaND = n.MaND
      WHERE n.MaTran = ?'
);
$st->execute([$maTran]);
$toi = $doiThu = null;
foreach ($st->fetchAll() as $r) {
    if ((int) $r['MaND'] === $maND) {
        $toi = $r;
    } else {
        $doiThu = $r;
    }
}

// Xác định Đấu Thần Plants vs Zombies của Người chơi và Đối thủ
$tuongChonId = $_SESSION['tuong_chon'] ?? 'sunflower';
$tuongToi = LoLChampions::layTuong($tuongChonId);

// Gán tướng cho đối thủ dựa trên ID và mã trận
$dsTuongKeys = array_keys(LoLChampions::DANH_SACH);
$availableKeys = array_values(array_diff($dsTuongKeys, [$tuongChonId]));
if (empty($availableKeys)) {
    $availableKeys = $dsTuongKeys;
}
$doiThuIndex = abs((int) crc32((string) ($doiThu['MaND'] ?? 1) . '_' . $maTran)) % count($availableKeys);
$tuongDoiThuId = $availableKeys[$doiThuIndex];
$tuongDoiThu = LoLChampions::layTuong($tuongDoiThuId);

$tieuDe = 'Vũ Đài Đấu Thần 1v1';
$cssTrang = 'phong';
$jsTrang  = 'phong';
require __DIR__ . '/layout/header.php';
?>

<div class="phong-container">
  <!-- ARENA HEADER BAR -->
  <div class="arena-match-header">
    <div class="header-left-info">
      <span class="match-mode-tag"><span class="live-pulse"></span> TRẬN ĐẤU XẾP HẠNG 1 VS 1</span>
      <h2 class="arena-topic-title"><?= e($tran['TenMon']) ?> &middot; <span class="text-gold"><?= e($tran['TenLoaiKT']) ?></span></h2>
    </div>
    <div class="header-right-actions">
      <?php if ($tran['TrangThai'] === 'Đang diễn ra'): ?>
        <button type="button" id="nutChiuThua" class="btn-surrender">
          <span class="flag-icon">🏳</span> ĐẦU HÀNG
        </button>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($tran['TrangThai'] === 'Đã ghép'): ?>
    <!-- VERSUS FACE-OFF LOBBY (CHỜ ĐỐI THỦ VÀO PHÒNG) -->
    <div class="vs-lobby-card card" data-tai-lai="1200">
      <div class="pet-corner tl"></div><div class="pet-corner tr"></div>
      <div class="pet-corner bl"></div><div class="pet-corner br"></div>

      <div class="vs-lobby-header">
        <span class="vs-tag">CHỜ KHỞI TRANH</span>
        <h3 class="vs-main-title">ĐẤU THẦN TIẾN VÀO VŨ ĐÀI</h3>
        <p class="vs-sub">
          <?= ($doiThu['TrangThaiThamGia'] ?? '') === 'Chưa vào' ? 'Đang đợi đối thủ xác nhận bước vào đấu trường...' : 'Cả hai đấu thủ đã có mặt! Chuẩn bị giao tranh...' ?>
        </p>
      </div>

      <div class="vs-faceoff-stage">
        <!-- Blue Player -->
        <div class="vs-fighter-card blue-side">
          <div class="fighter-glow-ring" style="border-color: <?= e($tuongToi['mauSac']) ?>; box-shadow: 0 0 30px <?= e($tuongToi['mauSac']) ?>66;">
            <img src="<?= e(BASE_URL . $tuongToi['avatar']) ?>" alt="<?= e($tuongToi['ten']) ?>" class="fighter-img">
          </div>
          <div class="fighter-name-box">
            <span class="fighter-side-tag blue">ĐỘI XANH (BẠN)</span>
            <h4 class="fighter-player-name"><?= e($toi['HoTen']) ?></h4>
            <div class="fighter-champ-title" style="color: <?= e($tuongToi['mauSac']) ?>;"><?= e($tuongToi['ten']) ?> &middot; <?= e($tuongToi['danhHieu']) ?></div>
            <div class="fighter-elo-badge">ELO <?= (int) $toi['EloTruoc'] ?></div>
          </div>
        </div>

        <!-- Center VS Emblem -->
        <div class="vs-center-emblem">
          <div class="vs-burst-ring"></div>
          <div class="vs-letters">VS</div>
          <div class="vs-pulse-loader">
            <div class="loader-dot"></div><div class="loader-dot"></div><div class="loader-dot"></div>
          </div>
        </div>

        <!-- Red Opponent -->
        <div class="vs-fighter-card red-side">
          <div class="fighter-glow-ring" style="border-color: <?= e($tuongDoiThu['mauSac']) ?>; box-shadow: 0 0 30px <?= e($tuongDoiThu['mauSac']) ?>66;">
            <img src="<?= e(BASE_URL . $tuongDoiThu['avatar']) ?>" alt="<?= e($tuongDoiThu['ten']) ?>" class="fighter-img">
          </div>
          <div class="fighter-name-box">
            <span class="fighter-side-tag red">ĐỘI ĐỎ (ĐỐI THỦ)</span>
            <h4 class="fighter-player-name"><?= e($doiThu['HoTen']) ?></h4>
            <div class="fighter-champ-title" style="color: <?= e($tuongDoiThu['mauSac']) ?>;"><?= e($tuongDoiThu['ten']) ?> &middot; <?= e($tuongDoiThu['danhHieu']) ?></div>
            <div class="fighter-elo-badge">ELO <?= (int) $doiThu['EloTruoc'] ?></div>
          </div>
        </div>
      </div>
    </div>

  <?php elseif ($tran['TrangThai'] === 'Đang diễn ra'): ?>
    <!-- 1 VS 1 PLANTS VS ZOMBIES COMBAT ARENA -->
    <div id="thi"
         data-api="<?= e(BASE_URL . '/client/api_thi.php?tran=' . $maTran) ?>"
         data-csrf="<?= e(csrf_token()) ?>"
         data-my-champ="<?= e($tuongToi['id']) ?>"
         data-my-champ-name="<?= e($tuongToi['ten']) ?>"
         data-my-champ-q="<?= e($tuongToi['chieuQ']) ?>"
         data-my-champ-r="<?= e($tuongToi['chieuR']) ?>"
         data-my-champ-buff="<?= e($tuongToi['buffLoai'] ?? '') ?>"
         data-opp-champ="<?= e($tuongDoiThu['id']) ?>"
         data-opp-champ-name="<?= e($tuongDoiThu['ten']) ?>"
         data-opp-champ-buff="<?= e($tuongDoiThu['buffLoai'] ?? '') ?>">

      <!-- COMBAT STAGE (TOP 1V1 BATTLE VIEW) -->
      <div class="arena-combat-stage" id="arenaCombatStage">
        <!-- Floating Damage & Projectile Layer -->
        <div class="combat-fx-layer" id="combatFxLayer"></div>

        <!-- LoL Announcer Floating Banner -->
        <div class="lol-announcer-banner" id="lolAnnouncerBanner" style="display: none;">
          <div class="announcer-wings left"></div>
          <div class="announcer-content">
            <div class="announcer-main-title" id="announcerTitle">FIRST BLOOD</div>
            <div class="announcer-sub-title" id="announcerSub">CHIẾN CÔNG ĐẦU</div>
          </div>
          <div class="announcer-wings right"></div>
        </div>

        <!-- Baron Steal Sudden Death Banner -->
        <div class="baron-alert-banner" id="baronAlertBanner" style="display: none;">
          <span class="baron-skull">🐉</span>
          <span class="baron-text">VÒNG QUYẾT ĐẤU BARON STEAL &middot; SÁT THƯƠNG NHÂN ĐÔI!</span>
        </div>

        <!-- BLUE FIGHTER (YOU) -->
        <div class="combat-fighter blue-corner" id="blueFighter">
          <div class="fighter-podium">
            <div class="fighter-avatar-box" id="myAvatarBox" style="border-color: <?= e($tuongToi['mauSac']) ?>; box-shadow: 0 0 25px <?= e($tuongToi['mauSac']) ?>88;">
              <img src="<?= e(BASE_URL . $tuongToi['avatar']) ?>" alt="<?= e($tuongToi['ten']) ?>" class="combat-avatar-img" id="myChampAvatar">
              <div class="hit-red-overlay"></div>
              <div class="stun-stars" id="myStunStars" style="display: none;">💫</div>
            </div>
            <div class="fighter-info-col">
              <div class="player-headline">
                <span class="corner-side-badge blue">BẠN</span>
                <span class="player-handle"><?= e($toi['HoTen']) ?></span>
              </div>
              <div class="champ-label" style="color: <?= e($tuongToi['mauSac']) ?>;"><?= e($tuongToi['ten']) ?></div>

              <!-- Health Bar -->
              <div class="bar-container hp-bar-wrap">
                <div class="bar-label-row">
                  <span class="bar-title">HP</span>
                  <span class="bar-val" id="myHpVal">1000 / 1000</span>
                </div>
                <div class="bar-track">
                  <div class="bar-fill hp-fill-blue" id="myHpBar" style="width: 100%;"></div>
                  <div class="bar-ghost" id="myHpGhost" style="width: 100%;"></div>
                </div>
              </div>

              <!-- Mana / Energy Bar -->
              <div class="bar-container mana-bar-wrap">
                <div class="bar-label-row">
                  <span class="bar-title">NỘ / MANA</span>
                  <span class="bar-val" id="myManaVal">0%</span>
                </div>
                <div class="bar-track">
                  <div class="bar-fill mana-fill-blue" id="myManaBar" style="width: 0%;"></div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- CENTER BATTLE LANE & TIMER -->
        <div class="combat-center-lane">
          <!-- Question Count Pill -->
          <div class="question-tracker-pill">
            <span class="tracker-label">CÂU HỎI</span>
            <span class="tracker-numbers"><span id="soCauHienTai">1</span> / <span id="tongSoCau">10</span></span>
          </div>

          <!-- Circular Neon SVG Timer -->
          <div class="arena-timer-box">
            <svg class="timer-svg" viewBox="0 0 100 100">
              <circle class="timer-track-circle" cx="50" cy="50" r="42"></circle>
              <circle class="timer-progress-circle" cx="50" cy="50" r="42" id="timerCircle"></circle>
            </svg>
            <div class="timer-center-content">
              <span class="timer-seconds" id="demNguocGiay">--</span>
              <span class="timer-unit">GIÂY</span>
            </div>
          </div>

          <!-- Killstreak Multiplier Badge -->
          <div class="killstreak-badge" id="killstreakBadge" style="display: none;">
            <span class="streak-flame">🔥</span>
            <span class="streak-text" id="killstreakText">COMBO x2</span>
          </div>
        </div>

        <!-- RED FIGHTER (OPPONENT) -->
        <div class="combat-fighter red-corner" id="redFighter">
          <div class="fighter-podium reverse">
            <div class="fighter-avatar-box" id="oppAvatarBox" style="border-color: <?= e($tuongDoiThu['mauSac']) ?>; box-shadow: 0 0 25px <?= e($tuongDoiThu['mauSac']) ?>88;">
              <img src="<?= e(BASE_URL . $tuongDoiThu['avatar']) ?>" alt="<?= e($tuongDoiThu['ten']) ?>" class="combat-avatar-img" id="oppChampAvatar">
              <div class="hit-red-overlay"></div>
              <div class="stun-stars" id="oppStunStars" style="display: none;">💫</div>
            </div>
            <div class="fighter-info-col reverse">
              <div class="player-headline">
                <span class="player-handle"><?= e($doiThu['HoTen']) ?></span>
                <span class="corner-side-badge red">ĐỐI THỦ</span>
              </div>
              <div class="champ-label" style="color: <?= e($tuongDoiThu['mauSac']) ?>;"><?= e($tuongDoiThu['ten']) ?></div>

              <!-- Health Bar -->
              <div class="bar-container hp-bar-wrap">
                <div class="bar-label-row">
                  <span class="bar-title">HP</span>
                  <span class="bar-val" id="oppHpVal">1000 / 1000</span>
                </div>
                <div class="bar-track">
                  <div class="bar-fill hp-fill-red" id="oppHpBar" style="width: 100%;"></div>
                  <div class="bar-ghost" id="oppHpGhost" style="width: 100%;"></div>
                </div>
              </div>

              <!-- Mana / Energy Bar -->
              <div class="bar-container mana-bar-wrap">
                <div class="bar-label-row">
                  <span class="bar-title">NỘ / MANA</span>
                  <span class="bar-val" id="oppManaVal">0%</span>
                </div>
                <div class="bar-track">
                  <div class="bar-fill mana-fill-red" id="oppManaBar" style="width: 0%;"></div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Live Match Score Ticker (Keeps existing API sync) -->
      <div id="tiendo" class="live-score-ticker" style="display: none;"></div>

      <!-- QUESTION & SKILLS CONSOLE (BOTTOM CONSOLE) -->
      <div class="arena-question-console card" id="consoleCard">
        <div class="pet-corner tl"></div><div class="pet-corner tr"></div>
        <div class="pet-corner bl"></div><div class="pet-corner br"></div>

        <div id="khung" class="quiz-content-area">
          <div class="waiting-prompt">
            <div class="spinner-ring"></div>
            <p>Đang kết nối tín hiệu câu hỏi với máy chủ Đấu Trường...</p>
          </div>
        </div>
      </div>

      <!-- Hotkey Hint Bar -->
      <div class="hotkey-hint-bar">
        <span>⌨️ <strong>MẸO CHIẾN ĐẤU:</strong> Bấm phím <strong>[Q]</strong>, <strong>[W]</strong>, <strong>[E]</strong>, <strong>[R]</strong> trên bàn phím để tung chiêu nhanh như chớp!</span>
      </div>
    </div>

  <?php elseif ($tran['TrangThai'] === 'Hủy'): ?>
    <!-- MATCH CANCELLED SCREEN -->
    <div class="card match-cancelled-card">
      <div class="pet-corner tl"></div><div class="pet-corner tr"></div>
      <div class="pet-corner bl"></div><div class="pet-corner br"></div>
      <div class="cancelled-icon">⚠️</div>
      <h3 class="cancelled-title">TRẬN ĐẤU ĐÃ BỊ HỦY</h3>
      <p class="cancelled-desc"><?= e($tran['LyDoKetThuc']) ?>. Điểm Elo của bạn không thay đổi.</p>
      <div class="cancelled-actions">
        <a class="btn-quick-match" href="<?= e(BASE_URL) ?>/client/timtran.php">
          <span>⚔️ TÌM TRẬN MỚI</span>
        </a>
      </div>
    </div>

  <?php else: ?>
    <!-- MATCH FINISHED CEREMONY: GRAND ESPORTS VICTORY / DEFEAT -->
    <?php
    $kq = (string) $toi['KetQua'];
    $isWin = ($kq === 'Thắng');
    $isLose = ($kq === 'Thua');
    $dEloToi = ($toi['EloSau'] !== null) ? ((int) $toi['EloSau'] - (int) $toi['EloTruoc']) : 0;
    ?>
    <div class="post-match-ceremony card <?= $isWin ? 'win-ceremony' : ($isLose ? 'lose-ceremony' : 'draw-ceremony') ?>">
      <div class="pet-corner tl"></div><div class="pet-corner tr"></div>
      <div class="pet-corner bl"></div><div class="pet-corner br"></div>

      <!-- Banner Header -->
      <div class="ceremony-crest">
        <?php if ($isWin): ?>
          <div class="crest-emblem gold">👑</div>
          <div class="ceremony-status-tag">ESPORTS CHAMPION</div>
          <h2 class="ceremony-main-title victory-glow">VICTORY &middot; CHIẾN THẮNG</h2>
        <?php elseif ($isLose): ?>
          <div class="crest-emblem silver">🛡️</div>
          <div class="ceremony-status-tag">MATCH DEFEAT</div>
          <h2 class="ceremony-main-title defeat-glow">DEFEAT &middot; THẤT BẠI</h2>
        <?php else: ?>
          <div class="crest-emblem blue">⚖️</div>
          <div class="ceremony-status-tag">STALEMATE</div>
          <h2 class="ceremony-main-title draw-glow">DRAW &middot; HÒA ĐẤU</h2>
        <?php endif; ?>
        <p class="ceremony-sub"><?= e($tran['LyDoKetThuc']) ?></p>
      </div>

      <!-- Post-Match Podium (Blue vs Red) -->
      <div class="ceremony-podium-grid">
        <!-- Player Box -->
        <div class="podium-card <?= $isWin ? 'winner' : '' ?>">
          <div class="podium-avatar-glow" style="border-color: <?= e($tuongToi['mauSac']) ?>;">
            <img src="<?= e(BASE_URL . $tuongToi['avatar']) ?>" alt="<?= e($tuongToi['ten']) ?>" class="podium-img">
          </div>
          <div class="podium-name-tag">BẠN &middot; <?= e($toi['HoTen']) ?></div>
          <div class="podium-champ-name" style="color: <?= e($tuongToi['mauSac']) ?>;"><?= e($tuongToi['ten']) ?></div>
          <div class="podium-score-val"><?= (int) $toi['DiemSo'] ?> <span class="unit">ĐIỂM</span></div>
          <?php if ($toi['EloSau'] !== null): ?>
            <div class="podium-elo-change <?= $dEloToi >= 0 ? 'plus' : 'minus' ?>">
              ELO: <?= (int) $toi['EloTruoc'] ?> &rarr; <strong><?= (int) $toi['EloSau'] ?></strong>
              <span class="elo-pill"><?= $dEloToi >= 0 ? '+' : '' ?><?= $dEloToi ?></span>
            </div>
          <?php endif; ?>
        </div>

        <div class="podium-vs-separator">VS</div>

        <!-- Opponent Box -->
        <div class="podium-card <?= $isLose ? 'winner' : '' ?>">
          <div class="podium-avatar-glow" style="border-color: <?= e($tuongDoiThu['mauSac']) ?>;">
            <img src="<?= e(BASE_URL . $tuongDoiThu['avatar']) ?>" alt="<?= e($tuongDoiThu['ten']) ?>" class="podium-img">
          </div>
          <div class="podium-name-tag">ĐỐI THỦ &middot; <?= e($doiThu['HoTen']) ?></div>
          <div class="podium-champ-name" style="color: <?= e($tuongDoiThu['mauSac']) ?>;"><?= e($tuongDoiThu['ten']) ?></div>
          <div class="podium-score-val"><?= (int) $doiThu['DiemSo'] ?> <span class="unit">ĐIỂM</span></div>
          <?php if ($doiThu['EloSau'] !== null): ?>
            <?php $dEloDoiThu = (int) $doiThu['EloSau'] - (int) $doiThu['EloTruoc']; ?>
            <div class="podium-elo-change <?= $dEloDoiThu >= 0 ? 'plus' : 'minus' ?>">
              ELO: <?= (int) $doiThu['EloTruoc'] ?> &rarr; <strong><?= (int) $doiThu['EloSau'] ?></strong>
              <span class="elo-pill"><?= $dEloDoiThu >= 0 ? '+' : '' ?><?= $dEloDoiThu ?></span>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Action Buttons -->
      <div class="ceremony-actions">
        <a class="btn-quick-match" href="<?= e(BASE_URL) ?>/client/timtran.php">
          <span class="sword-icon">⚔️</span> TÌM TRẬN MỚI
        </a>
        <a class="btn-ceremony-ghost" href="<?= e(BASE_URL) ?>/client/index.php">
          <span>🏠 VỀ TRANG CHỦ</span>
        </a>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/layout/footer.php'; ?>
