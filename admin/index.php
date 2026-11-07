<?php
// Dashboard và thống kê (UC10: A-06 đến A-10). Đặt tại: elo_quiz/admin/index.php
require_once __DIR__ . '/../core/bootstrap.php';
$adminId = Auth::requireAdmin(BASE_URL . '/admin/login.php');   // A-04

$pdo = Database::pdo();

// Khoảng thời gian xem (ngày gần nhất): chỉ nhận 7, 30, 90 hoặc 365 (1 năm)
$ngay = (int) ($_GET['ngay'] ?? 7);
if (!in_array($ngay, [7, 30, 90, 365], true)) {
    $ngay = 7;
}
$tuNgay = date('Y-m-d', strtotime('-' . ($ngay - 1) . ' days'));   // ngày đầu của khoảng
$moc    = $tuNgay . ' 00:00:00';

// ---- A-06: tổng số người dùng, môn học, câu hỏi, trận đấu ----
$tong = $pdo->query(
    'SELECT (SELECT COUNT(*) FROM NGUOIDUNG) AS nguoidung,
            (SELECT COUNT(*) FROM MONHOC)    AS monhoc,
            (SELECT COUNT(*) FROM CAUHOI)    AS cauhoi,
            (SELECT COUNT(*) FROM TRANDAU)   AS trandau'
)->fetch();

// ---- A-07: số trận theo trạng thái ----
$theoTrangThai = ['Đã ghép' => 0, 'Đang diễn ra' => 0, 'Kết thúc' => 0, 'Hủy' => 0];
foreach ($pdo->query('SELECT TrangThai, COUNT(*) AS so FROM TRANDAU GROUP BY TrangThai') as $r) {
    $theoTrangThai[$r['TrangThai']] = (int) $r['so'];
}

// ---- A-08 (biểu đồ): gom theo ngày (7, 30 ngày), theo tuần (90 ngày), theo tháng (1 năm) ----
// TRANDAU không có cột ngày tạo nên trận được tính vào ngày bắt đầu;
// trận bị hủy trước khi bắt đầu thì tính vào ngày kết thúc (hủy). Trận mới "Đã ghép" chưa có ngày nên chưa tính.
if ($ngay <= 30) {
    $kieuGop = 'ngay';
} elseif ($ngay <= 90) {
    $kieuGop = 'tuan';
} else {
    $kieuGop = 'thang';
}

$bieuDo = [];   // khóa nhóm => ['nhan', 'dangky', 'tran']
if ($kieuGop === 'ngay') {
    for ($i = $ngay - 1; $i >= 0; $i--) {
        $t = strtotime("-$i days");
        $bieuDo[date('Y-m-d', $t)] = ['nhan' => date('d/m', $t), 'dangky' => 0, 'tran' => 0];
    }
    $batDauBD = $tuNgay;
} elseif ($kieuGop === 'tuan') {
    // mỗi nhóm 7 ngày, nhóm 0 bắt đầu ở ngày đầu của khoảng; nhãn là ngày bắt đầu nhóm
    for ($k = 0; $k < (int) ceil($ngay / 7); $k++) {
        $bieuDo[$k] = ['nhan' => date('d/m', strtotime($tuNgay . ' +' . ($k * 7) . ' days')), 'dangky' => 0, 'tran' => 0];
    }
    $batDauBD = $tuNgay;
} else {
    // 12 tháng gần nhất (tính cả tháng hiện tại)
    for ($i = 11; $i >= 0; $i--) {
        $t = strtotime(date('Y-m-01') . " -$i months");
        $bieuDo[date('Y-m', $t)] = ['nhan' => date('m/Y', $t), 'dangky' => 0, 'tran' => 0];
    }
    $batDauBD = date('Y-m-01', strtotime(date('Y-m-01') . ' -11 months'));
}
$mocBD = $batDauBD . ' 00:00:00';

