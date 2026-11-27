<?php
// Theo dõi Elo, bảng xếp hạng và biến động bất thường (chỉ xem, không chỉnh Elo thủ công).
require_once __DIR__ . '/../core/bootstrap.php';
Auth::requireAdmin(BASE_URL . '/admin/dangnhap.php');

$pdo = Database::pdo();
$v   = (string) ($_GET['v'] ?? 'bxh');
if (!in_array($v, ['tra', 'bxh', 'bd'], true)) {
    $v = 'bxh';
}
$monHoc = $pdo->query('SELECT MaMon, TenMon FROM MONHOC ORDER BY TenMon')->fetchAll();
$tenMon = array_column($monHoc, 'TenMon', 'MaMon');
$mon    = (int) ($_GET['mon'] ?? 0);
if ($mon !== 0 && !isset($tenMon[$mon])) {
    $mon = 0;
}

// Khởi tạo biến dùng chung cho các tab.
$q        = trim((string) ($_GET['q'] ?? ''));
$linkXuat = '';
$tenBxh   = '';
$nMin     = max(1, Config::int('SO_TRAN_TOI_THIEU_BXH', 10));
$nguong   = max(1, Config::int('NGUONG_BIEN_DONG_ELO', 150));
$gio      = max(1, Config::int('KHOANG_BIEN_DONG_ELO', 24));

$ty = static fn (int $thang, int $tran): string => $tran > 0 ? number_format($thang * 100 / $tran, 1) . '%' : '—';
$ds = [];
$p  = null;

// Bậc rank tính động từ ELO_KHOI_TAO, không lưu CSDL.
$eloKt   = Config::int('ELO_KHOI_TAO', 1000);
$bacRank = [
    ['ten' => 'Cao thủ',   'tu' => $eloKt + 450, 'cls' => 'cao', 'mau' => '#c13cff'],
    ['ten' => 'Kim cương', 'tu' => $eloKt + 300, 'cls' => 'kc', 'mau' => '#2f8bff'],
    ['ten' => 'Vàng',      'tu' => $eloKt + 150, 'cls' => 'vang', 'mau' => '#f5a524'],
    ['ten' => 'Bạc',       'tu' => $eloKt + 50,  'cls' => 'bac', 'mau' => '#94a3b8'],
    ['ten' => 'Đồng',      'tu' => PHP_INT_MIN,  'cls' => 'dong', 'mau' => '#b9733a'],
];
$bacCua = static function (int $elo) use ($bacRank): array {
    foreach ($bacRank as $b) {
        if ($elo >= $b['tu']) {
            return $b;
        }
    }
    return end($bacRank);
};
// Avatar chữ cái đầu và màu cố định theo mã người dùng.
$mauAva  = ['#1e5cf0', '#18b87a', '#e5484d', '#8b5cf6', '#f59e0b', '#0ea5e9', '#14b8a6', '#ec4899'];
$chuCai  = static function (string $ten): string {
    $t = preg_split('/\s+/u', trim($ten), -1, PREG_SPLIT_NO_EMPTY) ?: ['?'];
    $a = mb_strtoupper(mb_substr($t[0], 0, 1));
    $b = count($t) > 1 ? mb_strtoupper(mb_substr(end($t), 0, 1)) : '';
    return $a . $b;
};

// Số ca biến động bất thường trên toàn hệ thống.
$st = $pdo->prepare(
    'SELECT COUNT(*) FROM (SELECT 1 FROM LICHSUELO l WHERE l.ThoiGian >= DATE_SUB(NOW(), INTERVAL ? HOUR)
      GROUP BY l.MaND, l.MaMon HAVING ABS(SUM(l.ThayDoi)) > ?) x'
);
$st->execute([$gio, $nguong]);
$soBd = (int) $st->fetchColumn();

// Dữ liệu thống kê cho tab Bảng xếp hạng.
$thongKe  = ['tong' => 0, 'tb' => 0, 'max' => 0];
$top5     = [];
$phoElo   = ['nhan' => [], 'so' => [], 'mau' => []];
$diemElo  = [];
$phanBo   = [];
$bienDong = [];
$chon     = null;
$hangChon = 0;
$tenMonXu  = '';

