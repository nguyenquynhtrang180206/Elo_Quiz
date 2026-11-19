<?php
// Chi tiết một trận đấu + hủy trận (UC14: A-24, A-26).
// Đặt tại: elo_quiz/admin/trandau_chitiet.php?id=<MaTran>. Danh sách: admin/trandau.php
require_once __DIR__ . '/../core/bootstrap.php';
Auth::requireAdmin(BASE_URL . '/admin/dangnhap.php');   // A-04

$pdo = Database::pdo();
$id  = (int) ($_GET['id'] ?? 0);

$coCotTao = TranDauGiamSat::coCotTao($pdo);
$cotTao   = $coCotTao ? 'TIMESTAMPDIFF(SECOND, t.ThoiGianTao, NOW(3))' : 'NULL';

$st = $pdo->prepare(
    "SELECT t.MaTran, t.TrangThai, t.LyDoKetThuc, t.ThoiGianBatDau, t.ThoiGianKetThuc, t.DotBien,
            m.TenMon, k.TenLoaiKT,
            TIMESTAMPDIFF(SECOND, t.ThoiGianBatDau, NOW(3)) AS giay_dien_ra,
            TIMESTAMPDIFF(SECOND, t.ThoiGianBatDau, t.ThoiGianKetThuc) AS giay_tong,
            $cotTao AS giay_da_ghep
       FROM TRANDAU t
       JOIN MONHOC m ON m.MaMon = t.MaMon
       JOIN LOAIKYTHI k ON k.MaLoaiKT = t.MaLoaiKT
      WHERE t.MaTran = ?"
);
$st->execute([$id]);
$tran = $st->fetch();

$tieuDe  = 'Chi tiết trận đấu';
$mucChon = 'trandau';
$cssTrang = ['trandau', 'trandau_chitiet'];

if (!$tran) {
    require __DIR__ . '/layout/header.php';
    echo '<div class="td"><p>Không tìm thấy trận này. <a href="' . e(BASE_URL) . '/admin/trandau.php">Về danh sách trận</a></p></div>';
    require __DIR__ . '/layout/footer.php';
    exit;
}

// ---- A-26: hủy trận chưa kết thúc. Hủy và ghi nhật ký nằm chung một giao dịch; bắt buộc có lý do ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if ((string) ($_POST['hanh_dong'] ?? '') === 'huy') {
        $lyDo = preg_replace('/^(.{300}).+$/su', '$1', trim((string) ($_POST['ly_do'] ?? '')));
        if ($lyDo === '') {
            redirect(BASE_URL . '/admin/trandau_chitiet.php?id=' . $id . '&kq=thieuly');
        }
        $pdo->beginTransaction();
        try {
            $daHuy = ThiDau::huyBoiQuanTri($pdo, $id);
            if ($daHuy) {
                NhatKy::ghi('Hủy trận đấu', 'TRANDAU #' . $id, 'Lý do: ' . $lyDo);
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }
        redirect(BASE_URL . '/admin/trandau_chitiet.php?id=' . $id . '&kq=' . ($daHuy ? 'huy' : 'khonghuy'));
    }
}

$kq = (string) ($_GET['kq'] ?? '');
$thongBao = ['huy' => 'Đã hủy trận và ghi vào nhật ký quản trị.'][$kq] ?? '';
$canhBao  = [
    'thieuly'  => 'Cần nhập lý do hủy trận.',
    'khonghuy' => 'Trận đã kết thúc hoặc đã bị hủy trước đó nên không thể hủy nữa.',
][$kq] ?? '';

// ---- Người chơi ----
$st = $pdo->prepare(
    'SELECT p.MaND, n.HoTen, n.Email, p.DiemSo, p.KetQua, p.EloTruoc, p.EloSau, p.TrangThaiThamGia
       FROM NGUOICHOITRANDAU p JOIN NGUOIDUNG n ON n.MaND = p.MaND
      WHERE p.MaTran = ? ORDER BY p.MaND'
);
$st->execute([$id]);
$nguoi = $st->fetchAll();

// ---- Câu hỏi và bài làm từng người ----
$st = $pdo->prepare(
    'SELECT c.ThuTu, c.MaCauHoi, q.NoiDung, c.DiemCoBan, c.ThoiGianQuyDinh
       FROM CAUHOITRANDAU c JOIN CAUHOI q ON q.MaCauHoi = c.MaCauHoi
      WHERE c.MaTran = ? ORDER BY c.ThuTu'
);
$st->execute([$id]);
$cauHoi = $st->fetchAll();

