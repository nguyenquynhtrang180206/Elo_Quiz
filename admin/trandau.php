<?php
// Danh sách tra cứu trận đấu và theo dõi trận bất thường.
require_once __DIR__ . '/../core/bootstrap.php';
Auth::requireAdmin(BASE_URL . '/admin/dangnhap.php');

$pdo = Database::pdo();
$coCotTao = true;

// Bộ lọc trận đấu.
$q   = trim((string) ($_GET['q'] ?? ''));
$mon = (int) ($_GET['mon'] ?? 0);
$tt  = (string) ($_GET['tt'] ?? '');
$bt  = isset($_GET['bt']) && $_GET['bt'] === '1';
if (!in_array($tt, ['Đã ghép', 'Đang diễn ra', 'Kết thúc', 'Hủy'], true)) {
    $tt = '';
}

$nguongPhut = max(1, Config::int('NGUONG_TRAN_KEO_DAI', 10));
$nguongGiay = $nguongPhut * 60;

// Tiêu chí trận bất thường dùng chung với biểu đồ.
[$dkBatThuong, $thamBatThuong] = TranDauGiamSat::dieuKienBatThuong($coCotTao, $nguongGiay);

$dkNen   = [];   // Điều kiện nền dùng chung cho đếm số trận theo trạng thái.
$thamNen = [];
if ($q !== '') {
    $maTran = ctype_digit(ltrim($q, '#')) ? (int) ltrim($q, '#') : 0;
    $like   = '%' . addcslashes($q, '\\%_') . '%';
    $dkNen[] = '(t.MaTran = ? OR EXISTS (
        SELECT 1 FROM NGUOICHOITRANDAU p JOIN NGUOIDUNG n ON n.MaND = p.MaND
         WHERE p.MaTran = t.MaTran AND (n.HoTen LIKE ? OR n.Email LIKE ?)))';
    array_push($thamNen, $maTran, $like, $like);
}
if ($mon > 0) {
    $dkNen[]   = 't.MaMon = ?';
    $thamNen[] = $mon;
}
$dieuKien = $dkNen;
$tham     = $thamNen;
if ($tt !== '') {
    $dieuKien[] = 't.TrangThai = ?';
    $tham[]     = $tt;
}
if ($bt) {
    $dieuKien[] = $dkBatThuong;
    array_push($tham, ...$thamBatThuong);
}
// 4 thẻ số liệu đầu trang (không theo bộ lọc).
$mocTG = $coCotTao ? 'COALESCE(t.ThoiGianBatDau, t.ThoiGianTao)' : 't.ThoiGianBatDau';
$st = $pdo->prepare(
    "SELECT COUNT(*) AS tong,
            COALESCE(SUM(DATE($mocTG) = CURDATE()), 0) AS hom_nay,
            AVG(CASE WHEN t.TrangThai = 'Kết thúc' AND t.ThoiGianBatDau IS NOT NULL AND t.ThoiGianKetThuc IS NOT NULL
                     THEN TIMESTAMPDIFF(SECOND, t.ThoiGianBatDau, t.ThoiGianKetThuc) END) AS tb_giay,
            COALESCE(SUM($dkBatThuong), 0) AS bat_thuong
       FROM TRANDAU t"
);
$st->execute($thamBatThuong);
$soLieu = $st->fetch();

$where = $dieuKien ? 'WHERE ' . implode(' AND ', $dieuKien) : '';

$cotTao = $coCotTao ? ', TIMESTAMPDIFF(SECOND, t.ThoiGianTao, NOW(3)) AS giay_da_ghep' : ', NULL AS giay_da_ghep';

// Danh sách phân trang (20 dòng/trang).
$st = $pdo->prepare("SELECT COUNT(*) FROM TRANDAU t $where");
$st->execute($tham);
$tongLoc = (int) $st->fetchColumn();
$p = new Paginator($tongLoc, 20);

