<?php
// Quản lý môn học và loại kỳ thi (UC12: A-14, A-15, A-16) + duyệt môn do sinh viên đề xuất.
// Đặt tại: elo_quiz/admin/monhoc.php
// A-15: không có nút xóa môn đã tồn tại; chỉ ẩn/hiện. Môn "Chờ duyệt" bị từ chối thì xóa (chưa ai tham chiếu).
// Theo ghi chú trong core/NhatKy.php, thao tác môn học không nằm trong danh sách A-40 nên không ghi nhật ký.
require_once __DIR__ . '/../core/bootstrap.php';
Auth::requireAdmin(BASE_URL . '/admin/login.php');   // A-04

$pdo   = Database::pdo();
$sach  = BASE_URL . '/admin/monhoc.php';

function mh_chuan(string $s): string
{
    return trim((string) preg_replace('/\s+/u', ' ', $s));
}

// ---------------------------------------------------------------------
// Xử lý POST (mô hình POST -> redirect -> GET)
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $hd   = (string) ($_POST['hanh_dong'] ?? '');
    $id   = (int) ($_POST['id'] ?? 0);
    $loi  = null;
    $ok   = null;
    $xong = false;   // true: thành công và là thao tác sửa -> về trang sạch (bỏ ?sua=...)

    try {
        switch ($hd) {
            case 'them_mon':
            case 'sua_mon':
                $ten  = mh_chuan((string) ($_POST['ten_mon'] ?? ''));
                $moTa = trim((string) ($_POST['mo_ta'] ?? ''));
                if ($ten === '') {
                    $loi = 'Hãy nhập tên môn học.';
                } elseif (mb_strlen($ten) > 150) {
                    $loi = 'Tên môn tối đa 150 ký tự.';
                } elseif (mb_strlen($moTa) > 500) {
                    $loi = 'Mô tả tối đa 500 ký tự.';
                } else {
                    // Trùng tên với môn khác (mọi trạng thái, không phân biệt hoa thường)
                    $st = $pdo->prepare('SELECT TrangThai FROM MONHOC WHERE TenMon = ? AND MaMon <> ? LIMIT 1');
                    $st->execute([$ten, $hd === 'sua_mon' ? $id : 0]);
                    $trung = $st->fetchColumn();
                    if ($trung === 'Chờ duyệt') {
                        $loi = 'Tên này đang có một đề xuất chờ duyệt, hãy duyệt đề xuất đó ở mục bên dưới.';
                    } elseif ($trung !== false) {
                        $loi = 'Đã có môn học trùng tên (trạng thái: ' . $trung . ').';
                    } elseif ($hd === 'them_mon') {
                        $pdo->prepare("INSERT INTO MONHOC (TenMon, MoTa, TrangThai) VALUES (?, ?, 'Đang mở')")
                            ->execute([$ten, $moTa === '' ? null : $moTa]);
                        $ok = 'Đã thêm môn học.';
                    } else {
                        $up = $pdo->prepare("UPDATE MONHOC SET TenMon = ?, MoTa = ? WHERE MaMon = ? AND TrangThai IN ('Đang mở','Đã ẩn')");
                        $up->execute([$ten, $moTa === '' ? null : $moTa, $id]);
                        // rowCount = 0 có thể do không đổi gì; kiểm tra tồn tại riêng
                        $co = $pdo->prepare("SELECT COUNT(*) FROM MONHOC WHERE MaMon = ? AND TrangThai IN ('Đang mở','Đã ẩn')");
                        $co->execute([$id]);
                        if ((int) $co->fetchColumn() === 0) {
                            $loi = 'Không tìm thấy môn học này.';
                        } else {
                            $ok   = 'Đã lưu thay đổi.';
                            $xong = true;
                        }
                    }
                }
                break;

            case 'an_mon':
            case 'hien_mon':
                $an = $hd === 'an_mon';
                $up = $pdo->prepare('UPDATE MONHOC SET TrangThai = ? WHERE MaMon = ? AND TrangThai = ?');
                $up->execute([$an ? 'Đã ẩn' : 'Đang mở', $id, $an ? 'Đang mở' : 'Đã ẩn']);
                $ok = $up->rowCount() === 1
                    ? ($an ? 'Đã ẩn môn học.' : 'Đã hiện lại môn học.')
                    : 'Môn học đã ở trạng thái này rồi.';
                break;

            case 'duyet_mon':
                // Điều kiện theo trạng thái cũ: hai quản trị viên cùng bấm thì chỉ một người có tác dụng
                $up = $pdo->prepare("UPDATE MONHOC SET TrangThai = 'Đang mở' WHERE MaMon = ? AND TrangThai = 'Chờ duyệt'");
                $up->execute([$id]);
                $ok = $up->rowCount() === 1 ? 'Đã duyệt, môn học đã hiện cho sinh viên.' : 'Đề xuất này đã được xử lý trước đó.';
                break;

            case 'tu_choi_mon':
                try {
                    $del = $pdo->prepare("DELETE FROM MONHOC WHERE MaMon = ? AND TrangThai = 'Chờ duyệt'");
                    $del->execute([$id]);
                    $ok = $del->rowCount() === 1 ? 'Đã từ chối và xóa đề xuất.' : 'Đề xuất này đã được xử lý trước đó.';
                } catch (PDOException $ex) {
                    $loi = 'Không xóa được đề xuất vì đã có dữ liệu tham chiếu.';
                }
                break;

            case 'them_kt':
            case 'sua_kt':
                $ten  = mh_chuan((string) ($_POST['ten_kt'] ?? ''));
                $moTa = trim((string) ($_POST['mo_ta_kt'] ?? ''));
                if ($ten === '') {
                    $loi = 'Hãy nhập tên loại kỳ thi.';
                } elseif (mb_strlen($ten) > 50) {
                    $loi = 'Tên loại kỳ thi tối đa 50 ký tự.';
                } elseif (mb_strlen($moTa) > 500) {
                    $loi = 'Mô tả tối đa 500 ký tự.';
                } else {
                    $st = $pdo->prepare('SELECT COUNT(*) FROM LOAIKYTHI WHERE TenLoaiKT = ? AND MaLoaiKT <> ?');
                    $st->execute([$ten, $hd === 'sua_kt' ? $id : 0]);
                    if ((int) $st->fetchColumn() > 0) {
                        $loi = 'Đã có loại kỳ thi trùng tên.';
                    } elseif ($hd === 'them_kt') {
                        $pdo->prepare('INSERT INTO LOAIKYTHI (TenLoaiKT, MoTa) VALUES (?, ?)')
                            ->execute([$ten, $moTa === '' ? null : $moTa]);
                        $ok = 'Đã thêm loại kỳ thi.';
                    } else {
                        $co = $pdo->prepare('SELECT COUNT(*) FROM LOAIKYTHI WHERE MaLoaiKT = ?');
                        $co->execute([$id]);
                        if ((int) $co->fetchColumn() === 0) {
                            $loi = 'Không tìm thấy loại kỳ thi này.';
                        } else {
                            $pdo->prepare('UPDATE LOAIKYTHI SET TenLoaiKT = ?, MoTa = ? WHERE MaLoaiKT = ?')
                                ->execute([$ten, $moTa === '' ? null : $moTa, $id]);
                            $ok   = 'Đã lưu thay đổi.';
                            $xong = true;
                        }
                    }
                }
                break;

            default:
                $loi = 'Thao tác không hợp lệ.';
        }
    } catch (PDOException $ex) {
        $loi = 'Lỗi cơ sở dữ liệu, chưa lưu được. Hãy thử lại.';
    }

    $_SESSION['mh_flash'] = $loi !== null ? ['loi', $loi] : ['ok', (string) $ok];
    // Lỗi hoặc thao tác nhanh: ở lại đúng trang đang xem (giữ bộ lọc, trang, form đang sửa).
    // Sửa thành công: về trang sạch.
    redirect($xong ? $sach : (string) $_SERVER['REQUEST_URI']);
}

