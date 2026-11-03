<?php
// Chi tiết trận đấu và hủy trận (UC14: A-24, A-26, A-27).
// Đặt tại: elo_quiz/admin/trandau_chitiet.php   (mở bằng ?id=<MaTran>)
// A-27: không có chức năng sửa kết quả hoặc điểm; trận đã kết thúc hoặc đã hủy thì chỉ xem.
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/ThiDau.php';
Auth::requireAdmin(BASE_URL . '/admin/login.php');   // A-04

$pdo = Database::pdo();
$id  = (int) ($_GET['id'] ?? 0);

$tieuDe  = 'Chi tiết trận đấu';
$mucChon = 'trandau';

$laySt = $pdo->prepare(
    'SELECT t.MaTran, t.MaMon, m.TenMon, k.TenLoaiKT, t.TrangThai, t.LyDoKetThuc, t.ThoiGianBatDau, t.ThoiGianKetThuc
       FROM TRANDAU t
       JOIN MONHOC m ON m.MaMon = t.MaMon
       JOIN LOAIKYTHI k ON k.MaLoaiKT = t.MaLoaiKT
      WHERE t.MaTran = ?'
);
$laySt->execute([$id]);
$tran = $laySt->fetch();

if (!$tran) {
    http_response_code(404);
    $cssTrang = 'trandau_chitiet';
    $jsTrang  = 'trandau_chitiet';
    require __DIR__ . '/layout/header.php';
    echo '<h2>Chi tiết trận đấu</h2><div class="card">Không tìm thấy trận này. '
        . '<a href="' . e(BASE_URL) . '/admin/trandau.php">Về danh sách</a></div>';
    require __DIR__ . '/layout/footer.php';
    exit;
}

// ---- A-26: hủy trận chưa kết thúc. Bắt buộc có lý do; hủy và ghi nhật ký trong cùng một giao dịch ----
$loi  = '';
$lyDo = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if ((string) ($_POST['hanh_dong'] ?? '') === 'huy') {
        $lyDo = preg_replace('/^(.{300}).+$/su', '$1', trim((string) ($_POST['ly_do'] ?? '')));
        if ($lyDo === '') {
            $loi = 'Hãy nhập lý do hủy trận.';
        } else {
            $pdo->beginTransaction();
            try {
                // ThiDau::huyBoiQuanTri khóa dòng TRANDAU trước nên không xung đột với việc trận vừa kết thúc
                $daHuy = ThiDau::huyBoiQuanTri($pdo, $id);
                if ($daHuy) {
                    NhatKy::ghi('Hủy trận', 'TRANDAU #' . $id, 'Lý do: ' . $lyDo);
                }
                $pdo->commit();
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $ex;
            }
            redirect(BASE_URL . '/admin/trandau_chitiet.php?id=' . $id . '&kq=' . ($daHuy ? 'huy' : 'khonghuy'));
        }
    }
}
$thongBao = ['huy' => 'Đã hủy trận. Elo của hai người chơi không thay đổi.'][$_GET['kq'] ?? ''] ?? '';
$canhBao  = ($_GET['kq'] ?? '') === 'khonghuy' ? 'Không hủy được: trận đã kết thúc hoặc đã bị hủy trước đó (có thể vừa kết thúc cùng lúc).' : '';

// ---- Hai người chơi ----
$st = $pdo->prepare(
    'SELECT p.MaND, n.HoTen, n.Email, p.DiemSo, p.KetQua, p.EloTruoc, p.EloSau, p.TrangThaiThamGia, p.LanHoatDongCuoi
       FROM NGUOICHOITRANDAU p JOIN NGUOIDUNG n ON n.MaND = p.MaND
      WHERE p.MaTran = ? ORDER BY p.MaND'
);
$st->execute([$id]);
$nguoi = $st->fetchAll();

// ---- Từng câu hỏi của trận (theo thứ tự) và câu trả lời của từng người ----
$st = $pdo->prepare(
    "SELECT c.MaCauHoi, c.ThuTu, c.DiemCoBan, c.HeSoThuong, c.ThoiGianQuyDinh, q.NoiDung,
            (SELECT d.NoiDungDapAn FROM DAPAN d WHERE d.MaCauHoi = q.MaCauHoi AND d.LaDapAnDung = 1 LIMIT 1) AS dap_an_dung
       FROM CAUHOITRANDAU c JOIN CAUHOI q ON q.MaCauHoi = c.MaCauHoi
      WHERE c.MaTran = ? ORDER BY c.ThuTu"
);
$st->execute([$id]);
$cauHoi = $st->fetchAll();