// Tab 1: Tra cứu Elo theo người dùng và môn.
if ($v === 'tra') {
    $dk  = [];
    $tham = [];
    if ($q !== '') {
        $like = '%' . addcslashes($q, '\\%_') . '%';
        $dk[] = '(n.HoTen LIKE ? OR n.Email LIKE ?)';
        array_push($tham, $like, $like);
    }
    if ($mon > 0) {
        $dk[]   = 'e.MaMon = ?';
        $tham[] = $mon;
    }
    $where = $dk ? 'WHERE ' . implode(' AND ', $dk) : '';

    $st = $pdo->prepare("SELECT COUNT(*) FROM ELOMONHOC e JOIN NGUOIDUNG n ON n.MaND = e.MaND $where");
    $st->execute($tham);
    $p = new Paginator((int) $st->fetchColumn(), 20);

    $st = $pdo->prepare(
        "SELECT n.MaND, n.HoTen, n.Email, m.TenMon, e.DiemElo, e.SoTran, e.SoThang, e.SoThua, e.SoHoa
           FROM ELOMONHOC e
           JOIN NGUOIDUNG n ON n.MaND = e.MaND
           JOIN MONHOC m ON m.MaMon = e.MaMon
           $where
          ORDER BY n.HoTen, m.TenMon
          LIMIT " . $p->limit() . ' OFFSET ' . $p->offset()
    );
    $st->execute($tham);
    $ds = $st->fetchAll();
}

