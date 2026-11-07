<?php
// Thêm / sửa câu hỏi cùng đáp án (UC13: A-18, A-19, A-21, A-44).
// Đặt tại: elo_quiz/admin/cauhoi_form.php   (?id=<MaCauHoi> để sửa, không có id là thêm mới)
//
// Quy tắc sửa (đã đổi so với bản cũ):
//  - Câu CHƯA dùng trong trận: sửa tự do (môn, loại kỳ thi, độ khó, nội dung, 2-4 phương án, giải thích).
//  - Câu ĐÃ dùng trong trận (kể cả trận đã kết thúc): vẫn sửa được để chữa lỗi nhập sai, nhưng chỉ sửa
//    nội dung, độ khó, giải thích, chữ của từng phương án và đáp án đúng. Giữ nguyên môn, loại kỳ thi và số
//    phương án vì bài làm cũ (CHITIETTRALOI) tham chiếu tới từng phương án. Mỗi lần sửa ghi nhật ký (A-40).
//    Điểm và Elo của các trận cũ KHÔNG được tính lại.
//  - Câu đang nằm trong trận chưa kết thúc ('Đã ghép', 'Đang diễn ra'): tạm khóa, chỉ xem.
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/CauHoiHelper.php';
Auth::requireAdmin(BASE_URL . '/admin/login.php');   // A-04

$pdo  = Database::pdo();
$id   = (int) ($_GET['id'] ?? 0);
$danhSach = BASE_URL . '/admin/cauhoi.php';

$tieuDe  = $id > 0 ? 'Sửa câu hỏi' : 'Thêm câu hỏi';
$mucChon = 'cauhoi';

// Đếm số trận đã dùng câu này và số trận chưa kết thúc đang dùng câu này
$demTran = static function (PDO $pdo, int $maCau): array {
    $st = $pdo->prepare('SELECT COUNT(DISTINCT MaTran) FROM CAUHOITRANDAU WHERE MaCauHoi = ?');
    $st->execute([$maCau]);
    $soTran = (int) $st->fetchColumn();
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM CAUHOITRANDAU c JOIN TRANDAU t ON t.MaTran = c.MaTran
         WHERE c.MaCauHoi = ? AND t.TrangThai IN ('Đã ghép', 'Đang diễn ra')"
    );
    $st->execute([$maCau]);
    return [$soTran, (int) $st->fetchColumn()];
};

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
    $st = $pdo->prepare('SELECT MaDapAn, NoiDungDapAn, LaDapAnDung FROM DAPAN WHERE MaCauHoi = ? ORDER BY MaDapAn');
    $st->execute([$id]);
    $dapCu = $st->fetchAll();
}

