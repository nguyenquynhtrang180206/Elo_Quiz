<?php
// Thêm / sửa câu hỏi cùng đáp án (UC13: A-18, A-19, A-21, A-44).
// Đặt tại: elo_quiz/admin/cauhoi_form.php   (?id=<MaCauHoi> để sửa, không có id là thêm mới)
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/CauHoiHelper.php';
Auth::requireAdmin(BASE_URL . '/admin/login.php');   // A-04

$pdo  = Database::pdo();
$id   = (int) ($_GET['id'] ?? 0);
$danhSach = BASE_URL . '/admin/cauhoi.php';

$tieuDe  = $id > 0 ? 'Sửa câu hỏi' : 'Thêm câu hỏi';
$mucChon = 'cauhoi';

// ---- Nạp câu hỏi khi sửa ----
$cau    = null;
$dapCu  = [];
if ($id > 0) {
    $st = $pdo->prepare('SELECT MaCauHoi, MaMon, MaLoaiKT, NoiDung, GiaiThich, DoKho, TrangThai FROM CAUHOI WHERE MaCauHoi = ?');
    $st->execute([$id]);
    $cau = $st->fetch();
    if (!$cau) {
        http_response_code(404);
        $cssTrang = 'cauhoi_form';
        require __DIR__ . '/layout/header.php';
        echo '<h2>' . e($tieuDe) . '</h2><div class="card">Không tìm thấy câu hỏi này. <a href="' . e($danhSach) . '">Về danh sách</a></div>';
        require __DIR__ . '/layout/footer.php';
        exit;
    }
    $st = $pdo->prepare('SELECT NoiDungDapAn, LaDapAnDung FROM DAPAN WHERE MaCauHoi = ? ORDER BY MaDapAn');
    $st->execute([$id]);
    $dapCu = $st->fetchAll();
}

// ---- A-21: câu đã dùng trong trận thì chỉ xem ----
$soTran = 0;
if ($id > 0) {
    $st = $pdo->prepare('SELECT COUNT(DISTINCT MaTran) FROM CAUHOITRANDAU WHERE MaCauHoi = ?');
    $st->execute([$id]);
    $soTran = (int) $st->fetchColumn();
}
$khoa = $soTran > 0;

$dsMon = $pdo->query("SELECT MaMon, TenMon FROM MONHOC WHERE TrangThai IN ('Đang mở','Đã ẩn') ORDER BY TenMon")->fetchAll();
$dsKT  = $pdo->query('SELECT MaLoaiKT, TenLoaiKT FROM LOAIKYTHI ORDER BY MaLoaiKT')->fetchAll();

$loi = [];

// ---- Xử lý lưu ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $in = [
        'mon'       => $_POST['mon'] ?? 0,
        'loai'      => $_POST['loai'] ?? '',
        'dokho'     => $_POST['dokho'] ?? '',
        'noidung'   => $_POST['noidung'] ?? '',
        'giaithich' => $_POST['giaithich'] ?? '',
        'dapan'     => is_array($_POST['dapan'] ?? null) ? array_slice($_POST['dapan'], 0, 4) : [],
        'dung'      => $_POST['dung'] ?? -1,
    ];

    try {
        $pdo->beginTransaction();
        $choPhep = true;
        if ($id > 0) {
            // Khóa dòng câu hỏi rồi kiểm tra lại "đã dùng chưa" trong cùng giao dịch: nếu một trận đang
            // được tạo cùng lúc thì khóa khóa ngoại của nó và khóa này loại trừ nhau, nên không có chuyện
            // sửa nội dung đúng lúc câu được đưa vào trận (A-21).
            $st = $pdo->prepare('SELECT MaCauHoi FROM CAUHOI WHERE MaCauHoi = ? FOR UPDATE');
            $st->execute([$id]);
            $st = $pdo->prepare('SELECT COUNT(*) FROM CAUHOITRANDAU WHERE MaCauHoi = ?');
            $st->execute([$id]);
            if ((int) $st->fetchColumn() > 0) {
                $loi[]   = 'Câu hỏi này đã được dùng trong trận đấu nên không sửa được, chỉ ẩn được.';
                $choPhep = false;
            }
        }
        if ($choPhep) {
            [$loi, $sach] = CauHoiHelper::kiemTra($pdo, $in, $id);
            if (!$loi) {
                if ($id > 0) {
                    $pdo->prepare('UPDATE CAUHOI SET MaMon = ?, MaLoaiKT = ?, NoiDung = ?, GiaiThich = ?, DoKho = ? WHERE MaCauHoi = ?')
                        ->execute([$sach['mon'], $sach['loai'], $sach['noidung'], $sach['giaithich'], $sach['dokho'], $id]);
                    // Câu chưa dùng trong trận nên chưa có bài làm nào tham chiếu đáp án: ghi lại toàn bộ đáp án
                    $pdo->prepare('DELETE FROM DAPAN WHERE MaCauHoi = ?')->execute([$id]);
                    CauHoiHelper::ghiDapAn($pdo, $id, $sach);
                    $maMoi = $id;
                } else {
                    $maMoi = CauHoiHelper::chen($pdo, $sach);
                }
                $pdo->commit();
                $_SESSION['ch_flash'] = ['ok', ($id > 0 ? 'Đã lưu câu hỏi #' : 'Đã thêm câu hỏi #') . $maMoi . '.'];
                redirect($danhSach);
            }
        }
        $pdo->rollBack();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $loi[] = 'Lỗi cơ sở dữ liệu, chưa lưu được. Hãy thử lại.';
    }
}

