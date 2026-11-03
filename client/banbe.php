<?php
// Bạn bè: tìm sinh viên, gửi / chấp nhận / từ chối / hủy lời mời (C-41). Đặt tại: elo_quiz/client/banbe.php
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/BanBeHelper.php';

$maND = Auth::requireClient(BASE_URL . '/client/login.php');
$pdo  = Database::pdo();

$q    = trim((string) ($_GET['q'] ?? ''));
$trang = (int) ($_GET['page'] ?? 1);

// ---- Xử lý thao tác (POST), xong thì quay lại đúng trang tìm kiếm ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $hanhDong = (string) ($_POST['hanh_dong'] ?? '');
    $nguoi    = (int) ($_POST['nguoi'] ?? 0);
    $qGui     = trim((string) ($_POST['q'] ?? ''));
    $trangGui = max(1, (int) ($_POST['page'] ?? 1));

    if ($hanhDong === 'gui') {
        $kq = BanBeHelper::guiLoiMoi($maND, $nguoi);
        BanBeHelper::flash($kq === BanBeHelper::OK ? 'ok' : 'loi', BanBeHelper::THONG_BAO[$kq]);
    } elseif ($hanhDong === 'huy') {
        $ok = BanBeHelper::huyLoiMoi($maND, $nguoi);
        BanBeHelper::flash($ok ? 'ok' : 'loi', $ok ? 'Đã hủy lời mời.' : 'Lời mời không còn hiệu lực.');
    } elseif ($hanhDong === 'chapnhan') {
        $ok = BanBeHelper::chapNhan($maND, $nguoi);
        BanBeHelper::flash($ok ? 'ok' : 'loi', $ok ? 'Đã chấp nhận lời mời.' : 'Lời mời không còn hiệu lực.');
    } elseif ($hanhDong === 'tuchoi') {
        $ok = BanBeHelper::tuChoi($maND, $nguoi);
        BanBeHelper::flash($ok ? 'ok' : 'loi', $ok ? 'Đã từ chối lời mời.' : 'Lời mời không còn hiệu lực.');
    }

    $url = BASE_URL . '/client/banbe.php';
    if ($qGui !== '') {
        $url .= '?' . http_build_query(['q' => $qGui, 'page' => $trangGui]);
    }
    redirect($url);
}

$thongBao = BanBeHelper::flash();

// Nút bấm nhỏ gửi một thao tác (kèm CSRF, giữ nguyên từ khóa tìm kiếm và trang)
function nutBanBe(string $hanhDong, int $nguoi, string $nhan, string $q, int $trang, string $lop = 'btn'): string
{
    return '<form method="post" action="" class="form-dong">'
        . csrf_field()
        . '<input type="hidden" name="hanh_dong" value="' . e($hanhDong) . '">'
        . '<input type="hidden" name="nguoi" value="' . $nguoi . '">'
        . '<input type="hidden" name="q" value="' . e($q) . '">'
        . '<input type="hidden" name="page" value="' . $trang . '">'
        . '<button type="submit" class="' . e($lop) . '">' . e($nhan) . '</button></form> ';
}

// ---- Tìm sinh viên theo họ tên (không tìm theo email để khỏi lộ email nào đã đăng ký) ----
$ketQua = [];
$p = null;
if ($q !== '') {
    $mau = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';

    $st = $pdo->prepare("SELECT COUNT(*) FROM NGUOIDUNG WHERE MaND <> ? AND TrangThai = 'Hoạt động' AND HoTen LIKE ?");
    $st->execute([$maND, $mau]);
    $p = new Paginator((int) $st->fetchColumn(), 10);

    // Quan hệ giữa mình và từng người tìm được: một dòng BANBE duy nhất cho mỗi cặp
    $st = $pdo->prepare(
        "SELECT u.MaND, u.HoTen, u.Lop, u.Khoa, b.MaNguoiGui, b.TrangThai AS TrangThaiBan
           FROM NGUOIDUNG u
           LEFT JOIN BANBE b ON b.CapNho = LEAST(?, u.MaND) AND b.CapLon = GREATEST(?, u.MaND)
          WHERE u.MaND <> ? AND u.TrangThai = 'Hoạt động' AND u.HoTen LIKE ?
          ORDER BY u.HoTen
          LIMIT " . $p->limit() . ' OFFSET ' . $p->offset()
    );
    $st->execute([$maND, $maND, $maND, $mau]);
    $ketQua = $st->fetchAll();
}
$trangHienTai = $p ? $p->trang() : 1;

// ---- Lời mời đã nhận, đã gửi, danh sách bạn bè ----
$st = $pdo->prepare(
    "SELECT u.MaND, u.HoTen, u.Lop, u.Khoa, b.NgayGui
       FROM BANBE b JOIN NGUOIDUNG u ON u.MaND = b.MaNguoiGui
      WHERE b.MaNguoiNhan = ? AND b.TrangThai = 'Chờ' ORDER BY b.NgayGui DESC"
);
$st->execute([$maND]);
$daNhan = $st->fetchAll();