$st = $pdo->prepare(
    'SELECT t.MaND, t.MaCauHoi, t.TrangThai, t.ThoiGianTraLoi, t.DiemNhanDuoc, d.NoiDungDapAn, d.LaDapAnDung
       FROM CHITIETTRALOI t LEFT JOIN DAPAN d ON d.MaDapAn = t.MaDapAn
      WHERE t.MaTran = ?'
);
$st->execute([$id]);
$traLoi = [];
foreach ($st as $r) {
    $traLoi[(int) $r['MaCauHoi']][(int) $r['MaND']] = $r;
}

// ---- Lý do hủy do quản trị viên nhập (nằm trong nhật ký quản trị) ----
$nhatKyHuy = null;
if ($tran['LyDoKetThuc'] === 'Quản trị viên hủy') {
    $st = $pdo->prepare(
        "SELECT l.MoTa, l.ThoiGian, a.HoTen
           FROM NHATKYQUANTRI l JOIN ADMIN a ON a.MaAdmin = l.MaAdmin
          WHERE l.HanhDong = 'Hủy trận' AND l.DoiTuong = ? ORDER BY l.MaNhatKy DESC LIMIT 1"
    );
    $st->execute(['TRANDAU #' . $id]);
    $nhatKyHuy = $st->fetch() ?: null;
}

$chuaKetThuc = in_array($tran['TrangThai'], ['Đã ghép', 'Đang diễn ra'], true);
$fmt = static fn (?string $t): string => $t ? date('d/m/Y H:i:s', strtotime($t)) : '—';

$cssTrang = 'trandau_chitiet';
$jsTrang  = 'trandau_chitiet';
require __DIR__ . '/layout/header.php';
?>
<h2>Trận #<?= (int) $tran['MaTran'] ?></h2>
<p><a href="<?= e(BASE_URL) ?>/admin/trandau.php">&laquo; Về danh sách trận</a></p>

<?php if ($thongBao !== ''): ?><div class="thanhcong"><?= e($thongBao) ?></div><?php endif; ?>
<?php if ($canhBao !== ''): ?><div class="loi"><?= e($canhBao) ?></div><?php endif; ?>

<div class="card">
  <table>
    <tr><th class="cot-nhan">Môn / loại kỳ thi</th><td><?= e($tran['TenMon']) ?> / <?= e($tran['TenLoaiKT']) ?></td></tr>
    <tr><th>Trạng thái</th><td><?= e($tran['TrangThai']) ?></td></tr>
    <tr><th>Lý do kết thúc</th><td><?= e($tran['LyDoKetThuc'] ?? '—') ?></td></tr>
    <?php if ($nhatKyHuy): ?>
      <tr><th>Ghi chú hủy</th><td><?= e($nhatKyHuy['MoTa']) ?> <span class="nhan-phu">(<?= e($nhatKyHuy['HoTen']) ?>, <?= e($fmt($nhatKyHuy['ThoiGian'])) ?>)</span></td></tr>
    <?php endif; ?>
    <tr><th>Bắt đầu</th><td><?= e($fmt($tran['ThoiGianBatDau'])) ?></td></tr>
    <tr><th>Kết thúc</th><td><?= e($fmt($tran['ThoiGianKetThuc'])) ?></td></tr>
  </table>
</div>