// Tab 2: Bảng xếp hạng theo môn hoặc toàn trường.
if ($v === 'bxh') {
    if ($mon > 0) {
        // Theo môn: xếp theo Elo giảm dần, hòa Elo thì ưu tiên nhiều trận hơn.
        $from  = 'FROM ELOMONHOC e JOIN NGUOIDUNG n ON n.MaND = e.MaND WHERE e.MaMon = ?';
        $tham  = [$mon];
        $cot   = 'n.MaND, n.HoTen, n.Email, n.TrangThai, e.DiemElo AS elo, e.SoTran, e.SoThang';
        $order = 'ORDER BY e.DiemElo DESC, e.SoTran DESC, n.MaND';
        $sqlDem = "SELECT COUNT(*) $from";
    } else {
        // Toàn trường: Elo trung bình các môn đã đủ số trận tối thiểu.
        $from  = 'FROM ELOMONHOC e JOIN NGUOIDUNG n ON n.MaND = e.MaND WHERE e.SoTran >= ? GROUP BY n.MaND, n.HoTen, n.Email, n.TrangThai';
        $tham  = [$nMin];
        $cot   = 'n.MaND, n.HoTen, n.Email, n.TrangThai, ROUND(AVG(e.DiemElo)) AS elo, SUM(e.SoTran) AS SoTran, SUM(e.SoThang) AS SoThang, COUNT(*) AS so_mon';
        $order = 'ORDER BY AVG(e.DiemElo) DESC, SUM(e.SoTran) DESC, n.MaND';
        $sqlDem = "SELECT COUNT(*) FROM (SELECT n.MaND $from) x";
    }
    $tenBxh = $mon > 0 ? 'môn ' . $tenMon[$mon] : 'toàn trường';

    // Xuất Excel trực tiếp bảng đang xem.
    if (isset($_GET['xuat'])) {
        $st = $pdo->prepare("SELECT $cot $from $order LIMIT 10000");
        $st->execute($tham);
        $tieuDe = ['Hạng', 'Họ tên', 'Email', $mon > 0 ? 'Elo' : 'Elo trung bình', 'Số trận', 'Tỷ lệ thắng', 'Trạng thái'];
        $dong = [];
        foreach ($st->fetchAll() as $i => $r) {
            $dong[] = [$i + 1, $r['HoTen'], $r['Email'], (int) $r['elo'], (int) $r['SoTran'], $ty((int) $r['SoThang'], (int) $r['SoTran']), $r['TrangThai']];
        }
        XlsxWriter::tai('bxh_' . date('Ymd_His') . '.xlsx', 'BXH', $tieuDe, $dong);
    }

    $st = $pdo->prepare($sqlDem);
    $st->execute($tham);
    $p = new Paginator((int) $st->fetchColumn(), 20);

    $st = $pdo->prepare("SELECT $cot $from $order LIMIT " . $p->limit() . ' OFFSET ' . $p->offset());
    $st->execute($tham);
    $ds = $st->fetchAll();
    $linkXuat = '?' . http_build_query(array_merge(array_diff_key($_GET, ['page' => 1]), ['xuat' => 1]));

    // Thống kê phân bố Elo theo bậc và khoảng điểm.
    $cotElo = $mon > 0 ? 'e.DiemElo AS elo' : 'AVG(e.DiemElo) AS elo';
    $st = $pdo->prepare("SELECT $cotElo $from LIMIT 20000");
    $st->execute($tham);
    $moiElo = array_map('intval', array_map('round', array_map('floatval', $st->fetchAll(PDO::FETCH_COLUMN))));
    foreach ($bacRank as $b) {
        $phanBo[$b['cls']] = 0;
    }
    foreach ($moiElo as $gt) {
        $phanBo[$bacCua($gt)['cls']]++;
    }
    $thongKe['tong'] = count($moiElo);
    $thongKe['tb']   = $moiElo ? (int) round(array_sum($moiElo) / count($moiElo)) : 0;
    $thongKe['max']  = $moiElo ? max($moiElo) : 0;
    if ($moiElo) {
        $buoc = ($thongKe['max'] - min($moiElo)) > 1500 ? 200 : 100;
        for ($x = (int) (floor(min($moiElo) / $buoc) * $buoc); $x <= $thongKe['max']; $x += $buoc) {
            $c = 0;
            foreach ($moiElo as $gt) {
                if ($gt >= $x && $gt < $x + $buoc) {
                    $c++;
                }
            }
            $phoElo['nhan'][] = $x . '–' . ($x + $buoc - 1);
            $phoElo['so'][]   = $c;
            $phoElo['mau'][]  = $bacCua($x + intdiv($buoc, 2))['mau'];
        }
    }
    if ($ds) {
        // Top 5 Elo của bảng đang xem.
        if ($p->offset() === 0) {
            $top5 = array_slice($ds, 0, 5);
        } else {
            $st = $pdo->prepare("SELECT $cot $from $order LIMIT 5");
            $st->execute($tham);
            $top5 = $st->fetchAll();
        }

        // Biến động Elo 7 ngày gần nhất của người chơi trên trang.
        $dsId = array_map(static fn ($r) => (int) $r['MaND'], $ds);
        $sql  = 'SELECT l.MaND, SUM(l.ThayDoi) FROM LICHSUELO l WHERE l.MaND IN (' . implode(',', array_fill(0, count($dsId), '?')) . ')
                  AND l.ThoiGian >= DATE_SUB(NOW(), INTERVAL 7 DAY)' . ($mon > 0 ? ' AND l.MaMon = ?' : '') . ' GROUP BY l.MaND';
        $st = $pdo->prepare($sql);
        $st->execute($mon > 0 ? array_merge($dsId, [$mon]) : $dsId);
        $bienDong = array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR));

        // Người chơi hiển thị ở panel chi tiết.
        $nd = (int) ($_GET['nd'] ?? 0);
        foreach ($ds as $i => $r) {
            if ($chon === null || (int) $r['MaND'] === $nd) {
                $chon     = $r;
                $hangChon = $p->offset() + $i + 1;
                if ((int) $r['MaND'] === $nd) {
                    break;
                }
            }
        }
        // Chuỗi Elo 30 ngày (rút gọn tối đa 60 điểm cho biểu đồ).
        $monVe = $mon;
        if ($monVe === 0) {
            $st = $pdo->prepare('SELECT MaMon FROM ELOMONHOC WHERE MaND = ? ORDER BY SoTran DESC, MaMon LIMIT 1');
            $st->execute([(int) $chon['MaND']]);
            $monVe = (int) $st->fetchColumn();
        }
        if ($monVe > 0) {
            $tenMonXu = $tenMon[$monVe] ?? '';
            $st = $pdo->prepare(
                'SELECT EloSau FROM LICHSUELO WHERE MaND = ? AND MaMon = ? AND ThoiGian >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                  ORDER BY ThoiGian, MaLichSu LIMIT 500'
            );
            $st->execute([(int) $chon['MaND'], $monVe]);
            $diem = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            $n = count($diem);
            if ($n >= 2) {
                if ($n > 60) {
                    $moi = [];
                    for ($i = 0; $i < 60; $i++) {
                        $moi[] = $diem[(int) floor($i * $n / 60)];
                    }
                    $moi[59] = $diem[$n - 1];
                    $diem = $moi;
                }
                $diemElo = $diem;
            }
        }
    }
}

