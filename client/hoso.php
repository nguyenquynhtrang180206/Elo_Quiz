<?php
// Hồ sơ cá nhân (C-13, C-14). Đặt tại: elo_quiz/client/hoso.php
// C-15: biểu đồ biến động Elo theo môn (Chart.js, dữ liệu từ LICHSUELO), chuyển môn và khoảng trận ngay trên trang.
require_once __DIR__ . '/../core/bootstrap.php';

$maND = Auth::requireClient(BASE_URL . '/client/dangnhap.php');
$pdo  = Database::pdo();

const AVATAR_TOI_DA = 2 * 1024 * 1024;       // 2 MB (mục 3.9)
const AVATAR_THU_MUC = 'uploads/avatars';    // đường dẫn lưu trong cột AnhDaiDien, tính từ thư mục gốc dự án

function layNguoiDung(PDO $pdo, int $maND)
{
    $st = $pdo->prepare('SELECT MaND, HoTen, Email, AnhDaiDien FROM NGUOIDUNG WHERE MaND = ?');
    $st->execute([$maND]);
    return $st->fetch();
}

$nd = layNguoiDung($pdo, $maND);
if (!$nd) {                                   // tài khoản không còn tồn tại
    unset($_SESSION['nd_id']);
    redirect(BASE_URL . '/client/dangnhap.php');
}

$loi = [];
$hoTen = $nd['HoTen'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $hoTen = trim((string) ($_POST['ho_ten'] ?? ''));

    if ($hoTen === '') {
        $loi[] = 'Họ tên không được để trống.';
    } elseif (mb_strlen($hoTen) > 100) {
        $loi[] = 'Họ tên tối đa 100 ký tự.';
    }

    // ---- Ảnh đại diện (3.9): chỉ JPG/PNG, tối đa 2 MB, kiểm tra loại file thật ----
    $duongDanMoi = null;
    $tepTam = null;
    $duoi = null;
    $f = $_FILES['anh_dai_dien'] ?? null;

    if ($f && $f['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
            $loi[] = 'Ảnh quá lớn.';
        } elseif ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            $loi[] = 'Tải ảnh lên không thành công, hãy thử lại.';
        } elseif ($f['size'] > AVATAR_TOI_DA) {
            $loi[] = 'Ảnh quá lớn';
        } else {
            // Đọc loại ảnh thật từ nội dung file, không tin tên file hay loại do trình duyệt gửi
            $thongTin = @getimagesize($f['tmp_name']);
            $kieu = $thongTin[2] ?? null;
            if ($kieu === IMAGETYPE_JPEG)     { $duoi = 'jpg'; }
            elseif ($kieu === IMAGETYPE_PNG)  { $duoi = 'png'; }
            else { $loi[] = 'Ảnh đại diện chỉ nhận file JPG hoặc PNG.'; }
            $tepTam = $f['tmp_name'];
        }
    }

    if (!$loi) {
        $anhCu = $nd['AnhDaiDien'];

        if ($tepTam !== null && $duoi !== null) {
            $thuMuc = rtrim($cfg['upload_dir'], '/\\') . '/avatars';
            if (!is_dir($thuMuc)) { @mkdir($thuMuc, 0775, true); }
            $ten = bin2hex(random_bytes(16)) . '.' . $duoi;       // tên ngẫu nhiên
            if (move_uploaded_file($tepTam, $thuMuc . '/' . $ten)) {
                $duongDanMoi = AVATAR_THU_MUC . '/' . $ten;
            } else {
                $loi[] = 'Không lưu được ảnh lên máy chủ. Kiểm tra quyền ghi thư mục uploads/avatars.';
            }
        }
    }

    if (!$loi) {
        $anhLuu = $duongDanMoi ?? $nd['AnhDaiDien'];
        $st = $pdo->prepare('UPDATE NGUOIDUNG SET HoTen = ?, AnhDaiDien = ? WHERE MaND = ?');
        $st->execute([
            $hoTen,
            $anhLuu,
            $maND,
        ]);

        // Xóa ảnh cũ sau khi đã thay ảnh mới (chỉ xóa file nằm trong uploads/avatars)
        if ($duongDanMoi !== null && !empty($anhCu) && strpos($anhCu, AVATAR_THU_MUC . '/') === 0) {
            $tepCu = rtrim($cfg['upload_dir'], '/\\') . '/avatars/' . basename($anhCu);
            if (is_file($tepCu)) { @unlink($tepCu); }
        }

        $_SESSION['flash_hoso'] = 'Đã cập nhật hồ sơ.';
        redirect(BASE_URL . '/client/hoso.php');
    }
}