$flash = $_SESSION['mh_flash'] ?? null;
unset($_SESSION['mh_flash']);

// ---------------------------------------------------------------------
// Dữ liệu hiển thị
// ---------------------------------------------------------------------

// ---- Môn do sinh viên đề xuất, đang chờ duyệt ----
$tongCho = (int) $pdo->query("SELECT COUNT(*) FROM MONHOC WHERE TrangThai = 'Chờ duyệt'")->fetchColumn();
$pCho    = new Paginator($tongCho, 10, 'pcho');
$dsCho   = $pdo->query(
    "SELECT m.MaMon, m.TenMon, m.MoTa, n.HoTen, n.Email
     FROM MONHOC m LEFT JOIN NGUOIDUNG n ON n.MaND = m.MaNguoiDeXuat
     WHERE m.TrangThai = 'Chờ duyệt'
     ORDER BY m.MaMon
     LIMIT " . $pCho->limit() . ' OFFSET ' . $pCho->offset()
)->fetchAll();

// ---- Danh sách môn (Đang mở / Đã ẩn), lọc và phân trang 20 dòng ----
$q  = trim((string) ($_GET['q'] ?? ''));
$tt = (string) ($_GET['tt'] ?? '');
if (!in_array($tt, ['Đang mở', 'Đã ẩn'], true)) {
    $tt = '';
}
$dk   = ["m.TrangThai IN ('Đang mở','Đã ẩn')"];
$tham = [];
if ($q !== '') {
    $dk[]   = '(m.TenMon LIKE ? OR m.MoTa LIKE ?)';
    $like   = '%' . addcslashes($q, '\\%_') . '%';
    array_push($tham, $like, $like);
}
if ($tt !== '') {
    $dk[]   = 'm.TrangThai = ?';
    $tham[] = $tt;
}
$where = 'WHERE ' . implode(' AND ', $dk);

