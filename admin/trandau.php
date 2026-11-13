<?php
// Danh sách và tra cứu trận đấu, danh sách trận bất thường (UC14: A-23, A-25).
// Đặt tại: elo_quiz/admin/trandau.php. Chi tiết và hủy trận: admin/trandau_chitiet.php
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/DotBien.php';
Auth::requireAdmin(BASE_URL . '/admin/dangnhap.php');   // A-04

$pdo = Database::pdo();

// Cột ThoiGianTao (xem sql/trandau_thoigiantao.sql) giúp phát hiện trận "Đã ghép" bị treo.
// Chưa chạy file SQL đó thì trang vẫn chạy, chỉ không đo được thời gian chờ của trận "Đã ghép".
$coCotTao = (bool) $pdo->query("SHOW COLUMNS FROM TRANDAU LIKE 'ThoiGianTao'")->fetch();

// ---- Bộ lọc: mã trận / người chơi, môn, trạng thái, chỉ trận bất thường ----
$q   = trim((string) ($_GET['q'] ?? ''));
$mon = (int) ($_GET['mon'] ?? 0);
$tt  = (string) ($_GET['tt'] ?? '');
$bt  = isset($_GET['bt']) && $_GET['bt'] === '1';
if (!in_array($tt, ['Đã ghép', 'Đang diễn ra', 'Kết thúc', 'Hủy'], true)) {
    $tt = '';
}

$nguongPhut = max(1, Config::int('NGUONG_TRAN_KEO_DAI', 10));
$nguongGiay = $nguongPhut * 60;

$dieuKien = [];
$tham     = [];
if ($q !== '') {
    $maTran = ctype_digit(ltrim($q, '#')) ? (int) ltrim($q, '#') : 0;
    $like   = '%' . addcslashes($q, '\\%_') . '%';
    $dieuKien[] = '(t.MaTran = ? OR EXISTS (
        SELECT 1 FROM NGUOICHOITRANDAU p JOIN NGUOIDUNG n ON n.MaND = p.MaND
         WHERE p.MaTran = t.MaTran AND (n.HoTen LIKE ? OR n.Email LIKE ?)))';
    array_push($tham, $maTran, $like, $like);
}
if ($mon > 0) {
    $dieuKien[] = 't.MaMon = ?';
    $tham[]     = $mon;
}
if ($tt !== '') {
    $dieuKien[] = 't.TrangThai = ?';
    $tham[]     = $tt;
}
if ($bt) {
    // A-25: hủy do lỗi, có người bị xử thua, hoặc ở trạng thái chưa kết thúc quá ngưỡng thời gian cấu hình
    $bd = "(t.LyDoKetThuc IN ('Hủy do lỗi hệ thống', 'Đối thủ bỏ trận (xử thua)')
            OR (t.TrangThai = 'Đang diễn ra' AND TIMESTAMPDIFF(SECOND, t.ThoiGianBatDau, NOW(3)) > ?)";
    $tham[] = $nguongGiay;
    if ($coCotTao) {
        $bd    .= " OR (t.TrangThai = 'Đã ghép' AND TIMESTAMPDIFF(SECOND, t.ThoiGianTao, NOW(3)) > ?)";
        $tham[] = $nguongGiay;
    }
    $dieuKien[] = $bd . ')';
}
$where = $dieuKien ? 'WHERE ' . implode(' AND ', $dieuKien) : '';

$cotTao = $coCotTao ? ', TIMESTAMPDIFF(SECOND, t.ThoiGianTao, NOW(3)) AS giay_da_ghep' : ', NULL AS giay_da_ghep';

// ---- Danh sách có phân trang (20 dòng/trang, có tổng số bản ghi) ----
$st = $pdo->prepare("SELECT COUNT(*) FROM TRANDAU t $where");
$st->execute($tham);
$p = new Paginator((int) $st->fetchColumn(), 20);

$st = $pdo->prepare(
    "SELECT t.MaTran, m.TenMon, k.TenLoaiKT, t.TrangThai, t.LyDoKetThuc, t.ThoiGianBatDau, t.ThoiGianKetThuc, t.DotBien,
            TIMESTAMPDIFF(SECOND, t.ThoiGianBatDau, NOW(3)) AS giay_dien_ra $cotTao,
            (SELECT GROUP_CONCAT(CONCAT(n.HoTen, ' (', p.DiemSo, IF(p.KetQua IS NULL, '', CONCAT(', ', p.KetQua)), ')')
                                 ORDER BY p.MaND SEPARATOR ' vs ')
               FROM NGUOICHOITRANDAU p JOIN NGUOIDUNG n ON n.MaND = p.MaND
              WHERE p.MaTran = t.MaTran) AS nguoi
       FROM TRANDAU t
       JOIN MONHOC m ON m.MaMon = t.MaMon
       JOIN LOAIKYTHI k ON k.MaLoaiKT = t.MaLoaiKT
       $where
      ORDER BY t.MaTran DESC
      LIMIT " . $p->limit() . ' OFFSET ' . $p->offset()
);
$st->execute($tham);
$ds = $st->fetchAll();

$monHoc = $pdo->query('SELECT MaMon, TenMon FROM MONHOC ORDER BY TenMon')->fetchAll();

// Nhãn bất thường cho từng dòng (cùng tiêu chí với bộ lọc ở trên)
function nhan_bat_thuong(array $r, int $nguongGiay): ?string
{
    if ($r['LyDoKetThuc'] === 'Hủy do lỗi hệ thống') {
        return 'Hủy do lỗi';
    }
    if ($r['LyDoKetThuc'] === 'Đối thủ bỏ trận (xử thua)') {
        return 'Có người bị xử thua';
    }
    if ($r['TrangThai'] === 'Đang diễn ra' && $r['giay_dien_ra'] !== null && (int) $r['giay_dien_ra'] > $nguongGiay) {
        return 'Đang diễn ra quá lâu';
    }
    if ($r['TrangThai'] === 'Đã ghép' && $r['giay_da_ghep'] !== null && (int) $r['giay_da_ghep'] > $nguongGiay) {
        return 'Đã ghép quá lâu';
    }
    return null;
}