$st = $pdo->prepare(
    "SELECT t.MaTran, m.TenMon, k.TenLoaiKT, t.TrangThai, t.LyDoKetThuc, t.ThoiGianBatDau, t.ThoiGianKetThuc, t.DotBien,
            TIMESTAMPDIFF(SECOND, t.ThoiGianBatDau, NOW(3)) AS giay_dien_ra,
            TIMESTAMPDIFF(SECOND, t.ThoiGianBatDau, t.ThoiGianKetThuc) AS giay_tong $cotTao
       FROM TRANDAU t
       JOIN MONHOC m ON m.MaMon = t.MaMon
       JOIN LOAIKYTHI k ON k.MaLoaiKT = t.MaLoaiKT
       $where
      ORDER BY t.MaTran DESC
      LIMIT " . $p->limit() . ' OFFSET ' . $p->offset()
);
$st->execute($tham);
$ds = $st->fetchAll();

// Người chơi của các trận trong trang.
$nguoiDs = [];
if ($ds) {
    $maDs = array_map(static fn ($r) => (int) $r['MaTran'], $ds);
    $st = $pdo->prepare(
        'SELECT p.MaTran, n.HoTen, p.DiemSo, p.KetQua, p.TrangThaiThamGia
           FROM NGUOICHOITRANDAU p JOIN NGUOIDUNG n ON n.MaND = p.MaND
          WHERE p.MaTran IN (' . implode(',', array_fill(0, count($maDs), '?')) . ')
          ORDER BY p.MaND'
    );
    $st->execute($maDs);
    foreach ($st->fetchAll() as $r) {
        $nguoiDs[(int) $r['MaTran']][] = $r;
    }
}

// Số trận trên các chip lọc trạng thái.
$demTT = ['Đã ghép' => 0, 'Đang diễn ra' => 0, 'Kết thúc' => 0, 'Hủy' => 0];
$demBt = 0;
$st = $pdo->prepare(
    "SELECT t.TrangThai, COUNT(*) AS n, COALESCE(SUM($dkBatThuong), 0) AS bt
       FROM TRANDAU t " . ($dkNen ? 'WHERE ' . implode(' AND ', $dkNen) : '') . ' GROUP BY t.TrangThai'
);
$st->execute(array_merge($thamBatThuong, $thamNen));
foreach ($st->fetchAll() as $r) {
    $demTT[$r['TrangThai']] = (int) $r['n'];
    $demBt += (int) $r['bt'];
}
$demTat = array_sum($demTT);

// Tạo link giữ nguyên bộ lọc hiện tại.
$lienKet = static function (array $doi) use ($q, $mon, $tt, $bt): string {
    $t = array_merge(['q' => $q, 'mon' => $mon ?: '', 'tt' => $tt, 'bt' => $bt ? '1' : ''], $doi);
    $t = array_filter($t, static fn ($v) => $v !== '' && $v !== null);
    return $t ? '?' . http_build_query($t) : '?';
};
$dangLoc = $q !== '' || $mon > 0 || $tt !== '' || $bt;

$monHoc = $pdo->query('SELECT MaMon, TenMon FROM MONHOC ORDER BY TenMon')->fetchAll();

// Panel trận bất thường (ưu tiên trận đang chạy).
$st = $pdo->prepare(
    "SELECT t.MaTran, m.TenMon, t.TrangThai, t.LyDoKetThuc, t.ThoiGianKetThuc,
            TIMESTAMPDIFF(SECOND, t.ThoiGianBatDau, NOW(3)) AS giay_dien_ra $cotTao
       FROM TRANDAU t
       JOIN MONHOC m ON m.MaMon = t.MaMon
      WHERE $dkBatThuong
      ORDER BY (t.TrangThai IN ('Đã ghép', 'Đang diễn ra')) DESC, t.MaTran DESC
      LIMIT 10"
);
$st->execute($thamBatThuong);
$dsBt = $st->fetchAll();

// Người chơi của các trận bất thường.
$nguoiBt = [];
if ($dsBt) {
    $maBt = array_map(static fn ($r) => (int) $r['MaTran'], $dsBt);
    $st = $pdo->prepare(
        'SELECT p.MaTran, n.HoTen, p.DiemSo, p.TrangThaiThamGia
           FROM NGUOICHOITRANDAU p JOIN NGUOIDUNG n ON n.MaND = p.MaND
          WHERE p.MaTran IN (' . implode(',', array_fill(0, count($maBt), '?')) . ')
          ORDER BY p.MaND'
    );
    $st->execute($maBt);
    foreach ($st->fetchAll() as $r) {
        $nguoiBt[(int) $r['MaTran']][] = $r;
    }
}