$laAdminSV = Auth::laAdminSinhVien();
$emailHienThi = $laAdminSV ? 'Tài khoản quản trị' : $nd['Email'];   // tài khoản liên kết của admin dùng email nội bộ
$thanhCong = $_SESSION['flash_hoso'] ?? null;
unset($_SESSION['flash_hoso']);

// ---- C-14: Elo từng môn, số trận, thắng/thua/hòa, tỷ lệ thắng (phân trang: 4 môn/trang) ----
const ELO_MOI_TRANG = 4;

$st = $pdo->prepare('SELECT COUNT(*) FROM ELOMONHOC WHERE MaND = ?');
$st->execute([$maND]);
$pElo = new Paginator((int) $st->fetchColumn(), ELO_MOI_TRANG, 'elo_page');   // tham số riêng: ?elo_page=2

$st = $pdo->prepare(
    'SELECT m.TenMon, e.DiemElo, e.SoTran, e.SoThang, e.SoThua, e.SoHoa
       FROM ELOMONHOC e
       JOIN MONHOC m ON m.MaMon = e.MaMon
      WHERE e.MaND = ?
      ORDER BY e.DiemElo DESC, m.TenMon, m.MaMon
      LIMIT ' . $pElo->limit() . ' OFFSET ' . $pElo->offset()
);
$st->execute([$maND]);
$elo = $st->fetchAll();

// Chân trang Elo (ví dụ 10 trang): luôn hiện trang đầu và trang cuối, ở giữa là trang trước - hiện tại - sau,
// chỗ nào bị bỏ qua thì có dấu "…".
//   trang 1: 1 2 … 10 | trang 2: 1 2 3 … 10 | trang 3: 1 2 3 4 … 10 | trang 4: 1 … 3 4 5 … 10
//   trang 5: 1 … 4 5 6 … 10 | trang 6: 1 … 5 6 7 … 10 | trang 8: 1 … 7 8 9 10 | trang 9: 1 … 8 9 10
function chanTrangElo(Paginator $p): string
{
    $so = $p->soTrang();
    if ($so <= 1) {
        return '';
    }
    $hienTai = $p->trang();
    $url = function (int $n): string {
        $q = $_GET;
        $q['elo_page'] = $n;
        return '?' . http_build_query($q) . '#elo-theo-mon';   // giữ nguyên vị trí ở khung Elo
    };
    $mucTrang = function (int $i) use ($hienTai, $url): string {
        return $i === $hienTai
            ? '<strong aria-current="page">' . $i . '</strong> '
            : '<a href="' . e($url($i)) . '">' . $i . '</a> ';
    };
    $dau = '<span class="elo-dots" aria-hidden="true">…</span> ';

    $tu  = max(1, $hienTai - 1);
    $den = min($so, $hienTai + 1);

    $h = '<nav class="phantrang elo-phantrang" aria-label="Phân trang Elo theo môn">';
    if ($tu > 1) {                                  // trang đầu luôn hiện
        $h .= $mucTrang(1);
        if ($tu > 2) {
            $h .= $dau;                             // có trang bị bỏ qua giữa trang đầu và cửa sổ
        }
    }
    for ($i = $tu; $i <= $den; $i++) {
        $h .= $mucTrang($i);
    }
    if ($den < $so) {                               // trang cuối luôn hiện
        if ($den < $so - 1) {
            $h .= $dau;                             // có trang bị bỏ qua giữa cửa sổ và trang cuối
        }
        $h .= $mucTrang($so);
    }
    return $h . '</nav>';
}