$st = $pdo->prepare(
    'SELECT DATE(COALESCE(ThoiGianBatDau, ThoiGianKetThuc)) AS ngay, COUNT(*) AS so
     FROM TRANDAU
     WHERE COALESCE(ThoiGianBatDau, ThoiGianKetThuc) >= ?
     GROUP BY ngay'
);
$st->execute([$mocBD]);
foreach ($st as $r) {
    if ($kieuGop === 'ngay') {
        $k = $r['ngay'];
    } elseif ($kieuGop === 'tuan') {
        $k = (int) floor((strtotime($r['ngay']) - strtotime($tuNgay)) / 86400 / 7);
    } else {
        $k = substr($r['ngay'], 0, 7);
    }
    if (isset($bieuDo[$k])) {
        $bieuDo[$k]['tran'] += (int) $r['so'];
    }
}

$st = $pdo->prepare(
    'SELECT DATE(NgayTao) AS ngay, COUNT(*) AS so FROM NGUOIDUNG WHERE NgayTao >= ? GROUP BY ngay'
);
$st->execute([$mocBD]);
foreach ($st as $r) {
    if ($kieuGop === 'ngay') {
        $k = $r['ngay'];
    } elseif ($kieuGop === 'tuan') {
        $k = (int) floor((strtotime($r['ngay']) - strtotime($tuNgay)) / 86400 / 7);
    } else {
        $k = substr($r['ngay'], 0, 7);
    }
    if (isset($bieuDo[$k])) {
        $bieuDo[$k]['dangky'] += (int) $r['so'];
    }
}

// ---- Biểu đồ tròn: số trận theo môn học trong khoảng đang xem (5 môn nhiều nhất + "Khác") ----
$st = $pdo->prepare(
    'SELECT m.TenMon, COUNT(*) AS so
     FROM TRANDAU t
     JOIN MONHOC m ON m.MaMon = t.MaMon
     WHERE COALESCE(t.ThoiGianBatDau, t.ThoiGianKetThuc) >= ?
     GROUP BY m.MaMon, m.TenMon
     ORDER BY so DESC, m.TenMon'
);
$st->execute([$moc]);
$mauMon  = ['#2f6bff', '#8b5cf6', '#f5a524', '#1fb88a', '#ef476f', '#94a3b8'];
$theoMon = [];
$khac    = 0;
$tongTranMon = 0;
foreach ($st->fetchAll() as $i => $r) {
    $tongTranMon += (int) $r['so'];
    if ($i < 5) {
        $theoMon[] = ['ten' => $r['TenMon'], 'so' => (int) $r['so']];
    } else {
        $khac += (int) $r['so'];
    }
}
if ($khac > 0) {
    $theoMon[] = ['ten' => 'Khác', 'so' => $khac];
}
foreach ($theoMon as $i => $m) {
    $theoMon[$i]['mau'] = $mauMon[$i];
    $theoMon[$i]['phantram'] = round($m['so'] * 100 / $tongTranMon, 1);
}

// Dữ liệu cho Chart.js (đọc ở assets/js/admin/index.js)
$duLieuDash = [
    'nhan'   => array_column($bieuDo, 'nhan'),
    'dangky' => array_column($bieuDo, 'dangky'),
    'tran'   => array_column($bieuDo, 'tran'),
    'mon'    => $theoMon,
];

// ---- A-09: người dùng hoạt động = có tham gia (đã vào phòng) ít nhất một trận trong khoảng ----
$st = $pdo->prepare(
    "SELECT COUNT(DISTINCT n.MaND)
     FROM NGUOICHOITRANDAU n
     JOIN TRANDAU t ON t.MaTran = n.MaTran
     WHERE n.TrangThaiThamGia <> 'Chưa vào'
       AND COALESCE(t.ThoiGianBatDau, t.ThoiGianKetThuc) >= ?"
);
$st->execute([$moc]);
$hoatDong   = (int) $st->fetchColumn();
$tyLeHoatDong = (int) $tong['nguoidung'] > 0 ? round($hoatDong * 100 / (int) $tong['nguoidung'], 1) : 0;