$st = $pdo->prepare(
    'SELECT r.MaND, r.MaCauHoi, r.TrangThai, r.ThoiGianTraLoi, r.DiemNhanDuoc, COALESCE(d.LaDapAnDung, 0) AS dung
       FROM CHITIETTRALOI r LEFT JOIN DAPAN d ON d.MaDapAn = r.MaDapAn
      WHERE r.MaTran = ?'
);
$st->execute([$id]);
$bai = [];
foreach ($st->fetchAll() as $r) {
    $bai[(int) $r['MaCauHoi']][(int) $r['MaND']] = $r;
}

$nguongGiay = TranDauGiamSat::nguongGiay();
$nhanBt     = TranDauGiamSat::nhan($tran, $nguongGiay);
$dangChay   = in_array($tran['TrangThai'], ['Đã ghép', 'Đang diễn ra'], true);
$lopTT      = ['Đã ghép' => 'tt-daghep', 'Đang diễn ra' => 'tt-dangdau', 'Kết thúc' => 'tt-ketthuc', 'Hủy' => 'tt-huy'];
$dbK        = DotBien::layDotBien($tran['DotBien'] ?? 'chuan');
$ngay       = static fn ($v) => $v ? date('d/m/Y H:i:s', strtotime($v)) : '—';
$chuCai     = static function (string $ten): string {
    $t = preg_split('/\s+/u', trim($ten));
    return mb_strtoupper(mb_substr((string) end($t), 0, 1)) ?: '?';
};

