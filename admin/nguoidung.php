<?php
// Danh sách người dùng (UC11: A-11, A-45). Đặt tại: elo_quiz/admin/nguoidung.php
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/XlsxWriter.php';
require_once __DIR__ . '/../core/ThongBaoHelper.php';
Auth::requireAdmin(BASE_URL . '/admin/dangnhap.php');   // A-04

$pdo = Database::pdo();

// ---- Bộ lọc: từ khóa (họ tên, email, lớp, khoa) và trạng thái ----
$q  = trim((string) ($_GET['q'] ?? ''));
$tt = (string) ($_GET['tt'] ?? '');
if (!in_array($tt, ['Hoạt động', 'Bị khóa'], true)) {
    $tt = '';
}

$dieuKien = [];
$tham     = [];
$dieuKien[] = 'n.MaND NOT IN (SELECT MaND FROM ADMIN WHERE MaND IS NOT NULL)';   // không liệt kê tài khoản sinh viên liên kết của admin
if ($q !== '') {
    $like       = '%' . addcslashes($q, '\\%_') . '%';   // thoát % và _ để tìm đúng chữ người dùng gõ
    $dieuKien[] = '(n.HoTen LIKE ? OR n.Email LIKE ?)';
    array_push($tham, $like, $like);
}
if ($tt !== '') {
    $dieuKien[] = 'n.TrangThai = ?';
    $tham[]     = $tt;
}
$where = $dieuKien ? 'WHERE ' . implode(' AND ', $dieuKien) : '';

$cotChon = "n.MaND, n.HoTen, n.Email, n.TrangThai, n.NgayTao,
            (SELECT COALESCE(SUM(e.SoTran), 0) FROM ELOMONHOC e WHERE e.MaND = n.MaND) AS so_tran";

// ---- Khóa / mở khóa ngay trên danh sách (A-12): đổi trạng thái + ghi nhật ký cùng một giao dịch (A-40), rồi báo cho người dùng ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $hanhDong = (string) ($_POST['hanh_dong'] ?? '');
    $maND     = (int) ($_POST['id'] ?? 0);
    $ketQua   = 'loi';

    if (($hanhDong === 'khoa' || $hanhDong === 'mokhoa') && $maND > 0) {
        $khoa = $hanhDong === 'khoa';
        $lyDo = preg_replace('/^(.{300}).+$/su', '$1', trim((string) ($_POST['ly_do'] ?? '')));

        $pdo->beginTransaction();
        try {
            // Điều kiện theo trạng thái cũ: bấm lặp hoặc hai quản trị viên cùng bấm thì chỉ xử lý một lần.
            // Không cho khóa tài khoản sinh viên đang liên kết với quản trị viên.
            $up = $pdo->prepare(
                'UPDATE NGUOIDUNG SET TrangThai = ?
                 WHERE MaND = ? AND TrangThai = ? AND MaND NOT IN (SELECT MaND FROM ADMIN WHERE MaND IS NOT NULL)'
            );
            $up->execute([$khoa ? 'Bị khóa' : 'Hoạt động', $maND, $khoa ? 'Hoạt động' : 'Bị khóa']);
            $daDoi = $up->rowCount() === 1;
            if ($daDoi) {
                NhatKy::ghi(
                    $khoa ? 'Khóa người dùng' : 'Mở khóa người dùng',
                    'NGUOIDUNG #' . $maND,
                    $lyDo !== '' ? 'Lý do: ' . $lyDo : null
                );
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }

        if ($daDoi) {
            // Gửi thông báo sau khi đã lưu: thông báo lỗi thì tài khoản vẫn được khóa, chỉ báo lại cho quản trị viên biết
            $daBao  = ThongBaoHelper::taoThongBaoTaiKhoan($pdo, $maND, $khoa, $lyDo);
            $ketQua = ($khoa ? 'khoa' : 'mokhoa') . ($daBao ? '' : '_chuabao');
        }
    }
    redirect('?' . http_build_query(array_merge(array_diff_key($_GET, ['kq' => 1, 'page' => 1]), ['kq' => $ketQua])));
}
$thongBao = [
    'khoa'           => ['ok',  'Đã khóa tài khoản và gửi thông báo cho người dùng.'],
    'mokhoa'         => ['ok',  'Đã mở khóa tài khoản và gửi thông báo cho người dùng.'],
    'khoa_chuabao'   => ['loi', 'Đã khóa tài khoản nhưng chưa gửi được thông báo cho người dùng.'],
    'mokhoa_chuabao' => ['loi', 'Đã mở khóa tài khoản nhưng chưa gửi được thông báo cho người dùng.'],
    'loi'            => ['loi', 'Không thực hiện được: tài khoản đã đổi trạng thái trước đó hoặc không được phép khóa.'],
][$_GET['kq'] ?? ''] ?? null;

