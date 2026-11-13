<?php
// Chi tiết người dùng, lịch sử Elo, khóa/mở khóa (UC11: A-11, A-12, A-13, A-29).
// Đặt tại: elo_quiz/admin/nguoidung_chitiet.php   (mở bằng ?id=<MaND>)
// A-13: không có chức năng xóa người dùng; chỉ khóa để giữ nguyên lịch sử trận đấu.
require_once __DIR__ . '/../core/bootstrap.php';
Auth::requireAdmin(BASE_URL . '/admin/dangnhap.php');   // A-04

$pdo = Database::pdo();
$id  = (int) ($_GET['id'] ?? 0);

$st = $pdo->prepare('SELECT MaND, HoTen, Email, TrangThai, NgayTao, TrangThaiKhaoSat FROM NGUOIDUNG WHERE MaND = ?');
$st->execute([$id]);
$nd = $st->fetch();

$tieuDe  = 'Chi tiết người dùng';
$mucChon = 'nguoidung';

if (!$nd) {
    http_response_code(404);
    $cssTrang = 'nguoidung_chitiet';
    $jsTrang  = 'nguoidung_chitiet';
    require __DIR__ . '/layout/header.php';
    echo '<h2>Chi tiết người dùng</h2><div class="card">Không tìm thấy người dùng này. '
        . '<a href="' . e(BASE_URL) . '/admin/nguoidung.php">Về danh sách</a></div>';
    require __DIR__ . '/layout/footer.php';
    exit;
}

// ---- A-12: khóa / mở khóa, cập nhật trạng thái và ghi nhật ký trong cùng một giao dịch (A-40) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $hanhDong = (string) ($_POST['hanh_dong'] ?? '');

    if ($hanhDong === 'khoa' || $hanhDong === 'mokhoa') {
        $khoa  = $hanhDong === 'khoa';
        $lyDo  = preg_replace('/^(.{300}).+$/su', '$1', trim((string) ($_POST['ly_do'] ?? '')));

        $pdo->beginTransaction();
        try {
            // Điều kiện theo trạng thái cũ: bấm lặp hoặc hai quản trị viên cùng bấm thì chỉ ghi nhật ký một lần
            $up = $pdo->prepare('UPDATE NGUOIDUNG SET TrangThai = ? WHERE MaND = ? AND TrangThai = ?');
            $up->execute([$khoa ? 'Bị khóa' : 'Hoạt động', $id, $khoa ? 'Hoạt động' : 'Bị khóa']);
            if ($up->rowCount() === 1) {
                NhatKy::ghi(
                    $khoa ? 'Khóa người dùng' : 'Mở khóa người dùng',
                    'NGUOIDUNG #' . $id,
                    $lyDo !== '' ? 'Lý do: ' . $lyDo : null
                );
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }
        redirect(BASE_URL . '/admin/nguoidung_chitiet.php?id=' . $id . '&kq=' . $hanhDong);
    }
}

$thongBao = ['khoa' => 'Đã khóa tài khoản.', 'mokhoa' => 'Đã mở khóa tài khoản.'][$_GET['kq'] ?? ''] ?? '';

// ---- A-11: Elo từng môn, số trận, tỷ lệ thắng (hiển thị tất cả môn đang mở kèm Elo khởi tạo 1.000 nếu chưa đấu) ----
$st = $pdo->prepare(
    "SELECT m.MaMon, m.TenMon,
            COALESCE(e.DiemElo, 1000) AS DiemElo,
            COALESCE(e.SoTran, 0)     AS SoTran,
            COALESCE(e.SoThang, 0)    AS SoThang,
            COALESCE(e.SoThua, 0)     AS SoThua,
            COALESCE(e.SoHoa, 0)      AS SoHoa
     FROM MONHOC m
     LEFT JOIN ELOMONHOC e ON e.MaMon = m.MaMon AND e.MaND = ?
     WHERE m.TrangThai = 'Đang mở'
     ORDER BY m.TenMon"
);
$st->execute([$id]);
$eloMon = $st->fetchAll();
$tongTran  = array_sum(array_column($eloMon, 'SoTran'));
$tongThang = array_sum(array_column($eloMon, 'SoThang'));