$lopTT = ['Đã ghép' => 'tt-daghep', 'Đang diễn ra' => 'tt-dangdau', 'Kết thúc' => 'tt-ketthuc', 'Hủy' => 'tt-huy'];

$tieuDe  = 'Trận đấu';
$mucChon = 'trandau';
$cssTrang = ['trandau', 'trandau_xuat'];
$jsTrang  = ['trandau_bieudo', 'trandau_xuat', 'trandau_loc_mon'];
require __DIR__ . '/layout/header.php';
?>
<div class="td">
  <nav class="td-duongdan" aria-label="Đường dẫn">
    <a href="<?= e(BASE_URL) ?>/admin/index.php" title="Tổng quan" aria-label="Tổng quan"><?= $svg('dashboard', 18) ?></a>
    <span class="td-cach">/</span>
    <span>Trận đấu</span>
  </nav>

  <div class="td-tieude">
    <div>
      <h2>Trận đấu <span class="td-live"><i></i>LIVE</span></h2>
      <p class="nhan-phu">Theo dõi lượng trận theo thời gian và các trận bất thường cần admin xem.</p>
    </div>
    <button type="button" class="td-xuat-btn" id="nutXuatTran" title="Xuất Excel / PDF">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
      Xuất báo cáo
    </button>
  </div>

  <div class="td-so-khung">
    <div class="td-so">
      <b><?= number_format((int) $soLieu['tong'], 0, ',', '.') ?></b>
      <span>Tổng số trận</span>
    </div>
    <div class="td-so">
      <b><?= number_format((int) $soLieu['hom_nay'], 0, ',', '.') ?></b>
      <span>Trận hôm nay</span>
    </div>
    <div class="td-so">
      <b><?= e(TranDauGiamSat::thoiLuong($soLieu['tb_giay'])) ?></b>
      <span>Thời lượng TB</span>
    </div>
    <a class="td-so td-so-bt" href="?bt=1" title="Lọc các trận bất thường">
      <b><?= number_format((int) $soLieu['bat_thuong'], 0, ',', '.') ?></b>
      <span>Trận bất thường</span>
    </a>
  </div>

  <div class="td-hang">
  <!-- Biểu đồ nhịp trận -->
  <section class="td-bd" id="tdBd" data-api="<?= e(BASE_URL) ?>/admin/trandau_nhip.php">
    <div class="td-bd-dau">
      <div>
        <div class="td-bd-nhan" id="tdBdNhan">TỔNG SỐ TRẬN ĐÃ ĐẤU</div>
        <div class="td-bd-hientai"><b id="tdBdHienTai">–</b><span id="tdBdDelta"></span></div>
      </div>
      <div class="td-bd-nut">
        <div class="td-nhom" role="group" aria-label="Khoảng giờ">
          <button type="button" data-khoang="1h">1H</button>
          <button type="button" data-khoang="3h" class="on">3H</button>
          <button type="button" data-khoang="1d">1D</button>
          <button type="button" data-khoang="1w">1W</button>
        </div>
        <div class="td-nhom" role="group" aria-label="Khoảng ngày">
          <button type="button" data-khoang="d7" title="7 ngày gần nhất, mỗi mốc một ngày">7N</button>
          <button type="button" data-khoang="d30" title="30 ngày gần nhất, mỗi mốc một ngày">30N</button>
          <button type="button" data-khoang="d90" title="90 ngày gần nhất, mỗi mốc một ngày">90N</button>
          <button type="button" data-khoang="tuychon" title="Chọn từ ngày đến ngày (tối đa 366 ngày)">Chọn ngày</button>
        </div>
      </div>
    </div>
    <div class="td-bd-ngay" id="tdBdNgay" hidden>
      <label>Từ ngày <input type="date" id="tdBdTu" max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d', strtotime('-6 days')) ?>"></label>
      <label>Đến ngày <input type="date" id="tdBdDen" max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>"></label>
      <button type="button" id="tdBdXem" class="td-nut td-nut-xanh">Xem</button>
      <span class="nhan-phu">Tối đa 366 ngày, mỗi mốc là một ngày.</span>
    </div>
    <div class="td-bd-tk">
      <div><span>Cao nhất</span><b id="tdTkCao">–</b></div>
      <div><span>Thấp nhất</span><b id="tdTkThap">–</b></div>
      <div><span id="tdTkTongNhan">Thêm trong 3 giờ</span><b id="tdTkTong">–</b></div>
      <div><span>Trận bất thường</span><b id="tdTkBt" class="mau-do">–</b></div>
      <div><span>Thời lượng TB</span><b id="tdTkTl">–</b></div>
    </div>
    <div class="td-bd-ve" id="tdBdVe"></div>
  </section>

  <!-- Panel trận bất thường -->
  <aside class="td-bt" aria-label="Trận bất thường">
    <div class="td-bt-dau">
      <h3><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>Trận bất thường</h3>
      <span class="td-bt-dem"><?= (int) $soLieu['bat_thuong'] ?></span>
    </div>
    <p class="td-bt-gt">Trận có vấn đề, admin nên mở ra xem. Gồm: <b>chạy quá <?= $nguongPhut ?> phút</b> mà chưa xong (có thể bị treo), <b>hủy do lỗi hệ thống</b>, hoặc <b>có người bỏ trận</b> nên bị xử thua.</p>

    <?php if (!$dsBt): ?>
      <p class="td-bt-rong">Hiện không có trận bất thường.</p>
    <?php endif; ?>

    <?php foreach ($dsBt as $r):
      $ma      = (int) $r['MaTran'];
      $nhanBt  = TranDauGiamSat::nhan($r, $nguongGiay);
      $dangChay = in_array($r['TrangThai'], ['Đã ghép', 'Đang diễn ra'], true);
      $nguoi   = $nguoiBt[$ma] ?? [];
      $tenNguoi = implode(' vs ', array_map(static fn ($x) => $x['HoTen'], $nguoi));
      $diemChuoi = count($nguoi) === 2 ? $nguoi[0]['DiemSo'] . ':' . $nguoi[1]['DiemSo'] : '';
      $bo = '';
      foreach ($nguoi as $x) { if ($x['TrangThaiThamGia'] === 'Bỏ trận') { $bo = $x['HoTen']; break; } }

      if ($nhanBt === 'Đang diễn ra quá lâu') {
          $lop = 'td-bt-treo'; $tag = 'Chạy quá ' . $nguongPhut . ' phút · có thể bị treo';
          $gio = TranDauGiamSat::thoiLuong($r['giay_dien_ra']);
      } elseif ($nhanBt === 'Đã ghép quá lâu') {
          $lop = 'td-bt-treo'; $tag = 'Đã ghép quá ' . $nguongPhut . ' phút · chưa vào trận';
          $gio = TranDauGiamSat::thoiLuong($r['giay_da_ghep']);
      } elseif ($nhanBt === 'Hủy do lỗi') {
          $lop = 'td-bt-loi'; $tag = 'Hủy do lỗi hệ thống';
          $gio = $r['ThoiGianKetThuc'] ? date('H:i', strtotime($r['ThoiGianKetThuc'])) : '';
      } else {
          $lop = 'td-bt-thua'; $tag = 'Có người bỏ trận (xử thua)';
          $gio = $r['ThoiGianKetThuc'] ? date('H:i', strtotime($r['ThoiGianKetThuc'])) : '';
      }
      $linkCt = e(BASE_URL) . '/admin/trandau_chitiet.php?id=' . $ma;
    ?>
      <article class="td-bt-the <?= $lop ?>">
        <span class="td-bt-tag"><?= e($tag) ?></span>
        <div class="td-bt-dong"><b>#<?= $ma ?> · <?= e($r['TenMon']) ?></b><time><?= e($gio) ?></time></div>
        <?php if ($lop === 'td-bt-loi'): ?>
          <p class="td-bt-nguoi">Hệ thống tự hủy, không ai thắng hay thua.</p>
        <?php elseif ($lop === 'td-bt-thua'): ?>
          <p class="td-bt-nguoi"><?= $bo !== '' ? e($bo) : 'Có người chơi' ?> bỏ giữa chừng nên bị xử thua.</p>
        <?php else: ?>
          <p class="td-bt-nguoi"><?= e($tenNguoi) ?><?= $diemChuoi !== '' ? ' · ' . e($diemChuoi) : '' ?></p>
        <?php endif; ?>

        <div class="td-bt-nut">
          <a class="td-nut td-nut-xanh" href="<?= $linkCt ?>">Xem chi tiết</a>
          <?php if ($dangChay): ?>
            <!-- Hủy trận gửi sang trandau_chitiet.php kèm lý do -->
            <details class="td-huy">
              <summary class="td-nut td-nut-do">Hủy trận</summary>
              <form method="post" action="<?= $linkCt ?>" data-xacnhan="Hủy trận #<?= $ma ?>? Thao tác này không thể hoàn tác.">
                <?= csrf_field() ?>
                <input type="hidden" name="hanh_dong" value="huy">
                <input type="text" name="ly_do" maxlength="300" required placeholder="Lý do hủy (bắt buộc)">
                <button type="submit" class="td-nut td-nut-do td-nut-dac">Xác nhận hủy</button>
              </form>
            </details>
          <?php endif; ?>
        </div>
        <?php if ($dangChay): ?>
          <p class="td-bt-ghichu">Nếu trận đã treo thật, bấm Hủy trận để giải phóng hai người chơi.</p>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>

    <?php if ((int) $soLieu['bat_thuong'] > count($dsBt)): ?>
      <a class="td-bt-tatca" href="?bt=1">Xem tất cả <?= (int) $soLieu['bat_thuong'] ?> trận bất thường</a>
    <?php endif; ?>
  </aside>
  </div>


  <!-- Bộ lọc danh sách -->
  <form method="get" action="" class="td-loc">
    <div class="td-loc-hang">
      <label class="td-tim">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="Mã trận, tên hoặc email người chơi">
      </label>
      <!-- Ô chọn môn có tìm kiếm -->
      <div class="td-mon" id="tdMon" data-gioi-han="5">
        <input type="hidden" name="mon" value="<?= (int) $mon ?>">
        <?php $tenMonChon = 'Mọi môn'; foreach ($monHoc as $m) { if ($mon === (int) $m['MaMon']) { $tenMonChon = $m['TenMon']; break; } } ?>
        <button type="button" class="td-mon-nut" aria-haspopup="listbox" aria-expanded="false" aria-label="Môn học">
          <span class="td-mon-ten"><?= e($tenMonChon) ?></span>
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
        </button>
        <div class="td-mon-hop" hidden>
          <input type="search" class="td-mon-tim" placeholder="Tìm môn học..." autocomplete="off" aria-label="Tìm môn học">
          <ul class="td-mon-ds" role="listbox">
            <li><button type="button" role="option" data-v="0" class="td-mon-cd<?= $mon === 0 ? ' on' : '' ?>">Mọi môn</button></li>
            <?php foreach ($monHoc as $m): ?>
              <li><button type="button" role="option" data-v="<?= (int) $m['MaMon'] ?>" class="td-mon-cd<?= $mon === (int) $m['MaMon'] ? ' on' : '' ?>"><?= e($m['TenMon']) ?></button></li>
            <?php endforeach; ?>
          </ul>
          <p class="td-mon-rong" hidden>Không có môn nào khớp.</p>
          <button type="button" class="td-mon-them" hidden>Xem thêm</button>
        </div>
      </div>
      <?php if ($tt !== ''): ?><input type="hidden" name="tt" value="<?= e($tt) ?>"><?php endif; ?>
      <?php if ($bt): ?><input type="hidden" name="bt" value="1"><?php endif; ?>
      <button type="submit" class="td-nut-tim">Tìm</button>
    </div>

    <div class="td-chips">
      <a class="td-chip<?= ($tt === '' && !$bt) ? ' on' : '' ?>" href="<?= e($lienKet(['tt' => '', 'bt' => ''])) ?>">Tất cả <b><?= number_format($demTat, 0, ',', '.') ?></b></a>
      <?php foreach ($demTT as $ten => $n): ?>
        <a class="td-chip<?= $tt === $ten ? ' on' : '' ?>" href="<?= e($lienKet(['tt' => $tt === $ten ? '' : $ten])) ?>"><?= e($ten) ?> <b><?= number_format($n, 0, ',', '.') ?></b></a>
      <?php endforeach; ?>
      <a class="td-chip td-chip-bt<?= $bt ? ' on' : '' ?>" href="<?= e($lienKet(['bt' => $bt ? '' : '1'])) ?>" title="Hủy do lỗi, có người bị xử thua, hoặc chưa xong quá <?= $nguongPhut ?> phút (ngưỡng NGUONG_TRAN_KEO_DAI)">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
        Bất thường <b><?= number_format($demBt, 0, ',', '.') ?></b>
      </a>
      <?php if ($dangLoc): ?><a class="td-chip-xoa" href="?">Xóa lọc</a><?php endif; ?>
    </div>
  </form>

  <?php if (!$coCotTao): ?>
    <p class="td-luuy">Chưa đo được thời gian chờ của trận "Đã ghép" vì bảng TRANDAU chưa có cột ThoiGianTao (xem sql/trandau_thoigiantao.sql).</p>
  <?php endif; ?>

  <!-- Danh sách trận -->
  <section class="td-ds">
    <div class="td-ds-dau">
      <h3>Danh sách trận</h3>
      <span class="nhan-phu"><?= number_format($tongLoc, 0, ',', '.') ?> trận<?= $dangLoc ? ' khớp bộ lọc' : '' ?></span>
    </div>

    <?php if (!$ds): ?>
      <div class="td-rong">
        <p>Không có trận nào khớp.</p>
        <?php if ($dangLoc): ?><a class="td-xem" href="?">Xóa bộ lọc</a><?php endif; ?>
      </div>
    <?php else: ?>
      <div class="td-bang-cuon">
        <table class="td-bang td-bang-the">
          <thead>
            <tr><th>Trận</th><th>Đối đầu</th><th>Trạng thái</th><th>Thời gian</th><th></th></tr>
          </thead>
          <tbody>
          <?php foreach ($ds as $r):
            $ma    = (int) $r['MaTran'];
            $nhan  = TranDauGiamSat::nhan($r, $nguongGiay);
            $nguoi = $nguoiDs[$ma] ?? [];

            // Thời lượng hoặc thời gian chờ theo trạng thái trận.
            if ($r['TrangThai'] === 'Kết thúc' || $r['TrangThai'] === 'Hủy') {
                $phuGio = $r['giay_tong'] !== null ? 'Kéo dài ' . TranDauGiamSat::thoiLuong($r['giay_tong']) : '';
            } elseif ($r['TrangThai'] === 'Đang diễn ra') {
                $phuGio = 'Đã chạy ' . TranDauGiamSat::thoiLuong($r['giay_dien_ra']);
            } else {
                $phuGio = $r['giay_da_ghep'] !== null ? 'Chờ vào ' . TranDauGiamSat::thoiLuong($r['giay_da_ghep']) : 'Chờ vào trận';
            }
          ?>
            <tr class="<?= $nhan !== null ? 'td-dong-bt' : '' ?>">
              <td class="td-ma">
                <b>#<?= $ma ?></b>
                <span><?= e($r['TenMon']) ?> · <?= e($r['TenLoaiKT']) ?></span>
                <?php
                  $dbK = DotBien::layDotBien($r['DotBien'] ?? 'chuan');
                  if (($dbK['id'] ?? 'chuan') !== 'chuan'): ?>
                  <i class="td-db" style="color:<?= e($dbK['mau']) ?>;background:<?= e($dbK['mau']) ?>1a;border-color:<?= e($dbK['mau']) ?>44;"><?= e($dbK['icon']) ?> <?= e($dbK['ten']) ?></i>
                <?php endif; ?>
              </td>

              <td class="td-c-vs">
                <?php if (count($nguoi) === 2): ?>
                  <div class="td-vs">
                    <?php foreach ($nguoi as $i => $x):
                      $lop = ''; $ket = '';
                      if ($x['TrangThaiThamGia'] === 'Bỏ trận') { $lop = 'td-bo';   $ket = 'Bỏ trận'; }
                      elseif ($x['KetQua'] === 'Thắng')         { $lop = 'td-thang'; $ket = 'Thắng'; }
                      elseif ($x['KetQua'] === 'Thua')          { $lop = 'td-thua'; }
                      elseif ($x['KetQua'] === 'Hòa')           { $lop = 'td-hoa';   $ket = 'Hòa'; }
                      $chuCai = mb_strtoupper(mb_substr(trim((string) (preg_split('/\s+/u', trim($x['HoTen']))[count(preg_split('/\s+/u', trim($x['HoTen']))) - 1] ?? '?')), 0, 1));
                    ?>
                      <?php if ($i === 1): ?><span class="td-diem"><?= (int) $nguoi[0]['DiemSo'] ?> : <?= (int) $nguoi[1]['DiemSo'] ?></span><?php endif; ?>
                      <span class="td-ng <?= $lop ?><?= $i === 1 ? ' td-ng-phai' : '' ?>">
                        <span class="td-ava"><?= e($chuCai !== '' ? $chuCai : '?') ?></span>
                        <span><span class="td-ten" title="<?= e($x['HoTen']) ?>"><?= e($x['HoTen']) ?></span><?php if ($ket !== ''): ?><span class="td-ket"><?= e($ket) ?></span><?php endif; ?></span>
                      </span>
                    <?php endforeach; ?>
                  </div>
                <?php elseif ($nguoi): ?>
                  <?= e(implode(' vs ', array_map(static fn ($x) => $x['HoTen'], $nguoi))) ?>
                <?php else: ?>
                  <span class="nhan-phu">—</span>
                <?php endif; ?>
              </td>

              <td data-label="Trạng thái">
                <span class="dam nhan-trang-thai <?= e($lopTT[$r['TrangThai']] ?? '') ?>"><?= e($r['TrangThai']) ?></span>
                <?php if ($nhan !== null): ?>
                  <span class="td-tt-phu td-tt-co">&#9888; <?= e($nhan) ?></span>
                <?php elseif (!empty($r['LyDoKetThuc'])): ?>
                  <span class="td-tt-phu"><?= e($r['LyDoKetThuc']) ?></span>
                <?php endif; ?>
              </td>

              <td class="td-gio" data-label="Thời gian">
                <?php if ($r['ThoiGianBatDau']): ?>
                  <b><?= e(date('H:i:s', strtotime($r['ThoiGianBatDau']))) ?> <small><?= e(date('d/m/Y', strtotime($r['ThoiGianBatDau']))) ?></small></b>
                <?php else: ?>
                  <b>—</b>
                <?php endif; ?>
                <span><?= e($phuGio) ?></span>
              </td>

              <td class="td-hd"><a class="td-xem" href="<?= e(BASE_URL) ?>/admin/trandau_chitiet.php?id=<?= $ma ?>">Chi tiết</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
    <div class="td-ds-chan"><?= $p->links() ?></div>
  </section>