require __DIR__ . '/layout/header.php';
?>
<div class="td">
  <nav class="td-duongdan" aria-label="Đường dẫn">
    <a href="<?= e(BASE_URL) ?>/admin/index.php" title="Tổng quan" aria-label="Tổng quan"><?= $svg('dashboard', 18) ?></a>
    <span class="td-cach">/</span>
    <a href="<?= e(BASE_URL) ?>/admin/trandau.php">Trận đấu</a>
    <span class="td-cach">/</span>
    <span>#<?= (int) $tran['MaTran'] ?></span>
  </nav>

  <?php if ($thongBao !== ''): ?><div class="thanhcong"><?= e($thongBao) ?></div><?php endif; ?>
  <?php if ($canhBao !== ''): ?><div class="loi"><?= e($canhBao) ?></div><?php endif; ?>

  <div class="td-tieude tc-dau">
    <div>
      <h2>Trận #<?= (int) $tran['MaTran'] ?>
        <span class="dam nhan-trang-thai <?= e($lopTT[$tran['TrangThai']] ?? '') ?>"><?= e($tran['TrangThai']) ?></span>
      </h2>
      <p class="nhan-phu"><?= e($tran['TenMon']) ?> · <?= e($tran['TenLoaiKT']) ?>
        <?php if (($dbK['id'] ?? 'chuan') !== 'chuan'): ?>
          · <i class="td-db" style="color:<?= e($dbK['mau']) ?>;background:<?= e($dbK['mau']) ?>1a;border-color:<?= e($dbK['mau']) ?>44;"><?= e($dbK['icon']) ?> <?= e($dbK['ten']) ?></i>
        <?php endif; ?>
      </p>
    </div>
    <a class="td-xem" href="<?= e(BASE_URL) ?>/admin/trandau.php">← Về danh sách</a>
  </div>

  <div class="tc-luoi">
    <!-- Bảng điểm đối đầu -->
    <section class="tc-the">
      <h3 class="tc-tieu">Đối đầu</h3>
      <?php if (count($nguoi) === 2): ?>
        <div class="tc-vs">
          <?php foreach ($nguoi as $i => $x):
            $lop = $x['TrangThaiThamGia'] === 'Bỏ trận' ? 'td-bo' : ($x['KetQua'] === 'Thắng' ? 'td-thang' : ($x['KetQua'] === 'Thua' ? 'td-thua' : ($x['KetQua'] === 'Hòa' ? 'td-hoa' : '')));
            $ket = $x['TrangThaiThamGia'] === 'Bỏ trận' ? 'Bỏ trận' : (string) $x['KetQua'];
            $delta = ($x['EloTruoc'] !== null && $x['EloSau'] !== null) ? (int) $x['EloSau'] - (int) $x['EloTruoc'] : null;
          ?>
            <?php if ($i === 1): ?><div class="tc-diem"><?= (int) $nguoi[0]['DiemSo'] ?><span>:</span><?= (int) $nguoi[1]['DiemSo'] ?></div><?php endif; ?>
            <div class="tc-ng <?= $lop ?>">
              <span class="td-ava tc-ava"><?= e($chuCai($x['HoTen'])) ?></span>
              <b><?= e($x['HoTen']) ?></b>
              <small><?= e($x['Email']) ?></small>
              <?php if ($ket !== ''): ?><span class="td-ket"><?= e($ket) ?></span><?php endif; ?>
              <?php if ($delta !== null): ?>
                <span class="tc-elo">Elo <?= (int) $x['EloTruoc'] ?> → <?= (int) $x['EloSau'] ?> <i class="<?= $delta >= 0 ? 'tang' : 'giam' ?>"><?= $delta >= 0 ? '+' : '' ?><?= $delta ?></i></span>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="nhan-phu">Trận này không có đủ hai người chơi.</p>
      <?php endif; ?>

      <dl class="tc-tt">
        <div><dt>Bắt đầu</dt><dd><?= e($ngay($tran['ThoiGianBatDau'])) ?></dd></div>
        <div><dt>Kết thúc</dt><dd><?= e($ngay($tran['ThoiGianKetThuc'])) ?></dd></div>
        <div><dt>Thời lượng</dt><dd><?= e(TranDauGiamSat::thoiLuong($tran['giay_tong'] ?? ($dangChay ? $tran['giay_dien_ra'] : null))) ?></dd></div>
        <div><dt>Lý do kết thúc</dt><dd><?= e($tran['LyDoKetThuc'] ?: '—') ?></dd></div>
      </dl>
    </section>

    <!-- Thao tác quản trị -->
    <aside class="tc-the tc-thaotac">
      <h3 class="tc-tieu">Thao tác</h3>
      <?php if ($nhanBt !== null): ?>
        <p class="tc-co">&#9888; <?= e($nhanBt) ?></p>
      <?php endif; ?>
      <?php if ($dangChay): ?>
        <p class="nhan-phu">Trận chưa kết thúc. Nếu trận đã treo thật, hủy trận để giải phóng hai người chơi. Hủy trận không làm đổi Elo.</p>
        <form method="post" action="" class="tc-huy" data-xacnhan="Hủy trận #<?= (int) $tran['MaTran'] ?>? Thao tác này không thể hoàn tác.">
          <?= csrf_field() ?>
          <input type="hidden" name="hanh_dong" value="huy">
          <textarea name="ly_do" rows="3" maxlength="300" required placeholder="Lý do hủy (bắt buộc)"></textarea>
          <button type="submit" class="td-nut td-nut-do td-nut-dac">Hủy trận</button>
        </form>
      <?php else: ?>
        <p class="nhan-phu">Trận đã <?= $tran['TrangThai'] === 'Hủy' ? 'bị hủy' : 'kết thúc' ?>, không còn thao tác nào.</p>
      <?php endif; ?>
    </aside>
  </div>

  <!-- Bài làm từng câu -->
  <section class="td-ds">
    <div class="td-ds-dau"><h3>Bài làm từng câu</h3><span class="nhan-phu"><?= count($cauHoi) ?> câu</span></div>
    <?php if (!$cauHoi): ?>
      <div class="td-rong"><p>Trận chưa có câu hỏi nào.</p></div>
    <?php else: ?>
      <div class="td-bang-cuon">
        <table class="td-bang tc-bang">
          <thead>
            <tr><th>#</th><th>Câu hỏi</th>
              <?php foreach ($nguoi as $x): ?><th><?= e($x['HoTen']) ?></th><?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($cauHoi as $c): ?>
            <tr>
              <td class="tc-stt"><b><?= (int) $c['ThuTu'] ?></b></td>
              <td class="tc-cau"><?= e(mb_strimwidth(strip_tags((string) $c['NoiDung']), 0, 110, '…')) ?>
                <span class="nhan-phu"><?= (int) $c['DiemCoBan'] ?> điểm · <?= (int) $c['ThoiGianQuyDinh'] ?>s</span></td>
              <?php foreach ($nguoi as $x):
                $r = $bai[(int) $c['MaCauHoi']][(int) $x['MaND']] ?? null; ?>
                <td class="tc-bai" data-label="<?= e($x['HoTen']) ?>">
                  <?php if (!$r || $r['TrangThai'] === 'Không làm'): ?>
                    <span class="nhan-phu">—</span>
                  <?php elseif ($r['TrangThai'] === 'Hết giờ'): ?>
                    <span class="tc-kq tc-het">Hết giờ</span>
                  <?php else: ?>
                    <span class="tc-kq <?= $r['dung'] ? 'tc-dung' : 'tc-sai' ?>"><?= $r['dung'] ? 'Đúng' : 'Sai' ?></span>
                    <span class="nhan-phu"><?= e(number_format((float) $r['ThoiGianTraLoi'], 1, ',', '')) ?>s · +<?= (int) $r['DiemNhanDuoc'] ?></span>
                  <?php endif; ?>
                </td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>