<?php
// Trang chủ QUIZUP ARENA (C-11, C-12). Đặt tại: elo_quiz/client/index.php
// Đăng nhập rồi: lời chào, nút Tìm trận, tóm tắt thành tích, trận gần đây, BXH nổi bật, Tin tức & Sự kiện.
// Khách chưa đăng nhập vẫn xem được BXH nổi bật và Tin tức & Sự kiện (C-12).
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/GhepDoi.php';
require_once __DIR__ . '/../core/CongDongHelper.php';

$pdo = Database::pdo();
$maND = Auth::clientId();

$hoTen = '';
$tranDangCo = null;
$tong = ['SoTran' => 0, 'SoThang' => 0, 'SoThua' => 0, 'SoHoa' => 0];
$eloCaoNhat = null;
$tranGanDay = [];
$monUaThich = 0;

if ($maND !== null) {
    $st = $pdo->prepare('SELECT HoTen FROM NGUOIDUNG WHERE MaND = ?');
    $st->execute([$maND]);
    $hoTen = (string) $st->fetchColumn();

    $tranDangCo = GhepDoi::tranDangCo($pdo, $maND);

    // Thành tích gộp mọi môn
    $st = $pdo->prepare(
        'SELECT COALESCE(SUM(SoTran), 0) AS SoTran, COALESCE(SUM(SoThang), 0) AS SoThang,
                COALESCE(SUM(SoThua), 0) AS SoThua, COALESCE(SUM(SoHoa), 0) AS SoHoa
           FROM ELOMONHOC WHERE MaND = ?'
    );
    $st->execute([$maND]);
    $tong = $st->fetch();

    // Môn có Elo cao nhất trong số môn đã đấu
    $st = $pdo->prepare(
        'SELECT m.TenMon, e.DiemElo FROM ELOMONHOC e JOIN MONHOC m ON m.MaMon = e.MaMon
          WHERE e.MaND = ? AND e.SoTran > 0 ORDER BY e.DiemElo DESC, e.SoTran DESC LIMIT 1'
    );
    $st->execute([$maND]);
    $eloCaoNhat = $st->fetch() ?: null;

    // Vài trận gần nhất đã xong hoặc đã hủy
    $st = $pdo->prepare(
        "SELECT t.MaTran, t.TrangThai, t.LyDoKetThuc, t.ThoiGianKetThuc, m.TenMon,
                n.DiemSo, n.KetQua, n.EloTruoc, n.EloSau, u.HoTen AS DoiThu, o.DiemSo AS DiemDoiThu
           FROM NGUOICHOITRANDAU n
           JOIN TRANDAU t ON t.MaTran = n.MaTran
           JOIN MONHOC m ON m.MaMon = t.MaMon
           LEFT JOIN NGUOICHOITRANDAU o ON o.MaTran = n.MaTran AND o.MaND <> n.MaND
           LEFT JOIN NGUOIDUNG u ON u.MaND = o.MaND
          WHERE n.MaND = ? AND t.TrangThai IN ('Kết thúc', 'Hủy')
          ORDER BY t.MaTran DESC LIMIT 5"
    );
    $st->execute([$maND]);
    $tranGanDay = $st->fetchAll();

    // Môn người này đấu nhiều nhất, dùng cho BXH rút gọn
    $st = $pdo->prepare('SELECT MaMon FROM ELOMONHOC WHERE MaND = ? AND SoTran > 0 ORDER BY SoTran DESC, MaMon LIMIT 1');
    $st->execute([$maND]);
    $monUaThich = (int) $st->fetchColumn();
}

// BXH rút gọn: môn người này đấu nhiều nhất; khách (hoặc chưa đấu) thì lấy môn có nhiều lượt đấu nhất
if ($monUaThich === 0) {
    $monUaThich = (int) $pdo->query(
        "SELECT e.MaMon FROM ELOMONHOC e JOIN MONHOC m ON m.MaMon = e.MaMon
          WHERE m.TrangThai = 'Đang mở' AND e.SoTran > 0
          GROUP BY e.MaMon ORDER BY SUM(e.SoTran) DESC, e.MaMon LIMIT 1"
    )->fetchColumn();
}
$tenMonBxh = '';
$bxh = [];
if ($monUaThich > 0) {
    $st = $pdo->prepare("SELECT TenMon FROM MONHOC WHERE MaMon = ? AND TrangThai = 'Đang mở'");
    $st->execute([$monUaThich]);
    $tenMonBxh = (string) $st->fetchColumn();
    if ($tenMonBxh !== '') {
        $st = $pdo->prepare(
            "SELECT r.* FROM (
                SELECT u.MaND, u.HoTen, u.AnhDaiDien, e.DiemElo, e.SoTran, RANK() OVER (ORDER BY e.DiemElo DESC) AS Hang
                  FROM ELOMONHOC e JOIN NGUOIDUNG u ON u.MaND = e.MaND
                 WHERE e.MaMon = ? AND e.SoTran > 0 AND u.TrangThai = 'Hoạt động'
             ) r ORDER BY r.Hang, r.SoTran DESC, r.MaND LIMIT 5"
        );
        $st->execute([$monUaThich]);
        $bxh = $st->fetchAll();
    }
}

// Top 3 cho bục vinh quang: chỉ lấy người thật trong BXH, chưa đủ 3 người thì để trống
$podium = array_slice($bxh, 0, 3);

// Chuỗi thắng hiện tại của từng người trong Top 3 (đếm các trận thắng liên tiếp gần nhất đã kết thúc)
$stChuoi = $pdo->prepare(
    "SELECT n.KetQua FROM NGUOICHOITRANDAU n JOIN TRANDAU t ON t.MaTran = n.MaTran
      WHERE n.MaND = ? AND t.TrangThai = 'Kết thúc' AND n.KetQua IS NOT NULL
      ORDER BY t.MaTran DESC LIMIT 100"
);
foreach ($podium as $i => $r) {
    $stChuoi->execute([(int) $r['MaND']]);
    $chuoi = 0;
    foreach ($stChuoi->fetchAll(PDO::FETCH_COLUMN) as $kq) {
        if ($kq !== 'Thắng') {
            break;
        }
        $chuoi++;
    }
    $podium[$i]['ChuoiThang'] = $chuoi;
}

