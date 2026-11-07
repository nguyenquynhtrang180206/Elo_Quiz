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
// ve = chuỗi truy vấn của danh sách lúc bấm Sửa (bộ lọc, trang danh sách, trang bản đồ). Chỉ nhận các khóa
// quen thuộc, nên không thể bị lợi dụng để chuyển sang địa chỉ khác. Lưu xong thì quay về đúng chỗ đó.
$veTho = [];
parse_str((string) ($_GET['ve'] ?? ''), $veTho);
$veSach = [];
foreach (['q', 'mon', 'kt', 'dk', 'tt', 'loc', 'page', 'bd'] as $khoa) {
    if (isset($veTho[$khoa]) && is_scalar($veTho[$khoa]) && (string) $veTho[$khoa] !== '') {
        $veSach[$khoa] = (string) $veTho[$khoa];
    }
}
$veChuoi = $veSach ? http_build_query($veSach) : '';
if ($veChuoi !== '') { $danhSach .= '?' . $veChuoi; }
$veNeo = static fn (int $maCau): string => $danhSach . '#cau-' . $maCau;   // nhảy đúng tới dòng vừa sửa

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
                    redirect($veNeo($id));
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
                redirect($veNeo($id));
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
                redirect($veNeo((int) $maMoi));
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
    // ?dokho= (từ dấu + trên bản đồ phủ ở cauhoi.php) chọn sẵn độ khó; giá trị lạ thì dùng Trung bình
    $dokhoGoiY = (string) ($_GET['dokho'] ?? '');
    $v = ['mon' => (int) ($_GET['mon'] ?? 0), 'loai' => '', 'dokho' => in_array($dokhoGoiY, CauHoiHelper::DO_KHO, true) ? $dokhoGoiY : 'Trung bình', 'noidung' => '', 'giaithich' => '',
          'dapan' => ['', '', '', ''], 'dung' => -1];
}

$tenMon = '';
foreach ($dsMon as $m) { if ((int) $m['MaMon'] === $v['mon']) { $tenMon = $m['TenMon']; } }
$tenKT = 'Cả hai kỳ';
foreach ($dsKT as $k) { if ((string) $k['MaLoaiKT'] === $v['loai']) { $tenKT = $k['TenLoaiKT']; } }
$soO = $daDung ? count($dapCu) : 4;   // câu đã dùng: giữ đúng số phương án đang có

$cssTrang = 'cauhoi_form';
$jsTrang  = 'cauhoi_form';
require __DIR__ . '/layout/header.php';

// Nhãn hạng theo độ khó (hiện trên thẻ độ khó và thẻ xem trước)
$hangTheoKho = ['Dễ' => ['Hạng Đồng', 'h-de'], 'Trung bình' => ['Hạng Bạc', 'h-tb'], 'Khó' => ['Hạng Vàng', 'h-kho']];
$huyChuong = static function (string $lop = ''): string {
    return '<svg class="cf-hc ' . $lop . '" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
        . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7.21 15 2.66 7.14a2 2 0 0 1 .13-2.2L4.4 2.8A2 2 0 0 1 6 2h12a2 2 0 0 1 1.6.8l1.6 2.14a2 2 0 0 1 .14 2.2L16.79 15"/>'
        . '<path d="M11 12 5.12 2.2"/><path d="m13 12 5.88-9.8"/><path d="M8 7h8"/><circle cx="12" cy="17" r="5"/></svg>';
};
$hangHienTai = $hangTheoKho[$v['dokho']] ?? $hangTheoKho['Trung bình'];
$icTick = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>';
?>
<div class="cf-trang">

  <div class="cf-dau">
    <div>
      <a class="cf-quaylai" href="<?= e($danhSach) ?>">&laquo; Ngân hàng câu hỏi</a>
      <h2><?= e($tieuDe) ?><?= $id > 0 ? ' #' . $id : '' ?></h2>
    </div>
    <?php if (!$khoa): ?>
      <div class="cf-vong" id="cfVong" style="--p:0" role="img" aria-label="Số điều kiện đã đạt"><span id="cfVongSo">0/5</span></div>
    <?php endif; ?>
  </div>

  <?php if ($loi): ?>
    <div class="cf-loi" role="alert"><?php foreach ($loi as $m): ?><div><?= e($m) ?></div><?php endforeach; ?></div>
  <?php endif; ?>