// ---- Giá trị đổ vào form: ưu tiên dữ liệu vừa gửi (khi có lỗi), rồi đến dữ liệu đang sửa ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $v = [
        'mon'       => (int) ($_POST['mon'] ?? 0),
        'loai'      => (string) ($_POST['loai'] ?? ''),
        'dokho'     => (string) ($_POST['dokho'] ?? 'Trung bình'),
        'noidung'   => (string) ($_POST['noidung'] ?? ''),
        'giaithich' => (string) ($_POST['giaithich'] ?? ''),
        'dapan'     => array_pad(array_map('strval', (array) ($_POST['dapan'] ?? [])), 4, ''),
        'dung'      => (int) ($_POST['dung'] ?? -1),
    ];
} elseif ($cau) {
    $v = [
        'mon'       => (int) $cau['MaMon'],
        'loai'      => $cau['MaLoaiKT'] === null ? '' : (string) $cau['MaLoaiKT'],
        'dokho'     => $cau['DoKho'],
        'noidung'   => $cau['NoiDung'],
        'giaithich' => (string) $cau['GiaiThich'],
        'dapan'     => array_pad(array_column($dapCu, 'NoiDungDapAn'), 4, ''),
        'dung'      => -1,
    ];
    foreach ($dapCu as $i => $d) {
        if ((int) $d['LaDapAnDung'] === 1) {
            $v['dung'] = $i;
        }
    }
} else {
    $v = ['mon' => (int) ($_GET['mon'] ?? 0), 'loai' => '', 'dokho' => 'Trung bình', 'noidung' => '', 'giaithich' => '',
          'dapan' => ['', '', '', ''], 'dung' => -1];
}

$cssTrang = 'cauhoi_form';
require __DIR__ . '/layout/header.php';
?>
<p><a href="<?= e($danhSach) ?>">&laquo; Ngân hàng câu hỏi</a></p>
<h2><?= e($tieuDe) ?><?= $id > 0 ? ' #' . $id : '' ?></h2>