// Dải tin nóng: 3 trận gần nhất đã kết thúc có người thắng
$tinNong = $pdo->query(
    "SELECT m.TenMon, uw.HoTen AS Thang, ul.HoTen AS Thua, w.DiemSo AS DiemThang, l.DiemSo AS DiemThua
       FROM TRANDAU t
       JOIN MONHOC m ON m.MaMon = t.MaMon
       JOIN NGUOICHOITRANDAU w ON w.MaTran = t.MaTran AND w.KetQua = 'Thắng'
       JOIN NGUOICHOITRANDAU l ON l.MaTran = t.MaTran AND l.MaND <> w.MaND
       JOIN NGUOIDUNG uw ON uw.MaND = w.MaND
       JOIN NGUOIDUNG ul ON ul.MaND = l.MaND
      WHERE t.TrangThai = 'Kết thúc'
      ORDER BY t.MaTran DESC LIMIT 3"
)->fetchAll();

// Bài đăng mới (chỉ bài đang hiển thị)
$baiMoi = $pdo->query(
    "SELECT b.MaBaiDang, b.TieuDe, b.NoiDung, b.NgayDang, u.HoTen, m.TenMon,
            (SELECT COUNT(*) FROM BINHLUAN c WHERE c.MaBaiDang = b.MaBaiDang AND c.TrangThai = 'Hiển thị') AS SoBinhLuan
       FROM BAIDANG b JOIN NGUOIDUNG u ON u.MaND = b.MaND LEFT JOIN MONHOC m ON m.MaMon = b.MaMon
      WHERE b.TrangThai = 'Hiển thị'
      ORDER BY b.NgayDang DESC, b.MaBaiDang DESC LIMIT 3"
)->fetchAll();

$tyLe = (int) $tong['SoTran'] > 0 ? round($tong['SoThang'] * 100 / $tong['SoTran'], 1) : 0;

// Thống kê nhanh hiển thị trên Hero Banner
$soMonMo = (int) $pdo->query("SELECT COUNT(*) FROM MONHOC WHERE TrangThai = 'Đang mở'")->fetchColumn();
$soCauHoi = (int) $pdo->query("SELECT COUNT(*) FROM CAUHOI c JOIN MONHOC m ON m.MaMon = c.MaMon WHERE c.TrangThai = 'Hiển thị' AND m.TrangThai = 'Đang mở'")->fetchColumn();
$soTuyenThu = (int) $pdo->query("SELECT COUNT(*) FROM NGUOIDUNG WHERE TrangThai = 'Hoạt động'")->fetchColumn();

$linkThiDau = $tranDangCo !== null
    ? BASE_URL . '/client/phong.php?tran=' . (int) $tranDangCo
    : BASE_URL . '/client/timtran.php';
$nhanThiDau = $tranDangCo !== null ? 'Vào phòng thi đấu' : 'Bắt đầu thi đấu';

$tieuDe = 'Trang chủ';
$cssTrang = 'index';
require __DIR__ . '/layout/header.php';
?>