// Tab 3: Biến động Elo bất thường (tính trực tiếp từ LICHSUELO).
if ($v === 'bd') {
    $tham   = [$gio, $nguong];
    $loc    = '';
    if ($mon > 0) {
        $loc    = 'AND l.MaMon = ?';
        $tham   = [$gio, $mon, $nguong];
    }
    $nhom = "FROM LICHSUELO l
              WHERE l.ThoiGian >= DATE_SUB(NOW(), INTERVAL ? HOUR) $loc
              GROUP BY l.MaND, l.MaMon
             HAVING ABS(SUM(l.ThayDoi)) > ?";

    $st = $pdo->prepare("SELECT COUNT(*) FROM (SELECT l.MaND $nhom) x");
    $st->execute($tham);
    $p = new Paginator((int) $st->fetchColumn(), 20);

    $st = $pdo->prepare(
        "SELECT t.MaND, n.HoTen, n.Email, n.TrangThai, m.TenMon, t.tong, t.so_tran, t.elo_dau, t.elo_cuoi
           FROM (SELECT l.MaND, l.MaMon, SUM(l.ThayDoi) AS tong, COUNT(*) AS so_tran,
                        (SELECT x.EloTruoc FROM LICHSUELO x WHERE x.MaND = l.MaND AND x.MaMon = l.MaMon
                            AND x.ThoiGian >= DATE_SUB(NOW(), INTERVAL ? HOUR)
                          ORDER BY x.ThoiGian, x.MaLichSu LIMIT 1) AS elo_dau,
                        (SELECT x.EloSau FROM LICHSUELO x WHERE x.MaND = l.MaND AND x.MaMon = l.MaMon
                            AND x.ThoiGian >= DATE_SUB(NOW(), INTERVAL ? HOUR)
                          ORDER BY x.ThoiGian DESC, x.MaLichSu DESC LIMIT 1) AS elo_cuoi
                   $nhom) t
           JOIN NGUOIDUNG n ON n.MaND = t.MaND
           JOIN MONHOC m ON m.MaMon = t.MaMon
          ORDER BY ABS(t.tong) DESC, t.MaND
          LIMIT " . $p->limit() . ' OFFSET ' . $p->offset()
    );
    $st->execute(array_merge([$gio, $gio], $tham));
    $ds = $st->fetchAll();
}

$tieuDe   = 'Elo và xếp hạng';
$mucChon  = 'elo';
$cssTrang = ['thongke_xuat', 'elo'];   // Nạp elo sau để ghi đè style hộp thoại Xuất.
$jsTrang  = ['elo', 'elo_xuat'];
require __DIR__ . '/layout/header.php';

$tabs = ['bxh' => 'Bảng xếp hạng', 'tra' => 'Tra cứu Elo', 'bd' => 'Biến động bất thường'];
$chiTiet = static fn (int $id): string => e(BASE_URL) . '/admin/nguoidung_chitiet.php?id=' . $id;
?>
<div class="adm-page-header">
  <div class="adm-header-eyebrow">
    <span class="live-pulse"></span>
    <span>ELO RATING & LEADERBOARDS</span>
  </div>
  <div class="adm-header-main">
    <h2 class="adm-title-pro">
      <svg class="title-svg-icon" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="12" x2="12" y1="20" y2="10"/><line x1="18" x2="18" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="16"/></svg>
      <span>Elo và xếp hạng</span>
    </h2>
  </div>
  <p class="adm-subtitle-pro">
    Theo dõi bảng xếp hạng sinh viên, bậc rank và phát hiện biến động Elo bất thường.
  </p>
</div>

