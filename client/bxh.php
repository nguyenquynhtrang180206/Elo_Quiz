<?php
// Bảng xếp hạng (C-36 đến C-39). Đặt tại: elo_quiz/client/bxh.php
// Các tab: Tổng thể = toàn trường (C-37), Theo môn học (C-36), Theo loại kỳ thi, Bạn bè (C-38).
// Ba kỳ: tất cả, tuần này, tháng này (C-39): kỳ tuần/tháng xếp theo tổng ThayDoi trong LICHSUELO của kỳ.
// Xem BXH không bắt buộc đăng nhập (C-12); riêng tab Bạn bè cần đăng nhập. Phân trang 10 dòng/trang (3.8).
require_once __DIR__ . '/../core/bootstrap.php';

$pdo  = Database::pdo();
$toi  = Auth::clientId();   // có thể null: khách vẫn xem được
$nMin = max(1, Config::int('SO_TRAN_TOI_THIEU_BXH', 10));

$dsMon = $pdo->query("SELECT MaMon, TenMon FROM MONHOC WHERE TrangThai = 'Đang mở' ORDER BY TenMon")->fetchAll();
$mon = (int) ($_GET['mon'] ?? 0);
$monHopLe = false;
foreach ($dsMon as $m) {
    if ((int) $m['MaMon'] === $mon) {
        $monHopLe = true;
    }
}
if (!$monHopLe) {
    $mon = $dsMon ? (int) $dsMon[0]['MaMon'] : 0;
}

// Mặc định mở tab "Tổng thể" giống bản thiết kế
$dsLoai = $pdo->query('SELECT MaLoaiKT, TenLoaiKT FROM LOAIKYTHI ORDER BY MaLoaiKT')->fetchAll();
$loai = (int) ($_GET['loai'] ?? 0);
$loaiHopLe = $loai === 0;
if ($loai !== 0) {
    foreach ($dsLoai as $l) {
        if ((int) $l['MaLoaiKT'] === $loai) {
            $loaiHopLe = true;
        }
    }
}
if (!$loaiHopLe) {
    $loai = $dsLoai ? (int) $dsLoai[0]['MaLoaiKT'] : 0;
}

$phamVi = (string) ($_GET['pv'] ?? 'truong');
if (!in_array($phamVi, ['truong', 'mon', 'loai', 'banbe'], true)) {
    $phamVi = 'truong';
}
$ky = (string) ($_GET['ky'] ?? 'tong');
if (!in_array($ky, ['tong', 'tuan', 'thang'], true)) {
    $ky = 'tong';
}

// Mốc đầu kỳ: tuần bắt đầu từ thứ Hai, tháng bắt đầu từ ngày 1
$tuNgay = null;
if ($ky === 'tuan') {
    $tuNgay = date('Y-m-d 00:00:00', strtotime('monday this week'));
} elseif ($ky === 'thang') {
    $tuNgay = date('Y-m-01 00:00:00');
}

// Tập "tôi và bạn bè đã chấp nhận": cần 3 tham số là mã người dùng
$taiBanBe = '(SELECT MaNguoiNhan FROM BANBE WHERE MaNguoiGui = ? AND TrangThai = \'Đã chấp nhận\'
              UNION SELECT MaNguoiGui FROM BANBE WHERE MaNguoiNhan = ? AND TrangThai = \'Đã chấp nhận\'
              UNION SELECT ?)';

$canDangNhap = $phamVi === 'banbe' && $toi === null;
$khongCoMon  = !in_array($phamVi, ['truong', 'loai'], true) && $mon === 0;
$khongCoLoai = $phamVi === 'loai' && !$dsLoai;
$core = null;
$p = [];
$kieu = '';   // quyết định các cột hiển thị

