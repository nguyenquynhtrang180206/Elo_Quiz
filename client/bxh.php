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
$loaiHopLe = false;
foreach ($dsLoai as $l) {
    if ((int) $l['MaLoaiKT'] === $loai) {
        $loaiHopLe = true;
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
$khongCoLoai = $phamVi === 'loai' && $loai === 0;
$core = null;
$p = [];
$kieu = '';   // quyết định các cột hiển thị

if ($phamVi === 'loai') {
    // Theo loại kỳ thi: xếp theo tổng Elo đã thắng/thua được trong các trận đã kết thúc của loại kỳ thi này
    if (!$khongCoLoai) {
        $kieu = 'loai';
        $core = "SELECT u.MaND, u.HoTen, u.AnhDaiDien, SUM(n.EloSau - n.EloTruoc) AS Diem,
                        COUNT(*) AS SoTran, SUM(n.KetQua = 'Thắng') AS SoThang
                   FROM NGUOICHOITRANDAU n
                   JOIN TRANDAU t ON t.MaTran = n.MaTran
                   JOIN NGUOIDUNG u ON u.MaND = n.MaND
                  WHERE t.MaLoaiKT = ? AND t.TrangThai = 'Kết thúc' AND n.EloSau IS NOT NULL
                    AND u.TrangThai = 'Hoạt động'";
        $p = [$loai];
        if ($tuNgay !== null) {
            $core .= ' AND t.ThoiGianKetThuc >= ?';
            $p[] = $tuNgay;
        }
        $core .= ' GROUP BY u.MaND, u.HoTen, u.AnhDaiDien';
    }
} elseif (!$canDangNhap && !$khongCoMon) {
    if ($ky === 'tong' && $phamVi === 'truong') {
        // C-37: Elo trung bình các môn đã đạt tối thiểu N trận; môn chưa đủ N không được tính
        $kieu = 'truong';
        $core = "SELECT u.MaND, u.HoTen, u.AnhDaiDien, AVG(e.DiemElo) AS Diem, COUNT(*) AS SoMon,
                        SUM(e.SoTran) AS SoTran, SUM(e.SoThang) AS SoThang
                   FROM ELOMONHOC e JOIN NGUOIDUNG u ON u.MaND = e.MaND
                  WHERE e.SoTran >= ? AND u.TrangThai = 'Hoạt động'
                  GROUP BY u.MaND, u.HoTen, u.AnhDaiDien";
        $p = [$nMin];
    } elseif ($ky === 'tong') {
        // C-36, C-38: theo môn, có thể giới hạn trong nhóm bạn bè
        $kieu = 'mon';
        $core = "SELECT u.MaND, u.HoTen, u.AnhDaiDien, e.DiemElo AS Diem, e.SoTran, e.SoThang
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
            $core = "SELECT u.MaND, u.HoTen, u.AnhDaiDien, SUM(l.ThayDoi) AS Diem, COUNT(*) AS SoTran,
                            (SELECT AVG(x.DiemElo) FROM ELOMONHOC x WHERE x.MaND = l.MaND AND x.SoTran >= ?) AS EloHienTai
                       FROM LICHSUELO l JOIN NGUOIDUNG u ON u.MaND = l.MaND
                      WHERE l.ThoiGian >= ? AND u.TrangThai = 'Hoạt động'
                      GROUP BY u.MaND, u.HoTen, u.AnhDaiDien";
            $p = [$nMin, $tuNgay];
        } else {
            $core = "SELECT u.MaND, u.HoTen, u.AnhDaiDien, SUM(l.ThayDoi) AS Diem, COUNT(*) AS SoTran, e.DiemElo AS EloHienTai
                       FROM LICHSUELO l
                       JOIN NGUOIDUNG u ON u.MaND = l.MaND
                       JOIN ELOMONHOC e ON e.MaND = l.MaND AND e.MaMon = l.MaMon
                      WHERE l.MaMon = ? AND l.ThoiGian >= ? AND u.TrangThai = 'Hoạt động'";
            $p = [$mon, $tuNgay];
            if ($phamVi === 'banbe') {
                $core .= " AND l.MaND IN $taiBanBe";
                array_push($p, $toi, $toi, $toi);
            }
            $core .= ' GROUP BY u.MaND, u.HoTen, u.AnhDaiDien, e.DiemElo';
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

$tabs = ['truong' => 'Tổng thể', 'mon' => 'Theo môn học', 'loai' => 'Theo loại kỳ thi', 'banbe' => 'Bạn bè'];
$kyTen = ['tong' => 'Tất cả', 'tuan' => 'Tuần này', 'thang' => 'Tháng này'];

// Danh sách cột theo kiểu bảng
if ($kieu === 'truong') {
    $cot = ['Người chơi', 'Số môn tính', 'Elo trung bình', 'Số trận', 'Tỉ lệ thắng'];
} elseif ($kieu === 'mon') {
    $cot = ['Người chơi', 'Môn học', 'Điểm Elo', 'Số trận', 'Tỉ lệ thắng'];
} elseif ($kieu === 'loai') {
    $cot = ['Người chơi', 'Loại kỳ thi', 'Elo thay đổi', 'Số trận', 'Tỉ lệ thắng'];
} else {
    $cot = $phamVi === 'truong'
        ? ['Người chơi', 'Thay đổi Elo', 'Số trận trong kỳ', 'Elo hiện tại']
        : ['Người chơi', 'Môn học', 'Thay đổi Elo', 'Số trận trong kỳ', 'Elo hiện tại'];
}

$tieuDe = 'Bảng xếp hạng';
$cssTrang = 'bxh';
$bodyClass = 'trang-bxh';
require __DIR__ . '/layout/header.php';
?>
<section class="bxh-hero">
  <span class="bxh-cup" aria-hidden="true">&#127942;</span>
  <div>
    <h2>Bảng xếp hạng</h2>
    <p>Vinh danh những người chơi xuất sắc nhất!</p>
  </div>
</section>

<nav class="bxh-tabs" aria-label="Phạm vi bảng xếp hạng">
  <?php foreach ($tabs as $k => $ten): ?>
    <a class="bxh-tab<?= $phamVi === $k ? ' dang-chon' : '' ?>" href="<?= e(bxhUrl(['pv' => $k])) ?>"><?= e($ten) ?></a>
  <?php endforeach; ?>
</nav>

<div class="bxh-loc">
  <?php if ($phamVi === 'loai' && $dsLoai): ?>
    <div class="bxh-nhom">
      <span class="bxh-nhan">Loại kỳ thi</span>
      <?php foreach ($dsLoai as $l): ?>
        <a class="bxh-chip<?= (int) $l['MaLoaiKT'] === $loai ? ' dang-chon' : '' ?>" href="<?= e(bxhUrl(['loai' => (int) $l['MaLoaiKT']])) ?>"><?= e($l['TenLoaiKT']) ?></a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php if (!in_array($phamVi, ['truong', 'loai'], true) && $dsMon): ?>
    <div class="bxh-nhom">
      <span class="bxh-nhan">Môn học</span>
      <?php foreach ($dsMon as $m): ?>
        <a class="bxh-chip<?= (int) $m['MaMon'] === $mon ? ' dang-chon' : '' ?>" href="<?= e(bxhUrl(['mon' => (int) $m['MaMon']])) ?>"><?= e($m['TenMon']) ?></a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <div class="bxh-nhom">
    <span class="bxh-nhan">Thời gian</span>
    <?php foreach ($kyTen as $k => $ten): ?>
      <a class="bxh-chip<?= $ky === $k ? ' dang-chon' : '' ?>" href="<?= e(bxhUrl(['ky' => $k])) ?>"><?= e($ten) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<div class="bxh-the">
<div class="bxh-podium" aria-hidden="true">
  <span class="bxh-cup-lon">&#127942;</span>
  <div class="bxh-bac-thang"><span class="b2">2</span><span class="b1">1</span><span class="b3">3</span></div>
</div>
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
            <th class="c-hang">#</th>
            <?php foreach ($cot as $i => $ten): ?>
              <th class="<?= $i === 0 ? 'c-ten' : 'c-so' ?>"><?= e($ten) ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($ds as $r):
            $laToi = $toi !== null && (int) $r['MaND'] === $toi;
            $hang  = (int) $r['Hang'];
            $tyLe  = isset($r['SoThang']) && (int) $r['SoTran'] > 0 ? round($r['SoThang'] * 100 / $r['SoTran']) : 0;
        ?>
          <tr class="<?= $laToi ? 'la-toi ' : '' ?><?= $hang <= 3 ? 'top-' . $hang : '' ?>">
            <td class="c-hang"><?= $hang <= 3 ? BXH_VUONG_MIEN : '' ?><span class="so-hang"><?= $hang ?></span></td>
            <td class="c-ten"><div class="o-ten">
              <?= bxhAvatar($r) ?>
              <span><?= e($r['HoTen']) ?><?= $laToi ? ' <em>(bạn)</em>' : '' ?></span>
            </div></td>
            <?php if ($kieu === 'truong'): ?>
              <td class="c-so"><?= (int) $r['SoMon'] ?> môn</td>
              <td class="c-so"><strong><?= e((string) round((float) $r['Diem'], 1)) ?></strong></td>
              <td class="c-so"><?= (int) $r['SoTran'] ?></td>
              <td class="c-so"><?= $tyLe ?>%</td>
            <?php elseif ($kieu === 'mon'): ?>
              <td class="c-so"><?= e($tenMon) ?></td>
              <td class="c-so"><strong><?= (int) $r['Diem'] ?></strong></td>
              <td class="c-so"><?= (int) $r['SoTran'] ?></td>
              <td class="c-so"><?= $tyLe ?>%</td>
            <?php elseif ($kieu === 'loai'): ?>
              <td class="c-so"><?= e($tenLoai) ?></td>
              <td class="c-so"><strong class="<?= (int) $r['Diem'] < 0 ? 'giam' : 'tang' ?>"><?= dau((int) $r['Diem']) ?></strong></td>
              <td class="c-so"><?= (int) $r['SoTran'] ?></td>
              <td class="c-so"><?= $tyLe ?>%</td>
            <?php else: ?>
              <?php if ($phamVi !== 'truong'): ?><td class="c-so"><?= e($tenMon) ?></td><?php endif; ?>
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
</div>

<p class="ghi-chu">
  <?php if ($phamVi === 'loai'): ?>
    Xếp theo tổng Elo thay đổi trong các trận đã kết thúc của loại kỳ thi này (mọi môn)<?= $ky !== 'tong' ? ' trong kỳ đã chọn' : '' ?>.
  <?php elseif ($phamVi === 'truong'): ?>
    <?php if ($ky === 'tong'): ?>
      Elo trung bình chỉ tính các môn đã đấu từ <?= (int) $nMin ?> trận trở lên; số trận và tỉ lệ thắng cũng chỉ tính trong các môn đó.
    <?php else: ?>
      Xếp theo tổng mức thay đổi Elo của mọi môn trong kỳ; Elo hiện tại là Elo trung bình các môn đã đủ <?= (int) $nMin ?> trận.
    <?php endif; ?>
  <?php elseif ($ky !== 'tong'): ?>
    Xếp theo tổng mức thay đổi Elo trong kỳ; người không có trận trong kỳ không hiện ở bảng này.
  <?php else: ?>
    Chỉ hiện người đã đấu ít nhất một trận ở môn này. Cùng Elo thì cùng hạng.
  <?php endif; ?>
</p>
<?php require __DIR__ . '/layout/footer.php'; ?>