$st = $pdo->prepare(
    "SELECT u.MaND, u.HoTen, u.Lop, u.Khoa, b.NgayGui
       FROM BANBE b JOIN NGUOIDUNG u ON u.MaND = b.MaNguoiNhan
      WHERE b.MaNguoiGui = ? AND b.TrangThai = 'Chờ' ORDER BY b.NgayGui DESC"
);
$st->execute([$maND]);
$daGui = $st->fetchAll();

$st = $pdo->prepare(
    "SELECT u.MaND, u.HoTen, u.Lop, u.Khoa
       FROM BANBE b JOIN NGUOIDUNG u ON u.MaND = IF(b.MaNguoiGui = ?, b.MaNguoiNhan, b.MaNguoiGui)
      WHERE (b.MaNguoiGui = ? OR b.MaNguoiNhan = ?) AND b.TrangThai = 'Đã chấp nhận'
      ORDER BY u.HoTen"
);
$st->execute([$maND, $maND, $maND]);
$banBe = $st->fetchAll();

function thongTinNguoi(array $r): string
{
    $phu = array_filter([$r['Lop'] ?? '', $r['Khoa'] ?? '']);
    return '<b>' . e($r['HoTen']) . '</b>' . ($phu ? ' <span class="ghichu">(' . e(implode(' · ', $phu)) . ')</span>' : '');
}

$tieuDe = 'Bạn bè';
$cssTrang = 'banbe';
require __DIR__ . '/layout/header.php';
?>
<div class="container">
  <h2>Bạn bè</h2>

  <?php if ($thongBao): ?>
    <div class="<?= $thongBao['loai'] === 'ok' ? 'thanhcong' : 'loi' ?>"><?= e($thongBao['nd']) ?></div>
  <?php endif; ?>

  <div class="card">
    <h3>Tìm sinh viên</h3>
    <form method="get" action="" class="timkiem">
      <input type="text" name="q" value="<?= e($q) ?>" maxlength="100" placeholder="Nhập họ tên cần tìm">
      <button type="submit" class="btn">Tìm</button>
    </form>

    <?php if ($q !== ''): ?>
      <div class="khoi-ketqua">
        <?php if (!$ketQua): ?>
          <p class="ghichu">Không tìm thấy sinh viên nào.</p>
        <?php endif; ?>
        <?php foreach ($ketQua as $r):
            $tt  = $r['TrangThaiBan'];
            $gui = (int) ($r['MaNguoiGui'] ?? 0);
            $id  = (int) $r['MaND'];
        ?>
          <div class="dong">
            <div><?= thongTinNguoi($r) ?></div>
            <div>
              <?php if ($tt === 'Đã chấp nhận'): ?>
                <span class="ghichu">Đã là bạn bè</span>
              <?php elseif ($tt === 'Chờ' && $gui === $maND): ?>
                <span class="ghichu">Đã gửi lời mời</span>
                <?= nutBanBe('huy', $id, 'Hủy lời mời', $q, $trangHienTai, 'btn btn-phu') ?>
              <?php elseif ($tt === 'Chờ'): ?>
                <span class="ghichu">Đã gửi lời mời cho bạn</span>
                <?= nutBanBe('chapnhan', $id, 'Chấp nhận', $q, $trangHienTai) ?>
                <?= nutBanBe('tuchoi', $id, 'Từ chối', $q, $trangHienTai, 'btn btn-phu') ?>
              <?php else: /* chưa có dòng, hoặc đã "Từ chối" thì được gửi lại */ ?>
                <?= nutBanBe('gui', $id, 'Kết bạn', $q, $trangHienTai) ?>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
        <?= $p ? $p->links() : '' ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h3>Lời mời đã nhận (<?= count($daNhan) ?>)</h3>
    <?php if (!$daNhan): ?><p class="ghichu">Không có lời mời nào đang chờ.</p><?php endif; ?>
    <?php foreach ($daNhan as $r): ?>
      <div class="dong">
        <div><?= thongTinNguoi($r) ?></div>
        <div>
          <?= nutBanBe('chapnhan', (int) $r['MaND'], 'Chấp nhận', $q, $trangHienTai) ?>
          <?= nutBanBe('tuchoi', (int) $r['MaND'], 'Từ chối', $q, $trangHienTai, 'btn btn-phu') ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="card">
    <h3>Lời mời đã gửi (<?= count($daGui) ?>)</h3>
    <?php if (!$daGui): ?><p class="ghichu">Bạn chưa gửi lời mời nào đang chờ.</p><?php endif; ?>
    <?php foreach ($daGui as $r): ?>
      <div class="dong">
        <div><?= thongTinNguoi($r) ?></div>
        <div><?= nutBanBe('huy', (int) $r['MaND'], 'Hủy lời mời', $q, $trangHienTai, 'btn btn-phu') ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="card">
    <h3>Bạn bè của tôi (<?= count($banBe) ?>)</h3>
    <?php if (!$banBe): ?><p class="ghichu">Bạn chưa có bạn bè nào. Hãy tìm và gửi lời mời ở trên.</p><?php endif; ?>
    <?php foreach ($banBe as $r): ?>
      <div class="dong"><div><?= thongTinNguoi($r) ?></div></div>
    <?php endforeach; ?>
  </div>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>