<!-- ==================== HERO BANNER CAROUSEL ==================== -->
<section class="card hero-arena" id="hero-arena" aria-label="Giới thiệu QUIZUP ARENA">
  <div class="hero-tia-sang" aria-hidden="true"></div>
  <div class="hero-luoi-nen" aria-hidden="true"></div>

  <button type="button" class="hero-mui-ten trai" data-hero-prev aria-label="Slide trước">
    <svg viewBox="0 0 24 24" width="20" height="20"><path d="M15 6l-6 6 6 6" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
  </button>
  <button type="button" class="hero-mui-ten phai" data-hero-next aria-label="Slide tiếp">
    <svg viewBox="0 0 24 24" width="20" height="20"><path d="M9 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
  </button>

  <div class="hero-khung">
    <!-- Cột trái: Tiêu đề & CTA theo từng slide -->
    <div class="hero-noi-dung">
      <div class="hero-slides-stack">
        <!-- Slide 1 -->
        <div class="hero-slide dang-hien" data-slide="0">
          <div class="hero-chao">
            <span class="cham-live" aria-hidden="true"></span>
            <?php if ($maND !== null): ?>
              Xin chào, <strong><?= e($hoTen) ?></strong>!
            <?php else: ?>
              SÂN CHƠI TRÍ TUỆ TRỰC TUYẾN
            <?php endif; ?>
          </div>
          <h1 class="hero-tieu-de">
            <span class="dong-1">QUIZUP</span>
            <span class="dong-2" data-text="ARENA">ARENA</span>
          </h1>
          <div class="hero-vong-cung" aria-hidden="true"></div>
          <?php if ($tranDangCo !== null): ?>
            <p class="hero-phu canh-bao">⚡ Bạn đang có một trận đấu chưa kết thúc &ndash; quay lại ngay!</p>
          <?php else: ?>
            <p class="hero-phu">Thử thách tri thức &ndash; Chinh phục bảng xếp hạng!</p>
          <?php endif; ?>
          <div class="hero-nut-nhom">
            <a class="hero-btn-chinh" href="<?= e($linkThiDau) ?>">
              <span class="icon-play">▶</span> <?= e($nhanThiDau) ?>
            </a>
            <a class="hero-link-phu" href="<?= e(BASE_URL) ?>/client/bxh.php">Tìm hiểu thêm <span>&rarr;</span></a>
          </div>
        </div>

        <!-- Slide 2 -->
        <div class="hero-slide" data-slide="1" aria-hidden="true">
          <div class="hero-chao">
            <span class="cham-live" aria-hidden="true"></span>
            ĐẤU TRƯỜNG 1 VS 1 THỜI GIAN THỰC
          </div>
          <h2 class="hero-tieu-de">
            <span class="dong-1">SO TÀI</span>
            <span class="dong-2" data-text="TRÍ TUỆ">TRÍ TUỆ</span>
          </h2>
          <div class="hero-vong-cung" aria-hidden="true"></div>
          <p class="hero-phu">Ghép đôi công bằng theo điểm Elo từng môn học &ndash; 10 câu hỏi kịch tính!</p>
          <div class="hero-nut-nhom">
            <a class="hero-btn-chinh" href="<?= e(BASE_URL) ?>/client/timtran.php">
              <span class="icon-play">▶</span> Tìm trận ngay
            </a>
            <a class="hero-link-phu" href="<?= e(BASE_URL) ?>/client/bxh.php">Xem xếp hạng <span>&rarr;</span></a>
          </div>
        </div>

        <!-- Slide 3 -->
        <div class="hero-slide" data-slide="2" aria-hidden="true">
          <div class="hero-chao">
            <span class="cham-live" aria-hidden="true"></span>
            CỘNG ĐỒNG HỌC THUẬT SINH VIÊN
          </div>
          <h2 class="hero-tieu-de">
            <span class="dong-1">KẾT NỐI</span>
            <span class="dong-2" data-text="BẠN BÈ">BẠN BÈ</span>
          </h2>
          <div class="hero-vong-cung" aria-hidden="true"></div>
          <p class="hero-phu">Chia sẻ kinh nghiệm ôn tập, thảo luận câu hỏi khó cùng sinh viên toàn trường!</p>
          <div class="hero-nut-nhom">
            <a class="hero-btn-chinh" href="<?= e(BASE_URL) ?>/client/congdong.php">
              <span class="icon-play">▶</span> Vào cộng đồng
            </a>
            <a class="hero-link-phu" href="<?= e(BASE_URL) ?>/client/banbe.php">Danh sách bạn bè <span>&rarr;</span></a>
          </div>
        </div>
      </div>

      <!-- Thanh chỉ số nhanh trên Banner -->
      <div class="hero-thong-ke" aria-label="Thông số đấu trường">
        <div class="hero-chip">
          <span class="chip-icon">
            <svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true"><polygon points="10,2 17,6 17,14 10,18 3,14 3,6" fill="none" stroke="#D4AF37" stroke-width="1.8"/><path d="M10 6v8M6 8l4 2 4-2" stroke="#F3DE8A" stroke-width="1.5" stroke-linecap="round"/><circle cx="10" cy="10" r="1.5" fill="#FFF8E7"/></svg>
          </span>
          <div><strong><?= $soMonMo ?></strong> <span>Môn mở</span></div>
        </div>
        <div class="hero-chip">
          <span class="chip-icon">
            <svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true"><polygon points="11,2 4,11 10,11 9,18 16,9 10,9" fill="#F3DE8A" stroke="#AA820A" stroke-width="1.3" stroke-linejoin="round"/></svg>
          </span>
          <div><strong><?= number_format($soCauHoi) ?></strong> <span>Câu hỏi</span></div>
        </div>
        <div class="hero-chip">
          <span class="chip-icon">
            <svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true"><path d="M10 2l7 3.5v5c0 4.2-3 7.3-7 8.5-4-1.2-7-4.3-7-8.5v-5L10 2z" fill="none" stroke="#D4AF37" stroke-width="1.8"/><circle cx="10" cy="8.5" r="2.5" fill="#38BDF8"/><path d="M6 14.5c1-1.8 2.2-2.5 4-2.5s3 0.7 4 2.5" stroke="#F3DE8A" stroke-width="1.6" stroke-linecap="round"/></svg>
          </span>
          <div><strong><?= $soTuyenThu ?></strong> <span>Tuyển thủ</span></div>
        </div>
      </div>
    </div>

    <!-- Cột phải: Khung Hologram Linh Vật Thi Cử Tương Tác Siêu Cấp (Đổi Pet & Mood khi chạm) -->
    <div class="hero-minh-hoa" aria-hidden="true">
      <div class="hero-san-khau hero-faker-stage" id="faker-stage">
        <!-- Vầng hào quang vô địch phát sáng phía sau -->
        <div class="hero-faker-aura" aria-hidden="true"></div>

        <!-- Bệ ánh sáng chân đế -->
        <div class="hero-faker-pedestal" aria-hidden="true"></div>

        <!-- Bong bóng hội thoại tương tác của Linh vật -->
        <div class="faker-speech-bubble" id="faker-bubble" role="status" aria-live="polite">
          <span class="bubble-icon" id="pet-bubble-icon">✌️</span>
          <span class="bubble-text" id="faker-quote">&ldquo;Đề thi này dễ, 10 điểm trong tầm tay! Tự tin lên người anh em! ✌️🐶&rdquo;</span>
          <span class="bubble-tail" aria-hidden="true"></span>
        </div>

        <!-- Huy hiệu Easter Egg khi click -->
        <div class="faker-toast-badge" id="faker-toast">+100 ELO TỰ TIN! 🌟</div>

        <!-- Các huy hiệu HUD danh giá nổi 3D đa tầng trong không gian -->
        <div class="hud-badge hud-1">
          <span class="cham-live"></span>
          <svg viewBox="0 0 16 16" width="13" height="13" aria-hidden="true"><path d="M2 3l4 4-4 4M14 3l-4 4 4 4" stroke="#00F5A0" stroke-width="1.6" fill="none" stroke-linecap="round"/></svg>
          1 vs 1 Trực Tiếp
        </div>
        <div class="hud-badge hud-2">
          <svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true"><polygon points="8,1 10,6 15,6.5 11,10 12.5,15 8,12 3.5,15 5,10 1,6.5 6,6" fill="#FFD166" stroke="#B45309" stroke-width="0.8"/></svg>
          +25 ELO Xếp Hạng
        </div>
        <div class="hud-badge hud-3">
          <svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true"><circle cx="8" cy="8.5" r="5.5" fill="none" stroke="#00D9FF" stroke-width="1.5"/><polyline points="8,5.5 8,8.5 10.5,8.5" stroke="#FFF" stroke-width="1.4" stroke-linecap="round"/><rect x="7" y="1.2" width="2" height="1.6" rx="0.5" fill="#00D9FF"/></svg>
          15s / Thử Thách
        </div>

        <!-- KHUNG TRANH HOLOGRAPHIC LINH VẬT THI CỬ (Sang trọng, viền neon, góc dát vàng) -->
        <div class="hero-pet-card" id="faker-character" title="Chạm để đổi linh vật &amp; lời thoại hài hước!">
          <div class="pet-card-glass">
            <!-- 4 góc dát vàng phong cách Cyber Esports -->
            <span class="pet-corner tl" aria-hidden="true"></span>
            <span class="pet-corner tr" aria-hidden="true"></span>
            <span class="pet-corner bl" aria-hidden="true"></span>
            <span class="pet-corner br" aria-hidden="true"></span>

            <!-- Thanh trạng thái trên đỉnh khung -->
            <div class="pet-card-header">
              <div class="pet-status-pill">
                <span class="cham-live"></span>
                <span id="pet-title-tag">TỰ TIN 100%</span>
              </div>
              <div class="pet-dots-bar" id="pet-dots" aria-label="Thứ tự linh vật">
                <span class="pet-dot active" data-pet-idx="0" title="1. Cún Tự Tin"></span>
                <span class="pet-dot" data-pet-idx="1" title="2. Cún Chạy Deadline"></span>
                <span class="pet-dot" data-pet-idx="2" title="3. Mèo Quá Tải"></span>
                <span class="pet-dot" data-pet-idx="3" title="4. Vịt Trầm Cảm"></span>
                <span class="pet-dot" data-pet-idx="4" title="5. Gấu Tâm Tịnh"></span>
                <span class="pet-dot" data-pet-idx="5" title="6. Chó Phán Xét"></span>
                <span class="pet-dot" data-pet-idx="6" title="7. Khỉ Học Bá"></span>
                <span class="pet-dot" data-pet-idx="7" title="8. Khỉ Thư Thái"></span>
                <span class="pet-dot" data-pet-idx="8" title="9. Mèo Siêu Ngầu"></span>
                <span class="pet-dot" data-pet-idx="9" title="10. Chuột Ninja"></span>
              </div>
            </div>

            <!-- Khung ảnh chính -->
            <div class="pet-photo-wrapper">
              <img id="pet-image" src="<?= e(BASE_URL) ?>/assets/images/pets/pet-1-peace.jpg" alt="Linh vật thi cử vui nhộn" class="pet-photo" width="340" height="420" loading="eager">
              <div class="pet-photo-shine" aria-hidden="true"></div>
              <div class="pet-mood-overlay" id="pet-mood-tag">✌️ Cún Tự Tin: 10 Điểm Về Tay</div>
            </div>

            <!-- Chân khung: Gợi ý chạm & Đếm số linh vật -->
            <div class="pet-card-footer">
              <span class="pet-hint-txt">⚡ Chạm khung đổi Pet &amp; Mood (<span id="pet-num-indicator">1</span>/10)</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Thanh điều khiển dưới cùng (hiển thị đẹp trên cả Desktop, Tablet và Mobile) -->
  <div class="hero-dieu-khien">
    <button type="button" class="hero-nut-nho" data-hero-prev aria-label="Slide trước">‹</button>
    <div class="hero-dots" role="tablist" aria-label="Chọn slide">
      <button type="button" class="dot dang-chon" data-hero-dot="0" aria-label="Slide 1"><span class="dot-tien-do"></span></button>
      <button type="button" class="dot" data-hero-dot="1" aria-label="Slide 2"><span class="dot-tien-do"></span></button>
      <button type="button" class="dot" data-hero-dot="2" aria-label="Slide 3"><span class="dot-tien-do"></span></button>
    </div>
    <button type="button" class="hero-nut-nho" data-hero-next aria-label="Slide tiếp">›</button>
  </div>