if ($phamVi === 'loai') {
    // Theo loại kỳ thi: xếp theo tổng Elo đã thắng/thua được trong các trận đã kết thúc của loại kỳ thi này
    if (!$khongCoLoai) {
        $kieu = 'loai';
        $core = "SELECT u.MaND, u.HoTen, u.AnhDaiDien, u.Khoa, SUM(n.EloSau - n.EloTruoc) AS Diem,
                        COUNT(*) AS SoTran, SUM(n.KetQua = 'Thắng') AS SoThang
                   FROM NGUOICHOITRANDAU n
                   JOIN TRANDAU t ON t.MaTran = n.MaTran
                   JOIN NGUOIDUNG u ON u.MaND = n.MaND
                  WHERE t.TrangThai = 'Kết thúc' AND n.EloSau IS NOT NULL
                    AND u.TrangThai = 'Hoạt động'";
        if ($loai > 0) {
            $core .= ' AND t.MaLoaiKT = ?';
            $p[] = $loai;
        }
        if ($tuNgay !== null) {
            $core .= ' AND t.ThoiGianKetThuc >= ?';
            $p[] = $tuNgay;
        }
        $core .= ' GROUP BY u.MaND, u.HoTen, u.AnhDaiDien, u.Khoa';
    }
} elseif (!$canDangNhap && !$khongCoMon) {
    if ($ky === 'tong' && $phamVi === 'truong') {
        // C-37: Elo trung bình các môn đã đạt tối thiểu N trận; môn chưa đủ N không được tính
        $kieu = 'truong';
        // MonChinh: môn người này đấu nhiều trận nhất (trong các môn đã đủ N trận, môn đang mở)
        $core = "SELECT u.MaND, u.HoTen, u.AnhDaiDien, u.Khoa, AVG(e.DiemElo) AS Diem, COUNT(*) AS SoMon,
                        SUM(e.SoTran) AS SoTran, SUM(e.SoThang) AS SoThang,
                        (SELECT m.TenMon FROM ELOMONHOC x JOIN MONHOC m ON m.MaMon = x.MaMon
                          WHERE x.MaND = u.MaND AND x.SoTran >= ? AND m.TrangThai = 'Đang mở'
                          ORDER BY x.SoTran DESC, x.DiemElo DESC LIMIT 1) AS MonChinh
                   FROM ELOMONHOC e JOIN NGUOIDUNG u ON u.MaND = e.MaND
                  WHERE e.SoTran >= ? AND u.TrangThai = 'Hoạt động'
                  GROUP BY u.MaND, u.HoTen, u.AnhDaiDien, u.Khoa";
        $p = [$nMin, $nMin];
    } elseif ($ky === 'tong') {
        // C-36, C-38: theo môn, có thể giới hạn trong nhóm bạn bè
        $kieu = 'mon';
        $core = "SELECT u.MaND, u.HoTen, u.AnhDaiDien, u.Khoa, e.DiemElo AS Diem, e.SoTran, e.SoThang
                   FROM ELOMONHOC e JOIN NGUOIDUNG u ON u.MaND = e.MaND
                  WHERE e.MaMon = ? AND e.SoTran > 0 AND u.TrangThai = 'Hoạt động'";
        $p = [$mon];
        if ($phamVi === 'banbe') {
            $core .= " AND e.MaND IN $taiBanBe";
            array_push($p, $toi, $toi, $toi);
        }
    } else {
        // C-39: tổng mức thay đổi Elo trong kỳ; người không có trận trong kỳ không xuất hiện
        $kieu = 'ky';
        if ($phamVi === 'truong') {
            $core = "SELECT u.MaND, u.HoTen, u.AnhDaiDien, u.Khoa, SUM(l.ThayDoi) AS Diem, COUNT(*) AS SoTran,
                            (SELECT AVG(x.DiemElo) FROM ELOMONHOC x WHERE x.MaND = l.MaND AND x.SoTran >= ?) AS EloHienTai
                       FROM LICHSUELO l JOIN NGUOIDUNG u ON u.MaND = l.MaND
                      WHERE l.ThoiGian >= ? AND u.TrangThai = 'Hoạt động'
                      GROUP BY u.MaND, u.HoTen, u.AnhDaiDien, u.Khoa";
            $p = [$nMin, $tuNgay];
        } else {
            $core = "SELECT u.MaND, u.HoTen, u.AnhDaiDien, u.Khoa, SUM(l.ThayDoi) AS Diem, COUNT(*) AS SoTran, e.DiemElo AS EloHienTai
                       FROM LICHSUELO l
                       JOIN NGUOIDUNG u ON u.MaND = l.MaND
                       JOIN ELOMONHOC e ON e.MaND = l.MaND AND e.MaMon = l.MaMon
                      WHERE l.MaMon = ? AND l.ThoiGian >= ? AND u.TrangThai = 'Hoạt động'";
            $p = [$mon, $tuNgay];
            if ($phamVi === 'banbe') {
                $core .= " AND l.MaND IN $taiBanBe";
                array_push($p, $toi, $toi, $toi);
            }
            $core .= ' GROUP BY u.MaND, u.HoTen, u.AnhDaiDien, u.Khoa, e.DiemElo';
        }
    }
}