// ---- A-45: xuất Excel theo đúng bộ lọc đang xem ----
if (isset($_GET['xuat'])) {
    $st = $pdo->prepare("SELECT $cotChon FROM NGUOIDUNG n $where ORDER BY n.NgayTao DESC, n.MaND DESC LIMIT 10000");
    $st->execute($tham);
    $nguoi = $st->fetchAll();

    $monHoc = $pdo->query('SELECT MaMon, TenMon FROM MONHOC ORDER BY MaMon')->fetchAll();

    $st = $pdo->prepare("SELECT e.MaND, e.MaMon, e.DiemElo FROM ELOMONHOC e JOIN NGUOIDUNG n ON n.MaND = e.MaND $where");
    $st->execute($tham);
    $elo = [];
    foreach ($st as $r) {
        $elo[(int) $r['MaND']][(int) $r['MaMon']] = (int) $r['DiemElo'];
    }

    $tieuDe = ['Họ tên', 'Email', 'Trạng thái'];
    foreach ($monHoc as $m) {
        $tieuDe[] = 'Elo - ' . $m['TenMon'];
    }
    $tieuDe[] = 'Số trận';

    $dong = [];
    foreach ($nguoi as $r) {
        $d = [$r['HoTen'], $r['Email'], $r['TrangThai']];
        foreach ($monHoc as $m) {
            $d[] = $elo[(int) $r['MaND']][(int) $m['MaMon']] ?? null;   // chưa chơi môn nào thì để trống
        }
        $d[]    = (int) $r['so_tran'];
        $dong[] = $d;
    }
    XlsxWriter::tai('nguoidung_' . date('Ymd_His') . '.xlsx', 'Người dùng', $tieuDe, $dong);
}

// ---- Danh sách có phân trang (20 dòng/trang, có hiển thị tổng số) ----
$st = $pdo->prepare("SELECT COUNT(*) FROM NGUOIDUNG n $where");
$st->execute($tham);
$p = new Paginator((int) $st->fetchColumn(), 20);

$st = $pdo->prepare(
    "SELECT $cotChon FROM NGUOIDUNG n $where
     ORDER BY n.NgayTao DESC, n.MaND DESC
     LIMIT " . $p->limit() . ' OFFSET ' . $p->offset()
);
$st->execute($tham);
$ds = $st->fetchAll();



$linkXuat = '?' . http_build_query(array_merge(array_diff_key($_GET, ['page' => 1, 'kq' => 1]), ['xuat' => 1]));

// ---- Số liệu cho sơ đồ phía trên (không phụ thuộc bộ lọc) ----
$loaiAdmin = 'n.MaND NOT IN (SELECT MaND FROM ADMIN WHERE MaND IS NOT NULL)';
$tk = $pdo->query(
    "SELECT COUNT(*) AS tong,
            COALESCE(SUM(n.TrangThai = 'Hoạt động'), 0) AS hoat_dong,
            COALESCE(SUM(n.TrangThai = 'Bị khóa'), 0)   AS bi_khoa,
            COALESCE(SUM(n.NgayTao >= NOW() - INTERVAL 7 DAY), 0) AS moi_7_ngay,
            COALESCE(SUM(NOT EXISTS (SELECT 1 FROM ELOMONHOC e WHERE e.MaND = n.MaND AND e.SoTran > 0)), 0) AS chua_dau
     FROM NGUOIDUNG n WHERE $loaiAdmin"
)->fetch();