</section>

<!-- ==================== KHỐI 2 CỘT: BÁO ĐẤU TRƯỜNG GEN Z + TAM ĐẠI THẦN BÀI 3D ==================== -->
<div class="arena-hai-cot">
  <!-- Cột trái: Báo Đấu Trường Gen Z (Daily Arena Meme Chronicles) -->
  <section class="card arena-panel genz-board">
    <header class="panel-dau">
      <h3 class="panel-tieu-de">
        <span class="panel-icon newspaper-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M4 6h16M4 10h16M4 14h10M4 18h7" stroke="#F3DE8A" stroke-width="1.8" stroke-linecap="round"/><rect x="2" y="3" width="20" height="18" rx="3" fill="none" stroke="currentColor" stroke-width="1.6"/><circle cx="17.5" cy="15.5" r="2.5" fill="var(--cyan)"/></svg>
        </span>
        Báo Đấu Trường Gen Z
      </h3>
      <a class="panel-xem-them" href="<?= e(BASE_URL) ?>/client/congdong.php">Xem tất cả <span>&rarr;</span></a>
    </header>

    <!-- Dải Ticker Hóng Biến -->
    <div class="arena-ticker ticker-genz" aria-label="Điểm tin hóng biến">
      <span class="ticker-pill pill-genz"><span class="cham-live yellow"></span> 🔥 HÓNG BIẾN</span>
      <div class="ticker-track">
        <div class="ticker-text">
          <?php if (!$tinNong): ?>
            <span>🏆 <strong>Chưa có trận nào kết thúc:</strong> Hãy là người đầu tiên ghi tên lên bảng vàng!</span>
          <?php else: ?>
            <?php foreach ($tinNong as $i => $t): ?>
              <?php if ($i > 0): ?><span class="ticker-dot">•</span><?php endif; ?>
              <span>🔥 <strong><?= e($t['Thang']) ?></strong> thắng <?= e($t['Thua']) ?> <?= (int) $t['DiemThang'] ?>–<?= (int) $t['DiemThua'] ?> môn <?= e($t['TenMon']) ?></span>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="tin-danh-sach genz-list">
      <?php if (!$baiMoi): ?>
        <p class="tin-mo-ta">Chưa có bài đăng nào. <a href="<?= e(BASE_URL) ?>/client/congdong.php">Vào cộng đồng để đăng bài đầu tiên</a>.</p>
      <?php else: ?>
        <?php 
          $petAvatars = [
            'assets/images/pets/pet-2-rush.jpg',
            'assets/images/pets/pet-3-tired.jpg',
            'assets/images/pets/pet-7-nerd.jpg',
            'assets/images/pets/pet-8-chill.jpg',
          ];
        ?>
        <?php foreach ($baiMoi as $idx => $b): 
          $petThumb = $petAvatars[$idx % count($petAvatars)];
        ?>
          <article class="tin-muc genz-card" data-post-id="<?= (int) $b['MaBaiDang'] ?>">
            <a class="tin-anh genz-thumb-link" href="<?= e(BASE_URL) ?>/client/baidang.php?id=<?= (int) $b['MaBaiDang'] ?>">
              <div class="genz-thumb-wrap">
                <img src="<?= e(BASE_URL . '/' . $petThumb) ?>" alt="Meme Pet" class="genz-thumb-img">
                <span class="genz-badge-tag <?= $idx === 0 ? 'hot' : 'viral' ?>"><?= $idx === 0 ? '🔥 HOT TREND' : '⚡ HÓNG BIẾN' ?></span>
              </div>
            </a>
            <div class="tin-noi-dung">
              <h4 class="tin-tieu-de">
                <a href="<?= e(BASE_URL) ?>/client/baidang.php?id=<?= (int) $b['MaBaiDang'] ?>"><?= e($b['TieuDe']) ?></a>
              </h4>
              <p class="tin-mo-ta"><?= e(mb_strimwidth($b['NoiDung'], 0, 135, '…')) ?></p>
              <div class="tin-meta genz-meta">
                <span class="meta-ngay">
                  <svg class="meta-svg" viewBox="0 0 16 16" width="13" height="13" aria-hidden="true"><rect x="2" y="3" width="12" height="11" rx="2" fill="none" stroke="currentColor" stroke-width="1.4"/><line x1="2" y1="6.5" x2="14" y2="6.5" stroke="currentColor" stroke-width="1.4"/><line x1="5" y1="1.5" x2="5" y2="3.5" stroke="var(--cyan)" stroke-width="1.6" stroke-linecap="round"/><line x1="11" y1="1.5" x2="11" y2="3.5" stroke="var(--cyan)" stroke-width="1.6" stroke-linecap="round"/></svg>
                  <?= e(CongDongHelper::ngay($b['NgayDang'])) ?>
                </span>
                <span>&middot; <?= e($b['HoTen']) ?></span>
                <?php if ($b['TenMon']): ?>
                  <span class="tin-mon"><?= e($b['TenMon']) ?></span>
                <?php endif; ?>
                <a class="tin-link-doc" href="<?= e(BASE_URL) ?>/client/baidang.php?id=<?= (int) $b['MaBaiDang'] ?>">Đọc tiếp &rarr;</a>
              </div>

              <!-- Số bình luận thật từ bảng BINHLUAN -->
              <div class="genz-reactions">
                <a class="react-btn" href="<?= e(BASE_URL) ?>/client/baidang.php?id=<?= (int) $b['MaBaiDang'] ?>">
                  <span class="r-icon">💬</span> <span class="r-count"><?= (int) $b['SoBinhLuan'] ?></span> <span class="r-label">Bình luận</span>
                </a>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </section>

  <!-- Cột phải: Tam Đại Thần Bài Top 3 (Challenger Cards 3D) -->
  <section class="card arena-panel challenger-panel">
    <header class="panel-dau">
      <h3 class="panel-tieu-de">
        <span class="panel-icon card-deck-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><rect x="3" y="5" width="13" height="16" rx="2" fill="none" stroke="#FFD166" stroke-width="1.8" transform="rotate(-6 3 5)"/><rect x="8" y="3" width="13" height="16" rx="2" fill="none" stroke="#00D9FF" stroke-width="1.8"/></svg>
        </span>
        Tam Đại Chiến Thần Top 3<?= $tenMonBxh !== '' ? ' <small class="bxh-sub">(' . e($tenMonBxh) . ')</small>' : '' ?>
      </h3>
      <a class="panel-xem-them" href="<?= e(BASE_URL) ?>/client/bxh.php<?= $monUaThich > 0 ? '?mon=' . (int) $monUaThich : '' ?>">Xem BXH <span>&rarr;</span></a>
    </header>

    <?php
      // 3 Thẻ Bài Thách Đấu Xếp Rẻ Quạt 3D
      // Thứ tự trên sân khấu: Thẻ 2 (trái, xoay -7deg), Thẻ 1 (giữa, thẳng đứng, to nhất), Thẻ 3 (phải, xoay +7deg)
      $theChienThan = [
          [
              'idx' => 1,
              'hang' => 2,
              'lop' => 'card-pos-left rank-diamond',
              'rankName' => 'RANK 2 • BẬC THẦY',
              'foil' => 'foil-ice',
              'crown' => '🥈',
              'icon' => '💎'
          ],
          [
              'idx' => 0,
              'hang' => 1,
              'lop' => 'card-pos-center rank-challenger',
              'rankName' => '👑 TOP 1 • THÁCH ĐẤU',
              'foil' => 'foil-gold',
              'crown' => '👑',
              'icon' => '⭐'
          ],
          [
              'idx' => 2,
              'hang' => 3,
              'lop' => 'card-pos-right rank-master',
              'rankName' => 'RANK 3 • CHIẾN TƯỚNG',
              'foil' => 'foil-amethyst',
              'crown' => '🥉',
              'icon' => '🔮'
          ],
      ];
    ?>

    <div class="challenger-deck-stage" id="deck-stage" title="Click vào Thẻ Bài để khiêu chiến!">
      <!-- Tia sáng hào quang huyền ảo phía sau bộ thẻ -->
      <div class="deck-aurora" aria-hidden="true"></div>

      <div class="deck-cards-row">
        <?php foreach ($theChienThan as $tc): 
          $ng = $podium[$tc['idx']] ?? null;
          $ten = $ng ? (string) $ng['HoTen'] : 'Chưa có';
          $elo = $ng ? (int) $ng['DiemElo'] : null;
          $chuoiThang = $ng ? (int) $ng['ChuoiThang'] : 0;
          $laToi = $ng && $maND !== null && (int) $ng['MaND'] === $maND;
          $hang = (int) $tc['hang'];
          $tu = preg_split('/\s+/u', trim($ten)) ?: ['?'];
          $chuCai = mb_strtoupper(mb_substr((string) end($tu), 0, 1));
        ?>
          <div class="tc-card <?= e($tc['lop']) ?><?= $laToi ? ' la-toi-card' : '' ?>" data-card-hang="<?= $hang ?>">
            <!-- Lớp phản quang Hologram Foil khi nghiêng -->
            <div class="tc-foil <?= e($tc['foil']) ?>" aria-hidden="true"></div>

            <!-- Huy hiệu Rank đỉnh thẻ -->
            <div class="tc-top-badge">
              <span class="tc-crown-mini"><?= $tc['crown'] ?></span>
              <span class="tc-rank-title"><?= e($tc['rankName']) ?></span>
            </div>

            <!-- Khung ảnh đại diện chiến thần -->
            <div class="tc-avatar-wrapper">
              <div class="tc-avatar-glow" aria-hidden="true"></div>
              <?php if (!empty($ng['AnhDaiDien'])): ?>
                <img class="tc-avatar-img" src="<?= e(BASE_URL . '/' . ltrim((string) $ng['AnhDaiDien'], '/')) ?>" alt="<?= e($ten) ?>">
              <?php else: ?>
                <div class="tc-avatar-char"><?= e($chuCai) ?></div>
              <?php endif; ?>
              <?php if ($laToi): ?>
                <span class="tc-you-tag">⭐ BẠN</span>
              <?php endif; ?>
            </div>

            <!-- Tên tuyển thủ -->
            <div class="tc-player-name" title="<?= e($ten) ?>"><?= e($ten) ?></div>

            <!-- Chip Elo dập nổi -->
            <div class="tc-elo-pill">
              <span class="tc-elo-gem"><?= $tc['icon'] ?></span>
              <strong class="tc-elo-num"><?= $elo !== null ? number_format($elo) : '—' ?></strong> <small>ELO</small>
            </div>

            <!-- Chỉ số chiến đấu (Combat Stats) -->
            <div class="tc-stats-badge">
              <span class="tc-stat-val"><?= $ng ? ($chuoiThang > 0 ? '🔥 Chuỗi ' . $chuoiThang . ' thắng' : (int) $ng['SoTran'] . ' trận đã đấu') : 'Đang chờ chiến thần' ?></span>
            </div>

            <!-- Nút Thách Đấu / So Tài -->
            <a class="tc-action-btn" href="<?= e(BASE_URL) ?>/client/timtran.php<?= $ng ? '?doithu=' . (int) $ng['MaND'] : '' ?>">
              <span class="tc-btn-icon">⚔️</span>
              <span class="tc-btn-text"><?= $hang === 1 ? 'Thách Đấu' : 'So Tài' ?></span>
            </a>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