// ---- C-15: dữ liệu biểu đồ biến động Elo (LICHSUELO), gom theo môn, mỗi môn giữ tối đa 200 trận gần nhất ----
const BIEUDO_TOI_DA = 200;
$st = $pdo->prepare(
    'SELECT l.MaMon, m.TenMon, l.MaTran, l.EloTruoc, l.EloSau, l.ThayDoi, l.ThoiGian
       FROM LICHSUELO l
       JOIN MONHOC m ON m.MaMon = l.MaMon
      WHERE l.MaND = ?
      ORDER BY l.ThoiGian, l.MaLichSu'
);
$st->execute([$maND]);
$bieuDo = [];
foreach ($st->fetchAll() as $r) {
    $k = (int) $r['MaMon'];
    if (!isset($bieuDo[$k])) {
        $bieuDo[$k] = ['ten' => $r['TenMon'], 'cuoi' => '', 'tran' => []];
    }
    $bieuDo[$k]['cuoi'] = $r['ThoiGian'];
    $bieuDo[$k]['tran'][] = [
        'id'    => (int) $r['MaTran'],
        'truoc' => (int) $r['EloTruoc'],
        'sau'   => (int) $r['EloSau'],
        'dd'    => (int) $r['ThayDoi'],
        'luc'   => date('d/m/Y H:i', strtotime($r['ThoiGian'])),
    ];
}
foreach ($bieuDo as $k => $b) {
    $bieuDo[$k]['tran'] = array_slice($b['tran'], -BIEUDO_TOI_DA);
}
// Môn vừa đấu gần nhất đứng đầu danh sách (và là môn được chọn sẵn)
uasort($bieuDo, fn($a, $b) => strcmp($b['cuoi'], $a['cuoi']));
$bieuDoJson = [];
foreach ($bieuDo as $k => $b) {
    $bieuDoJson[] = ['ma' => $k, 'ten' => $b['ten'], 'tran' => $b['tran']];
}