<div class="elo-arena">
  <div class="ea-tabs">
    <?php foreach ($tabs as $k => $ten): ?>
      <a class="ea-tab<?= $k === $v ? ' dang-chon' : '' ?><?= $k === 'bd' ? ' canh-bao' : '' ?>" href="?v=<?= e($k) ?>">
        <?= e($ten) ?><?php if ($k === 'bd' && $soBd > 0): ?> <span class="ea-dem"><?= $soBd ?></span><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>

  <div class="ea-note">Elo chỉ thay đổi do kết quả trận đấu; quản trị viên không điều chỉnh thủ công. Nghi ngờ gian lận thì khóa tài khoản ở mục Người dùng.
    <?php if ($soBd > 0 && $v !== 'bd'): ?> <a class="ea-note-link" href="?v=bd"><?= $soBd ?> ca biến động hơn <?= $nguong ?> điểm / <?= $gio ?> giờ cần xem xét →</a><?php endif; ?>
  </div>

  <?php if ($v === 'bxh'): ?>
  <div class="ea-bieudo-luoi">
    <section class="ea-khoi ea-bieudo-khung">
      <div class="ea-khung-dau"><h3><svg class="ea-ico" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/></svg>Phân bố theo bậc</h3></div>
      <?php if ($thongKe['tong'] === 0): ?>
        <div class="ea-ghichu ea-ghichu-trong">Chưa có người chơi nào được xếp hạng.</div>
      <?php else: ?>
        <div class="ea-tron">
          <div class="ea-tron-hinh">
            <canvas id="bd-bac"></canvas>
            <div class="ea-tron-giua"><strong><?= $thongKe['tong'] ?></strong><span>người chơi</span></div>
          </div>
          <ul class="ea-chuthich">
            <?php foreach ($bacRank as $b): $sl = $phanBo[$b['cls']] ?? 0; ?>
              <li><i style="background:<?= e($b['mau']) ?>"></i><span><?= e($b['ten']) ?></span><b><?= $sl ?></b></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <p class="ea-phu">Cao thủ từ <?= $bacRank[0]['tu'] ?>, Kim cương từ <?= $bacRank[1]['tu'] ?>, Vàng từ <?= $bacRank[2]['tu'] ?>, Bạc từ <?= $bacRank[3]['tu'] ?> điểm.</p>
      <?php endif; ?>
    </section>

    <section class="ea-khoi ea-bieudo-khung">
      <div class="ea-khung-dau"><h3><svg class="ea-ico" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="12" x2="12" y1="20" y2="10"/><line x1="18" x2="18" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="16"/></svg>Phân bố điểm Elo</h3></div>
      <?php if ($thongKe['tong'] === 0): ?>
        <div class="ea-ghichu ea-ghichu-trong">Chưa có dữ liệu.</div>
      <?php else: ?>
        <div class="ea-bieudo"><canvas id="bd-pho"></canvas></div>
        <p class="ea-phu">Trung bình <b><?= $thongKe['tb'] ?></b> · cao nhất <b><?= $thongKe['max'] ?></b> · BXH <?= e($tenBxh) ?>.</p>
      <?php endif; ?>
    </section>

    <section class="ea-khoi ea-bieudo-khung">
      <div class="ea-khung-dau"><h3><svg class="ea-ico" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/></svg>Top 5 Elo cao nhất</h3></div>
      <?php if (!$top5): ?>
        <div class="ea-ghichu ea-ghichu-trong">Chưa có dữ liệu.</div>
      <?php else: ?>
        <div class="ea-bieudo"><canvas id="bd-top"></canvas></div>
        <p class="ea-phu"><?= $mon > 0 ? 'Elo theo môn ' . e($tenMon[$mon]) : 'Elo trung bình các môn đã đủ ' . $nMin . ' trận' ?>.</p>
      <?php endif; ?>
    </section>
  </div>
  <?php endif; ?>

  <div class="<?= $v === 'bxh' ? 'ea-luoi' : '' ?>">
  <div class="ea-khoi">
    <form method="get" action="" class="ea-loc">
      <input type="hidden" name="v" value="<?= e($v) ?>">
      <?php if ($v === 'tra'): ?>
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="Tìm theo họ tên hoặc email" class="ea-o ea-o-tim">
      <?php endif; ?>
      <select name="mon" class="ea-o">
        <option value="0"><?= $v === 'bxh' ? 'Toàn trường' : 'Mọi môn' ?></option>
        <?php foreach ($monHoc as $m): ?>
          <option value="<?= (int) $m['MaMon'] ?>"<?= $mon === (int) $m['MaMon'] ? ' selected' : '' ?>><?= e($m['TenMon']) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="ea-nut">Lọc</button>
      <a class="ea-nut ea-nut-o" href="?v=<?= e($v) ?>">Xóa lọc</a>
      <?php if ($v === 'bxh'): ?><button type="button" class="ea-nut ea-nut-o ea-phai" id="exMoXuat" aria-haspopup="dialog">⬇ Xuất Excel / PDF</button><?php endif; ?>
    </form>

    <?php if ($v === 'bxh' && $mon === 0): ?>
      <div class="ea-ghichu">Toàn trường: Elo trung bình các môn đã đủ <?= $nMin ?> trận (SO_TRAN_TOI_THIEU_BXH); môn chưa đủ thì không tính.</div>
    <?php elseif ($v === 'bd'): ?>
      <div class="ea-ghichu">Người có Elo (theo từng môn) tăng hoặc giảm quá <?= $nguong ?> điểm trong <?= $gio ?> giờ gần nhất (NGUONG_BIEN_DONG_ELO, KHOANG_BIEN_DONG_ELO). Danh sách được tính khi mở trang, không lưu cờ riêng.</div>
    <?php endif; ?>

    <?php if (!$ds): ?>
      <div class="ea-trong">Không có dữ liệu khớp.</div>

    <?php elseif ($v === 'tra'): ?>
      <div class="ea-bang ea-tra">
        <div class="ea-dong ea-dau"><span>NGƯỜI DÙNG</span><span>MÔN</span><span>BẬC · ELO</span><span>SỐ TRẬN</span><span>THẮNG / THUA / HÒA</span><span>TỶ LỆ THẮNG</span></div>
        <?php foreach ($ds as $r): $b = $bacCua((int) $r['DiemElo']); $tl = (int) $r['SoTran'] > 0 ? (int) round($r['SoThang'] * 100 / $r['SoTran']) : 0; ?>
          <div class="ea-dong">
            <div class="ea-nd">
              <span class="ea-ava" style="background:<?= $mauAva[(int) $r['MaND'] % count($mauAva)] ?>"><?= e($chuCai($r['HoTen'])) ?></span>
              <div><a class="ea-ten" href="<?= $chiTiet((int) $r['MaND']) ?>"><?= e($r['HoTen']) ?></a><div class="ea-mail"><?= e($r['Email']) ?></div></div>
            </div>
            <span><?= e($r['TenMon']) ?></span>
            <span class="ea-bac"><i class="ea-ngoc <?= e($b['cls']) ?>"></i><span class="ea-elo"><?= (int) $r['DiemElo'] ?></span></span>
            <span><?= (int) $r['SoTran'] ?></span>
            <span><b class="xanh"><?= (int) $r['SoThang'] ?></b> / <b class="do"><?= (int) $r['SoThua'] ?></b> / <b><?= (int) $r['SoHoa'] ?></b></span>
            <span class="ea-tl"><?= e($ty((int) $r['SoThang'], (int) $r['SoTran'])) ?><span class="ea-thanh"><i style="width:<?= $tl ?>%"></i></span></span>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="ea-ghichu">Lịch sử Elo chi tiết của từng người xem ở trang chi tiết người dùng (bấm vào tên).</div>

    <?php elseif ($v === 'bxh'): ?>
      <div class="ea-bang ea-xh">
        <div class="ea-dong ea-dau"><span>HẠNG</span><span>NGƯỜI DÙNG</span><span>BẬC</span><span><?= $mon > 0 ? 'ELO' : 'ELO TB' ?></span><span>TRẠNG THÁI</span></div>
        <?php foreach ($ds as $i => $r):
            $hang = $p->offset() + $i + 1;
            $b    = $bacCua((int) $r['elo']);
            $dt   = $bienDong[(int) $r['MaND']] ?? 0;
            $laChon = $chon && (int) $r['MaND'] === (int) $chon['MaND'];
            $link = '?' . http_build_query(array_merge($_GET, ['v' => 'bxh', 'nd' => (int) $r['MaND']]));
        ?>
          <a class="ea-dong ea-bam<?= $laChon ? ' dang-chon' : '' ?>" href="<?= e($link) ?>">
            <span class="ea-hang<?= $hang <= 3 ? ' top' : '' ?>"><?= $hang ?></span>
            <div class="ea-nd">
              <span class="ea-ava" style="background:<?= $mauAva[(int) $r['MaND'] % count($mauAva)] ?>"><?= e($chuCai($r['HoTen'])) ?></span>
              <div><span class="ea-ten"><?= e($r['HoTen']) ?></span><div class="ea-mail"><?= (int) $r['SoTran'] ?> trận · <?= e($ty((int) $r['SoThang'], (int) $r['SoTran'])) ?> thắng</div></div>
            </div>
            <span class="ea-bac"><i class="ea-ngoc <?= e($b['cls']) ?>"></i><?= e($b['ten']) ?></span>
            <span><span class="ea-elo"><?= (int) $r['elo'] ?></span><?php if ($dt !== 0): ?><span class="ea-dt <?= $dt > 0 ? 'len' : 'xuong' ?>" title="Thay đổi 7 ngày gần nhất"><?= $dt > 0 ? '▲' : '▼' ?><?= abs($dt) ?></span><?php endif; ?></span>
            <span><?= $r['TrangThai'] === 'Bị khóa' ? '<span class="ea-nhan khoa">Bị khóa</span>' : '<span class="ea-nhan ok">Hoạt động</span>' ?></span>
          </a>
        <?php endforeach; ?>
      </div>

    <?php else: ?>
      <div class="ea-bang ea-bd">
        <div class="ea-dong ea-dau"><span>NGƯỜI DÙNG</span><span>MÔN</span><span>ELO ĐẦU KỲ → CUỐI KỲ</span><span>THAY ĐỔI</span><span>SỐ TRẬN TRONG KỲ</span><span>TRẠNG THÁI</span></div>
        <?php foreach ($ds as $r): $t = (int) $r['tong']; ?>
          <div class="ea-dong">
            <div class="ea-nd">
              <span class="ea-ava" style="background:<?= $mauAva[(int) $r['MaND'] % count($mauAva)] ?>"><?= e($chuCai($r['HoTen'])) ?></span>
              <div><a class="ea-ten" href="<?= $chiTiet((int) $r['MaND']) ?>"><?= e($r['HoTen']) ?></a><div class="ea-mail"><?= e($r['Email']) ?></div></div>
            </div>
            <span><?= e($r['TenMon']) ?></span>
            <span><span class="ea-elo"><?= (int) $r['elo_dau'] ?></span> <span class="ea-mui">→</span> <span class="ea-elo"><?= (int) $r['elo_cuoi'] ?></span></span>
            <span><span class="ea-chip <?= $t > 0 ? 'len' : 'xuong' ?>"><?= $t > 0 ? '+' . $t : (string) $t ?></span></span>
            <span><?= (int) $r['so_tran'] ?></span>
            <span><?= $r['TrangThai'] === 'Bị khóa' ? '<span class="ea-nhan khoa">Bị khóa</span>' : '<span class="ea-nhan ok">Hoạt động</span>' ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <?php if ($p): ?><div class="ea-pt"><?= $p->links() ?></div><?php endif; ?>
  </div>

  <?php if ($v === 'bxh' && $chon): $bc = $bacCua((int) $chon['elo']); $tlc = (int) $chon['SoTran'] > 0 ? e($ty((int) $chon['SoThang'], (int) $chon['SoTran'])) : '—'; ?>
  <aside class="ea-ben">
    <div class="ea-khoi ea-hoso">
      <span class="ea-ava lon" style="background:<?= $mauAva[(int) $chon['MaND'] % count($mauAva)] ?>"><?= e($chuCai($chon['HoTen'])) ?></span>
      <div class="ea-hoso-ten"><?= e($chon['HoTen']) ?></div>
      <div class="ea-mail"><?= e($chon['Email']) ?></div>
      <div class="ea-so-lon"><?= (int) $chon['elo'] ?></div>
      <div class="ea-bac giua"><i class="ea-ngoc <?= e($bc['cls']) ?>"></i><?= e($bc['ten']) ?> · hạng #<?= $hangChon ?></div>
      <div class="ea-kv">
        <div><b><?= (int) $chon['SoTran'] ?></b><small>TRẬN</small></div>
        <div><b><?= (int) $chon['SoThang'] ?></b><small>THẮNG</small></div>
        <div><b><?= $tlc ?></b><small>TỶ LỆ</small></div>
      </div>
      <?php if ($diemElo): ?>
        <div class="ea-xuhuong"><canvas id="bd-xuhuong"></canvas></div>
        <div class="ea-mail ea-trai">Elo 30 ngày gần nhất · môn <?= e($tenMonXu) ?></div>
      <?php else: ?>
        <div class="ea-mail ea-trai ea-cach">Chưa đủ dữ liệu trận trong 30 ngày để vẽ biểu đồ.</div>
      <?php endif; ?>
      <a class="ea-xem" href="<?= $chiTiet((int) $chon['MaND']) ?>">Xem chi tiết người dùng →</a>
    </div>
  </aside>
  <?php endif; ?>
  </div>
