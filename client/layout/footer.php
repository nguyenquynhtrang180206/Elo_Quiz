<?php
// =====================================================================
// MEGA HYBRID FOOTER: ESPORTS COMMAND HUB & HIGH-TECH TELEMETRY
// 1. Holographic Telemetry Dashboard (Số liệu máy chủ trực tiếp)
// 2. Esports Battle Gateway Bar (Tìm trận nhanh 1 vs 1)
// 3. 4 Cột Điều Hướng Cao Cấp & Trạm Tiếp Sức Sĩ Tử 3D Mascot
// 4. Bottom Bar Chuyên Nghiệp (Tránh chồng đè nút FX)
// =====================================================================

// Số liệu thật từ database; lỗi truy vấn thì để null và hiển thị "—" (không dùng số giả)
$ftSoTranDau = $ftSoMonHoc = $ftTopElo = $ftChinhXac = null;

try {
    $db = Database::pdo();

    // Trận đã hoàn tất
    $ftSoTranDau = (int) $db->query("SELECT COUNT(*) FROM TRANDAU WHERE TrangThai = 'Kết thúc'")->fetchColumn();

    // Môn đang mở
    $ftSoMonHoc = (int) $db->query("SELECT COUNT(*) FROM MONHOC WHERE TrangThai = 'Đang mở'")->fetchColumn();

    // Elo cao nhất: trong ELOMONHOC, người đã đấu, tài khoản đang hoạt động, môn đang mở
    $maxElo = $db->query(
        "SELECT MAX(e.DiemElo) FROM ELOMONHOC e
           JOIN NGUOIDUNG u ON u.MaND = e.MaND AND u.TrangThai = 'Hoạt động'
           JOIN MONHOC m ON m.MaMon = e.MaMon AND m.TrangThai = 'Đang mở'
          WHERE e.SoTran > 0"
    )->fetchColumn();
    $ftTopElo = $maxElo !== false && $maxElo !== null ? (int) $maxElo : null;

    // Tỉ lệ trả lời đúng: câu "Đã trả lời" chọn đúng đáp án / tổng câu "Đã trả lời", chỉ tính trận đã kết thúc
    $r = $db->query(
        "SELECT COUNT(*) AS Tong, COALESCE(SUM(d.LaDapAnDung = 1), 0) AS Dung
           FROM CHITIETTRALOI c
           JOIN TRANDAU t ON t.MaTran = c.MaTran AND t.TrangThai = 'Kết thúc'
           LEFT JOIN DAPAN d ON d.MaCauHoi = c.MaCauHoi AND d.MaDapAn = c.MaDapAn
          WHERE c.TrangThai = 'Đã trả lời'"
    )->fetch();
    if ($r && (int) $r['Tong'] > 0) {
        $ftChinhXac = round((int) $r['Dung'] * 100 / (int) $r['Tong'], 1);
    }
} catch (Throwable $t) {
    // Giữ nguyên null: trang vẫn hiển thị bình thường
}
?>
</main>