<h3>Người chơi</h3>
<div class="card">
  <table>
    <tr><th>Người chơi</th><th>Điểm</th><th>Kết quả</th><th>Elo trước</th><th>Elo sau</th><th>Thay đổi</th><th>Tham gia</th><th>Hoạt động cuối</th></tr>
    <?php foreach ($nguoi as $n):
        $doi = ($n['EloTruoc'] !== null && $n['EloSau'] !== null) ? (int) $n['EloSau'] - (int) $n['EloTruoc'] : null; ?>
      <tr>
        <td><a href="<?= e(BASE_URL) ?>/admin/nguoidung_chitiet.php?id=<?= (int) $n['MaND'] ?>"><?= e($n['HoTen']) ?></a><br><span class="nhan-phu"><?= e($n['Email']) ?></span></td>
        <td><?= (int) $n['DiemSo'] ?></td>
        <td><?= e($n['KetQua'] ?? '—') ?></td>
        <td><?= $n['EloTruoc'] !== null ? (int) $n['EloTruoc'] : '—' ?></td>
        <td><?= $n['EloSau'] !== null ? (int) $n['EloSau'] : '—' ?></td>
        <td><?= $doi === null ? '—' : ($doi > 0 ? '+' . $doi : (string) $doi) ?></td>
        <td><?= e($n['TrangThaiThamGia']) ?></td>
        <td><?= e($fmt($n['LanHoatDongCuoi'])) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<h3>Từng câu hỏi</h3>
<div class="card">
  <?php if (!$cauHoi): ?>
    <p class="nhan-phu">Trận này chưa có câu hỏi.</p>
  <?php else: ?>
    <table>
      <tr>
        <th>#</th><th>Câu hỏi / đáp án đúng</th>
        <?php foreach ($nguoi as $n): ?><th><?= e($n['HoTen']) ?></th><?php endforeach; ?>
      </tr>
      <?php foreach ($cauHoi as $c): ?>
        <tr>
          <td><?= (int) $c['ThuTu'] ?></td>
          <td>
            <?= e($c['NoiDung']) ?><br>
            <span class="nhan-phu">Đúng: <?= e($c['dap_an_dung'] ?? '—') ?> · <?= (int) $c['ThoiGianQuyDinh'] ?> giây · <?= (int) $c['DiemCoBan'] ?> + tối đa <?= (int) $c['HeSoThuong'] ?> điểm</span>
          </td>
          <?php foreach ($nguoi as $n):
              $r = $traLoi[(int) $c['MaCauHoi']][(int) $n['MaND']] ?? null; ?>
            <td>
              <?php if (!$r): ?>
                <span class="nhan-phu">Chưa có dữ liệu</span>
              <?php elseif ($r['TrangThai'] === 'Đã trả lời'): ?>
                <?= e($r['NoiDungDapAn']) ?><br>
                <span class="dam <?= (int) $r['LaDapAnDung'] === 1 ? 'mau-xanh' : 'mau-do' ?>">
                  <?= (int) $r['LaDapAnDung'] === 1 ? 'Đúng' : 'Sai' ?>
                </span>
                <span class="nhan-phu"> · <?= e(rtrim(rtrim((string) $r['ThoiGianTraLoi'], '0'), '.')) ?>s · <?= (int) $r['DiemNhanDuoc'] ?> điểm</span>
              <?php else: ?>
                <span class="nhan-phu"><?= e($r['TrangThai']) ?> · 0 điểm</span>
              <?php endif; ?>
            </td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<h3>Hủy trận</h3>
<div class="card">
  <?php if ($chuaKetThuc): ?>
    <?php if ($loi !== ''): ?><div class="loi"><?= e($loi) ?></div><?php endif; ?>
    <p class="nhan-phu">Chỉ hủy khi trận bị lỗi. Trận bị hủy không đổi Elo và không thể khôi phục. Thao tác được ghi vào nhật ký quản trị.</p>
    <form method="post" action="" data-xac-nhan="Hủy trận #<?= (int) $tran['MaTran'] ?>? Thao tác này không thể hoàn tác.">
      <?= csrf_field() ?>
      <input type="hidden" name="hanh_dong" value="huy">
      <label for="ly_do">Lý do hủy (bắt buộc)</label>
      <textarea id="ly_do" name="ly_do" rows="3" maxlength="300" required><?= e($lyDo) ?></textarea>
      <p><button type="submit" class="btn btn-do-dam">Hủy trận</button></p>
    </form>
  <?php else: ?>
    <p class="nhan-phu">Trận đã <?= $tran['TrangThai'] === 'Hủy' ? 'bị hủy' : 'kết thúc' ?> nên không thể hủy, và kết quả hoặc điểm cũng không được sửa.</p>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>