</div>

<!-- Hộp thoại xuất Excel/PDF -->
<div class="tx-nen" id="hopXuatTran" hidden>
  <form class="tx-hop" id="formXuatTran" method="post" action="<?= e(BASE_URL) ?>/admin/trandau_xuat.php" role="dialog" aria-modal="true" aria-labelledby="txTieuDe">
    <h3 id="txTieuDe">Xuất báo cáo trận đấu</h3>
    <p class="tx-phu">Chọn nội dung, phạm vi và định dạng file cần xuất.</p>
    <?= csrf_field() ?>
    <input type="hidden" name="q" value="<?= e($q) ?>">
    <input type="hidden" name="mon" value="<?= (int) $mon ?>">
    <input type="hidden" name="tt" value="<?= e($tt) ?>">
    <input type="hidden" name="bt" value="<?= $bt ? '1' : '' ?>">

    <div class="tx-chia">
    <div class="tx-trai">
    <div class="tx-luoi">
      <fieldset>
        <legend>Phạm vi</legend>
        <label class="tx-chon"><input type="radio" name="pham_vi" value="loc" checked> Đang lọc (<?= number_format($tongLoc, 0, ',', '.') ?> trận)</label>
        <label class="tx-chon"><input type="radio" name="pham_vi" value="tatca"> Toàn bộ trận (<?= number_format((int) $soLieu['tong'], 0, ',', '.') ?> trận)</label>
      </fieldset>
      <fieldset>
        <legend>Định dạng</legend>
        <label class="tx-chon"><input type="radio" name="dinhdang" value="excel" checked> Excel (.xlsx)</label>
        <label class="tx-chon"><input type="radio" name="dinhdang" value="pdf"> PDF</label>
        <label class="tx-chon"><input type="radio" name="dinhdang" value="ca"> Cả hai (tải liên tiếp 2 file)</label>
      </fieldset>
      <fieldset class="tx-rong">
        <legend>Nội dung</legend>
        <div class="tx-cot">
          <label class="tx-chon"><input type="checkbox" name="noidung[]" value="tongquan" checked> Tổng quan (4 số liệu, số trận theo trạng thái)</label>
          <label class="tx-chon"><input type="checkbox" name="noidung[]" value="bieudo" checked> Trận bắt đầu mỗi ngày (biểu đồ + bảng)</label>
          <label class="tx-chon"><input type="checkbox" name="noidung[]" value="ds" checked> Danh sách trận</label>
          <label class="tx-chon"><input type="checkbox" name="noidung[]" value="batthuong"> Danh sách trận bất thường</label>
        </div>
        <p class="tx-ghichu">Danh sách trận theo phạm vi ở trên; "Trận bắt đầu mỗi ngày" theo khoảng ngày chọn bên dưới.</p>
      </fieldset>
      <fieldset class="tx-rong" id="txBdChon">
        <legend>Trận bắt đầu mỗi ngày: chọn khoảng ngày</legend>
        <div class="tx-cot">
          <label class="tx-chon"><input type="radio" name="bd_khoang" value="7" checked> 7 ngày gần nhất</label>
          <label class="tx-chon"><input type="radio" name="bd_khoang" value="30"> 30 ngày gần nhất</label>
          <label class="tx-chon"><input type="radio" name="bd_khoang" value="90"> 90 ngày gần nhất</label>
          <label class="tx-chon"><input type="radio" name="bd_khoang" value="tuychon"> Chọn ngày</label>
        </div>
        <div class="tx-ngay">
          <label>Từ ngày <input type="date" name="bd_tu" max="<?= date('Y-m-d') ?>"></label>
          <label>Đến ngày <input type="date" name="bd_den" max="<?= date('Y-m-d') ?>"></label>
        </div>
        <p class="tx-ghichu">Đếm số trận bắt đầu trong từng ngày trên toàn bộ trận, không phụ thuộc bộ lọc của trang. Tối đa 366 ngày.</p>
      </fieldset>
      <fieldset class="tx-rong">
        <legend>Cột trong danh sách</legend>
        <div class="tx-cot">
          <?php foreach (['ma' => 'Mã trận', 'mon' => 'Môn học', 'kythi' => 'Kỳ thi', 'nguoi' => 'Người chơi (điểm, kết quả)', 'tt' => 'Trạng thái', 'canhbao' => 'Cảnh báo', 'lydo' => 'Lý do kết thúc', 'bd' => 'Bắt đầu', 'kt' => 'Kết thúc', 'tl' => 'Thời lượng'] as $k => $ten): ?>
            <label class="tx-chon"><input type="checkbox" name="truong[]" value="<?= e($k) ?>" checked> <?= e($ten) ?></label>
          <?php endforeach; ?>
        </div>
      </fieldset>
    </div>
    </div>
    <div class="tx-phai">
      <div class="tx-xem-dau">Xem trước</div>
      <div class="tx-xem" id="txXem"><p class="tx-ghichu">Đang tải...</p></div>
    </div>
    </div>

    <p class="tx-loi" id="txLoi" hidden></p>
    <div class="tx-nut">
      <button type="button" class="tx-huy" id="txHuy">Hủy</button>
      <button type="submit" class="tx-ok" id="txOk">Xuất</button>
    </div>
  </form>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>