<?php if ($loi): ?>
  <div class="loi"><?php foreach ($loi as $m): ?><div><?= e($m) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<?php if ($khoa): ?>
  <div class="loi">Câu hỏi này đã được dùng trong <?= $soTran ?> trận đấu nên chỉ xem, không sửa nội dung và đáp án (A-21). Muốn ngừng dùng thì ẩn câu hỏi ở trang danh sách.</div>
  <div class="card">
    <table>
      <tr><th class="cot-nhan">Môn</th><td><?php foreach ($dsMon as $m) { if ((int) $m['MaMon'] === $v['mon']) { echo e($m['TenMon']); } } ?></td></tr>
      <tr><th>Loại kỳ thi</th><td><?php
        $tenKT = 'Cả hai kỳ';
        foreach ($dsKT as $k) { if ((string) $k['MaLoaiKT'] === $v['loai']) { $tenKT = $k['TenLoaiKT']; } }
        echo e($tenKT); ?></td></tr>
      <tr><th>Độ khó</th><td><?= e($v['dokho']) ?></td></tr>
      <tr><th>Trạng thái</th><td><?= e($cau['TrangThai']) ?></td></tr>
      <tr><th>Nội dung</th><td class="xuong-dong"><?= e($v['noidung']) ?></td></tr>
      <?php foreach ($dapCu as $i => $d): ?>
        <tr><th>Phương án <?= chr(65 + $i) ?><?= (int) $d['LaDapAnDung'] === 1 ? ' (đúng)' : '' ?></th>
            <td<?= (int) $d['LaDapAnDung'] === 1 ? ' class="dam mau-xanh"' : '' ?>><?= e($d['NoiDungDapAn']) ?></td></tr>
      <?php endforeach; ?>
      <tr><th>Giải thích</th><td class="xuong-dong"><?= e($v['giaithich']) ?></td></tr>
    </table>
  </div>
<?php else: ?>
  <form method="post" action="" class="card">
    <?= csrf_field() ?>

    <label for="mon">Môn học</label>
    <select id="mon" name="mon" required>
      <option value="0">-- Chọn môn --</option>
      <?php foreach ($dsMon as $m): ?>
        <option value="<?= (int) $m['MaMon'] ?>"<?= (int) $m['MaMon'] === $v['mon'] ? ' selected' : '' ?>><?= e($m['TenMon']) ?></option>
      <?php endforeach; ?>
    </select>

    <label for="loai">Loại kỳ thi</label>
    <select id="loai" name="loai">
      <option value="">Dùng được cho cả hai kỳ</option>
      <?php foreach ($dsKT as $k): ?>
        <option value="<?= (int) $k['MaLoaiKT'] ?>"<?= $v['loai'] === (string) $k['MaLoaiKT'] ? ' selected' : '' ?>><?= e($k['TenLoaiKT']) ?></option>
      <?php endforeach; ?>
    </select>

    <label for="dokho">Độ khó</label>
    <select id="dokho" name="dokho">
      <?php foreach (CauHoiHelper::DO_KHO as $d): ?>
        <option value="<?= e($d) ?>"<?= $v['dokho'] === $d ? ' selected' : '' ?>><?= e($d) ?></option>
      <?php endforeach; ?>
    </select>

    <label for="noidung">Nội dung câu hỏi (tối đa <?= CauHoiHelper::TOI_DA_NOI_DUNG ?> ký tự)</label>
    <textarea id="noidung" name="noidung" rows="3" maxlength="<?= CauHoiHelper::TOI_DA_NOI_DUNG ?>" required><?= e($v['noidung']) ?></textarea>

    <label>Các phương án (2 đến 4, bỏ trống ô không dùng; chọn nút tròn ở đáp án đúng)</label>
    <?php for ($i = 0; $i < 4; $i++): ?>
      <div class="hang-dapan">
        <input type="radio" name="dung" value="<?= $i ?>" id="dung<?= $i ?>"<?= $v['dung'] === $i ? ' checked' : '' ?>>
        <span class="dam"><?= chr(65 + $i) ?>.</span>
        <input type="text" name="dapan[<?= $i ?>]" maxlength="<?= CauHoiHelper::TOI_DA_DAP_AN ?>" value="<?= e((string) $v['dapan'][$i]) ?>">
      </div>
    <?php endfor; ?>

    <label for="giaithich">Giải thích (không bắt buộc, chỉ hiện ở màn xem lại sau trận)</label>
    <textarea id="giaithich" name="giaithich" rows="2" maxlength="<?= CauHoiHelper::TOI_DA_GIAI_THICH ?>"><?= e($v['giaithich']) ?></textarea>

    <p>
      <button type="submit" class="btn">Lưu câu hỏi</button>
      <a class="btn btn-phu" href="<?= e($danhSach) ?>">Hủy</a>
    </p>
  </form>
<?php endif; ?>
<?php require __DIR__ . '/layout/footer.php'; ?>