</div>
<?php if ($v === 'bxh'):
  $duLieuElo = [
      'bac'     => array_map(static fn ($b) => ['ten' => $b['ten'], 'so' => $phanBo[$b['cls']] ?? 0, 'mau' => $b['mau']], $bacRank),
      'pho'     => $phoElo,
      'top'     => [
          'ten' => array_map(static fn ($r) => $r['HoTen'], $top5),
          'elo' => array_map(static fn ($r) => (int) $r['elo'], $top5),
          'mau' => array_map(static fn ($r) => $bacCua((int) $r['elo'])['mau'], $top5),
      ],
      'xuhuong' => $diemElo,
  ]; ?>
<!-- Hộp thoại xuất bảng xếp hạng Excel/PDF -->
<div class="modal-nen" id="exXuatHop" hidden>
  <form class="modal xuat-hop ex-xuat-hop" id="exXuatForm" method="post" action="<?= e(BASE_URL) ?>/admin/elo_xuat.php" role="dialog" aria-modal="true" aria-labelledby="exXuatTieuDe">
    <h3 id="exXuatTieuDe">Xuất bảng xếp hạng Elo</h3>
    <?= csrf_field() ?>
    <div class="xuat-luoi">
      <div class="xuat-chonCot">
        <fieldset>
          <legend>Bảng xếp hạng nào?</legend>
          <select name="mon" class="ex-chon-mon">
            <option value="0"<?= $mon === 0 ? ' selected' : '' ?>>Toàn trường</option>
            <?php foreach ($monHoc as $m): ?>
              <option value="<?= (int) $m['MaMon'] ?>"<?= $mon === (int) $m['MaMon'] ? ' selected' : '' ?>><?= e($m['TenMon']) ?></option>
            <?php endforeach; ?>
          </select>
          <p class="xuat-ghichu">Mặc định là bảng đang xem. Toàn trường chỉ tính Elo trung bình các môn đã đủ <?= (int) $nMin ?> trận.</p>
        </fieldset>
        <fieldset>
          <legend>Xuất bao nhiêu người?</legend>
          <label class="xuat-chon"><input type="radio" name="top" value="0" checked> Toàn bộ bảng xếp hạng</label>
          <label class="xuat-chon"><input type="radio" name="top" value="10"> Top 10</label>
          <label class="xuat-chon"><input type="radio" name="top" value="50"> Top 50</label>
          <label class="xuat-chon"><input type="radio" name="top" value="100"> Top 100</label>
        </fieldset>
        <fieldset>
          <legend>Nội dung xuất</legend>
          <label class="xuat-chon"><input type="checkbox" name="noidung[]" value="ds" checked> Bảng xếp hạng <span class="xuat-phu">(bảng)</span></label>
          <label class="xuat-chon"><input type="checkbox" name="noidung[]" value="bac" checked> Sơ đồ phân bố theo bậc</label>
          <label class="xuat-chon"><input type="checkbox" name="noidung[]" value="pho" checked> Sơ đồ phân bố điểm Elo</label>
        </fieldset>
        <fieldset id="exCotBox">
          <legend>Chọn cột của bảng</legend>
          <?php foreach ([
              'hang' => 'Hạng', 'ten' => 'Họ tên', 'email' => 'Email', 'bac' => 'Bậc',
              'elo' => 'Elo', 'sotran' => 'Số trận', 'tyle' => 'Tỷ lệ thắng', 'tt' => 'Trạng thái',
          ] as $k => $ten): ?>
            <label class="xuat-chon"><input type="checkbox" name="truong[]" value="<?= e($k) ?>" checked> <?= e($ten) ?></label>
          <?php endforeach; ?>
          <div class="ex-xuat-nut">
            <button type="button" class="btn btn-phu xuat-tatca" id="exChonHet">Chọn tất cả</button>
            <button type="button" class="btn btn-phu xuat-tatca" id="exBoChon">Bỏ chọn</button>
          </div>
        </fieldset>
        <fieldset>
          <legend>Định dạng</legend>
          <label class="xuat-chon"><input type="radio" name="dinhdang" value="excel" checked> Excel (.xlsx)</label>
          <label class="xuat-chon"><input type="radio" name="dinhdang" value="pdf"> PDF</label>
          <label class="xuat-chon"><input type="radio" name="dinhdang" value="ca"> Cả hai (tải liên tiếp 2 file)</label>
        </fieldset>
      </div>
      <div class="xuat-xemCot">
        <div class="xuat-xemDau">Xem trước <small id="exXemGhiChu"></small></div>
        <div class="xuat-xem" id="exXem"><p class="xuat-ghichu">Đang tải...</p></div>
      </div>
    </div>
    <p class="xuat-loi" id="exXuatLoi" hidden></p>
    <div class="hanh-dong">
      <button type="button" class="btn btn-phu" id="exXuatHuy">Hủy</button>
      <button type="submit" class="btn" id="exXuatOk">Xuất</button>
    </div>
  </form>
</div>
<script src="<?= e(BASE_URL) ?>/assets/vendor/chart.umd.js"></script>
<script id="du-lieu-elo" type="application/json"><?= json_encode($duLieuElo, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif; ?>
<?php require __DIR__ . '/layout/footer.php'; ?>