</div>



<script>
(function () {
  const hero = document.getElementById('hero-arena');
  if (!hero) return;
  const slides = hero.querySelectorAll('.hero-slide');
  const dots = hero.querySelectorAll('[data-hero-dot]');
  let cur = 0, timer = 0, startX = 0, dragging = false;

  function go(idx) {
    cur = (idx + slides.length) % slides.length;
    slides.forEach((s, i) => {
      const on = i === cur;
      s.classList.toggle('dang-hien', on);
      s.setAttribute('aria-hidden', on ? 'false' : 'true');
    });
    dots.forEach((d, i) => {
      d.classList.remove('dang-chon');
      if (i === cur) {
        void d.offsetWidth; // khởi động lại thanh tiến độ
        d.classList.add('dang-chon');
      }
    });
  }
  function start() {
    stop();
    timer = setInterval(() => go(cur + 1), 6000);
  }
  function stop() {
    if (timer) { clearInterval(timer); timer = 0; }
  }

  hero.querySelectorAll('[data-hero-prev]').forEach((b) => b.addEventListener('click', () => { go(cur - 1); start(); }));
  hero.querySelectorAll('[data-hero-next]').forEach((b) => b.addEventListener('click', () => { go(cur + 1); start(); }));
  dots.forEach((d) => d.addEventListener('click', () => { go(Number(d.dataset.heroDot || 0)); start(); }));

  // Vuốt cảm ứng (Touch / Pointer swipe) cho điện thoại & máy tính bảng
  hero.addEventListener('pointerdown', (e) => {
    if (e.target.closest('a,button')) return;
    startX = e.clientX;
    dragging = true;
  }, { passive: true });
  hero.addEventListener('pointerup', (e) => {
    if (!dragging) return;
    dragging = false;
    const dx = e.clientX - startX;
    if (Math.abs(dx) > 42) {
      go(dx < 0 ? cur + 1 : cur - 1);
      start();
    }
  }, { passive: true });

  // Hiệu ứng nghiêng 3D & đèn chiếu theo chuột trên máy tính
  hero.addEventListener('pointermove', (e) => {
    if (e.pointerType === 'touch') return;
    const r = hero.getBoundingClientRect();
    const x = (e.clientX - r.left) / r.width;
    const y = (e.clientY - r.top) / r.height;
    hero.style.setProperty('--mx', (x * 100).toFixed(1) + '%');
    hero.style.setProperty('--my', (y * 100).toFixed(1) + '%');
    hero.style.setProperty('--rx', ((0.5 - y) * 10).toFixed(2) + 'deg');
    hero.style.setProperty('--ry', ((x - 0.5) * 12).toFixed(2) + 'deg');
  }, { passive: true });

  hero.addEventListener('mouseenter', () => hero.classList.add('dang-hover'));
  hero.addEventListener('mouseleave', () => {
    hero.classList.remove('dang-hover');
    hero.style.setProperty('--rx', '0deg');
    hero.style.setProperty('--ry', '0deg');
  });
  start();

  // ==================== TƯƠNG TÁC LINH VẬT THI CỬ (5 PETS & VIRAL QUOTES) ====================
  const petCard          = document.getElementById('faker-character');
  const petImage         = document.getElementById('pet-image');
  const petBubble        = document.getElementById('faker-bubble');
  const petQuote         = document.getElementById('faker-quote');
  const petIcon          = document.getElementById('pet-bubble-icon');
  const petToast         = document.getElementById('faker-toast');
  const petStage         = document.getElementById('faker-stage');
  const petTitleTag      = document.getElementById('pet-title-tag');
  const petMoodTag       = document.getElementById('pet-mood-tag');
  const petNumIndicator  = document.getElementById('pet-num-indicator');
  const petDots          = document.querySelectorAll('#pet-dots .pet-dot');

  if (petCard && petImage && petBubble && petQuote) {
    const petData = [
      {
        img: '<?= e(BASE_URL) ?>/assets/images/pets/pet-1-peace.jpg',
        icon: '✌️',
        name: '✌️ Cún Tự Tin: 10 Điểm Về Tay',
        title: 'TỰ TIN 100%',
        quote: 'Đề thi này dễ, 10 điểm trong tầm tay! Tự tin lên người anh em, thi xong tui bao trà sữa full topping! ✌️🐶',
        toast: '+100 ELO TỰ TIN! 🌟',
        soundType: 'fanfare',
        notes: [523.25, 659.25, 783.99, 1046.50]
      },
      {
        img: '<?= e(BASE_URL) ?>/assets/images/pets/pet-2-rush.jpg',
        icon: '🏃',
        name: '🏃 Cún Chạy Deadline: Gấp Gấp!',
        title: 'TỐC ĐỘ BÀN THỜ',
        quote: 'Trễ giờ thi rồi mẹ ơiii! Vừa ngậm bánh mì vừa chạy thục mạng tới phòng thi! Chạy maauuu! 🍞🏃💨',
        toast: 'TỐC BIẾN +50 ELO! ⚡',
        soundType: 'rush',
        notes: [440, 554.37, 659.25, 880, 1108.73]
      },
      {
        img: '<?= e(BASE_URL) ?>/assets/images/pets/pet-3-tired.jpg',
        icon: '🥱',
        name: '🥱 Mèo Quá Tải: Cần 1 Tách Cafe',
        title: 'MỆT MỎI LEVEL MAX',
        quote: 'Mới học có 5 phút mà não đòi "nghỉ giải lao 3 tiếng"... Ủa rồi thi cử kiểu gì đây bé mèo? 💤☕',
        toast: 'NẠP CAFE HỒI SINH! ☕',
        soundType: 'meow',
        notes: [783.99, 659.25, 523.25, 392]
      },
      {
        img: '<?= e(BASE_URL) ?>/assets/images/pets/pet-4-stress.jpg',
        icon: '😱',
        name: '😱 Vịt Trầm Cảm: Đề Này Ai Làm Được',
        title: 'STRESS 999%',
        quote: 'Cứu tui vớiii! Ai đó giải thích giùm tui đống công thức này bằng tiếng Việt được không?! Trầm cảm quớ! 🦆💥',
        toast: 'CHỐNG SỐC TÂM LÝ! 🛡️',
        soundType: 'quack',
        notes: [659.25, 830.61, 659.25, 987.77]
      },
      {
        img: '<?= e(BASE_URL) ?>/assets/images/pets/pet-5-fire.jpg',
        icon: '🔥',
        name: '🔥 Gấu Tâm Tịnh: This Is Fine',
        title: 'BÌNH THẢN VÔ CỰC',
        quote: 'Deadline cháy tới đít nhưng tâm vẫn tịnh! Cứ từ từ bấm chọn đáp án, lửa cháy mặc lửa! 😎🔥',
        toast: 'TÂM BẤT BIẾN GIỮA DÒNG ĐỜI! 🧘',
        soundType: 'fire',
        notes: [523.25, 783.99, 1046.50, 1567.98]
      },
      {
        img: '<?= e(BASE_URL) ?>/assets/images/pets/pet-6-sideeye.jpg',
        icon: '👀',
        name: '👀 Chó Phán Xét: Nghi Ngờ Nhân Sinh',
        title: 'BOMBASTIC SIDE-EYE',
        quote: 'Nói thật đi, nãy giờ bạn toàn khoanh bừa đúng không? Đừng hòng qua mắt được cặp mắt nghiệp chướng này! 🐶👁️',
        toast: 'BẮT BÀI THÀNH CÔNG! 🔍',
        soundType: 'sideeye',
        notes: [349.23, 440, 392, 329.63]
      },
      {
        img: '<?= e(BASE_URL) ?>/assets/images/pets/pet-7-nerd.jpg',
        icon: '☝️',
        name: '☝️ Khỉ Học Bá: Theo Tính Toán Thì...',
        title: 'THỦ KHOA 300 IQ',
        quote: 'Theo tính toán lượng tử thì câu C xác suất đúng là 99.9%! Tin giáo sư Khỉ, không trượt phát nào! 🤓🐒',
        toast: '+200 IQ THỦ KHOA! 🧠',
        soundType: 'nerd',
        notes: [659.25, 880, 1174.66, 1318.51]
      },
      {
        img: '<?= e(BASE_URL) ?>/assets/images/pets/pet-8-chill.jpg',
        icon: '☕',
        name: '☕ Khỉ Thư Thái: Làm Hớp Cà Phê',
        title: 'CHILL VÔ CỰC',
        quote: 'Sáng sớm làm ngụm cà phê nóng cho tỉnh táo, thi thố gì tầm này cứ bình tĩnh mà chill người anh em! ☕🐵✨',
        toast: 'HỒI PHỤC NĂNG LƯỢNG! ☕',
        soundType: 'chill',
        notes: [392, 493.88, 587.33, 783.99]
      },
      {
        img: '<?= e(BASE_URL) ?>/assets/images/pets/pet-9-coolcat.jpg',
        icon: '👍',
        name: '👍 Mèo Siêu Ngầu: Chuẩn Không Cần Chỉnh',
        title: 'VIP PRO CHIẾN THẦN',
        quote: 'Tuyệt vời! Phong độ thế này thì qua môn là chuyện nhỏ, A+ thẳng tiến luôn bạn ơi! Like mạnh! 🕶️🐱👍',
        toast: 'BUFF TỰ TIN LEVEL MAX! 🚀',
        soundType: 'cool',
        notes: [523.25, 659.25, 880, 1046.50, 1318.51]
      },
      {
        img: '<?= e(BASE_URL) ?>/assets/images/pets/pet-10-ninja.jpg',
        icon: '🥷',
        name: '🥷 Chuột Ninja: Đề Nào Cũng Chém',
        title: 'SÁT THỦ ĐỀ THI',
        quote: 'Đề thi hôm nay chỉ có 2 con đường: một là trúng tủ, hai là bị ta chém bay màu! Vào trận so tài ngay! 🥷🐹⚔️',
        toast: 'SÁT THƯƠNG CHÍ MẠNG! 🗡️',
        soundType: 'slash',
        notes: [880, 783.99, 659.25, 1318.51]
      }
    ];

    let currentPetIdx = 0;
    let bubbleTimer = 0;

    // Âm thanh tương tác mô phỏng qua Web Audio API
    function playPetSound(notes, type) {
      try {
        const AudioCtx = window.AudioContext || window.webkitAudioContext;
        if (!AudioCtx) return;
        const ctx = new AudioCtx();
        const wave = type === 'meow' ? 'triangle' : (type === 'quack' ? 'sawtooth' : 'sine');
        notes.forEach((freq, idx) => {
          const osc = ctx.createOscillator();
          const gain = ctx.createGain();
          osc.type = wave;
          const startT = ctx.currentTime + idx * 0.065;
          osc.frequency.setValueAtTime(freq, startT);
          gain.gain.setValueAtTime(0, startT);
          gain.gain.linearRampToValueAtTime(0.16, startT + 0.015);
          gain.gain.exponentialRampToValueAtTime(0.001, startT + 0.28);
          osc.connect(gain);
          gain.connect(ctx.destination);
          osc.start(startT);
          osc.stop(startT + 0.3);
        });
      } catch (e) {}
    }

    // Hiệu ứng tia hạt sáng (Sparks) bùng nổ khi đổi Pet
    function spawnSparks(originX, originY) {
      if (!petStage) return;
      const rect = petStage.getBoundingClientRect();
      const centerX = originX !== undefined ? (originX - rect.left) : (rect.width * 0.5);
      const centerY = originY !== undefined ? (originY - rect.top) : (rect.height * 0.42);

      for (let i = 0; i < 18; i++) {
        const spark = document.createElement('span');
        spark.className = 'faker-spark';
        const angle = (Math.PI * 2 * i) / 18 + (Math.random() - 0.5) * 0.35;
        const dist = 35 + Math.random() * 65;
        spark.style.setProperty('--dx', (Math.cos(angle) * dist).toFixed(1) + 'px');
        spark.style.setProperty('--dy', (Math.sin(angle) * dist).toFixed(1) + 'px');
        spark.style.left = centerX + 'px';
        spark.style.top = centerY + 'px';
        if (i % 2 === 0) {
          spark.style.background = '#00D9FF';
          spark.style.boxShadow = '0 0 10px #00D9FF, 0 0 18px #FFF';
        }
        petStage.appendChild(spark);
        setTimeout(() => spark.remove(), 850);
      }
    }

    // Hiển thị câu thoại
    function showPetQuote(text) {
      petQuote.textContent = text;
      petBubble.classList.add('dang-hien');
      if (bubbleTimer) clearTimeout(bubbleTimer);
      bubbleTimer = setTimeout(() => {
        petBubble.classList.remove('dang-hien');
      }, 5000);
    }

    // Chuyển linh vật
    function switchPet(idx, originX, originY) {
      currentPetIdx = (idx + petData.length) % petData.length;
      const pet = petData[currentPetIdx];

      // 1. Hiệu ứng flash & pop trên khung
      petCard.classList.remove('pet-switching');
      void petCard.offsetWidth;
      petCard.classList.add('pet-switching');
      setTimeout(() => petCard.classList.remove('pet-switching'), 500);

      // 2. Đổi ảnh và nhãn
      petImage.src = pet.img;
      if (petMoodTag) petMoodTag.textContent = pet.name;
      if (petTitleTag) petTitleTag.textContent = pet.title;
      if (petNumIndicator) petNumIndicator.textContent = currentPetIdx + 1;
      if (petIcon) petIcon.textContent = pet.icon;

      // 3. Đổi trạng thái dots
      petDots.forEach((dot, i) => {
        dot.classList.toggle('active', i === currentPetIdx);
      });

      // 4. Âm thanh tương ứng
      playPetSound(pet.notes, pet.soundType);

      // 5. Bắn hạt sáng
      spawnSparks(originX, originY);

      // 6. Bắn Toast Badge
      if (petToast) {
        petToast.textContent = pet.toast;
        petToast.classList.remove('dang-bay');
        void petToast.offsetWidth;
        petToast.classList.add('dang-bay');
        setTimeout(() => petToast.classList.remove('dang-bay'), 1700);
      }

      // 7. Hiển thị câu thoại hài hước
      showPetQuote(pet.quote);
    }

    // Chạm vào khung ảnh -> chuyển sang linh vật kế tiếp
    petCard.addEventListener('click', function (e) {
      switchPet(currentPetIdx + 1, e.clientX, e.clientY);
    });

    // Bấm trực tiếp vào các chấm điều hướng
    petDots.forEach((dot) => {
      dot.addEventListener('click', function (e) {
        e.stopPropagation();
        const idx = Number(this.dataset.petIdx || 0);
        switchPet(idx, e.clientX, e.clientY);
      });
    });

    // Tự động chào mừng người dùng 1.2s sau khi vào trang
    setTimeout(() => {
      showPetQuote(petData[0].quote);
    }, 1200);
  }

  // ==================== TƯƠNG TÁC OPTION 2: BÁO GEN Z REACTIONS & THẺ BÀI CHIẾN THẦN ====================
  let soundCtx = null;
  function getAudioCtx() {
    try {
      const AudioCtx = window.AudioContext || window.webkitAudioContext;
      if (!AudioCtx) return null;
      if (!soundCtx) soundCtx = new AudioCtx();
      if (soundCtx.state === 'suspended') soundCtx.resume();
      return soundCtx;
    } catch (e) {
      return null;
    }
  }

  // 1. Âm thanh Bong bóng Pop khi thả cảm xúc
  function playPopSound() {
    const ctx = getAudioCtx();
    if (!ctx) return;
    const now = ctx.currentTime;
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();

    osc.type = 'sine';
    osc.frequency.setValueAtTime(550, now);
    osc.frequency.exponentialRampToValueAtTime(1100, now + 0.09);

    gain.gain.setValueAtTime(0.18, now);
    gain.gain.exponentialRampToValueAtTime(0.001, now + 0.09);

    osc.connect(gain);
    gain.connect(ctx.destination);
    osc.start(now);
    osc.stop(now + 0.09);
  }

  // 2. Âm thanh Kiếm chém Shiiing khi click Thẻ bài
  function playSwordSlashSound() {
    const ctx = getAudioCtx();
    if (!ctx) return;
    const now = ctx.currentTime;

    // Kim loại va chạm (Metallic clang)
    [1320, 2640].forEach((freq, i) => {
      const osc = ctx.createOscillator();
      const gain = ctx.createGain();
      osc.type = 'triangle';
      osc.frequency.setValueAtTime(freq, now + i * 0.02);
      osc.frequency.exponentialRampToValueAtTime(freq * 1.5, now + 0.25);

      gain.gain.setValueAtTime(0.12, now + i * 0.02);
      gain.gain.exponentialRampToValueAtTime(0.001, now + 0.28);

      osc.connect(gain);
      gain.connect(ctx.destination);
      osc.start(now + i * 0.02);
      osc.stop(now + 0.28);
    });
  }

  // Xử lý Click Reactions trên Báo Gen Z
  document.querySelectorAll('.react-btn[data-emoji]').forEach(btn => {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      const countEl = this.querySelector('.r-count');
      const emoji = this.dataset.emoji || '🔥';

      // 1. Tăng số đếm
      if (countEl) {
        let cur = parseInt(countEl.textContent, 10) || 0;
        countEl.textContent = cur + 1;
      }
      this.classList.add('reacted');

      // 2. Âm thanh pop
      playPopSound();

      // 3. Emoji bay vút lên
      const floatEl = document.createElement('span');
      floatEl.className = 'floating-react';
      floatEl.textContent = emoji;
      const dx = (Math.random() * 30 - 15) + 'px';
      floatEl.style.setProperty('--dx', dx);
      floatEl.style.left = (this.offsetWidth / 2 - 10) + 'px';
      floatEl.style.top = '-10px';

      this.appendChild(floatEl);
      setTimeout(() => floatEl.remove(), 900);
    });
  });

  // Xử lý Click Thẻ bài Chiến thần 3D
  const deckStage = document.getElementById('deck-stage');
  if (deckStage) {
    deckStage.querySelectorAll('.tc-card').forEach(card => {
      card.addEventListener('click', function (e) {
        // Nếu không click trực tiếp vào nút link chuyển trang
        if (!e.target.closest('.tc-action-btn')) {
          playSwordSlashSound();

          // Hiệu ứng flash lóe sáng trên thẻ
          card.style.filter = 'brightness(1.35)';
          setTimeout(() => { card.style.filter = ''; }, 250);
        }
      });
    });
  }
})();
</script>

<?php require __DIR__ . '/layout/footer.php'; ?>