// ---- A-10: 10 câu hỏi bị trả lời sai nhiều nhất (tính mọi trận) ----
// Sai = đã chọn một đáp án nhưng đáp án đó không đúng; câu hết giờ không tính là sai.
$caiSai = $pdo->query(
    "SELECT ch.MaCauHoi, ch.NoiDung, m.TenMon,
            SUM(d.LaDapAnDung = 0) AS so_sai,
            COUNT(*)               AS tong
     FROM CHITIETTRALOI c
     JOIN DAPAN  d  ON d.MaDapAn  = c.MaDapAn
     JOIN CAUHOI ch ON ch.MaCauHoi = c.MaCauHoi
     JOIN MONHOC m  ON m.MaMon    = ch.MaMon
     WHERE c.TrangThai = 'Đã trả lời'
     GROUP BY ch.MaCauHoi, ch.NoiDung, m.TenMon
     HAVING so_sai > 0
     ORDER BY so_sai DESC, tong ASC
     LIMIT 10"
)->fetchAll();

// ---- Giao diện mới - Phần 1: lời chào, 4 thẻ số liệu, % tăng so với kỳ trước ----
// Kỳ này = $ngay ngày gần nhất; kỳ trước = $ngay ngày liền trước đó (cùng độ dài).
$mocTruoc = date('Y-m-d', strtotime('-' . (2 * $ngay - 1) . ' days')) . ' 00:00:00';

$st = $pdo->prepare('SELECT HoTen FROM ADMIN WHERE MaAdmin = ?');
$st->execute([$adminId]);
$tenAdmin = (string) ($st->fetchColumn() ?: 'Admin');

$thu = ['Chủ nhật', 'Thứ hai', 'Thứ ba', 'Thứ tư', 'Thứ năm', 'Thứ sáu', 'Thứ bảy'];
$ngayHienTai = $thu[(int) date('w')] . ', ' . date('d/m/Y');
$gioHienTai  = date('H:i');

// Người đăng ký mới: kỳ này, kỳ trước
$st = $pdo->prepare(
    'SELECT SUM(NgayTao >= ?), SUM(NgayTao >= ? AND NgayTao < ?) FROM NGUOIDUNG WHERE NgayTao >= ?'
);
$st->execute([$moc, $mocTruoc, $moc, $mocTruoc]);
[$ndNay, $ndTruoc] = array_map('intval', $st->fetch(PDO::FETCH_NUM));

// Trận đấu: tính theo ngày bắt đầu (trận hủy trước khi bắt đầu tính theo ngày kết thúc, như A-08)
$st = $pdo->prepare(
    'SELECT SUM(t >= ?), SUM(t >= ? AND t < ?)
     FROM (SELECT COALESCE(ThoiGianBatDau, ThoiGianKetThuc) AS t FROM TRANDAU) x
     WHERE t >= ?'
);
$st->execute([$moc, $mocTruoc, $moc, $mocTruoc]);
[$trNay, $trTruoc] = array_map('intval', $st->fetch(PDO::FETCH_NUM));

// Bài đăng cộng đồng (không tính bài đã xóa): tổng, kỳ này, kỳ trước
$tongBai = (int) $pdo->query("SELECT COUNT(*) FROM BAIDANG WHERE TrangThai <> 'Đã xóa'")->fetchColumn();
$st = $pdo->prepare(
    "SELECT SUM(NgayDang >= ?), SUM(NgayDang >= ? AND NgayDang < ?)
     FROM BAIDANG WHERE TrangThai <> 'Đã xóa' AND NgayDang >= ?"
);
$st->execute([$moc, $mocTruoc, $moc, $mocTruoc]);
[$baiNay, $baiTruoc] = array_map('intval', $st->fetch(PDO::FETCH_NUM));