$tieuDe = 'Hồ sơ cá nhân';
$jsTrang = ['hoso_elo', 'hoso_avatar'];
$cssTrang = 'hoso';
require __DIR__ . '/layout/header.php';
?>
<div class="container">
  <h2>Hồ sơ cá nhân</h2>

  <?php if ($thanhCong): ?><div class="thanhcong"><?= e($thanhCong) ?></div><?php endif; ?>
  <?php if ($loi): ?>
    <div class="loi"><?php foreach ($loi as $m): ?><div><?= e($m) ?></div><?php endforeach; ?></div>
  <?php endif; ?>

  <div class="hoso-luoi">
    <div class="card">
      <h3>Thông tin</h3>
      <?php if (!empty($nd['AnhDaiDien'])): ?>
        <img class="avatar" src="<?= e(BASE_URL . '/' . $nd['AnhDaiDien']) ?>" alt="Ảnh đại diện">
      <?php else: ?>
        <div class="avatar-mac-dinh"><?= e(mb_strtoupper(mb_substr($nd['HoTen'], 0, 1))) ?></div>
      <?php endif; ?>

      <form method="post" action="" enctype="multipart/form-data" novalidate>
        <?= csrf_field() ?>

        <label for="anh_dai_dien">Ảnh đại diện</label>
        <input type="file" id="anh_dai_dien" name="anh_dai_dien" accept="image/jpeg,image/png">
        <div class="ghichu">Chỉ nhận JPG hoặc PNG, tối đa 2 MB. Để trống nếu không đổi ảnh.</div>

        <label for="ho_ten">Họ tên</label>
        <input type="text" id="ho_ten" name="ho_ten" maxlength="100" value="<?= e($hoTen) ?>" required>

        <label for="email">Email</label>
        <input type="email" id="email" value="<?= e($emailHienThi) ?>" readonly>

        <p><button type="submit" class="btn">Lưu thay đổi</button>
           <?php if (!$laAdminSV): ?><a class="btn btn-phu" href="<?= e(BASE_URL) ?>/client/doimatkhau.php">Đổi mật khẩu</a><?php endif; ?></p>
      </form>
    </div>

    <?php if ($laAdminSV): ?>
    <div class="card hoso-admin-tb" id="tb-admin">
      <h3>Tài khoản quản trị</h3>
      <p><strong>Đây là tài khoản quản trị</strong> nên không tham gia thi đấu: không có Elo, không có biểu đồ biến động Elo và không dùng chức năng Tìm trận.</p>
      <p class="ghichu">Bạn chỉ có thể xem Bảng xếp hạng, Cộng đồng và kiểm duyệt bài viết. Để quản lý hệ thống, hãy quay về trang quản trị.</p>
      <p><a class="btn" href="<?= e(BASE_URL) ?>/admin/index.php">Về trang quản trị</a></p>
    </div>
    <?php else: ?>
    <div class="card" id="elo-theo-mon">
      <h3>Elo theo môn</h3>
      <?php if (!$elo): ?>
        <p class="ghichu">Bạn chưa đấu trận nào. Elo sẽ xuất hiện sau trận đầu tiên.</p>
      <?php else: ?>
        <table>
          <tr><th>Môn</th><th>Elo</th><th>Trận</th><th>Thắng</th><th>Thua</th><th>Hòa</th><th>Tỷ lệ thắng</th></tr>
          <?php foreach ($elo as $r):
              $tyLe = ((int) $r['SoTran']) > 0 ? round($r['SoThang'] * 100 / $r['SoTran'], 1) : 0;
          ?>
            <tr>
              <td><?= e($r['TenMon']) ?></td>
              <td><?= (int) $r['DiemElo'] ?></td>
              <td><?= (int) $r['SoTran'] ?></td>
              <td><?= (int) $r['SoThang'] ?></td>
              <td><?= (int) $r['SoThua'] ?></td>
              <td><?= (int) $r['SoHoa'] ?></td>
              <td><?= e((string) $tyLe) ?>%</td>
            </tr>
          <?php endforeach; ?>
        </table>
        <?= chanTrangElo($pElo) ?>
      <?php endif; ?>
      <p class="ghichu">Elo chỉ thay đổi theo kết quả trận đấu, không chỉnh tay được.</p>
    </div>
    <?php endif; ?>
  </div>

  <?php if (!$laAdminSV): ?>
  <div class="card" id="bieudo-elo">
    <h3>Biến động Elo</h3>
    <?php if (!$bieuDoJson): ?>
      <p class="ghichu">Chưa có lịch sử Elo. Biểu đồ sẽ xuất hiện sau trận đấu đầu tiên.</p>
    <?php else: ?>
      <div class="bd-dieukhien">
        <label for="bdMon">Môn</label>
        <select id="bdMon">
          <?php foreach ($bieuDoJson as $b): ?>
            <option value="<?= (int) $b['ma'] ?>"><?= e($b['ten']) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="bd-khoang" role="group" aria-label="Khoảng trận hiển thị">
          <button type="button" class="btn btn-phu" data-khoang="10">10 trận</button>
          <button type="button" class="btn btn-phu" data-khoang="30">30 trận</button>
          <button type="button" class="btn btn-phu" data-khoang="0">Tất cả</button>
        </div>
      </div>

      <div class="bd-tomtat" id="bdTomTat" aria-live="polite">
        <div><span>Elo hiện tại</span><b id="bdHienTai">–</b></div>
        <div><span>Cao nhất</span><b id="bdCao">–</b></div>
        <div><span>Thấp nhất</span><b id="bdThap">–</b></div>
        <div><span>Thay đổi</span><b id="bdDoi">–</b></div>
        <div><span>Số trận</span><b id="bdSoTran">–</b></div>
      </div>

      <div class="bd-khung"><canvas id="bdCanvas" role="img" aria-label="Biểu đồ đường biến động Elo theo từng trận"></canvas></div>
      <p class="ghichu" id="bdGhiChu">Mỗi điểm là Elo sau một trận: xanh là tăng, đỏ là giảm. Rê chuột (hoặc chạm) vào điểm để xem chi tiết.</p>
      <noscript><p class="ghichu">Cần bật JavaScript để xem biểu đồ.</p></noscript>

      <script type="application/json" id="bdDuLieu"><?= json_encode($bieuDoJson, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php if (!$laAdminSV && $bieuDoJson): ?><script src="<?= e(BASE_URL) ?>/assets/vendor/chart.umd.js"></script><?php endif; ?>
<?php require __DIR__ . '/layout/footer.php'; ?>