// Số tài khoản mới mỗi ngày trong 30 ngày gần nhất (ngày không có ai đăng ký vẫn hiện cột 0)
$theoNgay = $pdo->query(
    "SELECT DATE(n.NgayTao) AS ngay, COUNT(*) AS so
     FROM NGUOIDUNG n
     WHERE $loaiAdmin AND n.NgayTao >= CURDATE() - INTERVAL 29 DAY
     GROUP BY DATE(n.NgayTao)"
)->fetchAll(PDO::FETCH_KEY_PAIR);
$nhanNgay = [];
$soNgay   = [];
for ($i = 29; $i >= 0; $i--) {
    $d          = date('Y-m-d', strtotime("-$i day"));
    $nhanNgay[] = date('d/m', strtotime($d));
    $soNgay[]   = (int) ($theoNgay[$d] ?? 0);
}
$jsonFlag = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;

$tieuDe  = 'Người dùng';
$mucChon = 'nguoidung';
$cssTrang = 'nguoidung';
$jsTrang  = 'nguoidung';
$giaoDienMoi = true;   // khung giao diện sáng mới (sidebar xanh + thanh trên + breadcrumb)
require __DIR__ . '/layout/header.php';
?>
<div class="nd-trang">

  <header class="nd-dau">
    <h1 class="nd-tieude">Người dùng</h1>
    <span class="nd-tong">Tổng <?= (int) $tk['tong'] ?> tài khoản</span>
  </header>

  <?php if ($thongBao): ?>
    <div class="nd-flash nd-flash-<?= e($thongBao[0]) ?>" role="status"><?= e($thongBao[1]) ?></div>
  <?php endif; ?>

  <!-- Sơ đồ quản lý người dùng -->
  <section class="nd-so-do" aria-label="Sơ đồ người dùng">
    <div class="nd-so-do-trai">
      <div class="nd-so-do-tieude">
        <b>Tài khoản mới 30 ngày gần nhất</b>
        <span><?= (int) $tk['moi_7_ngay'] ?> trong 7 ngày qua</span>
      </div>
      <div class="nd-cot-khung">
        <canvas id="ndBieuDoNgay" height="150"
                data-nhan="<?= e(json_encode($nhanNgay, $jsonFlag)) ?>"
                data-so="<?= e(json_encode($soNgay, $jsonFlag)) ?>"></canvas>
      </div>
    </div>
    <div class="nd-so-do-phai">
      <div class="nd-so-do-tieude"><b>Trạng thái tài khoản</b></div>
      <div class="nd-banh">
        <div class="nd-banh-hinh">
          <canvas id="ndBieuDoTT" width="130" height="130"
                  data-nhan="<?= e(json_encode(['Hoạt động', 'Bị khóa'], $jsonFlag)) ?>"
                  data-so="<?= e(json_encode([(int) $tk['hoat_dong'], (int) $tk['bi_khoa']], $jsonFlag)) ?>"></canvas>
        </div>
        <ul class="nd-chuthich">
          <li><i class="c-xanh"></i>Hoạt động <b><?= (int) $tk['hoat_dong'] ?></b></li>
          <li><i class="c-do"></i>Bị khóa <b><?= (int) $tk['bi_khoa'] ?></b></li>
          <li class="phu">Chưa đấu trận nào <b><?= (int) $tk['chua_dau'] ?></b></li>
        </ul>
      </div>
    </div>
  </section>

  <section class="nd-khung">
    <form method="get" action="" class="nd-thanh-cu">
      <input type="text" name="q" value="<?= e($q) ?>" placeholder="Tìm theo họ tên, email" class="nd-o-tim" autocomplete="off">
      <select name="tt" class="nd-chon">
        <option value="">Mọi trạng thái</option>
        <option value="Hoạt động"<?= $tt === 'Hoạt động' ? ' selected' : '' ?>>Hoạt động</option>
        <option value="Bị khóa"<?= $tt === 'Bị khóa' ? ' selected' : '' ?>>Bị khóa</option>
      </select>
      <button type="submit" class="nd-nut">Tìm</button>
      <a class="nd-nut nd-nut-vien" href="?">Xóa lọc</a>
      <span class="nd-giua"></span>
      <a class="nd-nut nd-nut-vien" href="<?= e($linkXuat) ?>">Xuất Excel</a>
    </form>

    <?php if (!$ds): ?>
      <p class="nd-trong">Không có người dùng nào khớp.</p>
    <?php else: ?>
      <div class="nd-cuon">
        <table class="nd-bang">
          <thead>
            <tr><th>Mã</th><th>Họ tên</th><th>Email</th><th>Số trận</th><th>Trạng thái</th><th>Ngày tạo</th><th>Khóa tài khoản</th></tr>
          </thead>
          <tbody>
          <?php foreach ($ds as $r): $biKhoa = $r['TrangThai'] === 'Bị khóa'; ?>
            <tr>
              <td class="nd-ma"><?= (int) $r['MaND'] ?></td>
              <td><a href="<?= e(BASE_URL) ?>/admin/nguoidung_chitiet.php?id=<?= (int) $r['MaND'] ?>"><?= e($r['HoTen']) ?></a></td>
              <td><?= e($r['Email']) ?></td>
              <td><?= (int) $r['so_tran'] ?></td>
              <td><?= $biKhoa
                    ? '<span class="tt-badge tt-khoa"><span class="badge-dot"></span> Bị khóa</span>'
                    : '<span class="tt-badge tt-hoatdong"><span class="badge-dot"></span> Hoạt động</span>' ?></td>
              <td><?= e(date('d/m/Y H:i', strtotime($r['NgayTao']))) ?></td>
              <td>
                <button type="button" class="nd-khoa-nut <?= $biKhoa ? 'mo' : 'khoa' ?>"
                        data-id="<?= (int) $r['MaND'] ?>" data-ten="<?= e($r['HoTen']) ?>"
                        data-hanh-dong="<?= $biKhoa ? 'mokhoa' : 'khoa' ?>">
                  <?= $biKhoa ? 'Mở khóa' : 'Khóa' ?>
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
    <div class="nd-chan"><?= $p->links() ?></div>
  </section>