$st = $pdo->prepare("SELECT COUNT(*) FROM MONHOC m $where");
$st->execute($tham);
$p = new Paginator((int) $st->fetchColumn(), 20);

$st = $pdo->prepare(
    "SELECT m.MaMon, m.TenMon, m.MoTa, m.TrangThai,
            (SELECT COUNT(*) FROM CAUHOI c  WHERE c.MaMon = m.MaMon) AS so_cau,
            (SELECT COUNT(*) FROM TRANDAU t WHERE t.MaMon = m.MaMon) AS so_tran
     FROM MONHOC m $where
     ORDER BY m.MaMon
     LIMIT " . $p->limit() . ' OFFSET ' . $p->offset()
);
$st->execute($tham);
$dsMon = $st->fetchAll();

// ---- Môn đang sửa (?sua=ID) ----
$monSua = null;
if (isset($_GET['sua'])) {
    $st = $pdo->prepare("SELECT MaMon, TenMon, MoTa FROM MONHOC WHERE MaMon = ? AND TrangThai IN ('Đang mở','Đã ẩn')");
    $st->execute([(int) $_GET['sua']]);
    $monSua = $st->fetch() ?: null;
}

// ---- Loại kỳ thi ----
$dsKT = $pdo->query(
    'SELECT k.MaLoaiKT, k.TenLoaiKT, k.MoTa,
            (SELECT COUNT(*) FROM CAUHOI c WHERE c.MaLoaiKT = k.MaLoaiKT) AS so_cau
     FROM LOAIKYTHI k ORDER BY k.MaLoaiKT'
)->fetchAll();
$ktSua = null;
if (isset($_GET['sua_kt'])) {
    foreach ($dsKT as $k) {
        if ((int) $k['MaLoaiKT'] === (int) $_GET['sua_kt']) {
            $ktSua = $k;
        }
    }
}