$ds = [];
$phanTrang = null;
if ($core !== null) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM ($core) d");
    $st->execute($p);
    $phanTrang = new Paginator((int) $st->fetchColumn(), 10);
    // Cùng điểm thì cùng hạng (RANK); trong cùng hạng ưu tiên người nhiều trận hơn
    $st = $pdo->prepare(
        "SELECT r.* FROM (SELECT d.*, RANK() OVER (ORDER BY d.Diem DESC) AS Hang FROM ($core) d) r
          ORDER BY r.Hang, r.SoTran DESC, r.MaND
          LIMIT " . $phanTrang->limit() . ' OFFSET ' . $phanTrang->offset()
    );
    $st->execute($p);
    $ds = $st->fetchAll();
}

$tenMon = '';
foreach ($dsMon as $m) {
    if ((int) $m['MaMon'] === $mon) {
        $tenMon = $m['TenMon'];
    }
}

$tenLoai = '';
foreach ($dsLoai as $l) {
    if ((int) $l['MaLoaiKT'] === $loai) {
        $tenLoai = $l['TenLoaiKT'];
    }
}

function dau(int $n): string
{
    return ($n > 0 ? '+' : '') . $n;
}

// Link giữ lại các lựa chọn hiện tại, chỉ đổi tham số truyền vào (đổi bộ lọc thì về trang 1)
function bxhUrl(array $doi): string
{
    $q = array_merge(['pv' => $GLOBALS['phamVi'], 'mon' => $GLOBALS['mon'], 'loai' => $GLOBALS['loai'], 'ky' => $GLOBALS['ky']], $doi);
    return '?' . http_build_query($q);
}

// Ảnh đại diện; chưa có ảnh thì hiện chữ cái đầu của tên
function bxhAvatar(array $r): string
{
    if (!empty($r['AnhDaiDien'])) {
        return '<img class="bxh-ava" src="' . e(BASE_URL . '/' . $r['AnhDaiDien']) . '" alt="">';
    }
    $ten = trim((string) $r['HoTen']);
    $chu = $ten !== '' ? mb_strtoupper(mb_substr($ten, 0, 1, 'UTF-8'), 'UTF-8') : '?';
    return '<span class="bxh-ava bxh-ava-chu">' . e($chu) . '</span>';
}

const BXH_VUONG_MIEN = '<svg class="bxh-vuongmien" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M3 7l4.5 4L12 4l4.5 7L21 7l-2 11H5L3 7zm2.2 13h13.6v2H5.2z"/></svg>';

$tabs = ['truong' => 'Toàn hệ thống', 'mon' => 'Theo môn học', 'loai' => 'Theo loại kỳ thi', 'banbe' => 'Bạn bè'];
$kyTen = ['tong' => 'Tất cả', 'tuan' => 'Tuần này', 'thang' => 'Tháng này'];

// Danh sách cột theo kiểu bảng
if ($kieu === 'truong') {
    $cot = ['Người chơi', 'Môn học chính', 'Elo trung bình', 'Số trận', 'Tỉ lệ thắng'];
} elseif ($kieu === 'mon') {
    $cot = ['Người chơi', 'Môn học', 'Điểm Elo', 'Số trận', 'Tỉ lệ thắng'];
} elseif ($kieu === 'loai') {
    $cot = ['Người chơi', 'Loại kỳ thi', 'Elo thay đổi', 'Số trận', 'Tỉ lệ thắng'];
} else {
    $cot = $phamVi === 'truong'
        ? ['Người chơi', 'Thay đổi Elo', 'Số trận trong kỳ', 'Elo hiện tại']
        : ['Người chơi', 'Môn học', 'Thay đổi Elo', 'Số trận trong kỳ', 'Elo hiện tại'];
}