// ---- A-29: lịch sử Elo (lọc theo môn, phân trang 20 dòng) ----
$monLoc = (int) ($_GET['mon'] ?? 0);
if (!in_array($monLoc, array_map('intval', array_column($eloMon, 'MaMon')), true)) {
    $monLoc = 0;
}
$dk   = 'WHERE l.MaND = ?' . ($monLoc ? ' AND l.MaMon = ?' : '');
$tham = $monLoc ? [$id, $monLoc] : [$id];

$st = $pdo->prepare("SELECT COUNT(*) FROM LICHSUELO l $dk");
$st->execute($tham);
$p = new Paginator((int) $st->fetchColumn(), 20);

$st = $pdo->prepare(
    "SELECT l.ThoiGian, m.TenMon, l.EloTruoc, l.EloSau, l.ThayDoi, l.MaTran
     FROM LICHSUELO l JOIN MONHOC m ON m.MaMon = l.MaMon
     $dk ORDER BY l.ThoiGian DESC, l.MaLichSu DESC
     LIMIT " . $p->limit() . ' OFFSET ' . $p->offset()
);
$st->execute($tham);
$lichSu = $st->fetchAll();

$cssTrang = 'nguoidung_chitiet';
$jsTrang  = 'nguoidung_chitiet';
require __DIR__ . '/layout/header.php';
?>
<div class="card nd-profile-hero-card">
  <div class="nd-profile-hero-top">
    <a href="<?= e(BASE_URL) ?>/admin/nguoidung.php" class="nd-nut-ve-hero">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
      <span>Danh sách người dùng</span>
    </a>
    <div class="nd-hero-eyebrow">
      <span class="live-pulse"></span>
      <span>HỒ SƠ ĐỊNH DANH NGƯỜI DÙNG</span>
    </div>
  </div>

  <div class="nd-profile-header">
    <div class="nd-avatar-lon" aria-hidden="true"><?= e(mb_strtoupper(mb_substr(trim($nd['HoTen']), 0, 1))) ?></div>
    <div class="nd-info-chinh">
      <h2><?= e($nd['HoTen']) ?></h2>
      <div class="nd-meta-row">
        <span class="nd-badge-id">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          UID #<?= (int) $nd['MaND'] ?>
        </span>
        <span class="nd-meta-pill-light">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
          <?= e($nd['Email']) ?>
        </span>
        <span class="nd-meta-pill-light">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="18" height="18" x="3" y="4" rx="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>
          Đăng ký: <?= e(date('d/m/Y', strtotime($nd['NgayTao']))) ?>
        </span>
        <?= $nd['TrangThai'] === 'Bị khóa' 
            ? '<span class="nd-meta-status status-khoa"><span class="status-dot dot-red"></span> Bị khóa</span>' 
            : '<span class="nd-meta-status status-live"><span class="status-dot dot-green"></span> Hoạt động</span>' ?>
      </div>
    </div>
  </div>
</div>

<?php if ($thongBao !== ''): ?><div class="thanhcong"><?= e($thongBao) ?></div><?php endif; ?>