$tieuDe  = 'Môn học, loại kỳ thi';
$mucChon = 'monhoc';
$cssTrang = 'monhoc';
$jsTrang  = 'monhoc';
require __DIR__ . '/layout/header.php';
?>
<h2>Môn học và loại kỳ thi</h2>

<?php if ($flash): ?>
  <div class="<?= $flash[0] === 'ok' ? 'thanhcong' : 'loi' ?>"><?= e($flash[1]) ?></div>
<?php endif; ?>

<?php if ($tongCho > 0): ?>
<h3 id="de-xuat" style="scroll-margin-top:80px">Môn sinh viên đề xuất, chờ duyệt (<?= $tongCho ?>)</h3>
<div class="card">
  <table>
    <tr><th>Tên môn</th><th>Mô tả</th><th>Người đề xuất</th><th></th></tr>
    <?php foreach ($dsCho as $r): ?>
      <tr>
        <td><?= e($r['TenMon']) ?></td>
        <td><?= e((string) $r['MoTa']) ?></td>
        <td><?= $r['HoTen'] !== null ? e($r['HoTen']) . ' <span class="nhan-phu">(' . e($r['Email']) . ')</span>' : '-' ?></td>
        <td class="khong-ngat">
          <form method="post" action="" class="form-dong">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $r['MaMon'] ?>">
            <button type="submit" name="hanh_dong" value="duyet_mon" class="btn">Duyệt</button>
            <button type="submit" name="hanh_dong" value="tu_choi_mon" class="btn btn-phu"
                    data-xac-nhan="Từ chối và xóa đề xuất này?">Từ chối</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
  <?= $pCho->links() ?>
</div>
<?php endif; ?>

<h3><?= $monSua ? 'Sửa môn học' : 'Thêm môn học' ?></h3>
<div class="card">
  <form method="post" action="">
    <?= csrf_field() ?>
    <input type="hidden" name="hanh_dong" value="<?= $monSua ? 'sua_mon' : 'them_mon' ?>">
    <?php if ($monSua): ?><input type="hidden" name="id" value="<?= (int) $monSua['MaMon'] ?>"><?php endif; ?>
    <label for="ten_mon">Tên môn (tối đa 150 ký tự)</label>
    <input type="text" id="ten_mon" name="ten_mon" maxlength="150" required value="<?= e($monSua['TenMon'] ?? '') ?>">
    <label for="mo_ta">Mô tả (không bắt buộc, tối đa 500 ký tự)</label>
    <textarea id="mo_ta" name="mo_ta" rows="2" maxlength="500"><?= e((string) ($monSua['MoTa'] ?? '')) ?></textarea>
    <p>
      <button type="submit" class="btn"><?= $monSua ? 'Lưu thay đổi' : 'Thêm môn' ?></button>
      <?php if ($monSua): ?><a class="btn btn-phu" href="<?= e($sach) ?>">Hủy sửa</a><?php endif; ?>
    </p>
  </form>
</div>

<h3>Danh sách môn học</h3>
<form method="get" action="" class="card form-loc">
  <input type="text" name="q" value="<?= e($q) ?>" placeholder="Tìm theo tên hoặc mô tả" class="o-tim">
  <select name="tt">
    <option value="">Mọi trạng thái</option>
    <option value="Đang mở"<?= $tt === 'Đang mở' ? ' selected' : '' ?>>Đang mở</option>
    <option value="Đã ẩn"<?= $tt === 'Đã ẩn' ? ' selected' : '' ?>>Đã ẩn</option>
  </select>
  <button type="submit" class="btn">Tìm</button>
  <a class="btn btn-phu" href="<?= e($sach) ?>">Xóa lọc</a>
</form>