// Tỷ lệ hoạt động kỳ trước (cùng cách tính A-09, mẫu số là tổng người dùng hiện tại)
$st = $pdo->prepare(
    "SELECT COUNT(DISTINCT n.MaND)
     FROM NGUOICHOITRANDAU n
     JOIN TRANDAU t ON t.MaTran = n.MaTran
     WHERE n.TrangThaiThamGia <> 'Chưa vào'
       AND COALESCE(t.ThoiGianBatDau, t.ThoiGianKetThuc) >= ?
       AND COALESCE(t.ThoiGianBatDau, t.ThoiGianKetThuc) < ?"
);
$st->execute([$mocTruoc, $moc]);
$hoatDongTruoc     = (int) $st->fetchColumn();
$tyLeHoatDongTruoc = (int) $tong['nguoidung'] > 0 ? round($hoatDongTruoc * 100 / (int) $tong['nguoidung'], 1) : 0;

// Mỗi thẻ: [nhãn, số lớn, màu, biểu tượng, giá trị kỳ này, giá trị kỳ trước, kiểu thay đổi]
// 'phantram' = % thay đổi so với kỳ trước; 'diem' = chênh lệch điểm phần trăm (dùng cho tỷ lệ)
$theSo = [
    ['Tổng người dùng',    number_format((int) $tong['nguoidung'], 0, ',', '.'), 'xanh', '👥', $ndNay,        $ndTruoc,           'phantram'],
    ['Tổng trận đấu',      number_format((int) $tong['trandau'], 0, ',', '.'),   'vang', '🏆', $trNay,        $trTruoc,           'phantram'],
    ['Bài đăng cộng đồng', number_format($tongBai, 0, ',', '.'),                 'tim',  '💬', $baiNay,       $baiTruoc,          'phantram'],
    ['Tỷ lệ hoạt động',    $tyLeHoatDong . '%',                                  'luc',  '📈', $tyLeHoatDong, $tyLeHoatDongTruoc, 'diem'],
];

// ---- Giao diện mới - Phần 3: hoạt động gần đây và top người chơi ----
// Hoạt động gần đây: gộp 4 nguồn (đăng ký mới, bài đăng mới, trận kết thúc, nhật ký quản trị), lấy 5 mục mới nhất (còn lại xem ở trang Nhật ký)
$dsHoatDong = [];
$gioNay = time();
$kq = $pdo->query(
    "(SELECT 'dangky' AS loai, u.NgayTao AS tg, u.HoTen AS ten, '' AS chitiet
        FROM NGUOIDUNG u ORDER BY u.NgayTao DESC LIMIT 5)
     UNION ALL
     (SELECT 'baidang', b.NgayDang, u.HoTen, b.TieuDe
        FROM BAIDANG b JOIN NGUOIDUNG u ON u.MaND = b.MaND
        WHERE b.TrangThai <> 'Đã xóa' ORDER BY b.NgayDang DESC LIMIT 5)
     UNION ALL
     (SELECT 'tran', t.ThoiGianKetThuc, m.TenMon, ''
        FROM TRANDAU t JOIN MONHOC m ON m.MaMon = t.MaMon
        WHERE t.TrangThai = 'Kết thúc' AND t.ThoiGianKetThuc IS NOT NULL
        ORDER BY t.ThoiGianKetThuc DESC LIMIT 5)
     UNION ALL
     (SELECT 'nhatky', n.ThoiGian, COALESCE(a.HoTen, 'Quản trị viên'), CONCAT(n.HanhDong, ' - ', n.DoiTuong)
        FROM NHATKYQUANTRI n LEFT JOIN ADMIN a ON a.MaAdmin = n.MaAdmin
        ORDER BY n.ThoiGian DESC LIMIT 5)
     ORDER BY tg DESC LIMIT 5"
);
foreach ($kq as $r) {
    $bieuTuong = ['dangky' => '👤', 'baidang' => '📝', 'tran' => '🏆', 'nhatky' => '🛠'][$r['loai']];
    $tieuDeMuc = e($r['ten']);
    if ($r['loai'] === 'dangky') {
        $noiDung = '<b>' . $tieuDeMuc . '</b> vừa đăng ký tài khoản';
    } elseif ($r['loai'] === 'baidang') {
        $noiDung = '<b>' . $tieuDeMuc . '</b> đăng bài mới: ' . e(preg_replace('/^(.{50}).+$/su', '$1…', (string) $r['chitiet']));
    } elseif ($r['loai'] === 'tran') {
        $noiDung = 'Trận <b>' . $tieuDeMuc . '</b> vừa kết thúc';
    } else {
        $noiDung = '<b>' . $tieuDeMuc . '</b>: ' . e($r['chitiet']);
    }

    $giay = max(0, $gioNay - strtotime($r['tg']));
    if ($giay < 60) {
        $cach = 'vừa xong';
    } elseif ($giay < 3600) {
        $cach = floor($giay / 60) . ' phút trước';
    } elseif ($giay < 86400) {
        $cach = floor($giay / 3600) . ' giờ trước';
    } elseif ($giay < 30 * 86400) {
        $cach = floor($giay / 86400) . ' ngày trước';
    } else {
        $cach = date('d/m/Y', strtotime($r['tg']));
    }
    $dsHoatDong[] = ['icon' => $bieuTuong, 'noidung' => $noiDung, 'cach' => $cach];
}