$soTran  = 0;
$dangTran = 0;
if ($id > 0) {
    [$soTran, $dangTran] = $demTran($pdo, $id);
}
$daDung = $soTran > 0;      // đã dùng: sửa hạn chế, có ghi nhật ký
$khoa   = $dangTran > 0;    // đang trong trận chưa kết thúc: chỉ xem

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
        $choPhep    = true;
        $cheDoDaDung = false;
        if ($id > 0) {
            // Khóa dòng câu hỏi rồi đếm lại trong cùng giao dịch: khóa này và khóa khóa ngoại của một trận
            // đang được tạo loại trừ nhau, nên không có chuyện sửa đúng lúc câu được đưa vào trận.
            $st = $pdo->prepare('SELECT MaCauHoi FROM CAUHOI WHERE MaCauHoi = ? FOR UPDATE');
            $st->execute([$id]);
            [$soTranTx, $dangTranTx] = $demTran($pdo, $id);
            if ($dangTranTx > 0) {
                $loi[]   = 'Câu hỏi đang nằm trong trận chưa kết thúc nên tạm thời chưa sửa được. Hãy chờ trận kết thúc rồi sửa.';
                $choPhep = false;
            } elseif ($soTranTx > 0) {
                $cheDoDaDung = true;
            }
        }

        if ($choPhep && $cheDoDaDung) {
            // ===== Câu đã dùng trong trận: sửa tại chỗ, giữ nguyên mã câu và mã từng phương án =====
            $st = $pdo->prepare('SELECT MaMon, MaLoaiKT, NoiDung, GiaiThich, DoKho FROM CAUHOI WHERE MaCauHoi = ?');
            $st->execute([$id]);
            $cu = $st->fetch();
            $st = $pdo->prepare('SELECT MaDapAn, NoiDungDapAn, LaDapAnDung FROM DAPAN WHERE MaCauHoi = ? ORDER BY MaDapAn');
            $st->execute([$id]);
            $dapGoc = $st->fetchAll();
            $n = count($dapGoc);

            $in['mon']   = (int) $cu['MaMon'];                                   // không đổi môn
            $in['loai']  = $cu['MaLoaiKT'] === null ? '' : (string) $cu['MaLoaiKT'];   // không đổi loại kỳ thi
            $in['dapan'] = array_slice(is_array($_POST['dapan'] ?? null) ? array_values($_POST['dapan']) : [], 0, $n);

            [$loi, $sach] = CauHoiHelper::kiemTra($pdo, $in, $id);
            if (!$loi && count($sach['dapan']) !== $n) {
                $loi[] = 'Câu đã dùng trong trận phải giữ nguyên ' . $n . ' phương án, không để trống phương án nào.';
            }

            if (!$loi) {
                // Liệt kê những gì thay đổi để ghi nhật ký
                $doi = [];
                if (CauHoiHelper::chuan((string) $cu['NoiDung']) !== $sach['noidung']) { $doi[] = 'nội dung'; }
                if (CauHoiHelper::chuan((string) $cu['GiaiThich']) !== (string) ($sach['giaithich'] ?? '')) { $doi[] = 'giải thích'; }
                if ($cu['DoKho'] !== $sach['dokho']) { $doi[] = 'độ khó (' . $cu['DoKho'] . ' → ' . $sach['dokho'] . ')'; }
                $dungCu = 0;
                foreach ($dapGoc as $i => $d) {
                    if (CauHoiHelper::chuan((string) $d['NoiDungDapAn']) !== $sach['dapan'][$i]) { $doi[] = 'phương án ' . chr(65 + $i); }
                    if ((int) $d['LaDapAnDung'] === 1) { $dungCu = $i; }
                }
                if ($dungCu !== (int) $sach['dung']) {
                    $doi[] = 'đáp án đúng (' . chr(65 + $dungCu) . ' → ' . chr(65 + (int) $sach['dung']) . ')';
                }

                if (!$doi) {
                    $pdo->rollBack();
                    $_SESSION['ch_flash'] = ['ok', 'Không có thay đổi nào để lưu ở câu hỏi #' . $id . '.'];
                    redirect($danhSach);
                }

                $pdo->prepare('UPDATE CAUHOI SET NoiDung = ?, GiaiThich = ?, DoKho = ? WHERE MaCauHoi = ?')
                    ->execute([$sach['noidung'], $sach['giaithich'], $sach['dokho'], $id]);
                // Bỏ cờ đúng của cả câu trước, rồi đặt lại: cột sinh DapAnDungDuy chỉ cho một dòng đúng mỗi câu
                $pdo->prepare('UPDATE DAPAN SET LaDapAnDung = 0 WHERE MaCauHoi = ?')->execute([$id]);
                $up = $pdo->prepare('UPDATE DAPAN SET NoiDungDapAn = ?, LaDapAnDung = ? WHERE MaDapAn = ? AND MaCauHoi = ?');
                foreach ($dapGoc as $i => $d) {
                    $up->execute([$sach['dapan'][$i], $i === (int) $sach['dung'] ? 1 : 0, (int) $d['MaDapAn'], $id]);
                }
                NhatKy::ghi('Sửa câu hỏi đã dùng', 'CAUHOI #' . $id, 'Đã dùng trong ' . $soTranTx . ' trận. Thay đổi: ' . implode('; ', $doi) . '.');
                $pdo->commit();
                $_SESSION['ch_flash'] = ['ok', 'Đã lưu câu hỏi #' . $id . ' (điểm và Elo của các trận cũ không đổi).'];
                redirect($danhSach);
            }
        } elseif ($choPhep) {
            // ===== Câu chưa dùng trong trận (hoặc thêm mới): như cũ =====
            [$loi, $sach] = CauHoiHelper::kiemTra($pdo, $in, $id);
            if (!$loi) {
                if ($id > 0) {
                    $pdo->prepare('UPDATE CAUHOI SET MaMon = ?, MaLoaiKT = ?, NoiDung = ?, GiaiThich = ?, DoKho = ? WHERE MaCauHoi = ?')
                        ->execute([$sach['mon'], $sach['loai'], $sach['noidung'], $sach['giaithich'], $sach['dokho'], $id]);
                    // Chưa có bài làm nào tham chiếu đáp án: ghi lại toàn bộ đáp án
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
    if ($cau) {   // câu đã dùng: môn và loại kỳ thi luôn lấy từ CSDL
        $v['mon']  = (int) $cau['MaMon'];
        $v['loai'] = $cau['MaLoaiKT'] === null ? '' : (string) $cau['MaLoaiKT'];
    }
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

$tenMon = '';
foreach ($dsMon as $m) { if ((int) $m['MaMon'] === $v['mon']) { $tenMon = $m['TenMon']; } }
$tenKT = 'Cả hai kỳ';
foreach ($dsKT as $k) { if ((string) $k['MaLoaiKT'] === $v['loai']) { $tenKT = $k['TenLoaiKT']; } }
$soO = $daDung ? count($dapCu) : 4;   // câu đã dùng: giữ đúng số phương án đang có

$cssTrang = 'cauhoi_form';
require __DIR__ . '/layout/header.php';
?>
<p><a href="<?= e($danhSach) ?>">&laquo; Ngân hàng câu hỏi</a></p>
<h2><?= e($tieuDe) ?><?= $id > 0 ? ' #' . $id : '' ?></h2>

<?php if ($loi): ?>
  <div class="loi"><?php foreach ($loi as $m): ?><div><?= e($m) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<?php if ($khoa): ?>
  <div class="loi">Câu hỏi này đang nằm trong <?= $dangTran ?> trận chưa kết thúc nên tạm thời chỉ xem. Hãy chờ trận kết thúc rồi sửa.</div>
  <div class="card">
    <table>
      <tr><th class="cot-nhan">Môn</th><td><?= e($tenMon) ?></td></tr>
      <tr><th>Loại kỳ thi</th><td><?= e($tenKT) ?></td></tr>
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
  <?php if ($daDung): ?>
    <div class="luu-y">
      Câu hỏi này đã được dùng trong <?= $soTran ?> trận đấu. Bạn vẫn sửa được để chữa lỗi (nội dung, độ khó, chữ của từng phương án, đáp án đúng, giải thích).
      Môn, loại kỳ thi và số phương án được giữ nguyên. <b>Điểm và Elo của các trận cũ không được tính lại</b>, và màn xem lại sẽ hiện nội dung đã sửa.
      Mỗi lần sửa được ghi vào nhật ký quản trị.
    </div>
  <?php endif; ?>

  <form method="post" action="" class="card">
    <?= csrf_field() ?>

    <?php if ($daDung): ?>
      <label>Môn học</label>
      <p class="dam"><?= e($tenMon) ?></p>
      <label>Loại kỳ thi</label>
      <p class="dam"><?= e($tenKT) ?></p>
    <?php else: ?>
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
    <?php endif; ?>

    <label for="dokho">Độ khó</label>
    <select id="dokho" name="dokho">
      <?php foreach (CauHoiHelper::DO_KHO as $d): ?>
        <option value="<?= e($d) ?>"<?= $v['dokho'] === $d ? ' selected' : '' ?>><?= e($d) ?></option>
      <?php endforeach; ?>
    </select>

    <label for="noidung">Nội dung câu hỏi (tối đa <?= CauHoiHelper::TOI_DA_NOI_DUNG ?> ký tự)</label>
    <textarea id="noidung" name="noidung" rows="3" maxlength="<?= CauHoiHelper::TOI_DA_NOI_DUNG ?>" required><?= e($v['noidung']) ?></textarea>

    <label><?= $daDung
        ? 'Các phương án (giữ nguyên ' . $soO . ' phương án, không để trống; chọn nút tròn ở đáp án đúng)'
        : 'Các phương án (2 đến 4, bỏ trống ô không dùng; chọn nút tròn ở đáp án đúng)' ?></label>
    <?php for ($i = 0; $i < $soO; $i++): ?>
      <div class="hang-dapan">
        <input type="radio" name="dung" value="<?= $i ?>" id="dung<?= $i ?>"<?= $v['dung'] === $i ? ' checked' : '' ?>>
        <span class="dam"><?= chr(65 + $i) ?>.</span>
        <input type="text" name="dapan[<?= $i ?>]" maxlength="<?= CauHoiHelper::TOI_DA_DAP_AN ?>" value="<?= e((string) $v['dapan'][$i]) ?>"<?= $daDung ? ' required' : '' ?>>
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