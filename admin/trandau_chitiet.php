<?php
// Chi tiết trận đấu và hủy trận (UC14: A-24, A-26, A-27).
// Đặt tại: elo_quiz/admin/trandau_chitiet.php   (mở bằng ?id=<MaTran>)
// A-27: không có chức năng sửa kết quả hoặc điểm; trận đã kết thúc hoặc đã hủy thì chỉ xem.
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/ThiDau.php';
require_once __DIR__ . '/../core/DotBien.php';
Auth::requireAdmin(BASE_URL . '/admin/dangnhap.php');   // A-04

$pdo = Database::pdo();
$id  = (int) ($_GET['id'] ?? 0);

$tieuDe  = 'Chi tiết trận đấu';
$mucChon = 'trandau';

$laySt = $pdo->prepare(
    'SELECT t.MaTran, t.MaMon, m.TenMon, k.TenLoaiKT, t.TrangThai, t.LyDoKetThuc, t.ThoiGianBatDau, t.ThoiGianKetThuc, t.DotBien
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
<div class="td-dau-trang">
  <a href="<?= e(BASE_URL) ?>/admin/trandau.php" class="td-nut-ve">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
    <span>Về danh sách trận</span>
  </a>
</div>

<div class="td-match-header">
  <div class="td-match-title-wrap">
    <div class="td-match-icon" aria-hidden="true">
      <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>
    </div>
    <div>
      <div class="adm-header-eyebrow" style="margin-bottom: 2px;">
        <span class="live-pulse"></span>
        <span>CHI TIẾT TRẬN ĐẤU & TELEMETRY</span>
      </div>
      <h2>Trận #<?= (int) $tran['MaTran'] ?></h2>
      <div class="nd-meta-row" style="margin-top: 4px;">
        <span class="nd-badge-id">Môn: <?= e($tran['TenMon']) ?></span>
        <span class="nd-meta-pill"><?= e($tran['TenLoaiKT']) ?></span>
        <span class="nd-meta-pill">Bắt đầu: <?= e($fmt($tran['ThoiGianBatDau'] ?? $tran['ThoiGianKetThuc'])) ?></span>
      </div>
    </div>
  </div>
  <div>
    <?php
      $lopTT = [
        'Đang diễn ra' => 'tt-dangdau',
        'Kết thúc'     => 'tt-ketthuc',
        'Đã ghép'      => 'tt-daghep',
        'Hủy'          => 'tt-huy',
      ][$tran['TrangThai']] ?? 'tt-ketthuc';
    ?>
    <span class="<?= $lopTT ?>" style="font-size:13px; padding:6px 14px; border-radius:8px;"><?= e($tran['TrangThai']) ?></span>
  </div>
</div>

<?php if ($thongBao !== ''): ?><div class="thanhcong"><?= e($thongBao) ?></div><?php endif; ?>
<?php if ($canhBao !== ''): ?><div class="loi"><?= e($canhBao) ?></div><?php endif; ?>

<!-- Bố cục Grid 2 cột: Thông số trận đấu (Trái) & Quản lý/Trạng thái (Phải) -->
<div class="td-grid-2cot">
  <div class="card td-card-thongtin">
    <h3 class="td-card-tieude">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
      Thông số trận đấu
    </h3>
    <div class="td-dl-grid">
      <div class="td-dl-item">
        <span class="td-dl-nhan">Môn học</span>
        <span class="td-dl-giatri"><b><?= e($tran['TenMon']) ?></b></span>
      </div>
      <div class="td-dl-item">
        <span class="td-dl-nhan">Loại kỳ thi</span>
        <span class="td-dl-giatri"><?= e($tran['TenLoaiKT']) ?></span>
      </div>
      <div class="td-dl-item">
        <span class="td-dl-nhan">Chế độ thi đấu</span>
        <span class="td-dl-giatri">
          <?php
            $dbInfo = DotBien::layDotBien($tran['DotBien'] ?? 'chuan');
            $laChuan = ($dbInfo['id'] ?? 'chuan') === 'chuan';
          ?>
          <?php if ($laChuan): ?>
            <span><?= e($dbInfo['icon']) ?> <?= e($dbInfo['ten']) ?></span>
          <?php else: ?>
            <span style="display:inline-flex;align-items:center;gap:6px;background:<?= e($dbInfo['mau']) ?>22;color:<?= e($dbInfo['mau']) ?>;border:1px solid <?= e($dbInfo['mau']) ?>66;padding:2px 8px;border-radius:12px;font-weight:700;font-size:12px;">
              <?= e($dbInfo['icon']) ?> <?= e($dbInfo['ten']) ?>
            </span>
          <?php endif; ?>
        </span>
      </div>
      <div class="td-dl-item">
        <span class="td-dl-nhan">Lý do kết thúc</span>
        <span class="td-dl-giatri"><?= e($tran['LyDoKetThuc'] ?? '—') ?></span>
      </div>
      <div class="td-dl-item">
        <span class="td-dl-nhan">Bắt đầu</span>
        <span class="td-dl-giatri"><?= e($fmt($tran['ThoiGianBatDau'])) ?></span>
      </div>
      <div class="td-dl-item">
        <span class="td-dl-nhan">Kết thúc</span>
        <span class="td-dl-giatri"><?= e($fmt($tran['ThoiGianKetThuc'])) ?></span>
      </div>
    </div>
  </div>

  <div class="card td-card-thaotac">
    <h3 class="td-card-tieude">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
      Trạng thái & Quản trị
    </h3>
    <div class="td-tt-box <?= $tran['TrangThai'] === 'Kết thúc' ? 'box-ketthuc' : ($tran['TrangThai'] === 'Hủy' ? 'box-huy' : 'box-dangdienra') ?>">
      <div class="td-tt-trangthai">
        Trạng thái: <b><?= e($tran['TrangThai']) ?></b>
      </div>
      <p class="td-tt-mota">
        <?php if ($tran['TrangThai'] === 'Kết thúc'): ?>
          Trận đấu đã hoàn thành hợp lệ. Điểm Elo của hai người chơi đã được cập nhật tự động.
        <?php elseif ($tran['TrangThai'] === 'Hủy'): ?>
          Trận đấu đã bị hủy. Điểm Elo của hai người chơi được bảo toàn nguyên vẹn.
          <?php if ($nhatKyHuy): ?>
            <br><b>Lý do:</b> <?= e($nhatKyHuy['MoTa']) ?> (bởi <?= e($nhatKyHuy['HoTen']) ?>).
          <?php endif; ?>
        <?php else: ?>
          Trận đấu đang diễn ra trong phòng thi. Nếu phát hiện sự cố, quản trị viên có thể hủy trận dưới đây.
        <?php endif; ?>
      </p>
    </div>

    <?php if ($chuaKetThuc): ?>
      <?php if ($loi !== ''): ?><div class="loi"><?= e($loi) ?></div><?php endif; ?>
      <form method="post" action="" class="td-form-action" data-xac-nhan="Hủy trận #<?= (int) $tran['MaTran'] ?>? Thao tác này không thể hoàn tác.">
        <?= csrf_field() ?>
        <input type="hidden" name="hanh_dong" value="huy">
        <label for="ly_do" style="font-size:12.5px; font-weight:600; color:#475569;">Lý do hủy trận (bắt buộc):</label>
        <textarea id="ly_do" name="ly_do" rows="2" maxlength="300" placeholder="Nhập nguyên nhân hủy..." required><?= e($lyDo) ?></textarea>
        <div style="display:flex; justify-content:flex-end; margin-top:4px;">
          <button type="submit" class="btn btn-do">Hủy trận</button>
        </div>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="nd-section-dau">
  <div class="nd-sec-badge">// BẢNG THÀNH TÍCH ĐỐI ĐẦU</div>
  <div class="nd-sec-title-row">
    <h3 class="nd-sec-tieude">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      <span>Người chơi tham gia</span>
    </h3>
    <span class="nd-sec-tag"><?= count($nguoi) ?> đấu thủ</span>
  </div>
  <p class="nd-sec-mota">Chi tiết điểm số ghi được, kết quả và biến động điểm số Elo sau trận.</p>
</div>
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

<div class="nd-section-dau">
  <div class="nd-sec-badge">// NHẬT KÝ LƯỢT TRẢ LỜI CHI TIẾT</div>
  <div class="nd-sec-title-row">
    <h3 class="nd-sec-tieude">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
      <span>Từng câu hỏi trong trận</span>
    </h3>
    <span class="nd-sec-tag"><?= count($cauHoi) ?> câu hỏi</span>
  </div>
  <p class="nd-sec-mota">Chi tiết các câu hỏi đã bốc ngẫu nhiên và đáp án lựa chọn của từng đấu thủ.</p>
</div>
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

<?php require __DIR__ . '/layout/footer.php'; ?>