$lopTT = ['Đã ghép' => 'tt-daghep', 'Đang diễn ra' => 'tt-dangdau', 'Kết thúc' => 'tt-ketthuc', 'Hủy' => 'tt-huy'];

$tieuDe  = 'Trận đấu';
$mucChon = 'trandau';
$cssTrang = 'trandau';
require __DIR__ . '/layout/header.php';
?>
<div class="card adm-page-hero-card">
  <div class="adm-header-eyebrow">
    <span class="live-pulse"></span>
    <span>LIVE MATCH MONITORING & AUDIT</span>
  </div>
  <div class="adm-header-main">
    <h2 class="adm-title-pro">
      <svg class="title-svg-icon" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>
      <span>Danh sách trận đấu</span>
    </h2>
    <div class="adm-header-badge-count">
      <span class="badge-num"><?= (int) $p->tong() ?></span>
      <span class="badge-txt">trận đấu</span>
    </div>
  </div>
  <p class="adm-subtitle-pro">
    Giám sát diễn biến phòng thi đấu, theo dõi kết quả đối kháng và phát hiện các trận bất thường thời gian thực.
  </p>
</div>

<form method="get" action="" class="card form-loc khung-tran">
  <input type="text" name="q" value="<?= e($q) ?>" placeholder="Mã trận, tên hoặc email người chơi" class="o-tim">
  <select name="mon">
    <option value="0">Mọi môn</option>
    <?php foreach ($monHoc as $m): ?>
      <option value="<?= (int) $m['MaMon'] ?>"<?= $mon === (int) $m['MaMon'] ? ' selected' : '' ?>><?= e($m['TenMon']) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="tt">
    <option value="">Mọi trạng thái</option>
    <?php foreach (['Đã ghép', 'Đang diễn ra', 'Kết thúc', 'Hủy'] as $v): ?>
      <option value="<?= e($v) ?>"<?= $tt === $v ? ' selected' : '' ?>><?= e($v) ?></option>
    <?php endforeach; ?>
  </select>
  <label class="nhan-checkbox">
    <input type="checkbox" name="bt" value="1"<?= $bt ? ' checked' : '' ?>> Chỉ trận bất thường
  </label>
  <button type="submit" class="btn">Tìm</button>
  <a class="btn btn-phu" href="?">Xóa lọc</a>
</form>

<p class="nhan-phu ghi-chu-bat-thuong">
  Trận bất thường: hủy do lỗi hệ thống, có người bị xử thua, hoặc ở trạng thái chưa kết thúc quá <?= $nguongPhut ?> phút
  (ngưỡng NGUONG_TRAN_KEO_DAI trong Cấu hình hệ thống).
  <?php if (!$coCotTao): ?>
    <br>Chưa đo được thời gian chờ của trận "Đã ghép" vì bảng TRANDAU chưa có cột ThoiGianTao (xem sql/trandau_thoigiantao.sql).
  <?php endif; ?>
</p>

<div class="card khung-tran">
  <?php if (!$ds): ?>
    <p class="nhan-phu">Không có trận nào khớp.</p>
  <?php else: ?>
    <table>
      <tr><th>Mã</th><th>Môn / kỳ thi</th><th>Người chơi (điểm, kết quả)</th><th>Trạng thái</th><th>Lý do kết thúc</th><th>Bắt đầu</th><th>Kết thúc</th><th></th></tr>
      <?php foreach ($ds as $r): $nhan = nhan_bat_thuong($r, $nguongGiay); ?>
        <tr>
          <td><?= (int) $r['MaTran'] ?></td>
          <td>
            <?= e($r['TenMon']) ?><br><span class="nhan-phu"><?= e($r['TenLoaiKT']) ?></span>
            <?php
              $dbK = DotBien::layDotBien($r['DotBien'] ?? 'chuan');
              if (($dbK['id'] ?? 'chuan') !== 'chuan'): ?>
                <br><span style="display:inline-block;margin-top:3px;font-size:11px;font-weight:700;color:<?= e($dbK['mau']) ?>;background:<?= e($dbK['mau']) ?>1a;border:1px solid <?= e($dbK['mau']) ?>44;padding:1px 6px;border-radius:10px;">
                  <?= e($dbK['icon']) ?> <?= e($dbK['ten']) ?>
                </span>
            <?php endif; ?>
          </td>
          <td><?= e($r['nguoi']) ?></td>
          <td>
            <span class="dam nhan-trang-thai <?= e($lopTT[$r['TrangThai']] ?? '') ?>"><?= e($r['TrangThai']) ?></span>
            <?php if ($nhan !== null): ?><br><span class="mau-do canh-bao-nho">&#9888; <?= e($nhan) ?></span><?php endif; ?>
          </td>
          <td><?= e($r['LyDoKetThuc']) ?></td>
          <td><?= $r['ThoiGianBatDau'] ? e(date('d/m/Y H:i:s', strtotime($r['ThoiGianBatDau']))) : '' ?></td>
          <td><?= $r['ThoiGianKetThuc'] ? e(date('d/m/Y H:i:s', strtotime($r['ThoiGianKetThuc']))) : '' ?></td>
          <td><a href="<?= e(BASE_URL) ?>/admin/trandau_chitiet.php?id=<?= (int) $r['MaTran'] ?>">Chi tiết</a></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
  <?= $p->links() ?>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>