// Thống kê nổi bật (số liệu thật từ CSDL)
function bxhRutGon(int $n): string
{
    if ($n >= 1000000) { return rtrim(rtrim(number_format($n / 1000000, 1), '0'), '.') . 'M'; }
    if ($n >= 1000)    { return rtrim(rtrim(number_format($n / 1000, 1), '0'), '.') . 'K'; }
    return (string) $n;
}
$thongKe = ['thanhvien' => 0, 'tran' => 0, 'cauhoi' => 0];
$topMon = [];
try {
    $thongKe['thanhvien'] = (int) $pdo->query("SELECT COUNT(*) FROM NGUOIDUNG WHERE TrangThai = 'Hoạt động'")->fetchColumn();
    $thongKe['tran']      = (int) $pdo->query("SELECT COUNT(*) FROM TRANDAU WHERE TrangThai = 'Kết thúc'")->fetchColumn();
    $thongKe['cauhoi']    = (int) $pdo->query("SELECT COUNT(*) FROM CAUHOI WHERE TrangThai = 'Hiển thị'")->fetchColumn();
    // Top môn học: môn có nhiều trận đã kết thúc nhất (môn đang mở)
    $topMon = $pdo->query("SELECT m.MaMon, m.TenMon, COUNT(*) AS SoTran
                             FROM TRANDAU t JOIN MONHOC m ON m.MaMon = t.MaMon
                            WHERE t.TrangThai = 'Kết thúc' AND m.TrangThai = 'Đang mở'
                            GROUP BY m.MaMon, m.TenMon ORDER BY SoTran DESC, m.TenMon LIMIT 3")->fetchAll();
} catch (Throwable $ex) {
    // lỗi truy vấn thì hiện 0 / danh sách rỗng, không làm hỏng bảng xếp hạng
}
$maxTranMon = $topMon ? max(1, (int) $topMon[0]['SoTran']) : 1;

$tieuDe = 'Bảng xếp hạng';
$cssTrang = 'bxh';
$bodyClass = 'trang-bxh';
require __DIR__ . '/layout/header.php';
?>
<section class="bxh-hero">
  <div class="bxh-hero-aurora" aria-hidden="true"></div>
  <div class="bxh-hero-grid" aria-hidden="true"></div>
  <span class="bxh-cup" aria-hidden="true"><img src="<?= e(BASE_URL) ?>/assets/images/hero-trophy-3d.jpg" alt="Cúp Vô Địch"></span>
  <div class="bxh-hero-center">
    <div class="bxh-live-pill">
      <span class="bxh-live-ping" aria-hidden="true"></span>
      <span>QUIZUP ARENA &bull; ĐẤU THẦN BẢNG</span>
    </div>
    <h2 class="bxh-hero-title">BẢNG XẾP HẠNG</h2>
    <div class="bxh-hero-divider" aria-hidden="true"></div>
    <p class="bxh-hero-sub">Chinh phục đỉnh cao và vinh danh <strong>Chiến Thần Tri Thức!</strong></p>
  </div>
  <div class="bxh-hero-quote-box">
    <span class="bxh-quote-sparkle" aria-hidden="true">✨</span>
    <p class="bxh-loi-dan">“Thi đấu hết mình,<br>tỏa sáng cùng QuizUp Arena!”</p>
  </div>
</section>

<div class="bxh-bocuc">

  <!-- ===== Cột trái: bộ lọc + lời mời thi đấu ===== -->
  <aside class="bxh-trai">
    <div class="bxh-hop">
      <h3 class="bxh-hop-tieude">&#127942; Bộ lọc</h3>

      <div class="bxh-nhom">
        <span class="bxh-nhan">Theo môn học</span>
        <div class="bxh-chips">
          <a class="bxh-chip<?= $phamVi === 'truong' ? ' dang-chon' : '' ?>" href="<?= e(bxhUrl(['pv' => 'truong'])) ?>">&#9734; Tất cả</a>
          <?php foreach ($dsMon as $m): ?>
            <a class="bxh-chip<?= $phamVi === 'mon' && (int) $m['MaMon'] === $mon ? ' dang-chon' : '' ?>" href="<?= e(bxhUrl(['pv' => 'mon', 'mon' => (int) $m['MaMon']])) ?>"><?= e($m['TenMon']) ?></a>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="bxh-nhom">
        <span class="bxh-nhan">Theo loại kỳ thi</span>
        <div class="bxh-chips">
          <a class="bxh-chip<?= $phamVi === 'loai' && $loai === 0 ? ' dang-chon' : '' ?>" href="<?= e(bxhUrl(['pv' => 'loai', 'loai' => 0])) ?>">&#127775; Tất cả</a>
          <?php foreach ($dsLoai as $l): ?>
            <a class="bxh-chip<?= $phamVi === 'loai' && (int) $l['MaLoaiKT'] === $loai ? ' dang-chon' : '' ?>" href="<?= e(bxhUrl(['pv' => 'loai', 'loai' => (int) $l['MaLoaiKT']])) ?>"><?= e($l['TenLoaiKT']) ?></a>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="bxh-nhom">
        <span class="bxh-nhan">Thời gian</span>
        <div class="bxh-chips">
          <?php foreach ($kyTen as $k => $ten): ?>
            <a class="bxh-chip<?= $ky === $k ? ' dang-chon' : '' ?>" href="<?= e(bxhUrl(['ky' => $k])) ?>"><?= e($ten) ?></a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="bxh-hop bxh-moi">
      <span class="bxh-moi-mien" aria-hidden="true">&#128081;</span>
      <p>Bạn đã sẵn sàng<br>để vươn lên vị trí dẫn đầu?</p>
      <a class="bxh-nut-vang" href="<?= e(BASE_URL) ?>/client/timtran.php">Bắt đầu thi đấu &rarr;</a>
    </div>
  </aside>

  <!-- ===== Cột giữa: tab + bảng xếp hạng ===== -->
  <section class="bxh-giua">
    <nav class="bxh-tabs" aria-label="Phạm vi bảng xếp hạng">
      <?php foreach ($tabs as $k => $ten): ?>
        <a class="bxh-tab<?= $phamVi === $k ? ' dang-chon' : '' ?>" href="<?= e(bxhUrl(['pv' => $k])) ?>"><?= e($ten) ?></a>
      <?php endforeach; ?>
    </nav>

    <div class="bxh-khung">
      <?php if ($khongCoLoai): ?>
        <p class="bxh-rong">Chưa có loại kỳ thi nào.</p>
      <?php elseif ($canDangNhap): ?>
        <p class="bxh-rong">Hãy <a href="<?= e(BASE_URL) ?>/client/login.php">đăng nhập</a> để xem bảng xếp hạng bạn bè.</p>
      <?php elseif ($khongCoMon): ?>
        <p class="bxh-rong">Chưa có môn học nào đang mở.</p>
      <?php elseif (!$ds): ?>
        <p class="bxh-rong">Chưa có dữ liệu xếp hạng cho lựa chọn này.</p>
      <?php else: ?>
        <div class="bxh-cuon">
          <table class="bxh-bang">
            <thead>
              <tr>
                <th class="c-hang">Hạng</th>
                <?php foreach ($cot as $i => $ten): ?>
                  <th class="<?= $i === 0 ? 'c-ten' : 'c-so' ?>"><?= e($ten) ?></th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($ds as $r):
                $laToi = $toi !== null && (int) $r['MaND'] === $toi;
                $hang  = (int) $r['Hang'];
                $tyLe  = isset($r['SoThang']) && (int) $r['SoTran'] > 0 ? round($r['SoThang'] * 100 / $r['SoTran'], 1) : 0;
            ?>
              <tr class="<?= $laToi ? 'la-toi ' : '' ?><?= $hang <= 3 ? 'top-' . $hang : '' ?>">
                <td class="c-hang"><?= $hang <= 3 ? BXH_VUONG_MIEN : '' ?><span class="so-hang"><?= $hang ?></span></td>
                <td class="c-ten"><div class="o-ten">
                  <?= bxhAvatar($r) ?>
                  <span class="o-ten-chu">
                    <b><?= e($r['HoTen']) ?><?= $laToi ? ' <em>(bạn)</em>' : '' ?></b>
                    <?php if (!empty($r['Khoa'])): ?><small><?= e($r['Khoa']) ?></small><?php endif; ?>
                  </span>
                </div></td>
                <?php if ($kieu === 'truong'): ?>
                  <td class="c-so"><?= $r['MonChinh'] !== null ? '<span class="bxh-mon">' . e($r['MonChinh']) . '</span>' : '&ndash;' ?></td>
                  <td class="c-so"><strong><?= e((string) round((float) $r['Diem'], 1)) ?></strong></td>
                  <td class="c-so"><?= (int) $r['SoTran'] ?></td>
                  <td class="c-so"><?= $tyLe ?>%</td>
                <?php elseif ($kieu === 'mon'): ?>
                  <td class="c-so"><span class="bxh-mon"><?= e($tenMon) ?></span></td>
                  <td class="c-so"><strong><?= (int) $r['Diem'] ?></strong></td>
                  <td class="c-so"><?= (int) $r['SoTran'] ?></td>
                  <td class="c-so"><?= $tyLe ?>%</td>
                <?php elseif ($kieu === 'loai'): ?>
                  <td class="c-so"><span class="bxh-mon"><?= e($tenLoai !== '' ? $tenLoai : 'Tất cả loại') ?></span></td>
                  <td class="c-so"><strong class="<?= (int) $r['Diem'] < 0 ? 'giam' : 'tang' ?>"><?= dau((int) $r['Diem']) ?></strong></td>
                  <td class="c-so"><?= (int) $r['SoTran'] ?></td>
                  <td class="c-so"><?= $tyLe ?>%</td>
                <?php else: ?>
                  <?php if ($phamVi !== 'truong'): ?><td class="c-so"><span class="bxh-mon"><?= e($tenMon) ?></span></td><?php endif; ?>
                  <td class="c-so"><strong class="<?= (int) $r['Diem'] < 0 ? 'giam' : 'tang' ?>"><?= dau((int) $r['Diem']) ?></strong></td>
                  <td class="c-so"><?= (int) $r['SoTran'] ?></td>
                  <td class="c-so"><?= $r['EloHienTai'] === null ? '&ndash;' : e((string) round((float) $r['EloHienTai'], $phamVi === 'truong' ? 1 : 0)) ?></td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?= $phanTrang->links() ?>
      <?php endif; ?>
    </div>

    <p class="ghi-chu">
      <?php if ($phamVi === 'loai'): ?>
        Xếp theo tổng Elo thay đổi trong các trận đã kết thúc của loại kỳ thi này (mọi môn)<?= $ky !== 'tong' ? ' trong kỳ đã chọn' : '' ?>.
      <?php elseif ($phamVi === 'truong'): ?>
        <?php if ($ky === 'tong'): ?>
          Elo trung bình chỉ tính các môn đã đấu từ <?= (int) $nMin ?> trận trở lên; số trận và tỉ lệ thắng cũng chỉ tính trong các môn đó. Môn học chính là môn bạn đấu nhiều nhất.
        <?php else: ?>
          Xếp theo tổng mức thay đổi Elo của mọi môn trong kỳ; Elo hiện tại là Elo trung bình các môn đã đủ <?= (int) $nMin ?> trận.
        <?php endif; ?>
      <?php elseif ($ky !== 'tong'): ?>
        Xếp theo tổng mức thay đổi Elo trong kỳ; người không có trận trong kỳ không hiện ở bảng này.
      <?php else: ?>
        Chỉ hiện người đã đấu ít nhất một trận ở môn này. Cùng Elo thì cùng hạng.
      <?php endif; ?>
    </p>
  </section>

  <!-- ===== Cột phải: thống kê nổi bật + top môn học ===== -->
  <aside class="bxh-phai">
    <div class="bxh-hop">
      <h3 class="bxh-hop-tieude">&#128202; Thống kê nổi bật</h3>
      <div class="bxh-thongke">
        <div><span class="bxh-thongke-ico" aria-hidden="true">&#128101;</span><b><?= e(bxhRutGon($thongKe['thanhvien'])) ?></b><span>Thành viên</span></div>
        <div><span class="bxh-thongke-ico" aria-hidden="true">&#9876;</span><b><?= e(bxhRutGon($thongKe['tran'])) ?></b><span>Trận đấu</span></div>
        <div><span class="bxh-thongke-ico" aria-hidden="true">&#127942;</span><b><?= e(bxhRutGon($thongKe['cauhoi'])) ?></b><span>Câu hỏi</span></div>
      </div>
    </div>

    <div class="bxh-hop">
      <h3 class="bxh-hop-tieude">&#11088; Top môn học</h3>
      <?php if (!$topMon): ?>
        <p class="bxh-goiy">Chưa có trận nào kết thúc.</p>
      <?php else: ?>
        <ol class="bxh-topmon">
          <?php foreach ($topMon as $i => $t): ?>
            <li class="hang-<?= $i + 1 ?>">
              <span class="bxh-huy"><?= $i + 1 ?></span>
              <a href="<?= e(bxhUrl(['pv' => 'mon', 'mon' => (int) $t['MaMon'], 'ky' => 'tong'])) ?>">
                <b><?= e($t['TenMon']) ?></b>
                <small><?= (int) $t['SoTran'] ?> trận đã đấu</small>
                <i style="width:<?= (int) round($t['SoTran'] * 100 / $maxTranMon) ?>%"></i>
              </a>
            </li>
          <?php endforeach; ?>
        </ol>
      <?php endif; ?>
    </div>
  </aside>

</div>
<?php require __DIR__ . '/layout/footer.php'; ?>