</div>

<!-- Hộp xác nhận khóa / mở khóa: nhập lý do, người dùng sẽ nhận thông báo kèm lý do này -->
<dialog class="nd-hop" id="ndHopKhoa">
  <form method="post" action="" class="nd-hop-form">
    <?= csrf_field() ?>
    <input type="hidden" name="id" id="ndHopId" value="">
    <input type="hidden" name="hanh_dong" id="ndHopHanhDong" value="">
    <h3 id="ndHopTieuDe">Khóa tài khoản</h3>
    <p id="ndHopMoTa" class="nd-hop-mota"></p>
    <label class="nd-hop-nhan" for="ndHopLyDo" id="ndHopNhanLyDo">Lý do (người dùng sẽ thấy trong thông báo)</label>
    <textarea name="ly_do" id="ndHopLyDo" rows="3" maxlength="300" placeholder="Ví dụ: Spam bài đăng trong cộng đồng"></textarea>
    <div class="nd-hop-nut">
      <button type="button" class="nd-nut nd-nut-vien" id="ndHopHuy">Hủy</button>
      <button type="submit" class="nd-nut nd-nut-do" id="ndHopXacNhan">Khóa tài khoản</button>
    </div>
  </form>
</dialog>

<script src="<?= e(BASE_URL) ?>/assets/vendor/chart.umd.js"></script>
<?php require __DIR__ . '/layout/footer.php'; ?>