<div class="card">
  <?php if (!$dsMon): ?>
    <p class="nhan-phu">Không có môn học nào khớp.</p>
  <?php else: ?>
    <table>
      <tr><th>Mã</th><th>Tên môn</th><th>Mô tả</th><th>Câu hỏi</th><th>Trận</th><th>Trạng thái</th><th></th></tr>
      <?php foreach ($dsMon as $r): ?>
        <tr>
          <td><?= (int) $r['MaMon'] ?></td>
          <td><?= e($r['TenMon']) ?></td>
          <td><?= e((string) $r['MoTa']) ?></td>
          <td><?= (int) $r['so_cau'] ?></td>
          <td><?= (int) $r['so_tran'] ?></td>
          <td><?= $r['TrangThai'] === 'Đã ẩn' ? '<span class="mau-do dam">Đã ẩn</span>' : 'Đang mở' ?></td>
          <td class="khong-ngat">
            <a class="btn btn-phu" href="?<?= e(http_build_query(array_merge($_GET, ['sua' => (int) $r['MaMon']]))) ?>">Sửa</a>
            <form method="post" action="" class="form-dong">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int) $r['MaMon'] ?>">
              <?php if ($r['TrangThai'] === 'Đang mở'): ?>
                <button type="submit" name="hanh_dong" value="an_mon" class="btn btn-phu"
                        data-xac-nhan="Ẩn môn này? Sinh viên sẽ không chọn được để tìm trận nữa.">Ẩn</button>
              <?php else: ?>
                <button type="submit" name="hanh_dong" value="hien_mon" class="btn">Hiện lại</button>
              <?php endif; ?>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
  <?= $p->links() ?>
  <p class="nhan-phu">Môn đã có câu hỏi hoặc trận đấu không xóa được, chỉ ẩn (A-15).</p>
</div>

<h3><?= $ktSua ? 'Sửa loại kỳ thi' : 'Loại kỳ thi' ?></h3>
<div class="card">
  <table>
    <tr><th>Mã</th><th>Tên loại kỳ thi</th><th>Mô tả</th><th>Câu hỏi gắn riêng</th><th></th></tr>
    <?php foreach ($dsKT as $k): ?>
      <tr>
        <td><?= (int) $k['MaLoaiKT'] ?></td>
        <td><?= e($k['TenLoaiKT']) ?></td>
        <td><?= e((string) $k['MoTa']) ?></td>
        <td><?= (int) $k['so_cau'] ?></td>
        <td><a class="btn btn-phu" href="?sua_kt=<?= (int) $k['MaLoaiKT'] ?>">Sửa</a></td>
      </tr>
    <?php endforeach; ?>
  </table>

  <form method="post" action="" class="form-loaikt">
    <?= csrf_field() ?>
    <input type="hidden" name="hanh_dong" value="<?= $ktSua ? 'sua_kt' : 'them_kt' ?>">
    <?php if ($ktSua): ?><input type="hidden" name="id" value="<?= (int) $ktSua['MaLoaiKT'] ?>"><?php endif; ?>
    <label for="ten_kt"><?= $ktSua ? 'Tên loại kỳ thi' : 'Thêm loại kỳ thi mới' ?> (tối đa 50 ký tự)</label>
    <input type="text" id="ten_kt" name="ten_kt" maxlength="50" required value="<?= e($ktSua['TenLoaiKT'] ?? '') ?>">
    <label for="mo_ta_kt">Mô tả (không bắt buộc)</label>
    <input type="text" id="mo_ta_kt" name="mo_ta_kt" maxlength="500" value="<?= e((string) ($ktSua['MoTa'] ?? '')) ?>">
    <p>
      <button type="submit" class="btn"><?= $ktSua ? 'Lưu thay đổi' : 'Thêm loại kỳ thi' ?></button>
      <?php if ($ktSua): ?><a class="btn btn-phu" href="<?= e($sach) ?>">Hủy sửa</a><?php endif; ?>
    </p>
  </form>
  <p class="nhan-phu">Loại kỳ thi không xóa được vì câu hỏi và trận đấu tham chiếu tới. Câu hỏi để trống loại kỳ thi dùng được cho cả hai kỳ (A-19).</p>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>