<!-- Bố cục 2 cột cân đối: Thông tin tài khoản (Trái) & Quản lý trạng thái (Phải) -->
<div class="nd-grid-2cot">
  <div class="card nd-card-thongtin">
    <h3 class="nd-card-tieude">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      Thông tin tài khoản
    </h3>
    <div class="nd-dl-grid">
      <div class="nd-dl-item">
        <span class="nd-dl-nhan">Họ và tên</span>
        <span class="nd-dl-giatri"><b><?= e($nd['HoTen']) ?></b></span>
      </div>
      <div class="nd-dl-item">
        <span class="nd-dl-nhan">Địa chỉ Email</span>
        <span class="nd-dl-giatri"><?= e($nd['Email']) ?></span>
      </div>
      <div class="nd-dl-item">
        <span class="nd-dl-nhan">Ngày đăng ký</span>
        <span class="nd-dl-giatri"><?= e(date('d/m/Y H:i', strtotime($nd['NgayTao']))) ?></span>
      </div>
      <div class="nd-dl-item">
        <span class="nd-dl-nhan">Khảo sát ý kiến</span>
        <span class="nd-dl-giatri"><?= e($nd['TrangThaiKhaoSat']) ?></span>
      </div>
      <div class="nd-dl-item">
        <span class="nd-dl-nhan">Tổng trận đã đấu</span>
        <span class="nd-dl-giatri"><b><?= (int) $tongTran ?></b> trận</span>
      </div>
      <div class="nd-dl-item">
        <span class="nd-dl-nhan">Tỷ lệ thắng</span>
        <span class="nd-dl-giatri"><b><?= $tongTran > 0 ? round($tongThang * 100 / $tongTran, 1) . '%' : '—' ?></b> <?= $tongTran > 0 ? '(' . (int) $tongThang . ' thắng)' : '' ?></span>
      </div>
    </div>
  </div>

  <div class="card nd-card-thaotac">
    <h3 class="nd-card-tieude">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
      Quản lý trạng thái
    </h3>
    <div class="nd-tt-box <?= $nd['TrangThai'] === 'Bị khóa' ? 'box-khoa' : 'box-hoatdong' ?>">
      <div class="nd-tt-trangthai">
        Trạng thái: <b><?= e($nd['TrangThai']) ?></b>
      </div>
      <p class="nd-tt-mota">
        <?= $nd['TrangThai'] === 'Bị khóa' 
            ? 'Tài khoản đang bị tạm khóa. Người dùng không thể đăng nhập hoặc tham gia phòng thi đấu.'
            : 'Tài khoản đang hoạt động bình thường, có thể đăng nhập và tham gia thi đấu xếp hạng.' ?>
      </p>
    </div>

    <form method="post" action="" class="nd-form-action">
      <?= csrf_field() ?>
      <label for="ly_do" class="nd-form-label">Lý do thay đổi (ghi vào nhật ký):</label>
      <input type="text" id="ly_do" name="ly_do" maxlength="300" placeholder="Ví dụ: Gian lận, vi phạm quy tắc cộng đồng...">
      <div class="nd-form-submit">
        <?php if ($nd['TrangThai'] === 'Hoạt động'): ?>
          <button type="submit" name="hanh_dong" value="khoa" class="btn btn-do"
                  data-xac-nhan="Khóa tài khoản này? Người dùng sẽ không đăng nhập được nữa.">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
            Khóa tài khoản
          </button>
        <?php else: ?>
          <button type="submit" name="hanh_dong" value="mokhoa" class="btn"
                  data-xac-nhan="Mở khóa tài khoản này?">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg>
            Mở khóa tài khoản
          </button>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="nd-card-head-pro">
    <div class="nd-card-head-eyebrow">// KỸ NĂNG & XẾP HẠNG THỜI GIAN THỰC</div>
    <div class="nd-card-head-title-row">
      <h3 class="nd-card-tieude-chinh">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="12" x2="12" y1="20" y2="10"/><line x1="18" x2="18" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="16"/></svg>
        <span>Điểm Elo từng môn</span>
      </h3>
      <span class="nd-card-tag-count"><?= count($eloMon) ?> môn đang mở</span>
    </div>
    <p class="nd-card-mota-phu">Chỉ số Elo tự động tính toán sau mỗi trận đối đầu xếp hạng theo chuẩn quốc tế.</p>
  </div>
  <?php if (!$eloMon): ?>
    <p class="nhan-phu">Chưa có môn học nào đang mở trên hệ thống.</p>
  <?php else: ?>
    <table>
      <tr><th>Môn học</th><th>Điểm Elo</th><th>Số trận</th><th>Thắng</th><th>Thua</th><th>Hòa</th><th>Tỷ lệ thắng</th></tr>
      <?php foreach ($eloMon as $r): ?>
        <tr>
          <td><b><?= e($r['TenMon']) ?></b></td>
          <td><strong><?= number_format((int) $r['DiemElo'], 0, ',', '.') ?></strong><?= (int) $r['SoTran'] === 0 ? ' <small class="nhan-phu" style="font-size:11.5px">(khởi tạo)</small>' : '' ?></td>
          <td><?= (int) $r['SoTran'] ?></td>
          <td><?= (int) $r['SoThang'] ?></td>
          <td><?= (int) $r['SoThua'] ?></td>
          <td><?= (int) $r['SoHoa'] ?></td>
          <td><?= (int) $r['SoTran'] > 0 ? e((string) round((int) $r['SoThang'] * 100 / (int) $r['SoTran'], 1)) . '%' : '<span class="nhan-phu">—</span>' ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
    <p class="nhan-phu" style="margin-top:14px; font-size:13px; line-height:1.5;">
      <?php if ($tongTran > 0): ?>
        Tổng cộng: <b><?= (int) $tongTran ?></b> trận đấu · Tỷ lệ thắng <b><?= round($tongThang * 100 / $tongTran, 1) ?>%</b>.
      <?php else: ?>
        Người dùng này chưa tham gia trận đấu nào. Điểm Elo khởi tạo mặc định là <b>1.000 điểm</b> cho mỗi môn học.
      <?php endif; ?>
      Elo chỉ tự động cập nhật sau kết quả các trận đấu xếp hạng, quản trị viên không thể chỉnh sửa thủ công.
    </p>
  <?php endif; ?>