// Top 5 người chơi: mỗi người lấy điểm Elo cao nhất trong các môn đã chơi (bỏ tài khoản bị khóa)
$topNguoi = [];
$kq = $pdo->query(
    "SELECT u.MaND, u.HoTen, u.AnhDaiDien, e.DiemElo
     FROM ELOMONHOC e
     JOIN NGUOIDUNG u ON u.MaND = e.MaND
     WHERE e.SoTran > 0 AND u.TrangThai = 'Hoạt động'
       AND e.DiemElo = (SELECT MAX(e2.DiemElo) FROM ELOMONHOC e2 WHERE e2.MaND = e.MaND AND e2.SoTran > 0)
     ORDER BY e.DiemElo DESC, e.SoTran DESC, u.MaND
     LIMIT 15"
);
foreach ($kq as $r) {
    if (!isset($topNguoi[$r['MaND']]) && count($topNguoi) < 5) {   // một người có thể hòa Elo ở 2 môn: chỉ lấy một dòng
        $topNguoi[$r['MaND']] = $r;
    }
}
// Elo thay đổi của họ trong khoảng đang xem (cộng ThayDoi ở LICHSUELO)
foreach ($topNguoi as $ma => $r) {
    $st = $pdo->prepare('SELECT COALESCE(SUM(ThayDoi), 0) FROM LICHSUELO WHERE MaND = ? AND ThoiGian >= ?');
    $st->execute([$ma, $moc]);
    $topNguoi[$ma]['thaydoi'] = (int) $st->fetchColumn();
}

// ---- Thông báo của admin: số chưa đọc ban đầu (danh sách tải qua admin/thongbao_api.php) ----
$soChuaDocAdmin = ThongBaoAdmin::laySoChuaDoc($pdo, $adminId);