<?php if ($khoa): ?>
  <div class="cf-loi">Câu hỏi này đang nằm trong <?= $dangTran ?> trận chưa kết thúc nên tạm thời chỉ xem. Hãy chờ trận kết thúc rồi sửa.</div>
  <div class="cf-khoi">
    <table class="cf-bang-xem">
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
    <div class="cf-luu-y" role="note">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
      <span>Câu này đã dùng trong <?= (int) $soTran ?> trận. Môn học, loại kỳ thi và số phương án được giữ nguyên. Điểm và Elo của các trận cũ không được tính lại, màn xem lại sẽ hiện nội dung đã sửa và mỗi lần sửa được ghi vào nhật ký quản trị.</span>
    </div>
  <?php endif; ?>

  <form method="post" action="" id="cfForm" novalidate
        data-moi="<?= $id > 0 ? '0' : '1' ?>" data-dadung="<?= $daDung ? '1' : '0' ?>" data-so-o="<?= (int) $soO ?>"
        data-toida-nd="<?= CauHoiHelper::TOI_DA_NOI_DUNG ?>" data-toida-gt="<?= CauHoiHelper::TOI_DA_GIAI_THICH ?>">
    <?= csrf_field() ?>

    <div class="cf-luoi">

      <!-- ===== Cột trái: thông tin câu hỏi ===== -->
      <section class="cf-khoi">
        <div class="cf-truong">
          <label for="mon">Môn học</label>
          <select id="mon" name="mon"<?= $daDung ? ' disabled' : '' ?>>
            <option value="0">-- Chọn môn --</option>
            <?php foreach ($dsMon as $m): ?>
              <option value="<?= (int) $m['MaMon'] ?>"<?= (int) $m['MaMon'] === $v['mon'] ? ' selected' : '' ?>><?= e($m['TenMon']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="cf-truong">
          <label for="loai">Loại kỳ thi</label>
          <select id="loai" name="loai"<?= $daDung ? ' disabled' : '' ?>>
            <option value="">Dùng được cho cả hai kỳ</option>
            <?php foreach ($dsKT as $k): ?>
              <option value="<?= (int) $k['MaLoaiKT'] ?>"<?= $v['loai'] === (string) $k['MaLoaiKT'] ? ' selected' : '' ?>><?= e($k['TenLoaiKT']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="cf-truong">
          <span class="cf-nhan" id="cfNhanKho">Độ khó</span>
          <div class="cf-dokho" role="radiogroup" aria-labelledby="cfNhanKho">
            <?php foreach (CauHoiHelper::DO_KHO as $d): [$tenHang, $lopHang] = $hangTheoKho[$d]; ?>
              <label class="cf-the-kho <?= $lopHang ?>">
                <input type="radio" name="dokho" value="<?= e($d) ?>"<?= $v['dokho'] === $d ? ' checked' : '' ?>>
                <span class="cf-the-nd">
                  <?= $huyChuong($lopHang) ?>
                  <b><?= e($d) ?></b>
                  <small><?= e($tenHang) ?></small>
                </span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="cf-truong">
          <div class="cf-hang-nhan">
            <label for="noidung">Nội dung câu hỏi</label>
            <span class="cf-dem" id="cfDemND">0/<?= CauHoiHelper::TOI_DA_NOI_DUNG ?></span>
          </div>
          <textarea id="noidung" name="noidung" rows="5" maxlength="<?= CauHoiHelper::TOI_DA_NOI_DUNG ?>"><?= e($v['noidung']) ?></textarea>
        </div>

        <div class="cf-truong">
          <div class="cf-hang-nhan">
            <label for="giaithich">Giải thích (không bắt buộc, hiện ở màn xem lại)</label>
            <span class="cf-dem" id="cfDemGT">0/<?= CauHoiHelper::TOI_DA_GIAI_THICH ?></span>
          </div>
          <textarea id="giaithich" name="giaithich" rows="4" maxlength="<?= CauHoiHelper::TOI_DA_GIAI_THICH ?>"><?= e($v['giaithich']) ?></textarea>
        </div>
      </section>

      <!-- ===== Cột phải: thẻ xem trước, gõ thẳng vào thẻ ===== -->
      <section class="cf-xem" aria-label="Xem trước câu hỏi">
        <div class="cf-xem-dau">
          <span id="cfXemMon">Chưa chọn môn · Cả hai kỳ</span>
          <span class="cf-hang <?= $hangHienTai[1] ?>" id="cfXemHang"><?= $huyChuong($hangHienTai[1]) ?><span id="cfXemHangTen"><?= e($hangHienTai[0]) ?></span></span>
        </div>
        <div class="cf-xem-than">
          <p class="cf-xem-cau" id="cfXemCau">Nội dung câu hỏi sẽ hiện ở đây</p>

          <div class="cf-dsdap">
            <?php for ($i = 0; $i < $soO; $i++): ?>
              <div class="cf-dap" data-i="<?= $i ?>">
                <span class="cf-chu"><?= chr(65 + $i) ?></span>
                <input type="text" name="dapan[<?= $i ?>]" maxlength="<?= CauHoiHelper::TOI_DA_DAP_AN ?>" placeholder="Phương án <?= chr(65 + $i) ?>"
                       aria-label="Phương án <?= chr(65 + $i) ?>" autocomplete="off" value="<?= e((string) $v['dapan'][$i]) ?>">
                <label class="cf-chon" title="Chọn làm đáp án đúng">
                  <input type="radio" name="dung" value="<?= $i ?>" aria-label="Chọn phương án <?= chr(65 + $i) ?> làm đáp án đúng"<?= $v['dung'] === $i ? ' checked' : '' ?>>
                  <span class="cf-tron"><?= $icTick ?></span>
                </label>
              </div>
            <?php endfor; ?>
          </div>

          <p class="cf-goi-y">Gõ thẳng vào thẻ. Bấm vòng tròn bên phải để chọn đáp án đúng.
            <?= $daDung ? 'Câu đã dùng phải giữ nguyên ' . (int) $soO . ' phương án, không để trống.' : 'Ô nào không dùng thì để trống (cần ít nhất 2 phương án).' ?></p>
        </div>
      </section>
    </div>

    <ul class="cf-kt" id="cfKt" aria-label="Điều kiện để lưu">
      <li data-k="mon"><?= $daDung ? 'Môn học được giữ nguyên' : 'Đã chọn môn học' ?></li>
      <li data-k="nd">Có nội dung câu hỏi</li>
      <li data-k="pa"><?= $daDung ? 'Đủ ' . (int) $soO . ' phương án' : 'Có ít nhất 2 phương án' ?></li>
      <li data-k="khac">Phương án không trùng nhau</li>
      <li data-k="dung">Đã chọn đáp án đúng</li>
    </ul>

    <div class="cf-nut">
      <button type="button" class="cf-btn" id="cfChayThu">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>
        Chạy thử như người chơi
      </button>
      <button type="submit" class="cf-btn cf-btn-chinh" id="cfLuu">Lưu câu hỏi</button>
      <button type="button" class="cf-btn" id="cfLamMoi">Làm mới</button>
    </div>
    <p class="cf-thongbao" id="cfThongBao" role="status" aria-live="polite"></p>
  </form>

  <!-- Hộp chạy thử: giả lập màn trả lời của người chơi, không ghi gì vào CSDL -->
  <dialog class="cf-dlg" id="cfDlg" aria-labelledby="cfDlgTieuDe">
    <div class="cf-xem cf-xem-dlg">
      <div class="cf-xem-dau">
        <span id="cfDlgTieuDe">Chạy thử</span>
        <span class="cf-hang" id="cfDlgHang"></span>
      </div>
      <div class="cf-xem-than">
        <p class="cf-xem-cau" id="cfDlgCau"></p>
        <div class="cf-dlg-dap" id="cfDlgDap"></div>
        <p class="cf-dlg-kq" id="cfDlgKq" role="status" aria-live="polite"></p>
        <p class="cf-dlg-gt" id="cfDlgGt" hidden></p>
        <div class="cf-nut">
          <button type="button" class="cf-btn" id="cfDlgLai">Chọn lại</button>
          <button type="button" class="cf-btn cf-btn-chinh" id="cfDlgDong">Đóng</button>
        </div>
      </div>
    </div>
  </dialog>

<?php endif; ?>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>