</div>

<div class="card">
  <div class="nd-card-head-pro">
    <div class="nd-card-head-eyebrow">// NHẬT KÝ BIẾN ĐỘNG THI ĐẤU</div>
    <div class="nd-card-head-title-row">
      <h3 class="nd-card-tieude-chinh">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        <span>Lịch sử biến động Elo</span>
      </h3>
      <span class="nd-card-tag-count"><?= (int) $p->tong() ?> lượt thay đổi</span>
    </div>
    <p class="nd-card-mota-phu">Ghi lại toàn bộ chuỗi trận đấu dẫn tới tăng hoặc giảm điểm kỹ năng của người dùng.</p>
  </div>

  <?php if ($lichSu || $monLoc > 0): ?>
  <form method="get" action="" class="form-lichsu-inline">
    <input type="hidden" name="id" value="<?= (int) $id ?>">
    <label style="font-size:13px; font-weight:700; color:#334155;">Lọc theo môn:</label>
    <select name="mon" style="width:auto;">
      <option value="0">Mọi môn</option>
      <?php foreach ($eloMon as $r): ?>
        <option value="<?= (int) $r['MaMon'] ?>"<?= (int) $r['MaMon'] === $monLoc ? ' selected' : '' ?>><?= e($r['TenMon']) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn">Lọc</button>
  </form>
  <?php endif; ?>

  <?php if (!$lichSu): ?>
    <div style="padding:16px 0; color:#64748b; display:flex; align-items:center; gap:10px;">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:#94a3b8;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <span style="font-size:13.5px;"><?= $monLoc > 0 ? 'Không có lịch sử biến động Elo ở môn này.' : 'Chưa có lịch sử đấu (người dùng chưa tham gia trận đấu nào nên chưa phát sinh biến động Elo).' ?></span>
    </div>
  <?php else: ?>
    <table>
      <tr><th>Thời gian</th><th>Môn học</th><th>Elo trước</th><th>Elo sau</th><th>Thay đổi</th><th>Trận đấu</th></tr>
      <?php foreach ($lichSu as $r): ?>
        <tr>
          <td><?= e(date('d/m/Y H:i', strtotime($r['ThoiGian']))) ?></td>
          <td><b><?= e($r['TenMon']) ?></b></td>
          <td><?= (int) $r['EloTruoc'] ?></td>
          <td><b><?= (int) $r['EloSau'] ?></b></td>
          <td class="dam <?= (int) $r['ThayDoi'] >= 0 ? 'mau-xanh' : 'mau-do' ?>"><?= sprintf('%+d', (int) $r['ThayDoi']) ?></td>
          <td><a href="<?= e(BASE_URL) ?>/admin/trandau_chitiet.php?id=<?= (int) $r['MaTran'] ?>">#<?= (int) $r['MaTran'] ?></a></td>
        </tr>
      <?php endforeach; ?>
    </table>
    <?= $p->links() ?>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>