$tieuDe  = 'Tổng quan';
$mucChon = 'dashboard';
$jsTrang  = ['index', 'thongbao_admin', 'thongke_xuat'];
$cssTrang = ['index', 'thongke_xuat'];
require __DIR__ . '/layout/header.php';
?>
<div class="dash">
  <div class="dash-thanhtren">
    <div class="adm-tb" id="admTb" data-api="<?= e(BASE_URL) ?>/admin/thongbao_api.php" data-csrf="<?= e(csrf_token()) ?>">
      <button type="button" class="dash-chuong" id="admTbNut" aria-label="Thông báo" aria-expanded="false" title="Thông báo cộng đồng">
        🔔<em id="admTbBadge"<?= $soChuaDocAdmin > 0 ? '' : ' hidden' ?>><?= $soChuaDocAdmin > 99 ? '99+' : $soChuaDocAdmin ?></em>
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
    <button type="button" class="btn" id="nutXuat" title="Xuất báo cáo thống kê (Excel/PDF)">⬇ Xuất</button>
    <div class="dash-admin"><b><?= e($tenAdmin) ?></b><span>Quản trị viên</span></div>
  </div>

  <div class="dash-chao">
    <span class="dash-vuong">👑</span>
    <div class="dash-loichao">
      <h2>Chào mừng trở lại, <?= e($tenAdmin) ?>!</h2>
      <p>Cùng xây dựng một cộng đồng tri thức vững mạnh hơn nhé!</p>
    </div>
    <div class="dash-ngay"><span>📅 <?= e($ngayHienTai) ?></span><span>🕒 <?= e($gioHienTai) ?></span></div>
  </div>

  <div class="dash-the">
    <?php foreach ($theSo as [$nhan, $so, $mau, $icon, $nay, $truoc, $kieu]):
        if ($kieu === 'diem') {
            $chenh = round($nay - $truoc, 1);
            $chu   = ($chenh > 0 ? '+' : '') . $chenh . ' điểm %';
        } elseif ($truoc > 0) {
            $chenh = round(($nay - $truoc) * 100 / $truoc);
            $chu   = ($chenh > 0 ? '+' : '') . $chenh . '%';
        } elseif ($nay > 0) {
            $chenh = 1;
            $chu   = 'mới';
        } else {
            $chenh = 0;
            $chu   = '0%';
        }
        $mui = $chenh > 0 ? '↑' : ($chenh < 0 ? '↓' : '→');
        $lop = $chenh > 0 ? 'len' : ($chenh < 0 ? 'xuong' : 'ngang'); ?>
      <div class="dash-so dash-<?= $mau ?>">
        <span class="dash-icon"><?= $icon ?></span>
        <div class="dash-so-lon"><?= e($so) ?></div>
        <div class="dash-nhan"><?= e($nhan) ?></div>
        <div class="dash-tang <?= $lop ?>"><?= $mui ?> <?= e($chu) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
  <p class="dash-phu">Mức tăng/giảm so với <?= $ngay ?> ngày trước đó. Tổng số môn học: <?= (int) $tong['monhoc'] ?> · Tổng số câu hỏi: <?= (int) $tong['cauhoi'] ?></p>

  <div class="dash-luoi">
    <section class="dash-khung">
      <div class="dash-khung-dau">
        <h3>📊 Thống kê tổng quan</h3>
        <div class="dash-loc">
          <?php foreach ([7 => '7 ngày', 30 => '30 ngày', 90 => '90 ngày', 365 => '1 năm'] as $n => $ten): ?>
            <a href="?ngay=<?= $n ?>"<?= $n === $ngay ? ' class="chon"' : '' ?>><?= e($ten) ?></a>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="dash-bieudo"><canvas id="bd-tong-quan"></canvas></div>
      <p class="dash-phu">Gom theo <?= $kieuGop === 'ngay' ? 'ngày' : ($kieuGop === 'tuan' ? 'tuần (nhãn là ngày đầu tuần)' : 'tháng') ?>.</p>
    </section>

    <section class="dash-khung">
      <div class="dash-khung-dau"><h3>🥧 Thống kê theo môn học</h3></div>
      <?php if ($tongTranMon === 0): ?>
        <p class="dash-phu">Chưa có trận nào trong <?= $ngay ?> ngày gần nhất.</p>
      <?php else: ?>
        <div class="dash-tron">
          <div class="dash-tron-hinh">
            <canvas id="bd-mon"></canvas>
            <div class="dash-tron-giua"><strong><?= $tongTranMon ?></strong><span>trận</span></div>
          </div>
          <ul class="dash-chuthich">
            <?php foreach ($theoMon as $m): ?>
              <li><i style="background:<?= e($m['mau']) ?>"></i><span><?= e($m['ten']) ?></span><b><?= e((string) $m['phantram']) ?>%</b></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
    </section>
  </div>

  <div class="dash-luoi dash-hang3">
    <section class="dash-khung">
      <div class="dash-khung-dau">
        <h3>⚡ Hoạt động gần đây</h3>
        <a class="dash-xemthem" href="<?= e(BASE_URL) ?>/admin/nhatky.php">Xem thêm →</a>
      </div>
      <?php if (!$dsHoatDong): ?>
        <p class="dash-phu">Chưa có hoạt động nào.</p>
      <?php else: ?>
        <ul class="dash-hoatdong">
          <?php foreach ($dsHoatDong as $h): ?>
            <li><span class="dash-hd-icon"><?= $h['icon'] ?></span><span class="dash-hd-tt"><span class="dash-hd-nd"><?= $h['noidung'] ?></span><time><?= e($h['cach']) ?></time></span></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <section class="dash-khung">
      <div class="dash-khung-dau">
        <h3>👑 Top người chơi</h3>
        <a class="dash-xemthem" href="<?= e(BASE_URL) ?>/admin/elo.php?v=bxh">Xem thêm →</a>
      </div>
      <?php if (!$topNguoi): ?>
        <p class="dash-phu">Chưa có người chơi nào có trận.</p>
      <?php else: ?>
        <ol class="dash-top">
          <?php $hang = 0; foreach ($topNguoi as $r): $hang++;
              $tu = preg_split('/\s+/', trim((string) $r['HoTen']));
              $chuCai = mb_strtoupper(mb_substr((string) end($tu), 0, 1));
              $td = $r['thaydoi'];
              $lopTd = $td > 0 ? 'len' : ($td < 0 ? 'xuong' : 'ngang');
              $muiTd = $td > 0 ? '↑ +' . $td : ($td < 0 ? '↓ ' . $td : '→ 0'); ?>
            <li>
              <span class="dash-hang h<?= $hang ?>"><?= $hang ?></span>
              <?php if (!empty($r['AnhDaiDien'])): ?>
                <img class="dash-avatar" src="<?= e(BASE_URL . '/' . ltrim((string) $r['AnhDaiDien'], '/')) ?>" alt="">
              <?php else: ?>
                <span class="dash-avatar dash-avatar-chu"><?= e($chuCai) ?></span>
              <?php endif; ?>
              <span class="dash-top-ten"><b><?= e($r['HoTen']) ?></b></span>
              <span class="dash-top-so">
                <span class="dash-top-elo"><?= (int) $r['DiemElo'] ?> Elo</span>
                <span class="dash-tang <?= $lopTd ?>"><?= e($muiTd) ?></span>
              </span>
            </li>
          <?php endforeach; ?>
        </ol>
        <p class="dash-phu">Mũi tên: Elo thay đổi trong <?= $ngay ?> ngày gần nhất.</p>
      <?php endif; ?>
    </section>

    <section class="dash-khung">
      <div class="dash-khung-dau"><h3>🚀 Thao tác nhanh</h3></div>
      <div class="dash-nhanh">
        <a class="dash-nut nx" href="<?= e(BASE_URL) ?>/admin/cauhoi_form.php"><span>❓</span>Thêm câu hỏi</a>
        <a class="dash-nut nv" href="<?= e(BASE_URL) ?>/admin/cauhoi_nhap.php"><span>📥</span>Nhập từ Word/Excel</a>
        <a class="dash-nut nt" href="<?= e(BASE_URL) ?>/admin/congdong.php"><span>💬</span>Kiểm duyệt cộng đồng</a>
        <a class="dash-nut nl" href="<?= e(BASE_URL) ?>/admin/nhatky.php"><span>📜</span>Nhật ký quản trị</a>
      </div>
    </section>
  </div>
  <section class="dash-khung dash-day">
    <div class="dash-khung-dau"><h3>🎯 Trận đấu theo trạng thái</h3></div>
    <div class="dash-trangthai">
      <?php foreach ($theoTrangThai as $ten => $so): ?>
        <div class="dash-tt"><span><?= e($ten) ?></span><b><?= $so ?></b></div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="dash-khung dash-day">
    <div class="dash-khung-dau"><h3>❗ 10 câu hỏi bị trả lời sai nhiều nhất</h3></div>
    <?php if (!$caiSai): ?>
      <p class="dash-phu">Chưa có dữ liệu.</p>
    <?php else: ?>
      <div class="dash-cuon">
        <table class="dash-bang">
          <tr><th>Câu hỏi</th><th>Môn</th><th>Lượt sai</th><th>Lượt trả lời</th><th>Tỷ lệ sai</th></tr>
          <?php foreach ($caiSai as $r): ?>
            <tr>
              <td><?= e(preg_replace('/^(.{90}).+$/su', '$1…', (string) $r['NoiDung'])) ?></td>
              <td><?= e($r['TenMon']) ?></td>
              <td><?= (int) $r['so_sai'] ?></td>
              <td><?= (int) $r['tong'] ?></td>
              <td><?= e((string) round((int) $r['so_sai'] * 100 / (int) $r['tong'], 1)) ?>%</td>
            </tr>
          <?php endforeach; ?>
        </table>
      </div>
    <?php endif; ?>
  </section>