<footer class="site-mega-footer" id="site-footer">
  <!-- Đường phân tách ánh sáng năng lượng trên cùng -->
  <div class="ft-energy-beam" aria-hidden="true"></div>

  <div class="container footer-container">

    <!-- 1. HOLOGRAPHIC TELEMETRY DASHBOARD (Bảng chỉ số server công nghệ cao) -->
    <div class="footer-dashboard-row" aria-label="Bảng chỉ số server trực tiếp">
      <!-- Card 1 -->
      <div class="ft-dash-card">
        <div class="ft-dash-icon-box icon-cyan">
          <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14.5 17.5L3 6V3h3l11.5 11.5M14.5 6.5l3-3 3.5 3.5-3 3M6.5 14.5l-3 3L7 21l3-3"/><path d="M10.5 13.5L3 21M21 3l-7.5 7.5" stroke="#00D9FF" stroke-width="2"/></svg>
        </div>
        <div class="ft-dash-info">
          <div class="ft-dash-top"><span class="ft-dash-tag">LIVE ARENA</span></div>
          <strong class="ft-dash-num"><?= $ftSoTranDau !== null ? number_format($ftSoTranDau) : '—' ?></strong>
          <span class="ft-dash-label">Trận Đã Hoàn Tất</span>
        </div>
      </div>

      <!-- Card 2 -->
      <div class="ft-dash-card">
        <div class="ft-dash-icon-box icon-orange">
          <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="#F59E0B"/><circle cx="12" cy="12" r="5" stroke="#FDE047"/><circle cx="12" cy="12" r="2" fill="#EF4444"/><path d="M12 3v3M12 18v3M3 12h3M18 12h3" stroke="#F59E0B" stroke-width="1.5"/></svg>
        </div>
        <div class="ft-dash-info">
          <div class="ft-dash-top"><span class="ft-dash-tag tag-gold">ĐỘ CHÍNH XÁC</span></div>
          <strong class="ft-dash-num text-gold"><?= $ftChinhXac !== null ? number_format($ftChinhXac, 1) . '%' : '—' ?></strong>
          <span class="ft-dash-label">Tỉ Lệ Trả Lời Chuẩn Xác</span>
        </div>
      </div>

      <!-- Card 3 -->
      <div class="ft-dash-card">
        <div class="ft-dash-icon-box icon-yellow">
          <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 18h14v2H5zM6 18L3 8l5 3 4-7 4 7 5-3-3 10H6z" fill="rgba(255,209,71,0.2)" stroke="#FFD147"/><circle cx="12" cy="12" r="2" fill="#00D9FF"/><circle cx="3" cy="7" r="1.5" fill="#FFE27A"/><circle cx="12" cy="3" r="1.5" fill="#FFE27A"/><circle cx="21" cy="7" r="1.5" fill="#FFE27A"/></svg>
        </div>
        <div class="ft-dash-info">
          <div class="ft-dash-top"><span class="ft-dash-tag tag-challenger">THÁCH ĐẤU</span></div>
          <strong class="ft-dash-num text-challenger"><?= $ftTopElo !== null ? number_format($ftTopElo) . ' ELO' : '—' ?></strong>
          <span class="ft-dash-label">Kỷ Lục Điểm Đấu Thần</span>
        </div>
      </div>

      <!-- Card 4 -->
      <div class="ft-dash-card">
        <div class="ft-dash-icon-box icon-purple">
          <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20M4 4.5A2.5 2.5 0 0 1 6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15zM8 7h8M8 11h6" stroke="#C084FC"/></svg>
        </div>
        <div class="ft-dash-info">
          <div class="ft-dash-top"><span class="ft-dash-tag tag-purple">BẢN ĐỒ TRI THỨC</span></div>
          <strong class="ft-dash-num text-purple"><?= $ftSoMonHoc !== null ? (int) $ftSoMonHoc . ' Bộ Môn' : '—' ?></strong>
          <span class="ft-dash-label">Đang Mở Tranh Tài</span>
        </div>
      </div>
    </div>

    <!-- 2. THÂN CHÂN TRANG: TRẠM CHỈ HUY & ĐIỀU HƯỚNG -->
    <div class="footer-main-grid">
      <!-- Cột 1: Thương hiệu & Thông Tin Liên Hệ -->
      <div class="ft-col ft-brand-col">
        <a class="ft-brand-head" href="<?= e(BASE_URL) ?>/client/index.php" title="QUIZUP ARENA">
          <!-- Huy hiệu hoàng gia chính thức đồng bộ Header -->
          <span class="ft-logo-shield" aria-hidden="true">
            <svg viewBox="0 0 52 52" width="38" height="38" fill="none">
              <polygon points="26,2.5 42.6,9.4 49.5,26 42.6,42.6 26,49.5 9.4,42.6 2.5,26 9.4,9.4" fill="#0A1838" stroke="#D4AF37" stroke-width="1.8" stroke-linejoin="round"/>
              <polygon points="26,6 40,12 46,26 40,40 26,46 12,40 6,26 12,12" fill="none" stroke="#F3DE8A" stroke-width="0.8" stroke-dasharray="3 2" opacity="0.75"/>
              <circle cx="26" cy="26" r="14" fill="#040A18" stroke="#D4AF37" stroke-width="0.8"/>
              <!-- Crown -->
              <path d="M20 18 L22 14.5 L26 16.5 L30 14.5 L32 18 Z" fill="#FDE047" stroke="#B45309" stroke-width="0.6"/>
              <!-- Letter Q Monogram -->
              <circle cx="26" cy="27" r="7" fill="none" stroke="#FCD34D" stroke-width="2.4"/>
              <polygon points="28,29 35,36 37,34 30,27" fill="#FDE047"/>
              <circle cx="26" cy="27" r="1.2" fill="#FFF"/>
            </svg>
          </span>
          <div class="ft-brand-titles">
            <span class="ft-brand-name">QUIZUP <span>ARENA</span></span>
            <span class="ft-brand-tagline">ESPORTS KNOWLEDGE PLATFORM</span>
          </div>
        </a>

        <p class="ft-brand-desc">
          Đấu trường trắc nghiệm trí tuệ 1 vs 1 đỉnh cao theo thời gian thực. Hệ thống ghép cặp tự động, thuật toán Elo công bằng và bảng xếp hạng phân bậc theo từng bộ môn.
        </p>

        <!-- Khối Thông Tin Liên Hệ & Kênh Mạng Xã Hội (Mẫu Hotline / Social) -->
        <div class="ft-contact-box">
          <div class="ft-contact-line">
            <span class="ft-contact-lbl">Phone:</span>
            <a href="tel:0359123821" class="ft-contact-val">0359123821</a>
          </div>
          <div class="ft-contact-line">
            <span class="ft-contact-lbl">Email:</span>
            <a href="mailto:fluentezenglish@gmail.com" class="ft-contact-val">fluentezenglish@gmail.com</a>
          </div>
          <div class="ft-social-links" aria-label="Kênh mạng xã hội">
            <!-- Facebook -->
            <a href="https://facebook.com" target="_blank" rel="noopener noreferrer" class="ft-social-btn ft-fb" title="Facebook">
              <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
            </a>
            <!-- Instagram -->
            <a href="https://instagram.com" target="_blank" rel="noopener noreferrer" class="ft-social-btn ft-insta" title="Instagram">
              <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/></svg>
            </a>
            <!-- Threads -->
            <a href="https://threads.net" target="_blank" rel="noopener noreferrer" class="ft-social-btn ft-threads" title="Threads">
              <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M12.186 24h-.007c-3.581-.024-6.334-1.205-8.184-3.509C2.35 18.44 1.5 15.586 1.472 12.01v-.017c.03-3.579.879-6.43 2.525-8.482C5.845 1.205 8.6.024 12.18 0h.014c2.746.02 5.043.725 6.826 2.098c1.677 1.29 2.858 3.13 3.509 5.467l-2.04.569c-1.104-3.96-3.898-5.984-8.304-6.015c-2.91.022-5.11.936-6.54 2.717C4.307 6.504 3.616 8.914 3.589 12c.027 3.086.718 5.496 2.057 7.164c1.43 1.783 3.631 2.698 6.54 2.717c2.623-.02 4.358-.631 5.8-2.045c1.647-1.613 1.618-3.593 1.09-4.798c-.31-.71-.873-1.3-1.634-1.75c-.192 1.352-.622 2.446-1.284 3.272c-.886 1.102-2.14 1.704-3.73 1.79-1.202.065-2.361-.218-3.259-.801c-1.063-.689-1.685-1.74-1.752-2.964c-.065-1.19.408-2.285 1.33-3.082c.88-.76 2.119-1.207 3.583-1.291a14 14 0 0 1 3.02.142c-.126-.742-.375-1.332-.75-1.757c-.513-.586-1.308-.883-2.359-.89h-.029c-.844 0-1.992.232-2.721 1.32l-1.757-1.18c.98-1.454 2.568-2.256 4.478-2.256h.044c3.194.02 5.097 1.975 5.287 5.388q.163.07.321.142c1.49.7 2.58 1.761 3.154 3.07c.797 1.82.871 4.79-1.548 7.158c-1.85 1.81-4.094 2.628-7.277 2.65Zm1.003-11.69q-.362 0-.739.021c-1.836.103-2.98.946-2.916 2.143c.067 1.256 1.452 1.839 2.784 1.767c1.224-.065 2.818-.543 3.086-3.71a10.5 10.5 0 0 0-2.215-.221"/></svg>
            </a>
          </div>
        </div>
      </div>

      <!-- Cột 2: Sàn Đấu & Elo -->
      <div class="ft-col">
        <h4 class="ft-col-heading">
          <span class="ft-heading-bar"></span>
          Sàn Đấu &amp; Elo
        </h4>
        <ul class="ft-nav-list">
          <li>
            <a class="ft-nav-link" href="<?= e(BASE_URL) ?>/client/timtran.php">
              <span class="ft-link-ico"><svg viewBox="0 0 20 20" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 4l4.5 4.5M16 4l-4.5 4.5M7 7l6 6M13 7l-6 6" stroke="var(--cyan)"/><circle cx="10" cy="10" r="1.5" fill="#FFE27A"/></svg></span>
              <span>Tìm trận 1 vs 1 nhanh</span>
              <span class="ft-link-arrow">&rsaquo;</span>
            </a>
          </li>
          <li>
            <a class="ft-nav-link" href="<?= e(BASE_URL) ?>/client/bxh.php">
              <span class="ft-link-ico"><svg viewBox="0 0 20 20" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M6 3.5h8v4.5a4 4 0 01-8 0V3.5zM6 5.5H3.5a1.5 1.5 0 000 3H6M14 5.5h2.5a1.5 1.5 0 010 3H14M8 13.5h4v3H8zM6 16.5h8" stroke="#FBBF24"/></svg></span>
              <span>Đấu Thần Đài Top 100</span>
              <span class="ft-link-arrow">&rsaquo;</span>
            </a>
          </li>
          <li>
            <a class="ft-nav-link" href="<?= e(BASE_URL) ?>/client/lichsutran.php">
              <span class="ft-link-ico"><svg viewBox="0 0 20 20" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="14" height="13" rx="2" stroke="currentColor"/><path d="M7 8h6M7 12h4" stroke="var(--cyan)"/></svg></span>
              <span>Lịch sử đối đầu &amp; Elo</span>
              <span class="ft-link-arrow">&rsaquo;</span>
            </a>
          </li>
          <li>
            <a class="ft-nav-link" href="<?= e(BASE_URL) ?>/client/timtran.php">
              <span class="ft-link-ico"><svg viewBox="0 0 20 20" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20M4 4.5A2.5 2.5 0 0 1 6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15z" stroke="#C084FC"/></svg></span>
              <span>Danh mục bộ môn thi</span>
              <span class="ft-link-arrow">&rsaquo;</span>
            </a>
          </li>
        </ul>
      </div>

      <!-- Cột 3: Cộng Đồng Gen Z -->
      <div class="ft-col">
        <h4 class="ft-col-heading">
          <span class="ft-heading-bar bar-purple"></span>
          Cộng Đồng Gen Z
        </h4>
        <ul class="ft-nav-list">
          <li>
            <a class="ft-nav-link" href="<?= e(BASE_URL) ?>/client/congdong.php">
              <span class="ft-link-ico"><svg viewBox="0 0 20 20" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2.5" y="3.5" width="15" height="13" rx="2" stroke="currentColor"/><path d="M5.5 7h9M5.5 10.5h6" stroke="#F59E0B"/></svg></span>
              <span>Báo Đấu Trường Gen Z</span>
              <span class="ft-link-badge hot">HOT</span>
              <span class="ft-link-arrow">&rsaquo;</span>
            </a>
          </li>
          <li>
            <a class="ft-nav-link" href="<?= e(BASE_URL) ?>/client/banbe.php">
              <span class="ft-link-ico"><svg viewBox="0 0 20 20" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="10" cy="7" r="3" stroke="currentColor"/><path d="M4 16c.8-2.5 2.5-3.5 6-3.5s5.2 1 6 3.5" stroke="var(--cyan)"/></svg></span>
              <span>Kết bạn &amp; Khiêu chiến</span>
              <span class="ft-link-arrow">&rsaquo;</span>
            </a>
          </li>
          <li>
            <a class="ft-nav-link" href="<?= e(BASE_URL) ?>/client/hoso.php">
              <span class="ft-link-ico"><svg viewBox="0 0 20 20" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8"><polygon points="10,2 17,6 17,14 10,18 3,14 3,6" stroke="#FBBF24"/><circle cx="10" cy="8" r="2" fill="var(--cyan)"/></svg></span>
              <span>Hồ sơ &amp; Bộ sưu tập huy hiệu</span>
              <span class="ft-link-arrow">&rsaquo;</span>
            </a>
          </li>
          <li>
            <a class="ft-nav-link" href="<?= e(BASE_URL) ?>/client/congdong.php">
              <span class="ft-link-ico"><svg viewBox="0 0 20 20" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 3a6 6 0 0 1 6 6c0 2-1 3.5-2 4.5V16H7v-2.5C6 12.5 5 11 5 9a6 6 0 0 1 4-6z" stroke="#38BDF8"/><path d="M8 18h4" stroke="#FFECA1"/></svg></span>
              <span>Đề xuất môn học mới</span>
              <span class="ft-link-arrow">&rsaquo;</span>
            </a>
          </li>
        </ul>
      </div>
    </div>

    <!-- 3. BOTTOM BAR: CREDITS -->
    <div class="footer-bottom-bar">
      <div class="ft-bottom-left">
        <span class="ft-copyright">&copy; <?= date('Y') ?> <strong>QUIZUP ARENA</strong>. Nền tảng thi đấu trắc nghiệm trí tuệ 1 vs 1.</span>
        <span class="ft-dev-credit">☕ Được chế tạo bởi hội sinh viên cú đêm chạy deadline bằng trà sữa.</span>
      </div>
    </div>

  </div>
</footer>

<?php foreach ((array) ($jsTrang ?? []) as $f): ?>
<script src="<?= e(BASE_URL) ?>/assets/js/client/<?= e($f) ?>.js"></script>
<?php endforeach; ?>
</body>
</html>