</div>

<div class="modal-nen" id="hopXuat" hidden>
  <form class="modal xuat-hop" id="formXuat" method="post" action="<?= e(BASE_URL) ?>/admin/thongke_xuat.php" role="dialog" aria-modal="true" aria-labelledby="xuatTieuDe"
        data-api="<?= e(BASE_URL) ?>/admin/thongke_du_lieu.php">
    <h3 id="xuatTieuDe">Xuất báo cáo thống kê</h3>
    <?= csrf_field() ?>
    <div class="xuat-luoi">
      <div class="xuat-chonCot">
        <fieldset>
          <legend>Xuất trang nào?</legend>
          <label class="xuat-chon"><input type="radio" name="trang" value="tongquan" checked> Thống kê tổng quan</label>
          <label class="xuat-chon"><input type="radio" name="trang" value="mon"> Thống kê theo môn học</label>
          <label class="xuat-chon"><input type="radio" name="trang" value="ca"> Cả hai</label>
        </fieldset>
        <fieldset>
          <legend>Mốc thời gian (chọn được nhiều)</legend>
          <?php foreach ([7 => '7 ngày', 30 => '30 ngày', 90 => '90 ngày', 365 => '1 năm'] as $n => $ten): ?>
            <label class="xuat-chon"><input type="checkbox" name="ngay[]" value="<?= $n ?>"<?= $n === $ngay ? ' checked' : '' ?>> <?= e($ten) ?></label>
          <?php endforeach; ?>
          <button type="button" class="btn btn-phu xuat-tatca" id="xuatChonHet">Chọn tất cả</button>
        </fieldset>
        <fieldset>
          <legend>Định dạng</legend>
          <label class="xuat-chon"><input type="radio" name="dinhdang" value="excel" checked> Excel (.xlsx)</label>
          <label class="xuat-chon"><input type="radio" name="dinhdang" value="pdf"> PDF</label>
          <label class="xuat-chon"><input type="radio" name="dinhdang" value="ca"> Cả hai (tải liên tiếp 2 file)</label>
        </fieldset>
        <fieldset>
          <legend>Cách chia sheet / trang</legend>
          <label class="xuat-chon"><input type="radio" name="chia" value="ngay" checked> Theo mốc ngày</label>
          <label class="xuat-chon"><input type="radio" name="chia" value="loai"> Theo loại biểu đồ</label>
          <label class="xuat-chon"><input type="radio" name="chia" value="ca"> Cả hai</label>
          <p class="xuat-ghichu" id="xuatGhiChuChia"></p>
        </fieldset>
      </div>
      <div class="xuat-xemCot">
        <div class="xuat-xemDau">Xem trước</div>
        <div class="xuat-xem" id="xuatXem"><p class="xuat-ghichu">Đang tải...</p></div>
      </div>
    </div>
    <p class="xuat-loi" id="xuatLoi" hidden></p>
    <div class="hanh-dong">
      <button type="button" class="btn btn-phu" id="xuatHuy">Hủy</button>
      <button type="submit" class="btn" id="xuatOk">Xuất</button>
    </div>
  </form>
</div>

<script src="<?= e(BASE_URL) ?>/assets/vendor/chart.umd.js"></script>
<script id="du-lieu-dash" type="application/json"><?= json_encode($duLieuDash, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php require __DIR__ . '/layout/footer.php'; ?>